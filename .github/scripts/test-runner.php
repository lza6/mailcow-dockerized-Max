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
 * 5. DB 版本门控：$db_version 必须是「活的」单次赋值
 *
 * 为什么值得单独守一道：
 *   init_db_schema() 是版本门控的 —— `versions.db_schema` 与 `$db_version`
 *   相等就直接 return，不跑表结构差异。而 sogo 的 bootstrap-sogo.sh 会用
 *   `grep -oE '$db_version = .*;'` 从这个文件里把它 grep 出来做等待判据。
 *
 *   2026-10-11 的 b118644d5 声称「提升 $db_version」，实际把赋值行改成了**注释**：
 *     · PHP 侧 $db_version 变成 null → 门控恒不生效、版本号写不进库
 *     · sogo 侧 grep 到的是注释里的值 → 与库里的值永远不等 → **死循环等 schema**
 *   结果是项目新增的 10 张表（含 warmup_messages）在库里一直不存在，且不报错。
 *
 *   这一节就是防它复发：赋值必须存在、必须是活代码、必须只出现一次。
 * ------------------------------------------------------------------ */
section('DB 版本门控');

$initDb = $ROOT . '/data/web/inc/init_db.inc.php';
assert_true(file_exists($initDb), 'init_db.inc.php 存在');

if (file_exists($initDb)) {
    $src = file_get_contents($initDb);

    // 行首非注释的赋值语句才算「活代码」
    $liveHits = array();
    foreach (preg_split('/\r?\n/', $src) as $line) {
        if (preg_match('/^\s*\$db_version\s*=\s*"[^"]+"\s*;/', $line)) {
            $liveHits[] = trim($line);
        }
    }
    assert_true(count($liveHits) === 1, '$db_version 恰好有一处未被注释的赋值（实际 ' . count($liveHits) . ' 处）');

    // grep 得到的赋值必须唯一，否则 sogo 的等待判据会取到多个值
    $grepLike = preg_match_all('/\$db_version\s*=\s*"[^"]*"\s*;/', $src);
    assert_true($grepLike === 1, '`\$db_version = "…";` 在全文件只出现一次，sogo 的 grep 不会取到歧义值（实际 ' . $grepLike . ' 处）');

    // 赋值必须出现在门控比较之前，否则比较时它还是 null
    $assignAt = strpos($src, '$db_version = ');
    $compareAt = strpos($src, '== $db_version');
    assert_true($assignAt !== false && $compareAt !== false && $assignAt < $compareAt,
        '$db_version 的赋值出现在门控比较之前');

    // 值必须是形如 yyyymmdd_hhmm 的版本串
    if (!empty($liveHits)) {
        assert_true(
            (bool)preg_match('/\$db_version\s*=\s*"\d{8}_\d{4}"/', $liveHits[0]),
            '$db_version 是 yyyymmdd_hhmm 形式的版本串（实际 ' . $liveHits[0] . '）'
        );
    }
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
