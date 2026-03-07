<?php
/**
 * api/signup.php — Handle new user registration.
 * Expects POST: username, email, password, confirm_password
 */
session_start();
require_once __DIR__ . '/../config/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
