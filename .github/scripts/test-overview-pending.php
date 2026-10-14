#!/usr/bin/env php
<?php
/**
 * 域名总览「待体检队列」与 RDAP 三态 —— 纯逻辑测试（离线，无网络、无真 redis）
 *
 * 守的是什么：
 *   1. **首屏绝不发起网络 I/O**。`ov_mark_pending()` 在首屏路径上被调用，
 *      它只允许做 redis 内存操作。一旦有人往里加了一句 curl/dns_get_record，
 *      16 个域就是数十秒的首屏延迟。本测试用**源码级守卫**钉住这条。
 *   2. **RDAP 失败不能被当成"未注册"**。三态（int / false / null）混掉任何一个，
 *      都会让「未知」被固化 —— 早先的实现就是把失败写成 false 并缓存 24 小时。
 *   3. **队列成员不被反复重置顺序**。zAdd 无条件覆盖 score 会让队列在
 *      "反复刷新页面"下退化成 LIFO，老域永远轮不上。
 *   4. **取出即移除**，且 limit 生效（避免一轮处理过多把 cron 拖长）。
 *
 * 用法：php .github/scripts/test-overview-pending.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$F = $ROOT . '/data/web/inc/functions.overview.inc.php';
if (!file_exists($F)) { fwrite(STDERR, "缺 $F\n"); exit(1); }

// functions.overview.inc.php 顶部只有函数定义，无副作用；但它会在
// overview_build 里用到 PDO 等 —— 本测试只调用纯函数，不需要 DB。
require_once $F;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_true($c, string $msg): void {
  if ($c) { $GLOBALS['__pass']++; }
  else { $GLOBALS['__fail']++; $GLOBALS['__failures'][] = $msg; echo "  ✗ $msg\n"; }
}
function assert_equals($exp, $act, string $msg): void {
  if ($exp === $act) { $GLOBALS['__pass']++; }
  else {
    $GLOBALS['__fail']++;
    $GLOBALS['__failures'][] = $msg;
    echo "  ✗ $msg\n      期望: " . var_export($exp, true) . "\n      实际: " . var_export($act, true) . "\n";
  }
}

/**
 * 最小 redis 桩：只实现被测函数用到的方法。
 * 用桩而不是真 redis，是为了让这套测试能进 CI（CI 里没有 redis）。
 */
class FakeRedis {
  public array $data = [];      // key => value
  public array $z    = [];      // key => [member => score]
  public array $ttl  = [];      // key => ttl
  public int   $setexCalls = 0;
  public bool  $throw = false;

  private function boom(): void { if ($this->throw) { throw new Exception('redis down'); } }

  public function zAdd($k, $score, $member) {
    $this->boom();
    if (isset($this->z[$k][$member])) { return 0; }   // 已存在 → 不覆盖、不计新增
    $this->z[$k][$member] = $score;
    return 1;
  }
  public function zRange($k, $start, $stop) {
    $this->boom();
    $m = array_keys($this->z[$k] ?? []);
    if (!$m) { return []; }
    // 按 score 升序（redis 的 zRange 语义）
    usort($m, function ($a, $b) use ($k) { return $this->z[$k][$a] <=> $this->z[$k][$b]; });
    return array_slice($m, $start, $stop - $start + 1);
  }
  public function zRem($k, $member) { $this->boom(); unset($this->z[$k][$member]); return 1; }
  public function zCard($k) { $this->boom(); return count($this->z[$k] ?? []); }
  public function expire($k, $t) { $this->boom(); $this->ttl[$k] = $t; return true; }
  public function get($k) { $this->boom(); return $this->data[$k] ?? false; }
  public function setex($k, $t, $v) { $this->boom(); $this->setexCalls++; $this->data[$k] = $v; $this->ttl[$k] = $t; return true; }
  public function keys($pat) {
    $this->boom();
    $pre = str_replace('*', '', $pat);
    return array_values(array_filter(array_keys($this->data), function ($k) use ($pre) { return strpos($k, $pre) === 0; }));
  }
}

