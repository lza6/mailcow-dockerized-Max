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
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.overview.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

$write_actions = array(
  'add_domain', 'add_mailbox', 'reset_mailbox_password',
  'toggle_mailbox', 'check_domain', 'check_all',
);

// 写操作一律拒绝 API key 会话。
//
// 说明：mailcow 的 API key 分 ro / rw，但本页新增的这几个写动作在上游权限表里
// 没有对应权限位，无法可靠区分调用方是 ro 还是 rw。与其猜测，不如全部拒绝：
// 需要写操作请用浏览器会话——框架的 session_check() 会强制校验 CSRF。
// （原先这里还跟了一段 `$is_write && $is_api_session` 的 CSRF 校验分支，
//   但上面的拒绝已经 exit，该分支永远不可达，属于死代码，已删除。）
if (isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true
    && in_array($action, $write_actions, true)) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'API key sessions cannot perform write operations on this endpoint'), JSON_UNESCAPED_UNICODE);
  exit;
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

/**
 * 判定 mailbox() 的调用结果。
 *
 * mailcow 的 mailbox() 失败时**不抛异常**，而是 return false 并把原因写进
 * $_SESSION['return'][].msg（源码里约 50 处如此）。调用方若不检查返回值，
 * 就会把"没做成"当成"做成了"——既误导管理员，也会往审计里写假记录。
 *
 * @return array array($ok, $msg)
 */
