<?php
/**
 * api/login.php — Authenticate an existing user.
 * Expects POST: email, password
 * On success: sets session and redirects to dashboard (public/index.php).
 */
session_start();
require_once __DIR__ . '/../config/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    $_SESSION['auth_error'] = 'Invalid email or password.';
    $_SESSION['old_email']  = $email;
    header('Location: ../pages/login.php');
    exit;
}

// ---------- Login success — create session ----------
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];

header('Location: ../public/index.php');
exit;
