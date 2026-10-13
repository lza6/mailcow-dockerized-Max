<?php
/**
 * mailcow-dockerized-Max — 域名体检（由 ofelia 每 30 分钟调用一次）
 *
 * 为什么需要它：
 *   「域名总览」页上的「收信 MX / 认证」两列读的是 redis 里的 `OV_CHECK/<域>`，
 *   而那个缓存**只有 1 小时 TTL**。没有定时任务时，管理员每次打开页面看到的
 *   大概率都是「未检测」—— 除非他恰好在一小时前手动点过「体检」。
 *   实测就是这个现象：缓存一过期，整列全部退回「未检测」。
 *
 * 本任务把体检做成**周期性**的，让页面始终有数据。
 * 同时刷新域名年龄（`OV_RDAP/<域>`，24 小时 TTL）。
 *
 * ⚠️ 这里**有意复制**了 `inc/ajax/overview.php` 里 `ov_run_check()` 的判定逻辑，
 *    而不是 require 那个文件：那个函数定义在**端点文件内部**，端点 require 即执行，
 *    在 CLI 下会被它的主流程与 CSRF 网关拦住。要复用就必须先把函数抽到
 *    inc/ 里 —— 那是独立的重构，不在本次范围内。
 *    两处逻辑若有一处改动，**必须同时改另一处**。
 *
 * 安全/资源约束：
 *   - 只做只读 DNS 查询 + 写 redis，**不碰数据库、不改任何配置**
 *   - 每域 4 次查询，16 个域约 2~3 秒；间隔 30 分钟，可忽略
 *   - 异常只写 STDERR，不影响其它 cron
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

try {
  $rows = $pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll();
  foreach ($rows as $x) {
    $domain = $x['domain'];
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

    if ($redis) {
      try { $redis->setex('OV_CHECK/' . $domain, 3600, json_encode($r)); } catch (Throwable $e) {}
    }

    // ---- 域名年龄（OV_RDAP，24h）----
    try {
      if ($redis) {
        $rk = 'OV_RDAP/' . $domain;
        $cached = $redis->get($rk);
        $need = true;
        if ($cached !== false && $cached !== null) {
          $d = json_decode($cached, true);
          if (is_array($d) && isset($d['registered'])) { $need = false; }
        }
        if ($need) {
          $tld = strtolower(substr(strrchr($domain, '.'), 1));
          $bases = array(
            'com' => 'https://rdap.verisign.com/com/v1/domain/',
            'net' => 'https://rdap.verisign.com/net/v1/domain/',
          );
          $url = (isset($bases[$tld]) ? $bases[$tld] : 'https://rdap.org/domain/') . rawurlencode($domain);
          $ch = curl_init($url);
          curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'mailcow-domain-health',
            CURLOPT_HTTPHEADER => array('Accept: application/rdap+json'),
          ));
          $body = curl_exec($ch);
          $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);
          $registered = null;
          if ($body !== false && $code === 200) {
            $j = json_decode($body, true);
            foreach ((array)($j['events'] ?? array()) as $ev) {
              if (($ev['eventAction'] ?? '') === 'registration' && !empty($ev['eventDate'])) {
                $registered = strtotime($ev['eventDate']);
              }
            }
          }
          if ($registered) {
            $redis->setex($rk, 86400, json_encode(array('registered' => $registered)));
          }
        }
      }
    } catch (Throwable $e) {
      fwrite(STDERR, '[healthcheck] RDAP ' . $domain . ' 失败: ' . $e->getMessage() . PHP_EOL);
    }

    $checked++;
    if (!$r['mx_ok'] || !$r['spf_ok'] || !$r['dkim_ok'] || !$r['dmarc_ok']) { $abnormal++; }
  }
  echo '[healthcheck] checked=' . $checked . ' abnormal=' . $abnormal . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[healthcheck] failed: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

exit(0);
