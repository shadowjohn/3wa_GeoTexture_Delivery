<?php
declare(strict_types=1);

use GeoTexture\Auth;
use function GeoTexture\appConfig;

require_once __DIR__ . '/../src/bootstrap.php';
Auth::startSession();
$auth = new Auth(appConfig()['state_dir']);
header('Cache-Control: no-store');
if ($auth->currentUser() !== null) { header('Location: index.php', true, 302); exit; }

$error = '';
$username = '';
$post = static fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $post('username');
    if (!Auth::checkCsrf($post('csrf_token'))) {
        http_response_code(403);
        $error = '登入表單已失效，請重新輸入驗證碼後再試。';
    } elseif ($auth->login($username, $post('password'), $post('captcha'))) {
        header('Location: index.php', true, 303);
        exit;
    } else {
        http_response_code(401);
        $error = '登入失敗，請確認帳號、密碼與驗證碼。連續失敗過多時，請於 5 分鐘後再試。';
    }
}
$csrf = Auth::csrfToken();
session_write_close();
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>登入 · GeoTexture Delivery</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <a class="auth-brand" href="login.php"><span class="auth-mark" aria-hidden="true">G</span><span>GeoTexture Delivery<small>建物貼皮交付工作站</small></span></a>
    <h1>登入工作站</h1>
    <p class="auth-intro">登入後即可管理處理範圍、建物任務與成果。</p>
    <?php if ($error !== ''): ?><p class="auth-alert error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
        <div class="auth-field"><label for="username">帳號</label><input id="username" name="username" type="text" required maxlength="100" autocomplete="username" autocapitalize="none" value="<?= $escape($username) ?>" autofocus></div>
        <div class="auth-field"><label for="password">密碼</label><input id="password" name="password" type="password" required maxlength="72" autocomplete="current-password"></div>
        <div class="auth-field"><label for="captcha">圖片驗證碼 <span>不分大小寫</span></label><div class="captcha-row"><input id="captcha" name="captcha" type="text" required maxlength="6" minlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="輸入 6 碼"><button class="captcha-button" type="button" id="refresh-captcha" title="點擊更換驗證碼" aria-label="更換圖片驗證碼"><img id="captcha-image" src="captcha.php" width="190" height="60" alt="圖片驗證碼，點擊可換圖"></button></div><p class="auth-help">看不清楚？點擊圖片換一張。</p></div>
        <button class="primary full" type="submit">登入</button>
    </form>
    <p class="auth-footnote">初次啟用帳號與密碼皆為 <strong>admin</strong>，登入後可於帳號設定修改密碼。</p>
</main>
<script>
document.getElementById('refresh-captcha').addEventListener('click', function () {
    document.getElementById('captcha-image').src = 'captcha.php?t=' + Date.now();
    document.getElementById('captcha').value = '';
    document.getElementById('captcha').focus();
});
</script>
</body>
</html>
