#!/usr/bin/env php
<?php
/**
 * 域名健康检查 —— 纯函数测试
 *
 * 被测对象：data/web/inc/domain_health_lib.inc.php
 *
 * 为什么单独抽出这个 lib：
 *   inc/ajax/domain_health.php 是「require 即执行」的端点脚本 —— 顶部就
 *   require prerequisites.inc.php（起 session、连 DB、连 redis），末尾直接跑
 *   主流程。在没有 PHP 栈的 CI 里 import 它必然 fatal，所以纯逻辑必须搬到
 *   一个无 I/O、无全局状态的文件里才谈得上「测」。
 *
 *   这也是本次重构前最容易被糊弄过去的地方：只对源码做文本 grep（「文件里
 *   有 mailcow_cc_role 就算过」）是「静态确认」，不是「已验证」。
 *
 * 覆盖重点（都对应真实发生过的误判）：
 *   1. dhc_rbl_classify —— Spamhaus 对公共解析器返回 127.255.255.x 是
 *      「查询被拒」，不是「被拉黑」。把两者混同会产出假警报（把正常 IP 报成
 *      被列黑），这是本项目投递诊断里最容易误导人的一类结论。
 *   2. dhc_build_checks —— SPF 语义（缺失 / 查询数超限 / -all / ~all / 无
 *      all）、DKIM（未发布 / 已发布 / 密钥过短）、DMARC 策略强度、MX 指向、
 *      黑名单命中、域名年龄、rspamd 打分分档。
 *   3. 结构契约 —— 返回 9 个检查项、id 固定顺序、status 只能取四个枚举值。
 *
 * 用法：php .github/scripts/test-domain-health.php
 * 退出码：0 = 全部通过，1 = 有失败
 */

declare(strict_types=1);

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_true($cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
    } else {
        $GLOBALS['__fail']++;
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n";
    }
}

function assert_equals($expected, $actual, string $msg): void
{
    if ($expected === $actual) {
        $GLOBALS['__pass']++;
    } else {
        $GLOBALS['__fail']++;
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n    期望: " . var_export($expected, true)
            . "\n    实际: " . var_export($actual, true) . "\n";
    }
}

function section(string $name): void
{
    echo "\n=== {$name} ===\n";
}

$ROOT = dirname(__DIR__, 2);
$LIB = $ROOT . '/data/web/inc/domain_health_lib.inc.php';

if (!file_exists($LIB)) {
    fwrite(STDERR, "找不到 {$LIB}（纯函数库必须存在于非 lib/ 路径，见 CI php -l 的扫描范围）\n");
    exit(1);
}
require_once $LIB;

/* ------------------------------------------------------------------ *
 * 夹具：一份「全部正常」的采集结果 $D，各用例只覆写关心的字段
 * ------------------------------------------------------------------ */
function dhc_fixture(array $over = []): array
{
    $D = [
        'spf'               => 'v=spf1 mx a:mail.example.com -all',
        'spf_dns_lookups'   => 3,
        'spf_has_all_minus' => true,
        'spf_has_all_soft'  => false,
        'dkim_selector'     => 'dkim',
        'dkim_dns_name'     => 'dkim._domainkey.example.com',
        'dkim_published'    => true,
        'dkim_key_bits'     => 2048,
        'dmarc'             => 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com',
        'dmarc_policy'      => 'reject',
        'dmarc_has_rua'     => true,
        'mxs'               => ['mail.example.com'],
        'mx_points_here'    => true,
        'hostname'          => 'mail.example.com',
        'a_records'         => ['203.0.113.10'],
        'www_records'       => ['203.0.113.10'],
        'pubip'             => '203.0.113.10',
        'ptr'               => 'mail.example.com',
        'ptr_matches'       => true,
        'rbl'               => ['listed' => [], 'refused' => [], 'zones' => 13, 'cached' => false],
        'age_days'          => 365,
        'scores'            => [],
    ];
    return array_merge($D, $over);
}

