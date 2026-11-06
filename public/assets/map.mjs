const empty = () => ({type: 'FeatureCollection', features: []});
const feature = geometry => ({type: 'Feature', properties: {}, geometry});
const COLORS = {unprocessed: '#9ca9b0', queued: '#e2a62c', failed: '#d8565e', completed: '#087f86'};

function roofLineChunks(features) {
  const chunks = [];
  let lines = [], positions = 0;
  for (const building of features) {
    const polygons = building.geometry.type === 'Polygon' ? [building.geometry.coordinates] : building.geometry.coordinates;
    const height = Math.max(0, Number(building.properties.height) || 0) + 0.15;
    for (const polygon of polygons) for (const ring of polygon) {
      // E8 每個 line3d 物件最多 100,000 點，依完整環分批並保留內洞。
      if (positions + ring.length > 80000 && lines.length) {
        chunks.push(lines);
        lines = []; positions = 0;
      }
      lines.push(feature({type: 'LineString', coordinates: ring.map(p => [p[0], p[1], height])}));
      positions += ring.length;
    }
  }
  if (lines.length) chunks.push(lines);
  return chunks;
}

export class MapWorkspace {
  constructor({onMove, onBuilding, onDraw, onFinish, onError, onModels = () => {}}) {
    Object.assign(this, {onMove, onBuilding, onDraw, onFinish, onError, onModels});
    this.layers = new Map();
    this.whiteLayerKeys = new Map();
    this.roofLayerIds = new Set();
    this.models = new Map();
    this.readyModels = new Set();
    this.pendingModels = new Set();
    this.failedModels = new Set();
    this.wantedModels = new Map();
    this.assets = new Set();
    this.scenes = [];
    this.chunksEnabled = true;
    this.coveredAssets = new Set();
    this.collection = empty();
    this.vertices = null;
    this.draftCursor = null;
    this.draftFrame = null;
    this.threeD = false;
    this.selection = null;
  }

  async init(center, zoom) {
    if (!globalThis.Easymap) throw new Error('E8 CDN 無法載入，請檢查網路後重新整理。');
    this.e8 = new Easymap('map', {center, zoom, modeControl: false});
    await this.e8.ready;
    // 成果檢視固定白天，避免即時夜光將 GLB 的 PBR 缺面照成深藍。
    this.e8.setEnvironment({time: '2026-06-21T12:00:00+08:00', paused: true});
    // E8 preview.5 尚無繪圖介面，使用公開 Native Context；不取用 SDK 私有狀態。
    const context=this.e8.getNativeContext();
    this.native = context.maplibre.map;
    // 示意風格使用中性補光，讓背光面仍能辨認材質；只管理應用自己的 light。
    const {THREE,scene}=context.three;
    this.previewLight=new THREE.AmbientLight(0xffffff,1.4);
    this.previewLight.name='app-procedural-facade-fill';
    this.previewLight.visible=false;
    scene.add(this.previewLight);
    this.e8.attachEvent('destroy',()=>{
      this.destroyed = true;
      scene.remove(this.previewLight);
      cancelAnimationFrame(this.draftFrame);
      clearTimeout(this.modelRefreshTimer);
      clearTimeout(this.modelPumpTimer);
      this.resizeObserver?.disconnect();
    });
    this.e8.setStatusBarVisible(false);
    // 白模用固定中性光，避免 SDK 的即時夜間藍光讓白牆偏色。
    const lightWhiteModels = () => this.native.setLight({
      anchor: 'viewport', color: '#ffffff', intensity: 0.3, position: [1.5, 210, 35],
    });
    this.native.on('style.load', lightWhiteModels);
    lightWhiteModels();
    // 側欄切換為上下配置時，容器尺寸改變不一定伴隨 window resize。
    this.resizeObserver = new ResizeObserver(() => {
      cancelAnimationFrame(this.resizeFrame);
      this.resizeFrame = requestAnimationFrame(() => {
        this.e8.resize();
        this.native.triggerRepaint();
        this.onMove();
      });
    });
    this.resizeObserver.observe(document.getElementById('map'));
    this.native.on('moveend', () => { this.syncModels(); this.renderBuildings(); this.onMove(); });
    this.native.on('click', event => {
      if (!this.vertices) return;
      const point = [event.lngLat.lng, event.lngLat.lat];
      const previous = this.vertices.at(-1);
      if (!previous || Math.hypot(point[0] - previous[0], point[1] - previous[1]) > 1e-10) {
        this.vertices.push(point);
      }
      this.draftCursor = null;
      this.renderDraft();
      this.onDraw(this.vertices.length);
    });
    this.native.on('mousemove', event => {
      if (!this.vertices) return;
      this.draftCursor = [event.lngLat.lng, event.lngLat.lat];
      if (this.draftFrame === null) this.draftFrame = requestAnimationFrame(() => this.renderDraft());
    });
    this.native.on('mouseout', () => {
      if (!this.vertices) return;
      this.draftCursor = null;
      this.renderDraft();
    });
    this.native.on('dblclick', event => {
      if (!this.vertices) return;
      event.preventDefault();
      if (this.vertices.length >= 3) this.onFinish();
    });
    this.e8.attachEvent('featureclick', event => {
      if (!this.vertices && event.properties?.build_id) {
        const building = this.collection.features.find(f => f.properties.build_id === event.properties.build_id);
        if (building) this.onBuilding(building.properties);
      }
    });
    this.e8.attachEvent('itemclick', event => {
      if (this.vertices || event.kind !== 'model') return;
      const building = this.collection.features.find(f => f.properties.model
        && event.id === `geotexture-${f.properties.model.asset_id}`);
      if (building) this.onBuilding(building.properties);
    });
    this.e8.attachEvent('itemerror', event => {
      if (!event.id?.startsWith('geotexture-')) this.onError(`圖層載入失敗：${event.message}`);
    });
    this.onMove();
  }

