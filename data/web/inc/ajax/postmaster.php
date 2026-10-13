<?php
/**
 * mailcow-dockerized-Max — Google Postmaster Tools 端点
 *
 * 两种请求形态：
 *   1. **JSON API**（前端调）—— `?action=xxx`，写操作走 POST；
 *   2. **OAuth 回调**（Google 把浏览器重定向过来）—— `?code=&state=`，
 *      天生只能是 GET。它同样是有副作用的操作，因此用一次性 `state`
 *      做防重放（OAuth 这条链路上等价于 CSRF token），
 *      并在处理前校验 CSRF 会话值与当前管理员身份。
 *
 * CSRF 语义（本项目最容易写错的地方，见 SOP §2.2）：
 *   框架在**校验通过**时 `unset($_POST['csrf_token'])` 并**轮换**会话 token；
 *   校验失败才清空 `$_POST`。所以：
 *     ❌ 不能拿 `$_POST['csrf_token']` 与会话里的值比对 —— 通过的那一刻会话已轮换，
 *        比对必然不等，会让**所有合法写请求 403**；
 *     ✅ 判据是「客户端确实提交过 csrf_token」且「$_POST 没被清空」。
 *   无参动作（不读任何 `$_POST` 字段）尤其危险：上面那条判据对它们不成立，
 *   必须显式校验，否则伪造 token 也能触发。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.audit.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.secretbox.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.postmaster.inc.php';

/** 统一 JSON 出口：必须把**轮换后**的 token 回传，前端下次写操作才不会 403。 */
function pm_json($data) {
  if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
  if (!isset($data['csrf']) && isset($_SESSION['CSRF']['TOKEN'])) { $data['csrf'] = $_SESSION['CSRF']['TOKEN']; }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pm_post($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

function pm_back($ok, $msg) {
  $u = '/admin/system?pm=' . ($ok ? 'ok' : 'err') . '&pm_msg=' . rawurlencode(mb_substr($msg, 0, 180));
  if (!headers_sent()) { header('Location: ' . $u); }
  exit;
}

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  pm_json(array('ok' => false, 'message' => 'access denied'));
}

/* ================================================================== *
 * OAuth 回调（Google 重定向回来，只能是 GET）
 * ================================================================== */
if (isset($_GET['code']) || isset($_GET['error'])) {
  $st  = isset($_GET['state']) ? (string)$_GET['state'] : '';
  $exp = isset($_SESSION['pm_oauth_state']) ? (string)$_SESSION['pm_oauth_state'] : '';
  unset($_SESSION['pm_oauth_state']);   // 一次性：无论成败都作废

  if (isset($_GET['error'])) {
    audit_log($pdo, 'postmaster.oauth', 'postmaster', '*', 'fail', 'Google 返回：' . (string)$_GET['error']);
    pm_back(false, '授权被拒绝：' . (string)$_GET['error']);
  }
  // state 不匹配 = 不是本次发起的授权（重放/串号），必须拒绝
  if ($st === '' || $exp === '' || !hash_equals($exp, $st)) {
    audit_log($pdo, 'postmaster.oauth', 'postmaster', '*', 'fail', 'state 校验失败');
    pm_back(false, 'state 校验失败，请重新点击「连接 Google 账号」');
  }

  $r = pm_oauth_exchange($pdo, (string)$_GET['code']);
  audit_log($pdo, 'postmaster.oauth', 'postmaster', '*', $r['ok'] ? 'ok' : 'fail',
            $r['ok'] ? ('账号=' . $r['email']) : $r['message']);
  pm_back($r['ok'], $r['ok'] ? ('已连接 Google 账号' . ($r['email'] !== '' ? '：' . $r['email'] : '')) : $r['message']);
}

/* ================================================================== *
 * JSON API
 * ================================================================== */
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

// `auth_url` 也算写操作：它会把一次性 state 写进会话。不拦的话，
// 伪造 token 的请求也能把用户的会话 state 覆盖掉，进而劫持授权回调。
$write_actions = array('set_oauth', 'disconnect', 'auth_url', 'save_manual',
                       'fetch_token', 'verify', 'sync', 'remove');
$is_write = in_array($action, $write_actions, true);

if ($is_write) {
  if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    http_response_code(405);
    pm_json(array('ok' => false, 'message' => '该动作仅接受 POST'));
  }
  if (!isset($_REQUEST['csrf_token']) || (string)$_REQUEST['csrf_token'] === '') {
    http_response_code(403);
    pm_json(array('ok' => false, 'message' => '缺少 CSRF token，请刷新页面后重试'));
  }
  if (empty($_POST)) {
    http_response_code(403);
    pm_json(array('ok' => false, 'message' => 'CSRF 校验失败，请刷新页面后重试'));
  }
  if (isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true) {
    http_response_code(403);
    pm_json(array('ok' => false, 'message' => 'API key 会话不允许执行写操作'));
  }
}

