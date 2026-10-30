<?php
/**
 * Database configuration & connection.
 * Migrated to environment-based configuration.
 */
require_once __DIR__ . '/env.php';

// Create connection using env() helper
$conn = new mysqli(
    env('DB_HOST', '127.0.0.1'),
    env('DB_USER', 'root'),
    env('DB_PASS', ''),
    env('DB_NAME', 'ExpMgWEB')
);

// Check connection
if ($conn->connect_error) {
    if (php_sapi_name() === 'cli') {
        die('Database connection failed: ' . $conn->connect_error . PHP_EOL);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Force utf8mb4 on the connection so multibyte/emoji round-trips match the
// schema's character set exactly (mysqli otherwise defaults to latin1).
$conn->set_charset('utf8mb4');
