<?php
/**
 * mailcow-dockerized-Max — 域名健康检查（发信投递）
 *
 * 检查项：
 *   1. SPF 语义（是否存在、是否 -all、是否包含本机 MX 的 A 记录）
 *   2. DKIM（redis 中的 selector 是否与 DNS 上实际发布的公钥对应）
 *   3. DMARC（是否存在、策略强度）
 *   4. MX 与服务器主机名一致性
 *   5. 出口 IP 反向解析（PTR）与 myhostname 一致性
 *   6. 出口 IP 黑名单状态（多 RBL 查询）
 *   7. 域名年龄（新注册域名会被 Gmail 等接收方审慎对待）
 *   8. 本机 rspamd 对该域出站邮件的实际打分（从 rspamd history 读取）
 *   9. A 记录 / 网站可达性（根域与 www 是否有 A 记录；没有网站的域名会被 Gmail 等接收方审慎对待）
 *
 * 只读：不修改任何配置，仅查询 DNS 与本地 redis。
 * 返回 HTML 片段（与 dns_diagnostics.php 的约定一致）。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

// 纯函数库（无 I/O）。放在非 lib/ 路径，保证被 CI 的 php -l 全量扫描覆盖。
require_once __DIR__ . '/../domain_health_lib.inc.php';

// 并发 DNS（只做网络 I/O）。RBL 有 13 个名单，串行在冷路径要 21 秒以上。
require_once __DIR__ . '/../dns_parallel.inc.php';

if (!isset($_SESSION['mailcow_cc_role']) ||
    !in_array($_SESSION['mailcow_cc_role'], array('admin', 'domainadmin'), true)) {
  http_response_code(403);
  echo 'Session invalid';
  exit();
}

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';

// 批量体检不需要单域参数，因此单域校验必须放在批量判定之后，
// 否则 ?all=1 会先被下面的 "Invalid domain" 拦掉。
$batch_requested = (isset($_GET['domains']) && trim((string)$_GET['domains']) !== '')
                || (isset($_GET['all']) && $_GET['all'] == '1');

if (!$batch_requested) {
  if ($domain === '' || !is_valid_domain_name($domain)) {
    http_response_code(400);
    echo 'Invalid domain';
    exit();
  }

  if ($_SESSION['mailcow_cc_role'] === 'domainadmin') {
    $allowed = mailbox('get', 'domain_details', $domain);
    if ($allowed === false) {
      http_response_code(403);
      echo 'No such domain in context';
      exit();
    }
  }
}

/* ------------------------------------------------------------------ *
 * 辅助函数
 * ------------------------------------------------------------------ */

function dhc_ptr($ip) {
  // 走并发原语（同一套解析器选择逻辑）：容器解析器在冷状态下这条查询实测要 7.5 秒，
  // 直连公共解析器只需 ~0.15 秒。单条查询也用批量接口，避免两套解析路径不一致。
  $rev = implode('.', array_reverse(explode('.', (string)$ip))) . '.in-addr.arpa';
  $r = dhc_dns_query_batch_typed(array(array('name' => $rev, 'type' => 12)));
  $vals = isset($r[$rev . '|12']['PTR']) ? $r[$rev . '|12']['PTR'] : array();
  return empty($vals) ? null : $vals[0];
}

/**
 * 组装 RDAP 请求描述（不发出），交给 dhc_http_multi 与其他外部请求一起并发。
 * 这样「取出口 IP」和「查域龄」两个 HTTPS 往返只花一个的时间。
 */
function dhc_rdap_request($domain) {
  $tld = strtolower(substr(strrchr((string)$domain, '.'), 1));
  $bases = array(
    'com' => 'https://rdap.verisign.com/com/v1/domain/',
    'net' => 'https://rdap.verisign.com/net/v1/domain/',
  );
  return array(
    'url'         => (isset($bases[$tld]) ? $bases[$tld] : 'https://rdap.org/domain/') . rawurlencode((string)$domain),
    'timeout'     => 12,
    'follow'      => true,
    'headers'     => array('Accept: application/rdap+json'),
  );
}

