<?php
declare(strict_types=1);

use GeoTexture\Auth;
use function GeoTexture\appConfig;

require_once __DIR__ . '/../src/bootstrap.php';
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '請使用頁面上的登出按鈕。';
    exit;
}
Auth::startSession();
$token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
if (!Auth::checkCsrf($token)) {
    http_response_code(403);
    echo '登出表單已失效，請返回工作站重新操作。';
    exit;
}
$auth = new Auth(appConfig()['state_dir']);
$auth->logout();
header('Location: login.php', true, 303);
