<?php
/**
 * api/login.php — Authenticate an existing user.
 * Expects POST: email, password
 * On success: sets session and redirects to dashboard (public/index.php).
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/rate_limiter.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/logger.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/login.php');
    exit;
}

// ---------- Rate limiting check ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!checkRateLimit($conn, $ip, 'login', 5, 900)) {
    $retryAfter = rateLimitRetryAfter($conn, $ip, 'login', 900);
    $_SESSION['rate_limit_retry_after'] = $retryAfter;
    $acceptJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false 
               || $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($acceptJson) {
        header('Content-Type: application/json');
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Rate limited', 'retry_after' => $retryAfter, 'limited_by' => 'login']);
        exit;
    }
    
    $_SESSION['auth_error'] = 'Too many login attempts. Try again in 15 minutes.';
    header('Location: ../pages/login.php');
    exit;
}

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password']   ?? '';

// ---------- Validation ----------
if ($email === '' || $password === '') {
    $_SESSION['auth_error'] = 'Please fill in all fields.';
    header('Location: ../pages/login.php');
    exit;
}

// Fetch user by email
$stmt = $conn->prepare('SELECT id, username, password FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['auth_error'] = 'Invalid email or password.';
    $_SESSION['old_email']  = $email;
    $stmt->close();
    header('Location: ../pages/login.php');
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// Verify password
if (!password_verify($password, $user['password'])) {
    recordRateLimit($conn, $ip, 'login', 900);
    $_SESSION['auth_error'] = 'Invalid email or password.';
    $_SESSION['old_email']  = $email;
    header('Location: ../pages/login.php');
    exit;
}

// ---------- Login success — create session ----------
session_regenerate_id(true);
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['login_time'] = time();
generateCsrfToken();

logMessage('INFO', 'Login successful', [
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'ip' => $ip
]);

header('Location: ../public/index.php');
exit;
