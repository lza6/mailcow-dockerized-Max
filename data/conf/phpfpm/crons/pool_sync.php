<?php
/**
 * mailcow-dockerized-Max — 号池维护（由 ofelia 每 30 分钟调用一次）
 *
 * 做三件事：
 *   1. 对 Resend 侧仍为 `pending` 的域**重新触发一次验证**
 *   2. 同步各域的最终状态与 DNS 记录快照（resend_pool_sync）
 *   3. 校验每个账号的 API Key 是否仍有效（更新 last_check / check_status）
 *
 * 为什么需要「重新触发验证」：
 *   `resend_pool_assign()` 在绑定时会调一次 `POST /domains/{id}/verify`，
 *   但那是**异步**的，而且往往在 DNS 记录还没生效时就跑了 —— 于是域会一直停在
 *   `pending`。实测：DNS 记录（`send.<域>` MX/CNAME、`resend._domainkey.<域>` TXT）
 *   其实都已正确发布，只是 Resend 没再验过。一个周期性的重触发就能自愈。
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

require_once(__DIR__ . '/../web/inc/functions.resend_pool.inc.php');

try {
  // ---- 1) 对 pending 的域重新触发验证 ----
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

  // ---- 2) 同步状态（顺带会把账号 Key 也校验一遍）----
  $s = resend_pool_sync();
  echo '[pool-sync] synced=' . (int)($s['updated'] ?? 0) . ' errors=' . count((array)($s['errors'] ?? array())) . PHP_EOL;
  foreach ((array)($s['errors'] ?? array()) as $e) {
    fwrite(STDERR, '[pool-sync] ' . $e . PHP_EOL);
  }
} catch (Throwable $e) {
  fwrite(STDERR, '[pool-sync] failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

exit(0);
