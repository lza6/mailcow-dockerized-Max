#!/usr/bin/env node
/**
 * Google Postmaster 后台脚本 —— **真实 DOM 行为测试**（jsdom + jQuery）
 *
 * 为什么必须有这一层：
 *   PHP 侧的纯逻辑测试（test-postmaster.php）只能证明"算出来的数是对的"。
 *   但从"算出正确的数"到"界面上显示成对的字"之间还隔着一整层渲染代码，
 *   而这一层的错误**不会报错、不会崩** —— 只会安安静静地把百分比显示错、
 *   把字段读成 undefined 显示成 "NaN%"、或者整块内容不显示。
 *   渲染契约测试（test-render-postmaster.php）只能查 id 有没有对上，查不了值。
 *   所以这里用 jsdom 真的把页面跑起来，喂进构造好的数据，**读回真实 DOM 文本**。
 *
 * 重点守的是 MEDIUM-1 那个坑：
 *   errorRatio 是**单日比例**。早期实现逐日累加，7 天 × 0.5% 会变成 3.5，
 *   界面再乘 100 就显示成「350%」—— 一个物理上不可能的数。
 *   现在后端按"有错误的天"求平均，前端展示日均 + 出现天数 + 单日最高。
 *
 * 用法：node .github/scripts/test-js-postmaster.mjs
 *   需要 jsdom 与 jquery（devDependencies）。缺依赖时**明确跳过并说明**，
 *   不假装通过 —— 静默跳过等于把这一层保护丢掉。
 */

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const JS_FILE = resolve(ROOT, 'data/web/js/site/postmaster.js');
const TPL_FILE = resolve(ROOT, 'data/web/templates/admin/tab-config-postmaster.twig');

let pass = 0;
let fail = 0;
const failures = [];

function ok(cond, msg) {
  if (cond) { pass++; } else { fail++; failures.push(msg); console.error('  ✗ ' + msg); }
}
function eq(exp, act, msg) {
  if (exp === act) { pass++; } else {
    fail++; failures.push(msg + ' — 期望 ' + JSON.stringify(exp) + '，实际 ' + JSON.stringify(act));
    console.error('  ✗ ' + msg + '\n      期望: ' + JSON.stringify(exp) + '\n      实际: ' + JSON.stringify(act));
  }
}
function has(hay, needle, msg) {
  const c = String(hay).includes(needle);
  if (c) { pass++; } else {
    fail++; failures.push(msg + ' — 未包含 ' + JSON.stringify(needle));
    console.error('  ✗ ' + msg + '\n      实际: ' + JSON.stringify(String(hay).slice(0, 400)));
  }
}
function notHas(hay, needle, msg) {
  const c = String(hay).includes(needle);
  if (!c) { pass++; } else {
    fail++; failures.push(msg + ' — 不应包含 ' + JSON.stringify(needle));
    console.error('  ✗ ' + msg + '\n      实际: ' + JSON.stringify(String(hay).slice(0, 400)));
  }
}

// ---- 依赖检查：宁可明确报"跑不了"，也不假装通过 ----
//
// ⚠️ 顺序很关键：jQuery 在 Node 下**必须**能取到全局 window，否则
//    它在 require 阶段就抛 "jQuery requires a window with a document"。
//    （jsdom 12+ 的行为：`require('jquery')` 会看 global.window/document。）
//    所以这里先建 DOM、把 window 挂到 global，**再** require jquery。
//    先 require 后挂 window 是最常见的写法错误，症状是"依赖明明装了却报缺依赖"。
// 依赖解析：优先用同目录下 js-harness 的隔离依赖（CI 里 `npm ci --prefix .github/scripts/js-harness`），
// 退回仓库根的 node_modules（本地开发时 `npm i -D jsdom jquery`）。
// 这样测试既能在 CI 里稳定拿到依赖，也能在开发者机器上按常规方式跑。
//
// ⚠️ createRequire 的入参必须是**文件路径**，它会从该文件的目录开始向上找
//    node_modules。传目录进去会抛 ERR_INVALID_ARG_VALUE，
//    而这正好会被这里的 try 吞掉 → 报成"依赖没装"，误导排查方向。
function loadDep(name) {
  const bases = [resolve(HERE, 'js-harness', '_resolve.js'), resolve(ROOT, '_resolve.js')];
  const tried = [];
  for (const base of bases) {
    try {
      return { mod: createRequire(base)(name), via: base };
    } catch (e) { tried.push(base + ' → ' + (e.code || e.message)); }
  }
  const err = new Error('找不到 ' + name + '（尝试过：' + tried.join('; ') + '）');
  err.code = 'MODULE_NOT_FOUND';
  throw err;
}