/** 取出某个检查项 */
function dhc_check(array $checks, string $id): array
{
    foreach ($checks as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    throw new RuntimeException("检查项不存在: {$id}");
}

/* ------------------------------------------------------------------ *
 * 1. dhc_rbl_classify —— 「被列黑」vs「查询被拒」
 * ------------------------------------------------------------------ */
section('RBL 应答分类');

assert_equals('refused', dhc_rbl_classify('127.255.255.254'), '127.255.255.254（Spamhaus 拒绝码）判为 refused');
assert_equals('refused', dhc_rbl_classify('127.255.255.2'), '127.255.255.2 判为 refused');
assert_equals('listed', dhc_rbl_classify('127.0.0.2'), '127.0.0.2（ZEN 真实列入答复）判为 listed');
assert_equals('listed', dhc_rbl_classify('127.0.0.4'), '127.0.0.4（SpamCop 答复）判为 listed');
// 边界：没有结尾点的不算拒绝码（避免把 127.255.255x 之类误判）
assert_equals('listed', dhc_rbl_classify('127.255.255'), '无结尾点的 127.255.255 不算拒绝码');
// 空答：调用方已过滤空值；此处固化行为，避免被改成语义不明的分支
assert_equals('listed', dhc_rbl_classify(''), '空串按 listed 返回（调用方负责过滤空值）');

/* ------------------------------------------------------------------ *
 * 2. dhc_summarize —— 各状态计数
 * ------------------------------------------------------------------ */
section('状态计数');

$sum = dhc_summarize([
    ['status' => 'ok'], ['status' => 'ok'], ['status' => 'warn'],
    ['status' => 'bad'], ['status' => 'unknown'],
]);
assert_equals(2, $sum['ok'], 'ok 计数');
assert_equals(1, $sum['warn'], 'warn 计数');
assert_equals(1, $sum['bad'], 'bad 计数');
assert_equals(1, $sum['unknown'], 'unknown 计数');

$sum2 = dhc_summarize([['status' => 'bogus'], ['no_status' => 1]]);
assert_equals(0, array_sum($sum2), '未知/缺失 status 不计入任何一档，也不报错');

$sum3 = dhc_summarize([]);
assert_equals(0, array_sum($sum3), '空输入返回全 0');

/* ------------------------------------------------------------------ *
 * 3. dhc_status_badge —— 枚举映射
 * ------------------------------------------------------------------ */
section('状态徽章');

assert_equals(DHC_OK, dhc_status_badge('ok'), 'ok -> DHC_OK');
assert_equals(DHC_WARN, dhc_status_badge('warn'), 'warn -> DHC_WARN');
assert_equals(DHC_BAD, dhc_status_badge('bad'), 'bad -> DHC_BAD');
assert_equals(DHC_UNK, dhc_status_badge('unknown'), 'unknown -> DHC_UNK');
assert_equals(DHC_UNK, dhc_status_badge('whatever'), '未知值兜底为 DHC_UNK');
assert_equals(DHC_UNK, dhc_status_badge(''), '空串兜底为 DHC_UNK');
assert_true(strpos(DHC_OK, 'badge') !== false, '徽章常量含 Bootstrap badge class');

/* ------------------------------------------------------------------ *
 * 4. SPF 语义
 * ------------------------------------------------------------------ */
section('SPF 语义');

assert_equals('bad', dhc_check(dhc_build_checks(dhc_fixture(['spf' => null])), 'spf')['status'],
    '无 SPF 记录 -> bad');

$c = dhc_check(dhc_build_checks(dhc_fixture(['spf_dns_lookups' => 11])), 'spf');
assert_equals('bad', $c['status'], 'DNS 查询数 11（>10）-> bad');
assert_true($c['fix'] !== '', '超限时给出精简建议');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['spf_dns_lookups' => 10])), 'spf')['status'],
    'DNS 查询数恰好 10 -> 不判 bad（边界）');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['spf_has_all_minus' => true])), 'spf')['status'],
    '结尾 -all -> ok');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture([
        'spf_has_all_minus' => false, 'spf_has_all_soft' => true,
    ])), 'spf')['status'],
    '结尾 ~all -> warn');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture([
        'spf_has_all_minus' => false, 'spf_has_all_soft' => false,
    ])), 'spf')['status'],
    '结尾既非 -all 也非 ~all -> warn');

// 优先级：查询数超限应当压过 -all
assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture([
        'spf_dns_lookups' => 12, 'spf_has_all_minus' => true,
    ])), 'spf')['status'],
    '查询数超限优先于 -all（permerror 更严重）');

