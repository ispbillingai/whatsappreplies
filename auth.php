<?php
/**
 * Authentication helpers
 */
require_once __DIR__ . '/database.php';

// Keep sessions alive for 1 year (never auto-logout)
ini_set('session.gc_maxlifetime', 31536000);
ini_set('session.cookie_lifetime', 31536000);
session_set_cookie_params([
    'lifetime' => 31536000,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Auto-restore session from remember-me cookie if session is dead
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    _restoreFromRememberToken($_COOKIE['remember_token']);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function _restoreFromRememberToken($token) {
    $db = getDB();
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        // Invalid or expired token - clear the cookie
        setcookie('remember_token', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        return;
    }

    // Verify user is still active
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$row['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        setcookie('remember_token', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        return;
    }

    // Restore session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
}

function _createRememberToken($userId) {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 365 * 86400); // 1 year

    // Clean expired tokens for this user
    $db->prepare('DELETE FROM remember_tokens WHERE user_id = ? AND expires_at <= NOW()')->execute([$userId]);
    $db->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')->execute([$userId, $hash, $expires]);

    setcookie('remember_token', $token, [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function _clearRememberToken() {
    if (!empty($_COOKIE['remember_token'])) {
        $db = getDB();
        $hash = hash('sha256', $_COOKIE['remember_token']);
        $db->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')->execute([$hash]);
    }
    setcookie('remember_token', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    // Force password change if flagged
    if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'change-password.php') {
        header('Location: change-password.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, email, role, created_at, last_login, IFNULL(timezone, "Africa/Nairobi") as timezone FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    // Apply user's timezone globally
    if ($user && !empty($user['timezone'])) {
        date_default_timezone_set($user['timezone']);
    }
    return $user;
}

if (!function_exists('fmtTime')) {
    /**
     * Format a datetime from the database for display.
     * Treats DB time as UTC+2 (server timezone), converts to user's PHP timezone.
     */
    function fmtTime($dbDatetime, $format = 'M d H:i') {
        if (empty($dbDatetime)) return '-';
        try {
            // DB stores in server timezone (UTC+2), convert to display timezone
            $dt = new DateTime($dbDatetime, new DateTimeZone('+02:00'));
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            return $dt->format($format);
        } catch (Exception $e) {
            return $dbDatetime;
        }
    }
}

function getUserTimezone() {
    if (!isLoggedIn()) return 'Africa/Nairobi';
    $db = getDB();
    $stmt = $db->prepare('SELECT IFNULL(timezone, "Africa/Nairobi") as timezone FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $tz = $stmt->fetchColumn();
    return $tz ?: 'Africa/Nairobi';
}

function login($email, $password) {
    $db = getDB();
    $stmt = $db->prepare('SELECT *, IFNULL(must_change_password, 0) as must_change_password FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['must_change_password'] = !empty($user['must_change_password']);
        $_SESSION['is_first_login'] = empty($user['last_login']);

        // Update last login
        $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

        // Create persistent remember-me cookie
        _createRememberToken($user['id']);

        return true;
    }
    return false;
}

function logout() {
    _clearRememberToken();
    session_destroy();
    header('Location: login.php');
    exit;
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: dashboard.php');
        exit;
    }
}

function generateApiKey() {
    return bin2hex(random_bytes(32));
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function flash($message, $type = 'success') {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