/* ------------------------------------------------------------------ */
echo "\n== 1) 首屏守卫：队列函数不得含任何网络 I/O ==\n";
$src = (string)file_get_contents($F);
foreach (array('ov_mark_pending', 'ov_take_pending', 'ov_healthcheck_info') as $fn) {
  $p = strpos($src, 'function ' . $fn . '(');
  assert_true($p !== false, "$fn 存在");
  $body = substr($src, $p, 2200);
  foreach (array('curl_init', 'curl_exec', 'dns_get_record', 'file_get_contents', 'fsockopen', 'stream_socket') as $bad) {
    // ⚠️ 必须写成 {$bad}：PHP 变量名允许高位字节，直接写 "$bad（" 会被
    //    解析成变量名 `$bad（`（把全角括号也吞进去）→ Undefined variable。
    assert_true(strpos($body, $bad) === false,
      "{$fn} 函数体内不含 {$bad}（首屏路径绝不能有网络 I/O）");
  }
}

echo "\n== 2) 入队：幂等，且不重置已有成员的位置 ==\n";
$r = new FakeRedis();
$n1 = ov_mark_pending($r, array('a.com', 'b.com', 'c.com'));
assert_equals(3, $n1, '首次入队 3 个域');
assert_equals(3, $r->zCard(OV_PENDING_KEY), '队列里有 3 个');
assert_equals(OV_PENDING_TTL, $r->ttl[OV_PENDING_KEY] ?? null, '队列设置了整体 TTL（防僵尸堆积）');

$r->z[OV_PENDING_KEY]['a.com'] = 100;          // 人为把 a.com 放到队头
$scoreBefore = $r->z[OV_PENDING_KEY]['a.com'];
$n2 = ov_mark_pending($r, array('a.com', 'd.com'));
assert_equals(1, $n2, '重复入队只把新成员记为新增（a.com 不算）');
assert_equals($scoreBefore, $r->z[OV_PENDING_KEY]['a.com'],
  '已在队列里的域 **score 不被覆盖** —— 否则反复刷新页面会让老域永远排不到');

echo "\n== 3) 取出：按入队时间先到先出，且取出即移除 ==\n";
$r2 = new FakeRedis();
ov_mark_pending($r2, array('x1.com'));
$r2->z[OV_PENDING_KEY]['x1.com'] = 1000;
$r2->z[OV_PENDING_KEY]['x2.com'] = 2000;
$r2->z[OV_PENDING_KEY]['x3.com'] = 3000;
$taken = ov_take_pending($r2, 2);
assert_equals(array('x1.com', 'x2.com'), $taken, '先入队的先被取出（FIFO）');
assert_equals(1, $r2->zCard(OV_PENDING_KEY), '已取出的成员被移除，只剩 1 个');

$taken2 = ov_take_pending($r2, 50);
assert_equals(array('x3.com'), $taken2, 'limit 大于剩余数时取走全部');
assert_equals(0, $r2->zCard(OV_PENDING_KEY), '队列已空');

echo "\n== 4) 空队列 / redis 异常：返回空数组，绝不抛异常 ==\n";
$r3 = new FakeRedis();
assert_equals(array(), ov_take_pending($r3, 10), '空队列返回空数组');
assert_equals(0, ov_mark_pending($r3, array()), '空域名列表返回 0');

$rBad = new FakeRedis(); $rBad->throw = true;
assert_equals(0, ov_mark_pending($rBad, array('z.com')), 'redis 抛异常时 ov_mark_pending 返回 0（不冒泡）');
assert_equals(array(), ov_take_pending($rBad, 5), 'redis 抛异常时 ov_take_pending 返回空数组');
$hi = ov_healthcheck_info($rBad);
assert_equals(false, $hi['enabled'], 'redis 异常时健康信息 enabled=false');

echo "\n== 5) 队列容量上界 ==\n";
$r4 = new FakeRedis();
$many = array();
for ($i = 0; $i < 200; $i++) { $many[] = "d{$i}.com"; }
ov_mark_pending($r4, $many);
$got = ov_take_pending($r4, 50);
assert_equals(50, count($got), '单轮最多取 50 个（避免一轮把 cron 拖长）');
assert_equals(150, $r4->zCard(OV_PENDING_KEY), '剩余留在队列里，下一轮继续');

echo "\n== 6) 体检健康信息：如实反映「还没跑过」 ==\n";
$r5 = new FakeRedis();
$hi = ov_healthcheck_info($r5);
assert_equals(true, $hi['enabled'], 'redis 可用时 enabled=true');
assert_equals(null, $hi['last_full_age'], '从未跑过时 last_full_age=null（界面据此显示"尚未执行过完整体检"）');
assert_equals('', $hi['last_full_at'], '从未跑过时 last_full_at 为空串');
assert_equals(0, $hi['pending'], '队列为空时 pending=0');
assert_equals('phpfpm_healthcheck', $hi['job'], '给出 cron 任务名（界面要显示给用户去排查）');

