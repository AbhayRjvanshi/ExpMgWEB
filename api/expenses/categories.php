<?php
/**
 * api/expenses/categories.php — Return list of categories (for dropdowns).
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$result = $conn->query('SELECT id, name FROM categories ORDER BY id');
$cats = [];
while ($row = $result->fetch_assoc()) {
    $cats[] = $row;
}

apiResponse((['ok' => true, 'categories' => $cats]));

} catch (Throwable $e) {
    logMessage('ERROR', 'Unhandled endpoint exception', [
        'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
        'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
        'error'    => $e->getMessage(),
        'file'     => $e->getFile(),
        'line'     => $e->getLine(),
    ]);
    apiError('Internal server error.', 500);
}