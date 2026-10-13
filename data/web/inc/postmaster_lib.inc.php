<?php
/**
 * mailcow-dockerized-Max — Google Postmaster Tools 纯逻辑层
 *
 * 本文件**只做计算，不做任何 I/O**：不发 HTTP、不查数据库、不碰 redis。
 * 所有联网与持久化在 `functions.postmaster.inc.php` 里，调用这里的函数。
 * 这么切是为了让「最容易写错、又最难在线上复现」的判定逻辑能离线测试 ——
 * 见 `.github/scripts/test-postmaster.php`。
 */

// Google 官方建议：用户投诉垃圾邮件率 **保持在 0.10% 以下**。
// 超过 0.30% 会被 Gmail 明显降权，是"进垃圾箱"最直接的可观测前兆。
if (!defined('PM_SPAM_RATIO_WARN')) { define('PM_SPAM_RATIO_WARN', 0.0010); }
if (!defined('PM_SPAM_RATIO_BAD'))  { define('PM_SPAM_RATIO_BAD',  0.0030); }

/**
 * 把 Site Verification API 返回的 token 变成一条具体的 DNS 记录。
 *
 * 两种方式的 token 形态**完全不同**，这是最容易写错的地方：
 *
 *   · `DNS_TXT`   —— token 本身就是记录值，直接放在**域名的顶点**上。
 *        getToken 返回：`google-site-verification=dQKNG_p2Y7W6SDinfPVqV92BV3j9R6Eaa0p8OycaS-U`
 *        要写：TXT  @  google-site-verification=…
 *
 *   · `DNS_CNAME` —— token 是**「标签 目标」两个值用空格拼起来的一整串**，
 *        必须先按空格劈开：前半段是记录**名**（还要拼到域名前面），后半段是记录**值**。
 *        getToken 返回：`yja3uiatwhx3 gv-hhiylnceqtxskw.dv.googlehosted.com`
 *        要写：CNAME  yja3uiatwhx3  →  gv-hhiylnceqtxskw.dv.googlehosted.com
 *        （Google 文档原话：the token is split in two by a space — the new
 *          record's name comes first, its value second。）
 *
 * 若把 DNS_CNAME 的整串当成 CNAME 的"值"直接写下去，记录会建出来、也不报错，
 * 但 Google 永远验不过 —— 典型的"看起来做完了其实没做"。
 *
 * ⚠️ 返回值里 `name` 与 `fqdn` 是**两个不同的东西**，混用会写出错误的记录：
 *   · `name` —— **相对标签**，`''` 表示顶点。这是 Cloudflare 写入接口要的入参
 *     形态（仓库既有的 `resend_pool_write_dns()` 正是按这个约定拼名字的）。
 *   · `fqdn` —— **完整记录名**（`name` 为空时等于域名本身）。给人看、给人复制、
 *     给 DNS 查询用。写接口不认这个。
 *
 * @return array array('ok'=>bool,'type'=>'TXT|CNAME','name'=>string,'fqdn'=>string,
 *                      'content'=>string,'error'=>string)
 */
function pm_dns_record($method, $token, $domain) {
  $fail = function ($msg) {
    return array('ok' => false, 'type' => '', 'name' => '', 'fqdn' => '',
                 'content' => '', 'error' => $msg);
  };

  $method  = strtoupper(trim((string)$method));
  $token   = trim((string)$token);
  $domain  = strtolower(trim((string)$domain));

  if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
    return $fail('域名格式无效');
  }
  if ($token === '') { return $fail('token 为空（尚未从 Google 取得验证 token）'); }

  if ($method === 'DNS_TXT') {
    // 顶点 TXT：相对标签为空。多个 TXT 可以并存，
    // 不会顶掉域名上已有的 SPF（那是另一条独立的 TXT 记录）。
    return array('ok' => true, 'type' => 'TXT', 'name' => '', 'fqdn' => $domain,
                 'content' => $token, 'error' => '');
  }

  if ($method === 'DNS_CNAME') {
    // 只用第一个空白劈开：目标里理论上不含空格，但标签可能含（防御性地只劈一次）
    $parts = preg_split('/\s+/', $token, 2);
    if (count($parts) !== 2) {
      return $fail('CNAME token 形态不对：应为「标签 目标」两段，以空格分隔（实际得到 '
                   . count($parts) . ' 段）');
    }
    $label  = strtolower(trim($parts[0]));
    $target = rtrim(trim($parts[1]), '.');   // 去尾点，Cloudflare 不接受带尾点的 target

    // 标签只能是一段主机名，不能已经带域名后缀（带了说明 Google 换了格式，宁可报错也别乱写）
    if ($label === '' || strpos($label, '.') !== false) {
      return $fail('CNAME 标签不像主机名前缀：' . $label);
    }
    if ($target === '') { return $fail('CNAME 目标为空'); }

    return array('ok' => true, 'type' => 'CNAME',
                 'name' => $label, 'fqdn' => $label . '.' . $domain,
                 'content' => $target, 'error' => '');
  }

  return $fail('不支持的验证方式：' . $method . '（只支持 DNS_TXT / DNS_CNAME）');
}

