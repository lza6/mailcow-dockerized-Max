#!/usr/bin/env php
<?php
/**
 * 语言包回退（fallback）行为测试
 *
 * 复刻 data/web/inc/prerequisites.inc.php:269-274 的真实加载链路：
 *
 *     $lang = json_decode(file_get_contents('lang.en-gb.json'), true);
 *     if (file_exists('lang.<locale>.json')) {
 *         $lang = array_merge_real($lang, json_decode(file_get_contents(...), true));
 *     }
 *
 * 即「先加载英文母本，再用译文叠加覆盖」。译文缺失的 key 必须回退英文，
 * 否则 Twig 取到 null 会渲染成空白（本项目最常见的汉化回归形态）。
 *
 * 与另外两个校验脚本的分工：
 *   - check-lang.cjs         静态检查「译文文件是否缺 key」
 *   - check-placeholders.cjs 静态检查「占位符 / HTML 标签是否一致」
 *   - 本脚本                 动态检查「合并后的 $lang 在运行期是否真的可用」
 *     覆盖静态检查触及不到的部分：合并语义、空串、非字符串值、无译文时的回退结果、
 *     以及 array_merge_real 的 2 层合并语义对译文结构提出的前提条件。
 *
 * 用法：php .github/scripts/test-lang-fallback.php
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

/** 展平为 "section.key" => value，用于逐 key 比对 */
function flatten_lang(array $node, string $prefix = ''): array
{
    $out = [];
    foreach ($node as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out += flatten_lang($v, $key);
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

/** 收集所有「值本身是数组」的节点路径 => 其直接子 key 列表 */
function array_nodes(array $node, string $prefix = ''): array
{
    $out = [];
    foreach ($node as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        $out[$key] = array_map('strval', array_keys($v));
        $out += array_nodes($v, $key);
    }
    return $out;
}

function load_json(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

const LOCALES = ['zh-cn', 'zh-tw'];

$ROOT = dirname(__DIR__, 2);
$langDir = $ROOT . '/data/web/lang';

/* ------------------------------------------------------------------ *
 * 1. 母本可加载
 * ------------------------------------------------------------------ */
section('英文母本');

$en = load_json($langDir . '/lang.en-gb.json');
assert_true($en !== [], 'lang.en-gb.json 可解析为非空数组');

$enLeaves = flatten_lang($en);
assert_true(
    count($enLeaves) > 1000,
    '英文母本叶子 key 数量合理（实际 ' . count($enLeaves) . '，阈值 >1000）'
);

// 母本自身必须是「无空白」的：locale 文件缺失时会直接使用母本，
// 若母本存在 null 或空串，界面会出现无法回退的空白
$enNulls = 0;
foreach ($enLeaves as $v) {
    if ($v === null) {
        $enNulls++;
    }
}
assert_equals(0, $enNulls, '英文母本不含 null（缺失 locale 文件时母本即为最终结果）');

/* ------------------------------------------------------------------ *
 * 2. array_merge_real 的合并语义（真实函数，非桩件）
 * ------------------------------------------------------------------ */
section('array_merge_real 合并语义');

$mergeLib = $ROOT . '/data/web/inc/lib/array_merge_real.php';
assert_true(file_exists($mergeLib), 'array_merge_real.php 存在');
require_once $mergeLib;
assert_true(function_exists('array_merge_real'), 'array_merge_real() 已定义且可调用');

$base = ['s' => ['a' => 'EN-A', 'b' => 'EN-B'], 't' => ['c' => 'EN-C']];
$over = ['s' => ['a' => 'ZH-A']];
$m = array_merge_real($base, $over);
assert_equals('ZH-A', $m['s']['a'] ?? null, '译文存在的 key 取译文');
assert_equals('EN-B', $m['s']['b'] ?? null, '译文缺失的 key 回退英文');
assert_equals('EN-C', $m['t']['c'] ?? null, '译文缺失的 section 整体回退英文');
assert_equals(3, count(flatten_lang($m)), '合并不会丢失任何 key');

/* ------------------------------------------------------------------ *
 * 3. 真实语言包：按 prerequisites.inc.php 的顺序合并
 * ------------------------------------------------------------------ */
section('真实语言包合并结果');

foreach (LOCALES as $loc) {
    $file = $langDir . '/lang.' . $loc . '.json';
    assert_true(file_exists($file), "lang.{$loc}.json 存在");

    $trans = load_json($file);
    assert_true($trans !== [], "lang.{$loc}.json 可解析为非空数组");

    // 复刻真实加载顺序：母本 → 叠加译文
    $merged = array_merge_real($en, $trans);
    $mergedLeaves = flatten_lang($merged);
    $transLeaves = flatten_lang($trans);

    // 3.1 不得丢 key
    $missing = array_diff_key($enLeaves, $mergedLeaves);
    assert_equals(
        0,
        count($missing),
        "{$loc}: 合并后无缺失 key（Twig 取不到会渲染空白）"
    );
    if ($missing) {
        echo '    缺失示例: ' . implode(', ', array_slice(array_keys($missing), 0, 5)) . "\n";
    }

    // 3.2 合并结果不得出现 null / 非字符串叶子
    $nulls = 0;
    $nonString = 0;
    foreach ($mergedLeaves as $v) {
        if ($v === null) {
            $nulls++;
        } elseif (!is_string($v)) {
            $nonString++;
        }
    }
    assert_equals(0, $nulls, "{$loc}: 合并后无 null 叶子");
    assert_equals(0, $nonString, "{$loc}: 合并后所有叶子均为字符串");

    // 3.3 译文不得把母本的非空文案翻成空串
    //     （母本自身为空串的 key —— 如 datatables.infoPostFix —— 不受此限制）
    $blanked = [];
    foreach ($enLeaves as $key => $enVal) {
        if (!is_string($enVal) || trim($enVal) === '') {
            continue;
        }
        $v = $mergedLeaves[$key] ?? null;
        if (is_string($v) && trim($v) === '') {
            $blanked[] = $key;
        }
    }
    assert_equals(0, count($blanked), "{$loc}: 没有把非空原文翻成空串");
    if ($blanked) {
        echo '    空串示例: ' . implode(', ', array_slice($blanked, 0, 5)) . "\n";
    }

    // 3.4 译文确实生效（存在译文处取译文）
    $notOverlaid = [];
    foreach ($transLeaves as $key => $v) {
        if (!is_string($v)) {
            continue;
        }
        if (($mergedLeaves[$key] ?? null) !== $v) {
            $notOverlaid[] = $key;
        }
    }
    assert_equals(0, count($notOverlaid), "{$loc}: 有译文的 key 全部取到译文（覆盖生效）");

    // 3.5 无译文的 key 回退为英文原文（逐 key 核对回退结果）
    $badFallback = [];
    foreach ($enLeaves as $key => $enVal) {
        if (array_key_exists($key, $transLeaves)) {
            continue;
        }
        if (($mergedLeaves[$key] ?? null) !== $enVal) {
            $badFallback[] = $key;
        }
    }
    assert_equals(0, count($badFallback), "{$loc}: 未翻译的 key 精确回退为英文原文");
}

/* ------------------------------------------------------------------ *
 * 4. array_merge_real 的 2 层语义对译文结构提出的前提
 *
 * array_merge_real 在 section 层调用的是 PHP 原生 array_merge（浅合并）：
 * 若某个 section 下还有一层数组（如 datatables.paginate），译文里的该子对象
 * 会整体替换英文而**不做深合并**。因此译文里这些子对象的 key 必须与英文完全一致，
 * 否则未被翻译的子 key 会在运行期凭空消失（check-lang.cjs 虽也能发现，但那是
 * 静态的文件比对；这里直接锁定运行期前提）。
 * ------------------------------------------------------------------ */
section('嵌套子对象结构一致性（2 层合并的运行期前提）');

$enNodes = array_nodes($en);
assert_true(count($enNodes) > 0, '英文母本含嵌套子对象（实际 ' . count($enNodes) . ' 个）');

foreach (LOCALES as $loc) {
    $trans = load_json($langDir . '/lang.' . $loc . '.json');
    $transNodes = array_nodes($trans);
    $problems = [];

    foreach ($enNodes as $path => $keys) {
        if (!isset($transNodes[$path])) {
            $problems[] = $path . ' (译文缺失该子对象)';
            continue;
        }
        $tk = $transNodes[$path];
        sort($keys);
        sort($tk);
        if ($keys !== $tk) {
            $problems[] = $path . ' (英文 ' . count($keys) . ' 键 / 译文 ' . count($tk) . ' 键)';
        }
    }

    assert_equals(0, count($problems), "{$loc}: 嵌套子对象 key 集合与英文一致");
    foreach (array_slice($problems, 0, 5) as $p) {
        echo "    {$p}\n";
    }
}

/* ------------------------------------------------------------------ *
 * 5. 语言选择器声明的 locale 都必须有语言包
 *
 * prerequisites.inc.php 只在 $AVAILABLE_LANGUAGES 内匹配 locale；若某 locale
 * 出现在选择器里却没有同名语言包，界面会静默显示英文（用户会报「切换无效」）。
 * ------------------------------------------------------------------ */
section('$AVAILABLE_LANGUAGES 与语言包文件一致性');

$varsFile = $ROOT . '/data/web/inc/vars.inc.php';
assert_true(file_exists($varsFile), 'vars.inc.php 存在');

$declared = [];
if (file_exists($varsFile)) {
    $lines = preg_split('/\r?\n/', (string) file_get_contents($varsFile));
    $start = null;
    $end = null;
    foreach ($lines as $i => $line) {
        if ($start === null && strpos($line, '$AVAILABLE_LANGUAGES') !== false) {
            $start = $i;
        }
        if ($start !== null && $i > $start && preg_match('/^\s*\);/', $line)) {
            $end = $i;
            break;
        }
    }
    if ($start !== null && $end !== null) {
        for ($i = $start; $i <= $end; $i++) {
            // 只认数组字面量里 2 空格缩进的 'xx-yy' => '名称'，注释行天然不匹配
            if (preg_match("/^\s{2}'([a-z]{2}-[a-z]{2})'\s*=>/", $lines[$i], $mm)) {
                $declared[] = $mm[1];
            }
        }
    }
}

// 防止正则失效导致「解析到 0 项」却静默通过
assert_true(
    count($declared) >= 25,
    '$AVAILABLE_LANGUAGES 解析出合理条目数（实际 ' . count($declared) . '）'
);

$noFile = [];
foreach ($declared as $loc) {
    if (!file_exists($langDir . '/lang.' . $loc . '.json')) {
        $noFile[] = $loc;
    }
}
assert_equals(0, count($noFile), '选择器声明的每个 locale 都有对应语言包');
if ($noFile) {
    echo '    缺语言包: ' . implode(', ', $noFile) . "\n";
}

foreach (LOCALES as $loc) {
    assert_true(in_array($loc, $declared, true), "{$loc} 已在 \$AVAILABLE_LANGUAGES 中声明");
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