let JSDOM;
try {
  ({ JSDOM } = loadDep('jsdom').mod);
} catch (e) {
  console.error('[skip] 缺少 jsdom，无法运行 DOM 行为测试。');
  console.error('       安装：npm ci --prefix .github/scripts/js-harness');
  console.error('       原因：' + e.message);
  process.exit(2); // 2 = 未执行（区别于 1 = 失败）
}

if (!existsSync(JS_FILE)) { console.error('缺 ' + JS_FILE); process.exit(1); }
const jsSrc = readFileSync(JS_FILE, 'utf8');

// ---- 从模板抽出所有 id，构造一份等价 DOM ----
// 这样"页面里有没有这个元素"由**模板**决定，测试不会自己编出一个
// 模板里根本不存在的容器来蒙混过关。
const tpl = existsSync(TPL_FILE) ? readFileSync(TPL_FILE, 'utf8') : '';
const ids = [...new Set([...tpl.matchAll(/id="([A-Za-z0-9_]+)"/g)].map((m) => m[1]))];

function buildDom() {
  const body = ids.map((id) => `<div id="${id}"></div>`).join('\n');
  // tbody 用 table 包起来，才能验证 render() 往 tbody 里灌行的行为
  const html = `<!doctype html><html><body>
${body}
<table id="pm_domains_table"><tbody id="pm_domains_body"><tr><td>占位</td></tr></tbody></table>
</body></html>`;
  return new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true });
}

const dom = buildDom();
const { window } = dom;
// 先挂 window，再 require jquery —— 顺序不能反（见上面的注释）
global.window = window;
global.document = window.document;
let jq;
try {
  jq = loadDep('jquery').mod;
} catch (e) {
  console.error('[skip] 缺少 jquery（或它取不到 window），无法运行 DOM 行为测试。');
  console.error('       安装：npm ci --prefix .github/scripts/js-harness');
  console.error('       原因：' + e.message);
  process.exit(2);
}
// jquery 的 CJS 导出是个工厂：给了 window 就返回绑定好的 jQuery。
// 若它已被 window 感知过，导出本身就是 jQuery 实例 —— 两种形态都兼容。
const $$ = (typeof jq === 'function' && jq.fn) ? jq : jq(window);
window.jQuery = window.$ = $$;

// ⚠️ 必须在注入脚本**之前**就装好 getJSON 桩。
//    脚本在 jQuery ready 时立刻会自己 load() 一次；如果那时桩还没装，
//    真实的 $.getJSON 会去打 /inc/ajax/postmaster.php，在 jsdom 里必然失败，
//    渲染入口就此错过 —— 后面再点「刷新」也补不回"首屏那一次"。
//    现象是断言全挂在"渲染入口未触发"上，很容易误判成脚本坏了。
//    （不能改用 ajaxPrefilter：$.getJSON 的成功链建立在 prefilter 之后，
//      拦下请求也不会真正触发 render。）
let served = null;
$$.getJSON = function () {
  const d = $$.Deferred();
  if (served) { d.resolve(served); } else { d.reject(); }
  return d.promise();
};

// 让页面内的脚本也能看到同一个 jQuery（脚本第一句就是 $(document).ready）
window.eval('window.jQuery = window.$;');

let threw = null;
try {
  window.eval(jsSrc);
} catch (e) {
  threw = e;
}
eq(null, threw ? String(threw.message || threw) : null, '脚本能在真实 DOM 上执行而不抛异常');

// mailcow 的 jQuery ready 是异步的，必须让出一轮事件循环再断言
await new Promise((r) => window.setTimeout(r, 150));

const $ = $$;
const $tbody = window.document.getElementById('pm_domains_body');
const text = () => ($tbody ? $tbody.textContent : '');

console.log('\n== A) 首屏：模板 id 齐备时脚本能正常挂载 ==');
ok(ids.length >= 20, '从模板抽到足够多的 id（' + ids.length + ' 个）');
ok(!threw, '脚本已挂载（无异常）');

console.log('\n== B) 投递错误渲染：日均 + 天数 + 单日最高（MEDIUM-1 回归守卫）==');
// getJSON 桩已在注入脚本前装好（见上面的注释），这里只需喂数据并触发渲染。
// 渲染入口：脚本把 load() 绑在 #pm_refresh 的 click 上。
const $refresh = $('#pm_refresh');
ok($refresh.length === 1, '模板里有 #pm_refresh 刷新按钮（渲染入口可被触发）');