/**
 * 域名信誉枚举 → 展示用等级。
 * @return array array('level'=>'good|ok|warn|bad|unknown','label'=>string)
 */
function pm_reputation_level($enum) {
  $e = strtoupper(trim((string)$enum));
  switch ($e) {
    case 'HIGH': return array('level' => 'good', 'label' => '高');
    case 'MEDIUM': return array('level' => 'ok',   'label' => '中');
    case 'LOW':  return array('level' => 'warn', 'label' => '低');
    case 'BAD':  return array('level' => 'bad',  'label' => '差');
    default:     return array('level' => 'unknown', 'label' => '未知');
  }
}

/**
 * 用户投诉垃圾邮件率 → 风险等级。
 *
 * 这是 Postmaster 里**最该盯的一个数**：它不是"被判成垃圾的比例"，
 * 而是"收件人主动点了举报的比例"。Google 的公开指引是保持 < 0.10%。
 * 注意系数是 0.0010 而不是 0.10 —— 接口给的是 0~1 的小数，界面才显示成百分比。
 *
 * @param float|null $ratio 0~1 的小数；null 表示当天没有数据
 * @return array array('level'=>'good|warn|bad|unknown','label'=>string,'pct'=>?float)
 */
function pm_spam_risk($ratio) {
  if ($ratio === null || $ratio === '' || !is_numeric($ratio)) {
    return array('level' => 'unknown', 'label' => '暂无数据', 'pct' => null);
  }
  $r = (float)$ratio;
  $pct = $r * 100.0;

  if ($r >= PM_SPAM_RATIO_BAD)  { return array('level' => 'bad',  'label' => '偏高（需立即处理）', 'pct' => $pct); }
  if ($r >= PM_SPAM_RATIO_WARN) { return array('level' => 'warn', 'label' => '需关注', 'pct' => $pct); }
  return array('level' => 'good', 'label' => '正常', 'pct' => $pct);
}

/**
 * 认证通过率 → 等级。Postmaster 给的是 0~1 的比例，但**没有数据时字段直接缺失**
 * （不是 0），所以必须区分"null = 未采集"与"0 = 通过率真的是 0"。
 */
function pm_auth_level($ratio) {
  if ($ratio === null || $ratio === '' || !is_numeric($ratio)) {
    return array('level' => 'unknown', 'pct' => null);
  }
  $p = (float)$ratio * 100.0;
  if ($p >= 99.5) { return array('level' => 'good', 'pct' => $p); }
  if ($p >= 95.0) { return array('level' => 'warn', 'pct' => $p); }
  return array('level' => 'bad', 'pct' => $p);
}

/**
 * 把若干天的 TrafficStats 汇总成 UI 要的那几个数。
 *
 * 只统计**有数据的天**：Google 的数据有 1~2 天延迟，且新域在积累到一定量级前
 * 当天条目可能是空的 —— 把空天按 0 计入会把平均值拉低、看起来"很健康"。
 *
 * @param array $days  TrafficStats 数组（字段名见 Google v1 文档）
 * @return array
 */
