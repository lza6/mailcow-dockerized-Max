#!/usr/bin/env php
<?php
/**
 * Google Postmaster — 纯逻辑层测试（离线，无网络）
 *
 * 守的是什么：
 *   1. **DNS_CNAME 的 token 是「标签 目标」两段**，必须劈开再分别落到
 *      CNAME 的 name / content。整串直接当 content 写下去，记录也建得出来、
 *      也不报错，但 Google 永远验不过 —— 典型"看起来做完了其实没做"。
 *   2. 垃圾投诉率的阈值单位是**小数**（0.0010 = 0.10%）。写成 0.10 的话，
 *      所有域名都会显示成"正常"，而这个数正是判断会不会进垃圾箱的核心指标。
 *   3. 汇总时**没有数据的天不能按 0 计入**，否则平均值被拉低、看着很健康。
 *
 * 用法：php .github/scripts/test-postmaster.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$F = $ROOT . '/data/web/inc/postmaster_lib.inc.php';
if (!file_exists($F)) { fwrite(STDERR, "缺 $F\n"); exit(1); }
require_once $F;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_equals($exp, $act, string $msg): void
{
    if ($exp === $act) { $GLOBALS['__pass']++; }
    else {
        $GLOBALS['__fail']++;
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n    期望: " . var_export($exp, true) . "\n    实际: " . var_export($act, true) . "\n";
    }
}
function assert_true($c, string $m): void
{
    if ($c) { $GLOBALS['__pass']++; }
    else { $GLOBALS['__fail']++; $GLOBALS['__failures'][] = $m; echo "  FAIL: {$m}\n"; }
}

echo "== 1) DNS 记录构造 ==\n";

// 真实样本：用户在 postmaster.google.com 里拿到的 CNAME 两段
$r = pm_dns_record('DNS_CNAME', 'yja3uiatwhx3 gv-hhiylnceqtxskw.dv.googlehosted.com', 'trendhollowretail.com');
assert_true($r['ok'], 'CNAME token 能被解析');
assert_equals('CNAME', $r['type'], 'CNAME 方式产出 CNAME 记录');
assert_equals('yja3uiatwhx3', $r['name'], 'CNAME 的 name 是**相对标签**（写接口要的形态）');
assert_equals('yja3uiatwhx3.trendhollowretail.com', $r['fqdn'], 'CNAME 的 fqdn 是给人看的完整记录名');
assert_equals('gv-hhiylnceqtxskw.dv.googlehosted.com', $r['content'], 'CNAME 的 content = 第二段目标');

// 真实样本：TXT token
$t = pm_dns_record('DNS_TXT', 'google-site-verification=dQKNG_p2Y7W6SDinfPVqV92BV3j9R6Eaa0p8OycaS-U', 'trendhollowretail.com');
assert_true($t['ok'], 'TXT token 能被解析');
assert_equals('TXT', $t['type'], 'TXT 方式产出 TXT 记录');
assert_equals('', $t['name'], 'TXT 的相对标签为空（顶点）');
assert_equals('trendhollowretail.com', $t['fqdn'], 'TXT 的完整名就是域名本身（与已有的 SPF 是两条独立 TXT，不冲突）');
assert_equals('google-site-verification=dQKNG_p2Y7W6SDinfPVqV92BV3j9R6Eaa0p8OycaS-U', $t['content'], 'TXT 的 content 就是 token 原文');

// 契约一致性：fqdn 必须等于「相对标签拼上域名」，两者不能各说各话
foreach (array($r, $t) as $rec) {
  $expect = ($rec['name'] === '') ? 'trendhollowretail.com' : $rec['name'] . '.trendhollowretail.com';
  assert_equals($expect, $rec['fqdn'], 'fqdn 与 name 自洽（' . $rec['type'] . '）');
}

// 尾点与多空格：Google 偶尔带尾点，Cloudflare 不接受
$r2 = pm_dns_record('DNS_CNAME', "  abc123   target.example.com.  ", 'example.com');
assert_equals('target.example.com', $r2['content'], 'CNAME 目标去掉尾点');
assert_equals('abc123', $r2['name'], '多余空白被规范化');

// 大小写
$r3 = pm_dns_record('dns_cname', 'AbC123 Tgt.Example.COM', 'Example.COM');
assert_equals('abc123', $r3['name'], '方式与域名大小写不敏感');
assert_equals('abc123.example.com', $r3['fqdn'], '域名被规范成小写');
assert_equals('Tgt.Example.COM', $r3['content'], '目标保留原样（DNS 本身大小写不敏感）');

// 反证：整串当 content 会怎样（这就是当初会踩的坑）
$r4 = pm_dns_record('DNS_CNAME', 'yja3uiatwhx3', 'trendhollowretail.com');
assert_true(!$r4['ok'], 'CNAME token 没有空格 → 必须报错，而不是硬写成一条无效记录');

// 标签里带点说明格式变了，宁可报错也不要猜
$r5 = pm_dns_record('DNS_CNAME', 'a.b.c target.com', 'example.com');
assert_true(!$r5['ok'], 'CNAME 标签含点 → 拒绝（格式已变，不能乱写）');

assert_true(!pm_dns_record('DNS_TXT', '', 'example.com')['ok'], '空 token 被拒');
assert_true(!pm_dns_record('DNS_TXT', 'x', '')['ok'], '空域名被拒');
assert_true(!pm_dns_record('DNS_TXT', 'x', 'nodot')['ok'], '非法域名被拒');
assert_true(!pm_dns_record('FILE', 'x', 'example.com')['ok'], '不支持的验证方式被拒（FILE 只能验站点，不能验域）');
assert_true(!pm_dns_record('DNS', 'x', 'example.com')['ok'], 'DNS 是过时写法，应提示改用 DNS_TXT');

echo "\n== 2) 域名信誉等级 ==\n";
assert_equals('good', pm_reputation_level('HIGH')['level'], 'HIGH → good');
assert_equals('ok',   pm_reputation_level('MEDIUM')['level'], 'MEDIUM → ok');
assert_equals('warn', pm_reputation_level('LOW')['level'], 'LOW → warn');
assert_equals('bad',  pm_reputation_level('BAD')['level'], 'BAD → bad');
assert_equals('unknown', pm_reputation_level('')['level'], '空 → unknown');
assert_equals('unknown', pm_reputation_level('REPUTATION_CATEGORY_UNSPECIFIED')['level'], '未指定 → unknown');
assert_equals('bad', pm_reputation_level('bad')['level'], '大小写不敏感');

echo "\n== 3) 垃圾投诉率阈值（单位是小数，不是百分数）==\n";
assert_equals('good', pm_spam_risk(0.0005)['level'], '0.05% → 正常');
assert_equals('warn', pm_spam_risk(0.0010)['level'], '0.10% 恰好到界 → 需关注');
assert_equals('warn', pm_spam_risk(0.0029)['level'], '0.29% → 需关注');
assert_equals('bad',  pm_spam_risk(0.0030)['level'], '0.30% 恰好到界 → 偏高');
assert_equals('bad',  pm_spam_risk(0.05)['level'],   '5% → 偏高');
assert_equals('unknown', pm_spam_risk(null)['level'], 'null → 暂无数据（不是 0，不能当健康）');
assert_equals('unknown', pm_spam_risk('')['level'], '空串 → 暂无数据');
assert_equals(null, pm_spam_risk(null)['pct'], '暂无数据时百分比为 null');
assert_equals(0.05, round((float)pm_spam_risk(0.0005)['pct'], 4), '0.0005 → 0.05%');
assert_equals('good', pm_spam_risk('0.0002')['level'], '字符串数字能被识别');

echo "\n== 4) 认证通过率等级 ==\n";
assert_equals('good', pm_auth_level(1.0)['level'], '100% → good');
assert_equals('good', pm_auth_level(0.995)['level'], '99.5% 恰好到界 → good');
assert_equals('warn', pm_auth_level(0.96)['level'], '96% → warn');
assert_equals('bad',  pm_auth_level(0.90)['level'], '90% → bad');
assert_equals('unknown', pm_auth_level(null)['level'], 'null → unknown');
assert_equals(null, pm_auth_level(null)['pct'], 'unknown 时百分比为 null');

echo "\n== 5) 多天汇总 ==\n";
$days = [
  ['name' => 'domains/a.com/trafficStats/20261011',
   'domainReputation' => 'MEDIUM', 'userReportedSpamRatio' => 0.0001,
   'spfSuccessRatio' => 1.0, 'dkimSuccessRatio' => 1.0, 'dmarcSuccessRatio' => 1.0,
   'outboundEncryptionRatio' => 1.0,
   'deliveryErrors' => [['errorClass' => 'TEMPORARY_ERROR', 'errorType' => 'RATE_LIMIT_EXCEEDED', 'errorRatio' => 0.01]]],
  ['name' => 'domains/a.com/trafficStats/20261012',
   'domainReputation' => 'HIGH', 'userReportedSpamRatio' => 0.0003,
   'spfSuccessRatio' => 1.0, 'dkimSuccessRatio' => 0.99, 'dmarcSuccessRatio' => 1.0,
   'deliveryErrors' => [['errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.05],
                        ['errorClass' => 'TEMPORARY_ERROR', 'errorType' => 'RATE_LIMIT_EXCEEDED', 'errorRatio' => 0.02]]],
  // 一整天都没数据的空条目：不能拉低平均值
  ['name' => 'domains/a.com/trafficStats/20261013'],
];
$s = pm_summarize_stats($days);
assert_equals(3, $s['span_days'], 'span_days = 给出的天数');
assert_equals(2, $s['days'], '只有 2 天有数据');
assert_equals('20261012', $s['latest_date'], '最新日期取最大值');
assert_equals('HIGH', $s['latest_domain_reputation'], '信誉取最新一天（不是平均）');
assert_equals('good', $s['reputation']['level'], '最新的 HIGH → good');
assert_equals(0.0002, round($s['spam_avg'], 7), '垃圾率平均只算有数据的 2 天：(0.0001+0.0003)/2');
assert_equals('good', $s['spam']['level'], '均值 0.02% → 正常');
assert_equals('good', $s['spf']['level'], 'SPF 100% → good');
assert_equals('good', $s['dkim']['level'], 'DKIM 99.5% → good');
assert_equals('good', $s['outbound_tls']['level'], 'TLS 只有 1 天有数据，按有数据的天求平均 = 100% → good');
assert_equals(2, count($s['errors']), '两条投递错误被聚合成两条');
assert_equals('SUSPECTED_SPAM', $s['errors'][0]['type'], '平均占比最高的错误排第一');
assert_equals(0.05, round($s['errors'][0]['ratio'], 4), 'SUSPECTED_SPAM 只出现 1 天 → 平均=0.05');
assert_equals(1, $s['errors'][0]['days'], 'SUSPECTED_SPAM 只出现 1 天');
assert_equals(0.015, round($s['errors'][1]['ratio'], 4), 'RATE_LIMIT_EXCEEDED 出现 2 天 → 平均 (0.01+0.02)/2 = 0.015');
assert_equals(2, $s['errors'][1]['days'], 'RATE_LIMIT_EXCEEDED 累计了 2 天');
assert_equals(0.02, round($s['errors'][1]['max'], 4), 'max 记单日最高值 0.02（平均低但有尖峰）');

// 回归守卫（MEDIUM-1）：errorRatio 是**单日比例**，逐日相加没有物理含义。
// 旧实现把 7 天 × 0.5% 加成 3.5、界面再乘 100 显示成「350%」。
// 现在必须与 SPF/DKIM 同口径按"有数据的天"求平均，最大值封顶在单日最高比例。
$spike = pm_summarize_stats(array(
  array('name' => 'domains/a.com/trafficStats/20261010',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261011',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261012',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261013',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261014',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261015',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
  array('name' => 'domains/a.com/trafficStats/20261016',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.005))),
));
assert_equals(0.005, round($spike['errors'][0]['ratio'], 6), '7 天各 0.5% → 平均仍是 0.5%，不是 3.5%');
assert_equals(7, $spike['errors'][0]['days'], '7 天全部计入 days');
assert_equals(0.005, round($spike['errors'][0]['max'], 6), 'max = 单日最高 0.5%');
assert_true($spike['errors'][0]['ratio'] <= 1.0, '平均比例永远不可能 > 1（旧实现会到 3.5）');

// 混入空天（无投递错误）不能拉低平均 —— 与 SPF/DKIM 同口径只算"有错误的天"
$mixed = pm_summarize_stats(array(
  array('name' => 'domains/a.com/trafficStats/20261010',
        'deliveryErrors' => array(array('errorClass' => 'PERMANENT_ERROR', 'errorType' => 'SUSPECTED_SPAM', 'errorRatio' => 0.02))),
  array('name' => 'domains/a.com/trafficStats/20261011', 'spfSuccessRatio' => 1.0),
));
assert_equals(0.02, round($mixed['errors'][0]['ratio'], 6), '只有 1 天出现该错误 → 平均等于那天，不被无错天稀释');
assert_equals(1, $mixed['errors'][0]['days'], '无错天不计入 days');

// 回归守卫：Google 会给"当天"留一条只有 name、指标还没算出的空壳。
// 按它去取信誉会把界面刷成「未知」、把上次已知的信誉抹掉 ——
// 那正好是这个模块最不该干的事（用户会以为域名突然出问题了）。
$keep = pm_summarize_stats(array(
  array('name' => 'domains/a.com/trafficStats/20261012', 'domainReputation' => 'HIGH', 'spfSuccessRatio' => 1.0),
  array('name' => 'domains/a.com/trafficStats/20261013', 'spfSuccessRatio' => 1.0),
));
assert_equals('good', $keep['reputation']['level'], '新一天有流量但没信誉值 → 沿用上一次已知的 HIGH');
assert_equals('HIGH', $keep['latest_domain_reputation'], 'latest_domain_reputation 保留上次已知值');
assert_equals('20261013', $keep['latest_date'], 'latest_date 取**有数据**的最新一天');

// 反过来：新一天确实算出了更差的信誉，就必须如实变成更差
$drop = pm_summarize_stats(array(
  array('name' => 'domains/a.com/trafficStats/20261012', 'domainReputation' => 'HIGH', 'spfSuccessRatio' => 1.0),
  array('name' => 'domains/a.com/trafficStats/20261013', 'domainReputation' => 'LOW',  'spfSuccessRatio' => 1.0),
));
assert_equals('warn', $drop['reputation']['level'], '新一天信誉变 LOW → 如实显示为需关注');

$empty = pm_summarize_stats([]);
assert_equals(0, $empty['days'], '空输入 → 0 天');
assert_equals('unknown', $empty['spam']['level'], '空输入 → 垃圾率 unknown（绝不能是 good）');
assert_equals('unknown', $empty['reputation']['level'], '空输入 → 信誉 unknown');
assert_equals('unknown', $empty['spf']['level'], '空输入 → SPF unknown');

// 只有 SPF 没有 DKIM：缺的必须是 unknown，不能被当成 0%
$partial = pm_summarize_stats([['name' => 'domains/a.com/trafficStats/20261012', 'spfSuccessRatio' => 1.0]]);
assert_equals('good', $partial['spf']['level'], '有 SPF 数据 → good');
assert_equals('unknown', $partial['dkim']['level'], '缺 DKIM 字段 → unknown，不能显示成 0% 通过');
assert_equals('unknown', $partial['spam']['level'], '缺垃圾率 → unknown');

echo "\n== 6) 状态徽章 ==\n";
assert_equals('good', pm_verify_badge('verified')['level'], 'verified → good');
assert_equals('warn', pm_verify_badge('dns_written')['level'], 'dns_written → warn');
assert_equals('warn', pm_verify_badge('pending')['level'], 'pending → warn');
assert_equals('bad',  pm_verify_badge('failed')['level'], 'failed → bad');
assert_equals('unknown', pm_verify_badge('')['level'], '空 → unknown');

echo "\n== 7) 错误翻译（403 有三种完全不同的原因，不能只抛原文）==\n";
$e = pm_explain_error(403, '{"error":{"status":"PERMISSION_DENIED","message":"Gmail Postmaster Tools API has not been used in project 123 before or it is disabled."}}');
assert_true(strpos($e['cause'], 'API') !== false, '403 + has not been used in project → 判定为 API 未启用');
assert_true(strpos($e['fix'], '启用') !== false, '给出的处置包含"启用 API"');

$e2 = pm_explain_error(403, '{"error":{"status":"PERMISSION_DENIED","message":"User does not have permission"}}');
assert_true(strpos($e2['cause'], '权限') !== false, '403 泛化 → 判定为域名权限不足');
assert_true(strpos($e2['fix'], '账号') !== false, '提示去核对 Google 账号是否一致');

assert_true(strpos(pm_explain_error(401, '')['cause'], '令牌') !== false, '401 → 令牌问题');
assert_true(strpos(pm_explain_error(404, '')['cause'], '不存在') !== false, '404 → 域名未注册');
assert_true(pm_explain_error(429, '') !== null, '429 → 有解释');
assert_equals(null, pm_explain_error(500, ''), '未收录的状态码返回 null（不硬编瞎话）');
assert_equals(null, pm_explain_error(200, ''), '成功码返回 null');

echo "\n== 8) 复核失败是否「再等也没用」（决定落 failed 还是继续等）==\n";
// 判据看错方向代价不对称：误判 fatal 只是让用户重做几分钟；
// 误判成可恢复则界面永远卡在「待确认」，用户反复点却没有任何出路。
assert_true(pm_verify_error_is_fatal(403, 'User does not have permission for this site'),
            '403 → fatal（域在别的账号下 / 无权，重试一万次也不会好）');
assert_true(pm_verify_error_is_fatal(401, 'Invalid Credentials'), '401 → fatal（授权失效，要先重新授权）');
assert_true(pm_verify_error_is_fatal(404, 'not found'), '404 → fatal（域在 Console 里不存在）');
assert_true(pm_verify_error_is_fatal(200, 'This site was already verified by another account'),
            '拿不到码但文案是"已被别的账号验证" → fatal');
assert_true(pm_verify_error_is_fatal(0, 'Forbidden'), '文案含 forbidden → fatal');

// 反过来：这些必须**继续等**，标成失败会误报，用户会去删掉正确的记录
assert_true(!pm_verify_error_is_fatal(200, 'The ownership verification failed. Please check your DNS record.'),
            '记录还没生效 → 不 fatal（DNS 传播本来就慢）');
assert_true(!pm_verify_error_is_fatal(400, 'token mismatch'), 'token 暂时不匹配 → 不 fatal');
assert_true(!pm_verify_error_is_fatal(0, 'Operation timed out after 30000 milliseconds'),
            '网络超时 → 不 fatal，下一轮再试');
assert_true(!pm_verify_error_is_fatal(429, 'Rate limit exceeded'), '429 限流 → 不 fatal');
assert_true(!pm_verify_error_is_fatal(500, 'Internal error'), '500 服务端抖动 → 不 fatal');
assert_true(!pm_verify_error_is_fatal(0, ''), '空错误 → 保守地继续等，不误判 failed');

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    foreach ($GLOBALS['__failures'] as $m) { echo "  - {$m}\n"; echo "::error::{$m}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
