#!/usr/bin/env node
/**
 * 域名总览 —— **真实 DOM 行为测试**（jsdom + jQuery）
 *
 * 为什么必须有这一层：
 *   后端测试只能证明"算出来的字段对不对"（`checked` 是三态之一、队列函数幂等）。
 *   但从"后端返回 'pending'"到"界面上显示「检测中…」而不是「未检测」"之间
 *   还隔着一整层渲染代码，而这一层的错误**不报错、不崩** ——
 *   只会安静地显示错的状态或整块空白。渲染契约测试只能查 id 有没有对上，查不了值。
 *
 * 重点守的：
 *   1. `checked` 三态各自渲染成正确的徽章（'pending' 不许显示成「未检测」）
 *   2. **轮询有硬上限**。这条最关键：轮询若没上限，浏览器会永远发请求，
 *      同时打爆服务端与用户电池。测试断言请求次数**恰好等于**上限。
 *   3. pending → ready 转换后**停止轮询**
 *   4. 数据来源说明里必须出现让用户能自助排查的信息（cron 名 / 周期 / ofelia）
 *
 * 用法：node .github/scripts/test-js-overview.mjs
 *   需要 jsdom 与 jquery（见 .github/scripts/js-harness/package.json）。
 */

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const JS_FILE  = resolve(ROOT, 'data/web/js/site/overview.js');
const TPL_FILE = resolve(ROOT, 'data/web/templates/overview.twig');

let pass = 0;
let fail = 0;
const failures = [];

function ok(cond, msg) {
  if (cond) { pass++; } else { fail++; failures.push(msg); console.error('  ✗ ' + msg); }
}
function eq(exp, act, msg) {
  if (exp === act) { pass++; } else {
    fail++; failures.push(msg);
    console.error('  ✗ ' + msg + '\n      期望: ' + JSON.stringify(exp) + '\n      实际: ' + JSON.stringify(act));
  }
}
function has(hay, needle, msg) {
  if (String(hay).includes(needle)) { pass++; } else {
    fail++; failures.push(msg + ' — 未包含 ' + JSON.stringify(needle));
    console.error('  ✗ ' + msg + '\n      实际: ' + JSON.stringify(String(hay).slice(0, 500)));
  }
}
function notHas(hay, needle, msg) {
  if (!String(hay).includes(needle)) { pass++; } else {
    fail++; failures.push(msg + ' — 不应包含 ' + JSON.stringify(needle));
    console.error('  ✗ ' + msg + '\n      实际: ' + JSON.stringify(String(hay).slice(0, 500)));
  }
}

// ---- 依赖解析：优先 js-harness，退回仓库根 ----
function loadDep(name) {
  const bases = [resolve(HERE, 'js-harness', '_resolve.js'), resolve(ROOT, '_resolve.js')];
  const tried = [];
  for (const base of bases) {
    try { return { mod: createRequire(base)(name), via: base }; }
    catch (e) { tried.push(base + ' → ' + (e.code || e.message)); }
  }
  const err = new Error('找不到 ' + name + '（尝试过：' + tried.join('; ') + '）');
  err.code = 'MODULE_NOT_FOUND';
  throw err;
}

let JSDOM;
try { ({ JSDOM } = loadDep('jsdom').mod); }
catch (e) {
  console.error('[skip] 缺少 jsdom，无法运行 DOM 行为测试。');
  console.error('       安装：npm ci --prefix .github/scripts/js-harness');
  console.error('       原因：' + e.message);
  process.exit(2);
}

if (!existsSync(JS_FILE)) { console.error('缺 ' + JS_FILE); process.exit(1); }
const jsSrc = readFileSync(JS_FILE, 'utf8');

const tpl = existsSync(TPL_FILE) ? readFileSync(TPL_FILE, 'utf8') : '';
const ids = [...new Set([...tpl.matchAll(/id="([A-Za-z0-9_]+)"/g)].map((m) => m[1]))];