/**
 * 并发发出多个 HTTP 请求（curl_multi）。
 *
 * 为什么需要：单域体检要发两个外部 HTTPS —— 出口 IP 探测与 RDAP 域龄，
 * 实测各约 2.5s / 5s。串行做就是 7.5s，而这页本身已经因为 DNS 很慢了。
 *
 * @param array $reqs  key => ['url'=>..,'timeout'=>..,'follow'=>bool,'headers'=>[]]
 * @return array       key => ['code'=>int,'body'=>string,'error'=>string]
 */
function dhc_http_multi(array $reqs) {
  $out = array();
  if (empty($reqs) || !function_exists('curl_multi_init')) {
    foreach (array_keys($reqs) as $k) { $out[$k] = array('code' => 0, 'body' => '', 'error' => 'curl unavailable'); }
    return $out;
  }

  $mh = curl_multi_init();
  $handles = array();
  foreach ($reqs as $k => $r) {
    $ch = curl_init((string)$r['url']);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => !empty($r['follow']),
      CURLOPT_MAXREDIRS      => 4,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT        => isset($r['timeout']) ? (int)$r['timeout'] : 10,
      CURLOPT_USERAGENT      => 'mailcow-domain-health',
      CURLOPT_HTTPHEADER     => isset($r['headers']) ? $r['headers'] : array(),
    ));
    curl_multi_add_handle($mh, $ch);
    $handles[$k] = $ch;
  }

  $running = null;
  do {
    $status = curl_multi_exec($mh, $running);
    if ($running) { curl_multi_select($mh, 0.5); }
  } while ($running && $status === CURLM_OK);

  foreach ($handles as $k => $ch) {
    $body = curl_multi_getcontent($ch);
    $out[$k] = array(
      'code'  => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
      'body'  => is_string($body) ? $body : '',
      'error' => (string)curl_error($ch),
    );
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $out;
}

/**
 * 由 RDAP 响应算出域名年龄（天）。带 24 小时缓存 —— 注册日期不会天天变。
 * @param array $resp dhc_http_multi 的单项结果
 * @return int|null 天；拿不到返回 null（界面显示"未知"，不猜）
 */
function dhc_domain_age_from_response($domain, $resp) {
  $cache_key = 'DHC_RDAP/' . $domain;
  global $redis;
  if ($redis) {
    $cached = $redis->get($cache_key);
    if ($cached !== false && $cached !== null) {
      $d = json_decode($cached, true);
      if (is_array($d) && array_key_exists('registered', $d)) {
        return $d['registered'] ? intdiv(time() - (int)$d['registered'], 86400) : null;
      }
    }
  }

  $registered = null;
  $registrar  = null;
  if (isset($resp['body']) && $resp['body'] !== '' && (int)($resp['code'] ?? 0) === 200) {
    $j = json_decode($resp['body'], true);
    if (is_array($j)) {
      foreach ((array)($j['events'] ?? array()) as $ev) {
        if (($ev['eventAction'] ?? '') === 'registration' && !empty($ev['eventDate'])) {
          $registered = strtotime($ev['eventDate']);
        }
      }
      foreach ((array)($j['entities'] ?? array()) as $ent) {
        if (in_array('registrar', (array)($ent['roles'] ?? array()), true)) {
          foreach ((array)($ent['vcardArray'][1] ?? array()) as $v) {
            if (($v[0] ?? '') === 'fn' && !empty($v[3])) { $registrar = $v[3]; }
          }
        }
      }
    }
  }

  if ($redis) {
    // 只缓存「成功拿到日期」的结果：查失败时缓存 null 会把一次偶发故障固化 24 小时
    if ($registered) {
      $redis->setex($cache_key, 86400, json_encode(array(
        'registered' => $registered,
        'registrar'  => $registrar,
      )));
    }
  }
  return $registered ? intdiv(time() - $registered, 86400) : null;
}

/** 从并发批次里取某名字某类型的值（取不到返回空数组） */
function dhc_batch_get($dns, $name, $type) {
  $k = (string)$name . '|' . (int)$type;
  return isset($dns[$k][$type]) ? $dns[$k][$type] : array();
}
function dhc_txt_of_batch($dns, $name) { return dhc_batch_get($dns, $name, 16); }
function dhc_a_of_batch($dns, $name)   { return dhc_batch_get($dns, $name, 1); }