// 构造两条投递错误，验证排序与展示口径
const sample = {
  ok: true,
  csrf: 'tok',
  data: {
    oauth: { connected: false, configured: false, email: '' },
    stats: { total: 1, verified: 0, pending: 1 },
    domains: [{
      domain: 'trendhollowretail.com',
      badge: { level: 'warn', label: 'DNS 已写入，待 Google 确认' },
      registered: false,
      last_error: '',
      dns_type: 'CNAME',
      dns_fqdn: 'yja3uiatwhx3.trendhollowretail.com',
      dns_content: 'gv-hhiylnceqtxskw.dv.googlehosted.com',
      dns_written_at: '2026-10-13 10:00:00',
      health: {
        days: 7,
        latest_date: '20261012',
        reputation: { level: 'high', label: '高' },
        spam: { level: 'good', label: '正常', pct: 0.02 },
        spf: { level: 'good', pct: 100 },
        dkim: { level: 'good', pct: 99.8 },
        dmarc: { level: 'good', pct: 100 },
        // 关键：ratio 是**日均**，必须是 0.005 这种量级，而**不是** 0.035
        errors: [
          { class: 'PERMANENT_ERROR', type: 'SUSPECTED_SPAM', ratio: 0.005, days: 7, max: 0.02 },
          { class: 'TEMPORARY_ERROR', type: 'RATE_LIMIT_EXCEEDED', ratio: 0.001, days: 2, max: 0.001 },
        ],
      },
    }],
  },
};

// 触发一次渲染（served 已在上面声明；这里把样例数据喂进去）
served = sample;
$refresh.trigger('click');
await new Promise((r) => window.setTimeout(r, 400));

const t = text();
console.log('  渲染结果片段：' + JSON.stringify(t.slice(0, 300)));

if (t.includes('SUSPECTED_SPAM')) {
  has(t, 'trendhollowretail.com', '域名被渲染出来');
  has(t, 'SUSPECTED_SPAM', '展示了投递错误类型');
  has(t, '0.50%', '错误比例按**日均**展示：0.005 → 0.50%');
  notHas(t, '350.00%', '绝不能把 7 天 × 0.5% 显示成 350%（旧实现的错法）');
  notHas(t, 'NaN', '不能出现 NaN%（字段读取失败的症状）');
  notHas(t, 'undefined', '不能出现 undefined');
  has(t, '7 天', '展示了该错误出现的天数');
  has(t, '2.00%', '平均低但有尖峰时，额外展示单日最高 0.02 → 2.00%');
  // healthCell 只渲染 errors[0]（界面上一次只强调"最该先看的那一条"）。
  // 后端已按日均倒序，所以排在最前面的必须是占比最高的那个。
  // 断言就钉这个契约：**只有第一条出现、且是占比最高的那条**。
  has(t, '错误 SUSPECTED_SPAM', '展示的是排序后的第一条错误（占比最高的 SUSPECTED_SPAM）');
  notHas(t, 'RATE_LIMIT_EXCEEDED',
         '只渲染 errors[0]，不把次要错误也塞进同一格（避免信息过载）');

  console.log('\n== B2) 前端忠实渲染 errors[0]（排序责任在后端）==');
  // 前端不做排序、只取第一条。这条断言存在的意义是**钉住职责边界**：
  // 如果哪天有人把排序挪到前端，或前端开始自作主张挑一条，
  // 这里的"倒序输入 → 渲染出倒序后的第一条"就会失配，从而暴露出来。
  // （后端排序本身由 test-postmaster.php 的 §5 直接验证。）
  const flipped = JSON.parse(JSON.stringify(sample));
  flipped.data.domains[0].health.errors = [
    { class: 'TEMPORARY_ERROR', type: 'RATE_LIMIT_EXCEEDED', ratio: 0.001, days: 2, max: 0.001 },
    { class: 'PERMANENT_ERROR', type: 'SUSPECTED_SPAM', ratio: 0.005, days: 7, max: 0.02 },
  ];
  served = flipped;
  $refresh.trigger('click');
  await new Promise((r) => window.setTimeout(r, 250));
  const t3 = text();
  has(t3, '错误 RATE_LIMIT_EXCEEDED', '输入顺序变了，渲染出的第一条也跟着变（前端不擅自重排）');
  has(t3, '0.10%', 'RATE_LIMIT 的日均 0.001 → 0.10%');
} else {
  // 渲染入口没跑起来 —— 这是测试自身的问题，必须显式报出来，不能静默通过
  console.error('  ! 渲染入口未能触发（#pm_refresh 点击没有产生渲染）。');
  console.error('    这通常意味着脚本的加载路径变了；请同步更新本测试的触发方式。');
  fail++;
  failures.push('渲染入口未触发，DOM 行为未被真正验证');
}

