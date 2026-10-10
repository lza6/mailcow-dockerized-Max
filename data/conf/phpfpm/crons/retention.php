<?php
/**
 * mailcow-dockerized-Max — 数据保留期清理（由 ofelia 每天 04:30 调用一次）
 *
 * 为什么需要它（性能审查结论 + 实测）：
 *   本项目自建的三张表都**只增不减**，而且都直接参与「域名总览」的查询：
 *     - admin_audit_log      每次管理操作 +1 行；audit_count() 还要对它 COUNT(*)
 *     - warmup_log           每计划每天 1 行；被总览的关联子查询反复扫
 *     - overview_mbox_daily  每邮箱每天 1 行；按 day 读取
 *   行数增长会直接反映到页面耗时上，因此需要一个明确的保留期。
 *
 * 安全约束：
 *   - **分批删除**（每批 LIMIT），避免一条长事务把表锁住、影响邮件收发
 *   - 只删早于保留期的行，绝不触碰最近数据
 *   - 任何异常只写 STDERR，不影响其它 cron 任务
 *   - 保留期可通过环境变量覆盖：OV_RETAIN_AUDIT_DAYS / OV_RETAIN_WARMUP_DAYS /
 *     OV_RETAIN_DAILY_DAYS（设为 0 表示该表不清理）
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
  fwrite(STDERR, '[retention] DB connect failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

/** 表 => array(时间列, 环境变量名, 默认保留天数) */
$PLAN = array(
  'admin_audit_log'     => array('created',  'OV_RETAIN_AUDIT_DAYS',  180),
  'warmup_log'          => array('run_date', 'OV_RETAIN_WARMUP_DAYS', 365),
  'overview_mbox_daily' => array('day',      'OV_RETAIN_DAILY_DAYS',  90),
);

$BATCH = 2000;          // 每批删除行数
$MAX_BATCHES = 50;      // 单表单次最多跑多少批，防止异常情况下长时间占用

$grand = 0;
foreach ($PLAN as $table => $spec) {
  list($col, $env, $def) = $spec;
  $envVal = getenv($env);
  $days = ($envVal === false || $envVal === '') ? (int)$def : (int)$envVal;
  if ($days <= 0) {
    echo '[retention] ' . $table . ': 已禁用（' . $env . '=0）' . PHP_EOL;
    continue;
  }

  $total = 0;
  for ($i = 0; $i < $MAX_BATCHES; $i++) {
    try {
      $st = $pdo->prepare(
        "DELETE FROM `" . $table . "`
          WHERE `" . $col . "` < DATE_SUB(CURDATE(), INTERVAL :d DAY)
          LIMIT " . $BATCH
      );
      $st->bindValue(':d', $days, PDO::PARAM_INT);
      $st->execute();
      $n = $st->rowCount();
    } catch (Exception $e) {
      fwrite(STDERR, '[retention] ' . $table . ' 删除失败: ' . $e->getMessage() . PHP_EOL);
      break;
    }
    $total += $n;
    if ($n < $BATCH) { break; }
    usleep(50000);   // 批间让出 50ms，避免长时间占锁
  }

  $grand += $total;
  echo '[retention] ' . $table . '：保留 ' . $days . ' 天，本次清理 ' . $total . ' 行' . PHP_EOL;
}

echo '[retention] 完成，共清理 ' . $grand . ' 行' . PHP_EOL;
