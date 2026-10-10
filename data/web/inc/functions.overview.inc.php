<?php
/**
 * mailcow-dockerized-Max — 域名总览数据构建（页面与 AJAX 共用）
 *
 * 为什么要抽出来：
 *   页面若靠 AJAX 加载首屏，会多一次往返 + 闪现「加载中…」。
 *   把构建逻辑抽成库后，页面可直接内联首屏数据，打开即有内容。
 *
 * 性能原则：
 *   这里只做 DB 查询与 redis 缓存读取，**绝不发起 DNS/RDAP 实时查询**。
 *   所有外部查询（MX/SPF/DKIM/DMARC/RDAP）都由「体检」动作触发后写缓存。
 */

/**
 * 取本机主机名（判断 MX 是否指向我们）
 */
function ov_host() {
  return strtolower(trim((string)getenv('MAILCOW_HOSTNAME')));
}

/**
 * 域名注册时长（天）。走 RDAP，结果缓存 1 天。
 *
 * @param bool $cacheOnly true = 只读缓存，**不发起网络请求**。
 *        页面首屏必须传 true —— 16 个域逐个 RDAP 会让首屏慢到 1.8 秒以上。
 *        只有「体检」动作才传 false。
 * 失败返回 null（界面显示「未知」），不猜测。
 */
function ov_domain_age_days($redis, $domain, $cacheOnly = false) {
  $key = 'OV_RDAP/' . $domain;
  if ($redis) {
    try {
      $raw = $redis->get($key);
      if ($raw !== false && $raw !== null) {
        $d = json_decode($raw, true);
        if (is_array($d) && array_key_exists('registered', $d)) {
          if (!$d['registered']) return null;
          return intdiv(time() - (int)$d['registered'], 86400);
        }
      }
    } catch (Exception $e) {}
  }
  if ($cacheOnly) { return null; }   // 页面路径：缓存没有就返回「未知」，绝不等网络

  $tld = strtolower((string)substr(strrchr($domain, '.'), 1));
  $bases = array('com' => 'https://rdap.verisign.com/com/v1/domain/',
                 'net' => 'https://rdap.verisign.com/net/v1/domain/');
  $url = (isset($bases[$tld]) ? $bases[$tld] : 'https://rdap.org/domain/') . rawurlencode($domain);

  $registered = false;
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT      => 'mailcow-max/1.0',
  ));
  $body = curl_exec($ch);
  curl_close($ch);
  if ($body) {
    $j = json_decode($body, true);
    if (is_array($j) && !empty($j['events'])) {
      foreach ($j['events'] as $ev) {
        if (isset($ev['eventAction']) && $ev['eventAction'] === 'registration' && !empty($ev['eventDate'])) {
          $ts = strtotime($ev['eventDate']);
          if ($ts) { $registered = $ts; }
          break;
        }
      }
    }
  }
  if ($redis) {
    try { $redis->setex($key, 86400, json_encode(array('registered' => $registered))); } catch (Exception $e) {}
  }
  return $registered ? intdiv(time() - $registered, 86400) : null;
}

/**
 * 记录/读取「今日首次看到的邮件数」，用于算「今日新增」。
 *
 * 做法：每封邮箱在当天第一次被看到时，把当时的 messages 存为基线；
 * 之后「今日新增」= 当前 messages - 基线。跨天自动重置。
 * 这是唯一不需要解析日志、也不需要 doveadm 的低成本办法。
 */
function ov_daily_baseline($pdo, $counts) {
  $today = date('Y-m-d');
  $out = array();

  // 确保表存在（首次运行时自建，避免强依赖 init_db 升级）
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `overview_mbox_daily` (
      `username` VARCHAR(255) NOT NULL,
      `day` DATE NOT NULL,
      `base_messages` BIGINT NOT NULL DEFAULT 0,
      PRIMARY KEY (`username`,`day`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Exception $e) { return $out; }

  // 取今天的基线
  try {
    $st = $pdo->prepare("SELECT `username`,`base_messages` FROM `overview_mbox_daily` WHERE `day` = :d");
    $st->execute(array(':d' => $today));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[$r['username']] = (int)$r['base_messages'];
    }
  } catch (Exception $e) { return $out; }

  // 缺基线的补上（忽略主键冲突）
  try {
    $ins = $pdo->prepare("INSERT IGNORE INTO `overview_mbox_daily` (`username`,`day`,`base_messages`) VALUES (:u,:d,:m)");
    foreach ($counts as $u => $m) {
      if (!array_key_exists($u, $out)) {
        $ins->execute(array(':u' => $u, ':d' => $today, ':m' => (int)$m));
        $out[$u] = (int)$m;
      }
    }
  } catch (Exception $e) {}

  return $out;
}

