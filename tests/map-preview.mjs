import assert from 'node:assert/strict';
import {MapWorkspace} from '../public/assets/map.mjs';

// 使用可控制完成順序的 SDK 替身，驗證下載上限與白模替換時機。
const timers = new Map();
let nextTimer = 0;
globalThis.setTimeout = fn => { timers.set(++nextTimer, fn); return nextTimer; };
globalThis.clearTimeout = id => timers.delete(id);
globalThis.location = {href: 'https://example.test/index.php'};
globalThis.Easymap = {registerModelAsset() {}};
globalThis.dgModel = class {
  constructor(options) {
    Object.assign(this, options);
    this.ready = new Promise((resolve, reject) => { this.resolve = resolve; this.reject = reject; });
  }
};
const flush = async () => {
  for (let i = 0; i < 5; i++) await Promise.resolve();
  const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(fn => fn());
};
const collection = (count = 200, prefix = 'a') => ({type: 'FeatureCollection', features: Array.from({length: count}, (_, i) => ({
  type: 'Feature', id: String(i),
  geometry: {type: 'Polygon', coordinates: [[[121+i*.0001,25],[121+i*.0001+.00005,25],[121+i*.0001,25.00005],[121+i*.0001,25]]]},
  properties: {source_key: 'fixture', source_hash: 'hash', build_id: String(i), height: 12, status: 'completed', model: {
    asset_id: prefix + i.toString(16).padStart(31, '0'), url: `api.php?action=model&asset_id=${prefix}${i}`,
    longitude: 121+i*.0001, latitude: 25, resident_bytes: 2*1024*1024, style_key: 'procedural-tw-v1', surfaces: [{large: 'not sent to map worker'}],
  }},
}))});
function workspace(width = 1000) {
  const map = new MapWorkspace({onError: error => map.errors.push(error)});
  map.errors = []; map.added = []; map.removed = []; map.center = {lng: 121, lat: 25};
  map.e8 = {addItem: model => map.added.push(model), removeItem: model => map.removed.push(model), enable3D() {}, disable3D() {}};
  map.bounds = [120,24,122,26];
  map.native = {getContainer: () => ({clientWidth: width}), getCenter: () => map.center, easeTo() {},
    getBounds: () => ({getWest: () => map.bounds[0], getEast: () => map.bounds[2], getSouth: () => map.bounds[1], getNorth: () => map.bounds[3]})};
  map.putLayer = (id, data) => {
    const layer = {data, setVisible() { return this; }}; map.layers.set(id, layer); return layer;
  };
  map.threeD = true;
  return map;
}
const whiteCount = map => map.layers.get('buildings-completed-3d').data.features.length;
async function finish(map) {
  while (map.pendingModels.size) {
    for (const id of map.pendingModels) map.models.get(id).resolve();
    await flush();
  }
  await flush();
}

const map = workspace(); map.setBuildings(collection());
const initialFill = map.layers.get('buildings-completed-fill');
const unchangedWhite = map.layers.get('buildings-unprocessed-3d');
assert.equal(map.pendingModels.size, 4, 'Desktop may download at most four models at once');
assert.equal(whiteCount(map), 200, 'Every footprint stays visible while texture downloads');
assert.equal(map.added[0].longitude, 121, 'Nearest building loads first');
assert.ok(!('model' in map.layers.get('buildings-completed-fill').data.features[0].properties));
await finish(map);
assert.equal(map.layers.get('buildings-completed-fill'), initialFill, 'Model readiness must not rebuild 2D GeoJSON');
assert.equal(map.layers.get('buildings-unprocessed-3d'), unchangedWhite, 'Unchanged building groups must not rebuild');
assert.equal(map.models.size, 64, 'Resident textured model budget');
assert.equal(whiteCount(map), 136, 'Buildings outside texture budget still render as white models');
map.center = {lng: 121.0199, lat: 25}; map.syncModels(); map.renderBuildings();
assert.equal(map.removed.length, 32, 'Moving keeps at most 32 extra ready singles');
assert.equal(whiteCount(map), 168, 'Retained ready models stay visible while new nearby models load');
await finish(map);
map.set3D(false);
assert.equal(map.models.size, 0, '2D releases resident models');
assert.equal(whiteCount(map), 200);

