<?php
declare(strict_types=1);

use GeoTexture\Auth;

require_once __DIR__ . '/../src/bootstrap.php';

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function rejects(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException | RuntimeException $e) {
        check($e->getMessage() !== '', $message);
        return;
    }
    throw new RuntimeException($message);
}
function removeTree(string $path): void {
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) { continue; }
        if ($item->isDir()) { removeTree($item->getPathname()); }
        else { unlink($item->getPathname()); }
    }
    rmdir($path);
}

$tmp = sys_get_temp_dir() . '/geotexture-auth-' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
try {
    check(class_exists(Auth::class), 'Auth 登入服務尚未實作');
    Auth::startSession();
    $options = session_get_cookie_params();
    check($options['httponly'] && $options['samesite'] === 'Lax', 'Session cookie 必須限制腳本讀取與跨站送出');
    $auth = new Auth($tmp);
    check($auth->currentUser() === null, '未登入不可取得使用者');
    $token = Auth::csrfToken();
    check(strlen($token) >= 32 && Auth::checkCsrf($token), '有效 CSRF token 可驗證');
    check(!Auth::checkCsrf(null) && !Auth::checkCsrf('wrong'), '缺少或偽造 CSRF token 應拒絕');
    check(!$auth->login('admin', 'admin', 'wrong'), '缺少正確驗證碼不可登入');
    $captcha = Auth::generateCaptcha();
    check(!$auth->login('admin', 'wrong', $captcha), '正確驗證碼仍不可登入錯誤密碼');
    check(!$auth->login('admin', 'admin', $captcha), '使用過的驗證碼不可重放');
    $oldSession = session_id();
    check($auth->login('admin', 'admin', strtolower(Auth::generateCaptcha())), '初始 admin/admin 可搭配不區分大小寫驗證碼登入');
    check(session_id() !== $oldSession, '登入成功必須更新 session ID');
    check($auth->currentUser()['username'] === 'admin', '登入後可取得帳號');
    $db = new PDO('sqlite:' . $tmp . '/app.sqlite');
    if (PHP_OS_FAMILY!=='Windows') {
        clearstatcache(true,$tmp.'/app.sqlite');
        check((fileperms($tmp.'/app.sqlite') & 0777)===0660, '帳號庫需允許共用群組寫入且禁止其他使用者讀取');
    }
    $hash = $db->query("SELECT password_hash FROM users WHERE username='admin'")->fetchColumn();
    check($hash !== 'admin' && password_verify('admin', $hash), '資料庫只能保存密碼雜湊');
    rejects(fn() => $auth->changePassword('wrong', 'Changed123!', 'Changed123!'), '改密碼必須核對目前密碼');
    rejects(fn() => $auth->changePassword('admin', 'short', 'short'), '新密碼不可少於八字');
    rejects(fn() => $auth->changePassword('admin', 'Changed123!', 'different'), '新密碼與確認必須相同');
    $previousLogin = $_SESSION;
    $auth->changePassword('admin', 'Changed123!', 'Changed123!');
    check($auth->currentUser()['username'] === 'admin', '改密碼後維持目前登入');
    $currentLogin = $_SESSION;
    $_SESSION = $previousLogin;
    check($auth->currentUser() === null, '改密碼後其他舊登入必須立即失效');
    $_SESSION = $currentLogin;
    unset($_SESSION['auth_password_version']);
    check($auth->currentUser() === null, '舊版未綁定密碼版本的登入必須重新認證');
    $_SESSION = $currentLogin;
    $_SESSION['auth_started_at'] = time() - 43201;
    check($auth->currentUser() === null, '登入超過十二小時必須重新認證');
    $_SESSION = $currentLogin;
    rejects(fn() => $auth->changePassword('Changed123!', "HasNull\0Password", "HasNull\0Password"), '密碼空字元需拒絕');
    $auth->logout();
    check($auth->currentUser() === null && !Auth::checkCsrf($token), '登出清除登入狀態及原 CSRF token');
    Auth::startSession();
    $auth = new Auth($tmp);
    check(!$auth->login('admin', 'admin', Auth::generateCaptcha()), '重新初始化不可還原預設密碼');
    check($auth->login('admin', 'Changed123!', Auth::generateCaptcha()), '變更後密碼跨連線持久化');
    $auth->logout();
    Auth::startSession();
    rejects(fn() => $auth->changePassword('Changed123!', 'Another123!', 'Another123!'), '未登入不可修改密碼');
    $captcha = Auth::generateCaptcha();
    $_SESSION['captcha_expires_at'] = time() - 1;
    check(!$auth->login('admin', 'Changed123!', $captcha), '過期驗證碼不可登入');
    $png = Auth::captchaPng();
    $info = getimagesizefromstring($png);
    check($info !== false && $info['mime'] === 'image/png' && $info[0] >= 150 && $info[1] >= 50, 'GD 產生實際且可讀的 PNG 驗證碼');
    $db->exec('DELETE FROM login_attempts');
    for ($i = 0; $i < 10; $i++) { $auth->login('admin', 'wrong', Auth::generateCaptcha()); }
    check(!$auth->login('admin', 'Changed123!', Auth::generateCaptcha()), '連續失敗達上限後暫時限制登入');
    $db->exec('DELETE FROM login_attempts');
    check($auth->login('admin', 'Changed123!', Auth::generateCaptcha()), '限制解除後仍可正常登入');
    $emptyDir = $tmp . '/existing';
    mkdir($emptyDir);
    $existing = new PDO('sqlite:' . $emptyDir . '/app.sqlite');
    $existing->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT UNIQUE NOT NULL,password_hash TEXT NOT NULL)');
    $untouched = new Auth($emptyDir);
    check((int)$existing->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, '既有 users 表不可擅自新增預設帳號');
    unset($untouched, $existing);
    echo "auth: $checks checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    unset($auth, $db);
    removeTree($tmp);
}
