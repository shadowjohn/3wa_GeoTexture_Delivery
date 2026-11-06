import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

// 在無 SDK/網路環境執行真實控制器，驗證 UI 範圍與提交範圍一致。
const elements = new Map();
const document = {
  getElementById(id) {
    if (!elements.has(id)) elements.set(id, {
      id, disabled: false, hidden: false, addEventListener() {}, setAttribute() {},
      removeAttribute(name) { delete this[name]; },
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
assert.equal(requests[0].url.searchParams.get('source_key'), 'new-taipei-8418');
assert.equal(requests[1].body.source_key, 'new-taipei-8418');
console.log('PASS frontend: selected recipe and source propagated to GET and POST');

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

const emptyCollection = {type: 'FeatureCollection', features: [], meta: {truncated: false}};
const metaData = {source_key: 'taichung-8', source: {ready: true}, csrf_token: 'csrf-test', zoom: 15,
  center: {longitude: 120.648087, latitude: 24.1191754}, sources: {
    'new-taipei-8418': {name: '新北市', center: {longitude: 121.4, latitude: 25.1}},
    'taichung-8': {name: '臺中市', center: {longitude: 120.648087, latitude: 24.1191754}},
  }};
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const mapStub = `map = {threeD:true,vertices:null,scenes:[],buildings:null,
  native:{getZoom:()=>17,getCenter:()=>({lng:121,lat:25})}, bounds:()=>[120,24,122,26],
  setScenes(scenes){this.scenes=scenes;}, setBuildings(data){this.buildings=data;},
  setSelection(p){this.displayed=p;}, home(center){this.center=center;}, locate(){},
  set3D(value){this.threeD=value;}, setChunksEnabled() {}};`;
let releasePreview;
const previewRequests = [];
const previewFetch = async (url, options) => {
  previewRequests.push({url, options});
  return {status:200,ok:true,json:()=>new Promise(resolve=>{releasePreview=resolve;})};
};
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','tick','release','metaData',source+`
meta=metaData; mapReady=true; $('preview-chunks').checked=true;
${mapStub}
const previous=requestPreview(map.bounds(),viewVersion); await tick();
await changeRecipe('procedural-pastel-v1');
release({data:{scenes:[{id:'old',status:'completed',members:['old'],model:{asset_id:'scene-old'}}]}});
await previous;
assert.deepEqual(map.scenes,[],'A late preview response cannot restore the previous recipe');
assert.equal(previewScenes.length,0);
previewScenes=[{id:'queued',status:'queued'}];
const pending=pollPreview(); await tick();
await pollPreview();
document.hidden=true; await pollPreview(); document.hidden=false;
release({data:{id:'queued',status:'completed',members:['current'],model:{asset_id:'scene-current'}}});
await pending;
assert.equal(map.scenes[0].status,'completed','Pending preview scenes are polled to completion');
previewScenes=[{id:'late-2d',status:'queued'}];
const late=pollPreview(); await tick(); changeView(false);
release({data:{id:'late-2d',status:'completed'}}); await late;
assert.deepEqual(map.scenes,[],'Switching to 2D rejects a late chunk status');
`)(assert,document,previewFetch,{href:'http://localhost/index.php'},()=>{},()=>{},tick,value=>releasePreview(value),metaData);
assert.equal(previewRequests.length,3,'Polling is paused while hidden and never overlaps');
assert.equal(previewRequests[0].options.headers['X-CSRF-Token'],'csrf-test');
console.log('PASS frontend: preview cancellation / polling / hidden pause / shared view guards / CSRF');

let releaseView;
const sourceFetch = async url => ({status:200,ok:true,json:()=>url.searchParams.get('action') === 'meta'
  ? Promise.resolve({data:structuredClone(metaData)}) : new Promise(resolve=>{releaseView=resolve;})});
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','tick','release','metaData','polygonB','emptyCollection',source+`
meta={...metaData,source_key:'new-taipei-8418'}; mapReady=true; modes.export.polygon=polygonB;
${mapStub}
const pending=loadView(); await tick();
await changeSource('taichung-8');
release({data:{...emptyCollection,features:[{properties:{build_id:'old-city'}}]}}); await pending;
assert.equal(sourceKey,'taichung-8');
assert.deepEqual(map.buildings.features,[],'Old-city buildings must not return after a source switch');
assert.equal(modes.export.polygon,null,'Source switches clear working polygons');
assert.deepEqual(map.center,metaData.center);
assert.equal($('station-control').hidden,false);
assert.equal($('building-detail').hidden,true);
`)(assert,document,sourceFetch,{href:'http://localhost/index.php'},()=>{},()=>{},tick,value=>releaseView(value),metaData,polygonB,emptyCollection);
console.log('PASS frontend: source switch clears polygons / correct home center / stale view rejected');

let releaseExport, releaseStatus;
const sceneRequests = [];
const exportFetch = async (url, options) => {
  const action=url.searchParams.get('action'); sceneRequests.push({action,url,options,body:options.body && JSON.parse(options.body)});
  return {status:200,ok:true,json:()=>action==='create_scene_export' ? new Promise(resolve=>{releaseExport=resolve;})
    : action==='scene_status' ? new Promise(resolve=>{releaseStatus=resolve;})
    : Promise.resolve({data:{id:'first',status:'cancelled',progress:0,total:1}})};
};
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','tick','releaseCreate','releasePoll','metaData','polygonB',source+`
meta=metaData; mapReady=true; mode='export'; modes.export.polygon=structuredClone(polygonB);
modes.export.collection={features:[{properties:{status:'completed'}}],meta:{truncated:false}};
${mapStub}
updateControls();
const creation=$('create-scene-export').onclick(); await tick();
recipeKey='procedural-modern-v1'; sourceKey='taichung-8';
modes.export.polygon.coordinates[0][0][0]=120;
releaseCreate({data:{id:'first',status:'queued',progress:0,total:1}}); await creation;
assert.equal(sceneExport.recipeKey,'procedural-tw-v1');
assert.equal(sceneExport.sourceKey,'new-taipei-8418');
assert.deepEqual(sceneExport.polygon,polygonB,'The initiating polygon is frozen');
assert.match($('scene-export-context').textContent,/新北市/);
const stalePoll=pollSceneExport(); await tick(); await pollSceneExport();
document.hidden=true; await pollSceneExport(); document.hidden=false;
await $('cancel-scene-export').onclick();
releasePoll({data:{id:'first',status:'completed',progress:100,total:1,glb_url:'old.glb',download_url:'old.zip'}}); await stalePoll;
assert.equal(sceneExport.job.status,'cancelled','A late status must not undo cancellation');
assert.equal($('scene-glb-download').hidden,true);
const second=$('create-scene-export').onclick(); await tick();
releaseCreate({data:{id:'second',status:'queued',progress:0,total:1}}); await second;
const ready=pollSceneExport(); await tick();
releasePoll({data:{id:'second',status:'completed',progress:100,total:1,glb_url:'api.php?action=scene_file&id=second&format=glb',download_url:'api.php?action=scene_file&id=second&format=zip'}}); await ready;
assert.equal($('scene-glb-download').hidden,false);
assert.match($('scene-glb-download').href,/scene_file/,'Large GLB downloads use an authenticated link');
assert.equal($('scene-zip-download').hidden,false);
assert.equal($('create-scene-export').disabled,false);
`)(assert,document,exportFetch,{href:'http://localhost/index.php'},()=>{},()=>{},tick,value=>releaseExport(value),value=>releaseStatus(value),metaData,polygonB);
assert.equal(sceneRequests.filter(r=>r.action==='scene_status').length,2,'Export polls do not overlap and pause while hidden');
assert.equal(sceneRequests[0].body.recipe_key,'procedural-tw-v1');
assert.equal(sceneRequests[0].body.source_key,'new-taipei-8418');
assert.equal(sceneRequests[1].url.searchParams.get('source_key'),'new-taipei-8418','Polling retains the export source after a city switch');
assert.equal(sceneRequests[2].body.source_key,'new-taipei-8418','Cancellation retains the initiating source');
console.log('PASS frontend: frozen export context / hidden and overlap guards / late cancellation / authenticated downloads');

const otherCityJob = {id:'other-city',source_key:'taichung-8',recipe_key:'procedural-modern-v1',name:'臺中任務',
  items:[],polygon:polygonA,total:1,area_m2:100,status:'queued',prepared:0,completed:0,failed:0,skipped:0,
  created_at:'2026-11-06T00:00:00Z',updated_at:'2026-11-06T00:00:00Z'};
const crossCityFetch=async url=>({status:200,ok:true,json:async()=>({data:url.searchParams.get('action')==='meta' ? structuredClone(metaData) : otherCityJob})});
await new AsyncFunction('assert','document','fetch','location','setTimeout','clearTimeout','metaData','polygonA','polygonB','qualityNotices',source+`
meta={...metaData,source_key:'new-taipei-8418'}; mapReady=true; modes.export.polygon=polygonB;
${mapStub}
await showJob('other-city');
assert.equal(sourceKey,'taichung-8','Inspecting a job switches to its own source');
assert.equal(recipeKey,'procedural-modern-v1');
assert.equal(activeJob,'other-city');
assert.deepEqual(map.displayed,polygonA);
assert.equal(modes.export.polygon,null);
assert.equal($('download').disabled,true);
showBuilding({build_id:'station',height:20,geometry_correction:{name:'<station>',base_m:6}});
assert.match($('building-detail').innerHTML,/6 公尺示意淨空，非實測/);
assert.ok(!$('building-detail').innerHTML.includes('<station>'),'Station metadata is escaped');
`)(assert,document,crossCityFetch,{href:'http://localhost/index.php'},()=>{},()=>{},metaData,polygonA,polygonB,()=>[]);
console.log('PASS frontend: cross-city job context / station disclosure and escaping');
