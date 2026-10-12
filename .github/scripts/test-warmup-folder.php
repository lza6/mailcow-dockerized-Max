#!/usr/bin/env php
<?php
/**
 * 养号 — IMAP 文件夹名解码测试（离线，无网络）
 *
 * 守的是什么：
 *   IMAP 规范要求文件夹名用 **modified UTF-7** 传输。Gmail 实测返回：
 *       &V4NXPpCuTvY-  = 垃圾邮件      &XfJT0ZCuTvY- = 已发邮件
 *   而 `wwe_spam_folder()` / `wwe_sent_folder()` 是按 **UTF-8 名字**
 *   （'垃圾邮件'、'sent'…）做匹配的。如果 `wwe_folder_leaf()` 不解码，
 *   这两个函数**永远返回空** —— 不报错，只是"找不到文件夹"。
 *   后果是养号的两件核心功能静默失效：**垃圾箱判定** 与 **对方是否回信**。
 *
 * 用法：php .github/scripts/test-warmup-folder.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$F = $ROOT . '/data/web/inc/functions.warmup_ext.inc.php';
if (!file_exists($F)) { fwrite(STDERR, "缺 $F\n"); exit(1); }

// 该文件顶层定义了常量与函数，无副作用；但会用到 wwe_valid_host 等同文件函数
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
function section(string $s): void { echo "\n=== {$s} ===\n"; }

$P = '{imap.gmail.com:993/imap/ssl/novalidate-cert}';

section('modified UTF-7 解码（Gmail 实际返回值）');

assert_equals('垃圾邮件', wwe_folder_leaf($P . '[Gmail]/&V4NXPpCuTvY-'), 'Gmail 垃圾邮件 → 中文名');
assert_equals('已发邮件', wwe_folder_leaf($P . '[Gmail]/&XfJT0ZCuTvY-'), 'Gmail 已发邮件 → 中文名');
assert_equals('所有邮件', wwe_folder_leaf($P . '[Gmail]/&YkBnCZCuTvY-'), 'Gmail 所有邮件 → 中文名');
assert_equals('已删除邮件', wwe_folder_leaf($P . '[Gmail]/&XfJSIJZkkK5O9g-'), 'Gmail 已删除邮件 → 中文名');

section('纯 ASCII 名不受影响');

assert_equals('INBOX', wwe_folder_leaf($P . 'INBOX'), 'INBOX 原样');
assert_equals('Junk', wwe_folder_leaf($P . 'Junk'), 'Junk 原样');
assert_equals('Sent', wwe_folder_leaf($P . 'Sent'), 'Sent 原样');
assert_equals('Spam', wwe_folder_leaf('Spam'), '无前缀也原样');
assert_equals('', wwe_folder_leaf(''), '空串');

section('解码后必须能命中匹配表');

// 这两条是"解码没做就必然失败"的回归断言：
// 匹配表里的名字是 UTF-8 中文，只有解码后才可能相等。
$spamExact = array('垃圾邮件', '垃圾箱', '垃圾筒', '垃圾郵件', '廣告郵件', '广告邮件');
$leafCn = wwe_folder_leaf($P . '[Gmail]/&V4NXPpCuTvY-');
assert_true(in_array($leafCn, $spamExact, true), '解码后的 Gmail 垃圾邮件名能命中精确匹配表');

$sentExact = array('sent', 'sent items', 'sent messages', 'sent mail', 'sent-mail', 'sentitems',
                   'gesendet', '已发送', '已发邮件', '已寄郵件', '寄件備份', '已發送');
$leafSent = wwe_folder_leaf($P . '[Gmail]/&XfJT0ZCuTvY-');
assert_true(in_array($leafSent, $sentExact, true), '解码后的 Gmail 已发邮件名能命中精确匹配表');

// 这条是**回归断言**：Gmail 简中账号返回的是「已发邮件」而非「已发送」，
// 第一版匹配表里只有「已发送」，导致解码正确也依然找不到 Sent 文件夹。
assert_equals('已发邮件', $leafSent, 'Gmail 简中 Sent 的确切名字是「已发邮件」');

// 反证：不做解码时会得到什么（说明这条测试确实在守解码）
$raw = '&V4NXPpCuTvY-';
assert_true(!in_array($raw, $spamExact, true), '未解码的 MUTF-7 原串**不可能**命中匹配表（这就是当初失效的原因）');

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    foreach ($GLOBALS['__failures'] as $m) { echo "  - {$m}\n"; echo "::error::{$m}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
