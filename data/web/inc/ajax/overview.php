<?php
/**
 * mailcow-dockerized-Max — 域名总览 AJAX 端点
 *
 * 一个页面看全：每个域的真实收信/认证/中继/养号状态，以及每个邮箱账号。
 * 所有写操作都会写入 admin_audit_log（审计）。
 *
 * 性能设计（重要）：
 *   页面加载**只读数据库 + redis 缓存**，绝不在加载时做 DNS 查询——
 *   16 个域逐个查 DNS 会让首屏等十几秒。
 *   DNS 类检查（MX/SPF/DKIM/DMARC）由「体检」动作触发后写入缓存，
 *   页面只展示缓存结果；没体检过的域显示「未检测」。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.audit.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

$is_api_session = isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true;
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

$write_actions = array(
  'add_domain', 'add_mailbox', 'reset_mailbox_password',
  'toggle_mailbox', 'check_domain', 'check_all',
);
$is_write = in_array($action, $write_actions, true);

if ($is_api_session && $is_write) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'read-only API key cannot perform write operations'), JSON_UNESCAPED_UNICODE);
  exit;
}

// 写操作需要 CSRF（仅 API 会话路径；浏览器会话由框架 session_check 校验）
if ($is_write && $is_api_session) {
  $sess_token = isset($_SESSION['CSRF']['TOKEN']) ? $_SESSION['CSRF']['TOKEN'] : '';
  if (!isset($_POST['csrf_token']) || !hash_equals($sess_token, (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'CSRF token invalid'), JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function ov_json($data) {
  // mailcow 每次 POST 通过校验后会轮换 CSRF token，必须回传否则前端第二次写必 403
  if (!isset($data['csrf']) && isset($_SESSION['CSRF']['TOKEN'])) {
    $data['csrf'] = $_SESSION['CSRF']['TOKEN'];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ov_post($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

function ov_valid_domain($d) {
  if ($d === '' || strlen($d) > 253) return false;
  if (preg_match('/[\r\n\t,;@\s]/', $d)) return false;
  return (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $d);
}
function ov_valid_email($e) {
  if ($e === '' || strlen($e) > 254) return false;
  if (preg_match('/[\r\n\t,;]/', $e)) return false;
  return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

/** 本机主机名（用于判断 MX 是否指向我们自己） */
function ov_hostname() {
  return strtolower(trim((string)getenv('MAILCOW_HOSTNAME')));
}

/** 缓存的域检查结果（由 check_domain 写入） */
function ov_cached_check($redis, $domain) {
  if (!$redis) return null;
  $raw = $redis->get('OV_CHECK/' . $domain);
  if ($raw === false || $raw === null) return null;
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

/**
 * 对单个域做轻量检查（MX / SPF / DKIM / DMARC），结果写 redis 缓存 1 小时。
 * 只在「体检」动作里调用，不在页面加载时调用。
 */
function ov_run_check($pdo, $redis, $domain) {
  $host = ov_hostname();
  $r = array('domain' => $domain, 'checked_at' => date('Y-m-d H:i:s'));

  // MX
  $mxs = array();
  $res = @dns_get_record($domain, DNS_MX);
  if (is_array($res)) {
    foreach ($res as $x) {
      if (!empty($x['target'])) $mxs[] = strtolower(rtrim($x['target'], '.'));
    }
  }
  $r['mx'] = $mxs;
  $r['mx_ok'] = in_array($host, $mxs, true);

  // SPF
  $spf = null;
  $res = @dns_get_record($domain, DNS_TXT);
  if (is_array($res)) {
    foreach ($res as $x) {
      $t = isset($x['txt']) ? $x['txt'] : '';
      if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; }
    }
  }
  $r['spf'] = $spf;
  $r['spf_ok'] = $spf !== null && !preg_match('/include:\S*serv00/i', $spf);

  // DKIM（从 redis 取 selector）
  $sel = null;
  try { $sel = $redis ? $redis->hGet('DKIM_SELECTORS', $domain) : null; } catch (Exception $e) { $sel = null; }
  $r['dkim_selector'] = $sel;
  $r['dkim_ok'] = false;
  if ($sel) {
    $name = $sel . '._domainkey.' . $domain;
    $res = @dns_get_record($name, DNS_TXT);
    if (is_array($res)) {
      foreach ($res as $x) {
        $t = isset($x['txt']) ? $x['txt'] : '';
        if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) { $r['dkim_ok'] = true; break; }
      }
    }
  }

  // DMARC
  $dmarc = null;
  $res = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
  if (is_array($res)) {
    foreach ($res as $x) {
      $t = isset($x['txt']) ? $x['txt'] : '';
      if (stripos($t, 'v=DMARC1') === 0) { $dmarc = $t; break; }
    }
  }
  $r['dmarc'] = $dmarc;
  $r['dmarc_ok'] = $dmarc !== null;

  if ($redis) {
    try { $redis->setex('OV_CHECK/' . $domain, 3600, json_encode($r)); } catch (Exception $e) {}
  }
  return $r;
}