/* ------------------------------------------------------------------ *
 * 5. DKIM
 * ------------------------------------------------------------------ */
section('DKIM');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['dkim_selector' => ''])), 'dkim')['status'],
    '本机无 selector 记录 -> warn');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['dkim_key_bits' => 2048])), 'dkim')['status'],
    '公钥已发布 + 2048 bit -> ok');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['dkim_key_bits' => 512])), 'dkim')['status'],
    '公钥已发布但密钥 <1024 bit -> warn');

assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture(['dkim_published' => false])), 'dkim')['status'],
    'selector 有但 DNS 未发布 -> bad');

// 密钥长度未知（0）时不应误判为「过短」
assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['dkim_key_bits' => 0])), 'dkim')['status'],
    '密钥长度未知（0）不判过短');

/* ------------------------------------------------------------------ *
 * 6. DMARC
 * ------------------------------------------------------------------ */
section('DMARC');

assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture(['dmarc' => null, 'dmarc_policy' => ''])), 'dmarc')['status'],
    '无 DMARC 记录 -> bad');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['dmarc_policy' => 'none'])), 'dmarc')['status'],
    'p=none -> warn（仅监控）');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['dmarc_policy' => 'quarantine'])), 'dmarc')['status'],
    'p=quarantine -> ok');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['dmarc_policy' => 'reject'])), 'dmarc')['status'],
    'p=reject -> ok');

$c = dhc_check(dhc_build_checks(dhc_fixture(['dmarc_policy' => 'none', 'dmarc_has_rua' => false])), 'dmarc');
assert_true(strpos($c['fix'], 'rua') !== false, '缺 rua 时建议里出现 rua');

/* ------------------------------------------------------------------ *
 * 7. MX / PTR / A
 * ------------------------------------------------------------------ */
section('MX / PTR / A');

assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture(['mxs' => []])), 'mx')['status'],
    '无 MX -> bad（收不到退信/投诉）');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['mx_points_here' => false])), 'mx')['status'],
    'MX 未指向本机 -> warn');

assert_equals('unknown',
    dhc_check(dhc_build_checks(dhc_fixture(['pubip' => ''])), 'ptr')['status'],
    '取不到出口 IP -> unknown（跳过 PTR）');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['ptr_matches' => false])), 'ptr')['status'],
    'PTR 与本机名不一致 -> warn');

assert_equals('unknown',
    dhc_check(dhc_build_checks(dhc_fixture(['ptr_matches' => false, 'ptr' => ''])), 'ptr')['status'],
    '完全没有 PTR -> unknown');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['a_records' => [], 'www_records' => []])), 'a')['status'],
    '根域与 www 都无 A -> warn（无网站）');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['a_records' => [], 'www_records' => ['203.0.113.10']])), 'a')['status'],
    '仅 www 有 A -> ok（边界）');

/* ------------------------------------------------------------------ *
 * 8. 黑名单结果解读
 * ------------------------------------------------------------------ */
section('黑名单');

assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture([
        'rbl' => ['listed' => ['Spamhaus ZEN (127.0.0.2)'], 'refused' => [], 'zones' => 13, 'cached' => false],
    ])), 'rbl')['status'],
    '命中名单 -> bad');

$c = dhc_check(dhc_build_checks(dhc_fixture([
    'rbl' => ['listed' => [], 'refused' => ['Spamhaus ZEN'], 'zones' => 13, 'cached' => false],
])), 'rbl');
assert_equals('ok', $c['status'], '只有「查询被拒」时仍判 ok（不当作被拉黑）');
assert_true(implode("\n", $c['evidence']) !== '' && strpos(implode("\n", $c['evidence']), 'Spamhaus ZEN') !== false,
    '被拒的名单会出现在证据里（透明化，便于用户自行判断）');

