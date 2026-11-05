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
    $postActions = ['selection','create_job','cancel_job','retry_job','export'];
    $getActions = ['meta','buildings','jobs','job','model'];
    if (!is_string($action) || !in_array($action,array_merge($postActions,$getActions),true)) { throw new AppException('找不到 API action',404); }
    $expected = in_array($action,$postActions,true) ? 'POST' : 'GET';
    if ($method!==$expected) { header('Allow: '.$expected); throw new AppException('此 action 必須使用 '.$expected,405); }
    $input = [];
    if ($method==='POST') {
        if (in_array($action,['create_job','cancel_job','retry_job'],true)) {
            $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!is_string($provided) || !hash_equals($csrfToken,$provided)) { throw new AppException('CSRF token 無效，請重新載入頁面',403); }
        }
        $raw=file_get_contents('php://input',false,null,0,1048577);
        if ($raw===false || strlen($raw)>1048576) { throw new AppException('請求內容過大',413); }
        try { $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AppException('請提供有效的 JSON object',400); }
        if (!is_array($input) || array_is_list($input)) { throw new AppException('請提供 JSON object',400); }
    }
    $app=geoTextureApp();
    $polygon=static function () use ($input): array {
        if (!isset($input['polygon']) || !is_array($input['polygon'])) { throw new InvalidArgumentException('缺少 GeoJSON Polygon'); }
        return $input['polygon'];
    };
    $id=static function () use ($input,$method): string {
        $id=$method==='GET' ? ($_GET['id'] ?? null) : ($input['id'] ?? null);
        if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D',$id)) { throw new InvalidArgumentException('任務 id 無效'); }
        return $id;
    };
    switch ($action) {
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
            respondJson(['data'=>$app->buildings(array_map('floatval',$parts),$limit)]);
        case 'selection': respondJson(['data'=>$app->selection($polygon())]);
        case 'jobs': respondJson(['data'=>$app->jobs()]);
        case 'job': respondJson(['data'=>$app->job($id())]);
        case 'create_job':
            if (!isset($input['name']) || !is_string($input['name'])) { throw new InvalidArgumentException('任務名稱必須是字串'); }
            if (isset($input['force']) && !is_bool($input['force'])) { throw new InvalidArgumentException('force 必須是 boolean'); }
            respondJson(['data'=>$app->createJob($input['name'],$polygon(),$input['force'] ?? false)],201);
        case 'cancel_job': respondJson(['data'=>$app->cancelJob($id())]);
        case 'retry_job': respondJson(['data'=>$app->retryJob($id())]);
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
            header('Content-Type: model/gltf-binary');
            header('Content-Disposition: inline; filename="'.$file['name'].'"');
            header('Content-Length: '.filesize($file['path']));
            header('ETag: "'.$file['sha256'].'"');
            readfile($file['path']);
            exit;
    }
} catch (AppException $e) { respondJson(['error'=>$e->getMessage()],$e->httpStatus); }
catch (InvalidArgumentException $e) { respondJson(['error'=>$e->getMessage()],422); }
catch (Throwable $e) {
    error_log('[GeoTexture API] '.$e);
    respondJson(['error'=>'服務處理失敗，請檢查 PHP 錯誤紀錄。'],500);
}