/** PTR 查询（带 24h 缓存：出口 IP 的反解不会频繁变） */
function dhc_ptr_cached($ip) {
  $cache_key = 'DHC_PTR/' . $ip;
  global $redis;
  if ($redis) {
    $cached = $redis->get($cache_key);
    if ($cached !== false && $cached !== null) {
      $d = json_decode($cached, true);
      if (is_array($d) && array_key_exists('ptr', $d)) {
        return $d['ptr'] !== '' ? $d['ptr'] : null;
      }
    }
  }
  $ptr = dhc_ptr($ip);
  if ($redis) {
    $redis->setex($cache_key, 86400, json_encode(array('ptr' => $ptr === null ? '' : $ptr)));
  }
  return $ptr;
}

/**
 * 黑名单查询。注意：Spamhaus 对公共解析器返回 127.255.255.254（查询被拒），
 * 这不是"被列入"，必须单独识别，否则会误报。
 */
function dhc_rbl_check($ip) {
  // 13 个名单 × 逐条 DNS 查询，实测约 21.6 秒，是本页最大的耗时来源。
  // 缓存 1 小时：既把重复查询降到接近 0，也避免批量体检时连续撞各名单的
  // 查询频率限制（Spamhaus 等对公共解析器会返回 127.255.255.x 拒绝服务）。
  $cache_key = 'DHC_RBL/' . $ip;
  global $redis;
  if ($redis) {
    $cached = $redis->get($cache_key);
    if ($cached !== false && $cached !== null) {
      $d = json_decode($cached, true);
      if (is_array($d) && isset($d['listed'], $d['refused'])) {
        $d['cached'] = true;
        return $d;
      }
    }
  }

  $rev = implode('.', array_reverse(explode('.', $ip)));
  $zones = array(
    'zen.spamhaus.org'        => 'Spamhaus ZEN',
    'bl.spamcop.net'          => 'SpamCop',
    'b.barracudacentral.org'  => 'Barracuda',
    'dnsbl.sorbs.net'         => 'SORBS',
    'psbl.surriel.com'        => 'PSBL',
    'ubl.unsubscore.com'      => 'Lashback UBL',
    'dnsbl-1.uceprotect.net'  => 'UCEPROTECT L1',
    'dnsbl-2.uceprotect.net'  => 'UCEPROTECT L2',
    'dnsbl-3.uceprotect.net'  => 'UCEPROTECT L3',
    'cbl.abuseat.org'         => 'CBL',
    'dnsbl.cyberlogic.net'    => 'Cyberlogic',
    'all.s5h.net'             => 's5h.net',
    'spam.spamrats.com'       => 'SpamRats',
  );
  $listed = array();
  $refused = array();
  // 并发查询：13 个名单串行时冷路径实测 21.6 秒（单个名字最慢 5.8s），
  // 改成同时发出后整体降到「最慢那一个」的量级。
  // 失败/超时的名字会以空数组返回，语义与串行版的 "continue" 一致。
  $answers = dhc_dns_query_a_batch(
    array_map(function ($z) use ($rev) { return $rev . '.' . $z; }, array_keys($zones))
  );
  foreach ($zones as $zone => $label) {
    $ips = isset($answers[$rev . '.' . $zone]) ? $answers[$rev . '.' . $zone] : array();
    if (empty($ips)) {
      continue;
    }
    foreach ($ips as $ip) {
      // 127.255.255.x = 查询被拒（非列入）—— 判定见 dhc_rbl_classify()
      if (dhc_rbl_classify($ip) === 'refused') {
        $refused[] = $label;
      } else {
        $listed[] = $label . ' (' . $ip . ')';
      }
    }
  }
  $out = array(
    'listed'  => $listed,
    'refused' => $refused,
    'zones'   => count($zones),
    'cached'  => false,
  );
  if ($redis) {
    $redis->setex($cache_key, 3600, json_encode($out));
  }
  return $out;
}