try {
  switch ($action) {

    // ---- 读：首屏状态 ----
    case 'overview': {
      pm_json(array('ok' => true, 'data' => pm_overview_state($pdo, $redis)));
    }

    // ---- 写：保存 OAuth 客户端凭据 ----
    case 'set_oauth': {
      $id  = pm_post('client_id');
      $sec = pm_post('client_secret');
      if ($id === '' || !preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $id)) {
        pm_json(array('ok' => false, 'message' => 'Client ID 形态不对（应以 .apps.googleusercontent.com 结尾）'));
      }
      pm_setting_set($pdo, 'pm_oauth_client_id', $id);
      // Secret 只在这里出现一次明文，立刻加密；库里存密文。
      // 留空表示"不改"，避免用户为了改 Client ID 而被迫重新粘贴 Secret。
      if ($sec !== '') {
        // ⚠️ 必须检查加密结果：sb_encrypt() 在保管箱不可用时返回空串，
        //    而 pm_setting_set(..., '') 是"删除"语义 —— 会静默丢掉 Secret
        //    却仍然提示"已保存"，用户下一步连接必然失败且看不出原因。
        $enc = sb_encrypt($sec);
        if ($enc === '') {
          pm_json(array('ok' => false,
            'message' => 'Client Secret 加密失败，未保存：请确认 /warmup-secrets 目录可读可写。'));
        }
        pm_setting_set($pdo, 'pm_oauth_client_secret_enc', $enc);
      }
      audit_log($pdo, 'postmaster.set_oauth', 'postmaster', '*', 'ok',
                'client_id 已更新' . ($sec !== '' ? '（含 Secret）' : '（Secret 未变）'));
      pm_json(array('ok' => true, 'message' => '已保存'));
    }

    // ---- 写：断开授权 ----
    case 'disconnect': {
      pm_oauth_disconnect($pdo);
      if ($redis) { try { $redis->del('PM_ACCESS_TOKEN'); } catch (Throwable $e) {} }
      audit_log($pdo, 'postmaster.disconnect', 'postmaster', '*', 'ok', '已断开 Google 授权');
      pm_json(array('ok' => true, 'message' => '已断开（Client ID/Secret 保留，可随时重连）'));
    }

    // ---- 写：发起授权（返回跳转地址，由前端跳）----
    case 'auth_url': {
      if (!pm_oauth_configured($pdo)) {
        pm_json(array('ok' => false, 'message' => '请先填写 Client ID 与 Client Secret'));
      }
      $st = pm_new_state();
      $_SESSION['pm_oauth_state'] = $st;   // 回调时比对，一次性
      pm_json(array('ok' => true, 'url' => pm_auth_url($pdo, $st), 'redirect_uri' => pm_redirect_uri()));
    }

    // ---- 写：免授权路径 —— 用户把 Google 给的记录值粘进来 ----
    case 'save_manual': {
      $domain = strtolower(pm_post('domain'));
      $method = strtoupper(pm_post('method', 'DNS_TXT'));
      $token  = pm_post('token');
      if ($token === '') { pm_json(array('ok' => false, 'message' => '请粘贴 Google 给你的记录值')); }
      $r = pm_save_record($pdo, $redis, $domain, $method, $token);
      audit_log($pdo, 'postmaster.save_manual', 'postmaster_domain', $domain,
                $r['ok'] ? 'ok' : 'fail', $r['message']);
      if (!$r['ok']) { pm_json(array('ok' => false, 'message' => $r['message'])); }
      pm_json(array('ok' => true, 'message' => $r['message'], 'record' => $r['record'],
                    'data' => pm_overview_state($pdo, $redis)));
    }

    // ---- 写：OAuth 路径 —— 自动取 token 并写 DNS ----
    case 'fetch_token': {
      $domain = strtolower(pm_post('domain'));
      $method = strtoupper(pm_post('method', 'DNS_TXT'));
      $t = pm_get_token($pdo, $redis, $domain, $method);
      if (!$t['ok']) {
        $msg = $t['error'];
        if (!empty($t['explain'])) { $msg = $t['explain']['cause'] . '；' . $t['explain']['fix']; }
        audit_log($pdo, 'postmaster.fetch_token', 'postmaster_domain', $domain, 'fail', $msg);
        pm_json(array('ok' => false, 'message' => $msg));
      }
      $r = pm_save_record($pdo, $redis, $domain, $t['method'], $t['token']);
      audit_log($pdo, 'postmaster.fetch_token', 'postmaster_domain', $domain,
                $r['ok'] ? 'ok' : 'fail', $r['ok'] ? $r['message'] : $r['message']);
      pm_json(array('ok' => $r['ok'], 'message' => $r['message'], 'record' => $r['record'],
                    'data' => pm_overview_state($pdo, $redis)));
    }

    // ---- 写：请 Google 复核 ----
    case 'verify': {
      $domain = strtolower(pm_post('domain'));
      $row = pm_row($pdo, $domain);
      if (!$row) { pm_json(array('ok' => false, 'message' => '该域还没有验证记录，先取 token 或粘贴记录')); }
      if (!pm_oauth_connected($pdo)) {
        pm_json(array('ok' => false, 'message' => '需要先连接 Google 账号才能由这里代你提交验证。'
                   . '也可以按界面显示的记录手工添加后，去 postmaster.google.com 点「验证」。'));
      }
      $v = pm_verify_domain($pdo, $redis, $domain, $row['verify_method']);
      if ($v['ok']) {
        $pdo->prepare("UPDATE `postmaster_domains` SET `verify_status`='verified', `verified_at`=NOW(0), `last_error`='' WHERE `domain`=:d")
            ->execute(array(':d' => $domain));
        audit_log($pdo, 'postmaster.verify', 'postmaster_domain', $domain, 'ok', 'Google 已确认所有权');
        pm_json(array('ok' => true, 'message' => '验证通过', 'data' => pm_overview_state($pdo, $redis)));
      }
      $msg = $v['error'];
      if (!empty($v['explain'])) { $msg = $v['explain']['cause'] . '；' . $v['explain']['fix']; }
      // 与后台定时复核同一套判据：不可恢复的错（域在别的账号下 / 无权 / API 未启用）
      // 落到 failed，让徽章如实变红；只是"记录还没生效"则留在原地继续等。
      // 两处判据分叉的话，手工点和自动跑会给出互相矛盾的状态。
      if (pm_verify_error_is_fatal((int)($v['http'] ?? 0), (string)$v['error'])) {
        $pdo->prepare("UPDATE `postmaster_domains` SET `verify_status`='failed', `last_error`=:e WHERE `domain`=:d")
            ->execute(array(':e' => mb_substr($msg, 0, 255), ':d' => $domain));
      } else {
        pm_note_error($pdo, $domain, $msg);
      }
      audit_log($pdo, 'postmaster.verify', 'postmaster_domain', $domain, 'fail', $msg);
      pm_json(array('ok' => false, 'message' => $msg, 'data' => pm_overview_state($pdo, $redis)));
    }

    // ---- 写：全部同步 ----
    case 'sync': {
      if (!pm_oauth_connected($pdo)) {
        pm_json(array('ok' => false, 'message' => '尚未连接 Google 账号。'
                   . '未授权时界面上的「手工添加记录」流程依然可用。'));
      }
      $r = pm_sync_all($pdo, $redis, 7);
      audit_log($pdo, 'postmaster.sync', 'postmaster', '*', 'ok',
                sprintf('registered=%d synced=%d verified=%d errors=%d',
                        $r['registered'], $r['synced'], $r['verified'], count($r['errors'])));
      pm_json(array('ok' => true, 'result' => $r, 'data' => pm_overview_state($pdo, $redis)));
    }

    // ---- 写：移除本地记录（不删 DNS，删了会让已通过的验证失效）----
    case 'remove': {
      $domain = strtolower(pm_post('domain'));
      if (!is_valid_domain_name($domain)) { pm_json(array('ok' => false, 'message' => '域名格式无效')); }
      $pdo->prepare("DELETE FROM `postmaster_domains` WHERE `domain` = :d")->execute(array(':d' => $domain));
      audit_log($pdo, 'postmaster.remove', 'postmaster_domain', $domain, 'ok',
                '仅移除本地状态，DNS 记录与 Google 侧的所有权不受影响');
      pm_json(array('ok' => true, 'message' => '已移除本地记录（DNS 与 Google 侧所有权未动）',
                    'data' => pm_overview_state($pdo, $redis)));
    }

    default:
      http_response_code(400);
      pm_json(array('ok' => false, 'message' => 'unknown action'));
  }
}
catch (Throwable $e) {
  // 绝不把异常原文回给浏览器（可能含 SQL 片段/表名），也不写进审计
  $trace = bin2hex(random_bytes(4));
  error_log('postmaster.php error [' . $trace . ']: ' . get_class($e) . ' ' . $e->getMessage());
  http_response_code(500);
  pm_json(array('ok' => false, 'message' => 'internal error', 'trace' => $trace));
}
