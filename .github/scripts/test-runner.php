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

    // 本次要加的两个列必须在表定义里（漏了就会被 init_db 对账**删掉**）
    $dbSrc2 = file_get_contents($initDb);
    assert_true(strpos($dbSrc2, '"relayhost_id" =>') !== false,
        'resend_accounts 定义里有 relayhost_id 列');
    assert_true(strpos($dbSrc2, '"message_token" =>') !== false,
        'warmup_messages 定义里有 message_token 列');
}

/* ------------------------------------------------------------------ *
 * 6. 养号：投递反查锚点（token）
 *
 * 守的是一个**静默失效**：投递判定要拿「我们记下的标识」去收件人邮箱里
 * 反查这封邮件落在收件箱还是垃圾箱。锚点选错的话，查询不报错、
 * 只是永远找不到 —— 垃圾箱率恒为 0，而界面上一切正常。
 *
 * 三条已知走不通的路（都实测过）：
 *   · Message-ID —— 出站走中继时中继会重写它（Resend → @send.域 / amazonses）
 *   · HEADER 搜索 —— **PHP 的 imap_search() 自己就不支持**，请求根本不发出去
 *   · X-GM-RAW  —— 同上，也是被 PHP 挡掉
 * 唯一能用又快的：把唯一 token 放进 Subject，用 SUBJECT 搜。
 * ------------------------------------------------------------------ */
section('养号投递反查锚点');

$warmupInc = $ROOT . '/data/web/inc/functions.warmup.inc.php';
$warmupExt = $ROOT . '/data/web/inc/functions.warmup_ext.inc.php';
assert_true(file_exists($warmupInc) && file_exists($warmupExt), '养号核心文件存在');

if (file_exists($warmupInc) && file_exists($warmupExt)) {
    require_once $warmupExt;
    require_once $warmupInc;

    $t1 = warmup_new_token();
    $t2 = warmup_new_token();
    assert_true((bool)preg_match('/^WU[A-Z0-9]{10}$/', $t1), 'token 形如 WU + 10 位大写字母数字（实际 ' . $t1 . '）');
    assert_true($t1 !== $t2, '两次生成的 token 不相同');
    // token 是对外可见的唯一键：撞了会把结果判到别的邮件头上，且完全静默
    $seen = array();
    for ($i = 0; $i < 500; $i++) { $seen[warmup_new_token()] = 1; }
    assert_equals(500, count($seen), '连续生成 500 个 token 无重复');
    // 只含安全字符：不引入需要 IMAP 转义的字符，也不会被 Subject 搜索误解
    assert_true(strpbrk($t1, "\"'\\*%(){}<>[] ") === false, 'token 只含安全字符');

    $incSrc = file_get_contents($warmupInc);
    $extSrc = file_get_contents($warmupExt);

    // 主题里必须真的带上 token，否则搜不到
    assert_true(strpos($incSrc, '(ref %s)') !== false, '发信主题模板里带上 token');
    assert_true(strpos($incSrc, "'token' => \$token") !== false
             || strpos($incSrc, "'token' => \$token,") !== false,
        'warmup_send_mail() 把 token 回传给调用方');

    // 搜索条件：必须用 SUBJECT；HEADER / X-GM-RAW 是被 PHP 拒掉的死路
    assert_true(strpos($extSrc, "'SUBJECT \"'") !== false, '按 SUBJECT 搜索 token');
    // 注意断言的是**搜索条件字面量**（带引号），而不是裸词 ——
    // 注释里解释"为什么不用 X-GM-RAW"时本来就会提到它。
    assert_true(strpos($extSrc, "'X-GM-RAW") === false, '不再把 X-GM-RAW 当搜索条件（会被 PHP 拒绝）');
    assert_true(strpos($extSrc, "'HEADER ") === false, '不再把 HEADER 当搜索条件（会被 PHP 拒绝）');

    // 兜底路径的候选上限：它直接决定耗时（Gmail 上约 1.3 秒/封）。
    // 曾经是 300 → 一本 16 封邮件要跑近一小时，check 永远跑不完。
    assert_true(strpos($extSrc, '$verify(array_reverse($r3), 40)') !== false,
        'Message-ID 兜底路径只读最近 40 封的头（不是 300）');

    // token 是可选参数，旧调用点不能被打断
    $rf = new ReflectionFunction('wwe_find_message');
    assert_equals(5, $rf->getNumberOfParameters(), 'wwe_find_message 有 5 个参数');
    assert_equals(4, $rf->getNumberOfRequiredParameters(), 'wwe_find_message 前 4 个参数必填、token 可选');

    $rr = new ReflectionFunction('wwe_rescue_from_spam');
    assert_equals(3, $rr->getNumberOfParameters(), 'wwe_rescue_from_spam 有 3 个参数（token 可选）');

    // 连接层重试存在，但认证失败不重试（否则会把 Google 的限流拖更久）
    assert_true(strpos($extSrc, 'AUTHENTICATIONFAILED') !== false,
        'wwe_connect 能识别认证失败并停止重试');
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
