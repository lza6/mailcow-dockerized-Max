#!/usr/bin/env php
<?php
/**
 * Google Postmaster 标签页 —— 真实 Twig 渲染 + 模板↔JS 契约测试（离线）
 *
 * 为什么不能只 grep 源码：
 *   「模板里写了这个 id」和「JS 能找到这个 id」是两回事，中间隔着 Twig 编译、
 *   拼写、以及**改了一边忘了另一边**。本项目历史上就出过「响应体里元素齐全、
 *   前端其实什么都没渲染」的事故（空 tbody）。
 *
 * 本脚本做三件事：
 *   1. 用仓库自带 Twig 真实渲染模板（语法错误会直接抛异常）
 *   2. 把 postmaster.js 里**实际引用**的每个 `$('#id')` 抽出来，
 *      逐个断言它出现在渲染结果里 —— 这条就是"没有假按钮 / 没有断掉的绑定"的机器化判据
 *   3. 断言渲染结果里没有空 `<tbody>`（mailcow 会把它删掉，导致整表永久空白）
 *
 * 用法：php .github/scripts/test-render-postmaster.php
 * 退出码：0 = 全部通过，1 = 有失败
 */

declare(strict_types=1);

$ROOT     = dirname(__DIR__, 2);
$AUTOLOAD = $ROOT . '/data/web/inc/lib/vendor/autoload.php';
$TMPL_DIR = $ROOT . '/data/web/templates';
$TPL      = 'admin/tab-config-postmaster.twig';
$JS       = $ROOT . '/data/web/js/site/postmaster.js';
$LIB      = $ROOT . '/data/web/inc/postmaster_lib.inc.php';

foreach ([$AUTOLOAD, $TMPL_DIR . '/' . $TPL, $JS, $LIB] as $need) {
    if (!file_exists($need)) { fwrite(STDERR, "缺少前置文件：{$need}\n"); exit(1); }
}
require_once $AUTOLOAD;

