<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuditLogger.php';

if (!empty($_SESSION['user_id'])) {
    AuditLogger::log('logout', 'user', (int)$_SESSION['user_id'], [
        'username' => $_SESSION['username'] ?? null,
    ]);
}

// Clear all session data
$_SESSION = [];

// Remove the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Prevent the browser from reusing a cached dashboard
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Return to login
header('Location: /authentication/login.php');
exit;
