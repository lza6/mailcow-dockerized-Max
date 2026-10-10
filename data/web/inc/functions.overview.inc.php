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

  // ---- 号池（Resend）----
  $pool = null;
  try {
    $accs = $pdo->query(
      "SELECT a.`id`, a.`label`, a.`active`, a.`check_status`, a.`daily_quota`,
              (SELECT COUNT(*) FROM `resend_domains` d WHERE d.`account_id`=a.`id`) AS `domains`
         FROM `resend_accounts` a ORDER BY a.`id` ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $pool = array();
    foreach ($accs as $a) {
      $pool[] = array(
        'id' => (int)$a['id'], 'label' => $a['label'], 'active' => (int)$a['active'],
        'status' => $a['check_status'], 'quota' => (int)$a['daily_quota'],
        'domains' => (int)$a['domains'],
      );
    }
  } catch (Exception $e) { $pool = null; }

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
    'pool'      => $pool,
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
