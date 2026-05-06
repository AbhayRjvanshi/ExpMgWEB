<?php
/**
 * api/signup.php — Handle new user registration.
 * Expects POST: username, email, password, confirm_password
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/rate_limiter.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/signup.php');
    exit;
}

// ---------- Rate limiting check ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!checkRateLimit($conn, $ip, 'signup', 3, 3600)) {
    $retryAfter = rateLimitRetryAfter($conn, $ip, 'signup', 3600);
    $_SESSION['rate_limit_retry_after'] = $retryAfter;
    $acceptJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false 
               || $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($acceptJson) {
        header('Content-Type: application/json');
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Rate limited', 'retry_after' => $retryAfter, 'limited_by' => 'signup']);
        exit;
    }
    
    $_SESSION['auth_error'] = 'Too many signup attempts. Try again in 1 hour.';
    header('Location: ../pages/signup.php');
    exit;
}

// Gather & trim input
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? '');
$password = $_POST['password']         ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

// ---------- Validation ----------
$errors = [];

if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
    $errors[] = 'Username must be 3–50 characters.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
if (strlen($password) < 6) {
    $errors[] = 'Password must be at least 6 characters.';
}
if ($password !== $confirm) {
    $errors[] = 'Passwords do not match.';
}

// Check uniqueness (username & email)
if (empty($errors)) {
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $errors[] = 'Username or email already taken.';
    }
    $stmt->close();
}

// If errors, redirect back
if (!empty($errors)) {
    $_SESSION['auth_error']   = implode(' ', $errors);
    $_SESSION['old_username'] = $username;
    $_SESSION['old_email']    = $email;
    header('Location: ../pages/signup.php');
    exit;
}

// ---------- Create user ----------
$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $username, $email, $hash);

if ($stmt->execute()) {
    recordRateLimit($conn, $ip, 'signup', 3600);
    $_SESSION['auth_success'] = 'Account created! Please sign in.';
    $stmt->close();
    header('Location: ../pages/login.php');
    exit;
} else {
    $_SESSION['auth_error'] = 'Something went wrong. Please try again.';
    $stmt->close();
    header('Location: ../pages/signup.php');
    exit;
}
