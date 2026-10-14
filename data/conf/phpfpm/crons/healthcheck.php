<?php
/**
 * mailcow-dockerized-Max — 域名体检（由 ofelia 调用，标签见 docker-compose.yml）
 *
 * 为什么需要它：
 *   「域名总览」页上的「收信 MX / 认证」两列读的是 redis 里的 `OV_CHECK/<域>`，
 *   而那个缓存**只有 1 小时 TTL**。没有定时任务时，管理员每次打开页面看到的
 *   大概率都是「未检测」—— 除非他恰好在一小时前手动点过「体检」。
 *
 * 本任务的运行策略（**队列驱动 + 定期全量兜底**）：
 *   1) 若 `OV_CHECK_PENDING` 队列非空（页面发现空缓存时会入队）→ 只查队列里的域；
 *   2) 否则若距上次全量 ≥25 分钟 → 全量刷一遍；
 *   3) 两者都不满足 → **立即退出，不发任何 DNS/RDAP 请求**（稳态成本≈0）。
 *
 *   (1) 解决"新域/缓存被清后要干等 30 分钟"，
 *   (2) 解决"缓存存在但 TTL 已过期、或 DNS 值已变" —— 这两类队列发现不了。
 *   25 < 30，保证在 OV_CHECK 的 1 小时 TTL 到期前至少刷到一次。
 *
 * ⚠️ 这里**有意复制**了 `inc/ajax/overview.php` 里 `ov_run_check()` 的 DNS 判定逻辑，
 *    而不是 require 那个文件：那个函数定义在**端点文件内部**，端点 require 即执行，
 *    在 CLI 下会被它的主流程与 CSRF 网关拦住。要复用就必须先把函数抽到
 *    inc/ 里 —— 那是独立的重构，不在本次范围内。
 *    两处逻辑若有一处改动，**必须同时改另一处**。
 *
 * ✅ 例外：**RDAP / 域名年龄**已不再重复实现 —— 本文件直接调用
 *    `inc/functions.overview.inc.php` 的 `ov_domain_age_days($redis, $domain, false)`，
 *    与页面侧共用同一份缓存语义（成功 24h / 未注册 1h / 失败 300s）。
 *    历史教训：这里曾有一份独立 curl，失败时写 `registered=false` 且 24h TTL，
 *    与页面侧写入互相污染，导致「未知」被固化一整天。
 *
 * 安全/资源约束：
 *   - 只做只读 DNS 查询 + 写 redis，**不碰数据库配置、不改任何配置**
 *   - 队列空时零网络请求；全量轮每域 3~4 次查询，实测 16 域约 3 秒
 *   - 异常只写 STDERR，不影响其它 cron
 */

require_once(__DIR__ . '/../web/inc/vars.inc.php');
if (file_exists(__DIR__ . '/../web/inc/vars.local.inc.php')) {
  include_once(__DIR__ . '/../web/inc/vars.local.inc.php');
}
// ov_domain_age_days / ov_mark_pending / ov_take_pending 等（纯函数，无副作用）
require_once(__DIR__ . '/../web/inc/functions.overview.inc.php');

