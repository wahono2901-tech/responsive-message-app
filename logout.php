<?php
/**
 * Logout User
 * File: logout.php
 */

require_once __DIR__ . '/config/config.php';

// Cek apakah file session.php ada
$session_file = __DIR__ . '/config/session.php';
if (file_exists($session_file)) {
    require_once $session_file;
} else {
    // Fallback: start session manually
    if (session_status() === PHP_SESSION_NONE) {
        session_name('RMSESSID');
        session_start();
    }
}

// Hapus semua data session
$_SESSION = array();

// Hapus cookie session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hapus remember me cookie jika ada
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Hancurkan session
session_destroy();

// Redirect ke login
header('Location: ' . rtrim(BASE_URL, '/') . '/login.php?logout=1');
exit;