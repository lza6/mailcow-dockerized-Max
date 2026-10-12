#!/usr/bin/env php
<?php
/**
 * 并发 DNS 原语的**离线**单元测试（合成报文，不联网 → 可在 CI 里跑）。
 *
 * 为什么必须离线测：
 *   `dns_parallel.inc.php` 里真正容易出错、且出错后**极难察觉**的部分不是网络，
 *   而是**报文解析**。解析错了的表现是"某个名单没查到" —— 看起来像"这个 IP 干净"，
 *   实际上是我们把应答丢了。这类 bug 在外网测试里会被网络抖动掩盖。
 *
 * 因此这里手工拼 DNS 报文，覆盖：
 *   正常 A / TXT（多段拼接）/ MX（含压缩指针）/ PTR、以及各种畸形输入。
 *
 * 用法：php .github/scripts/test-dns-parse.php
 * 退出码：0 = 全部通过，1 = 有失败
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/data/web/inc/dns_parallel.inc.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_true($cond, string $msg): void
{
    if ($cond) { $GLOBALS['__pass']++; }
    else {
        $GLOBALS['__fail']++;
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n";
    }
}
function assert_equals($exp, $act, string $msg): void
{
    if ($exp === $act) { $GLOBALS['__pass']++; }
    else {
        $GLOBALS['__fail']++;
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n    期望: " . var_export($exp, true) . "\n    实际: " . var_export($act, true) . "\n";
    }
}
function section(string $s): void { echo "\n=== {$s} ===\n"; }

/** dhc_dns_parse 恒返回四键结构；判断"四种类型都为空" */
function is_all_empty($r): bool
{
    if (!is_array($r)) { return false; }
    foreach (['A', 'MX', 'TXT', 'PTR'] as $t) {
        if (!array_key_exists($t, $r) || !is_array($r[$t]) || $r[$t] !== []) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- 报文构造

/** 域名 -> wire 格式（无压缩） */
function wire_name(string $name): string
{
    $out = '';
    foreach (explode('.', rtrim($name, '.')) as $l) {
        if ($l === '') { continue; }
        $out .= chr(strlen($l)) . $l;
    }
    return $out . "\x00";
}

/** 拼一个应答报文 */
function pkt(int $id, int $flags, array $questions, array $answers): string
{
    $q = ''; $a = '';
    foreach ($questions as $qs) { $q .= $qs; }
    foreach ($answers as $as) { $a .= $as; }
    return pack('n6', $id, $flags, count($questions), count($answers), 0, 0) . $q . $a;
}

/** 问题段条目 */
function q_item(string $name, int $type): string
{
    return wire_name($name) . pack('nn', $type, 1);
}

/** 答案段条目；$rdata 为原始字节 */
function rr(string $ownerWire, int $type, string $rdata): string
{
    return $ownerWire . pack('nnNn', $type, 1, 300, strlen($rdata)) . $rdata;
}

$PTR_TO_QNAME = "\xC0\x0C";   // 指向报文偏移 12 处的名字（压缩指针）

// ---------------------------------------------------------------- 1. 畸形输入

section('畸形输入不得崩溃、不得死循环');

assert_true(is_all_empty(dhc_dns_parse('')), '空串');
assert_true(is_all_empty(dhc_dns_parse("\x00\x01\x02")), '过短（<12 字节）');
assert_true(is_all_empty(dhc_dns_parse(str_repeat("\xff", 400))), '全 0xff 垃圾');
assert_true(is_all_empty(dhc_dns_parse(str_repeat("a", 12))), '恰好 12 字节但无问题段');

$r = dhc_dns_parse(pkt(1, 0x0100, [q_item('a.example', 1)], []));   // QR=0（是查询不是应答）
assert_true(is_all_empty($r), 'QR=0（不是应答）应返回空');

$r = dhc_dns_parse(pkt(1, 0x8183, [q_item('a.example', 1)], []));   // RCODE=3 NXDOMAIN
assert_true(is_all_empty($r), 'NXDOMAIN（RCODE=3）应返回空，不能当"查到"');

$r = dhc_dns_parse(pkt(1, 0x8182, [q_item('a.example', 1)], []));   // SERVFAIL
assert_true(is_all_empty($r), 'SERVFAIL 应返回空');

// 截断：声明有 1 条答案但字节不够
$truncated = substr(pkt(1, 0x8180, [q_item('a.example', 1)], [rr($PTR_TO_QNAME, 1, "\x01\x02\x03\x04")]), 0, -2);
$r = dhc_dns_parse($truncated);
assert_true(is_array($r) && $r['A'] === [], '截断报文安全返回空');

// 压缩指针自指（死循环陷阱）
$selfLoop = pack('n6', 7, 0x8180, 1, 1, 0, 0) . "\xC0\x0C" . pack('nn', 1, 1)
          . "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . "\x01\x02\x03\x04";
$r = dhc_dns_parse($selfLoop);
assert_true(is_array($r), '自指压缩指针不死循环');

// ---------------------------------------------------------------- 2. A 记录

section('A 记录');

$p = pkt(1, 0x8180, [q_item('a.example', 1)], [rr($PTR_TO_QNAME, 1, inet_pton('93.184.216.34'))]);
$r = dhc_dns_parse($p);
assert_equals(['93.184.216.34'], $r['A'], '单条 A 记录');

// 多条 A
$p = pkt(1, 0x8180, [q_item('a.example', 1)], [
    rr($PTR_TO_QNAME, 1, inet_pton('10.0.0.1')),
    rr($PTR_TO_QNAME, 1, inet_pton('10.0.0.2')),
    rr($PTR_TO_QNAME, 1, inet_pton('10.0.0.1')),   // 重复
]);
$r = dhc_dns_parse($p);
sort($r['A']);
assert_equals(['10.0.0.1', '10.0.0.2'], $r['A'], '多条 A 记录并去重');

// rdlen 不是 4 的 A 记录应被跳过而不是解出乱码
$p = pkt(1, 0x8180, [q_item('a.example', 1)], [rr($PTR_TO_QNAME, 1, "\x01\x02\x03")]);
$r = dhc_dns_parse($p);
assert_equals([], $r['A'], 'rdlen!=4 的 A 记录应跳过（不产出错误 IP）');

// ---------------------------------------------------------------- 3. TXT

section('TXT 记录');

// TXT 的一个 rdata 可以由**多段**组成，解析时要按每段的长度前缀拼接，
// 而不是把长度字节一起当内容（那会得到 "v=spf1 mx -all" 前面多出乱码）。
$txtData = chr(12) . 'v=spf1 mx -a' . chr(2) . 'll';
$p = pkt(1, 0x8180, [q_item('a.example', 16)], [rr($PTR_TO_QNAME, 16, $txtData)]);
$r = dhc_dns_parse($p);
assert_equals(['v=spf1 mx -all'], $r['TXT'], 'TXT 多段按 wire 拼接（不是把长度字节当内容）');

// 多条 TXT
$p = pkt(1, 0x8180, [q_item('a.example', 16)], [
    rr($PTR_TO_QNAME, 16, chr(20) . 'v=spf1 include:a.com'),
    rr($PTR_TO_QNAME, 16, chr(3) . 'abc'),
]);
$r = dhc_dns_parse($p);
assert_true(in_array('v=spf1 include:a.com', $r['TXT'], true), '第一条 TXT 正确');
assert_true(in_array('abc', $r['TXT'], true), '第二条 TXT 正确');

// 空 TXT（rdlen=0）不应产生空串条目
$p = pkt(1, 0x8180, [q_item('a.example', 16)], [rr($PTR_TO_QNAME, 16, '')]);
$r = dhc_dns_parse($p);
assert_equals([], $r['TXT'], '空 TXT 不产出空字符串');

// ---------------------------------------------------------------- 4. MX（含压缩指针）

section('MX 记录');

// MX rdata = 2 字节优先级 + 名字（这里用未压缩名字，并额外带一个压缩指针用例）
$mxRdata = pack('n', 10) . wire_name('mail.example.com');
$p = pkt(1, 0x8180, [q_item('a.example', 15)], [rr($PTR_TO_QNAME, 15, $mxRdata)]);
$r = dhc_dns_parse($p);
assert_equals(['10 mail.example.com'], $r['MX'], 'MX 未压缩名字');

// 压缩指针形式的 MX 目标：指向问题段的名字（偏移 12，即上面 "mx.example.com"）
$mxRdata2 = pack('n', 20) . $PTR_TO_QNAME;
$p = pkt(1, 0x8180, [q_item('mx.example.com', 15)], [rr($PTR_TO_QNAME, 15, $mxRdata2)]);
$r = dhc_dns_parse($p);
assert_equals(['20 mx.example.com'], $r['MX'], 'MX 压缩指针目标正确解出（不是空/乱码）');

// ---------------------------------------------------------------- 5. PTR

section('PTR 记录');

$ptrRdata = wire_name('mail.example.com');
$p = pkt(1, 0x8180, [q_item('4.3.2.1.in-addr.arpa', 12)], [rr($PTR_TO_QNAME, 12, $ptrRdata)]);
$r = dhc_dns_parse($p);
assert_equals(['mail.example.com'], $r['PTR'], 'PTR 记录（小写、无结尾点）');

// ---------------------------------------------------------------- 6. 混合与偏移正确性

section('多记录混合（验证偏移推进正确）');

$p = pkt(1, 0x8180, [q_item('a.example', 1)], [
    rr($PTR_TO_QNAME, 16, chr(5) . 'hello'),                            // TXT 在前
    rr($PTR_TO_QNAME, 1, inet_pton('1.2.3.4')),                         // A 在后
]);
$r = dhc_dns_parse($p);
assert_equals(['hello'], $r['TXT'], '混合报文中的 TXT');
assert_equals(['1.2.3.4'], $r['A'], '混合报文中的 A（说明偏移没跑偏）');

// ---------------------------------------------------------------- 7. 查询构造

section('查询报文构造');

$q = dhc_dns_build_query('a.example.com', 0x1234, 1);
assert_true($q !== '', '正常名字可构造');
assert_equals(0x1234, unpack('n', substr($q, 0, 2))[1], '事务 ID 写入正确');
assert_equals(0x0100, unpack('n', substr($q, 2, 2))[1], 'RD 标志位 = 1');
assert_equals(1, unpack('n', substr($q, 4, 2))[1], 'QDCOUNT = 1');
assert_equals("\x00\x00\x01\x00\x01", substr($q, -5), '结尾是 根标签 + QTYPE=1 + QCLASS=1');

$q16 = dhc_dns_build_query('a.example.com', 1, 16);
assert_equals("\x00\x00\x10\x00\x01", substr($q16, -5), 'QTYPE=16 (TXT) 正确');
$q15 = dhc_dns_build_query('a.example.com', 1, 15);
assert_equals("\x00\x00\x0f\x00\x01", substr($q15, -5), 'QTYPE=15 (MX) 正确');
$q12 = dhc_dns_build_query('4.3.2.1.in-addr.arpa', 1, 12);
assert_equals("\x00\x00\x0c\x00\x01", substr($q12, -5), 'QTYPE=12 (PTR) 正确');

assert_equals('', dhc_dns_build_query(str_repeat('a', 70) . '.com', 1), '超长 label 拒绝');
assert_equals('', dhc_dns_build_query('..', 1), '空标签拒绝');

// 合法性：能解回自己（把查询当报文解析，QDCOUNT 应为 1）
$h = unpack('n6', substr(dhc_dns_build_query('a.example.com', 5, 1), 0, 12));
assert_equals(1, $h[3], '构造出的报文 QDCOUNT=1');

// ---------------------------------------------------------------- 汇总

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    echo "\n失败项：\n";
    foreach ($GLOBALS['__failures'] as $m) { echo "  - {$m}\n"; echo "::error::{$m}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
