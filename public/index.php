<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
$auth = new \GeoTexture\Auth(\GeoTexture\appConfig()['state_dir']);
$user = $auth->requireLogin();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GeoTexture Delivery</title>
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="https://3wa.tw/inc/javascript/easymap8/vendor/maplibre/maplibre-gl.css">
  <link rel="stylesheet" href="assets/app.css?v=atlas-scene-1">
</head>
<body>
  <header class="header">
    <h1>GeoTexture Delivery</h1>
    <div class="header-actions">
      <span id="source-label">新北市 · 淡江大橋</span>
      <a href="account.php">帳號設定</a>
      <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\GeoTexture\Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="text-button">登出</button></form>
    </div>
  </header>
  <main class="workspace">
    <aside class="sidebar" aria-label="範圍與任務管理">
      <nav class="tabs" aria-label="工作模式">
        <button type="button" id="task-tab" class="active" aria-pressed="true">處理任務</button>
        <button type="button" id="export-tab" aria-pressed="false">成果匯出</button>
      </nav>
      <section class="style-panel" aria-label="建物外觀風格">
        <label for="source-key">建物來源</label>
        <select id="source-key" disabled><option value="new-taipei-8418">新北市</option></select>
        <div id="station-control" hidden>
          <label for="station-location">車站開口檢視</label>
          <select id="station-location">
            <option value="">選擇車站…</option>
            <option value="120.648087,24.1191754">大慶</option>
            <option value="120.6401092,24.1144603">九張犁</option>
            <option value="120.6342882,24.1110577">九德</option>
            <option value="120.6248737,24.1086584">烏日</option>
            <option value="120.6161284,24.1115634">高鐵臺中站</option>
          </select>
        </div>
        <label for="recipe-key">建物外觀</label>
        <select id="recipe-key">
          <?php foreach (\GeoTexture\Recipes::all() as $key=>$recipe): ?>
          <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($recipe['label'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <p id="recipe-description" class="hint">磁磚牆、窗框與樓層線，依真實輪廓產生示意立面。</p>
        <label class="checkbox"><input type="checkbox" id="preview-chunks" checked disabled>合併街廓預覽（減少模型載入數）</label>
        <p id="preview-status" class="hint" role="status"></p>
        <span id="appearance-badge" class="appearance-badge">示意立面 · 非實景照片</span>
      </section>
      <section class="selection-panel">
        <h2 id="mode-title">建立處理範圍</h2>
        <p class="hint" id="mode-hint">在地圖上畫 Polygon，選取要製作貼圖的建物。</p>
        <button type="button" id="draw" class="outline full" disabled>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 18 4-13 11 5-3 10Z"/><circle cx="5" cy="18" r="2"/><circle cx="9" cy="5" r="2"/><circle cx="20" cy="10" r="2"/><circle cx="17" cy="20" r="2"/></svg>
          <span>繪製 Polygon</span>
        </button>
        <div class="draw-actions" id="draw-actions" hidden>
          <button type="button" id="finish" class="primary" disabled>完成範圍</button>
          <button type="button" id="undo">退一步</button>
          <button type="button" id="cancel-draw">取消</button>
        </div>
        <p id="draw-hint" class="hint" hidden>單擊加點，移動游標可預覽範圍；按「完成範圍」或雙擊結束。Esc 取消。</p>
        <dl class="range-summary">
          <div><dt>建物</dt><dd id="selection-count">尚未框選</dd></div>
          <div><dt>面積</dt><dd id="selection-area">—</dd></div>
          <div id="selection-ready-row" hidden><dt>貼皮成果</dt><dd id="selection-ready">—</dd></div>
        </dl>
        <button type="button" id="resume-selection" class="outline full" hidden>返回目前框選</button>
        <form id="task-form">
          <label for="task-name">任務名稱</label>
          <input id="task-name" name="name" value="淡江大橋周邊" maxlength="100" required>
          <label class="checkbox"><input type="checkbox" id="force">強制重做已有成果的建物</label>
          <button type="submit" id="create-job" class="primary full" disabled>建立任務</button>
        </form>
        <div id="export-panel" hidden>
          <p class="hint">匯出範圍獨立保存，可重新框選。ZIP 包含建物 GeoJSON、範圍、成果與缺漏清單。</p>
          <button type="button" id="create-scene-export" class="primary full" disabled>建立整區 GLB</button>
          <p class="hint">最多 2,000 棟、支援三種示意風格；合併為一個 GLB，每棟保留獨立 Build ID 節點。僅包含建立匯出時已有的成果。</p>
          <div id="scene-export-result" hidden>
            <p id="scene-export-context" class="hint"></p>
            <progress id="scene-export-progress" max="100" value="0" aria-label="整區匯出進度"></progress>
            <p id="scene-export-status" class="inline-message" role="status"></p>
            <div class="scene-actions">
              <button type="button" id="cancel-scene-export" class="text-button" hidden>取消整區匯出</button>
              <a id="scene-glb-download" download hidden>下載整區 GLB</a>
              <a id="scene-zip-download" download hidden>下載完整 ZIP</a>
            </div>
          </div>
          <button type="button" id="download" class="primary full" disabled>下載範圍成果 ZIP</button>
          <p class="hint">匯出目前所選風格的模型。示意立面保留風格標示；實景成果保留缺面與影像來源記錄。</p>
        </div>
        <p id="selection-message" class="inline-message" role="status"></p>
      </section>
      <section class="jobs-panel" aria-labelledby="jobs-title">
        <div class="section-heading"><h2 id="jobs-title">任務紀錄</h2><button type="button" id="refresh-jobs" class="text-button">重新整理</button></div>
        <p id="progress-status" class="hint">進度每 3 秒自動更新。</p>
        <div id="jobs"><p class="hint">讀取任務中…</p></div>
      </section>
      <section id="job-detail" class="detail-panel" hidden aria-label="任務詳情"></section>
      <section id="building-detail" class="detail-panel" hidden aria-label="建物資訊"></section>
      <p class="converter-note" id="converter-note">正在確認轉換服務…</p>
    </aside>
    <section class="map-panel" aria-label="E8 建物圖台">
      <div id="map"></div>
      <div class="map-toolbar" aria-label="地圖工具">
        <div class="view-buttons"><button type="button" id="view-2d" aria-pressed="false">2D</button><button type="button" id="view-3d" class="active" aria-pressed="true" title="先顯示白模，再由近到遠載入貼皮；處理狀態以屋頂輪廓標示">3D</button></div>
        <button type="button" id="home">回到來源中心</button>
      </div>
      <div id="map-loading" class="map-notice" role="status">正在載入 E8 圖台…</div>
      <div id="map-message" class="map-message" role="status" hidden></div>
      <footer class="map-footer">
        <div class="legend" aria-label="建物處理狀態">
          <span><i class="unprocessed"></i>未處理</span>
          <span><i class="queued"></i>排隊中</span>
          <span><i class="failed"></i>處理失敗</span>
          <span><i class="completed"></i>已有成果</span>
        </div>
        <div><span id="visible-count" class="hint">建物依目前視野載入</span><div id="model-progress" class="hint" role="status"></div></div>
      </footer>
    </section>
  </main>
  <script src="https://3wa.tw/inc/javascript/easymap8/easymap.js"></script>
  <script type="module" src="assets/app.mjs?v=viewport-residency-1"></script>
</body>
</html>
