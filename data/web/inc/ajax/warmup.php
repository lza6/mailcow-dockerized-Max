<?php
/**
 * mailcow-dockerized-Max — 养号（Warmup）AJAX 端点
 *
 * 仅管理员可调用。写操作要求 CSRF（沿用 resend_pool.php 的既有约定）。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.audit.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.warmup.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.secretbox.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.warmup_ext.inc.php';
// 复用总览页的养号数据构造器：**两个端点必须返回同一组字段**——
// 先前这里是手写的，少给了 sent_total/fail_total/messages/等，
// 导致「首屏有数据、点刷新就变空/变 undefined」这类只在刷新时暴露的问题。
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.overview.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- 权限：只读 API 会话禁止写操作 ----
$is_api_session = isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true;

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

$write_actions = array(
  'add_plan', 'edit_plan', 'delete_plan', 'set_status', 'run_now',
  'add_recipient', 'delete_recipient', 'toggle_recipient',
  'verify_recipient', 'edit_recipient', 'check_deliveries', 'run_interact',
  'add_plans_for_all',
);
$is_write = in_array($action, $write_actions, true);

/*
 * 写动作显式校验「本次请求确实过了框架的 CSRF 校验」。
 *
 * 与 ajax/overview.php 同一套判据，原因也一样：
 *   sessions.inc.php 在 CSRF **失败**时只清空 $_POST、不中止请求。
 *   「参数被清空 → 动作自然失效」对**带参**动作成立，但 check_deliveries /
 *   run_interact 这类**无参**动作不受影响，会被伪造请求直接触发（要连 IMAP、
 *   要发信，代价很高）。
 *
 * 判据为什么不能是比对 token 值：框架在**校验通过后**会 unset
 * $_POST['csrf_token'] 并轮换会话 token，此刻比对必然不等 → 合法请求全 403。
 * 只能用「$_POST 是否被清空」——框架只在失败时清空它。
 */
if ($is_write) {
  if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'message' => '该动作仅接受 POST'), JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!isset($_REQUEST['csrf_token']) || (string)$_REQUEST['csrf_token'] === '') {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => '缺少 CSRF token'), JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (empty($_POST)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'CSRF 校验失败，请刷新页面后重试'), JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if ($is_api_session && $is_write) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'API key sessions cannot perform write operations on this endpoint'), JSON_UNESCAPED_UNICODE);
  exit;
}

// （原先这里还有一段「$is_write && $is_api_session 时比对 CSRF token」的分支，
//   但上面已经对该组合直接 403 退出，那段永远不可达 —— 属于死代码，已删除。
//   留着会误导读者以为「API 会话可以凭 token 写」。）

