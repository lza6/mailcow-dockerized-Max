#!/usr/bin/env node
/**
 * 语言包完整性校验
 *
 * 检查 data/web/lang/lang.<locale>.json 是否与母本 lang.en-gb.json 对齐。
 * 缺失的 key 会导致界面回退显示英文（不会报错），所以必须拦住。
 *
 * 退出码：0 = 通过，1 = 有缺失
 */
'use strict';

const fs = require('fs');
const path = require('path');

const LANG_DIR = path.join(__dirname, '..', '..', 'data', 'web', 'lang');
const MASTER = 'lang.en-gb.json';
const TARGETS = ['lang.zh-cn.json', 'lang.zh-tw.json'];

function load(file) {
  const p = path.join(LANG_DIR, file);
  if (!fs.existsSync(p)) {
    console.error(`::error::语言包不存在: ${file}`);
    process.exit(1);
  }
  try {
    return JSON.parse(fs.readFileSync(p, 'utf8'));
  } catch (e) {
    console.error(`::error file=${p}::JSON 解析失败: ${e.message}`);
    process.exit(1);
  }
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

const master = flatten(load(MASTER));
const masterKeys = Object.keys(master);
let failed = false;

console.log(`母本 ${MASTER}: ${masterKeys.length} 个 key\n`);

for (const file of TARGETS) {
  const target = flatten(load(file));
  const missing = masterKeys.filter((k) => !(k in target));
  const extra = Object.keys(target).filter((k) => !(k in master));

  console.log(`--- ${file} ---`);
  console.log(`  key 总数        : ${Object.keys(target).length} / ${masterKeys.length}`);

  if (missing.length) {
    console.log(`  缺失            : ${missing.length}`);
    // 逐个输出为 GitHub 注解，便于在 PR 里直接定位
    for (const k of missing.slice(0, 50)) {
      console.log(`::error file=data/web/lang/${file}::缺失 key: ${k}`);
    }
    if (missing.length > 50) {
      console.log(`  ... 另有 ${missing.length - 50} 个未列出`);
    }
    failed = true;
  } else {
    console.log(`  缺失            : 0`);
  }

  if (extra.length) {
    // 多余 key 不阻断（可能是上游删除但翻译未同步），仅提示
    console.log(`  多余（不阻断）  : ${extra.length}`);
  }
  console.log('');
}

if (failed) {
  console.error('语言包校验失败：存在缺失的 key');
  process.exit(1);
}
console.log('语言包校验通过');
