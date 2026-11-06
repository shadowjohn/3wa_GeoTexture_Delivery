<?php
declare(strict_types=1);

use GeoTexture\Auth;

require_once __DIR__ . '/../src/bootstrap.php';
Auth::startSession();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
try {
    $png = Auth::captchaPng();
    session_write_close();
    header('Content-Type: image/png');
    echo $png;
} catch (Throwable $error) {
    session_write_close();
    error_log('GeoTexture captcha: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '驗證碼暫時無法產生，請稍後重試。';
}
