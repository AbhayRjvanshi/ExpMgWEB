<?php
/**
 * api/budgets/get.php — Retrieve budget for a given month.
 * GET ?month=YYYY-MM
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$userId = (int) $_SESSION['user_id'];
$month  = trim($_GET['month'] ?? '');

// M4: real month range.
try {
    validateMonth($month, 'month');
} catch (Exception $e) {
    apiResponse(['ok' => false, 'error' => 'Invalid month format.'], 400);
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    apiResponse((['ok' => false, 'error' => 'Provide ?month=YYYY-MM']));
    exit;
}

$stmt = $conn->prepare("SELECT id, amount_limit, budget_month FROM budgets WHERE user_id = ? AND budget_month = ?");
$stmt->bind_param('is', $userId, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    apiResponse((['ok' => true, 'budget' => ['id' => (int) $row['id'], 'amount_limit' => (float) $row['amount_limit'], 'month' => $row['budget_month']]]));
} else {
    apiResponse((['ok' => true, 'budget' => null]));
}

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