function ov_mailbox_result($result) {
  $msgs = array();
  if (isset($_SESSION['return']) && is_array($_SESSION['return'])) {
    foreach ($_SESSION['return'] as $r) {
      if (isset($r['msg']) && $r['msg'] !== '') { $msgs[] = (string)$r['msg']; }
    }
    // 取完即清，避免污染后续判定
    unset($_SESSION['return']);
  }
  if ($result === false) {
    return array(false, $msgs ? implode('; ', array_unique($msgs)) : 'mailbox() 返回 false');
  }
  if (is_array($result) && isset($result['error']) && $result['error'] !== '') {
    return array(false, (string)$result['error']);
  }
  return array(true, '');
}

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
  // SPF 判定标准（原实现只去找一个已下线的 `include:...serv00...`，
  // serv00 回收后该特征在所有域上都不存在，于是「没有 SPF 的域」也会被判 ok）。
  // 现改为三条硬标准：
  //   1) 存在 v=spf1 记录
  //   2) 授权了本机（mx / a:本机主机名 / include:本机主机名）
  //   3) 以 all 机制收尾（~/-/+/ ? 任一）
  $r['spf_ok'] = false;
  if ($spf !== null) {
    $spf_l = strtolower($spf);
    $covers_us = (bool)preg_match('/(?:^|\s)mx(?:\s|:|$)/', $spf_l)
              || ($host !== '' && strpos($spf_l, ':' . $host) !== false);
    $has_all = (bool)preg_match('/[~\-+?]all(?:\s|$)/', $spf_l);
    $r['spf_ok'] = $covers_us && $has_all;
  }

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
        // 长 TXT 会被 DNS 拆成多段（255 字节一段）。dns_get_record 在部分平台
        // 给出 entries 数组而非拼好的 txt，不拼回去会让 p= 的值被截断。
        $t = isset($x['txt']) ? $x['txt'] : '';
        if (isset($x['entries']) && is_array($x['entries'])) { $t = implode('', $x['entries']); }
        if (stripos($t, 'v=DKIM1') === false) { continue; }
        // 关键：撤销后的公钥是 "v=DKIM1; p="（p 为空）。只要出现 p= 就算通过的话，
        // 已撤销的 key 会被判成「已发布」——必须取出 p 的值并确认非空。
        if (preg_match('/\bp\s*=\s*([A-Za-z0-9+\/\s=]+)/i', str_replace('"', '', $t), $m)
            && trim($m[1]) !== '') {
          $r['dkim_ok'] = true;
          break;
        }
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

    // ================= 总览（只读，纯 DB + 缓存，不含 DNS 实时查询） =================
    case 'overview': {
      global $redis;
      $ov = overview_build($pdo, $redis, true);
      $ov['audit'] = audit_recent($pdo, 30);
      $ov['stats']['audit_count'] = audit_count($pdo);
      ov_json($ov);
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
        if (!$r['spf_ok'])   $errs[] = 'SPF 缺失、未授权本机或缺少 all 机制';
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
      // 本动作**不接收任何参数**，因此必须显式要求 POST：
      // mailcow 的框架只在 $_POST 非空时才校验 CSRF（sessions.inc.php:157），
      // 若允许纯 GET 触发，第三方页面用 <img src="...?action=check_all"> 就能
      // 让已登录管理员被动执行全量体检（每域 RDAP + 多次 DNS，可耗尽 php-fpm worker）。
      if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
        http_response_code(405);
        ov_json(array('ok' => false, 'message' => 'check_all 仅接受 POST'));
      }
      $rows = $pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll(PDO::FETCH_ASSOC);
      $n = 0; $bad = 0; $errs = array();
      foreach ($rows as $x) {
        try {
          // 体检时顺便刷新域龄（写缓存 24h），之后页面首屏就能直接读缓存
          ov_domain_age_days($redis, $x['domain'], false);
          $r = ov_run_check($pdo, $redis, $x['domain']);
          $n++;
          if (!$r['mx_ok'] || !$r['spf_ok'] || !$r['dkim_ok'] || !$r['dmarc_ok']) $bad++;
        } catch (Exception $e) {
          // 不静默吞掉：逐域记录原因，避免"一个都没跑成"却被报成 ok
          $errs[] = $x['domain'] . ': ' . $e->getMessage();
        }
      }
      $total = count($rows);
      if ($n === 0 && $total > 0) {
        $msg = '全部域名体检失败：' . implode('; ', array_slice($errs, 0, 3));
        audit_log($pdo, 'domain.check_all', 'domain', '', 'fail', mb_substr($msg, 0, 250));
        ov_json(array('ok' => false, 'message' => $msg, 'checked' => 0, 'total' => $total));
      }
      audit_log($pdo, 'domain.check_all', 'domain', '', empty($errs) ? 'ok' : 'fail',
                'checked=' . $n . '/' . $total . ' abnormal=' . $bad
                . ($errs ? ('; errors=' . count($errs)) : ''));
      ov_json(array('ok' => true, 'checked' => $n, 'total' => $total,
                    'abnormal' => $bad, 'errors' => $errs));
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
        // mailcow 的 mailbox('add','domain') 有一串硬校验，必须同时满足：
        //   defquota > 0、maxquota > 0、defquota <= maxquota、maxquota <= quota
        // 任一条不满足就 return false（如 defquota_empty / mailbox_quota_exceeds_domain_quota）。
        // 而 mailbox() 失败不抛异常、只 return false，所以这里既要有合理默认值，
        // 也必须检查返回值，否则会出现「接口报成功、库里没数据、审计记假 ok」。
        // 默认值对齐本项目已有域的实际配置（见 domain 表）。
        $aliases  = (int)ov_post('aliases', '400');
        $mboxes   = (int)ov_post('mailboxes', '10');
        $defquota = (int)ov_post('defquota', '3072');     // 单邮箱默认配额 MB
        $maxquota = (int)ov_post('maxquota', '10240');    // 单邮箱最大配额 MB
        $dquota   = (int)ov_post('quota', '10240');       // 域总配额 MB

        if ($defquota <= 0) { $defquota = 3072; }
        if ($maxquota <= 0) { $maxquota = 10240; }
        if ($maxquota < $defquota) { $maxquota = $defquota; }
        // 域总配额必须 >= 单邮箱最大配额，否则 mailcow 直接拒绝
        if ($dquota < $maxquota) { $dquota = $maxquota; }

        $result = mailbox('add', 'domain', array(
          'domain'           => $d,
          'description'      => ov_post('description'),
          'aliases'          => $aliases,
          'mailboxes'        => $mboxes,
          'defquota'         => $defquota,
          'maxquota'         => $maxquota,
          'quota'            => $dquota,
          'active'           => 1,
          'gal'              => 0,
          'backupmx'         => 0,
          'relay_all_recipients' => 0,
          'relay_unknown_only'   => 0,
          'dkim_selector'    => 'dkim',
          'key_size'         => 2048,
          'template'         => 0,
          'restart_sogo'     => 0,
        ));
        list($ok, $msg) = ov_mailbox_result($result);
        if (!$ok) {
          audit_log($pdo, 'domain.add', 'domain', $d, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '添加失败：' . $msg));
        }
        audit_log($pdo, 'domain.add', 'domain', $d, 'ok',
                  'created; defquota=' . $defquota . ' maxquota=' . $maxquota);
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
        list($ok, $msg) = ov_mailbox_result($result);
        if (!$ok) {
          audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '添加失败：' . $msg));
        }
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
        // 注意：**不要**在这里传 active=1 —— 那会把已停用的邮箱静默重新启用，
        // 而按钮的语义只是「重置密码」。是否启用应由管理员显式决定。
        $result = mailbox('edit', 'mailbox', array(
          'username' => $user,
          'password' => $pw,
          'password2'=> $pw,
        ));
        list($ok, $msg) = ov_mailbox_result($result);
        if (!$ok) {
          audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '重置失败：' . $msg));
        }
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
        $result = mailbox('edit', 'mailbox', array('username' => $user, 'active' => $new));
        list($ok, $msg) = ov_mailbox_result($result);
        if (!$ok) {
          audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '操作失败：' . $msg));
        }
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
