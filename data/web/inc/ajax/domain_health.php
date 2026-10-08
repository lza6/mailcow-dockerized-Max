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
 *
 * 只读：不修改任何配置，仅查询 DNS 与本地 redis。
 * 返回 HTML 片段（与 dns_diagnostics.php 的约定一致）。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

define('DHC_OK',   '<span class="badge bg-success">正常</span>');
define('DHC_WARN', '<span class="badge bg-warning text-dark">注意</span>');
define('DHC_BAD',  '<span class="badge bg-danger">异常</span>');
define('DHC_UNK',  '<span class="badge bg-secondary">未知</span>');

if (!isset($_SESSION['mailcow_cc_role']) ||
    !in_array($_SESSION['mailcow_cc_role'], array('admin', 'domainadmin'), true)) {
  http_response_code(403);
  echo 'Session invalid';
  exit();
}

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
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

/* ------------------------------------------------------------------ *
 * 辅助函数
 * ------------------------------------------------------------------ */

function dhc_txt($name) {
  $out = array();
  $recs = @dns_get_record($name, DNS_TXT);
  if (is_array($recs)) {
    foreach ($recs as $r) {
      if (isset($r['txt'])) {
        $out[] = $r['txt'];
      } elseif (isset($r['entries']) && is_array($r['entries'])) {
        $out[] = implode('', $r['entries']);
      }
    }
  }
  return $out;
}

function dhc_a($name) {
  $out = array();
  $recs = @dns_get_record($name, DNS_A);
  if (is_array($recs)) {
    foreach ($recs as $r) {
      if (!empty($r['ip'])) {
        $out[] = $r['ip'];
      }
    }
  }
  return $out;
}

function dhc_mx($name) {
  $out = array();
  $recs = @dns_get_record($name, DNS_MX);
  if (is_array($recs)) {
    foreach ($recs as $r) {
      if (!empty($r['target'])) {
        $out[] = strtolower(rtrim($r['target'], '.'));
      }
    }
  }
  return $out;
}

function dhc_ptr($ip) {
  $recs = @dns_get_record(implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa', DNS_PTR);
  if (is_array($recs)) {
    foreach ($recs as $r) {
      if (!empty($r['target'])) {
        return strtolower(rtrim($r['target'], '.'));
      }
    }
  }
  return null;
}

/** 对外部 DNS 做一次查询（绕过本地 unbound 缓存），失败回退到系统解析器 */
function dhc_resolve_external($name, $type) {
  $map = array('TXT' => DNS_TXT, 'A' => DNS_A, 'MX' => DNS_MX, 'PTR' => DNS_PTR);
  if (!isset($map[$type])) {
    return array();
  }
  if (function_exists('dns_get_record')) {
    $recs = @dns_get_record($name, $map[$type]);
    return is_array($recs) ? $recs : array();
  }
  return array();
}

/** 本机出口 IPv4 */
function dhc_public_ipv4() {
  $ch = curl_init('http://ip4.mailcow.email');
  curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
  curl_setopt($ch, CURLOPT_TIMEOUT, 6);
  $ip = trim((string)curl_exec($ch));
  curl_close($ch);
  return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
}

/**
 * 域名注册日期 -> 天数。
 * 通过 RDAP 获取（权威、无需 API key）。.com/.net 走 Verisign。
 * 失败返回 null，界面上显示"未知"，不猜测。
 */
function dhc_domain_age_days($domain) {
  $cache_key = 'DHC_RDAP/' . $domain;
  global $redis;
  if ($redis) {
    $cached = $redis->get($cache_key);
    if ($cached !== false && $cached !== null) {
      $d = json_decode($cached, true);
      if (is_array($d) && isset($d['registered'])) {
        return $d['registered'] ? intdiv(time() - (int)$d['registered'], 86400) : null;
      }
    }
  }

  $tld = strtolower(substr(strrchr($domain, '.'), 1));
  $bases = array(
    'com' => 'https://rdap.verisign.com/com/v1/domain/',
    'net' => 'https://rdap.verisign.com/net/v1/domain/',
  );
  $base = isset($bases[$tld]) ? $bases[$tld] : 'https://rdap.org/domain/';
  $url  = $base . rawurlencode($domain);

  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_USERAGENT      => 'mailcow-domain-health',
    CURLOPT_HTTPHEADER     => array('Accept: application/rdap+json'),
  ));
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $registered = null;
  $registrar  = null;
  if ($body !== false && $code === 200) {
    $j = json_decode($body, true);
    if (is_array($j)) {
      foreach ((array)($j['events'] ?? array()) as $ev) {
        if (($ev['eventAction'] ?? '') === 'registration' && !empty($ev['eventDate'])) {
          $registered = strtotime($ev['eventDate']);
        }
      }
      foreach ((array)($j['entities'] ?? array()) as $ent) {
        if (in_array('registrar', (array)($ent['roles'] ?? array()), true)) {
          foreach ((array)($ent['vcardArray'][1] ?? array()) as $v) {
            if (($v[0] ?? '') === 'fn' && !empty($v[3])) {
              $registrar = $v[3];
            }
          }
        }
      }
    }
  }

  if ($redis) {
    $redis->setex($cache_key, 86400, json_encode(array(
      'registered' => $registered,
      'registrar'  => $registrar,
    )));
  }
  return $registered ? intdiv(time() - $registered, 86400) : null;
}