  putLayer(id, data, options) {
    let layer = this.layers.get(id);
    if (!layer) {
      layer = new dgGeoJson(data, {id, ...options});
      this.layers.set(id, layer);
      this.e8.addItem(layer);
    } else layer.setData(data);
    return layer;
  }

  setBuildings(collection) {
    this.collection = collection;
    if (this.previewLight) this.previewLight.visible=collection.features.some(f=>f.properties.model?.appearance_kind==='procedural');
    this.syncModels();
    this.renderBuildings();
  }

  setScenes(scenes) {
    this.scenes = scenes;
    this.syncModels();
    this.renderBuildings(true);
  }

  setChunksEnabled(enabled) {
    this.chunksEnabled = enabled;
    this.syncModels();
    this.renderBuildings(true);
  }

  renderBuildings(modelsOnly = false) {
    // 大型模型品質紀錄不送進 MapLibre worker；點擊時從原集合取得詳情。
    const displayFeature = f => ({type: f.type, id: f.id, geometry: f.geometry, properties: {
      build_id: f.properties.build_id, height: f.properties.height, status: f.properties.status,
    }});
    if (!modelsOnly) this.putLayer('buildings-pick', {type: 'FeatureCollection', features: this.collection.features.map(displayFeature)}, {
      render: 'fill', color: '#ffffff', opacity: 0.001, interactive: true, above3D: true,
    }).setVisible(this.threeD);
    const roofLayerIds = new Set();
    for (const [status, color] of Object.entries(COLORS)) {
      const features = this.collection.features.filter(f => (f.properties.status ?? 'unprocessed') === status);
      if (!modelsOnly) this.putLayer(`buildings-${status}-fill`, {type: 'FeatureCollection', features: features.map(displayFeature)}, {
          render: 'fill', color, opacity: 0.7, interactive: true,
        }).setVisible(!this.threeD);
      // 等模型真正 ready 才移除白模，載入失敗、超過預覽額度仍保留建物。
      const whiteModels = features.filter(f => !this.readyModels.has(f.properties.model?.asset_id) && !this.coveredAssets.has(f.properties.model?.asset_id)).map(displayFeature);
      const whiteKey = JSON.stringify(whiteModels.map(f => f.properties.build_id));
      if (modelsOnly && this.whiteLayerKeys.get(status) === whiteKey) {
        for (const id of this.roofLayerIds) if (id.startsWith(`buildings-${status}-roof-`)) roofLayerIds.add(id);
        continue;
      }
      this.whiteLayerKeys.set(status, whiteKey);
      this.putLayer(`buildings-${status}-3d`, {
        type: 'FeatureCollection', features: whiteModels,
      }, {render: 'extrusion', color: '#edf0f2', heightProperty: 'height', opacity: 1, interactive: true}).setVisible(this.threeD);
      if (this.threeD) roofLineChunks(whiteModels).forEach((lines, index) => {
        const id = `buildings-${status}-roof-${index}`;
        roofLayerIds.add(id);
        this.putLayer(id, {type: 'FeatureCollection', features: lines}, {
          render: 'line3d', color: status === 'unprocessed' ? '#a6b1b8' : color,
          width: status === 'unprocessed' ? 0.8 : 1.8, opacity: 1, altitude: 0,
        });
      });
    }
    for (const id of this.roofLayerIds) {
      if (!roofLayerIds.has(id)) {
        this.e8.removeItem(this.layers.get(id));
        this.layers.delete(id);
      }
    }
    this.roofLayerIds = roofLayerIds;
    const available = this.collection.features.filter(f => f.properties.model).map(f => f.properties.model.asset_id);
    const wanted = new Set([...this.wantedModels.values()].flatMap(info => info.members ?? [info.asset_id]));
    this.onModels({loaded: available.filter(id => this.readyModels.has(id) || this.coveredAssets.has(id)).length,
      total: available.filter(id => wanted.has(id)).length, available: available.length});
  }

