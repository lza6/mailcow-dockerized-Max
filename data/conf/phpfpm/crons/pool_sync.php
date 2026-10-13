<?php
/**
 * mailcow-dockerized-Max — 号池维护（由 ofelia 每 30 分钟调用一次）
 *
 * 做三件事：
 *   1. 同步各域的最终状态与 DNS 记录快照（resend_pool_sync）
 *   2. 对**同步之后仍未通过**的域重新触发一次验证
 *   3. 校验每个账号的 API Key 是否仍有效（更新 last_check / check_status）
 *
 * 为什么「重新触发验证」必须排在同步**之后**：
 *   `POST /domains/{id}/verify` 会把 Resend 侧的状态**打回 pending**。
 *   第一版把触发放在同步之前，于是每次都是「先打回 pending，再读到 pending，
 *   再把 pending 写回本地库」—— 这个 cron 一直在自我空转，
 *   而 Resend 那边其实早已 verified。
 *   实测：15 个域在 Resend 侧全部 verified，本地却 12 个卡在 pending，
 *   且输出仍是 `synced=15 errors=0`，看不出任何异常。
 *
 * 安全约束：
 *   - 只对**已绑定且未 verified** 的域重触发；已 verified 的不再打扰
 *   - 每次最多处理 30 个域，避免一次调用打太多 API
 *   - 任何异常只写 STDERR；单个域失败不影响其它域
 */

require_once(__DIR__ . '/../web/inc/vars.inc.php');
if (file_exists(__DIR__ . '/../web/inc/vars.local.inc.php')) {
  include_once(__DIR__ . '/../web/inc/vars.local.inc.php');
}

$dsn = $database_type . ":unix_socket=" . $database_sock . ";dbname=" . $database_name;
$opt = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $database_user, $database_pass, $opt);
} catch (PDOException $e) {
  fwrite(STDERR, '[pool-sync] DB connect failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

$redis = null;
try {
  if (class_exists('Redis')) {
    $redis = new Redis();
    $redis->connect('redis-mailcow', 6379, 2.0);
    $rp = getenv('REDISPASS');
    if ($rp !== false && $rp !== '') { $redis->auth($rp); }
  }
} catch (Throwable $e) { $redis = null; }

// `functions.resend_pool.inc.php` 顶部用 `$_SERVER['DOCUMENT_ROOT']` 定位 prerequisites，
// CLI 下这个变量不存在，必须先补上，否则 require 直接失败。
if (empty($_SERVER['DOCUMENT_ROOT'])) { $_SERVER['DOCUMENT_ROOT'] = '/web'; }

require_once(__DIR__ . '/../web/inc/functions.resend_pool.inc.php');

try {
  // ---- 1) 先同步状态（顺带会把账号 Key 也校验一遍）----
  //
  // ⚠️ **顺序绝不能反**。第一版是先 `POST /domains/{id}/verify` 再 GET，
  //    结果是：重触发验证会把 Resend 侧的状态**打回 pending**，
  //    紧接着 GET 读到的就是刚被打回去的 pending，然后写回本地库 ——
  //    这个 cron 于是永远在「把 pending 刷成 pending」自我空转，
  //    而 Resend 那边其实早就 verified 了。
  //    实测：15 个域在 Resend 侧全部 verified，本地却 12 个卡在 pending，
  //    且 `synced=15 errors=0` 看上去一切正常。
  $s = resend_pool_sync();
  echo '[pool-sync] synced=' . (int)($s['updated'] ?? 0) . ' errors=' . count((array)($s['errors'] ?? array())) . PHP_EOL;
  foreach ((array)($s['errors'] ?? array()) as $e) {
    fwrite(STDERR, '[pool-sync] ' . $e . PHP_EOL);
  }

  // ---- 2) 再对**同步之后仍未通过**的域补一次重触发 ----
  // 走到这里的都是真的还没验过的域，重触发把它们打回 pending 也无所谓，
  // 下一轮同步会读到最新结果。
  $rows = $pdo->query("SELECT d.`id`, d.`domain`, d.`resend_domain_id`, a.`api_key`
                         FROM `resend_domains` d
                         LEFT JOIN `resend_accounts` a ON a.`id` = d.`account_id`
                        WHERE d.`active` = 1
                          AND d.`status` <> 'verified'
                          AND d.`resend_domain_id` <> ''
                          AND a.`api_key` <> ''
                        ORDER BY d.`id` ASC
                        LIMIT 30")->fetchAll();

  $triggered = 0;
  foreach ($rows as $row) {
    $r = resend_api_call($row['api_key'], 'POST', '/domains/' . rawurlencode($row['resend_domain_id']) . '/verify');
    if (!empty($r['ok'])) {
      $triggered++;
    } else {
      fwrite(STDERR, '[pool-sync] trigger failed ' . $row['domain'] . ': ' . ($r['error'] ?? '?') . PHP_EOL);
    }
    usleep(300000);   // 限速，别把上游打急
  }
  echo '[pool-sync] triggered=' . $triggered . '/' . count($rows) . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[pool-sync] failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

exit(0);