/**
 * 构建总览数据。页面与 AJAX 都调用它。
 *
 * @param bool $ageCacheOnly 域龄是否只读缓存。
 *        页面传 true（保证首屏零网络 I/O）；「体检」传 false 以刷新域龄。
 */
/**
 * 中继号池（Resend）—— 账号、域名绑定、容量与额度汇总。
 *
 * 只读数据库，**不发外部请求**（Resend/CF 的实时状态由「同步」动作刷新后落库）。
 * CF Token 直接从 resend_pool_settings 读，避免为一屏展示去加载整个号池库。
 */
function ov_relay_pool($pdo, $redis) {
  $limit = 3;
  try {
    $v = $redis ? $redis->get('RESEND_POOL_DOMAIN_LIMIT') : false;
    if ($v !== false && $v !== null && (int)$v > 0) { $limit = (int)$v; }
  } catch (Exception $e) {}

  $accounts = array();
  $used = array();
  try {
    foreach ($pdo->query("SELECT `account_id`, COUNT(*) AS c FROM `resend_domains` WHERE `active`=1 GROUP BY `account_id`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $used[(int)$r['account_id']] = (int)$r['c'];
    }
    $rows = $pdo->query("SELECT `id`,`label`,`api_key`,`daily_quota`,`active`,`last_check`,`check_status`
                           FROM `resend_accounts` ORDER BY `active` DESC, `id` ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $k = (string)$r['api_key'];
      $accounts[] = array(
        'id'           => (int)$r['id'],
        'label'        => (string)$r['label'],
        // 只展示掩码；明文 Key 永远不下发到页面
        'key_masked'   => $k === '' ? '' : (substr($k, 0, 8) . '…' . substr($k, -4)),
        'key_len'      => strlen($k),
        'daily_quota'  => (int)$r['daily_quota'],
        'active'       => (int)$r['active'],
        'domains'      => isset($used[(int)$r['id']]) ? $used[(int)$r['id']] : 0,
        'limit'        => $limit,
        'last_check'   => (string)$r['last_check'],
        'check_status' => (string)$r['check_status'],
      );
    }
  } catch (Exception $e) { $accounts = array(); }

  $bindings = array();
  try {
    $rows = $pdo->query("SELECT d.`id`, d.`domain`, d.`account_id`, d.`status`, d.`active`, d.`verified_at`,
                                a.`label` AS `account_label`
                           FROM `resend_domains` d
                           LEFT JOIN `resend_accounts` a ON a.`id` = d.`account_id`
                          ORDER BY d.`domain` ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $bindings[] = array(
        'id' => (int)$r['id'], 'domain' => (string)$r['domain'],
        'account_id' => (int)$r['account_id'], 'account_label' => (string)$r['account_label'],
        'status' => (string)$r['status'], 'active' => (int)$r['active'],
        'verified_at' => (string)$r['verified_at'],
      );
    }
  } catch (Exception $e) { $bindings = array(); }

  $activeN = 0; $cap = 0; $usedTotal = 0; $quotaTotal = 0;
  foreach ($accounts as $a) {
    if ($a['active'] === 1) {
      $activeN++;
      $cap        += $limit;          // 号池总容量 = 启用账号数 × 单账号域名上限
      $usedTotal  += $a['domains'];
      $quotaTotal += $a['daily_quota'];
    }
  }

  $cfToken = '';
  try {
    $st = $pdo->prepare("SELECT `svalue` FROM `resend_pool_settings` WHERE `skey` = 'cf_api_token' LIMIT 1");
    $st->execute();
    $cfToken = (string)$st->fetchColumn();
  } catch (Exception $e) {}

  return array(
    'limit'           => $limit,
    'accounts'        => $accounts,
    'bindings'        => $bindings,
    'active_accounts' => $activeN,
    'capacity'        => $cap,
    'used'            => $usedTotal,
    'left'            => max(0, $cap - $usedTotal),
    'quota_total'     => $quotaTotal,
    'cf_configured'   => $cfToken !== '',
    'cf_masked'       => $cfToken === '' ? '' : (substr($cfToken, 0, 6) . '…' . substr($cfToken, -4)),
    'cf_len'          => strlen($cfToken),
  );
}

/**
 * 养号（Warmup）状态 —— 计划、收件人池、发送日志。
 *
 * 复用 functions.warmup.inc.php 的 warmup_day_index() / warmup_daily_quota()，
 * **不复制公式**：配额算法一旦调整，复制出来的副本必然与调度器漂移，
 * 会出现「界面显示今天该发 5 封、实际调度器按 3 封发」这种最难查的不一致。
 */
function ov_warmup_state($pdo) {
  if (!function_exists('warmup_daily_quota')) {
    require_once __DIR__ . '/functions.warmup.inc.php';
  }
  $plans = array(); $recipients = array(); $logs = array();
  $sentToday = 0; $sentTotal = 0; $failTotal = 0;

  try {
    $rows = $pdo->query("SELECT p.*,
        COALESCE((SELECT SUM(l.`sent`)   FROM `warmup_log` l WHERE l.`plan_id`=p.`id` AND l.`run_date`=CURDATE()),0) AS `sent_today`,
        COALESCE((SELECT COUNT(*)        FROM `warmup_log` l WHERE l.`plan_id`=p.`id` AND l.`run_date`=CURDATE()),0) AS `ran_today`,
        COALESCE((SELECT SUM(l.`sent`)   FROM `warmup_log` l WHERE l.`plan_id`=p.`id`),0) AS `sent_all`,
        COALESCE((SELECT SUM(l.`failed`) FROM `warmup_log` l WHERE l.`plan_id`=p.`id`),0) AS `fail_all`
      FROM `warmup_plans` p ORDER BY p.`id` ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $p) {
      $day = warmup_day_index($p);
      $plans[] = array(
        'id' => (int)$p['id'], 'domain' => (string)$p['domain'], 'sender' => (string)$p['sender'],
        'status' => (string)$p['status'], 'pause_reason' => (string)$p['pause_reason'],
        'start_count' => (int)$p['start_count'], 'step' => (int)$p['step'],
        'max_count' => (int)$p['max_count'], 'total_days' => (int)$p['total_days'],
        'started_on' => (string)$p['started_on'],
        'day' => $day, 'day_quota' => warmup_daily_quota($p),
        'days_left' => max(0, (int)$p['total_days'] - $day),
        'sent_today' => (int)$p['sent_today'],
        // 今天调度器是否真的跑过 —— 只靠 sent_today 无法区分
        // 「配额为 0 没发」和「调度器压根没跑」，必须单独给出
        'ran_today' => ((int)$p['ran_today'] > 0),
        'sent_all' => (int)$p['sent_all'], 'fail_all' => (int)$p['fail_all'],
        'last_run' => (string)$p['last_run'],
      );
      $sentTotal += (int)$p['sent_all'];
      $failTotal += (int)$p['fail_all'];
    }
  } catch (Exception $e) {}

  try {
    foreach ($pdo->query("SELECT `id`,`email`,`label`,`active` FROM `warmup_recipients` ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $recipients[] = array('id' => (int)$r['id'], 'email' => (string)$r['email'],
                            'label' => (string)$r['label'], 'active' => (int)$r['active']);
    }
  } catch (Exception $e) {}

  try {
    $logs = $pdo->query("SELECT l.`id`, l.`plan_id`, l.`run_at`, l.`run_date`, l.`planned`,
                                l.`sent`, l.`failed`, l.`detail`, p.`sender`, p.`domain`
                           FROM `warmup_log` l
                           LEFT JOIN `warmup_plans` p ON p.`id` = l.`plan_id`
                          ORDER BY l.`id` DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}

  try {
    $sentToday = (int)$pdo->query("SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `run_date`=CURDATE()")->fetchColumn();
  } catch (Exception $e) {}

  return array(
    'plans'       => $plans,
    'recipients'  => $recipients,
    'logs'        => $logs,
    'sent_today'  => $sentToday,
    'sent_total'  => $sentTotal,
    'fail_total'  => $failTotal,
    'global_cap'  => defined('WARMUP_GLOBAL_DAILY_CAP') ? WARMUP_GLOBAL_DAILY_CAP : 30,
    'max_per_run' => defined('WARMUP_MAX_PER_RUN') ? WARMUP_MAX_PER_RUN : 3,
    'window'      => (defined('WARMUP_WINDOW_START_HOUR') ? WARMUP_WINDOW_START_HOUR : 8) . ':00 - '
                   . (defined('WARMUP_WINDOW_END_HOUR') ? WARMUP_WINDOW_END_HOUR : 20) . ':00',
  );
}

function overview_build($pdo, $redis, $ageCacheOnly = true) {
  // ---- 域名 + 出站中继 ----
  $domains = $pdo->query(
    "SELECT d.`domain`, d.`active`, d.`created`, r.`hostname` AS `relay_host`
       FROM `domain` d
       LEFT JOIN `relayhosts` r ON r.`id` = d.`relayhost`
      ORDER BY d.`domain` ASC"
  )->fetchAll(PDO::FETCH_ASSOC);

  // ---- 邮箱数（按域）----
  $mboxCnt = array();
  foreach ($pdo->query("SELECT `domain`, COUNT(*) AS n FROM `mailbox` GROUP BY `domain`")->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $mboxCnt[$x['domain']] = (int)$x['n'];
  }

  // ---- 邮件数与占用（来自 quota2，**快**，不用 doveadm）----
  $quotas = array();   // username => ['messages'=>, 'bytes'=>]
  try {
    foreach ($pdo->query("SELECT `username`,`messages`,`bytes` FROM `quota2`")->fetchAll(PDO::FETCH_ASSOC) as $q) {
      $quotas[$q['username']] = array('messages' => (int)$q['messages'], 'bytes' => (int)$q['bytes']);
    }
  } catch (Exception $e) {}

  // 按域汇总邮件数
  $msgByDom = array();
  $countsForBase = array();
  foreach ($quotas as $u => $q) {
    $countsForBase[$u] = $q['messages'];
    $at = strrpos($u, '@');
    if ($at !== false) {
      $dom = substr($u, $at + 1);
      if (!isset($msgByDom[$dom])) $msgByDom[$dom] = 0;
      $msgByDom[$dom] += $q['messages'];
    }
  }
  $base = ov_daily_baseline($pdo, $countsForBase);

  // ---- 养号计划 ----
  $wu = array();
  try {
    foreach ($pdo->query(
      "SELECT p.`id`, p.`domain`, p.`status`, p.`started_on`, p.`start_count`, p.`step`,
              p.`max_count`, p.`total_days`, p.`sent_total`, p.`fail_total`, p.`last_run`,
              COALESCE((SELECT SUM(`sent`) FROM `warmup_log` WHERE `plan_id`=p.`id` AND `run_date`=CURDATE()),0) AS `sent_today`
         FROM `warmup_plans` p"
    )->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $day = 1;
      if (!empty($x['started_on'])) {
        $day = (int)floor((strtotime(date('Y-m-d')) - strtotime($x['started_on'])) / 86400) + 1;
        if ($day < 1) $day = 1;
      }
      $quota = (int)$x['start_count'] + ($day - 1) * (int)$x['step'];
      if ($quota > (int)$x['max_count']) $quota = (int)$x['max_count'];
      $wu[$x['domain']] = array(
        'status' => $x['status'], 'day' => $day, 'total_days' => (int)$x['total_days'],
        'quota' => $quota, 'sent_today' => (int)$x['sent_today'],
        'sent_total' => (int)$x['sent_total'], 'fail_total' => (int)$x['fail_total'],
        'last_run' => $x['last_run'],
      );
    }
  } catch (Exception $e) {}

  // ---- 域名健康缓存 ----
  $out = array();
  foreach ($domains as $d) {
    $c = null;
    if ($redis) {
      try {
        $raw = $redis->get('OV_CHECK/' . $d['domain']);
        if ($raw !== false && $raw !== null) { $c = json_decode($raw, true); }
      } catch (Exception $e) {}
    }
    $bad = 0; $warn = 0;
    if (is_array($c)) {
      if (empty($c['mx_ok']))    $bad++;
      if (empty($c['spf_ok']))   $bad++;
      if (empty($c['dkim_ok']))  $bad++;
      if (empty($c['dmarc_ok'])) $warn++;
    }
    $out[] = array(
      'domain'     => $d['domain'],
      'active'     => (int)$d['active'],
      'created'    => $d['created'],
      'relay'      => ($d['relay_host'] === null ? '' : $d['relay_host']),
      'mailboxes'  => isset($mboxCnt[$d['domain']]) ? $mboxCnt[$d['domain']] : 0,
      'messages'   => isset($msgByDom[$d['domain']]) ? $msgByDom[$d['domain']] : 0,
      'age_days'   => ov_domain_age_days($redis, $d['domain'], $ageCacheOnly),
      'checked'    => is_array($c),
      'checked_at' => is_array($c) ? (string)$c['checked_at'] : '',
      'mx'         => is_array($c) && isset($c['mx']) ? $c['mx'] : array(),
      'mx_ok'      => is_array($c) ? (bool)$c['mx_ok'] : null,
      'spf_ok'     => is_array($c) ? (bool)$c['spf_ok'] : null,
      'dkim_ok'    => is_array($c) ? (bool)$c['dkim_ok'] : null,
      'dmarc_ok'   => is_array($c) ? (bool)$c['dmarc_ok'] : null,
      'bad'        => $bad,
      'warn'       => $warn,
      'warmup'     => isset($wu[$d['domain']]) ? $wu[$d['domain']] : null,
    );
  }

  // ---- 邮箱清单 ----
  $mbox = array();
  foreach ($pdo->query(
    "SELECT `username`,`domain`,`name`,`active`,`quota`,`created` FROM `mailbox`
      ORDER BY `domain` ASC, `username` ASC"
  )->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $u = $m['username'];
    $cur = isset($quotas[$u]) ? $quotas[$u]['messages'] : 0;
    $b   = isset($base[$u]) ? $base[$u] : $cur;
    $mbox[] = array(
      'username'  => $u,
      'domain'    => $m['domain'],
      'name'      => $m['name'],
      'active'    => (int)$m['active'],
      'quota'     => (int)$m['quota'],
      'created'   => $m['created'],
      'messages'  => $cur,
      'today_new' => max(0, $cur - $b),
      'bytes'     => isset($quotas[$u]) ? $quotas[$u]['bytes'] : 0,
    );
  }

  // ---- 中继号池（账号 / 域名绑定 / 容量额度）与 CF 状态 ----
  $relay = null;
  try { $relay = ov_relay_pool($pdo, $redis); } catch (Exception $e) { $relay = null; }

  // ---- 养号（计划 / 收件人 / 日志）----
  $warmup = null;
  try { $warmup = ov_warmup_state($pdo); } catch (Exception $e) { $warmup = null; }

  // ---- 统计 ----
  $todaySent = 0;
  try {
    $todaySent = (int)$pdo->query("SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `run_date`=CURDATE()")->fetchColumn();
  } catch (Exception $e) {}

  $abnormal = 0;
  $totalMsg = 0;
  $totalNew = 0;
  foreach ($out as $x) { if ($x['bad'] > 0) $abnormal++; $totalMsg += $x['messages']; }
  foreach ($mbox as $m) { $totalNew += $m['today_new']; }

  return array(
    'ok'        => true,
    'hostname'  => ov_host(),
    'domains'   => $out,
    'mailboxes' => $mbox,
    'relay'     => $relay,
    'warmup'    => $warmup,
    'stats'     => array(
      'domains'   => count($out),
      'mailboxes' => count($mbox),
      'abnormal'  => $abnormal,
      'today_sent'=> $todaySent,
      'messages'  => $totalMsg,
      'today_new' => $totalNew,
    ),
    'server_time' => date('Y-m-d H:i:s'),
  );
}