/** 本机 rspamd 最近对该域的出站打分（取最高分的一条） */
function dhc_rspamd_scores($domain) {
  global $redis;
  if (!$redis) {
    return array();
  }
  $out = array();
  try {
    $lines = $redis->lRange('RS_0_HISTORY', 0, 400);
  } catch (Exception $e) {
    return array();
  }
  if (!is_array($lines)) {
    return array();
  }
  foreach ($lines as $line) {
    $j = json_decode($line, true);
    if (!is_array($j) || empty($j['from'])) {
      continue;
    }
    if (substr(strrchr($j['from'], '@'), 1) !== $domain) {
      continue;
    }
    $out[] = array(
      'score'  => (float)($j['score'] ?? 0),
      'action' => (string)($j['action'] ?? ''),
      'symbols'=> $j['symbols'] ?? array(),
      'time'   => (int)($j['time'] ?? 0),
    );
    if (count($out) >= 5) {
      break;
    }
  }
  return $out;
}

/* ------------------------------------------------------------------ *
 * 采集（按域）—— 单域视图与批量体检共用同一实现
 * ------------------------------------------------------------------ */

/**
 * 采集一个域的全部原始诊断数据（只采集，不判定、不渲染）。
 *
 * 返回的键名与原局部变量一致，便于 dhc_build_checks() 直接取用。
 * 采集与判定分离，是为了让同一份数据既能渲染 HTML，也能输出 JSON、
 * 或用于批量体检，而不必重复实现检查逻辑。
 */
function dhc_collect($domain) {
  global $redis;

// 0. 先并行启动「出口 IP 探测」——它是一个外部 HTTPS 请求（冷路径实测 2.5s）。
//    PHP 单线程，没法与后面的 DNS 真正并行，但可以用 curl_multi 把
//    「取出口 IP」与「域名年龄 RDAP」两个外部 HTTP 一起发出去，
//    省掉一次完整往返。DNS 部分则由 dns_parallel 批量并发。
$ext = dhc_http_multi(array(
  'pubip' => array('url' => 'http://ip4.mailcow.email', 'timeout' => 6),
  'rdap'  => dhc_rdap_request($domain),
));

// 1. 把本域需要的**所有 DNS 查询**收集成一个批次，一次性并发发出。
//    冷路径下每个名字首次解析要 0.3–2.7 秒，串行做十几条就是十几秒。
//    同一个名字的多种类型（如根的 A 与 TXT）用不同 key 并存，互不覆盖。
$q = array(
  array('name' => $domain,          'type' => 16),   // TXT  → SPF
  array('name' => '_dmarc.' . $domain, 'type' => 16), // TXT  → DMARC
  array('name' => $domain,          'type' => 15),   // MX
  array('name' => $domain,          'type' => 1),    // A    → 网站可达性
  array('name' => 'www.' . $domain, 'type' => 1),    // A    → www
);

// DKIM selector 要从 redis 取，取到才有对应的 TXT 名字
$dkim_selector = null;
// 必须判空：本文件其余 redis 调用都有 `if ($redis)` 保护，只有这里曾经漏了。
// 而且 `$redis` 为 null 时抛的是 `Error`（不是 `Exception`），只 catch Exception
// 拦不住，结果是整个诊断页 Fatal error —— 偏偏这是出问题时最该能打开的页面。
if ($redis) {
  try {
    $dkim_selector = $redis->hGet('DKIM_SELECTORS', $domain);
  } catch (Throwable $e) {
    error_log('domain_health: redis hGet(DKIM_SELECTORS) failed: ' . get_class($e) . ' ' . $e->getMessage());
    $dkim_selector = null;
  }
}
if ($dkim_selector) {
  $dkim_dns = $dkim_selector . '._domainkey.' . $domain;
  $q[] = array('name' => $dkim_dns, 'type' => 16);
} else {
  $dkim_dns = null;
}

$dns = dhc_dns_query_batch_typed($q);

// 出口 IP 拿到后才能查 PTR 与 13 个 RBL 名单 —— 这是第二批（也并发）
$pubip = null;
if (!empty($ext['pubip']['body'])) {
  $cand = trim((string)$ext['pubip']['body']);
  if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $pubip = $cand; }
}

