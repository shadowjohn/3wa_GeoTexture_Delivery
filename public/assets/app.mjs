import {MapWorkspace} from './map.mjs?v=atlas-scene-1';
import {qualityNotices} from './model-quality.mjs';

const $ = id => document.getElementById(id);
const modes = {task: {polygon: null, collection: null}, export: {polygon: null, collection: null}};
const LABELS = {queued: '排隊中', preparing: '處理中', awaiting_converter: '待實景轉換', partial: '部分貼皮', completed: '已完成', failed: '失敗', cancelled: '已取消', unprocessed: '未處理', prepared: '待轉換'};
let mode = 'task', meta, map, mapReady = false, selectionVersion = 0, activeJob = null, inspectedJob = null;
let viewRequest, viewTimer, jobsBusy = false, selectionBusy = false, operationBusy = false;
let inspectedItems = [];
let recipeKey = 'procedural-tw-v1', sourceKey = 'new-taipei-8418', sourceBusy = false;
let viewVersion = 0, previewVersion = 0, previewRequest, previewTimer, previewScenes = [];
let sceneExport = null, sceneExportVersion = 0, scenePollRequest, sceneExportBusy = false;
const scenePending = job => job && ['queued', 'running'].includes(job.status);
const sourceLabel = key => meta?.sources?.[key]?.name ?? key;
let jobRequestVersion = 0;
let jobsVersion = 0, progressBusy = false, progressMapAt = 0, progressMapDirty = false;
const jobsCache = new Map();
const recipeLabel = key => meta?.recipes?.[key]?.label ?? ({'procedural-tw-v1':'台灣街區','procedural-modern-v1':'現代簡約','procedural-pastel-v1':'粉彩插畫','texture-v1':'實景貼皮'}[key] ?? key);
const number = n => Number(n ?? 0).toLocaleString('zh-TW');
const area = n => n >= 1e6 ? `${(n / 1e6).toFixed(2)} km²` : `${number(Math.round(n))} m²`;
const date = value => new Date(/Z$|[+-]\d\d:\d\d$/.test(value) ? value : value + 'Z').toLocaleString('zh-TW', {hour12: false});
const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

function message(text, error = false, target = 'selection-message') {
  $(target).textContent = text;
  $(target).classList.toggle('error', error);
  if (target === 'map-message') $(target).hidden = !text;
}