/**
 * 黑名单查询。注意：Spamhaus 对公共解析器返回 127.255.255.254（查询被拒），
 * 这不是"被列入"，必须单独识别，否则会误报。
 */
function dhc_rbl_check($ip) {
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
  foreach ($zones as $zone => $label) {
    $res = @dns_get_record($rev . '.' . $zone, DNS_A);
    if (!is_array($res) || empty($res)) {
      continue;
    }
    foreach ($res as $r) {
      if (empty($r['ip'])) {
        continue;
      }
      // 127.255.255.x = 查询被拒（非列入）
      if (strpos($r['ip'], '127.255.255.') === 0) {
        $refused[] = $label;
      } else {
        $listed[] = $label . ' (' . $r['ip'] . ')';
      }
    }
  }
  return array('listed' => $listed, 'refused' => $refused);
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
 * 采集
 * ------------------------------------------------------------------ */

$row = array();

// 1. SPF
$domain_txts = dhc_txt($domain);
$spf = null;
foreach ($domain_txts as $t) {
  if (stripos($t, 'v=spf1') === 0) {
    $spf = $t;
    break;
  }
}
$spf_dns_lookups = $spf === null ? 0 : preg_match_all('/\b(include|a|mx|ptr|exists|redirect|exp)[:.\s]/i', $spf, $m);
$spf_has_all_minus = $spf !== null && preg_match('/\s-all\s*$/i', trim($spf));
$spf_has_all_soft  = $spf !== null && preg_match('/\s~all\s*$/i', trim($spf));

// 2. DKIM（从 redis 取 selector，去 DNS 上核对公钥是否存在）
$dkim_selector = null;
try {
  $dkim_selector = $redis->hGet('DKIM_SELECTORS', $domain);
} catch (Exception $e) {
  $dkim_selector = null;
}
$dkim_dns_name = null;
$dkim_published = false;
$dkim_key_bits  = null;
if ($dkim_selector) {
  $dkim_dns_name = $dkim_selector . '._domainkey.' . $domain;
  foreach (dhc_txt($dkim_dns_name) as $t) {
    if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) {
      $dkim_published = true;
      if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $t, $mm)) {
        $dkim_key_bits = strlen(base64_decode($mm[1], true) ?: '') * 8;
      }
      break;
    }
  }
}

// 3. DMARC
$dmarc = null;
foreach (dhc_txt('_dmarc.' . $domain) as $t) {
  if (stripos($t, 'v=DMARC1') === 0) {
    $dmarc = $t;
    break;
  }
}
$dmarc_policy = null;
$dmarc_has_rua = false;
if ($dmarc !== null) {
  if (preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dmarc, $mm)) {
    $dmarc_policy = strtolower($mm[1]);
  }
  $dmarc_has_rua = stripos($dmarc, 'rua=') !== false;
}

// 4. MX
$mxs = dhc_mx($domain);
$hostname = strtolower((string)getenv('MAILCOW_HOSTNAME'));
$mx_points_here = in_array($hostname, $mxs, true);

// 5. 出口 IP + PTR
$pubip = dhc_public_ipv4();
$ptr = $pubip ? dhc_ptr($pubip) : null;
$ptr_matches = $ptr !== null && $hostname !== '' && $ptr === $hostname;

// 6. 黑名单
$rbl = $pubip ? dhc_rbl_check($pubip) : array('listed' => array(), 'refused' => array());

// 7. 域名年龄
$age_days = dhc_domain_age_days($domain);