console.log('\n== C) 空数据不应渲染成 NaN / undefined ==');
// 直接验证 healthCell 在字段缺失时的容错：用一个 health 为 null 的域重新渲染
const sample2 = JSON.parse(JSON.stringify(sample));
sample2.data.domains[0].health = null;
served = sample2;
$refresh.trigger('click');
await new Promise((r) => window.setTimeout(r, 300));
const t2 = text();
notHas(t2, 'NaN', 'health 为 null 时不出现 NaN');
notHas(t2, 'undefined', 'health 为 null 时不出现 undefined');
has(t2, '暂无数据', 'health 为 null 时明确显示「暂无数据」而不是空白');
notHas(t2, '投诉', 'health 为 null 时不渲染出「投诉」这一项（避免半截内容）');

console.log('\n== D) 空 tbody 兜底（footer.js 会删空 tbody）==');
// 先模拟 mailcow footer.js 的全局行为：删掉所有空 tbody。
// 这里 tbody 里还有内容，所以不会被删 —— 但那正是要证明的：
// **只要模板里的占位行还在，容器就保得住**。
window.document.querySelectorAll('tbody').forEach((tb) => {
  if (tb.children.length < 1) { tb.remove(); }
});
// 再模拟另一种更糟的情况：容器被外力直接摘掉（占位行被人删了、页面结构被改）
const gone = window.document.getElementById('pm_domains_body');
gone.remove();
// 重建一张只有 table、没有 tbody 的表，验证 body() 的兜底逻辑
const tbl = window.document.getElementById('pm_domains_table');
ok(tbl !== null, 'table 容器还在（事件委托挂在这里）');

served = sample;   // 喂回有数据的样例
$refresh.trigger('click');
await new Promise((r) => window.setTimeout(r, 300));
const tbNow = window.document.getElementById('pm_domains_body');
ok(tbNow !== null, 'tbody 被移除后，脚本能重新建出 #pm_domains_body');
if (tbNow) {
  ok(tbNow.querySelectorAll('tr').length >= 1, '重建后的 tbody 里确实有内容（不是又一个空壳）');
  notHas(tbNow.textContent, 'NaN', '重建后的内容里没有 NaN');
}

console.log('\n== E) Bootstrap 插件缺席时提示不能崩（jsdom 天然没有 .alert()）==');
// #pm_refresh 的 load() 失败路径会走 notice()。这里让它失败一次，
// 然后等过 6 秒的收起定时器 —— 若 notice() 硬调用 $.fn.alert()，
// 定时器里会抛未捕获异常。用一个临时 process 监听器捕获它。
const uncaught = [];
const onUncaught = (e) => { uncaught.push(e); };
process.on('uncaughtException', onUncaught);
ok(typeof $.fn.alert !== 'function', 'jsdom 环境里确实没有 Bootstrap 的 $.fn.alert（降级路径会被走到）');

served = null;              // 让 getJSON 桩 reject → 走 .fail() → notice(..., true)
$refresh.trigger('click');
await new Promise((r) => window.setTimeout(r, 200));
ok($('#pm_notice').find('.alert').length >= 1, '失败时确实弹出了提示（说明走的是 notice()）');
// 等过 6000ms 的收起定时器（这里用 6.6s 保证真的越过它）
await new Promise((r) => window.setTimeout(r, 6600));
process.removeListener('uncaughtException', onUncaught);
eq(0, uncaught.length,
   '提示自动收起时不抛未捕获异常（Bootstrap 缺失时降级为直接移除）');
ok($('#pm_notice').find('.alert').length === 0, '提示到点后确实被收起（没有永久残留）');

console.log('\n' + '-'.repeat(50));console.log(`通过: ${pass}    失败: ${fail}`);
if (fail > 0) {
  for (const m of failures) { console.log('  - ' + m); console.log('::error::' + m); }
  process.exit(1);
}
console.log('全部通过');
process.exit(0);
