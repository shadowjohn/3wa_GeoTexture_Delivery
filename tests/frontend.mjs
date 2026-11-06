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
  querySelectorAll() { return elements.has('run-button') ? [elements.get('run-button')] : []; },
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
const runButton=$('run-button'); runButton.disabled=true;
mode = 'export'; mapReady = true;
modes.export.polygon = polygonB;
modes.export.collection = {features:[{properties:{status:'unprocessed'}}],meta:{truncated:false}};
map = {vertices:null,setSelection(p){this.displayed=p},locate(){}};
updateControls();
assert.equal(runButton.disabled,false,'Batch buttons must re-enable when submission finishes');
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

const requests = [];
const captureFetch = async (url, options) => {
  requests.push({url, body: options.body && JSON.parse(options.body)});
  return {status: 200, ok: true, json: async () => ({data: {features: [], meta: {truncated: false}}})};
};
await new AsyncFunction('assert', 'document', 'fetch', 'location', 'setTimeout', source + `
await api('buildings', {params: {bbox: '121,25,122,26'}});
await api('selection', {body: {polygon: {}}});
`)(assert, document, captureFetch, {href: 'http://localhost/index.php'}, () => {});
assert.equal(requests[0].url.searchParams.get('recipe_key'), 'procedural-tw-v1', 'Map query must request selected recipe');
assert.equal(requests[1].body.recipe_key, 'procedural-tw-v1', 'Selection/task/export must use selected recipe');
console.log('PASS frontend: selected recipe propagated to GET and POST');

let resolveJob;
const delayedFetch = async () => ({status:200,ok:true,json:async()=>new Promise(resolve=>{resolveJob=resolve;})});
const delayedScenario = source + `
const pending=showJob('stale');
await new Promise(resolve=>setTimeout(resolve,0));
await changeRecipe('procedural-modern-v1');
release({data:{id:'stale',items:[],name:'old'}});
await pending;
assert.equal(activeJob,null,'A response from the previous recipe must not reopen a job');
assert.equal(recipeKey,'procedural-modern-v1');
`;
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','release',delayedScenario)(
  assert,document,delayedFetch,{href:'http://localhost/index.php'},setTimeout,clearTimeout,value=>resolveJob(value)
);
console.log('PASS frontend: late job response cannot undo style switch');

let progressCalls=0, timerCalls=0;
const progressRow={id:'progress-job',status:'preparing',total:2,recipe_key:'procedural-tw-v1',prepared:1,completed:1,partial:0,failed:0,queued:1,skipped:0,updated_at:'2026-11-06T05:20:00Z'};
const progressFetch=async url=>{
  assert.equal(url.searchParams.get('action'),'progress','輪詢只能要求精簡進度');
  progressCalls++;
  return {status:200,ok:true,json:async()=>({data:{jobs:[{...progressRow}],worker_running:true,checked_at:'2026-11-06T05:20:01Z'}})};
};
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','progressRow','calls',source+`
const nodes=new Map();
const card={querySelector(selector){if(!nodes.has(selector)) nodes.set(selector,{textContent:'',value:0,innerHTML:''});return nodes.get(selector);}};
$('jobs').querySelector=()=>card;
$('jobs').innerHTML='preserve existing cards';
jobsCache.set('progress-job',{...progressRow,completed:0,prepared:0});
map={vertices:[]};
await pollProgress();
assert.equal(card.querySelector('[data-bar="completed"]').value,1);
assert.match(card.querySelector('[data-count="completed"]').textContent,/50%/);
assert.match(card.querySelector('[data-count="remaining"]').textContent,/待處理 1 棟/);
assert.equal($('jobs').innerHTML,'preserve existing cards','輪詢不可重建任務列表');
assert.equal(progressMapDirty,true,'畫 Polygon 時保留待刷新成果');
const before=calls(); document.hidden=true; await pollProgress(); document.hidden=false;
progressBusy=true; await pollProgress(); progressBusy=false;
assert.equal(calls(),before,'隱藏頁面與重疊輪詢不發送請求');
progressRow.status='completed'; progressRow.completed=2; progressRow.prepared=2; progressRow.queued=0;
map.vertices=null; progressMapAt=Date.now();
await pollProgress();
assert.match(card.querySelector('[data-count="completed"]').textContent,/100%/);
assert.equal(card.querySelector('.status').textContent,'已完成');
assert.ok(!card.querySelector('.job-actions').innerHTML.includes('data-run='));
assert.equal(progressMapDirty,true);
progressMapAt=0; await pollProgress();
assert.equal(progressMapDirty,false,'最後一批完成後，仍需刷新被節流延後的地圖');
`)(assert,document,progressFetch,{href:'http://localhost/index.php'},()=>{timerCalls++;},()=>{},progressRow,()=>progressCalls);
assert.equal(timerCalls,1,'更新地圖需節流且不得漏掉最後結果');
console.log('PASS frontend: incremental progress / hidden tab / overlap guard / final map refresh');
