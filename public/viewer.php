<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
$auth = new \GeoTexture\Auth(\GeoTexture\appConfig()['state_dir']);
$auth->requireLogin();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$assetId = $_GET['asset_id'] ?? '';
if (!is_string($assetId) || ($assetId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $assetId))) {
    http_response_code(400);
    exit('模型識別碼無效。');
}
$assetUrl = $assetId === '' ? '' : 'api.php?action=model&asset_id=' . $assetId;
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>GLB 預覽 · GeoTexture Delivery</title>
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    body{height:100dvh;display:flex;flex-direction:column}.header a{color:var(--teal);font-size:13px;text-decoration:none}.viewer-tools{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 20px;background:#fff;border-bottom:1px solid var(--line)}.viewer-tools input{font-size:12px;max-width:260px}.viewer-tools label{margin:0;font-size:13px}.viewer-tools a{font-size:13px;color:var(--teal)}#viewer-stage{position:relative;flex:1;min-height:250px;background:#eef2f4}#viewer-stage canvas{width:100%;height:100%;display:block;touch-action:none}.viewer-footer{background:#fff;border-top:1px solid var(--line);padding:10px 20px;font-size:12px;color:var(--muted)}#viewer-message{margin:0}#viewer-message.error{color:var(--red)}@media(max-width:680px){.header h1{font-size:17px}.viewer-tools{gap:8px;padding:10px 12px}.viewer-tools input{max-width:210px}.viewer-tools button{font-size:12px}.viewer-footer{padding:10px 12px}}
  </style>
  <script type="importmap">{"imports":{"three":"https://3wa.tw/inc/javascript/easymap8/vendor/three/three.module.min.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.178.0/examples/jsm/"}}</script>
</head>
<body>
  <header class="header"><h1>GeoTexture · GLB 預覽</h1><a href="index.php">返回圖台</a></header>
  <div class="viewer-tools">
    <label for="local-model">開啟匯出的 GLB</label><input id="local-model" type="file" accept=".glb,model/gltf-binary">
    <button type="button" id="fit-model" disabled>完整模型</button>
    <button type="button" id="rotate-model" disabled>旋轉 90°</button>
    <?php if ($assetUrl !== ''): ?><a id="download-model" href="<?= htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') ?>" download>下載 GLB</a><?php endif; ?>
  </div>
  <main id="viewer-stage" data-asset-url="<?= htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="獨立 GLB 模型預覽"></main>
  <footer class="viewer-footer"><p id="viewer-message" role="status">載入預覽器中…</p><p id="viewer-quality" class="inline-message error" hidden></p><div id="viewer-credits"></div></footer>
  <script type="module" src="assets/viewer.mjs"></script>
</body>
</html>