$rbl = array('listed' => array(), 'refused' => array(), 'zones' => 0, 'cached' => false);
$ptr = null;
if ($pubip) {
  $ptr = dhc_ptr_cached($pubip);
  $rbl = dhc_rbl_check($pubip);
}

// ---- 结果整理 ----
// SPF
$spf = null;
foreach (dhc_txt_of_batch($dns, $domain) as $t) {
  if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; }
}
$spf_dns_lookups = $spf === null ? 0 : preg_match_all('/\b(include|a|mx|ptr|exists|redirect|exp)[:.\s]/i', $spf, $m);
$spf_has_all_minus = $spf !== null && preg_match('/\s-all\s*$/i', trim($spf));
$spf_has_all_soft  = $spf !== null && preg_match('/\s~all\s*$/i', trim($spf));

// DKIM
$dkim_dns_name = $dkim_dns;
$dkim_published = false;
$dkim_key_bits  = null;
if ($dkim_dns !== null) {
  foreach (dhc_txt_of_batch($dns, $dkim_dns) as $t) {
    if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) {
      $dkim_published = true;
      if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $t, $mm)) {
        $dkim_key_bits = strlen(base64_decode($mm[1], true) ?: '') * 8;
      }
      break;
    }
  }
}

// DMARC
$dmarc = null;
foreach (dhc_txt_of_batch($dns, '_dmarc.' . $domain) as $t) {
  if (stripos($t, 'v=DMARC1') === 0) { $dmarc = $t; break; }
}
$dmarc_policy = null;
$dmarc_has_rua = false;
if ($dmarc !== null) {
  if (preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dmarc, $mm)) {
    $dmarc_policy = strtolower($mm[1]);
  }
  $dmarc_has_rua = stripos($dmarc, 'rua=') !== false;
}

// MX（批次里存成 "10 mx.example.com"，与原 dns_get_record 的 target 语义对齐）
$mxs = array();
foreach (dhc_batch_get($dns, $domain, 15) as $rec) {
  $parts = explode(' ', (string)$rec, 2);
  if (!empty($parts[1])) { $mxs[] = strtolower(rtrim($parts[1], '.')); }
}
$hostname = strtolower((string)getenv('MAILCOW_HOSTNAME'));
$mx_points_here = in_array($hostname, $mxs, true);

$ptr_matches = $ptr !== null && $hostname !== '' && $ptr === $hostname;

// 域名年龄（RDAP 已在上面与出口 IP 一起并发发出）
$age_days = dhc_domain_age_from_response($domain, $ext['rdap']);

// rspamd 实际打分
$scores = dhc_rspamd_scores($domain);

// A 记录（网站可达性）
$a_records   = dhc_a_of_batch($dns, $domain);
$www_records = dhc_a_of_batch($dns, 'www.' . $domain);

  return compact(
    'domain',
    'spf', 'spf_dns_lookups', 'spf_has_all_minus', 'spf_has_all_soft',
    'dkim_selector', 'dkim_dns_name', 'dkim_published', 'dkim_key_bits',
    'dmarc', 'dmarc_policy', 'dmarc_has_rua',
    'mxs', 'hostname', 'mx_points_here',
    'pubip', 'ptr', 'ptr_matches',
    'rbl', 'age_days', 'scores',
    'a_records', 'www_records'
  );
}




/* ------------------------------------------------------------------ *
 * 主流程
 * ------------------------------------------------------------------ */

$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'html';

// ---- 批量模式：?domains=a.com,b.com 或 ?all=1 ----
$batch_raw  = isset($_GET['domains']) ? (string)$_GET['domains'] : '';
$want_batch = ($batch_raw !== '') || (isset($_GET['all']) && $_GET['all'] == '1');