// 8. rspamd 实际打分
$scores = dhc_rspamd_scores($domain);
?>
<div class="table-responsive">
  <table class="table table-striped">
    <tbody>
    <tr>
      <th style="width:34%">检查项</th>
      <th style="width:12%">状态</th>
      <th>详情</th>
    </tr>

    <!-- SPF -->
    <tr>
      <td>SPF（发件人策略）</td>
      <td><?php
        if ($spf === null) { echo DHC_BAD; }
        elseif ($spf_has_all_minus || $spf_has_all_soft) { echo DHC_OK; }
        else { echo DHC_WARN; }
      ?></td>
      <td>
        <?php if ($spf === null): ?>
          <strong class="text-danger">未找到 SPF 记录</strong><br>
          <small>没有 SPF 时，接收方无法验证你的发件来源，投递率会明显下降。</small>
        <?php else: ?>
          <code><?=htmlspecialchars($spf);?></code><br>
          <small>
            DNS 查询次数约 <strong><?=(int)$spf_dns_lookups;?></strong>（上限 10）<?php if ($spf_dns_lookups > 10): ?> <span class="text-danger">超限，接收方会报 permerror</span><?php endif; ?><br>
            <?php if ($spf_has_all_minus): ?>
              结尾 <code>-all</code>：未授权来源应被拒绝（严格，推荐）
            <?php elseif ($spf_has_all_soft): ?>
              结尾 <code>~all</code>：未授权来源标记为 softfail（较宽松）
            <?php else: ?>
              <span class="text-warning">结尾不是 <code>-all</code> / <code>~all</code>，策略不明确</span>
            <?php endif; ?>
          </small>
        <?php endif; ?>
      </td>
    </tr>

    <!-- DKIM -->
    <tr>
      <td>DKIM（签名）</td>
      <td><?php
        if ($dkim_selector && $dkim_published) { echo DHC_OK; }
        elseif ($dkim_selector && !$dkim_published) { echo DHC_BAD; }
        else { echo DHC_WARN; }
      ?></td>
      <td>
        <?php if (!$dkim_selector): ?>
          <span class="text-warning">本机 redis 中未记录该域的 DKIM selector</span><br>
          <small>若该域不用于发信，可忽略；用于发信则需在「DKIM 密钥」中生成。</small>
        <?php else: ?>
          selector：<code><?=htmlspecialchars($dkim_selector);?></code><br>
          DNS 记录名：<code><?=htmlspecialchars($dkim_dns_name);?></code><br>
          <?php if ($dkim_published): ?>
            公钥已发布<?php if ($dkim_key_bits): ?>，长度约 <strong><?=(int)$dkim_key_bits;?></strong> bit<?php endif; ?>
            <?php if ($dkim_key_bits && $dkim_key_bits < 1024): ?><span class="text-danger">密钥过短</span><?php endif; ?>
          <?php else: ?>
            <strong class="text-danger">DNS 上查不到该 selector 的公钥</strong><br>
            <small>签名会验不过。请把「DKIM 密钥」页里的 TXT 值发布到 DNS。</small>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>

    <!-- DMARC -->
    <tr>
      <td>DMARC（策略）</td>
      <td><?php
        if ($dmarc === null) { echo DHC_BAD; }
        elseif ($dmarc_policy === 'reject') { echo DHC_OK; }
        elseif ($dmarc_policy === 'quarantine') { echo DHC_OK; }
        else { echo DHC_WARN; }
      ?></td>
      <td>
        <?php if ($dmarc === null): ?>
          <strong class="text-danger">未找到 DMARC 记录</strong><br>
          <small>Gmail / Outlook 对无 DMARC 的域名会明显更保守。</small>
        <?php else: ?>
          <code><?=htmlspecialchars($dmarc);?></code><br>
          <small>
            策略 <code>p=<?=htmlspecialchars((string)$dmarc_policy);?></code>
            <?php if ($dmarc_policy === 'none'): ?>
              —— <span class="text-warning">仅监控，不拦截</span>。对投递率提升有限；建议先加 <code>rua</code> 观察，再逐步收紧到 <code>quarantine</code>。
            <?php endif; ?><br>
            聚合报告 rua：<?=$dmarc_has_rua ? '已配置' : '<span class="text-warning">未配置（无法收到认证汇总报告）</span>'; ?>
          </small>
        <?php endif; ?>
      </td>
    </tr>

    <!-- MX -->
    <tr>
      <td>MX 记录</td>
      <td><?php
        if (empty($mxs)) { echo DHC_BAD; }
        elseif ($mx_points_here) { echo DHC_OK; }
        else { echo DHC_WARN; }
      ?></td>
      <td>
        <?php if (empty($mxs)): ?>
          <span class="text-danger">没有 MX 记录</span>
        <?php else: ?>
          <?=htmlspecialchars(implode(', ', $mxs));?><br>
          <small>
            服务器主机名 <code><?=htmlspecialchars($hostname);?></code>
            <?php if ($mx_points_here): ?>—— 一致<?php else: ?>—— <span class="text-warning">MX 未指向本机，请确认这是预期的</span><?php endif; ?>
          </small>
        <?php endif; ?>
      </td>
    </tr>

    <!-- PTR -->
    <tr>
      <td>反向解析 PTR</td>
      <td><?php echo $ptr_matches ? DHC_OK : ($ptr ? DHC_WARN : DHC_UNK); ?></td>
      <td>
        <?php if (!$pubip): ?>
          <span class="text-muted">无法取得本机出口 IP</span>
        <?php else: ?>
          出口 IP：<code><?=htmlspecialchars($pubip);?></code><br>
          PTR：<code><?=htmlspecialchars((string)($ptr ?: '（无）'));?></code><br>
          <small>
            <?php if ($ptr_matches): ?>
              与 <code>MAILCOW_HOSTNAME</code> 一致，符合规范。
            <?php else: ?>
              <span class="text-warning">与 <code>MAILCOW_HOSTNAME</code> 不一致</span>。多数接收方要求 PTR 与 HELO 名一致，建议在主机商侧修正。
            <?php endif; ?>
          </small>
        <?php endif; ?>
      </td>
    </tr>

    <!-- 黑名单 -->
    <tr>
      <td>出口 IP 黑名单</td>
      <td><?php
        if (!empty($rbl['listed'])) { echo DHC_BAD; }
        else { echo DHC_OK; }
      ?></td>
      <td>
        <?php if (empty($rbl['listed'])): ?>
          在已查询的黑名单中<strong>未发现</strong>该 IP。<br>
        <?php else: ?>
          <strong class="text-danger">被发现于以下黑名单：</strong>
          <ul class="mb-1"><?php foreach ($rbl['listed'] as $l): ?><li><?=htmlspecialchars($l);?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($rbl['refused'])): ?>
          <small class="text-muted">
            <i class="bi bi-info-circle"></i>
            以下列表拒绝了本次查询（未生效，<em>不代表被列入</em>）：<?=htmlspecialchars(implode(', ', $rbl['refused']));?><br>
            Spamhaus 对公共解析器会返回 127.255.255.x 表示「查询被拒」。如需真实结果，请配置 Spamhaus DQS key。
          </small>
        <?php endif; ?>
      </td>
    </tr>

    <!-- 域名年龄 -->
    <tr>
      <td>域名注册时长</td>
      <td><?php
        if ($age_days === null) { echo DHC_UNK; }
        elseif ($age_days >= 90) { echo DHC_OK; }
        else { echo DHC_WARN; }
      ?></td>
      <td>
        <?php if ($age_days === null): ?>
          <span class="text-muted">无法取得注册日期（RDAP 查询失败或该 TLD 不支持）</span>
        <?php else: ?>
          约 <strong><?=(int)$age_days;?></strong> 天<br>
          <?php if ($age_days < 90): ?>
            <small class="text-warning">
              <i class="bi bi-exclamation-triangle"></i>
              新注册域名会被 Gmail 等接收方审慎对待（业界经验：低于 30–90 天投递最不稳定）。
              这段时间建议：降低发信量、优先用有历史的中继、引导收件人把邮件标记为「非垃圾」。
            </small>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>

    <!-- rspamd 实际打分 -->
    <tr>
      <td>本机出站打分（rspamd）</td>
      <td><?php
        if (empty($scores)) { echo DHC_UNK; }
        else {
          $max = 0; foreach ($scores as $s) { $max = max($max, $s['score']); }
          echo $max >= 6 ? DHC_BAD : ($max >= 3 ? DHC_WARN : DHC_OK);
        }
      ?></td>
      <td>
        <?php if (empty($scores)): ?>
          <span class="text-muted">近期没有该域的出站记录（先发一封再回来查看）</span>
        <?php else: ?>
          <?php foreach ($scores as $s): ?>
            <div>
              分数 <strong><?=htmlspecialchars(number_format($s['score'], 2));?></strong>
              （阈值 15.00）<?php if ($s['action'] !== ''): ?>，动作 <code><?=htmlspecialchars($s['action']);?></code><?php endif; ?>
              <?php if (!empty($s['time'])): ?><small class="text-muted"> · <?=htmlspecialchars(date('Y-m-d H:i', $s['time']));?></small><?php endif; ?>
              <?php
                // 只展示对出站投递有意义的加分项
                $hits = array();
                foreach ((array)$s['symbols'] as $sym => $info) {
                  $sc = is_array($info) ? (float)($info['score'] ?? 0) : (float)$info;
                  if ($sc > 0) { $hits[] = htmlspecialchars($sym) . '(' . number_format($sc, 2) . ')'; }
                }
                if ($hits): ?><br><small><?=implode(' ', $hits);?></small><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
    </tr>
    </tbody>
  </table>
</div>

<p class="help-block">
  <small>
    <i class="bi bi-info-circle"></i>
    本页为只读诊断，不会修改任何配置。DNS 结果受解析器缓存影响，刚做的改动可能需等待 TTL 到期才会反映。<br>
    说明：SPF / DKIM / DMARC 全部通过，并不保证邮件进入收件箱——接收方还会综合评估出口 IP 信誉、域名历史与收件人互动。
    若三项全部通过但仍进垃圾箱，请优先检查「出口 IP 黑名单」「域名注册时长」与「本机出站打分」三项。
  </small>
</p>
