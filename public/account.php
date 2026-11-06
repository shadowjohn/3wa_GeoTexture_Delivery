<?php
declare(strict_types=1);

use GeoTexture\Auth;
use function GeoTexture\appConfig;

require_once __DIR__ . '/../src/bootstrap.php';
Auth::startSession();
$auth = new Auth(appConfig()['state_dir']);
$user = $auth->requireLogin();
header('Cache-Control: no-store');
$error = '';
$success = !empty($_SESSION['password_changed']);
unset($_SESSION['password_changed']);
$post = static fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($post('csrf_token'))) {
        http_response_code(403);
        $error = '表單已失效，請重新輸入後再試。';
    } else {
        try {
            $auth->changePassword($post('current_password'), $post('new_password'), $post('confirm_password'));
            $_SESSION['password_changed'] = true;
            header('Location: account.php', true, 303);
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $error = $exception->getMessage();
        }
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
    <title>帳號設定 · GeoTexture Delivery</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <a class="auth-brand" href="index.php"><span class="auth-mark" aria-hidden="true">G</span><span>GeoTexture Delivery<small>建物貼皮交付工作站</small></span></a>
    <div class="auth-title-row"><h1>帳號設定</h1><a href="index.php">返回工作站</a></div>
    <p class="auth-intro">目前登入：<strong><?= $escape($user['username']) ?></strong></p>
    <?php if ($success): ?><p class="auth-alert success" role="status">密碼已更新，下次登入請使用新密碼。</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="auth-alert error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="account.php">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="username" autocomplete="username" value="<?= $escape($user['username']) ?>">
        <div class="auth-field"><label for="current_password">目前密碼</label><input id="current_password" name="current_password" type="password" required maxlength="72" autocomplete="current-password" autofocus></div>
        <div class="auth-field"><label for="new_password">新密碼</label><input id="new_password" name="new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><p class="auth-help">至少 8 個字元，最多 72 位元組。</p></div>
        <div class="auth-field"><label for="confirm_password">確認新密碼</label><input id="confirm_password" name="confirm_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
        <button class="primary full" type="submit">更新密碼</button>
    </form>
    <form class="auth-logout" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>"><button class="text-button" type="submit">登出目前帳號</button></form>
</main>
</body>
</html>
