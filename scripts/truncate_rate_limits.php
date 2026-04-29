<?php
require_once __DIR__ . '/config/db.php';
if ($conn->query('TRUNCATE TABLE rate_limits')) {
    echo "Rate limits truncated successfully.\n";
} else {
    echo "Error: " . $conn->error . "\n";
}
