<?php
/**
 * mailcow-dockerized-Max — 域名健康检查：纯函数库
 *
 * 从 inc/ajax/domain_health.php 抽出，唯一目的是让「判定逻辑」可被离线测试：
 * 端点脚本 require 一次 prerequisites.inc.php 就会连库、起 session，
 * 在 CI（无 PHP 栈、无 redis、无 DB）里根本无法导入。
 *
 * 本文件**只放无 I/O、无全局状态的纯逻辑**：
 *   · 常量 DHC_*        —— 状态徽章 HTML（唯一渲染出口）
 *   · dhc_rbl_classify  —— 区分「被列黑」与「查询被拒」（Spamhaus 的 127.255.255.x）
 *   · dhc_build_checks  —— $D（采集结果）→ $C（检查项判定），不碰网络
 *   · dhc_status_badge  —— 状态字符串 → 徽章
 *   · dhc_summarize     —— 各状态计数
 *
 * **不要**在这里加入 dns_get_record / redis / session / $lang 依赖，
 * 否则测试会退化成装样子（把「静态确认」冒充「已验证」）。
 */

if (!defined('DHC_OK'))   { define('DHC_OK',   '<span class="badge bg-success">正常</span>'); }
if (!defined('DHC_WARN')) { define('DHC_WARN', '<span class="badge bg-warning text-dark">注意</span>'); }
if (!defined('DHC_BAD'))  { define('DHC_BAD',  '<span class="badge bg-danger">异常</span>'); }
if (!defined('DHC_UNK'))  { define('DHC_UNK',  '<span class="badge bg-secondary">未知</span>'); }

/**
 * RBL 应答分类：区分「被列入黑名单」与「查询被拒」。
 *
 * Spamhaus 等名单对来自公共解析器（1.1.1.1 / 8.8.8.8）的查询会返回
 * 127.255.255.x，语义是「拒绝提供查询服务」，**不是被拉黑**。
 * 把拒绝当列入 → 假警报；把列入当拒绝 → 漏报。两个方向都会误导用户，
 * 因此这段判定必须单独可测。
 *
 * @param string $ip RBL 应答里的 A 记录值
 * @return string 'refused' | 'listed'
 */
function dhc_rbl_classify($ip) {
  return strpos((string)$ip, '127.255.255.') === 0 ? 'refused' : 'listed';
}

/**
 * 把采集到的原始数据转成结构化检查项。
 *
 * 每项结构：
 *   id       string  机器可读标识
 *   name     string  展示名
 *   status   string  ok | warn | bad | unknown
 *   reason   string  一句话结论（为什么是这个状态）
 *   evidence array   证据行（纯文本，渲染时统一转义）
 *   fix      string  修复建议；无问题时为空字符串
 *
 * 这是本次重构的核心：判定与渲染彻底分离。同一份结果可渲染 HTML、
 * 输出 JSON，或供批量体检复用，解释文案不再散落在多处 echo 里。
 */