$dsn = $database_type . ":unix_socket=" . $database_sock . ";dbname=" . $database_name;
$opt = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $database_user, $database_pass, $opt);
} catch (PDOException $e) {
  fwrite(STDERR, '[healthcheck] DB connect failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

// redis（缓存要写到它里面；连不上就只做检查不写缓存）
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
  fwrite(STDERR, '[healthcheck] redis 不可用，本次只检查不写缓存: ' . $e->getMessage() . PHP_EOL);
  $redis = null;
}

$host = strtolower((string)getenv('MAILCOW_HOSTNAME'));
$checked = 0; $abnormal = 0;

// ---- 决定本轮处理哪些域（队列驱动 + 定期全量兜底）----
$lastFullKey = 'OV_CHECK_LAST_FULL';
$wantAll = true;
if ($redis) {
  try {
    $last = (int)$redis->get($lastFullKey);
    if ($last > 0 && (time() - $last) < 25 * 60) { $wantAll = false; }
  } catch (Throwable $e) {}
}

if ($wantAll) {
  $targets = array();
  foreach ($pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll() as $x) {
    $targets[] = $x['domain'];
  }
  $mode = 'full';
} else {
  // 队列为空 → 不发任何 DNS/RDAP 请求，直接退出。
  // 这是本策略最重要的一条：稳态下 cron 成本 ≈ 0，
  // 从而可以放心把间隔保持在 30 分钟而不与别的 cron 抢 CPU。
  $targets = ov_take_pending($redis, 50);
  $mode = 'queue';
  if (!$targets) {
    echo '[healthcheck] queue empty, skip' . PHP_EOL;
    exit(0);
  }
}

// 活跃域集合：队列里的域可能已停用/已删除（入队后管理员改了状态），
// 必须复核，否则会对不该管的域作 DNS 查询，还会把 OV_CHECK 写回去，
// 让总览页显示一个"已停用但健康"的假象。
$activeSet = array();
try {
  foreach ($pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1'")->fetchAll() as $x) {
    $activeSet[$x['domain']] = true;
  }
} catch (Throwable $e) {}

try {
  foreach ($targets as $domain) {
    if (!isset($activeSet[$domain])) { continue; }
    $r = array('domain' => $domain, 'checked_at' => date('Y-m-d H:i:s'));

    // ---- MX ----
    $mxs = array();
    $res = @dns_get_record($domain, DNS_MX);
    if (is_array($res)) {
      foreach ($res as $y) { if (!empty($y['target'])) { $mxs[] = strtolower(rtrim($y['target'], '.')); } }
    }
    $r['mx'] = $mxs;
    $r['mx_ok'] = in_array($host, $mxs, true);

    // ---- SPF：存在 + 授权本机 + 以 all 收尾 ----
    $spf = null;
    $res = @dns_get_record($domain, DNS_TXT);
    if (is_array($res)) {
      foreach ($res as $y) { $t = $y['txt'] ?? ''; if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; } }
    }
    $r['spf'] = $spf;
    $r['spf_ok'] = false;
    if ($spf !== null) {
      $l = strtolower($spf);
      $covers  = (bool)preg_match('/(?:^|\s)mx(?:\s|:|$)/', $l) || ($host !== '' && strpos($l, ':' . $host) !== false);
      $has_all = (bool)preg_match('/[~\-+?]all(?:\s|$)/', $l);
      $r['spf_ok'] = $covers && $has_all;
    }

    // ---- DKIM：redis 里的 selector → 去 DNS 找公钥，且 p= 必须非空 ----
    //（已撤销的 key 是 "v=DKIM1; p="，只看有没有 p= 会把撤销的判成已发布）
    $sel = null;
    try { $sel = $redis ? $redis->hGet('DKIM_SELECTORS', $domain) : null; } catch (Throwable $e) { $sel = null; }
    $r['dkim_selector'] = $sel;
    $r['dkim_ok'] = false;
    if ($sel) {
      $res = @dns_get_record($sel . '._domainkey.' . $domain, DNS_TXT);
      if (is_array($res)) {
        foreach ($res as $y) {
          $t = $y['txt'] ?? '';
          if (isset($y['entries']) && is_array($y['entries'])) { $t = implode('', $y['entries']); }
          if (stripos($t, 'v=DKIM1') === false) { continue; }
          if (preg_match('/\bp\s*=\s*([A-Za-z0-9+\/\s=]+)/i', str_replace('"', '', $t), $m) && trim($m[1]) !== '') {
            $r['dkim_ok'] = true; break;
          }
        }
      }
    }

    // ---- DMARC ----
    $dmarc = null;
    $res = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
    if (is_array($res)) {
      foreach ($res as $y) { $t = $y['txt'] ?? ''; if (stripos($t, 'v=DMARC1') === 0) { $dmarc = $t; break; } }
    }
    $r['dmarc'] = $dmarc;
    $r['dmarc_ok'] = $dmarc !== null;

    $wrote = false;
    if ($redis) {
      try { $wrote = (bool)$redis->setex('OV_CHECK/' . $domain, 3600, json_encode($r)); }
      catch (Throwable $e) { $wrote = false; }
    }
    // 本轮没写成缓存（redis 写失败）→ 重新排队，而不是干等下一个 25 分钟的全量。
    // 这是自愈不是重试风暴：每轮最多取 50 个，且要等到下一个 tick 才会再取。
    if ($redis && !$wrote) {
      try { $redis->zAdd(OV_PENDING_KEY, time(), $domain); } catch (Throwable $e) {}
    }

    // ---- 域名年龄 ----
    // 共用 inc/functions.overview.inc.php 的实现（成功 24h / 未注册 1h / 失败 300s）。
    // 此前这里有一份独立的 curl 副本，失败时写 registered=false + 86400 TTL，
    // 与页面侧写入互相污染 —— 删掉副本就消除了这个结构性根因。
    ov_domain_age_days($redis, $domain, false);

    $checked++;
    if (!$r['mx_ok'] || !$r['spf_ok'] || !$r['dkim_ok'] || !$r['dmarc_ok']) { $abnormal++; }
  }

  // 记下这次全量时间，供下一轮判断是否需要全量
  if ($mode === 'full' && $redis) {
    try { $redis->setex($lastFullKey, 3600, (string)time()); } catch (Throwable $e) {}
  }
  echo '[healthcheck] mode=' . $mode . ' checked=' . $checked . ' abnormal=' . $abnormal . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[healthcheck] failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

exit(0);