async function api(action, {body, signal, params = {}, recipe = recipeKey, source = sourceKey} = {}) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  url.searchParams.set('recipe_key', recipe);
  url.searchParams.set('source_key', source);
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
  const response = await fetch(url, {
    method: body ? 'POST' : 'GET', signal, credentials: 'same-origin',
    headers: body ? {'Content-Type': 'application/json', 'X-CSRF-Token': meta?.csrf_token ?? ''} : {},
    body: body ? JSON.stringify({recipe_key: recipe, source_key: source, ...body}) : undefined,
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
  $('create-job').disabled = !mapReady || sourceBusy || selectionBusy || operationBusy || Boolean(map?.vertices) || Boolean(inspectedJob) || !count || incomplete;
  $('download').disabled = $('create-job').disabled;
  $('draw').disabled = !mapReady || sourceBusy || selectionBusy || operationBusy || Boolean(map?.vertices);
  $('resume-selection').hidden = !inspectedJob;
  $('recipe-key').disabled = sourceBusy || selectionBusy || operationBusy || Boolean(map?.vertices);
  $('source-key').disabled = $('recipe-key').disabled || !meta?.sources;
  $('station-location').disabled = !mapReady || sourceBusy || selectionBusy || operationBusy || Boolean(map?.vertices);
  $('preview-chunks').disabled = !mapReady || sourceBusy || recipeKey === 'texture-v1';
  $('create-scene-export').disabled = $('download').disabled || count > 2000 || recipeKey === 'texture-v1' || sceneExportBusy || Boolean(scenePending(sceneExport?.job));
  for (const button of document.querySelectorAll('[data-run]')) button.disabled=operationBusy || selectionBusy;
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
  if (mapReady && modes[mode].polygon && !modes[mode].collection) selectPolygon(modes[mode].polygon);
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
  if (!mapReady || sourceBusy || !meta.source.ready) return;
  const version = ++viewVersion;
  stopPreview();
  viewRequest?.abort();
  viewRequest = new AbortController();
  const signal = viewRequest.signal;
  if (map.native.getZoom() < 13) {
    map.setScenes([]);
    map.setBuildings({type: 'FeatureCollection', features: []});
    $('visible-count').textContent = '請放大至街廓範圍載入建物';
    message('請放大地圖以載入建物。', false, 'map-message');
    return;
  }
  const bbox = map.bounds();
  try {
    const result = await api('buildings', {params: {bbox: bbox.join(','), limit: 2000, detail: 'preview'}, signal});
    if (signal.aborted || version !== viewVersion) return;
    map.setBuildings(result);
    requestPreview(bbox, version);
    $('visible-count').textContent = `目前載入 ${number(result.features.length)} 棟${result.meta.truncated ? '（已達上限）' : ''}`;
    message(result.meta.truncated ? '目前視野超過 2,000 棟，請放大；建立任務仍會精確查詢所畫範圍。' : '', false, 'map-message');
  } catch (error) {
    if (error.name !== 'AbortError' && version === viewVersion) {
      $('visible-count').textContent = '建物載入失敗';
      message(error.message, true, 'map-message');
    }
  }
}

function scheduleView() {
  ++viewVersion; viewRequest?.abort(); stopPreview();
  clearTimeout(viewTimer); viewTimer = setTimeout(loadView, 200);
}

function stopPreview(clear = false) {
  ++previewVersion; previewRequest?.abort(); previewRequest = null;
  clearTimeout(previewTimer); previewScenes = [];
  if (clear) { map?.setScenes([]); message('', false, 'preview-status'); }
}

function previewEnabled() {
  return mapReady && map.threeD && recipeKey !== 'texture-v1' && $('preview-chunks').checked;
}

function applyPreview(scenes) {
  previewScenes = scenes;
  map.setScenes(scenes);
  const pending = scenes.filter(scenePending).length;
  const failed = scenes.filter(scene => ['failed', 'cancelled'].includes(scene.status)).length;
  message(pending ? `${pending} 個街廓背景準備中，可能等候約一分鐘；目前保留個別建物。`
    : failed ? '部分街廓未完成，保留個別建物預覽。' : scenes.length ? '街廓已備妥，載入完成後自動接替個別建物。' : '', false, 'preview-status');
}

async function requestPreview(bbox, version) {
  if (!previewEnabled() || document.hidden) return;
  const generation = previewVersion, request = new AbortController();
  previewRequest = request;
  try {
    // Tilted map bounds extend far beyond the camera target; prioritize its actual center.
    const center=map.native.getCenter();
    const result = await api('preview_scenes', {body: {bbox, center:[center.lng,center.lat]}, signal: request.signal});
    if (request.signal.aborted || generation !== previewVersion || version !== viewVersion || !previewEnabled()) return;
    applyPreview(result.scenes);
  } catch (error) {
    if (!request.signal.aborted && generation === previewVersion) message(`街廓預覽暫不可用，保留個別建物：${error.message}`, true, 'preview-status');
  } finally {
    if (previewRequest === request) { previewRequest = null; queuePreviewPoll(); }
  }
}

function queuePreviewPoll() {
  clearTimeout(previewTimer);
  if (previewScenes.some(scenePending)) previewTimer = setTimeout(pollPreview, 3000);
}

async function pollPreview() {
  if (previewRequest || !previewEnabled()) return;
  if (document.hidden) { queuePreviewPoll(); return; }
  const pending = previewScenes.filter(scenePending);
  if (!pending.length) return;
  const generation = previewVersion, request = new AbortController();
  previewRequest = request;
  try {
    const updates = await Promise.all(pending.map(scene => api('scene_status', {params: {id: scene.id}, signal: request.signal})));
    if (request.signal.aborted || generation !== previewVersion || !previewEnabled()) return;
    applyPreview(previewScenes.map(scene => updates.find(update => update.id === scene.id) ?? scene));
  } catch (error) {
    if (!request.signal.aborted && generation === previewVersion) message(`街廓進度稍後重試：${error.message}`, true, 'preview-status');
  } finally {
    if (previewRequest === request) { previewRequest = null; queuePreviewPoll(); }
  }
}

function clearViewContext(clearPolygons = false) {
  ++viewVersion; viewRequest?.abort(); ++selectionVersion;
  inspectedJob = null; activeJob = null; inspectedItems = [];
  $('job-detail').hidden = true; $('building-detail').hidden = true;
  for (const current of Object.values(modes)) { current.collection = null; if (clearPolygons) current.polygon = null; }
  map?.setBuildings({type: 'FeatureCollection', features: []});
  stopPreview(true);
  if (mapReady) map.setSelection(modes[mode].polygon);
}

function updateSourceDescription() {
  $('source-key').innerHTML = Object.entries(meta.sources ?? {}).map(([key, source]) => `<option value="${escape(key)}">${escape(source.name)}</option>`).join('');
  $('source-key').value = sourceKey;
  $('source-label').textContent = sourceLabel(sourceKey);
  $('station-control').hidden = sourceKey !== 'taichung-8';
  $('station-location').value = '';
}

async function changeSource(key, {locate = true} = {}) {
  if (sourceBusy || selectionBusy || operationBusy || key === sourceKey) return;
  const version = ++jobRequestVersion;
  sourceKey = key; sourceBusy = true; clearViewContext(true); updateControls();
  try {
    const nextMeta = await api('meta');
    if (version !== jobRequestVersion) return;
    meta = nextMeta; updateSourceDescription();
    if (locate && mapReady) map.home(meta.center, meta.zoom);
    $('task-name').value = `${sourceLabel(sourceKey)}範圍`;
    message(`已切換至${sourceLabel(sourceKey)}，請重新框選範圍。`);
    if (!meta.source.ready) message(meta.source.error ?? '建物來源尚未就緒。', true, 'map-message');
  } catch (error) { meta.source.ready = false; message(error.message, true, 'map-message'); }
  finally { sourceBusy = false; updateControls(); scheduleView(); }
}
$('source-key').onchange = () => changeSource($('source-key').value);
$('station-location').onchange = () => {
  if (!mapReady || !$('station-location').value) return;
  const [longitude, latitude] = $('station-location').value.split(',').map(Number);
  map.home({longitude, latitude}, 18); changeView(true);
};
$('preview-chunks').onchange = () => {
  map?.setChunksEnabled($('preview-chunks').checked);
  stopPreview(true); if (mapReady) scheduleView();
};

function updateRecipeDescription() {
  const recipe=meta?.recipes?.[recipeKey];
  $('recipe-key').value=recipeKey;
  $('recipe-description').textContent=recipe?.description ?? '';
  $('appearance-badge').textContent=recipeKey==='texture-v1' ? '實景照片 · 依成果保留品質說明' : '示意立面 · 非實景照片';
}

async function changeRecipe(key) {
  if (sourceBusy || selectionBusy || operationBusy) return;
  recipeKey=key; updateRecipeDescription();
  ++jobRequestVersion;
  clearViewContext();
  message(`已切換為${recipeLabel(recipeKey)}，地圖與匯出使用此風格。`);
  updateControls(); scheduleView();
  if (modes[mode].polygon && mapReady) await selectPolygon(modes[mode].polygon);
}
$('recipe-key').onchange = () => changeRecipe($('recipe-key').value);

function jobActions(job) {
  return `<button type="button" class="text-button" data-job="${job.id}">範圍與詳情</button>${job.recipe_key?.startsWith('procedural-') && ['queued','preparing'].includes(job.status) ? `<button type="button" class="text-button" data-run="${job.id}" ${operationBusy || selectionBusy ? 'disabled' : ''}>立即處理一批</button>` : ''}${['queued', 'preparing', 'awaiting_converter', 'partial'].includes(job.status) ? `<button type="button" class="text-button" data-cancel="${job.id}">取消任務</button>` : ''}${['failed', 'cancelled', 'partial'].includes(job.status) ? `<button type="button" class="text-button" data-retry="${job.id}">重試任務</button>` : ''}`;
}

function completedLabel(job) {
  return `${number(job.completed)} / ${number(job.total)}（${job.total ? Math.floor(job.completed / job.total * 100) : 0}%）`;
}

function remainingLabel(job) {
  const queued=job.queued ?? Math.max(0,job.total-job.prepared-job.failed);
  return `${job.status==='cancelled' ? '已停止' : '待處理'} ${number(queued)} 棟 · 失敗 ${number(job.failed)} 棟${job.partial ? ` · 部分貼皮 ${number(job.partial)} 棟` : ''}`;
}

function jobMarkup(job) {
  return `<article class="job" data-job-card="${job.id}">
    <div class="job-heading"><button type="button" class="job-link" data-job="${job.id}">${escape(job.name)}</button><span class="status ${job.status}">${LABELS[job.status] ?? job.status}</span></div>
    <p class="job-meta">${escape(sourceLabel(job.source_key ?? 'new-taipei-8418'))} · ${escape(recipeLabel(job.recipe_key ?? 'texture-v1'))} · ${area(job.area_m2)} · ${number(job.total)} 棟 · ${escape(date(job.created_at))}</p>
    <div class="progress-label"><span>前置建檔</span><span data-count="prepared">${number(job.prepared)} / ${number(job.total)}</span></div>
    <progress data-bar="prepared" max="${Math.max(1, job.total)}" value="${job.prepared}" aria-label="前置建檔進度"></progress>
    <div class="progress-label"><span>貼皮成果</span><span data-count="completed">${completedLabel(job)}</span></div>
    <progress data-bar="completed" max="${Math.max(1, job.total)}" value="${job.completed}" aria-label="貼皮成果進度"></progress>
    <p class="hint" data-count="remaining">${remainingLabel(job)}</p>
    <div class="job-actions">${jobActions(job)}</div>
  </article>`;
}

async function loadJobs({refreshMap = false} = {}) {
  if (jobsBusy) return;
  jobsBusy = true;
  ++jobsVersion;
  try {
    const jobs = await api('jobs');
    jobsCache.clear();
    for (const job of jobs) jobsCache.set(job.id,job);
    $('jobs').innerHTML = jobs.length ? jobs.map(jobMarkup).join('') : '<p class="hint">尚無任務。畫出第一個範圍，開始累積建物成果。</p>';
    if (activeJob) await showJob(activeJob, false);
    if (refreshMap) scheduleView();
  } catch (error) { $('jobs').textContent = error.message; }
  finally { jobsBusy = false; }
}

async function pollProgress() {
  if (document.hidden || jobsBusy || progressBusy) return;
  progressBusy=true;
  const version=jobsVersion;
  try {
    const progress=await api('progress');
    if (version!==jobsVersion) return;
    if (progress.jobs.length!==jobsCache.size || progress.jobs.some(job=>!jobsCache.has(job.id))) {
      await loadJobs();
      return;
    }
    let modelsChanged=false;
    for (const row of progress.jobs) {
      const previous=jobsCache.get(row.id), job={...previous,...row};
      const card=$('jobs').querySelector(`[data-job-card="${job.id}"]`);
      if (!card) continue;
      modelsChanged ||= previous.completed!==job.completed || previous.partial!==job.partial;
      card.querySelector('[data-count="prepared"]').textContent=`${number(job.prepared)} / ${number(job.total)}`;
      card.querySelector('[data-count="completed"]').textContent=completedLabel(job);
      card.querySelector('[data-count="remaining"]').textContent=remainingLabel(job);
      card.querySelector('[data-bar="prepared"]').value=job.prepared;
      card.querySelector('[data-bar="completed"]').value=job.completed;
      const status=card.querySelector('.status');
      status.textContent=LABELS[job.status] ?? job.status;
      status.className=`status ${job.status}`;
      if (previous.status!==job.status) card.querySelector('.job-actions').innerHTML=jobActions(job);
      jobsCache.set(job.id,job);
      if (inspectedJob?.id===job.id) inspectedJob={...inspectedJob,...row};
      if (activeJob===job.id) {
        const values={status:LABELS[job.status] ?? job.status,completed:completedLabel(job),failed:`${number(job.failed)} 棟`,skipped:`${number(job.skipped)} 棟`,updated_at:date(job.updated_at)};
        for (const [key,value] of Object.entries(values)) {
          const node=$('job-detail').querySelector(`[data-detail="${key}"]`);
          if (node) node.textContent=value;
        }
      }
    }
    const pending=progress.jobs.some(job=>['queued','preparing'].includes(job.status));
    message(`${progress.worker_running ? '背景轉換中' : pending ? '等待下一批自動處理' : '進度已更新'} · ${new Date(progress.checked_at).toLocaleTimeString('zh-TW',{hour12:false})} · 每 3 秒更新`,false,'progress-status');
    if (inspectedJob) updateControls();
    progressMapDirty ||= modelsChanged;
    if (progressMapDirty && !map?.vertices && Date.now()-progressMapAt>=15000) {
      progressMapDirty=false; progressMapAt=Date.now(); scheduleView();
    }
  } catch (error) {
    message(`進度更新暫時失敗，稍後自動重試：${error.message}`,true,'progress-status');
  } finally { progressBusy=false; }
}

async function showJob(id, locate = true) {
  const requestVersion=++jobRequestVersion;
  try {
    const job = await api('job', {params: {id}});
    if (requestVersion!==jobRequestVersion) return;
    if (locate && job.source_key && job.source_key !== sourceKey) {
      await changeSource(job.source_key, {locate: false});
      if (jobRequestVersion !== requestVersion + 1 || sourceKey !== job.source_key || !meta.source.ready) return;
    }
    activeJob = id;
    $('job-detail').hidden = false;
    inspectedItems = job.items ?? [];
    const rows = inspectedItems.slice(0, 100).map(item => `<tr><td>${item.model ? `<button type="button" class="text-button" data-building="${escape(item.build_id)}" title="定位並查看成果">${escape(item.build_id)}</button>` : escape(item.build_id)}</td><td>${LABELS[item.status] ?? escape(item.status)}</td></tr>`).join('');
    const firstModel = inspectedItems.find(item => item.model);
    $('job-detail').innerHTML = `<h3>${escape(job.name)}</h3><dl>
      <div><dt>任務狀態</dt><dd data-detail="status">${LABELS[job.status] ?? escape(job.status)}</dd></div>
      <div><dt>外觀風格</dt><dd>${escape(recipeLabel(job.recipe_key ?? 'texture-v1'))}</dd></div>
      <div><dt>範圍面積</dt><dd>${area(job.area_m2)}</dd></div>
      <div><dt>處理建物</dt><dd>${number(job.total)} 棟</dd></div>
      <div><dt>貼皮成果</dt><dd data-detail="completed">${completedLabel(job)}</dd></div>
      <div><dt>已有成果重用</dt><dd data-detail="skipped">${number(job.skipped)} 棟</dd></div>
      ${job.partial ? `<div><dt>部分貼皮</dt><dd>${number(job.partial)} 棟</dd></div>` : ''}
      <div><dt>失敗</dt><dd data-detail="failed">${number(job.failed)} 棟</dd></div>
      <div><dt>建立時間</dt><dd>${escape(date(job.created_at))}</dd></div>
      <div><dt>最後更新</dt><dd data-detail="updated_at">${escape(date(job.updated_at))}</dd></div>
      </dl>${job.error ? `<p class="inline-message error">${escape(job.error)}</p>` : ''}
      ${firstModel ? `<button type="button" class="outline full" data-building="${escape(firstModel.build_id)}">查看已貼圖建物</button>` : ''}
      <details><summary>建物清單${job.total > 100 ? '（顯示前 100 棟）' : ''}</summary><button type="button" class="text-button" data-refresh-job="${job.id}">更新建物清單</button><table><thead><tr><th>Build ID</th><th>狀態</th></tr></thead><tbody>${rows}</tbody></table></details>`;
    if (locate && mapReady) {
      if (map.vertices) cancelDraw();
      if (job.recipe_key && job.recipe_key!==recipeKey) {
        recipeKey=job.recipe_key; updateRecipeDescription();
        ++viewVersion; viewRequest?.abort(); ++selectionVersion; stopPreview(true);
        for (const current of Object.values(modes)) current.collection=null;
        map.setBuildings({type:'FeatureCollection',features:[]}); scheduleView();
      }
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
  const synthetic = model?.appearance_kind === 'procedural';
  const notices = qualityNotices(model);
  const correction = p.geometry_correction ?? model?.geometry_correction;
  const credits = (Array.isArray(model?.attribution) ? model.attribution : []).filter(a => a && /^\d+$/.test(a.image_id));
  $('building-detail').hidden = false;
  $('building-detail').innerHTML = `<h3>建物 ${escape(p.build_id)}</h3><dl>
    <div><dt>名稱</dt><dd>${escape(p.name || '—')}</dd></div><div><dt>高度</dt><dd>${p.height_missing ? '尚無有效高度' : `${escape(p.height)} m`}</dd></div>
    <div><dt>成果狀態</dt><dd>${partial ? '部分貼皮' : p.status === 'completed' ? '已有成果' : LABELS[p.status] ?? escape(p.status)}</dd></div>
    ${model?.surfaces_total ? `<div><dt>已貼面數</dt><dd>${number(model.surfaces_textured)} / ${number(model.surfaces_total)}</dd></div>` : ''}</dl>
    ${synthetic ? `<p class="hint">${escape(model.style_label ?? recipeLabel(model.style_key))} · 程序化示意立面。輪廓與高度來自建物資料；窗戶、樓層分割與配色為生成外觀。</p>` : partial ? '<p class="hint">部分立面已有真實影像，缺面仍為白模；匯出會保留缺面與影像來源記錄。</p>' : model ? '<p class="hint">切換 3D 可查看實際成果模型。</p>' : '<p class="hint">尚無此風格的成果。3D 模式顯示原始高度白模。</p>'}
    ${correction ? `<p class="hint">${escape(correction.name ?? '車站開口')}：${escape(correction.base_m)} 公尺示意淨空，非實測。開口以外保留落地量體。</p>` : ''}
    ${notices.length ? `<p class="inline-message error">試轉／待核對：${notices.map(escape).join('；')}。</p>` : ''}
    ${model?.asset_id ? `<a href="viewer.php?asset_id=${encodeURIComponent(model.asset_id)}" target="_blank" rel="noopener">獨立 GLB 預覽</a>` : ''}
    ${credits.length ? `<details><summary>Mapillary 影像來源 · CC BY-SA 4.0</summary>${credits.map(a => `<p><a href="https://www.mapillary.com/app/?pKey=${a.image_id}" target="_blank" rel="noopener">${escape(a.author?.username || a.image_id)}</a></p>`).join('')}<p>影像經立面投影與裁切。<a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">授權條款</a></p></details>` : ''}`;
}

$('job-detail').addEventListener('click', event => {
  const refresh=event.target.closest('[data-refresh-job]');
  if (refresh) return showJob(refresh.dataset.refreshJob,false);
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
  if (modes[mode].polygon && !modes[mode].collection) selectPolygon(modes[mode].polygon);
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
  if (threeD) scheduleView(); else stopPreview(true);
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
  if (sourceBusy || operationBusy || selectionBusy) return;
  if (button.dataset.job) return showJob(button.dataset.job);
  if (button.dataset.run) {
    operationBusy=true; button.disabled=true; updateControls();
    message('正在產生示意立面；完成後切換 3D 查看。');
    try {
      const stats=await api('run_batch',{body:{id:button.dataset.run,batch:10}});
      message(stats.locked ? '背景正在轉換，進度會自動更新。' : `本批完成 ${number(stats.completed)} 棟${stats.failed ? `，失敗 ${number(stats.failed)} 棟，請查看任務詳情` : ''}。剩餘建物將由背景排程接續處理。`,stats.failed>0);
    } catch (error) { message(error.message,true); }
    finally { operationBusy=false; updateControls(); await loadJobs({refreshMap:true}); }
    return;
  }
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
    message(`任務已建立：${number(job.total)} 棟，${recipeKey==='texture-v1' ? '背景排程會準備輸入，實景貼皮仍需 CLI 試轉' : '背景排程會自動轉換，進度每 3 秒更新；可以關閉頁面'}。`);
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

function renderSceneExport() {
  const context = sceneExport, job = context?.job;
  $('scene-export-result').hidden = !context;
  if (!context) return;
  $('scene-export-context').textContent = `${sourceLabel(context.sourceKey)} · ${recipeLabel(context.recipeKey)} · ${area(polygonArea(context.polygon))} · ${context.createdAt}`;
  $('scene-export-progress').value = job?.progress ?? 0;
  $('cancel-scene-export').hidden = !scenePending(job);
  $('cancel-scene-export').disabled = sceneExportBusy;
  const status = job?.status;
  message(job ? `${LABELS[status] ?? (status === 'running' ? '處理中' : status)} · ${job.progress}% · ${number(job.total)} 棟${job.error ? ` · ${job.error}` : scenePending(job) ? ' · 背景排程可能等候約一分鐘，可繼續操作地圖。' : ''}` : '正在建立整區匯出…', status === 'failed', 'scene-export-status');
  for (const [id, url] of [['scene-glb-download', job?.glb_url], ['scene-zip-download', job?.download_url]]) {
    $(id).hidden = status !== 'completed' || !url;
    if (url && status === 'completed') $(id).href = url;
    else $(id).removeAttribute('href');
  }
  updateControls();
}

$('create-scene-export').onclick = async () => {
  if ($('create-scene-export').disabled) return;
  const version = ++sceneExportVersion;
  scenePollRequest?.abort(); scenePollRequest = null;
  const context = {sourceKey, recipeKey, polygon: structuredClone(modes.export.polygon), createdAt: new Date().toLocaleString('zh-TW', {hour12: false}), job: null};
  sceneExport = context; sceneExportBusy = true; renderSceneExport();
  try {
    const job = await api('create_scene_export', {body: {polygon: context.polygon}, recipe: context.recipeKey, source: context.sourceKey});
    if (version !== sceneExportVersion || sceneExport !== context) return;
    context.job = job;
  } catch (error) {
    if (version === sceneExportVersion) context.job = {status: 'failed', progress: 0, total: 0, error: error.message};
  } finally {
    if (version === sceneExportVersion) { sceneExportBusy = false; renderSceneExport(); }
  }
};

async function pollSceneExport() {
  if (document.hidden || sceneExportBusy || scenePollRequest || !scenePending(sceneExport?.job)) return;
  const context = sceneExport, version = sceneExportVersion, request = new AbortController();
  scenePollRequest = request;
  try {
    const job = await api('scene_status', {params: {id: context.job.id}, recipe: context.recipeKey, source: context.sourceKey, signal: request.signal});
    if (request.signal.aborted || version !== sceneExportVersion || sceneExport !== context) return;
    context.job = job; renderSceneExport();
  } catch (error) {
    if (!request.signal.aborted && version === sceneExportVersion) message(`整區匯出進度稍後重試：${error.message}`, true, 'scene-export-status');
  } finally { if (scenePollRequest === request) scenePollRequest = null; }
}

$('cancel-scene-export').onclick = async () => {
  if (sceneExportBusy || !scenePending(sceneExport?.job)) return;
  const context = sceneExport, version = ++sceneExportVersion;
  scenePollRequest?.abort(); scenePollRequest = null; sceneExportBusy = true; renderSceneExport();
  try {
    const job = await api('cancel_scene_export', {body: {id: context.job.id}, recipe: context.recipeKey, source: context.sourceKey});
    if (version === sceneExportVersion && sceneExport === context) context.job = job;
  } catch (error) {
    if (version === sceneExportVersion) message(`取消失敗：${error.message}`, true, 'scene-export-status');
    return;
  } finally {
    if (version === sceneExportVersion) { sceneExportBusy = false; updateControls(); $('cancel-scene-export').disabled = false; }
  }
  if (version === sceneExportVersion) renderSceneExport();
};

async function start() {
  try {
    meta = await api('meta');
    sourceKey = meta.source_key ?? sourceKey;
    updateSourceDescription(); updateRecipeDescription();
    $('converter-note').textContent = '示意立面每分鐘自動轉換 · 實景貼皮沿用 CLI 試轉';
    loadJobs();
    setInterval(pollProgress,3000);
    setInterval(pollSceneExport,3000);
    document.addEventListener('visibilitychange',()=>{
      if (!document.hidden) { pollProgress(); pollSceneExport(); if (mapReady) scheduleView(); }
    });
    window.addEventListener('pagehide', () => { viewRequest?.abort(); stopPreview(); scenePollRequest?.abort(); clearTimeout(viewTimer); });
    map = new MapWorkspace({onMove: scheduleView, onBuilding: showBuilding, onDraw: count => { $('finish').disabled = count < 3; }, onFinish: finishDraw, onError: text => message(text, true, 'map-message'), onModels: ({loaded, total, available}) => {
      $('model-progress').textContent = map.threeD && available ? `近處貼皮 ${loaded} / ${total} 棟${available > total ? ' · 移動或放大查看其他建物' : ''}` : '';
    }});
    await map.init(meta.center, meta.zoom);
    mapReady = true;
    changeView(true);
    $('map-loading').hidden = true;
    updateControls(); scheduleView();
    if (!meta.source.ready) message(meta.source.error ?? '尚未設定建物資料來源。', true, 'map-message');
    pollProgress();
  } catch (error) {
    $('map-loading').textContent = error.message;
    message(error.message, true);
  }
}
start();
