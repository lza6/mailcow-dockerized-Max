<?php
/**
 * mailcow-dockerized-Max — 养号调度器（由 ofelia 每 5 分钟调用一次）
 *
 * 逻辑：每次运行都由 warmup_run() 自行判断"今日随机时刻是否已到"、
 * 今日配额是否用尽，因此高频调用是安全的——不会多发包。
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
}
catch (PDOException $e) {
  fwrite(STDERR, '[warmup] DB connect failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

require_once(__DIR__ . '/../web/inc/functions.warmup.inc.php');

// 主库判定：多节点部署时只在 master 上跑
$isMaster = getenv('MASTER');
if ($isMaster !== false && $isMaster !== 'y') {
  echo '[warmup] not master, skip' . PHP_EOL;
  exit(0);
}

try {
  $r = warmup_run($pdo, false);
}
catch (Exception $e) {
  fwrite(STDERR, '[warmup] run failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

$parts = array();
$parts[] = 'plans=' . $r['checked'];
$parts[] = 'sent=' . $r['sent'];
$parts[] = 'failed=' . $r['failed'];
if (!empty($r['paused'])) {
  $parts[] = 'paused=[' . implode(',', $r['paused']) . ']';
}
if (!empty($r['skipped'])) {
  $parts[] = 'skipped=' . count($r['skipped']);
}

// 无动作时不刷屏
if ($r['sent'] === 0 && $r['failed'] === 0 && !empty($r['skipped'])) {
  echo '[warmup] idle (' . implode('; ', array_slice($r['skipped'], 0, 3)) . ')' . PHP_EOL;
  exit(0);
}

echo '[warmup] ' . implode(' ', $parts) . PHP_EOL;
foreach ($r['details'] as $d) {
  echo '  ' . $d . PHP_EOL;
}
