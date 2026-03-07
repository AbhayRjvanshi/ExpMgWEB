<?php
/**
 * api/expenses/categories.php — Return list of categories (for dropdowns).
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$result = $conn->query('SELECT id, name FROM categories ORDER BY id');
$cats = [];
while ($row = $result->fetch_assoc()) {
    $cats[] = $row;
}

echo json_encode(['ok' => true, 'categories' => $cats]);