const stale = workspace(); stale.setBuildings(collection());
const old = [...stale.models.values()];
stale.setBuildings(collection(200, 'b'));
assert.equal(stale.added.length, 4, 'Changing style waits for in-flight transfers instead of flooding new requests');
old.forEach(model => model.resolve()); await flush();
assert.ok(old.every(model => stale.removed.includes(model)), 'Late models from the previous style must be removed');
assert.equal(stale.pendingModels.size, 4);
assert.equal(stale.readyModels.size, 0);
stale.set3D(false); await finish(stale);
assert.equal(stale.models.size, 0, 'Late completions must not reappear after switching to 2D');

const mobile = workspace(390); mobile.setBuildings(collection());
assert.equal(mobile.pendingModels.size, 2);
const failed = mobile.models.values().next().value;
failed.reject(new Error('test network failure')); await flush(); await finish(mobile);
assert.equal(mobile.wantedModels.size, 24);
assert.equal(mobile.models.size, 23);
assert.equal(whiteCount(mobile), 177, 'Failed model retains the original white building');
assert.equal(mobile.errors.length, 1);
console.log('PASS map preview: nearest first / bounded transfers and GPU models / white fallback / late results / mobile');

const scene = (data, id = 'chunk-a', members = data.features.map(f => f.properties.model.asset_id)) => ({
  id, status: 'completed', members, recipe_key: 'procedural-tw-v1',
  member_versions: data.features.filter(f => members.includes(f.properties.model.asset_id)).map(f => ({
    source_key: f.properties.source_key, build_id: f.properties.build_id, source_hash: f.properties.source_hash,
    recipe_key: 'procedural-tw-v1', asset_id: f.properties.model.asset_id,
    longitude: f.properties.model.longitude, latitude: f.properties.model.latitude,
  })),
  model: {asset_id: `scene-${id}`, url: `api.php?action=scene_file&id=${id}`, longitude: 121, latitude: 25, resident_bytes: 4*1024*1024},
});
const chunked = workspace();
let counts;
chunked.onModels = value => { counts = value; };
const chunkData = collection(8);
chunked.setBuildings(chunkData); await finish(chunked);
chunked.setScenes([scene(chunkData)]);
assert.equal(chunked.models.size, 9, 'Original models stay until the chunk is ready');
assert.equal(chunked.coveredAssets.size, 0);
assert.equal(counts.loaded, 8, 'Progress counts buildings, not the pending chunk');
const chunkModel = chunked.models.get('scene-chunk-a');
chunkModel.resolve(); await flush();
assert.equal(chunked.models.size, 1, 'A ready chunk retires every covered single model');
assert.equal(whiteCount(chunked), 0, 'Ready chunk replacement never flashes white buildings');
assert.deepEqual(counts, {loaded: 8, total: 8, available: 8});
assert.equal(chunked.layers.get('buildings-pick').data.features.length, 8, 'Merged buildings retain interactive footprints');
chunked.setBuildings(collection(8, 'c'));
assert.ok(chunked.removed.includes(chunkModel), 'Replacing current asset versions invalidates the entire old chunk');
assert.equal(chunked.coveredAssets.size, 0);
assert.equal(whiteCount(chunked), 8);
await finish(chunked);
chunked.setScenes([scene(chunked.collection, 'chunk-b')]);
const lateChunk = chunked.models.get('scene-chunk-b');
chunked.set3D(false); lateChunk.resolve(); await flush();
assert.equal(chunked.models.size, 0, 'Late chunks must not reappear in 2D');
assert.equal(chunked.coveredAssets.size, 0);

