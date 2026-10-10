<?php
/**
 * mailcow-dockerized-Max — Resend 号池 AJAX 端点
 *
 * 仅管理员可调用。所有写操作都要求 CSRF token。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.resend_pool.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- 权限：API 会话若为只读，则禁止一切写操作 ----
// sessions.inc.php:65/68 会为 API Key 设置 mailcow_cc_api_access = rw|ro。
// 只读 Key 不应能通过本端点修改配置。
$is_api_session = isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true;
$api_access = isset($_SESSION['mailcow_cc_api_access']) ? $_SESSION['mailcow_cc_api_access'] : '';

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

// ---- 写操作清单 ----
$write_actions = array('add_account', 'edit_account', 'delete_account', 'assign', 'auto_assign',
                       'remove_domain', 'set_limit', 'set_cf_token', 'sync');
$is_write = in_array($action, $write_actions, true);

// 只读 API 会话禁止写操作
if ($is_api_session && $is_write) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'read-only API key cannot perform write operations'), JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- 写操作需要 CSRF ----
// 注意：键名必须是 $_SESSION['CSRF']['TOKEN']（权威定义见 inc/footer.inc.php:77
// 与 inc/sessions.inc.php:33-34）。此前误用 csrf_token 导致校验恒失败。
// 另外，mailcow 的 session_check()（sessions.inc.php:157-168）在浏览器会话下
// 已校验并 unset($_POST['csrf_token']) 并轮换 token，因此这里只对
// 「未被框架校验过的路径」补校验 —— 即 API 会话。
if ($is_write && $is_api_session) {
  $sess_token = $_SESSION['CSRF']['TOKEN'] ?? '';
  if (!isset($_POST['csrf_token']) || !hash_equals($sess_token, (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'CSRF token invalid'), JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function rp_json($data) {
  // mailcow 的 session_check() 在每次 POST 通过校验后轮换 CSRF token
  // （sessions.inc.php:166）。页面注入的 window.csrf_token 是加载时的旧值，
  // 若不回传新 token，前端第二次写操作必然 403。
  if (!isset($data["csrf"]) && isset($_SESSION["CSRF"]["TOKEN"])) {
    $data["csrf"] = $_SESSION["CSRF"]["TOKEN"];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  switch ($action) {

    // 列出账号 + 域名映射 + 配置
    case 'list':
      rp_json(array(
        'ok' => true,
        'accounts' => resend_pool_accounts(false),
        'domains' => resend_pool_domains(),
        'limit' => resend_pool_domain_limit(),
        'cf_configured' => (resend_pool_cf_token($pdo, $redis) !== ''),
      ));
      break;

    // 测试一个 Key（不落库）
    case 'check_key':
      $key = trim((string)($_POST['api_key'] ?? ''));
      if ($key === '') rp_json(array('ok' => false, 'message' => 'API Key 不能为空'));
      $chk = resend_check_key($key);
      rp_json(array('ok' => $chk['ok'], 'message' => $chk['message'], 'count' => count($chk['domains'])));
      break;

    // 添加账号
    case 'add_account':
      $key = trim((string)($_POST['api_key'] ?? ''));
      $label = trim((string)($_POST['label'] ?? ''));
      if ($key === '') rp_json(array('ok' => false, 'message' => 'API Key 不能为空'));
      if (strpos($key, 're_') !== 0) rp_json(array('ok' => false, 'message' => 'API Key 应以 re_ 开头'));
      $chk = resend_check_key($key);
      if (!$chk['ok']) {
        rp_json(array('ok' => false, 'message' => 'Key 校验失败：' . $chk['message'] . '（注意：需要 Full access 权限，Sending access 无法管理域名）'));
      }
      $stmt = $pdo->prepare("SELECT `id` FROM `resend_accounts` WHERE `api_key` = :k LIMIT 1");
      $stmt->execute(array(':k' => $key));
      if ($stmt->fetch()) rp_json(array('ok' => false, 'message' => '该 Key 已存在'));
      if ($label === '') $label = '账号 ' . date('md-His');
      $stmt = $pdo->prepare("INSERT INTO `resend_accounts` (`label`,`api_key`,`active`,`check_status`) VALUES (:l,:k,1,:s)");
      $stmt->execute(array(':l' => $label, ':k' => $key, ':s' => $chk['message']));
      rp_json(array('ok' => true, 'message' => '账号「' . $label . '」已添加（' . $chk['message'] . '）'));
      break;

    // 修改账号（标签 / 启用状态）
    case 'edit_account':
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) rp_json(array('ok' => false, 'message' => '参数错误'));
      $sets = array(); $params = array(':id' => $id);
      if (isset($_POST['label'])) { $sets[] = '`label`=:l'; $params[':l'] = trim((string)$_POST['label']); }
      if (isset($_POST['active'])) { $sets[] = '`active`=:a'; $params[':a'] = (int)$_POST['active'] === 1 ? 1 : 0; }
      if (empty($sets)) rp_json(array('ok' => false, 'message' => '没有要修改的内容'));
      $stmt = $pdo->prepare("UPDATE `resend_accounts` SET " . implode(',', $sets) . " WHERE `id`=:id");
      $stmt->execute($params);
      // 同步 relayhost 状态
      $stmt = $pdo->prepare("SELECT * FROM `resend_accounts` WHERE `id`=:id LIMIT 1");
      $stmt->execute(array(':id' => $id));
      $acc = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($acc) resend_pool_ensure_relayhost($acc);
      rp_json(array('ok' => true, 'message' => '已更新'));
      break;

    // 删除账号
    case 'delete_account':
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) rp_json(array('ok' => false, 'message' => '参数错误'));
      // 仍承载域名的账号不允许直接删除
      $stmt = $pdo->prepare("SELECT COUNT(*) c FROM `resend_domains` WHERE `account_id`=:id AND `active`=1");
      $stmt->execute(array(':id' => $id));
      if ((int)$stmt->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
        rp_json(array('ok' => false, 'message' => '该账号仍绑定域名，请先移除域名或改用其他账号'));
      }
      // 清理对应的 relayhosts 记录，避免遗留含旧 API Key 的孤儿行
      $stmt = $pdo->prepare("DELETE FROM `relayhosts` WHERE `hostname` = :h AND `username` = :u");
      $stmt->execute(array(':h' => RESEND_RELAY_HOST, ':u' => 'resend#' . $id));
      $stmt = $pdo->prepare("DELETE FROM `resend_accounts` WHERE `id`=:id");
      $stmt->execute(array(':id' => $id));
      rp_json(array('ok' => true, 'message' => '账号及其中继记录已删除'));
      break;

    // 手动绑定：域名 → 账号
    case 'assign':
      $domain = trim((string)($_POST['domain'] ?? ''));
      $aid = (int)($_POST['account_id'] ?? 0);
      if ($domain === '' || $aid <= 0) rp_json(array('ok' => false, 'message' => '参数错误'));
      $res = resend_pool_assign($domain, $aid);
      rp_json($res);
      break;

    // 自动分配
    case 'auto_assign':
      $domains = isset($_POST['domains']) ? (array)$_POST['domains'] : array();
      $domains = array_filter(array_map('trim', $domains));
      if (empty($domains)) {
        // 未指定则取 mailcow 中所有启用的域名
        foreach ($pdo->query("SELECT `domain` FROM `domain` WHERE `active`=1 ORDER BY `domain`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $domains[] = $r['domain'];
        }
      }
      if (empty($domains)) rp_json(array('ok' => false, 'message' => '没有可分配的域名'));
      $res = resend_pool_auto_assign($domains);
      rp_json($res);
      break;

    // 移除域名
    case 'remove_domain':
      $domain = trim((string)($_POST['domain'] ?? ''));
      if ($domain === '') rp_json(array('ok' => false, 'message' => '参数错误'));
      $res = resend_pool_remove($domain);
      rp_json($res);
      break;

    // 设置域名上限
    case 'set_limit':
      $n = resend_pool_set_domain_limit((int)($_POST['limit'] ?? 3));
      rp_json(array('ok' => true, 'message' => '域名上限已设为 ' . $n, 'limit' => $n));
      break;

    // 设置 Cloudflare Token（用于自动写 DNS）
    case 'set_cf_token':
      $t = trim((string)($_POST['cf_token'] ?? ''));
      // 落库到 MySQL（持久）+ redis 短期缓存。此前只写 redis，redis 一清 token 就丢。
      $db_ok = resend_pool_setting_set($pdo, 'cf_api_token', $t);
      if ($redis) {
        try {
          if ($t === '') { $redis->del('RESEND_POOL_CF_TOKEN'); }
          else { $redis->setex('RESEND_POOL_CF_TOKEN', 600, $t); }
        } catch (Exception $e) {}
      }
      if (!$db_ok) { rp_json(array('ok' => false, 'message' => 'Token 写入数据库失败')); }
      if ($t === '') { rp_json(array('ok' => true, 'message' => '已清除 Cloudflare Token（含数据库）')); }
      rp_json(array('ok' => true, 'message' => 'Cloudflare Token 已保存'));
      break;

    // 同步验证状态
    case 'sync':
      rp_json(resend_pool_sync());
      break;

    // 读取某域名缓存的 DNS 记录
    case 'records':
      $domain = trim((string)($_GET['domain'] ?? ''));
      $stmt = $pdo->prepare("SELECT `records_json`,`status` FROM `resend_domains` WHERE `domain`=:d LIMIT 1");
      $stmt->execute(array(':d' => $domain));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row) rp_json(array('ok' => false, 'message' => '未找到'));
      rp_json(array('ok' => true, 'status' => $row['status'], 'records' => json_decode($row['records_json'], true)));
      break;

    default:
      http_response_code(400);
      rp_json(array('ok' => false, 'message' => 'unknown action'));
  }
} catch (Throwable $e) {
  // 不把内部异常消息回显给前端（可能含 SQL 片段、路径等敏感信息），
  // 只记入错误日志，前端得到通用提示 + 可定位的追踪 ID。
  $trace_id = bin2hex(random_bytes(6));
  error_log('[resend_pool] trace=' . $trace_id . ' ' . $e->getMessage()
    . ' @ ' . $e->getFile() . ':' . $e->getLine());
  http_response_code(500);
  rp_json(array('ok' => false, 'message' => '服务器内部错误，请查看日志（追踪号 ' . $trace_id . '）'));
}