function wu_json($data) {
  // mailcow 的 session_check()（sessions.inc.php:166）在每次 POST 通过校验后
  // 都会轮换 $_SESSION['CSRF']['TOKEN']。页面里注入的 window.csrf_token 是
  // 页面加载时的旧值，若不回传新 token，前端第二次写操作必然 403。
  // 因此每次响应都带上当前 token，由前端更新。
  if (!isset($data['csrf']) && isset($_SESSION['CSRF']['TOKEN'])) {
    $data['csrf'] = $_SESSION['CSRF']['TOKEN'];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function wu_post($key, $default = '') {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function wu_int($key, $default = 0) {
  return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

/**
 * 校验邮箱地址（拒绝换行等头注入字符）
 */
function wu_valid_email($email) {
  if ($email === '' || strlen($email) > 254) {
    return false;
  }
  if (preg_match('/[\r\n\t,;]/', $email)) {
    return false;
  }
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * 校验域名
 */
function wu_valid_domain($domain) {
  if ($domain === '' || strlen($domain) > 253) {
    return false;
  }
  if (preg_match('/[\r\n\t,;@\s]/', $domain)) {
    return false;
  }
  return (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain);
}

try {
  switch ($action) {

    // ============ 概览 ============
    case 'overview': {
      // 单一构造器：字段集与总览页首屏**完全一致**，不再各写一份。
      $w = ov_warmup_state($pdo);
      $w['ok']          = true;
      $w['global_cap']  = WARMUP_GLOBAL_DAILY_CAP;
      $w['max_per_run'] = WARMUP_MAX_PER_RUN;
      $w['reply_daily_cap'] = WARMUP_REPLY_DAILY_CAP;
      $w['server_time'] = date('Y-m-d H:i:s');
      wu_json($w);
    }

    // ============ 计划 ============
    case 'add_plan': {
      $domain = strtolower(wu_post('domain'));
      $sender = wu_post('sender');
      if (!wu_valid_domain($domain)) {
        wu_json(array('ok' => false, 'message' => 'invalid domain'));
      }
      if (!wu_valid_email($sender)) {
        wu_json(array('ok' => false, 'message' => 'invalid sender'));
      }
      if (substr(strtolower(strrchr($sender, '@')), 1) !== $domain) {
        wu_json(array('ok' => false, 'message' => 'sender domain must match domain'));
      }
      // 发件域必须是**本机管理的域**，发件邮箱必须真实存在且启用。
      // 只做语法校验是不够的：php-fpm 以 mynetworks 内可信客户端身份连 postfix:25
      // 且 SMTPAuth=false，所以给任意域（比如 billing@某支付域）建计划是能真发出去的，
      // 而且发出去的信没有 SPF/DKIM 对齐 —— 等于在替别人发仿冒信。
      $dc = $pdo->prepare("SELECT COUNT(*) FROM `domain` WHERE `domain` = :d AND `active` = 1");
      $dc->execute(array(':d' => $domain));
      if ((int)$dc->fetchColumn() === 0) {
        wu_json(array('ok' => false, 'message' => '该域名不在本机管理范围内（或已停用），不能作为养号发件域'));
      }
      $mc = $pdo->prepare("SELECT COUNT(*) FROM `mailbox` WHERE `username` = :u AND `active` = 1");
      $mc->execute(array(':u' => $sender));
      if ((int)$mc->fetchColumn() === 0) {
        wu_json(array('ok' => false, 'message' => '发件邮箱不存在或已停用'));
      }

      $start = max(1, min(50, wu_int('start_count', 2)));
      $step  = max(0, min(20, wu_int('step', 1)));
      $max   = max($start, min(200, wu_int('max_count', 20)));
      $days  = max(1, min(365, wu_int('total_days', 30)));

      // 防重：同域同发件人只允许一个未结束的计划
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM `warmup_plans`
                             WHERE `domain` = :d AND `sender` = :s
                               AND `status` IN ('active','paused')");
      $stmt->execute(array(':d' => $domain, ':s' => $sender));
      if ((int)$stmt->fetchColumn() > 0) {
        wu_json(array('ok' => false, 'message' => 'a plan for this sender already exists'));
      }

      $stmt = $pdo->prepare("INSERT INTO `warmup_plans`
        (`domain`, `sender`, `start_count`, `step`, `max_count`, `total_days`, `status`, `started_on`)
        VALUES (:d, :s, :sc, :st, :mx, :td, 'active', CURDATE())");
      $stmt->execute(array(
        ':d' => $domain, ':s' => $sender,
        ':sc' => $start, ':st' => $step, ':mx' => $max, ':td' => $days,
      ));
      $newId = (int)$pdo->lastInsertId();
      audit_log($pdo, 'warmup.plan_add', 'warmup_plan', (string)$newId, 'ok',
                $sender . ' / 起量' . $start . ' 日增' . $step . ' 上限' . $max . ' 天数' . $days);
      wu_json(array('ok' => true, 'id' => $newId));
    }

    case 'edit_plan': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $start = max(1, min(50, wu_int('start_count', 2)));
      $step  = max(0, min(20, wu_int('step', 1)));
      $max   = max($start, min(200, wu_int('max_count', 20)));
      $days  = max(1, min(365, wu_int('total_days', 30)));

      $stmt = $pdo->prepare("UPDATE `warmup_plans`
                                SET `start_count` = :sc, `step` = :st,
                                    `max_count` = :mx, `total_days` = :td
                              WHERE `id` = :id");
      $stmt->execute(array(':sc' => $start, ':st' => $step, ':mx' => $max, ':td' => $days, ':id' => $id));
      audit_log($pdo, 'warmup.plan_edit', 'warmup_plan', (string)$id, 'ok', '起量'.$start.' 日增'.$step.' 上限'.$max.' 天数'.$days);
      wu_json(array('ok' => true));
    }

    case 'set_status': {
      $id = wu_int('id');
      $status = wu_post('status');
      if ($id <= 0 || !in_array($status, array('active', 'paused'), true)) {
        wu_json(array('ok' => false, 'message' => 'invalid params'));
      }
      $stmt = $pdo->prepare("UPDATE `warmup_plans`
                                SET `status` = :s, `pause_reason` = ''
                              WHERE `id` = :id");
      $stmt->execute(array(':s' => $status, ':id' => $id));
      audit_log($pdo, 'warmup.plan_status', 'warmup_plan', (string)$id, 'ok', 'status='.$status);
      wu_json(array('ok' => true));
    }

    case 'delete_plan': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $stmt = $pdo->prepare("DELETE FROM `warmup_plans` WHERE `id` = :id");
      audit_log($pdo, 'warmup.plan_delete', 'warmup_plan', (string)$id, 'ok', 'deleted');
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    // ============ 收件人池：加账号（可带 IMAP/SMTP 凭据，一键验证）============
    case 'add_recipient': {
      $email = wu_post('email');
      $label = mb_substr(wu_post('label'), 0, 128);
      if (!wu_valid_email($email)) {
        wu_json(array('ok' => false, 'message' => '邮箱格式不合法'));
      }
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM `warmup_recipients` WHERE `email` = :e");
      $stmt->execute(array(':e' => $email));
      if ((int)$stmt->fetchColumn() > 0) {
        wu_json(array('ok' => false, 'message' => '该收件人已存在'));
      }

      $imapHost = wwe_valid_host(wu_post('imap_host', 'imap.gmail.com'));
      $smtpHost = wwe_valid_host(wu_post('smtp_host', 'smtp.gmail.com'));
      if ($imapHost === '' || $smtpHost === '') {
        wu_json(array('ok' => false, 'message' => 'IMAP/SMTP 主机名不合法'));
      }
      $imapPort = wwe_valid_port(wu_post('imap_port', '993'), 993);
      $smtpPort = wwe_valid_port(wu_post('smtp_port', '465'), 465);

      // 应用专用密码明文只在这里出现一次，立刻加密；库里存的是密文
      $secret = (string)(isset($_POST['secret']) ? $_POST['secret'] : '');
      $enc    = '';
      if ($secret !== '') {
        if (!sb_available()) {
          wu_json(array('ok' => false, 'message' => '凭据保管箱不可用（openssl 扩展缺失或密钥目录不可写）'));
        }
        $enc = sb_encrypt($secret);
        if ($enc === '') { wu_json(array('ok' => false, 'message' => '凭据加密失败')); }
      }

      $stmt = $pdo->prepare("INSERT INTO `warmup_recipients`
          (`email`,`label`,`imap_host`,`imap_port`,`smtp_host`,`smtp_port`,`secret_enc`,
           `auto_reply`,`rescue_spam`)
          VALUES (:e,:l,:ih,:ip,:sh,:sp,:sec,:ar,:rs)");
      $stmt->execute(array(
        ':e' => $email, ':l' => $label,
        ':ih' => $imapHost, ':ip' => $imapPort, ':sh' => $smtpHost, ':sp' => $smtpPort,
        // 没填密码时写 **NULL 而不是空串**：列是 `TEXT NULL DEFAULT NULL`，
        // 写空串会让「已配置密码」的判定（IS NOT NULL）返回真，
        // 界面出现绿色「已配置」，而实际上根本登不上 IMAP —— 自相矛盾且误导。
        ':sec' => ($enc === '' ? null : $enc),
        ':ar' => wu_int('auto_reply', 0) ? 1 : 0,
        ':rs' => wu_int('rescue_spam', 0) ? 1 : 0,
      ));
      $id = (int)$pdo->lastInsertId();

      // 带凭据就顺手验一次 —— 让管理员立刻知道密码对不对，而不是等养号跑起来才发现
      $verify = null;
      if ($enc !== '') {
        $cred = warmup_recipient_cred($pdo, $id);
        if ($cred) {
          list($conn, $err) = wwe_connect($cred, 'INBOX');
          if ($conn !== false) {
            $folders = wwe_list_folders($conn, wwe_ref($cred));
            $n = count($folders);
            @imap_close($conn);
            $verify = array('ok' => true, 'message' => 'IMAP 验证通过，可见 ' . $n . ' 个文件夹');
          } else {
            $verify = array('ok' => false, 'message' => 'IMAP 验证失败：' . $err);
          }
          $pdo->prepare("UPDATE `warmup_recipients`
                            SET `verify_status` = :s, `last_verify` = NOW(0) WHERE `id` = :i")
              ->execute(array(':s' => mb_substr($verify['message'], 0, 255), ':i' => $id));
        }
      }
      audit_log($pdo, 'warmup.rcpt_add', 'warmup_recipient', $email, 'ok',
                'id=' . $id . ' 凭据=' . ($enc === '' ? '无' : '已加密')
                . ' 自动回复=' . (wu_int('auto_reply') ? 1 : 0) . ' 救援=' . (wu_int('rescue_spam') ? 1 : 0));
      wu_json(array('ok' => true, 'id' => $id, 'verify' => $verify));
    }

    // ============ 收件人：重新验证 IMAP 凭据 ============
    case 'verify_recipient': {
      $id = wu_int('id');
      if ($id <= 0) { wu_json(array('ok' => false, 'message' => 'invalid id')); }
      $cred = warmup_recipient_cred($pdo, $id);
      if (!$cred) { wu_json(array('ok' => false, 'message' => '该收件人未配置应用专用密码')); }
      list($conn, $err) = wwe_connect($cred, 'INBOX');
      if ($conn === false) {
        $pdo->prepare("UPDATE `warmup_recipients` SET `verify_status` = :s, `last_verify` = NOW(0)
                        WHERE `id` = :i")->execute(array(':s' => mb_substr('失败: ' . $err, 0, 255), ':i' => $id));
        wu_json(array('ok' => false, 'message' => 'IMAP 验证失败：' . $err));
      }
      $folders = wwe_list_folders($conn, wwe_ref($cred));
      $n = count($folders);
      $spam = wwe_spam_folder($conn, wwe_ref($cred));
      @imap_close($conn);
      $msg = 'IMAP 验证通过，可见 ' . $n . ' 个文件夹' . ($spam !== '' ? ('；垃圾箱=' . $spam) : '；未找到垃圾箱文件夹');
      $pdo->prepare("UPDATE `warmup_recipients` SET `verify_status` = :s, `last_verify` = NOW(0)
                      WHERE `id` = :i")->execute(array(':s' => mb_substr($msg, 0, 255), ':i' => $id));
      audit_log($pdo, 'warmup.rcpt_verify', 'warmup_recipient', (string)$cred['email'], 'ok', mb_substr($msg, 0, 200));
      wu_json(array('ok' => true, 'message' => $msg, 'spam_folder' => $spam));
    }

    // ============ 收件人：更新凭据 / 互动开关 ============
    case 'edit_recipient': {
      $id = wu_int('id');
      if ($id <= 0) { wu_json(array('ok' => false, 'message' => 'invalid id')); }
      $sets = array(); $args = array(':i' => $id);
      if (isset($_POST['label'])) { $sets[] = '`label` = :l'; $args[':l'] = mb_substr(wu_post('label'), 0, 128); }
      if (isset($_POST['auto_reply']))   { $sets[] = '`auto_reply` = :ar';   $args[':ar'] = wu_int('auto_reply') ? 1 : 0; }
      if (isset($_POST['rescue_spam']))  { $sets[] = '`rescue_spam` = :rs';  $args[':rs'] = wu_int('rescue_spam') ? 1 : 0; }
      if (isset($_POST['active']))       { $sets[] = '`active` = :a';        $args[':a'] = wu_int('active') ? 1 : 0; }
      // 支持显式清空：传 secret=__clear__ 表示删除已存密码（否则只能覆盖，
      // 误配的账号就只能删掉重建，连带产生孤儿消息）
      if (isset($_POST['secret']) && (string)$_POST['secret'] === '__clear__') {
        $sets[] = '`secret_enc` = NULL';
        $sets[] = '`verify_status` = :vs'; $args[':vs'] = '已清空凭据';
      }
      if (isset($_POST['secret']) && (string)$_POST['secret'] !== '' && (string)$_POST['secret'] !== '__clear__') {
        if (!sb_available()) { wu_json(array('ok' => false, 'message' => '凭据保管箱不可用')); }
        $enc = sb_encrypt((string)$_POST['secret']);
        if ($enc === '') { wu_json(array('ok' => false, 'message' => '凭据加密失败')); }
        $sets[] = '`secret_enc` = :sec'; $args[':sec'] = $enc;
      }
      if (empty($sets)) { wu_json(array('ok' => false, 'message' => '没有要修改的内容')); }
      $pdo->prepare("UPDATE `warmup_recipients` SET " . implode(', ', $sets) . " WHERE `id` = :i")
          ->execute($args);
      audit_log($pdo, 'warmup.rcpt_edit', 'warmup_recipient', '#' . $id, 'ok',
                'fields=' . implode('|', $sets));
      wu_json(array('ok' => true));
    }

    // ============ 一键：为所有「还没有计划的启用邮箱」建计划 ============
    // 之前必须一个个手点；而"没绑 Resend 的域也要养"这类需求一次就是十几个。
    case 'add_plans_for_all': {
      $start = max(1, min(50, wu_int('start_count', 2)));
      $step  = max(0, min(20, wu_int('step', 1)));
      $max   = max($start, min(200, wu_int('max_count', 20)));
      $days  = max(1, min(365, wu_int('total_days', 30)));

      // 已有未结束计划的发件人不重复建
      $busy = array();
      foreach ($pdo->query("SELECT `sender` FROM `warmup_plans`
                             WHERE `status` IN ('active','paused')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $busy[strtolower($r['sender'])] = true;
      }
      $rows = $pdo->query("SELECT `username` FROM `mailbox`
                            WHERE `active` = 1 ORDER BY `username` ASC")->fetchAll(PDO::FETCH_ASSOC);
      $created = 0; $skipped = array();
      $ins = $pdo->prepare("INSERT INTO `warmup_plans`
          (`domain`,`sender`,`start_count`,`step`,`max_count`,`total_days`,`status`,`started_on`)
          VALUES (:d,:s,:sc,:st,:mx,:td,'active',CURDATE())");
      foreach ($rows as $r) {
        $sender = (string)$r['username'];
        if (isset($busy[strtolower($sender)])) { $skipped[] = $sender . '（已有未结束计划）'; continue; }
        $at = strrpos($sender, '@');
        if ($at === false) { $skipped[] = $sender . '（地址异常）'; continue; }
        $domain = substr($sender, $at + 1);
        if (!wu_valid_domain($domain)) { $skipped[] = $sender . '（域名异常）'; continue; }
        try {
          $ins->execute(array(':d' => $domain, ':s' => $sender, ':sc' => $start,
                              ':st' => $step, ':mx' => $max, ':td' => $days));
          $created++;
          $busy[strtolower($sender)] = true;
        } catch (Exception $e) {
          $skipped[] = $sender . '（' . mb_substr($e->getMessage(), 0, 80) . '）';
        }
      }
      audit_log($pdo, 'warmup.plan_add_all', 'warmup_plan', '*', 'ok',
                'created=' . $created . ' skipped=' . count($skipped));
      wu_json(array('ok' => true, 'created' => $created, 'skipped' => $skipped));
    }

    // ============ 手动触发：投递判定（去收件人邮箱看进了收件箱还是垃圾箱）============
    case 'check_deliveries': {
      $r = warmup_check_deliveries($pdo, 30);
      audit_log($pdo, 'warmup.check_deliveries', 'warmup', '*', 'ok', 'checked='.$r['checked'].' inbox='.$r['inbox'].' spam='.$r['spam']);
      wu_json(array('ok' => true, 'result' => $r));
    }

    // ============ 手动触发：互动（垃圾箱救援 + 自动回复）============
    case 'run_interact': {
      $r = warmup_interact($pdo, 10);
      audit_log($pdo, 'warmup.interact', 'warmup', '*', 'ok', 'rescued='.$r['rescued'].' replied='.$r['replied']);
      wu_json(array('ok' => true, 'result' => $r));
    }

    case 'delete_recipient': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      // 先把该收件人的未判定消息置终态，再删账号。
      // 否则它们按 recipient_id 悬空：每轮判定仍会捞出、取不到凭据就跳过，
      // 既白连一次 IMAP，又把「账号已删除」误报成「未配置应用专用密码」。
      $pdo->prepare("UPDATE `warmup_messages`
                        SET `result` = 'recipient_removed', `checked_at` = NOW(0)
                      WHERE `recipient_id` = :i AND `result` = 'unknown'")
          ->execute(array(':i' => $id));
      $stmt = $pdo->prepare("DELETE FROM `warmup_recipients` WHERE `id` = :id");
      audit_log($pdo, 'warmup.rcpt_delete', 'warmup_recipient', '#'.$id, 'ok', 'deleted');
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    case 'toggle_recipient': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $stmt = $pdo->prepare("UPDATE `warmup_recipients` SET `active` = 1 - `active` WHERE `id` = :id");
      audit_log($pdo, 'warmup.rcpt_toggle', 'warmup_recipient', '#'.$id, 'ok', 'toggled');
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    // ============ 立即执行一轮（人工触发，忽略随机时刻） ============
    case 'run_now': {
      $r = warmup_run($pdo, true);
      // 「立即执行一次」跳过随机时刻，是影响面最大的动作，必须留痕
      audit_log($pdo, 'warmup.run_now', 'warmup', '*', 'ok',
                'sent=' . $r['sent'] . ' failed=' . $r['failed']);
      wu_json(array('ok' => true, 'result' => $r));
    }

    default:
      http_response_code(400);
      wu_json(array('ok' => false, 'message' => 'unknown action'));
  }
}
catch (Exception $e) {
  $trace = bin2hex(random_bytes(4));
  error_log('warmup.php error [' . $trace . ']: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(array('ok' => false, 'message' => 'internal error', 'trace' => $trace), JSON_UNESCAPED_UNICODE);
  exit;
}
