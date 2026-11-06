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
  properties: {build_id: String(i), height: 12, status: 'completed', model: {
    asset_id: prefix + i.toString(16).padStart(31, '0'), url: `api.php?action=model&asset_id=${prefix}${i}`,
    longitude: 121+i*.0001, latitude: 25, surfaces: [{large: 'not sent to map worker'}],
  }},
}))});
function workspace(width = 1000) {
  const map = new MapWorkspace({onError: error => map.errors.push(error)});
  map.errors = []; map.added = []; map.removed = []; map.center = {lng: 121, lat: 25};
  map.e8 = {addItem: model => map.added.push(model), removeItem: model => map.removed.push(model), enable3D() {}, disable3D() {}};
  map.native = {getContainer: () => ({clientWidth: width}), getCenter: () => map.center, easeTo() {},
    getBounds: () => ({getWest: () => 120, getEast: () => 122, getSouth: () => 24, getNorth: () => 26})};
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
assert.equal(map.removed.length, 64, 'Moving releases the old GPU models');
assert.equal(whiteCount(map), 200, 'New nearby models replace white only when ready');
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
