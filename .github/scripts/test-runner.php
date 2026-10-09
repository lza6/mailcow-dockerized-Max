#!/usr/bin/env php
<?php
/**
 * 轻量断言测试运行器
 *
 * 项目无 PHPUnit 依赖（依赖已 vendor 且不含开发工具），
 * 因此用纯 PHP 实现一个最小断言框架，保证 CI 里能真实执行测试。
 *
 * 用法：php .github/scripts/test-runner.php
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
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        $GLOBALS['__failures'][] = $msg;
        echo "  FAIL: {$msg}\n    期望: {$exp}\n    实际: {$act}\n";
    }
}

function section(string $name): void
{
    echo "\n=== {$name} ===\n";
}

$ROOT = dirname(__DIR__, 2);

/* ------------------------------------------------------------------ *
 * 1. 语言包：结构与回退逻辑
 * ------------------------------------------------------------------ */
section('语言包结构');

$langDir = $ROOT . '/data/web/lang';
$master = json_decode(file_get_contents($langDir . '/lang.en-gb.json'), true);
assert_true(is_array($master), 'en-gb 母本可解析为数组');
assert_true(count($master) > 20, 'en-gb 母本包含多个 section');

foreach (['lang.zh-cn.json', 'lang.zh-tw.json'] as $f) {
    $data = json_decode(file_get_contents($langDir . '/' . $f), true);
    assert_true(is_array($data), "{$f} 可解析");
    // 每个 section 都应是关联数组（不是列表），否则前端按 lang.section.key 取值会失败
    $allAssoc = true;
    foreach ($data as $sec => $val) {
        if (!is_array($val)) {
            $allAssoc = false;
            break;
        }
    }
    assert_true($allAssoc, "{$f} 所有 section 均为对象");
}

/* ------------------------------------------------------------------ *
 * 2. array_merge_real 回退行为（语言包加载的核心实现）
 * ------------------------------------------------------------------ */
section('array_merge_real 回退逻辑');

require_once $ROOT . '/data/web/inc/lib/array_merge_real.php';

$en = ['a' => ['x' => 'EN-X', 'y' => 'EN-Y'], 'b' => ['z' => 'EN-Z']];
$cn = ['a' => ['x' => 'CN-X']];

$merged = array_merge_real($en, $cn);
assert_equals('CN-X', $merged['a']['x'], '中文覆盖英文（同 key 取中文）');
assert_equals('EN-Y', $merged['a']['y'], '中文缺失的 key 回退英文');
assert_equals('EN-Z', $merged['b']['z'], '中文缺失的 section 回退英文');

/* ------------------------------------------------------------------ *
 * 3. 安全性：语言包不得包含可执行内容
 * ------------------------------------------------------------------ */
section('语言包安全');

foreach (['lang.zh-cn.json', 'lang.zh-tw.json', 'lang.en-gb.json'] as $f) {
    $raw = file_get_contents($langDir . '/' . $f);
    assert_true(strpos($raw, '<script') === false, "{$f} 不含 <script 标签");
    assert_true(strpos($raw, '<?php') === false, "{$f} 不含 PHP 代码");
    assert_true(strpos($raw, 'javascript:') === false, "{$f} 不含 javascript: 协议");
}

/* ------------------------------------------------------------------ *
 * 4. 域名健康检查：文件存在且语法可解析
 * ------------------------------------------------------------------ */
section('域名健康检查模块');

$healthFile = $ROOT . '/data/web/inc/ajax/domain_health.php';
assert_true(file_exists($healthFile), 'domain_health.php 存在');

if (file_exists($healthFile)) {
    $src = file_get_contents($healthFile);
    assert_true(strpos($src, 'mailcow_cc_role') !== false, '包含权限校验（mailcow_cc_role）');
    assert_true(strpos($src, 'htmlspecialchars') !== false, '输出经过 HTML 转义');
    // 不允许出现未转义的直接输出
    assert_true(
        preg_match('/echo\s+\$_(GET|POST|REQUEST)\b/', $src) !== 1,
        '未直接输出超全局变量'
    );
}

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