function pm_summarize_stats(array $days) {
  $out = array(
    'days'            => 0,        // 有数据的天数
    'span_days'       => count($days),
    'latest_date'     => '',
    'latest_domain_reputation' => '',
    'reputation'      => pm_reputation_level(''),
    'spam'            => pm_spam_risk(null),
    'spam_avg'        => null,
    'spf'             => pm_auth_level(null),
    'dkim'            => pm_auth_level(null),
    'dmarc'           => pm_auth_level(null),
    'outbound_tls'    => pm_auth_level(null),
    'errors'          => array(),
  );

  if (empty($days)) { return $out; }

  $sum = array('spam' => 0.0, 'spamN' => 0, 'spf' => 0.0, 'spfN' => 0,
               'dkim' => 0.0, 'dkimN' => 0, 'dmarc' => 0.0, 'dmarcN' => 0,
               'tls' => 0.0, 'tlsN' => 0);
  $errAgg = array();
  $latestKey = '';   // 最新的**有数据**的那天
  $latestRep = '';   // 最新的**有信誉值**的那天（与上者可能不是同一天）

  foreach ($days as $d) {
    if (!is_array($d)) { continue; }
    // name 形如 domains/<域>/trafficStats/20260807，日期从末尾取
    $name = isset($d['name']) ? (string)$d['name'] : '';
    $date = '';
    if ($name !== '' && preg_match('#/trafficStats/(\d{8})$#', $name, $m)) { $date = $m[1]; }
    if ($date === '') { $date = isset($d['date']) ? (string)$d['date'] : ''; }

    $hasAny = false;
    foreach (array('spfSuccessRatio' => 'spf', 'dkimSuccessRatio' => 'dkim',
                   'dmarcSuccessRatio' => 'dmarc', 'outboundEncryptionRatio' => 'tls') as $k => $slot) {
      if (isset($d[$k]) && is_numeric($d[$k])) { $sum[$slot] += (float)$d[$k]; $sum[$slot . 'N']++; $hasAny = true; }
    }
    if (isset($d['userReportedSpamRatio']) && is_numeric($d['userReportedSpamRatio'])) {
      $sum['spam'] += (float)$d['userReportedSpamRatio']; $sum['spamN']++; $hasAny = true;
    }
    foreach ((array)(isset($d['deliveryErrors']) ? $d['deliveryErrors'] : array()) as $e) {
      if (!is_array($e)) { continue; }
      $cls = (string)($e['errorClass'] ?? '');
      $typ = (string)($e['errorType'] ?? '');
      if ($cls === '' && $typ === '') { continue; }
      $key = $cls . '|' . $typ;
      // 记 `sum` 与 `n` 而不是直接累加进 `ratio`：
      // errorRatio 是**单日的比例**，逐日相加没有物理含义，
      // 界面再乘 100 会显示成「350%」这种明显错误、也没法据此判断严重程度。
      // 与 SPF/DKIM 同口径地按"有数据的天"求平均才是可解读的。
      if (!isset($errAgg[$key])) {
        $errAgg[$key] = array('class' => $cls, 'type' => $typ, 'sum' => 0.0, 'days' => 0, 'max' => 0.0);
      }
      $ratio = (float)($e['errorRatio'] ?? 0);
      $errAgg[$key]['sum']  += $ratio;
      $errAgg[$key]['days'] += 1;
      if ($ratio > $errAgg[$key]['max']) { $errAgg[$key]['max'] = $ratio; }
      $hasAny = true;
    }

    if ($hasAny) {
      $out['days']++;
      // 「最新」必须取**有数据**的那天。Google 会给当天留一条只有 name、
      // 各指标尚未算出的空壳，按它去取信誉会把界面刷成「未知」、
      // 把上一次已知的信誉抹掉 —— 恰好是这个模块最不该干的事。
      if ($date !== '' && $date > $latestKey) { $latestKey = $date; $out['latest_date'] = $date; }
    }
    // 信誉单独跟一遍：某天有流量但量级不够时 reputation 也会缺失，
    // 这时应继续沿用更早那天的已知值，而不是显示成未知。
    $rep = (string)($d['domainReputation'] ?? '');
    if ($rep !== '' && $date !== '' && $date >= $latestRep) {
      $latestRep = $date;
      $out['latest_domain_reputation'] = $rep;
    }
  }

  $out['reputation'] = pm_reputation_level($out['latest_domain_reputation']);

  if ($sum['spamN'] > 0) {
    $out['spam_avg'] = $sum['spam'] / $sum['spamN'];
    $out['spam'] = pm_spam_risk($out['spam_avg']);
  }
  if ($sum['spfN']   > 0) { $out['spf']   = pm_auth_level($sum['spf']   / $sum['spfN']); }
  if ($sum['dkimN']  > 0) { $out['dkim']  = pm_auth_level($sum['dkim']  / $sum['dkimN']); }
  if ($sum['dmarcN'] > 0) { $out['dmarc'] = pm_auth_level($sum['dmarc'] / $sum['dmarcN']); }
  if ($sum['tlsN']   > 0) { $out['outbound_tls'] = pm_auth_level($sum['tls'] / $sum['tlsN']); }

  // 投递错误按**单日平均**比例倒序 —— 与 SPF/DKIM 同口径，界面上最该先看的是占比最高的那个。
  // `days`（出现天数）与 `max`（单日最高）一并留在结构里：平均低但 max 高说明是偶发尖峰，
  // 平均和 max 都高才是持续性问题，界面据此给不同的措辞。
  foreach ($errAgg as $k => $v) {
    $errAgg[$k]['ratio'] = ($v['days'] > 0) ? ($v['sum'] / $v['days']) : 0.0;
  }
  usort($errAgg, function ($a, $b) {
    if ($a['ratio'] === $b['ratio']) {
      $c = strcmp($a['class'], $b['class']);
      return ($c !== 0) ? $c : strcmp($a['type'], $b['type']);
    }
    return ($a['ratio'] < $b['ratio']) ? 1 : -1;
  });
  $out['errors'] = array_values($errAgg);

  return $out;
}

/**
 * 本地状态 → 界面徽章。状态值就是数据库里 postmaster_domains.verify_status。
 */
