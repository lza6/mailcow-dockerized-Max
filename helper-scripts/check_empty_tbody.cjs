#!/usr/bin/env node
/**
 * 防止「空 tbody」回归。
 *
 * 背景（2026-10-11 实战踩坑）：
 *   mailcow 的 footer.js 里有一条**全局**规则：
 *       $('tbody').filter(function(){ return $(this).children().length < 1; }).remove();
 *   它在页面解析阶段执行，会把页面上所有**空的 <tbody> 整个删掉**。
 *
 *   任何「tbody 留空、等 JS 填」的模板都会因此永久空白：JS 再 .html() 也
 *   找不到容器。症状极具迷惑性 —— 同一页里 div 形式的统计卡显示正常，
 *   只有表格全空；而服务端 HTML 里元素 ID 是齐全的，光看响应体根本发现不了。
 *   （域名总览页与养号页都因此空白过。）
 *
 * 规则：模板里的每个 <tbody> 必须至少有一个子元素（通常是占位行）。
 *
 * 用法：node helper-scripts/check_empty_tbody.cjs
 * 退出码：0 = 通过，1 = 发现空 tbody
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const TEMPLATE_DIR = path.join(ROOT, 'data', 'web', 'templates');

function walk(dir, out) {
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    const st = fs.statSync(p);
    if (st.isDirectory()) { walk(p, out); }
    else if (name.endsWith('.twig')) { out.push(p); }
  }
  return out;
}

const files = walk(TEMPLATE_DIR, []);
const bad = [];

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const re = /<tbody\b([^>]*)>([\s\S]*?)<\/tbody>/gi;
  let m;
  while ((m = re.exec(src))) {
    const inner = m[2].trim();
    // 去掉 Twig 注释与纯空白后，若仍为空即为违规
    const stripped = inner.replace(/\{#[\s\S]*?#\}/g, '').trim();
    if (stripped === '') {
      const line = src.slice(0, m.index).split('\n').length;
      bad.push({ file: path.relative(ROOT, f), line, tag: ('<tbody' + m[1] + '>').slice(0, 90) });
    }
  }
}

console.log('检查 ' + files.length + ' 个 .twig 模板\n');

if (bad.length === 0) {
  console.log('✓ 未发现空 <tbody>');
  process.exit(0);
}

console.log('✗ 发现 ' + bad.length + ' 处空 <tbody>（会被 mailcow 的 footer.js 删掉，导致表格永久空白）：\n');
for (const b of bad) {
  console.log('  ' + b.file + ':' + b.line + '   ' + b.tag);
}
console.log('\n修法：在 tbody 里放一行占位，例如');
console.log('  <tbody id="xxx_body">');
console.log('    <tr><td colspan="N" class="text-center text-muted py-3">正在加载…</td></tr>');
console.log('  </tbody>');
process.exit(1);
