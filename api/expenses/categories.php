<?php
/**
 * api/expenses/categories.php — Return list of categories (for dropdowns).
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';

requireAuth();

$result = $conn->query('SELECT id, name FROM categories ORDER BY id');
$cats = [];
while ($row = $result->fetch_assoc()) {
    $cats[] = $row;
}

echo json_encode(['ok' => true, 'categories' => $cats]);