  syncModels() {
    const small = this.native.getContainer().clientWidth < 600;
    this.modelConcurrency = small ? 2 : 4;
    let candidates = [], chunks = [];
    if (this.threeD) {
      const center = this.native.getCenter(), bounds = this.native.getBounds();
      const longitudeScale = Math.cos(center.lat * Math.PI / 180);
      const available = new Set(this.collection.features.map(f => f.properties.model?.asset_id).filter(Boolean));
      candidates = this.collection.features.map(f => f.properties.model).filter(info => info
        && info.longitude >= bounds.getWest() && info.longitude <= bounds.getEast()
        && info.latitude >= bounds.getSouth() && info.latitude <= bounds.getNorth());
      const visible = new Set(candidates.map(info => info.asset_id));
      const distance = info => ((info.longitude - center.lng) * longitudeScale) ** 2 + (info.latitude - center.lat) ** 2;
      candidates.sort((a, b) => distance(a) - distance(b) || a.asset_id.localeCompare(b.asset_id));
      // shortcut: 邊界街廓成員不全時保留個別模型；需要跨視野載入時再加入完整版本查詢。
      if (this.chunksEnabled) chunks = this.scenes.filter(scene => scene.status === 'completed' && scene.model
        && scene.members?.length && scene.members.every(id => available.has(id)) && scene.members.some(id => visible.has(id)))
        .map(scene => ({...scene.model, members: scene.members}))
        .sort((a, b) => distance(a) - distance(b)).slice(0, small ? 4 : 8);
    }
    this.coveredAssets = new Set(chunks.filter(info => this.readyModels.has(info.asset_id)).flatMap(info => info.members));
    const singles = candidates.filter(info => !this.coveredAssets.has(info.asset_id)).slice(0, small ? 24 : 64);
    const wanted = new Map([...chunks, ...singles].map(info => [info.asset_id, info]));
    this.wantedModels = wanted;
    for (const [id, model] of this.models) {
      if (!wanted.has(id) && !this.pendingModels.has(id)) this.removeModel(id, model);
    }
    for (const id of this.failedModels) if (!wanted.has(id)) this.failedModels.delete(id);
    this.pumpModels();
  }

  removeModel(id, model) {
    this.e8.removeItem(model);
    this.models.delete(id);
    this.readyModels.delete(id);
  }

  pumpModels() {
    if (this.destroyed) return;
    for (const [id, info] of this.wantedModels) {
      if (this.pendingModels.size >= this.modelConcurrency) break;
      if (this.models.has(id) || this.failedModels.has(id)) continue;
      const assetId = `geotexture-${id}`;
      try {
        if (!this.assets.has(assetId)) {
          Easymap.registerModelAsset(assetId, {
            url: new URL(info.url, location.href).href,
            unitMeters: 1, upAxis: info.up_axis ?? 'Y', forwardAxis: info.forward_axis ?? 'Z',
            anchor: info.anchor ?? [0, 0, 0],
          });
          this.assets.add(assetId);
        }
        const model = new dgModel({
          id: assetId, assetId, longitude: info.longitude, latitude: info.latitude,
          altitude: info.altitude ?? 0, heading: info.heading ?? 0, scale: info.scale ?? 1,
        });
        this.e8.addItem(model);
        this.models.set(id, model);
        this.pendingModels.add(id);
        model.ready.then(() => {
          if (this.destroyed) return;
          if (this.wantedModels.has(id)) {
            this.readyModels.add(id);
            this.syncModels();
          }
          else this.removeModel(id, model);
        }, error => {
          if (this.destroyed) return;
          this.removeModel(id, model);
          this.failedModels.add(id);
          if (this.wantedModels.has(id)) this.onError(`成果模型無法載入，暫保留白模：${error.message}`);
        }).finally(() => {
          this.pendingModels.delete(id);
          if (this.destroyed) return;
          // SDK 不能中止傳輸；過期請求完成後才釋放名額，避免快速移動堆積下載。
          if (!this.modelRefreshTimer) this.modelRefreshTimer = setTimeout(() => {
            this.modelRefreshTimer = null;
            this.renderBuildings(true);
          }, 400);
          clearTimeout(this.modelPumpTimer);
          this.modelPumpTimer = setTimeout(() => this.pumpModels(), 0);
        });
      } catch (error) {
        this.failedModels.add(id);
        this.onError(`成果模型無法載入：${error.message}`);
      }
    }
  }

