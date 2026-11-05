<?php
declare(strict_types=1);

namespace GeoTexture;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Auth {
    private PDO $db;

    public function __construct(string $stateDir) {
        if (!is_dir($stateDir) && !mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
            throw new RuntimeException('無法建立登入資料目錄。');
        }
        $this->db = new PDO('sqlite:' . rtrim($stateDir, '/\\') . '/app.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        // 初始帳號只隨新 users 表建立，避免重啟時覆蓋既有帳號或密碼。
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $exists = $this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
            if (!$exists) {
                $this->db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL)');
                $statement = $this->db->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');
                $statement->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
            }
            $this->db->exec('CREATE TABLE IF NOT EXISTS login_attempts(id INTEGER PRIMARY KEY,client_key TEXT NOT NULL,failed_at INTEGER NOT NULL)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_client_time ON login_attempts(client_key,failed_at)');
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('geotexture_session');
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) { throw new RuntimeException('無法啟動登入工作階段。'); }
    }

    public static function csrfToken(): string {
        self::startSession();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrf(?string $token): bool {
        self::startSession();
        return is_string($token) && $token !== '' && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function currentUser(): ?array {
        self::startSession();
        $id = $_SESSION['auth_user_id'] ?? null;
        if (!is_int($id) || $id < 1) { return null; }
        $statement = $this->db->prepare('SELECT id,username FROM users WHERE id=?');
        $statement->execute([$id]);
        $user = $statement->fetch();
        return $user === false ? null : ['id' => (int)$user['id'], 'username' => $user['username']];
    }

    public function requireLogin(): array {
        $user = $this->currentUser();
        if ($user !== null) { return $user; }
        header('Location: login.php', true, 302);
        exit;
    }

    public static function generateCaptcha(): string {
        self::startSession();
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $answer = '';
        for ($i = 0; $i < 6; $i++) { $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $_SESSION['captcha_hash'] = hash('sha256', $answer);
        $_SESSION['captcha_expires_at'] = time() + 300;
        return $answer;
    }

    public static function captchaPng(): string {
        if (!extension_loaded('gd')) { throw new RuntimeException('圖片驗證碼需要 PHP GD 擴充套件。'); }
        $answer = self::generateCaptcha();
        $image = imagecreatetruecolor(190, 60);
        $background = imagecolorallocate($image, 245, 251, 251);
        $noise = imagecolorallocate($image, 167, 206, 208);
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 8; $i++) {
            imageline($image, random_int(0, 189), random_int(0, 59), random_int(0, 189), random_int(0, 59), $noise);
        }
        for ($i = 0; $i < strlen($answer); $i++) {
            $letter = imagecreatetruecolor(9, 15);
            imagefill($letter, 0, 0, imagecolorallocate($letter, 245, 251, 251));
            imagechar($letter, 5, 0, 0, $answer[$i], imagecolorallocate($letter, 5, 89, 96));
            imagecopyresampled($image, $letter, 14 + $i * 27, random_int(12, 18), 0, 0, 18, 30, 9, 15);
            imagedestroy($letter);
        }
        for ($i = 0; $i < 65; $i++) { imagesetpixel($image, random_int(0, 189), random_int(0, 59), $noise); }
        ob_start();
        imagepng($image);
        $png = (string)ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    public function login(string $username, string $password, string $captcha): bool {
        self::startSession();
        $validCaptcha = isset($_SESSION['captcha_hash'], $_SESSION['captcha_expires_at'])
            && is_string($_SESSION['captcha_hash']) && (int)$_SESSION['captcha_expires_at'] >= time()
            && hash_equals($_SESSION['captcha_hash'], hash('sha256', strtoupper(trim($captcha))));
        // 不論帳密是否正確，每張驗證碼均只能嘗試一次。
        unset($_SESSION['captcha_hash'], $_SESSION['captcha_expires_at']);
        $client = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
        $cutoff = time() - 300;
        $this->db->prepare('DELETE FROM login_attempts WHERE failed_at < ?')->execute([$cutoff]);
        $attempts = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE client_key=? AND failed_at>=?');
        $attempts->execute([$client, $cutoff]);
        if ((int)$attempts->fetchColumn() >= 10) { return false; }
        $statement = $this->db->prepare('SELECT id,password_hash FROM users WHERE username=?');
        $statement->execute([trim($username)]);
        $user = $statement->fetch();
        // 不存在的帳號仍執行雜湊核對，避免依回應時間猜測帳號。
        $hash = $user === false ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.' : $user['password_hash'];
        $validPassword = strlen($password) <= 72 && password_verify($password, $hash);
        if (!$validCaptcha || !$validPassword || $user === false) {
            $this->db->prepare('INSERT INTO login_attempts(client_key,failed_at) VALUES(?,?)')->execute([$client, time()]);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = (int)$user['id'];
        unset($_SESSION['csrf_token']);
        $this->db->prepare('DELETE FROM login_attempts WHERE client_key=?')->execute([$client]);
        return true;
    }

    public function changePassword(string $current, string $new, string $confirm): void {
        $user = $this->currentUser();
        if ($user === null) { throw new RuntimeException('請先登入再修改密碼。'); }
        $statement = $this->db->prepare('SELECT password_hash FROM users WHERE id=?');
        $statement->execute([$user['id']]);
        if (strlen($current) > 72 || !password_verify($current, (string)$statement->fetchColumn())) {
            throw new InvalidArgumentException('目前密碼不正確。');
        }
        if ($new !== $confirm) { throw new InvalidArgumentException('新密碼與確認密碼不一致。'); }
        if (preg_match_all('/./us', $new) < 8) { throw new InvalidArgumentException('新密碼至少需要 8 個字元。'); }
        if (strlen($new) > 72) { throw new InvalidArgumentException('新密碼不可超過 72 位元組。'); }
        $this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
    }

    public function logout(): void {
        self::startSession();
        $_SESSION = [];
        $options = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $options['path'],
            'domain' => $options['domain'], 'secure' => $options['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        session_id('');
    }
}
