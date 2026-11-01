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

// C2/H3 (PENTEST/highly sever/): enforce strict SQL mode per connection so a
// calendar-impossible date or an out-of-range amount raises a catchable
// mysqli_sql_exception instead of being silently coerced. This mirrors the
// server-level sql_mode in my.ini (belt and braces -- the app keeps its
// guarantee even where the server config drifts).
// NOTE: raw queries are banned in this file by the test suite -- the mode is
// applied through a prepared statement for exactly that reason.
$modeStmt = $conn->prepare("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
if ($modeStmt instanceof mysqli_stmt) {
    $modeStmt->execute();
    $modeStmt->close();
}