function buildDom() {
  const body = ids.map((id) => `<div id="${id}"></div>`).join('\n');
  return new JSDOM(`<!doctype html><html><body>
${body}
<table id="ov_tbl_domains"><tbody id="ov_domains_body"></tbody></table>
</body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true });
}

const dom = buildDom();
const { window } = dom;
global.window = window;
global.document = window.document;

let jq;
try { jq = loadDep('jquery').mod; }
catch (e) { console.error('[skip] 缺少 jquery：' + e.message); process.exit(2); }
const $$ = (typeof jq === 'function' && jq.fn) ? jq : jq(window);
window.jQuery = window.$ = $$;

// ---- 可控定时器：把 setTimeout 换成"只记录、不自动执行" ----
// 轮询总长 60 秒，用真实定时器测要等一分钟且不稳定。
// 劫持后可以同步地把"时间推进"若干轮，从而**精确数出请求次数**。
//
// ⚠️ 劫持必须发生在**脚本 eval 且 jQuery ready 跑完之后**。
//    jQuery 的 `.ready()` 内部就用 `window.setTimeout` 作为就绪兜底策略之一；
//    提前劫持会让 ready 回调永远不执行 → 所有事件处理器都没绑上 →
//    触发 click 毫无反应、界面全空。这个症状极具迷惑性，
//    看起来像"被测脚本坏了"，实际是测试自己把宿主环境改坏了。
const timers = [];
let timersHijacked = false;
function hijackTimers() {
  if (timersHijacked) { return; }
  timersHijacked = true;
  window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  window.clearTimeout = (id) => { if (id) { timers[id - 1] = null; } };
}

// ⚠️ drainage 必须是**异步**的：jQuery 的 Deferred（$.getJSON().done()）
//    回调走的是微/宏任务，同步地连调 timer 回调时 .done 还没跑，
//    于是 apply() 没执行、界面始终空的 —— 看起来像"脚本没工作"，
//    实际是测试自己的时序错了。每推进一轮定时器都要先让出一轮事件循环。
const tick = () => new Promise((r) => setTimeout(r, 25));   // Node 全局，非被劫持的 window

async function drainTimers(maxRounds = 30) {
  for (let n = 0; n < maxRounds; n++) {
    await tick();
    const t = timers.find(Boolean);
    if (!t) { break; }
    timers[timers.indexOf(t)] = null;
    try { t.fn(); } catch (e) { /* 单轮异常不该中断整个测试 */ }
  }
  await tick();
}
// 触发一次刷新并等它把结果渲染完（不能立刻断言：渲染在 deferred 里）
async function refresh() {
  jsonCalls = 0;
  timers.length = 0;
  $('#ov_refresh').trigger('click');
  await drainTimers();
}

// ---- 供测试注入的响应 ----
let served = null;
let jsonCalls = 0;
$$.getJSON = function () {
  jsonCalls++;
  const d = $$.Deferred();
  if (served) { d.resolve(typeof served === 'function' ? served() : served); } else { d.reject(); }
  return d.promise();
};

window.eval('window.jQuery = window.$;');

let threw = null;
try { window.eval(jsSrc); } catch (e) { threw = e; }
eq(null, threw ? String(threw.message || threw) : null, '脚本能在真实 DOM 上执行而不抛异常');

// 注意：这里必须用 Node 全局的 setTimeout 让出一轮事件循环
// （jQuery ready 是异步的；此刻 window.setTimeout 还没被劫持）。
await new Promise((r) => setTimeout(r, 200));

// ready 已跑完、事件处理器已绑上 —— 现在才安全地接管定时器
hijackTimers();

const $ = $$;
const bodyText = () => {
  const el = window.document.getElementById('ov_domains_body');
  return el ? el.textContent : '';
};
const srcText = () => $(window.document.getElementById('ov_data_sources')).text();

// ---- 造一条域数据 ----
function domainRow(checked, extra = {}) {
  return Object.assign({
    domain: 'example.com', active: 1, created: '2026-01-01',
    relay: '', relay_acct: null, mailboxes: 1, messages: 5,
    age_days: 30, checked: checked, checked_at: '2026-10-14 10:00:00',
    mx: ['mail.example.com'], mx_ok: true, spf_ok: true, dkim_ok: true, dmarc_ok: true,
    bad: 0, warn: 0, warmup: null,
  }, extra);
}
function payload(rows, hcOverride) {
  return {
    ok: true, csrf: 'tok',
    // 服务器时区必须是响应的一部分 —— 页面上所有时刻都按它显示，
    // 不标注会让跨时区的操作者误判"到点了为什么没发"。
    server_tz: 'EDT',
    stats: { domains: rows.length, mailboxes: 1, abnormal: 0, today_sent: 0, messages: 5, today_new: 0 },
    domains: rows, mailboxes: [], relay: null, relayhosts: [], warmup: null, audit: [],
    healthcheck: Object.assign({
      enabled: true, last_full_at: '2026-10-14 09:55:00', last_full_age: 300,
      pending: 0, interval: '每 30 分钟', job: 'phpfpm_healthcheck',
    }, hcOverride || {}),
  };
}

// 养号区：用来验证时区标注。
// 字段名必须与 renderWarmup() 实际读的一致（是 logs 不是 log）——
// 写错会抛 TypeError，看起来像"脚本坏了"，其实是测试夹具不对。
function warmupPayload() {
  const p = payload([domainRow('ready')]);
  p.warmup = {
    window: '8:00 - 20:00', max_per_run: 3, global_cap: 30,
    sent_today: 0, sent_total: 10, fail_total: 0,
    secretbox: true, crypto: false, clear: true,
    stats: { inbox: 5, spam: 0, decided: 5, inbox_rate: 100, spam_rate: 0,
             today_inbox: 0, today_spam: 0, replied: 0, rescued: 0 },
    plans: [{
      id: 1, domain: 'example.com', sender: 'a@example.com', status: 'active',
      day: 3, total_days: 30, days_left: 27, day_quota: 3, sent_today: 0, ran_today: 0,
      sent_all: 3, fail_all: 0, start_count: 2, step: 1, max_count: 5,
      next_at: '2026-10-14 16:18', next_state: 'pending', last_skip_reason: '',
    }],
    recipients: [], messages: [], logs: [],
  };
  return p;
}

console.log('\n== A) 脚本挂载 ==');
ok(ids.length >= 10, '从模板抽到足够多的 id（' + ids.length + ' 个）');
ok(!threw, '脚本已挂载（无异常）');

console.log('\n== B) checked = ready → 正常徽章 ==');
served = payload([domainRow('ready')]);
await refresh();
const tReady = bodyText();
has(tReady, '指向本机', 'ready + mx_ok → 显示「指向本机」');
has(tReady, 'SPF', 'ready → 显示认证徽章');
notHas(tReady, '检测中', 'ready 时**不**显示「检测中」');
notHas(tReady, '未检测', 'ready 时**不**显示「未检测」');

console.log('\n== C) checked = pending → 「检测中…」而不是「未检测」==');
served = payload([domainRow('pending', { mx_ok: null, spf_ok: null, dkim_ok: null, dmarc_ok: null })]);
await refresh();
const tPending = bodyText();
has(tPending, '检测中', 'pending → 显示「检测中…」');
has(tPending, '排队中', 'pending → 域名单元格显示「排队中…」');
notHas(tPending, '未检测', 'pending **绝不**显示「未检测」（这正是本次要消灭的空窗）');
notHas(tPending, '指向别处', 'pending 时不得报"指向别处"这个**假故障**');
has(tPending, '30 分钟', '给出可预期的等待时长（后台任务周期）');

console.log('\n== D) checked = none → 「未检测」+ 排查指引 ==');
served = payload([domainRow('none', { mx_ok: null, spf_ok: null, dkim_ok: null, dmarc_ok: null })]);
await refresh();
const tNone = bodyText();
has(tNone, '未检测', 'none → 显示「未检测」');
has(tNone, 'ofelia', 'none → 给出该去查什么（ofelia 容器）');
notHas(tNone, '检测中', 'none ≠ pending，不显示「检测中」');

console.log('\n== E) 轮询硬上限（最关键的一条）==');
// 让后台永远返回 pending，把定时器跑到底，数请求次数。
served = payload([domainRow('pending', { mx_ok: null, spf_ok: null, dkim_ok: null, dmarc_ok: null })]);
await refresh();            // refresh 内部已把定时器推到没有为止
ok(jsonCalls >= 2 && jsonCalls <= 7,
   '轮询请求次数受限（实际 ' + jsonCalls + ' 次，期望 2~7：1 次首取 + ≤6 次轮询）');
ok(jsonCalls < 20, '绝不出现"无限轮询"（实际 ' + jsonCalls + ' 次）');

console.log('\n== F) pending → ready 后停止轮询 ==');
let round = 0;
served = () => {
  round++;
  return round === 1
    ? payload([domainRow('pending', { mx_ok: null, spf_ok: null, dkim_ok: null, dmarc_ok: null })])
    : payload([domainRow('ready')]);
};
await refresh();
has(bodyText(), '指向本机', '第二次返回 ready → 界面切到真实徽章');
const callsAfterReady = jsonCalls;
ok(callsAfterReady <= 4, '切到 ready 后不再继续轮询（共 ' + callsAfterReady + ' 次请求）');

console.log('\n== G) 数据来源说明必须能自助排查 ==');
served = payload([domainRow('ready')], {});
await refresh();
const src = srcText();
has(src, 'phpfpm_healthcheck', '给出后台任务名（用户才能去查它）');
has(src, '每 30 分钟', '给出刷新周期');
has(src, '上次检测', '给出「上次检测」时间');
has(src, 'ofelia', '给出长期未检测时的排查入口');
has(src, '首屏绝不发起 DNS', '说明为什么首屏不查 DNS（性能取舍要讲清楚）');

console.log('\n== H) 体检任务停了要变红提示 ==');
served = payload([domainRow('ready')], { last_full_age: 7200, last_full_at: '2026-10-14 07:00:00' });
await refresh();
has(srcText(), '超过 2 个周期', '超过 2 个周期 → 明确提示任务可能已停');

served = payload([domainRow('ready')], { enabled: false });
await refresh();
has(srcText(), 'redis', 'redis 不可用 → 明确报告连接异常（而不是假装正常）');

console.log('\n== I) 空数据不崩 ==');
served = payload([]);
await refresh();
notHas(bodyText(), 'undefined', '空域列表时不出现 undefined');
notHas(bodyText(), 'NaN', '空域列表时不出现 NaN');
has(bodyText(), '暂无域名', '空域列表给出明确文案');

console.log('\n== J) 时刻必须标注服务器时区（跨时区误判的根因）==');
// 真实教训：服务器跑 America/New_York，操作者在北京。页面显示「09:07 待发送」
// 会被读成北京时间，于是"到点了为什么没发"必然发生。标出时区即可消除歧义。
//
// ⚠️ 断言要读**具体元素**（#ov_wu_rule / #ov_wu_plan_body）。
//    构建的测试 DOM 是扁平的兄弟节点，#ov_warmup_card 里没有子元素 ——
//    读它会得到空串，看起来像功能没做。
served = warmupPayload();
await refresh();
const wuRule = $(window.document.getElementById('ov_wu_rule')).text();
const wuPlan = $(window.document.getElementById('ov_wu_plan_body')).text();
has(wuRule, 'EDT', '养号规则行显示服务器时区缩写');
has(wuRule, '服务器时区', '明确说明"以下时刻均为服务器时区"');
has(wuRule, '8:00 - 20:00', '仍显示发送窗口');
has(wuPlan, '16:18', '计划行仍显示下一封时刻');
has(wuPlan, 'EDT', '下一封时刻旁也标了时区（就近可读）');
notHas(wuRule + wuPlan, 'undefined', '时区标注不引入 undefined');

console.log('\n' + '-'.repeat(50));
console.log(`通过: ${pass}    失败: ${fail}`);
if (fail > 0) {
  for (const m of failures) { console.log('  - ' + m); console.log('::error::' + m); }
  process.exit(1);
}
console.log('全部通过');
process.exit(0);
