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
  constructor({onMove, onBuilding, onDraw, onFinish, onError}) {
    Object.assign(this, {onMove, onBuilding, onDraw, onFinish, onError});
    this.layers = new Map();
    this.roofLayerIds = new Set();
    this.models = new Map();
    this.assets = new Set();
    this.collection = empty();
    this.vertices = null;
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
    this.native = this.e8.getNativeContext().maplibre.map;
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
    this.native.on('moveend', () => this.onMove());
    this.native.on('click', event => {
      if (!this.vertices) return;
      const point = [event.lngLat.lng, event.lngLat.lat];
      const previous = this.vertices.at(-1);
      if (!previous || Math.hypot(point[0] - previous[0], point[1] - previous[1]) > 1e-10) {
        this.vertices.push(point);
      }
      this.renderDraft();
      this.onDraw(this.vertices.length);
    });
    this.native.on('dblclick', event => {
      if (!this.vertices) return;
      event.preventDefault();
      if (this.vertices.length >= 3) this.onFinish();
    });
    this.e8.attachEvent('featureclick', event => {
      if (!this.vertices && event.properties?.build_id) this.onBuilding(event.properties);
    });
    this.e8.attachEvent('itemclick', event => {
      if (this.vertices || event.kind !== 'model') return;
      const building = this.collection.features.find(f => f.properties.model
        && event.id === `geotexture-${f.properties.model.asset_id}`);
      if (building) this.onBuilding(building.properties);
    });
    this.e8.attachEvent('itemerror', event => this.onError(`圖層載入失敗：${event.message}`));
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
    const roofLayerIds = new Set();
    for (const [status, color] of Object.entries(COLORS)) {
      const features = collection.features.filter(f => (f.properties.status ?? 'unprocessed') === status);
      this.putLayer(`buildings-${status}-fill`, {type: 'FeatureCollection', features}, {
        render: 'fill', color, opacity: 0.7, interactive: true,
      }).setVisible(!this.threeD);
      const whiteModels = features.filter(f => !f.properties.model);
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
    this.syncModels();
  }

  syncModels() {
    const wanted = new Map();
    if (this.threeD) {
      for (const f of this.collection.features) {
        if (f.properties.model) wanted.set(f.properties.model.asset_id, f.properties.model);
      }
    }
    for (const [id, model] of this.models) {
      if (!wanted.has(id)) { this.e8.removeItem(model); this.models.delete(id); }
    }
    for (const [id, info] of wanted) {
      if (this.models.has(id)) continue;
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
      } catch (error) { this.onError(`成果模型無法載入：${error.message}`); }
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
    this.native.doubleClickZoom.enable();
    document.querySelector('#map').classList.remove('drawing');
    this.renderDraft();
  }

  renderDraft() {
    const points = this.vertices ?? [];
    const lines = points.length >= 2 ? [feature({type: 'LineString', coordinates: points})] : [];
    this.putLayer('draft-line', {type: 'FeatureCollection', features: lines}, {
      render: 'line', color: '#087f86', width: 3, above3D: true,
    });
    this.putLayer('draft-points', {type: 'FeatureCollection', features: points.map(p => feature({type: 'Point', coordinates: p}))}, {
      render: 'circle', color: '#fff', radius: 4, strokeWidth: 2, strokeColor: '#087f86', above3D: true,
    });
  }
}
