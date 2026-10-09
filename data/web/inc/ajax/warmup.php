<?php
/**
 * mailcow-dockerized-Max — 养号（Warmup）AJAX 端点
 *
 * 仅管理员可调用。写操作要求 CSRF（沿用 resend_pool.php 的既有约定）。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.warmup.inc.php';

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
);
$is_write = in_array($action, $write_actions, true);

if ($is_api_session && $is_write) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'read-only API key cannot perform write operations'), JSON_UNESCAPED_UNICODE);
  exit;
}

// 写操作需要 CSRF（仅 API 会话路径，浏览器会话由框架 session_check() 校验）
if ($is_write && $is_api_session) {
  $sess_token = $_SESSION['CSRF']['TOKEN'] ?? '';
  if (!isset($_POST['csrf_token']) || !hash_equals($sess_token, (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'CSRF token invalid'), JSON_UNESCAPED_UNICODE);
    exit;
  }
}

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
      $plans = warmup_plans($pdo);
      $recipients = warmup_recipients($pdo);
      $logs = warmup_logs($pdo, 50);

      $out = array();
      foreach ($plans as $p) {
        $out[] = array(
          'id'           => (int)$p['id'],
          'domain'       => $p['domain'],
          'sender'       => $p['sender'],
          'status'       => $p['status'],
          'pause_reason' => $p['pause_reason'],
          'start_count'  => (int)$p['start_count'],
          'step'         => (int)$p['step'],
          'max_count'    => (int)$p['max_count'],
          'total_days'   => (int)$p['total_days'],
          'started_on'   => $p['started_on'],
          'day_index'    => warmup_day_index($p),
          'day_quota'    => warmup_daily_quota($p),
          'sent_today'   => (int)$p['sent_today'],
          'sent_all'     => (int)$p['sent_all'],
          'fail_all'     => (int)$p['fail_all'],
          'last_run'     => $p['last_run'],
        );
      }

      $rcOut = array();
      foreach ($recipients as $r) {
        $rcOut[] = array(
          'id'     => (int)$r['id'],
          'email'  => $r['email'],
          'label'  => $r['label'],
          'active' => (int)$r['active'],
        );
      }

      $logOut = array();
      foreach ($logs as $l) {
        $logOut[] = array(
          'id'       => (int)$l['id'],
          'plan_id'  => (int)$l['plan_id'],
          'domain'   => $l['domain'],
          'sender'   => $l['sender'],
          'run_at'   => $l['run_at'],
          'run_date' => $l['run_date'],
          'planned'  => (int)$l['planned'],
          'sent'     => (int)$l['sent'],
          'failed'   => (int)$l['failed'],
          'detail'   => $l['detail'],
        );
      }

      wu_json(array(
        'ok'             => true,
        'plans'          => $out,
        'recipients'     => $rcOut,
        'logs'           => $logOut,
        'sent_today'     => warmup_sent_today_total($pdo),
        'global_cap'     => WARMUP_GLOBAL_DAILY_CAP,
        'max_per_run'    => WARMUP_MAX_PER_RUN,
        'window'         => WARMUP_WINDOW_START_HOUR . ':00 - ' . WARMUP_WINDOW_END_HOUR . ':00',
        'server_time'    => date('Y-m-d H:i:s'),
      ));
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
      wu_json(array('ok' => true, 'id' => (int)$pdo->lastInsertId()));
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
      wu_json(array('ok' => true));
    }

    case 'delete_plan': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $stmt = $pdo->prepare("DELETE FROM `warmup_plans` WHERE `id` = :id");
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    // ============ 收件人池 ============
    case 'add_recipient': {
      $email = wu_post('email');
      $label = mb_substr(wu_post('label'), 0, 128);
      if (!wu_valid_email($email)) {
        wu_json(array('ok' => false, 'message' => 'invalid email'));
      }
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM `warmup_recipients` WHERE `email` = :e");
      $stmt->execute(array(':e' => $email));
      if ((int)$stmt->fetchColumn() > 0) {
        wu_json(array('ok' => false, 'message' => 'recipient already exists'));
      }
      $stmt = $pdo->prepare("INSERT INTO `warmup_recipients` (`email`, `label`) VALUES (:e, :l)");
      $stmt->execute(array(':e' => $email, ':l' => $label));
      wu_json(array('ok' => true, 'id' => (int)$pdo->lastInsertId()));
    }

    case 'delete_recipient': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $stmt = $pdo->prepare("DELETE FROM `warmup_recipients` WHERE `id` = :id");
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    case 'toggle_recipient': {
      $id = wu_int('id');
      if ($id <= 0) {
        wu_json(array('ok' => false, 'message' => 'invalid id'));
      }
      $stmt = $pdo->prepare("UPDATE `warmup_recipients` SET `active` = 1 - `active` WHERE `id` = :id");
      $stmt->execute(array(':id' => $id));
      wu_json(array('ok' => true));
    }

    // ============ 立即执行一轮（人工触发，忽略随机时刻） ============
    case 'run_now': {
      $r = warmup_run($pdo, true);
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