const failedChunk = workspace(); failedChunk.setBuildings(chunkData); await finish(failedChunk);
failedChunk.setScenes([scene(chunkData, 'failed')]);
failedChunk.models.get('scene-failed').reject(new Error('invalid chunk')); await flush();
assert.equal(failedChunk.models.size, 8, 'A failed chunk retains all loaded originals');
assert.equal(failedChunk.coveredAssets.size, 0);
assert.equal(whiteCount(failedChunk), 0);
failedChunk.setChunksEnabled(false);
assert.ok([...failedChunk.wantedModels.keys()].every(id => !id.startsWith('scene-')));

const budgetData = collection(100);
const scenes = Array.from({length: 10}, (_, i) => scene(budgetData, `budget-${i}`, budgetData.features.slice(i * 10, (i + 1) * 10).map(f => f.properties.model.asset_id)));
for (const [width, chunks, singles, transfers] of [[1000, 8, 20, 4], [390, 4, 24, 2]]) {
  const bounded = workspace(width);
  bounded.scenes = scenes; bounded.setBuildings(budgetData);
  assert.equal(bounded.pendingModels.size, transfers, 'Chunks and singles share transfer concurrency');
  await finish(bounded);
  assert.equal([...bounded.models.keys()].filter(id => id.startsWith('scene-')).length, chunks);
  assert.equal(bounded.models.size, chunks + singles, 'Chunk and single GPU budgets remain separate and bounded');
  bounded.setChunksEnabled(false); bounded.setScenes([]); await finish(bounded);
  assert.equal(bounded.models.size, width < 600 ? 36 : 84, 'Disabling chunks restores active singles with bounded existing retention');
}
console.log('PASS chunks: ready replacement / building counts / current asset versions / failure / 2D / shared budgets');

const nearby = workspace(); nearby.bounds=[120.9998,24.9998,121.0002,25.0002];
const one = collection(1); nearby.setBuildings(one); await finish(nearby);
const original = nearby.models.get(one.features[0].properties.model.asset_id);
nearby.bounds=[121.0003,24.9998,121.0007,25.0002]; nearby.center={lng:121.0005,lat:25};
nearby.syncModels();
assert.equal(nearby.models.get(original.assetId.replace('geotexture-','')), original, 'Nearby pan retains the real SDK instance');
assert.equal(nearby.retainedModels.size,1);
nearby.bounds=[120.9998,24.9998,121.0002,25.0002]; nearby.center={lng:121,lat:25}; nearby.setBuildings(one);
assert.equal(nearby.added.length,1,'A -> B -> A does not download/recreate the same model');
nearby.bounds=[121.02,25.02,121.03,25.03]; nearby.syncModels();
assert.equal(nearby.models.size,0,'Leaving the retention ring releases the instance');

const partial = workspace(); partial.setBuildings({...chunkData,features:chunkData.features.slice(0,2)}); await finish(partial);
partial.setScenes([scene(chunkData,'boundary')]); await finish(partial);
assert.equal(partial.coveredAssets.size,8,'Full immutable member snapshot supports a partially visible chunk');
assert.equal(whiteCount(partial),0);
const boundaryInstance=partial.models.get('scene-boundary');
partial.setBuildings({...chunkData,features:chunkData.features.slice(4)});
partial.setScenes([]); await finish(partial);
assert.equal(partial.models.get('scene-boundary'),boundaryInstance,'Valid ready chunk survives query boundary changes');
const changed=structuredClone(partial.collection); changed.features[0].properties.model=null;
partial.setBuildings(changed);
assert.ok(!partial.models.has('scene-boundary'),'Known missing/currently invalid asset invalidates the old chunk');

