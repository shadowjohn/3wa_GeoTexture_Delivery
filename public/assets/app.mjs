import {MapWorkspace} from './map.mjs';
import {qualityNotices} from './model-quality.mjs';

const $ = id => document.getElementById(id);
const modes = {task: {polygon: null, collection: null}, export: {polygon: null, collection: null}};
const LABELS = {queued: '排隊中', preparing: '前置建檔中', awaiting_converter: '待轉換', partial: '部分貼皮', completed: '已完成', failed: '失敗', cancelled: '已取消', unprocessed: '未處理', prepared: '待轉換'};
let mode = 'task', meta, map, mapReady = false, selectionVersion = 0, activeJob = null, inspectedJob = null;
let viewRequest, viewTimer, jobsBusy = false, selectionBusy = false, operationBusy = false;
let inspectedItems = [];
const number = n => Number(n ?? 0).toLocaleString('zh-TW');
const area = n => n >= 1e6 ? `${(n / 1e6).toFixed(2)} km²` : `${number(Math.round(n))} m²`;
const date = value => new Date(/Z$|[+-]\d\d:\d\d$/.test(value) ? value : value + 'Z').toLocaleString('zh-TW', {hour12: false});
const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

function message(text, error = false, target = 'selection-message') {
  $(target).textContent = text;
  $(target).classList.toggle('error', error);
  if (target === 'map-message') $(target).hidden = !text;
}