  set3D(enabled) {
    if (enabled) this.e8.enable3D(); else this.e8.disable3D();
    this.threeD = enabled;
    this.layers.get('selection-fill')?.setVisible(!enabled);
    this.setBuildings(this.collection);
    this.native.easeTo({pitch: enabled ? 55 : 0, duration: 350});
  }

  bounds() {
    const b = this.native.getBounds();
    return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];
  }

  home(center, zoom) {
    this.e8.set3DCamera({center, zoom});
  }

  focusModel(model) {
    this.native.easeTo({center: [model.longitude, model.latitude], zoom: 21,
      pitch: 55, bearing: 155, duration: 500});
  }

  locate(polygon) {
    const points = polygon.coordinates.flat();
    const west = Math.min(...points.map(p => p[0]));
    const east = Math.max(...points.map(p => p[0]));
    const south = Math.min(...points.map(p => p[1]));
    const north = Math.max(...points.map(p => p[1]));
    this.native.fitBounds([[west, south], [east, north]], {padding: 65, maxZoom: 18, duration: 500});
  }

  setSelection(polygon, color = '#087f86') {
    this.selection = polygon;
    this.putLayer('selection-fill', polygon ? {type: 'FeatureCollection', features: [feature(polygon)]} : empty(), {
      render: 'fill', color: '#087f86', opacity: 0.12, above3D: true,
    }).setVisible(!this.threeD);
    const edges = polygon ? polygon.coordinates.map(ring => feature({type: 'LineString', coordinates: ring})) : [];
    this.putLayer('selection-line', {type: 'FeatureCollection', features: edges}, {
      render: 'line', color, width: 2.5, above3D: true,
    });
  }

  beginDraw() {
    this.vertices = [];
    this.draftCursor = null;
    this.native.doubleClickZoom.disable();
    document.querySelector('#map').classList.add('drawing');
    this.setSelection(null);
    this.renderDraft();
    this.onDraw(0);
  }

  undoDraw() {
    if (!this.vertices) return;
    this.vertices.pop();
    this.renderDraft();
    this.onDraw(this.vertices.length);
  }

  endDraw() {
    if (!this.vertices || this.vertices.length < 3) throw new Error('Polygon 至少需要 3 個不同的點。');
    const ring = this.vertices.map(p => [...p]);
    ring.push([...ring[0]]);
    this.cancelDraw();
    return {type: 'Polygon', coordinates: [ring]};
  }

  cancelDraw() {
    this.vertices = null;
    this.draftCursor = null;
    this.native.doubleClickZoom.enable();
    document.querySelector('#map').classList.remove('drawing');
    this.renderDraft();
  }

  renderDraft() {
    cancelAnimationFrame(this.draftFrame);
    this.draftFrame = null;
    const points = this.vertices ?? [];
    // 游標只參與預覽；完成範圍時仍只採用使用者實際點下的頂點。
    const preview = [...points];
    const last = preview.at(-1);
    if (this.draftCursor && (!last || Math.hypot(this.draftCursor[0] - last[0], this.draftCursor[1] - last[1]) > 1e-10)) {
      preview.push(this.draftCursor);
    }
    const ring = preview.length >= 3 ? [...preview, preview[0]] : null;
    this.putLayer('draft-fill', ring ? {type: 'FeatureCollection', features: [feature({type: 'Polygon', coordinates: [ring]})]} : empty(), {
      render: 'fill', color: '#087f86', opacity: 0.2, above3D: true,
    });
    const lines = preview.length >= 2 ? [feature({type: 'LineString', coordinates: ring ?? preview})] : [];
    this.putLayer('draft-line', {type: 'FeatureCollection', features: lines}, {
      render: 'line', color: '#087f86', width: 3, above3D: true,
    });
    this.putLayer('draft-points', {type: 'FeatureCollection', features: points.map(p => feature({type: 'Point', coordinates: p}))}, {
      render: 'circle', color: '#fff', radius: 4, strokeWidth: 2, strokeColor: '#087f86', above3D: true,
    });
    this.putLayer('draft-cursor', this.draftCursor ? {type: 'FeatureCollection', features: [feature({type: 'Point', coordinates: this.draftCursor})]} : empty(), {
      render: 'circle', color: '#087f86', radius: 4, strokeWidth: 2, strokeColor: '#fff', above3D: true,
    });
  }
}
