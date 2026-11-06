<?php
declare(strict_types=1);

use GeoTexture\AppException;
use GeoTexture\Auth;

require_once __DIR__ . '/../src/bootstrap.php';

ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respondJson(array $payload, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}

try {
    Auth::startSession();
    $auth = new Auth(\GeoTexture\appConfig()['state_dir']);
    if ($auth->currentUser() === null) { throw new AppException('請先登入後台',401); }
    $csrfToken = Auth::csrfToken();
    session_write_close();
    $action = $_GET['action'] ?? 'meta';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $postActions = ['selection','create_job','cancel_job','retry_job','export','run_batch','preview_scenes','create_scene_export','cancel_scene_export'];
    $getActions = ['meta','buildings','jobs','job','model','progress','scene_status','scene_file'];
    if (!is_string($action) || !in_array($action,array_merge($postActions,$getActions),true)) { throw new AppException('找不到 API action',404); }
    $expected = in_array($action,$postActions,true) ? 'POST' : 'GET';
    if ($method!==$expected) { header('Allow: '.$expected); throw new AppException('此 action 必須使用 '.$expected,405); }
    $input = [];
    if ($method==='POST') {
        if (in_array($action,['create_job','cancel_job','retry_job','run_batch','preview_scenes','create_scene_export','cancel_scene_export'],true)) {
            $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!is_string($provided) || !hash_equals($csrfToken,$provided)) { throw new AppException('CSRF token 無效，請重新載入頁面',403); }
        }
        $raw=file_get_contents('php://input',false,null,0,1048577);
        if ($raw===false || strlen($raw)>1048576) { throw new AppException('請求內容過大',413); }
        try { $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AppException('請提供有效的 JSON object',400); }
        if (!is_array($input) || array_is_list($input)) { throw new AppException('請提供 JSON object',400); }
    }
    $recipe=$input['recipe_key'] ?? $_GET['recipe_key'] ?? \GeoTexture\appConfig()['recipe_key'];
    if (!is_string($recipe)) { throw new InvalidArgumentException('外觀風格必須是字串'); }
    $source=$input['source_key'] ?? $_GET['source_key'] ?? \GeoTexture\BuildingSource::SOURCE_KEY;
    if(!is_string($source) || !isset(\GeoTexture\appConfig()['sources'][$source])) { throw new InvalidArgumentException('未知建物來源'); }
    $app=\GeoTexture\createApp(['recipe_key'=>\GeoTexture\Recipes::validate($recipe),'source_key'=>$source]);
    $polygon=static function () use ($input): array {
        if (!isset($input['polygon']) || !is_array($input['polygon'])) { throw new InvalidArgumentException('缺少 GeoJSON Polygon'); }
        return $input['polygon'];
    };
    $id=static function () use ($input,$method): string {
        $id=$method==='GET' ? ($_GET['id'] ?? null) : ($input['id'] ?? null);
        if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D',$id)) { throw new InvalidArgumentException('任務 id 無效'); }
        return $id;
    };
    $sceneId=static function() use($input,$method): string {
        $value=$method==='GET'?($_GET['id'] ?? null):($input['id'] ?? null);
        if(!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)){throw new InvalidArgumentException('區塊 id 無效');}return $value;
    };
    switch ($action) {
        case 'preview_scenes':
            if(!is_array($input['bbox'] ?? null)){throw new InvalidArgumentException('缺少 bbox');}
            respondJson(['data'=>$app->previewScenes($input['bbox'])]);
        case 'create_scene_export': respondJson(['data'=>$app->createSceneExport($polygon())],201);
        case 'cancel_scene_export': respondJson(['data'=>$app->cancelSceneExport($sceneId())]);
        case 'scene_status': respondJson(['data'=>$app->sceneStatus($sceneId())]);
        case 'scene_file':
            $format=$_GET['format'] ?? 'glb';if(!is_string($format)){throw new InvalidArgumentException('格式無效');}
            $file=$app->sceneFile($sceneId(),$format);$etag='"'.$file['sha256'].'"';
            header('Cache-Control: private, no-cache');header('ETag: '.$etag);
            if(trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')===$etag){http_response_code(304);exit;}
            header('Content-Type: '.($format==='zip'?'application/zip':'model/gltf-binary'));
            header('Content-Disposition: '.($format==='zip'?'attachment':'inline').'; filename="'.$file['name'].'"');
            header('Content-Length: '.filesize($file['path']));readfile($file['path']);exit;
        case 'meta':
            $meta=$app->meta(); $meta['csrf_token']=$csrfToken;
            respondJson(['data'=>$meta]);
        case 'buildings':
            if (!isset($_GET['bbox']) || !is_string($_GET['bbox'])) { throw new InvalidArgumentException('缺少 bbox=west,south,east,north'); }
            $parts=explode(',',$_GET['bbox']);
            if (count($parts)!==4) { throw new InvalidArgumentException('bbox 必須含四個數值'); }
            foreach ($parts as $part) { if (!is_numeric($part) || !is_finite((float)$part)) { throw new InvalidArgumentException('bbox 含無效數值'); } }
            $limit=filter_var($_GET['limit'] ?? '2000',FILTER_VALIDATE_INT);
            if ($limit===false) { throw new InvalidArgumentException('limit 必須為整數'); }
            respondJson(['data'=>$app->buildings(array_map('floatval',$parts),$limit,($_GET['detail'] ?? '')==='preview')]);
        case 'selection': respondJson(['data'=>$app->selection($polygon())]);
        case 'jobs': respondJson(['data'=>$app->jobs()]);
        case 'progress': respondJson(['data'=>$app->progress()]);
        case 'job': respondJson(['data'=>$app->job($id())]);
        case 'create_job':
            if (!isset($input['name']) || !is_string($input['name'])) { throw new InvalidArgumentException('任務名稱必須是字串'); }
            if (isset($input['force']) && !is_bool($input['force'])) { throw new InvalidArgumentException('force 必須是 boolean'); }
            respondJson(['data'=>$app->createJob($input['name'],$polygon(),$input['force'] ?? false)],201);
        case 'cancel_job': respondJson(['data'=>$app->cancelJob($id())]);
        case 'retry_job': respondJson(['data'=>$app->retryJob($id())]);
        case 'run_batch':
            $batch=$input['batch'] ?? 10;
            if (!is_int($batch) || $batch<1 || $batch>10) { throw new InvalidArgumentException('網頁每批可產生 1 至 10 棟'); }
            $jobId=$id();
            $job=$app->job($jobId,false);
            if (!\GeoTexture\Recipes::procedural($job['recipe_key'])) { throw new InvalidArgumentException('實景任務請使用原有 CLI 試轉流程'); }
            respondJson(['data'=>$app->runBatch($batch,$jobId,15)]);
        case 'export':
            $path=$app->export($polygon());
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="geotexture-'.gmdate('Ymd-His').'.zip"');
            header('Content-Length: '.filesize($path));
            try { readfile($path); } finally { unlink($path); }
            exit;
        case 'model':
            if (!isset($_GET['asset_id']) || !is_string($_GET['asset_id'])) { throw new InvalidArgumentException('缺少 asset_id'); }
            $file=$app->modelFile($_GET['asset_id']);
            $etag='"'.$file['sha256'].'"';
            // 私人瀏覽器快取每次仍須通過登入與檔案驗證；相同模型只回 304。
            header('Cache-Control: private, no-cache');
            header('ETag: '.$etag);
            if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')===$etag) { http_response_code(304); exit; }
            header('Content-Type: model/gltf-binary');
            header('Content-Disposition: inline; filename="'.$file['name'].'"');
            header('Content-Length: '.filesize($file['path']));
            readfile($file['path']);
            exit;
    }
} catch (AppException $e) { respondJson(['error'=>$e->getMessage()],$e->httpStatus); }
catch (InvalidArgumentException $e) { respondJson(['error'=>$e->getMessage()],422); }
catch (Throwable $e) {
    error_log('[GeoTexture API] '.$e);
    respondJson(['error'=>'服務處理失敗，請檢查 PHP 錯誤紀錄。'],500);
}
