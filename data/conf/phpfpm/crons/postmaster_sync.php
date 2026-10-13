<?php
/**
 * mailcow-dockerized-Max — Google Postmaster 定时同步（由 ofelia 每 6 小时调用一次）
 *
 * 做三件事（都在 pm_sync_all() 里，这里只负责调度与日志）：
 *   1. 更新每个域是否已被 Google 收录；
 *   2. 对已收录的域拉取按天信誉/投诉率/投递错误，汇总落库；
 *   3. 对「DNS 已写入但还没通过」的域代用户请 Google 复核一次
 *      （用户加完记录通常不会自己回去点「验证」）。
 *
 * 为什么是 6 小时而不是每 30 分钟：
 *   Postmaster 的数据粒度就是**天**，且 Google 官方说明有 1~2 天延迟。
 *   拉得再勤也拿不到更新的数，只会白耗 Google 的接口配额
 *   （它的 403/429 报错文案很像权限问题，很容易把人带到错误方向去排查）。
 *
 * 未连接 OAuth 时**直接正常退出**：免授权路径（用户手工粘记录 + 我们写 DNS）
 * 本来就不需要云端同步，这不是故障。
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
  fwrite(STDERR, '[postmaster] DB connect failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

// redis 只用来缓存 access_token 与 CF token，连不上就降级为 null，不影响判定
$redis = null;
try {
  if (class_exists('Redis')) {
    $redis = new Redis();
    $redis->connect('redis-mailcow', 6379, 2.0);
    $rp = getenv('REDISPASS');
    if ($rp !== false && $rp !== '') { $redis->auth($rp); }
    $redis->ping();
  }
} catch (Throwable $e) {
  fwrite(STDERR, '[postmaster] redis 不可用，本次不缓存令牌: ' . $e->getMessage() . PHP_EOL);
  $redis = null;
}

// CLI 下没有 DOCUMENT_ROOT，而 functions.postmaster.inc.php 依赖它定位同级文件
if (empty($_SERVER['DOCUMENT_ROOT'])) { $_SERVER['DOCUMENT_ROOT'] = '/web'; }

require_once(__DIR__ . '/../web/inc/functions.secretbox.inc.php');
require_once(__DIR__ . '/../web/inc/functions.postmaster.inc.php');

// 主库判定：多节点部署时只在 master 上跑
$isMaster = getenv('MASTER');
if ($isMaster !== false && $isMaster !== 'y') {
  echo '[postmaster] not master, skip' . PHP_EOL;
  exit(0);
}

try {
  $r = pm_sync_all($pdo, $redis, 7);
  if (!$r['connected']) {
    echo '[postmaster] ' . $r['message'] . PHP_EOL;
    exit(0);
  }
  echo '[postmaster] registered=' . $r['registered'] . ' synced=' . $r['synced']
     . ' verified=' . $r['verified'] . ' errors=' . count($r['errors']) . PHP_EOL;
  foreach (array_slice($r['errors'], 0, 5) as $e) {
    fwrite(STDERR, '[postmaster] ' . $e . PHP_EOL);
  }
} catch (Throwable $e) {
  fwrite(STDERR, '[postmaster] failed: ' . get_class($e) . ' ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

exit(0);
