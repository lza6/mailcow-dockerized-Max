#!/usr/bin/env node
/**
 * 语言包占位符一致性校验
 *
 * 译文必须保留原文的所有占位符，否则界面会显示残缺文案
 * （例如 "别名地址 %s 的更改已保存" 丢了 %s，用户看到的就是断句）。
 *
 * 检查三类占位符：
 *   %s / %d / %u  —— sprintf 风格
 *   {name}        —— Twig/模板风格
 *   {{ expr }}    —— Twig 表达式
 *
 * 同时校验 HTML 标签数量是否一致（<b>/<br>/<a> 等），
 * 标签丢失会导致界面结构错乱。
 *
 * 退出码：0 = 通过，1 = 有不一致
 */
'use strict';

const fs = require('fs');
const path = require('path');

const LANG_DIR = path.join(__dirname, '..', '..', 'data', 'web', 'lang');
const MASTER = 'lang.en-gb.json';
const TARGETS = ['lang.zh-cn.json', 'lang.zh-tw.json'];

const PLACEHOLDER_RE = /%[sd u]|\{[A-Za-z0-9_]+\}|\{\{[^}]*\}\}/g;
const TAG_RE = /<\/?([a-zA-Z][a-zA-Z0-9]*)/g;

function load(file) {
  const p = path.join(LANG_DIR, file);
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function flatten(obj, prefix = '') {
  const out = {};
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      Object.assign(out, flatten(v, key));
    } else {
      out[key] = v;
    }
  }
  return out;
}

/** 提取排序后的占位符签名，用于比较（顺序无关） */
function sig(text, re) {
  const m = String(text).match(re) || [];
  return m.map((s) => s.replace(/\s+/g, '')).sort().join('|');
}

const master = flatten(load(MASTER));
let failed = false;

for (const file of TARGETS) {
  const target = flatten(load(file));
  let phIssues = 0;
  let tagIssues = 0;

  for (const key of Object.keys(master)) {
    const a = master[key];
    const b = target[key];
    if (typeof a !== 'string' || typeof b !== 'string') continue;

    if (sig(a, PLACEHOLDER_RE) !== sig(b, PLACEHOLDER_RE)) {
      phIssues++;
      if (phIssues <= 20) {
        console.log(`::error file=data/web/lang/${file}::占位符不一致 ${key}`);
        console.log(`    原文: ${JSON.stringify(a.slice(0, 120))}`);
        console.log(`    译文: ${JSON.stringify(b.slice(0, 120))}`);
      }
    }

    const ta = (a.match(TAG_RE) || []).map((s) => s.toLowerCase()).sort().join(',');
    const tb = (b.match(TAG_RE) || []).map((s) => s.toLowerCase()).sort().join(',');
    if (ta !== tb) {
      tagIssues++;
      if (tagIssues <= 20) {
        console.log(`::error file=data/web/lang/${file}::HTML 标签不一致 ${key}`);
        console.log(`    原文标签: ${ta || '(无)'}`);
        console.log(`    译文标签: ${tb || '(无)'}`);
      }
    }
  }

  console.log(`--- ${file} ---`);
  console.log(`  占位符不一致: ${phIssues}`);
  console.log(`  HTML 标签不一致: ${tagIssues}\n`);

  if (phIssues || tagIssues) failed = true;
}

if (failed) {
  console.error('占位符/标签校验失败');
  process.exit(1);
}
console.log('占位符与标签校验通过');