const overlap=workspace(); overlap.setBuildings(chunkData); overlap.setScenes([scene(chunkData,'old')]); await finish(overlap);
const oldChunk=overlap.models.get('scene-old');
overlap.setScenes([scene(chunkData,'next',chunkData.features.slice(0,4).map(f=>f.properties.model.asset_id))]);
assert.equal(overlap.models.get('scene-old'),oldChunk,'Keep valid old chunk while regrouped replacement loads');
assert.equal(whiteCount(overlap),0);
await finish(overlap);
assert.ok(!overlap.models.has('scene-old'),'Ready replacement retires every overlapping old chunk');
assert.equal(overlap.coveredAssets.size,4);
assert.equal(overlap.models.size,5,'Uncovered members use singles; covered members are not rendered twice');

for(const [width,maxBytes] of [[1000,64*1024*1024],[390,24*1024*1024]]){
  const budget=workspace(width),data=collection(200);
  data.features.forEach(f=>{f.properties.model.resident_bytes=20*1024*1024;});
  budget.setBuildings(data);await finish(budget);budget.center={lng:121.0199,lat:25};budget.syncModels();await finish(budget);
  const bytes=[...budget.retainedModels].reduce((n,id)=>n+budget.modelDescriptors.get(id).resident_bytes,0);
  assert.ok(bytes<=maxBytes,'Extra retention obeys the decoded-memory estimate budget');
  assert.equal(budget.retainedModels.size,Math.floor(maxBytes/(20*1024*1024)));
  budget.setBuildings({type:'FeatureCollection',features:[]});await finish(budget);
  assert.equal(budget.models.size,0,'Empty/source/style reset releases all residency');
  assert.equal(budget.modelDescriptors.size,0,'Reset does not keep unbounded metadata');
}
const unknown=workspace();const unknownData=collection(1);unknownData.features[0].properties.model.resident_bytes=null;
unknown.bounds=[120.9998,24.9998,121.0002,25.0002];unknown.setBuildings(unknownData);await finish(unknown);
unknown.bounds=[121.0003,24.9998,121.0007,25.0002];unknown.syncModels();
assert.equal(unknown.models.size,0,'Unknown decoded cost is not retained outside the active view');

const versions=workspace();const pair=collection(2);const frozen=scene(pair,'frozen');
versions.setBuildings(pair);versions.setScenes([frozen]);await finish(versions);
const revised=structuredClone(pair);revised.features[0].properties.model.asset_id='new-version';revised.features[0].properties.source_hash='new-hash';
versions.setBuildings(revised);await finish(versions);
const latest=versions.models.get('new-version');
versions.setBuildings({...pair,features:pair.features.slice(1)});await finish(versions);
assert.equal(versions.models.get('new-version'),latest,'Known newer offscreen version stays resident');
assert.ok(!versions.models.has('scene-frozen'),'Leaving the viewport cannot resurrect an old immutable scene snapshot');
versions.setScenes([frozen]);await finish(versions);
assert.ok(!versions.models.has('scene-frozen'),'Polling an old frozen snapshot does not overwrite newer observations');
versions.setScenes([scene(revised,'fresh')],true);await finish(versions);
assert.equal(versions.coveredAssets.size,2,'Fresh full-cell response can replace stale scene membership');
versions.setBuildings({type:'FeatureCollection',features:[]});await finish(versions);
assert.equal(versions.knownVersions.size,0,'Context reset clears bounded version observations');

const simultaneous=workspace();simultaneous.setBuildings(chunkData);simultaneous.setScenes([scene(chunkData,'simultaneous')]);
simultaneous.models.get([...simultaneous.pendingModels][0]).resolve();await flush();
assert.ok(simultaneous.pendingModels.has('scene-simultaneous'));
for(const id of simultaneous.pendingModels)simultaneous.models.get(id).resolve();
await flush();
assert.equal(simultaneous.models.size,1,'Singles and covering chunk resolving in the same microtask batch leave no duplicate models');
assert.equal(simultaneous.pendingModels.size,0);
console.log('PASS residency: round trip reuse / retention ring / byte budgets / boundary versions / overlap handoff / reset');