// 取不到出口 IP 时端点传 zones=0。**必须与「查过且干净」区分开** ——
// 否则会给出一个绿色「正常」徽章，而真相是根本没查（用户会被误导跳过这一项）。
$c = dhc_check(dhc_build_checks(dhc_fixture([
    'rbl' => ['listed' => [], 'refused' => [], 'zones' => 0, 'cached' => false],
])), 'rbl');
assert_equals('unknown', $c['status'], 'zones=0（未执行查询）判 unknown，不能判 ok');
assert_true(strpos($c['reason'], '未执行') !== false || strpos($c['reason'], '未查询') !== false,
    'zones=0 的说明要写明「未执行查询」而不是「未发现」');
assert_true($c['fix'] !== '', 'zones=0 时给出可操作的修复建议');

/* ------------------------------------------------------------------ *
 * 9. 域名年龄
 * ------------------------------------------------------------------ */
section('域名年龄');

assert_equals('unknown',
    dhc_check(dhc_build_checks(dhc_fixture(['age_days' => null])), 'age')['status'],
    '取不到注册日期 -> unknown');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['age_days' => 90])), 'age')['status'],
    '恰好 90 天 -> ok（边界）');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['age_days' => 89])), 'age')['status'],
    '89 天 -> warn');

/* ------------------------------------------------------------------ *
 * 10. rspamd 打分分档
 * ------------------------------------------------------------------ */
section('rspamd 打分');

$mk = function ($score) {
    return [['score' => $score, 'action' => 'no action', 'time' => 0, 'symbols' => []]];
};

assert_equals('unknown',
    dhc_check(dhc_build_checks(dhc_fixture(['scores' => []])), 'rspamd')['status'],
    '无出站记录 -> unknown');

assert_equals('ok',
    dhc_check(dhc_build_checks(dhc_fixture(['scores' => $mk(2.9)])), 'rspamd')['status'],
    '最高分 2.9（<3）-> ok');

assert_equals('warn',
    dhc_check(dhc_build_checks(dhc_fixture(['scores' => $mk(3.0)])), 'rspamd')['status'],
    '最高分 3.0 -> warn（边界）');

assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture(['scores' => $mk(6.0)])), 'rspamd')['status'],
    '最高分 6.0 -> bad（边界）');

// 多封时取最高分
assert_equals('bad',
    dhc_check(dhc_build_checks(dhc_fixture(['scores' => [
        ['score' => 1.0, 'action' => '', 'time' => 0, 'symbols' => []],
        ['score' => 9.5, 'action' => '', 'time' => 0, 'symbols' => []],
    ]])), 'rspamd')['status'],
    '多封取最高分 -> bad');

/* ------------------------------------------------------------------ *
 * 11. 结构契约
 * ------------------------------------------------------------------ */
section('结构契约');

$checks = dhc_build_checks(dhc_fixture());
assert_equals(9, count($checks), '固定产出 9 个检查项');

$ids = array_column($checks, 'id');
assert_equals(
    ['spf', 'dkim', 'dmarc', 'mx', 'a', 'ptr', 'rbl', 'age', 'rspamd'],
    $ids,
    '检查项 id 与顺序稳定（前端/批量视图依赖它）'
);

$allowed = ['ok', 'warn', 'bad', 'unknown'];
$statusOk = true;
$shapeOk = true;
foreach ($checks as $c) {
    if (!in_array($c['status'], $allowed, true)) {
        $statusOk = false;
    }
    foreach (['id', 'name', 'status', 'reason', 'evidence', 'fix'] as $k) {
        if (!array_key_exists($k, $c)) {
            $shapeOk = false;
        }
    }
    if (!is_array($c['evidence'])) {
        $shapeOk = false;
    }
}
assert_true($statusOk, '所有 status 落在 ok|warn|bad|unknown 内');
assert_true($shapeOk, '每项都含 id/name/status/reason/evidence(array)/fix');

// 全绿夹具下不应出现 bad
$badCount = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'bad') {
        $badCount++;
    }
}
assert_equals(0, $badCount, '「全部正常」夹具下不应有任何 bad');

/* ------------------------------------------------------------------ *
 * 汇总
 * ------------------------------------------------------------------ */
echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";

if ($GLOBALS['__fail'] > 0) {
    echo "\n失败项：\n";
    foreach ($GLOBALS['__failures'] as $msg) {
        echo "  - {$msg}\n";
        echo "::error::{$msg}\n";
    }
    exit(1);
}

echo "全部通过\n";
exit(0);
