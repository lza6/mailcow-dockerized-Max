#!/usr/bin/env php
<?php
/**
 * 真实渲染测试：域名健康弹窗 × 三种界面语言
 *
 * 为什么需要它：
 *   `grep` 只能证明「源码里没有中文字面量」，**不能**证明「渲染后显示的是语言包的值」。
 *   两者之间隔着 Twig 编译、变量作用域、语言包递归合并三层 —— 本项目历史上
 *   多次出现「源码看着对、页面其实空白」（空 tbody 事故）与「键取错段 → 渲染成空白」。
 *
 * 本脚本用仓库自带的 Twig（`data/web/inc/lib/vendor`，已入库）真实渲染模板，
 * 断言**渲染后的 HTML**，因此可以在无容器、无数据库、无网络的 CI 里跑。
 *
 * 覆盖的风险点：
 *   1. 模板语法错误 / 引用了不存在的模板（会直接抛异常）
 *   2. 语言键取错层级（Twig 用 `lang.mailbox.key`；写成裸键会渲染成空白）
 *   3. 三种语言包任一缺键（`array_merge_real` 会回退英文，此脚本验证回退真的发生）
 *   4. 英文界面里漏出中文（证明真的走了语言包，而不是硬编码）
 *
 * 用法：php .github/scripts/test-render-domain-health.php
 * 退出码：0 = 全部通过，1 = 有失败
 */

declare(strict_types=1);

$ROOT     = dirname(__DIR__, 2);
$AUTOLOAD = $ROOT . '/data/web/inc/lib/vendor/autoload.php';
$TMPL_DIR = $ROOT . '/data/web/templates';
$LANG_DIR = $ROOT . '/data/web/lang';

foreach ([$AUTOLOAD, $TMPL_DIR . '/modals/mailbox.twig', $LANG_DIR . '/lang.en-gb.json'] as $need) {
    if (!file_exists($need)) {
        fwrite(STDERR, "缺少前置文件：{$need}\n");
        exit(1);
    }
}
require_once $AUTOLOAD;
require_once $ROOT . '/data/web/inc/lib/array_merge_real.php';

if (!class_exists('Twig\Environment')) {
    fwrite(STDERR, "Twig 不可用（检查 data/web/inc/lib/vendor 是否完整）\n");
    exit(1);
}

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

$loader = new Twig\Environment(
    new Twig\Loader\FilesystemLoader($TMPL_DIR),
    ['strict_variables' => false, 'autoescape' => false, 'cache' => false]
);

// 每个 locale 期望看到的文案（取自语言包，断言的是**渲染结果**里出现它们）
$cases = [
    'en-gb' => [
        'title_needles' => ['Domain Health', 'deliverability checks'],
        'hint_needle'   => 'Checks SPF, DKIM',
        'forbid'        => ['域名健康', '發信投遞', '发信投递'],
    ],
    'zh-cn' => [
        'title_needles' => ['域名健康', '发信投递'],
        'hint_needle'   => '检查 SPF、DKIM',
        'forbid'        => [],
    ],
    'zh-tw' => [
        'title_needles' => ['網域健康', '寄信投遞'],
        'hint_needle'   => '檢查 SPF、DKIM',
        'forbid'        => [],
    ],
];

echo "=== 域名健康弹窗渲染（真实 Twig）===\n";

foreach ($cases as $loc => $exp) {
    // 复刻 prerequisites.inc.php 的真实加载链：英文母本 + 译文覆盖
    $lang = json_decode((string)file_get_contents($LANG_DIR . '/lang.en-gb.json'), true);
    assert_true(is_array($lang), "{$loc}: en-gb 母本可解析");

    $locFile = $LANG_DIR . "/lang.{$loc}.json";
    if ($loc !== 'en-gb') {
        assert_true(file_exists($locFile), "{$loc}: 语言包文件存在");
        if (file_exists($locFile)) {
            $lang = array_merge_real($lang, json_decode((string)file_get_contents($locFile), true));
        }
    }

    try {
        $html = $loader->render('modals/mailbox.twig', ['lang' => $lang]);
    } catch (\Throwable $e) {
        assert_true(false, "{$loc}: 模板渲染抛异常 —— " . $e->getMessage());
        continue;
    }

    // 只截「域名健康」这一个弹窗，避免被同文件其它弹窗的文案干扰
    $seg = '';
    if (preg_match('/<div class="modal fade" id="domainHealthModal".*?<!-- domain health modal -->/s', $html, $m)) {
        $seg = $m[0];
    }
    assert_true($seg !== '', "{$loc}: 渲染结果中存在 domainHealthModal 弹窗");
    if ($seg === '') {
        continue;
    }

    $title = '';
    if (preg_match('/<h3 class="modal-title">(.*?)<\/h3>/s', $seg, $mt)) {
        $title = trim((string)preg_replace('/\s+/', ' ', strip_tags($mt[1])));
    }
    foreach ($exp['title_needles'] as $needle) {
        assert_true(strpos($title, $needle) !== false, "{$loc}: 标题含「{$needle}」（实际「{$title}」）");
    }

    $hint = '';
    if (preg_match('/<p class="text-muted small">(.*?)<\/p>/s', $seg, $mh)) {
        $hint = trim((string)preg_replace('/\s+/', ' ', strip_tags($mh[1])));
    }
    assert_true(strpos($hint, $exp['hint_needle']) !== false, "{$loc}: 提示段落含「{$exp['hint_needle']}」");

    // 渲染后不得残留未解析的 Twig 语法或 undefined
    assert_true(strpos($seg, '{{') === false, "{$loc}: 无残留 {{ }}");
    assert_true(stripos($seg, 'undefined') === false, "{$loc}: 无 undefined");

    foreach ($exp['forbid'] as $bad) {
        assert_true(strpos($seg, $bad) === false, "{$loc}: 渲染结果不含「{$bad}」");
    }
}

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
