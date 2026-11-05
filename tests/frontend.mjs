import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

// 在無 SDK/網路環境執行真實控制器，驗證 UI 範圍與提交範圍一致。
const elements = new Map();
const document = {
  getElementById(id) {
    if (!elements.has(id)) elements.set(id, {
      id, disabled: false, hidden: false, addEventListener() {}, setAttribute() {},
      classList: {toggle() {}},
    });
    return elements.get(id);
  },
  addEventListener() {},
  createElement() { return {click() {}}; },
};
const polygonA = {type: 'Polygon', coordinates: [[[121.4, 25.1], [121.401, 25.1], [121.401, 25.101], [121.4, 25.1]]]};
const polygonB = {type: 'Polygon', coordinates: [[[121.41, 25.11], [121.411, 25.11], [121.411, 25.111], [121.41, 25.11]]]};
let exported;
const fetch = async (url, options) => {
  const action = url.searchParams.get('action');
  if (action === 'export') {
    exported = JSON.parse(options.body).polygon;
    return {status: 200, ok: true, blob: async () => new Blob(['test zip boundary'])};
  }
  assert.equal(action, 'job');
  return {status: 200, ok: true, json: async () => ({data: {
    id: 'A', name: '任務 A', polygon: polygonA, area_m2: 5000, total: 1,
    prepared: 0, completed: 0, failed: 0, skipped: 0, items: [], status: 'queued',
    created_at: '2026-11-05T12:00:00Z', updated_at: '2026-11-05T12:00:00Z',
  }})};
};
const source = readFileSync(new URL('../public/assets/app.mjs', import.meta.url), 'utf8')
  .replace(/^import[^\n]*\n/gm, '').replace(/start\(\);\s*$/, '');
const scenario = `
mode = 'export'; mapReady = true;
modes.export.polygon = polygonB;
modes.export.collection = {features:[{properties:{status:'unprocessed'}}],meta:{truncated:false}};
map = {vertices:null,setSelection(p){this.displayed=p},locate(){}};
updateControls();
assert.equal($('download').disabled,false);
map.vertices=[]; updateControls();
assert.equal($('download').disabled,true,'繪製新範圍期間不可提交舊範圍');
map.vertices=null; updateControls();
await showJob('A');
assert.deepEqual(map.displayed,polygonA);
assert.deepEqual(modes.export.polygon,polygonB);
assert.equal($('download').disabled,true,'任務檢視 A 不可仍匯出工作範圍 B');
assert.equal($('create-job').disabled,true,'檢視期間也不可建立舊範圍任務');
assert.equal($('resume-selection').hidden,false);
$('resume-selection').onclick();
assert.deepEqual(map.displayed,polygonB);
assert.equal($('download').disabled,false);
await $('download').onclick();
`;
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
await new AsyncFunction('assert', 'polygonA', 'polygonB', 'document', 'fetch', 'location', 'setTimeout', source + scenario)(
  assert, polygonA, polygonB, document, fetch, {href: 'http://localhost/index.php'}, () => {},
);
assert.deepEqual(exported, polygonB, '回復後的匯出需與顯示範圍相同');
console.log('PASS frontend: drawing / inspect / resume / export bounds');