try {
  switch ($action) {

    // ================= 总览（只读，纯 DB + 缓存） =================
    case 'overview': {
      global $redis;

      // 域名 + 中继
      $domStmt = $pdo->query(
        "SELECT d.`domain`, d.`active`, d.`relayhost`, r.`hostname` AS `relay_host`,
                d.`backupmx`, d.`created`
           FROM `domain` d
           LEFT JOIN `relayhosts` r ON r.`id` = d.`relayhost`
          ORDER BY d.`domain` ASC"
      );
      $domains = $domStmt->fetchAll(PDO::FETCH_ASSOC);

      // 各域邮箱数
      $cnt = array();
      foreach ($pdo->query("SELECT `domain`, COUNT(*) AS `n` FROM `mailbox` GROUP BY `domain`")->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $cnt[$x['domain']] = (int)$x['n'];
      }

      // 养号计划（按域聚合）
      $wu = array();
      $hasWarmup = true;
      try {
        foreach ($pdo->query(
          "SELECT p.`domain`,
                  p.`status`, p.`started_on`, p.`start_count`, p.`step`, p.`max_count`, p.`total_days`,
                  COALESCE((SELECT SUM(`sent`) FROM `warmup_log` WHERE `plan_id`=p.`id` AND `run_date`=CURDATE()),0) AS `sent_today`,
                  COALESCE((SELECT SUM(`sent`) FROM `warmup_log` WHERE `plan_id`=p.`id`),0) AS `sent_all`,
                  COALESCE((SELECT SUM(`failed`) FROM `warmup_log` WHERE `plan_id`=p.`id`),0) AS `fail_all`
             FROM `warmup_plans` p"
        )->fetchAll(PDO::FETCH_ASSOC) as $x) {
          $day = 1;
          if (!empty($x['started_on'])) {
            $day = (int)floor((strtotime(date('Y-m-d')) - strtotime($x['started_on'])) / 86400) + 1;
            if ($day < 1) $day = 1;
          }
          $quota = (int)$x['start_count'] + ($day - 1) * (int)$x['step'];
          if ($quota > (int)$x['max_count']) $quota = (int)$x['max_count'];
          $wu[$x['domain']] = array(
            'status'     => $x['status'],
            'day'        => $day,
            'total_days' => (int)$x['total_days'],
            'quota'      => $quota,
            'sent_today' => (int)$x['sent_today'],
            'sent_all'   => (int)$x['sent_all'],
            'fail_all'   => (int)$x['fail_all'],
          );
        }
      } catch (Exception $e) {
        $hasWarmup = false;
      }

      $outDomains = array();
      foreach ($domains as $d) {
        $c = ov_cached_check($redis, $d['domain']);
        $bad = 0; $warn = 0;
        if ($c) {
          if (!$c['mx_ok'])    $bad++;
          if (!$c['spf_ok'])   $bad++;
          if (!$c['dkim_ok'])  $bad++;
          if (!$c['dmarc_ok']) $warn++;
        }
        $outDomains[] = array(
          'domain'       => $d['domain'],
          'active'       => (int)$d['active'],
          'relay'        => ($d['relay_host'] === null ? '' : $d['relay_host']),
          'mailboxes'    => isset($cnt[$d['domain']]) ? $cnt[$d['domain']] : 0,
          'checked'      => $c !== null,
          'checked_at'   => $c ? $c['checked_at'] : '',
          'mx'           => $c ? $c['mx'] : array(),
          'mx_ok'        => $c ? (bool)$c['mx_ok'] : null,
          'spf_ok'       => $c ? (bool)$c['spf_ok'] : null,
          'dkim_ok'      => $c ? (bool)$c['dkim_ok'] : null,
          'dmarc_ok'     => $c ? (bool)$c['dmarc_ok'] : null,
          'bad'          => $bad,
          'warn'         => $warn,
          'warmup'       => isset($wu[$d['domain']]) ? $wu[$d['domain']] : null,
        );
      }

      // 邮箱清单
      $mbox = array();
      foreach ($pdo->query(
        "SELECT m.`username`, m.`domain`, m.`name`, m.`active`, m.`quota`, m.`created`, m.`modified`
           FROM `mailbox` m ORDER BY m.`domain` ASC, m.`username` ASC"
      )->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $mbox[] = array(
          'username' => $m['username'],
          'domain'   => $m['domain'],
          'name'     => $m['name'],
          'active'   => (int)$m['active'],
          'quota'    => (int)$m['quota'],
          'created'  => $m['created'],
        );
      }

      // 统计
      $todaySent = 0;
      try {
        $todaySent = (int)$pdo->query("SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `run_date`=CURDATE()")->fetchColumn();
      } catch (Exception $e) {}

      $abnormal = 0;
      foreach ($outDomains as $x) { if ($x['bad'] > 0) $abnormal++; }

      ov_json(array(
        'ok'        => true,
        'hostname'  => ov_hostname(),
        'domains'   => $outDomains,
        'mailboxes' => $mbox,
        'stats'     => array(
          'domains'      => count($outDomains),
          'mailboxes'    => count($mbox),
          'abnormal'     => $abnormal,
          'today_sent'   => $todaySent,
          'audit_count'  => audit_count($pdo),
        ),
        'audit'     => audit_recent($pdo, 30),
        'server_time' => date('Y-m-d H:i:s'),
      ));
    }

    // ================= 体检单个域 =================
    case 'check_domain': {
      global $redis;
      $d = strtolower(ov_post('domain'));
      if (!ov_valid_domain($d)) {
        audit_log($pdo, 'domain.check', 'domain', $d, 'fail', 'invalid domain');
        ov_json(array('ok' => false, 'message' => 'invalid domain'));
      }
      try {
        $r = ov_run_check($pdo, $redis, $d);
        $errs = array();
        if (!$r['mx_ok'])    $errs[] = 'MX 未指向本机';
        if (!$r['spf_ok'])   $errs[] = 'SPF 缺失或含可疑 include';
        if (!$r['dkim_ok'])  $errs[] = 'DKIM 公钥未发布';
        if (!$r['dmarc_ok']) $errs[] = 'DMARC 缺失';
        audit_log($pdo, 'domain.check', 'domain', $d, empty($errs) ? 'ok' : 'fail',
                  empty($errs) ? 'all pass' : implode('; ', $errs));
        ov_json(array('ok' => true, 'result' => $r));
      } catch (Exception $e) {
        audit_log($pdo, 'domain.check', 'domain', $d, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'check failed'));
      }
    }

    // ================= 体检全部 =================
    case 'check_all': {
      global $redis;
      $rows = $pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll(PDO::FETCH_ASSOC);
      $n = 0; $bad = 0;
      foreach ($rows as $x) {
        try {
          $r = ov_run_check($pdo, $redis, $x['domain']);
          $n++;
          if (!$r['mx_ok'] || !$r['spf_ok'] || !$r['dkim_ok'] || !$r['dmarc_ok']) $bad++;
        } catch (Exception $e) {}
      }
      audit_log($pdo, 'domain.check_all', 'domain', '', 'ok', "checked=$n abnormal=$bad");
      ov_json(array('ok' => true, 'checked' => $n, 'abnormal' => $bad));
    }

    // ================= 新增域名 =================
    case 'add_domain': {
      $d = strtolower(ov_post('domain'));
      if (!ov_valid_domain($d)) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', 'invalid domain');
        ov_json(array('ok' => false, 'message' => 'invalid domain'));
      }
      $exists = $pdo->prepare("SELECT COUNT(*) FROM `domain` WHERE `domain` = :d");
      $exists->execute(array(':d' => $d));
      if ((int)$exists->fetchColumn() > 0) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', 'already exists');
        ov_json(array('ok' => false, 'message' => '域名已存在'));
      }
      try {
        // 走 mailcow 自己的 domain 添加逻辑，避免绕开其副作用（DKIM、别名域等）
        $result = mailbox('add', 'domain', array(
          'domain'           => $d,
          'description'      => ov_post('description'),
          'aliases'          => 0,
          'mailboxes'        => (int)ov_post('mailboxes', '0'),
          'defquota'         => (int)ov_post('defquota', '0'),
          'maxquota'         => (int)ov_post('maxquota', '0'),
          'quota'            => (int)ov_post('quota', '0'),
          'active'           => 1,
          'gal'              => 0,
          'backupmx'         => 0,
          'relay_all_recipients' => 0,
          'relay_unknown_only'   => 0,
          'dkim_selector'    => 'dkim',
          'key_size'         => 2048,
        ));
        audit_log($pdo, 'domain.add', 'domain', $d, 'ok', 'created');
        ov_json(array('ok' => true, 'result' => $result));
      } catch (Exception $e) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'add failed: ' . $e->getMessage()));
      } catch (Error $e) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'add failed: ' . $e->getMessage()));
      }
    }

    // ================= 新增邮箱 =================
    case 'add_mailbox': {
      $user = strtolower(ov_post('username'));
      $pw   = (string)ov_post('password');
      if (!ov_valid_email($user)) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'invalid address');
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      $parts = explode('@', $user);
      $dom = $parts[1];
      $dchk = $pdo->prepare("SELECT COUNT(*) FROM `domain` WHERE `domain` = :d");
      $dchk->execute(array(':d' => $dom));
      if ((int)$dchk->fetchColumn() === 0) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'domain not in mailcow');
        ov_json(array('ok' => false, 'message' => '该域名不在 mailcow 中，请先添加域名'));
      }
      if (strlen($pw) < 8) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'password too short');
        ov_json(array('ok' => false, 'message' => '密码至少 8 位'));
      }
      try {
        $result = mailbox('add', 'mailbox', array(
          'local_part'      => $parts[0],
          'domain'          => $dom,
          'name'            => ov_post('name'),
          'quota'           => (int)ov_post('quota', '0'),
          'password'        => $pw,
          'password2'       => $pw,
          'active'          => 1,
          'force_pw_update' => 0,
          'tls_enforce_in'  => 0,
          'tls_enforce_out' => 0,
        ));
        // 审计里绝不记录密码
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'ok', 'created; quota=' . (int)ov_post('quota', '0'));
        ov_json(array('ok' => true, 'result' => $result));
      } catch (Exception $e) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'add failed'));
      } catch (Error $e) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'add failed'));
      }
    }

    // ================= 重置邮箱密码 =================
    case 'reset_mailbox_password': {
      $user = strtolower(ov_post('username'));
      $pw   = (string)ov_post('password');
      if (!ov_valid_email($user)) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'invalid address');
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      // 未提供密码则现场生成 16 位随机密码（只在响应里返回一次）
      $generated = false;
      if ($pw === '') {
        $pw = bin2hex(random_bytes(8));
        $generated = true;
      }
      if (strlen($pw) < 8) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'password too short');
        ov_json(array('ok' => false, 'message' => '密码至少 8 位'));
      }
      $chk = $pdo->prepare("SELECT COUNT(*) FROM `mailbox` WHERE `username` = :u");
      $chk->execute(array(':u' => $user));
      if ((int)$chk->fetchColumn() === 0) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'no such mailbox');
        ov_json(array('ok' => false, 'message' => '邮箱不存在'));
      }
      try {
        mailbox('edit', 'mailbox', array(
          'username' => $user,
          'password' => $pw,
          'password2'=> $pw,
          'active'   => 1,
        ));
        // 审计只记"改了"，绝不记密码本身
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'ok',
                  $generated ? 'generated random password' : 'set to provided password');
        ov_json(array('ok' => true, 'generated' => $generated, 'password' => $pw));
      } catch (Exception $e) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'reset failed'));
      } catch (Error $e) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'reset failed'));
      }
    }

    // ================= 启用/停用邮箱 =================
    case 'toggle_mailbox': {
      $user = strtolower(ov_post('username'));
      if (!ov_valid_email($user)) {
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      $st = $pdo->prepare("SELECT `active` FROM `mailbox` WHERE `username` = :u");
      $st->execute(array(':u' => $user));
      $cur = $st->fetchColumn();
      if ($cur === false) {
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', 'no such mailbox');
        ov_json(array('ok' => false, 'message' => '邮箱不存在'));
      }
      $new = ((int)$cur === 1) ? 0 : 1;
      try {
        mailbox('edit', 'mailbox', array('username' => $user, 'active' => $new));
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'ok', 'active=' . $new);
        ov_json(array('ok' => true, 'active' => $new));
      } catch (Exception $e) {
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', $e->getMessage());
        ov_json(array('ok' => false, 'message' => 'toggle failed'));
      }
    }

    default:
      http_response_code(400);
      ov_json(array('ok' => false, 'message' => 'unknown action'));
  }
}
catch (Exception $e) {
  $trace = bin2hex(random_bytes(4));
  error_log('overview.php error [' . $trace . ']: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(array('ok' => false, 'message' => 'internal error', 'trace' => $trace), JSON_UNESCAPED_UNICODE);
  exit;
}