function pm_verify_badge($status) {
  switch ((string)$status) {
    case 'verified':    return array('level' => 'good', 'label' => '已验证');
    case 'dns_written': return array('level' => 'warn', 'label' => 'DNS 已写入，待 Google 确认');
    case 'pending':     return array('level' => 'warn', 'label' => '待验证');
    case 'failed':      return array('level' => 'bad',  'label' => '验证失败');
    default:            return array('level' => 'unknown', 'label' => '未开始');
  }
}

/**
 * 把 Google 返回的英文错误翻成"该去查什么"。
 *
 * Postmaster 这条链路上的失败几乎都长得一样（403 / 404），但原因完全不同：
 * API 没启用、OAuth 账号不是加域名的那一个、域名没在那个账号下验证过……
 * 只把原文抛给用户等于没给信息。返回 null 表示没有匹配的已知模式。
 *
 * @return array|null array('cause'=>string,'fix'=>string)
 */
function pm_explain_error($httpCode, $body) {
  $b = strtolower((string)$body);
  $c = (int)$httpCode;

  if ($c === 401) {
    return array('cause' => 'OAuth 访问令牌缺失或已失效',
                 'fix'   => '重新点一次「连接 Google 账号」完成授权；若反复失效，检查 Client Secret 是否填错。');
  }
  if ($c === 403) {
    if (strpos($b, 'has not been used in project') !== false
        || strpos($b, 'service_disabled') !== false
        || strpos($b, 'is disabled') !== false) {
      return array('cause' => '对应的 Google API 还没在该 Cloud 项目里启用',
                   'fix'   => '到 Google Cloud Console → API 和服务 → 库，分别启用 '
                              . '「Gmail Postmaster Tools API」与「Site Verification API」，等 1~2 分钟后重试。');
    }
    if (strpos($b, 'accessnotconfigured') !== false) {
      return array('cause' => '该 Cloud 项目无权调用此 API',
                   'fix'   => '确认两个 API 都已启用；若已启用仍报此错，可能是该项目未开通对应服务，换用已验证过该域名的那个 Google 账号重新授权。');
    }
    return array('cause' => '已授权，但没有这个域的权限',
                 'fix'   => 'Postmaster 只对「已在该账号下验证过所有权」的域开放数据。'
                            . '确认加域用的 Google 账号与授权用的是同一个，且该域在 postmaster.google.com 里显示为 Verified。');
  }
  if ($c === 404) {
    return array('cause' => '该域在 Postmaster 里不存在',
                 'fix'   => '先在 postmaster.google.com 添加该域并完成所有权验证，再回来同步。');
  }
  if ($c === 429) {
    return array('cause' => '调用过于频繁，被 Google 限流',
                 'fix'   => '稍等几分钟再同步；同步任务本身已按天粒度缓存，不需要频繁手动点。');
  }
  return null;
}

/**
 * 复核失败是否属于「再等也不会好」。
 *
 * 为什么需要这个判定：Site Verification 的复核失败**长得都差不多**，
 * 但只有一类是"再等等就好"（DNS 还没传播）。若不区分，界面会永远停在
 * 「DNS 已写入，待 Google 确认」，用户按提示反复点「验证」却始终不通过 ——
 * 而真正的原因可能是这个域正被**另一个 Google 账号**持有，本账号永远拿不到。
 *
 * 判据刻意保守，因为看错方向的代价不对称：
 *   · 把可恢复的误判成 fatal → 用户删记录重来，多花几分钟；
 *   · 把不可恢复的误判成可恢复 → 用户卡死且没有任何出路提示。
 *
 * @param int    $http    HTTP 状态码（0 = 网络层失败，拿不到码）
 * @param string $errText Google 返回的错误原文
 * @return bool true = 应落到 `failed`
 */
function pm_verify_error_is_fatal($http, $errText) {
  $c = (int)$http;
  $b = strtolower((string)$errText);

  // 401：授权已失效，本域这条记录救不了，必须先回 OAuth 卡片重新授权
  // 403：Google 明确拒绝登记所有权（域在别的账号下、或对应 API 未启用）
  // 404：该域在 Cloud Console / Postmaster 里不存在，先添加再验证
  if ($c === 401 || $c === 403 || $c === 404) { return true; }

  // 明确的所有权 / 权限类文案，即使拿不到 HTTP 码也一样判 fatal
  if (strpos($b, 'already verified') !== false
      || strpos($b, 'owned by another') !== false
      || strpos($b, 'permission') !== false
      || strpos($b, 'forbidden') !== false
      || strpos($b, 'not found') !== false) {
    return true;
  }

  // 其余（token mismatch / verification failed / 网络超时 / 429 …）都当作
  // "DNS 可能还在传播"，继续等下一轮复核。
  return false;
}