async function api(action, {body, signal, params = {}} = {}) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
  const response = await fetch(url, {
    method: body ? 'POST' : 'GET', signal, credentials: 'same-origin',
    headers: body ? {'Content-Type': 'application/json', 'X-CSRF-Token': meta?.csrf_token ?? ''} : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  if (response.status === 401) { location.assign('login.php'); throw new Error('登入已逾時，請重新登入。'); }
  if (action === 'export' && response.ok) return response;
  const json = await response.json().catch(() => { throw new Error('伺服器未回傳有效資料，請檢查 PHP 紀錄。'); });
  if (!response.ok) throw new Error(json.error ?? `HTTP ${response.status}`);
  return json.data;
}

function updateControls() {
  const current = modes[mode];
  const collection = current.collection;
  const count = inspectedJob?.total ?? collection?.features.length ?? 0;
  const incomplete = collection?.meta?.truncated;
  $('selection-count').textContent = selectionBusy ? '查詢中…' : collection ? `${incomplete ? '超過 ' : ''}${number(count)} 棟` : '尚未框選';
  if (inspectedJob) $('selection-count').textContent = `${number(count)} 棟`;
  $('selection-area').textContent = inspectedJob ? area(inspectedJob.area_m2) : current.polygon ? area(polygonArea(current.polygon)) : '—';
  const completed = inspectedJob?.completed ?? collection?.features.filter(f => f.properties.status === 'completed').length ?? 0;
  const partial = inspectedJob?.partial ?? collection?.features.filter(f => f.properties.model?.texture_status === 'partial').length ?? 0;
  $('selection-ready').textContent = inspectedJob || collection ? `${number(completed)} 完整 · ${number(partial)} 部分` : '—';
  $('create-job').disabled = !mapReady || selectionBusy || operationBusy || Boolean(map?.vertices) || Boolean(inspectedJob) || !count || incomplete;
  $('download').disabled = $('create-job').disabled;
  $('draw').disabled = !mapReady || selectionBusy || operationBusy || Boolean(map?.vertices);
  $('resume-selection').hidden = !inspectedJob;
  $('mode-title').textContent = inspectedJob ? '檢視任務範圍' : mode === 'task' ? '建立處理範圍' : '框選匯出範圍';
  $('mode-hint').textContent = inspectedJob ? `目前顯示「${inspectedJob.name}」；返回目前框選後，才能建立任務或匯出。` : mode === 'task' ? '在地圖上畫 Polygon，選取要製作貼圖的建物。' : '重新畫 Polygon，匯出這個範圍的建物與已有成果。';
}

// 僅供畫面摘要；後台以同一 Polygon 重新計算並保存正式面積。
function polygonArea(polygon) {
  const ring = polygon.coordinates[0], radius = 6371008.8, rad = Math.PI / 180;
  let sum = 0;
  for (let i = 0; i < ring.length - 1; i++) {
    sum += (ring[i + 1][0] - ring[i][0]) * rad * (2 + Math.sin(ring[i][1] * rad) + Math.sin(ring[i + 1][1] * rad));
  }
  return Math.abs(sum * radius * radius / 2);
}

function switchMode(next) {
  if (selectionBusy || operationBusy) return;
  if (map?.vertices) cancelDraw();
  inspectedJob = null;
  mode = next;
  $('task-tab').classList.toggle('active', mode === 'task');
  $('export-tab').classList.toggle('active', mode === 'export');
  $('task-tab').setAttribute('aria-pressed', String(mode === 'task'));
  $('export-tab').setAttribute('aria-pressed', String(mode === 'export'));
  $('mode-title').textContent = mode === 'task' ? '建立處理範圍' : '框選匯出範圍';
  $('mode-hint').textContent = mode === 'task' ? '在地圖上畫 Polygon，選取要製作貼圖的建物。' : '重新畫 Polygon，匯出這個範圍的建物與已有成果。';
  $('task-form').hidden = mode !== 'task';
  $('export-panel').hidden = mode !== 'export';
  $('selection-ready-row').hidden = mode !== 'export';
  message('');
  if (mapReady) map.setSelection(modes[mode].polygon);
  updateControls();
}

function drawState(active) {
  $('draw-actions').hidden = !active;
  $('draw-hint').hidden = !active;
  updateControls();
}

function cancelDraw() {
  map.cancelDraw();
  map.setSelection(modes[mode].polygon);
  drawState(false);
}

async function finishDraw() {
  if (selectionBusy) return;
  try {
    const polygon = map.endDraw();
    drawState(false);
    await selectPolygon(polygon);
  } catch (error) { message(error.message, true); }
}

async function selectPolygon(polygon) {
  const selectedMode = mode, version = ++selectionVersion;
  const current = modes[selectedMode];
  current.polygon = polygon;
  current.collection = null;
  selectionBusy = true;
  map.setSelection(polygon);
  message('正在精確篩選 Polygon 內相交的建物…');
  updateControls();
  try {
    const result = await api('selection', {body: {polygon}});
    if (version !== selectionVersion) return;
    current.collection = result;
    if (result.meta.truncated) message('範圍超過 10,000 棟，請縮小後再建立任務或匯出。', true);
    else message(result.features.length ? `已選取 ${number(result.features.length)} 棟建物。` : '範圍內沒有建物，請重新繪製。');
  } catch (error) { if (version === selectionVersion) message(error.message, true); }
  finally { if (version === selectionVersion) { selectionBusy = false; updateControls(); } }
}

async function loadView() {
  if (!mapReady || !meta.source.ready) return;
  viewRequest?.abort();
  viewRequest = new AbortController();
  const signal = viewRequest.signal;
  if (map.native.getZoom() < 13) {
    map.setBuildings({type: 'FeatureCollection', features: []});
    $('visible-count').textContent = '請放大至街廓範圍載入建物';
    message('請放大地圖以載入建物。', false, 'map-message');
    return;
  }
  try {
    const result = await api('buildings', {params: {bbox: map.bounds().join(','), limit: 2000}, signal});
    if (signal.aborted) return;
    map.setBuildings(result);
    $('visible-count').textContent = `目前載入 ${number(result.features.length)} 棟${result.meta.truncated ? '（已達上限）' : ''}`;
    message(result.meta.truncated ? '目前視野超過 2,000 棟，請放大；建立任務仍會精確查詢所畫範圍。' : '', false, 'map-message');
  } catch (error) {
    if (error.name !== 'AbortError') {
      $('visible-count').textContent = '建物載入失敗';
      message(error.message, true, 'map-message');
    }
  }
}

function scheduleView() { clearTimeout(viewTimer); viewTimer = setTimeout(loadView, 200); }

function jobMarkup(job) {
  return `<article class="job">
    <div class="job-heading"><button type="button" class="job-link" data-job="${job.id}">${escape(job.name)}</button><span class="status ${job.status}">${LABELS[job.status] ?? job.status}</span></div>
    <p class="job-meta">${area(job.area_m2)} · ${number(job.total)} 棟 · ${escape(date(job.created_at))}</p>
    <div class="progress-label"><span>前置建檔</span><span>${number(job.prepared)} / ${number(job.total)}</span></div>
    <progress max="${Math.max(1, job.total)}" value="${job.prepared}" aria-label="前置建檔進度"></progress>
    <div class="progress-label"><span>貼皮成果</span><span>${number(job.completed)} / ${number(job.total)}</span></div>
    <progress max="${Math.max(1, job.total)}" value="${job.completed}" aria-label="貼皮成果進度"></progress>
    ${job.partial ? `<p class="hint">部分貼皮 ${number(job.partial)} 棟，仍有缺面。</p>` : ''}
    <div class="job-actions"><button type="button" class="text-button" data-job="${job.id}">範圍與詳情</button>${['queued', 'preparing', 'awaiting_converter', 'partial'].includes(job.status) ? `<button type="button" class="text-button" data-cancel="${job.id}">取消任務</button>` : ''}${['failed', 'cancelled', 'partial'].includes(job.status) ? `<button type="button" class="text-button" data-retry="${job.id}">重試任務</button>` : ''}</div>
  </article>`;
}

async function loadJobs({refreshMap = false} = {}) {
  if (jobsBusy) return;
  jobsBusy = true;
  try {
    const jobs = await api('jobs');
    $('jobs').innerHTML = jobs.length ? jobs.map(jobMarkup).join('') : '<p class="hint">尚無任務。畫出第一個範圍，開始累積建物成果。</p>';
    if (activeJob) await showJob(activeJob, false);
    if (refreshMap) scheduleView();
  } catch (error) { $('jobs').textContent = error.message; }
  finally { jobsBusy = false; }
}

async function showJob(id, locate = true) {
  try {
    const job = await api('job', {params: {id}});
    activeJob = id;
    $('job-detail').hidden = false;
    inspectedItems = job.items ?? [];
    const rows = inspectedItems.slice(0, 100).map(item => `<tr><td>${item.model ? `<button type="button" class="text-button" data-building="${escape(item.build_id)}" title="定位並查看成果">${escape(item.build_id)}</button>` : escape(item.build_id)}</td><td>${LABELS[item.status] ?? escape(item.status)}</td></tr>`).join('');
    const firstModel = inspectedItems.find(item => item.model);
    $('job-detail').innerHTML = `<h3>${escape(job.name)}</h3><dl>
      <div><dt>任務狀態</dt><dd>${LABELS[job.status] ?? escape(job.status)}</dd></div>
      <div><dt>範圍面積</dt><dd>${area(job.area_m2)}</dd></div>
      <div><dt>處理建物</dt><dd>${number(job.total)} 棟</dd></div>
      <div><dt>已有成果重用</dt><dd>${number(job.skipped)} 棟</dd></div>
      ${job.partial ? `<div><dt>部分貼皮</dt><dd>${number(job.partial)} 棟</dd></div>` : ''}
      <div><dt>失敗</dt><dd>${number(job.failed)} 棟</dd></div>
      <div><dt>建立時間</dt><dd>${escape(date(job.created_at))}</dd></div>
      <div><dt>最後更新</dt><dd>${escape(date(job.updated_at))}</dd></div>
      </dl>${job.error ? `<p class="inline-message error">${escape(job.error)}</p>` : ''}
      ${firstModel ? `<button type="button" class="outline full" data-building="${escape(firstModel.build_id)}">查看已貼圖建物</button>` : ''}
      <details><summary>建物清單${job.total > 100 ? '（顯示前 100 棟）' : ''}</summary><table><thead><tr><th>Build ID</th><th>狀態</th></tr></thead><tbody>${rows}</tbody></table></details>`;
    if (locate && mapReady) {
      if (map.vertices) cancelDraw();
      inspectedJob = job;
      map.setSelection(job.polygon);
      map.locate(job.polygon);
      message('正在檢視任務範圍，尚未變更你的工作框選。');
      updateControls();
    }
  } catch (error) { message(error.message, true); }
}

function showBuilding(p) {
  const model = p.model && typeof p.model === 'string' ? JSON.parse(p.model) : p.model;
  const partial = model?.texture_status === 'partial';
  const notices = qualityNotices(model);
  const credits = (Array.isArray(model?.attribution) ? model.attribution : []).filter(a => a && /^\d+$/.test(a.image_id));
  $('building-detail').hidden = false;
  $('building-detail').innerHTML = `<h3>建物 ${escape(p.build_id)}</h3><dl>
    <div><dt>名稱</dt><dd>${escape(p.name || '—')}</dd></div><div><dt>高度</dt><dd>${p.height_missing ? '尚無有效高度' : `${escape(p.height)} m`}</dd></div>
    <div><dt>成果狀態</dt><dd>${partial ? '部分貼皮' : p.status === 'completed' ? '已有成果' : LABELS[p.status] ?? escape(p.status)}</dd></div>
    ${model?.surfaces_total ? `<div><dt>已貼面數</dt><dd>${number(model.surfaces_textured)} / ${number(model.surfaces_total)}</dd></div>` : ''}</dl>
    ${partial ? '<p class="hint">部分立面已有真實影像，缺面仍為白模；匯出會保留缺面與影像來源記錄。</p>' : model ? '<p class="hint">切換 3D 可查看實際成果模型。</p>' : '<p class="hint">尚無貼皮成果。3D 模式顯示原始高度白模。</p>'}
    ${notices.length ? `<p class="inline-message error">試轉／待核對：${notices.map(escape).join('；')}。</p>` : ''}
    ${model?.asset_id ? `<a href="viewer.php?asset_id=${encodeURIComponent(model.asset_id)}" target="_blank" rel="noopener">獨立 GLB 預覽</a>` : ''}
    ${credits.length ? `<details><summary>Mapillary 影像來源 · CC BY-SA 4.0</summary>${credits.map(a => `<p><a href="https://www.mapillary.com/app/?pKey=${a.image_id}" target="_blank" rel="noopener">${escape(a.author?.username || a.image_id)}</a></p>`).join('')}<p>影像經立面投影與裁切。<a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">授權條款</a></p></details>` : ''}`;
}

$('job-detail').addEventListener('click', event => {
  const id = event.target.closest('[data-building]')?.dataset.building;
  const item = inspectedItems.find(item => item.build_id === id);
  if (!item?.model || !mapReady) return;
  if (map.vertices) cancelDraw();
  changeView(true);
  map.focusModel(item.model);
  showBuilding(item);
  $('building-detail').scrollIntoView({block: 'nearest', behavior: 'smooth'});
});

$('task-tab').onclick = () => switchMode('task');
$('export-tab').onclick = () => switchMode('export');
$('draw').onclick = () => { inspectedJob = null; message(''); map.beginDraw(); drawState(true); };
$('resume-selection').onclick = () => {
  inspectedJob = null;
  map.setSelection(modes[mode].polygon);
  if (modes[mode].polygon) map.locate(modes[mode].polygon);
  message(modes[mode].polygon ? '已返回目前框選範圍。' : '尚未框選範圍，請繪製 Polygon。');
  updateControls();
};
$('undo').onclick = () => map.undoDraw();
$('cancel-draw').onclick = cancelDraw;
$('finish').onclick = finishDraw;
$('home').onclick = () => mapReady && map.home(meta.center, meta.zoom);
$('view-2d').onclick = () => changeView(false);
$('view-3d').onclick = () => changeView(true);
function changeView(threeD) {
  if (!mapReady) return;
  map.set3D(threeD);
  for (const [id, active] of [['view-2d', !threeD], ['view-3d', threeD]]) {
    $(id).classList.toggle('active', active);
    $(id).setAttribute('aria-pressed', String(active));
  }
}
document.addEventListener('keydown', event => {
  if (!map?.vertices || /INPUT|TEXTAREA|SELECT/.test(event.target.tagName)) return;
  if (event.key === 'Escape') cancelDraw();
  if (event.key === 'Enter') { event.preventDefault(); finishDraw(); }
});
$('refresh-jobs').onclick = () => loadJobs({refreshMap: true});
$('jobs').addEventListener('click', async event => {
  const button = event.target.closest('button');
  if (!button) return;
  if (button.dataset.job) return showJob(button.dataset.job);
  const id = button.dataset.cancel ?? button.dataset.retry;
  if (!id) return;
  button.disabled = true;
  try {
    await api(button.dataset.cancel ? 'cancel_job' : 'retry_job', {body: {id}});
    await loadJobs({refreshMap: true});
  } catch (error) { message(error.message, true); button.disabled = false; }
});
$('task-form').addEventListener('submit', async event => {
  event.preventDefault();
  if ($('create-job').disabled) return;
  operationBusy = true; updateControls();
  try {
    const job = await api('create_job', {body: {name: $('task-name').value.trim(), polygon: modes.task.polygon, force: $('force').checked}});
    message(`任務已建立：${number(job.total)} 棟，等待排程處理。`);
    activeJob = job.id;
    await loadJobs({refreshMap: true});
  } catch (error) { message(error.message, true); }
  finally { operationBusy = false; updateControls(); }
});
$('download').onclick = async () => {
  if ($('download').disabled) return;
  operationBusy = true; updateControls(); message('正在整理匯出範圍與成果…');
  try {
    const response = await api('export', {body: {polygon: modes.export.polygon}});
    const blob = await response.blob();
    const url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = `geotexture-${new Date().toISOString().replace(/[:.]/g, '-')}.zip`;
    link.click(); setTimeout(() => URL.revokeObjectURL(url), 10000);
    message('成果包已產生，包含建物資料與成果／缺漏清單。');
  } catch (error) { message(error.message, true); }
  finally { operationBusy = false; updateControls(); }
};

async function start() {
  try {
    meta = await api('meta');
    $('converter-note').textContent = meta.converter_ready ? '轉換器已啟用' : '排程目前僅前置建檔 · 真實貼皮以 CLI 試轉';
    loadJobs();
    map = new MapWorkspace({onMove: scheduleView, onBuilding: showBuilding, onDraw: count => { $('finish').disabled = count < 3; }, onFinish: finishDraw, onError: text => message(text, true, 'map-message')});
    await map.init(meta.center, meta.zoom);
    mapReady = true;
    $('map-loading').hidden = true;
    updateControls(); scheduleView();
    if (!meta.source.ready) message(meta.source.error ?? '尚未設定建物資料來源。', true, 'map-message');
    setInterval(() => { if (!document.hidden) loadJobs({refreshMap: true}); }, 15000);
  } catch (error) {
    $('map-loading').textContent = error.message;
    message(error.message, true);
  }
}
start();