if ($want_batch) {
  if ($_SESSION['mailcow_cc_role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'message' => 'batch requires admin'));
    exit();
  }

  if ($batch_raw !== '') {
    $want = array_values(array_filter(array_map('trim', explode(',', $batch_raw))));
  } else {
    $want = array();
    $stmt = $pdo->query("SELECT `domain` FROM `domain` WHERE `active` = '1' ORDER BY `domain` ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $want[] = $r['domain']; }
  }

  // 单次最多处理这么多域。RBL 是逐条 DNS 查询（每域十几条），
  // 一次跑完 15 个域会持续几十秒，且可能触发名单限流；
  // 因此强制分片，由前端按片轮询，服务端每次只做一片。
  $CHUNK = 3;
  $total = count($want);
  $want  = array_slice($want, 0, $CHUNK);

  $out = array();
  foreach ($want as $d) {
    if (!is_valid_domain_name($d)) {
      $out[] = array('domain' => $d, 'error' => 'invalid domain name');
      continue;
    }
    // 单域失败不阻断整体
    try {
      $D = dhc_collect($d);
      $checks = dhc_build_checks($D);
      $out[] = array('domain' => $d, 'summary' => dhc_summarize($checks), 'checks' => $checks);
    } catch (Exception $e) {
      $out[] = array('domain' => $d, 'error' => 'check failed: ' . $e->getMessage());
    } catch (Error $e) {
      $out[] = array('domain' => $d, 'error' => 'check failed: ' . $e->getMessage());
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array(
    'ok'         => true,
    'chunk_size' => $CHUNK,
    'total'      => $total,
    'returned'   => count($out),
    'results'    => $out,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
}

// ---- 单域 ----
$D       = dhc_collect($domain);
$checks  = dhc_build_checks($D);
$summary = dhc_summarize($checks);

if ($format === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array(
    'ok'      => true,
    'domain'  => $domain,
    'summary' => $summary,
    'checks'  => $checks,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit();
}
?>
<div class="mb-2">
  <?php
  $badge_cls = array('bad' => 'danger', 'warn' => 'warning text-dark',
                     'unknown' => 'secondary', 'ok' => 'success');
  $badge_lbl = array('bad' => '异常', 'warn' => '注意', 'unknown' => '未知', 'ok' => '正常');
  foreach (array('bad', 'warn', 'unknown', 'ok') as $k): ?>
    <span class="badge bg-<?=$badge_cls[$k]?>"><?=htmlspecialchars($badge_lbl[$k]);?> <?=(int)$summary[$k];?></span>
  <?php endforeach; ?>
</div>
<div class="table-responsive">
  <table class="table table-striped">
    <tbody>
    <tr>
      <th style="width:30%">检查项</th>
      <th style="width:10%">状态</th>
      <th>结论与依据</th>
    </tr>
    <?php foreach ($checks as $c): ?>
    <tr>
      <td><?=htmlspecialchars($c['name']);?></td>
      <td><?=dhc_status_badge($c['status']);?></td>
      <td>
        <?php if ($c['reason'] !== ''): ?>
          <div><?=htmlspecialchars($c['reason']);?></div>
        <?php endif; ?>
        <?php if (!empty($c['evidence'])): ?>
          <small class="text-muted">
            <?php foreach ($c['evidence'] as $e): ?>
              <div><?=htmlspecialchars($e);?></div>
            <?php endforeach; ?>
          </small>
        <?php endif; ?>
        <?php if ($c['fix'] !== ''): ?>
          <div class="mt-1">
            <small><span class="badge bg-light text-dark border">建议</span> <?=htmlspecialchars($c['fix']);?></small>
          </div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="help-block">
  <small>
    <i class="bi bi-info-circle"></i>
    本页为只读诊断，不会修改任何配置。DNS 结果受解析器缓存影响，刚做的改动可能需等待 TTL 到期才会反映。
    黑名单结果缓存 1 小时。<br>
    说明：SPF / DKIM / DMARC 全部通过，并不保证邮件进入收件箱——接收方还会综合评估出口 IP 信誉、域名历史、域名是否有网站内容，以及收件人互动。
    若三项全部通过但仍进垃圾箱，请优先检查「A 记录（网站可达性）」「出口 IP 黑名单」「域名注册时长」与「本机出站打分」四项。
  </small>
</p>