function dhc_build_checks($D) {
  $C = array();

  // ---- 1. SPF ----
  $ev = array();
  $status = 'warn'; $reason = ''; $fix = '';
  if ($D['spf'] === null) {
    $status = 'bad';
    $reason = '未找到 SPF 记录。接收方无法验证你的发件来源，投递率会明显下降。';
    $fix = '在 DNS 添加 TXT 记录，内容形如 "v=spf1 mx a:<你的邮件主机名> -all"。';
  } else {
    $ev[] = '记录值：' . $D['spf'];
    $ev[] = 'DNS 查询次数约 ' . (int)$D['spf_dns_lookups'] . '（上限 10）';
    if ($D['spf_dns_lookups'] > 10) {
      $status = 'bad';
      $reason = 'SPF 的 DNS 查询次数超过 10 次，接收方会报 permerror。';
      $fix = '精简 SPF：合并 include、去掉无用机制（如 a/mx/ptr 中不需要的项）。';
    } elseif ($D['spf_has_all_minus']) {
      $status = 'ok';
      $reason = '结尾为 -all，未授权来源应被拒绝（严格，推荐）。';
    } elseif ($D['spf_has_all_soft']) {
      $status = 'warn';
      $reason = '结尾为 ~all，未授权来源仅标记 softfail（较宽松）。';
      $fix = '条件成熟时把 ~all 收紧为 -all。';
    } else {
      $status = 'warn';
      $reason = '结尾既不是 -all 也不是 ~all，策略不明确。';
      $fix = '把 SPF 结尾改为 -all（严格）或 ~all（宽松）。';
    }
  }
  $C[] = array('id' => 'spf', 'name' => 'SPF（发件人策略）', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 2. DKIM ----
  $ev = array(); $status = 'warn'; $reason = ''; $fix = '';
  if (!$D['dkim_selector']) {
    $status = 'warn';
    $reason = '本机 redis 中未记录该域的 DKIM selector。';
    $fix = '若该域用于发信，请在「DKIM 密钥」页生成密钥并发布到 DNS；不用于发信可忽略。';
  } else {
    $ev[] = 'selector：' . $D['dkim_selector'];
    $ev[] = 'DNS 记录名：' . $D['dkim_dns_name'];
    if ($D['dkim_published']) {
      $status = 'ok';
      $reason = '公钥已在 DNS 发布。';
      if ($D['dkim_key_bits']) {
        $ev[] = '密钥长度约 ' . (int)$D['dkim_key_bits'] . ' bit';
        if ($D['dkim_key_bits'] < 1024) {
          $status = 'warn';
          $reason = '公钥已发布，但密钥长度不足 1024 bit。';
          $fix = '重新生成更长的 DKIM 密钥（建议 2048 bit）。';
        }
      }
    } else {
      $status = 'bad';
      $reason = 'DNS 上查不到该 selector 的公钥，签名会验不过。';
      $fix = '把「DKIM 密钥」页里的 TXT 值发布到 DNS 记录 ' . $D['dkim_dns_name'] . '。';
    }
  }
  $C[] = array('id' => 'dkim', 'name' => 'DKIM（签名）', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 3. DMARC ----
  $ev = array(); $status = 'warn'; $reason = ''; $fix = '';
  if ($D['dmarc'] === null) {
    $status = 'bad';
    $reason = '未找到 DMARC 记录。Gmail / Outlook 对无 DMARC 的域名会明显更保守。';
    $fix = '在 DNS 添加 _dmarc.<域> 的 TXT，形如 "v=DMARC1; p=none; rua=mailto:dmarc@<你的域>"，先观察再收紧。';
  } else {
    $ev[] = '记录值：' . $D['dmarc'];
    $ev[] = '策略 p=' . (string)$D['dmarc_policy'];
    $ev[] = '聚合报告 rua：' . ($D['dmarc_has_rua'] ? '已配置' : '未配置');
    if ($D['dmarc_policy'] === 'reject' || $D['dmarc_policy'] === 'quarantine') {
      $status = 'ok';
      $reason = 'DMARC 策略为 ' . $D['dmarc_policy'] . '，具备实际保护力。';
    } else {
      $status = 'warn';
      $reason = 'DMARC 策略为 none，仅监控不拦截，对投递率提升有限。';
      $fix = '先配置 rua 收集报告并观察，确认无误后逐步收紧到 quarantine。';
    }
    if (!$D['dmarc_has_rua']) {
      $fix = trim($fix . ' 另外建议加上 rua=mailto:... 以接收认证汇总报告。');
    }
  }
  $C[] = array('id' => 'dmarc', 'name' => 'DMARC（策略）', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 4. MX ----
  $ev = array(); $status = 'warn'; $reason = ''; $fix = '';
  if (empty($D['mxs'])) {
    $status = 'bad';
    $reason = '该域没有 MX 记录，无法接收邮件（退信、投诉都会失败）。';
    $fix = '把 MX 指向你的邮件服务器主机名。';
  } else {
    $ev[] = 'MX：' . implode(', ', $D['mxs']);
    $ev[] = '服务器主机名：' . $D['hostname'];
    if ($D['mx_points_here']) {
      $status = 'ok';
      $reason = 'MX 指向本机，一致。';
    } else {
      $status = 'warn';
      $reason = 'MX 未指向本机。若这是有意的（例如邮件由别处托管）可忽略。';
      $fix = '确认是否有意；若应由本机收信，把 MX 改为 ' . $D['hostname'] . '。';
    }
  }
  $C[] = array('id' => 'mx', 'name' => 'MX 记录', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 5. A 记录（网站可达性）----
  $ev = array(); $status = 'warn'; $reason = ''; $fix = '';
  $ev[] = '根域 A：' . (empty($D['a_records']) ? '无' : implode(', ', $D['a_records']));
  $ev[] = 'www A：' . (empty($D['www_records']) ? '无' : implode(', ', $D['www_records']));
  if (empty($D['a_records']) && empty($D['www_records'])) {
    $status = 'warn';
    $reason = '该域名没有网站。接收方会参考域名是否有真实内容，完全没有网站的域名更容易被当作垃圾发信源。';
    $fix = '为该域名建站，或把 A / CNAME 指向已有网站。';
  } else {
    $status = 'ok';
    $reason = '域名存在可访问的网站内容，对投递信誉有正面作用。';
  }
  $C[] = array('id' => 'a', 'name' => 'A 记录（网站可达性）', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 6. PTR ----
  $ev = array(); $status = 'unknown'; $reason = ''; $fix = '';
  if (!$D['pubip']) {
    $status = 'unknown';
    $reason = '无法取得本机出口 IP，跳过 PTR 检查。';
  } else {
    $ev[] = '出口 IP：' . $D['pubip'];
    $ev[] = 'PTR：' . ($D['ptr'] ? $D['ptr'] : '（无）');
    if ($D['ptr_matches']) {
      $status = 'ok';
      $reason = 'PTR 与 MAILCOW_HOSTNAME 一致，符合规范。';
    } elseif ($D['ptr']) {
      $status = 'warn';
      $reason = 'PTR 与 MAILCOW_HOSTNAME 不一致。多数接收方要求二者一致。';
      $fix = '在主机商侧把 PTR 改为 ' . $D['hostname'] . '。';
    } else {
      $status = 'unknown';
      $reason = '该 IP 没有 PTR 记录。';
      $fix = '在主机商侧设置 PTR（反向解析）。';
    }
  }
  $C[] = array('id' => 'ptr', 'name' => '反向解析 PTR', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 7. 黑名单 ----
  $ev = array(); $status = 'ok'; $reason = ''; $fix = '';
  $rbl = isset($D['rbl']) ? $D['rbl'] : array();
  $listed  = isset($rbl['listed'])  ? $rbl['listed']  : array();
  $refused = isset($rbl['refused']) ? $rbl['refused'] : array();
  $zones   = isset($rbl['zones'])   ? (int)$rbl['zones'] : 0;
  if (!empty($listed)) {
    $status = 'bad';
    $reason = '出口 IP 被发现于以下黑名单，会直接导致投递失败。';
    $fix = '按名单要求申请移除（delist），并排查是哪类邮件触发的投诉。';
    foreach ($listed as $l) { $ev[] = '已列入：' . $l; }
  } elseif ($zones === 0) {
    // 「一个名单都没查」必须与「查过且干净」区分开。
    // 否则取不到出口 IP 时会给出一个绿色的「正常」徽章 —— 而真相是**根本没查**。
    // 这一页的脚注恰好让用户「优先检查出口 IP 黑名单」，绿徽章会直接误导他跳过这里。
    $status = 'unknown';
    $reason = '未执行黑名单查询（通常因为取不到本机出口 IP）。';
    $fix = '确认服务器能访问 ip4.mailcow.email（出站 80 端口），然后重新体检。';
  } else {
    $status = 'ok';
    $reason = '在已查询的黑名单中未发现该出口 IP。';
  }
  if (!empty($refused)) {
    $ev[] = '以下名单拒绝了本次查询（未生效，不代表被列入）：' . implode(', ', $refused);
    $ev[] = 'Spamhaus 对公共解析器返回 127.255.255.x 表示「查询被拒」。如需真实结果，请配置 Spamhaus DQS key。';
  }
  $ev[] = '共查询 ' . $zones . ' 个名单';
  if (!empty($rbl['cached'])) { $ev[] = '（本结果来自缓存，最长 1 小时）'; }
  $C[] = array('id' => 'rbl', 'name' => '出口 IP 黑名单', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 8. 域名年龄 ----
  $ev = array(); $status = 'unknown'; $reason = ''; $fix = '';
  if ($D['age_days'] === null) {
    $status = 'unknown';
    $reason = '无法取得注册日期（RDAP 查询失败或该 TLD 不支持）。';
  } else {
    $ev[] = '注册约 ' . (int)$D['age_days'] . ' 天';
    if ($D['age_days'] >= 90) {
      $status = 'ok';
      $reason = '域名注册已满 90 天，年龄不再是主要阻力。';
    } else {
      $status = 'warn';
      $reason = '新注册域名会被 Gmail 等接收方审慎对待（业界经验：低于 30–90 天投递最不稳定）。';
      $fix = '这段时间建议：降低发信量、优先用有历史的中继、引导收件人把邮件标记为「非垃圾」。';
    }
  }
  $C[] = array('id' => 'age', 'name' => '域名注册时长', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  // ---- 9. rspamd 出站打分 ----
  $ev = array(); $status = 'unknown'; $reason = ''; $fix = '';
  if (empty($D['scores'])) {
    $status = 'unknown';
    $reason = '近期没有该域的出站记录（先发一封再回来查看）。';
  } else {
    $max = 0;
    foreach ($D['scores'] as $s) { $max = max($max, (float)$s['score']); }
    foreach ($D['scores'] as $s) {
      $line = '分数 ' . number_format((float)$s['score'], 2) . '（阈值 15.00）';
      if ($s['action'] !== '') { $line .= '，动作 ' . $s['action']; }
      if (!empty($s['time'])) { $line .= ' · ' . date('Y-m-d H:i', (int)$s['time']); }
      $hits = array();
      foreach ((array)$s['symbols'] as $sym => $info) {
        $sc = is_array($info) ? (float)($info['score'] ?? 0) : (float)$info;
        if ($sc > 0) { $hits[] = $sym . '(' . number_format($sc, 2) . ')'; }
      }
      if ($hits) { $line .= '　加分项：' . implode(' ', $hits); }
      $ev[] = $line;
    }
    if ($max >= 6) {
      $status = 'bad';
      $reason = '本机 rspamd 对该域出站邮件给出较高分（' . number_format($max, 2) . '），说明本机也认为内容或发信行为可疑。';
      $fix = '查看上方加分项，针对性减少触发规则（如减少链接/图片比例、避免营销词）。';
    } elseif ($max >= 3) {
      $status = 'warn';
      $reason = '本机 rspamd 给出中等分数（' . number_format($max, 2) . '），部分规则被触发。';
      $fix = '检查上方加分项，确认是否符合预期。';
    } else {
      $status = 'ok';
      $reason = '本机 rspamd 打分正常（最高 ' . number_format($max, 2) . '）。';
    }
  }
  $C[] = array('id' => 'rspamd', 'name' => '本机出站打分（rspamd）', 'status' => $status,
               'reason' => $reason, 'evidence' => $ev, 'fix' => $fix);

  return $C;
}

/**
 * 状态 -> 徽章 HTML。唯一的渲染出口，避免各处各自拼装。
 */
function dhc_status_badge($status) {
  switch ($status) {
    case 'ok':   return DHC_OK;
    case 'warn': return DHC_WARN;
    case 'bad':  return DHC_BAD;
    default:     return DHC_UNK;
  }
}

/** 各状态计数，用于批量视图与 JSON 输出 */
function dhc_summarize($checks) {
  $n = array('ok' => 0, 'warn' => 0, 'bad' => 0, 'unknown' => 0);
  foreach ($checks as $c) {
    // 缺 status 的项直接跳过。必须写成 $c['status'] ?? '' 而不是裸下标：
    // 裸下标在缺键时会发 PHP Warning，而本页是把 HTML 直接吐给浏览器的，
    // warning 文本会插进页面。语义与原先一致（不计入任何一档）。
    $st = isset($c['status']) ? $c['status'] : '';
    if (isset($n[$st])) { $n[$st]++; }
  }
  return $n;
}