if (!class_exists('Twig\Environment')) {
    fwrite(STDERR, "Twig 不可用（检查 data/web/inc/lib/vendor 是否完整）\n");
    exit(1);
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_true($cond, string $msg): void
{
    if ($cond) { $GLOBALS['__pass']++; }
    else { $GLOBALS['__fail']++; $GLOBALS['__failures'][] = $msg; echo "  FAIL: {$msg}\n"; }
}

/* ---------------- 1) 真实渲染 ---------------- */
$twig = new Twig\Environment(
    new Twig\Loader\FilesystemLoader($TMPL_DIR),
    ['strict_variables' => false, 'autoescape' => false, 'cache' => false]
);

$html = '';
try {
    $html = $twig->render($TPL);
    assert_true(true, '模板渲染成功（语法正确、可被 admin.twig include）');
} catch (Throwable $e) {
    // 渲染失败就没什么可继续断言的了，直接失败退出
    fwrite(STDERR, "模板渲染抛异常：" . get_class($e) . ' ' . $e->getMessage() . "\n");
    exit(1);
}

assert_true(strlen($html) > 2000, '渲染结果非空（长度 ' . strlen($html) . '）');
assert_true(strpos($html, 'tab-config-postmaster') !== false, '渲染出了 tab-pane 容器');
assert_true(strpos($html, 'collapse-tab-postmaster') !== false, '渲染出了折叠区容器');

/* ---------------- 2) 模板 ↔ JS 契约 ---------------- */
$jsSrc = (string)file_get_contents($JS);

// JS 里所有 $('#xxx') 引用
preg_match_all("/\\\$\('#([A-Za-z0-9_]+)'\)/", $jsSrc, $m);
$jsIds = array_values(array_unique($m[1]));
assert_true(count($jsIds) > 10, 'JS 里抽到足够多的 id 引用（' . count($jsIds) . ' 个）');

$missing = array();
foreach ($jsIds as $id) {
    if (strpos($html, 'id="' . $id . '"') === false) { $missing[] = $id; }
}
assert_true(empty($missing),
    'JS 引用的每个 id 都出现在渲染结果里（缺：' . implode(', ', $missing) . '）');

// 反向：模板里声明了 id，但 JS 从不引用 —— 多半是改了一边忘了另一边。
// 容器类 id（只做分组/锚点）在白名单里。
$allowNoJs = array('tab-config-postmaster', 'collapse-tab-postmaster', 'pm_oauth_card');
preg_match_all('/id="([A-Za-z0-9_]+)"/', $html, $m2);
$tplIds = array_values(array_unique($m2[1]));
$orphan = array();
foreach ($tplIds as $id) {
    if (in_array($id, $allowNoJs, true)) { continue; }
    if (!in_array($id, $jsIds, true)) { $orphan[] = $id; }
}
assert_true(empty($orphan), '模板里没有 JS 从不引用的孤立控件（孤立：' . implode(', ', $orphan) . '）');

// 行内按钮用的是事件委托（class），也要核对：委托了但 JS 从不生成 = 假按钮。
// 判据必须从 JS 里 **class="…" 字面量** 抽，而不是"这个字符串在 JS 里出现过" ——
// 后者恒真（类名就在 on('click','.xxx') 那一行里），等于没测。
preg_match_all('/class="([^"]*)"/', $jsSrc, $m3cls);
$emitted = array();
foreach ($m3cls[1] as $clsStr) {
    foreach (preg_split('/\s+/', trim($clsStr)) as $c) {
        if ($c !== '') { $emitted[$c] = true; }
    }
}
preg_match_all("/on\('click', '\.([A-Za-z0-9_-]+)'/", $jsSrc, $m3);
$delegated = array_values(array_unique($m3[1]));
assert_true(count($delegated) > 0, 'JS 里用了行内事件委托（' . count($delegated) . ' 个 class）');
foreach ($delegated as $cls) {
    assert_true(isset($emitted[$cls]), '委托的 class 确实会被 JS 渲染出来：' . $cls);
}

/* ---------------- 3) 空 tbody 检查 ---------------- */
preg_match_all('#<tbody([^>]*)>(.*?)</tbody>#is', $html, $tb, PREG_SET_ORDER);
assert_true(count($tb) > 0, '渲染结果里至少有一个 tbody');
$empty = array();
foreach ($tb as $t) {
    if (trim(strip_tags($t[2])) === '' && strpos($t[2], '<tr') === false) {
        preg_match('/id="([^"]+)"/', $t[1], $mm);
        $empty[] = $mm[1] ?? '(无 id)';
    }
}
assert_true(empty($empty),
    '没有空 tbody（mailcow 会把空 tbody 删掉导致整表永久空白；空的是：' . implode(', ', $empty) . '）');

/* ---------------- 4) 关键内容必须在 ---------------- */
$need = array(
    'postmaster.google.com'                 => '给出 Postmaster 官网入口',
    'console.cloud.google.com'              => '给出 Cloud API 控制台入口（授权步骤要用）',
    'Gmail Postmaster Tools API'            => '点名要启用的第一个 API',
    'Site Verification API'                 => '点名要启用的第二个 API',
    '/inc/ajax/postmaster.php'              => '把重定向 URI 的可复制值写进了模板',
    'redirect_uri_mismatch'                 => '排错表覆盖了最常见的那条失败',
    'google-site-verification='             => 'TXT 记录形态有示例',
    'dv.googlehosted.com'                   => 'CNAME 目标形态有示例（最容易写错的地方）',
    'DNS_TXT'                               => '验证方式选项存在',
    'DNS_CNAME'                             => '验证方式选项存在',
    '暂无数据'                               => '解释了"量级不够时没有数据是正常的"',
);
foreach ($need as $needle => $why) {
    assert_true(strpos($html, $needle) !== false, '渲染结果包含「' . $needle . '」（' . $why . '）');
}

// 排错表必须真的有多行（不是标题党）
$rows = preg_match_all('#<tr>\s*<td>#', $html);
assert_true($rows >= 7, '排错表至少有 7 条具体条目（实际 ' . $rows . '）');

/* ---------------- 5) 纯函数库能被独立加载 ---------------- */
require_once $LIB;
$rec = pm_dns_record('DNS_CNAME', 'yja3uiatwhx3 gv-hhiylnceqtxskw.dv.googlehosted.com', 'example.com');
assert_true($rec['ok'], '纯函数库可在无 DB/无网络环境下加载并工作');
assert_true(function_exists('pm_summarize_stats'), '汇总函数存在');
assert_true(defined('PM_SPAM_RATIO_WARN'), '阈值常量已定义');

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    foreach ($GLOBALS['__failures'] as $m) { echo "  - {$m}\n"; echo "::error::{$m}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