$r5->data['OV_CHECK_LAST_FULL'] = (string)(time() - 120);
ov_mark_pending($r5, array('p1.com', 'p2.com'));
$hi = ov_healthcheck_info($r5);
assert_true($hi['last_full_age'] >= 119 && $hi['last_full_age'] <= 125, 'last_full_age 正确计算（约 120 秒）');
assert_equals(2, $hi['pending'], 'pending 反映队列真实长度');

/* ------------------------------------------------------------------ */
echo "\n== 7) RDAP 三态缓存语义（失败不得被当成「未注册」）==\n";
// 用桩直接喂缓存内容，验证读取侧对三态的解析（不触发网络）
function rdap_read_with_cache($cachedJson) {
  $r = new FakeRedis();
  if ($cachedJson !== null) { $r->data['OV_RDAP/t.com'] = $cachedJson; }
  // cacheOnly=true → 绝不会发起网络请求
  return ov_domain_age_days($r, 't.com', true);
}

$age = rdap_read_with_cache(json_encode(array('registered' => time() - 10 * 86400)));
assert_equals(10, $age, 'int → 返回天数（10 天）');

$age = rdap_read_with_cache(json_encode(array('registered' => false)));
assert_equals(null, $age, 'false（确定未注册）→ null');

$age = rdap_read_with_cache(json_encode(array('registered' => null, 'error' => 'HTTP 503', 'at' => time())));
assert_equals(null, $age, 'null（查询失败）→ 页面显示"未知"，但**语义上等同无缓存**');

assert_equals(null, rdap_read_with_cache(null), '完全没有缓存 → null（页面不猜）');
assert_equals(null, rdap_read_with_cache('not-json-at-all'), '缓存内容损坏 → null（不崩）');

echo "\n== 8) 关键回归：失败缓存必须能被 cron 侧重新尝试 ==\n";
// 这条守的是**交叉污染**：若把 registered=null 当成「已有值」，
// cron 就会跳过重试，一次网络抖动永久固化「未知」。
//
// 用源码级断言而不是真发请求：真请求会依赖外网、慢且不稳定，
// 且要触发它必须先走到网络阶段 —— 而"有没有走到"正是要验的东西。
$funcStart = strpos($src, 'function ov_domain_age_days(');
$funcEnd   = strpos($src, "\nfunction ", $funcStart + 10);
assert_true($funcStart !== false && $funcEnd !== false, '能定位到 ov_domain_age_days 的完整函数体');
$readPath = substr($src, $funcStart, $funcEnd - $funcStart);
assert_true(strpos($readPath, "\$d['registered'] === null") !== false,
  '读取侧显式识别 null 三态（不是只判真假）');
assert_true(preg_match('/\$d\[.registered.\]\s*===\s*null\s*\)\s*\{\s*\/\/[^\n]*\n\s*\$d\s*=\s*null;/s', $readPath) === 1
          || preg_match('/registered.\]\s*===\s*null[^}]*\$d\s*=\s*null;/s', $readPath) === 1,
  'null 分支把 $d 置空（= 当作无缓存），从而允许 cacheOnly=false 继续往下走');
assert_true(strpos($readPath, '$cacheOnly) { return null; }') !== false
       || strpos($readPath, 'if ($cacheOnly)') !== false,
  'cacheOnly 早退仍在（页面路径不发起网络）');

// 缓存 TTL 三档必须区分：成功 24h / 未注册 1h / 失败 300s
assert_true(strpos($readPath, 'setex($key, 300,') !== false, '失败缓存 300 秒（不是 24 小时）');
assert_true(strpos($readPath, 'setex($key, 3600,') !== false, '确定未注册缓存 1 小时');
assert_true(strpos($readPath, 'setex($key, 86400,') !== false, '成功缓存 24 小时');
assert_true(strpos($readPath, 'CURLINFO_HTTP_CODE') !== false, '读取 HTTP 码（区分 404 与 5xx）');
assert_true(strpos($readPath, 'curl_error') !== false, '记录 curl_error（失败原因不能静默）');

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
  foreach ($GLOBALS['__failures'] as $m) { echo "  - $m\n"; echo "::error::$m\n"; }
  exit(1);
}
echo "全部通过\n";
exit(0);
