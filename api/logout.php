<?php
/**
 * api/logout.php — Destroy the session and redirect to login.
 */
session_start();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

session_destroy();
header('Location: ../pages/login.php');
exit;
