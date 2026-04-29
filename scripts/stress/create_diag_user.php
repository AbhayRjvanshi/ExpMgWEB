<?php
chdir(__DIR__ . '/..' . '/..');
require 'config/db.php';

$username = 'diag_user_' . substr(bin2hex(random_bytes(6)), 0, 10);
$email = $username . '@diag.local';
$password = 'DiagPass123!';
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $username, $email, $hash);
$ok = $stmt->execute();
$id = (int)$stmt->insert_id;
$stmt->close();

if (!$ok) {
    fwrite(STDERR, "insert_failed\n");
    exit(1);
}

echo json_encode([
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'user_id' => $id,
], JSON_PRETTY_PRINT), PHP_EOL;
