<?php
/**
 * api/budgets/set.php — Create or update a monthly budget.
 * POST: month (YYYY-MM), amount_limit (number > 0)
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
try {
requirePostMethod();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse((['ok' => false, 'error' => 'Invalid method.']));
    exit;
}

requireAuth();

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
$userId = (int) $_SESSION['user_id'];
$idemCtx = idempotencyGuard($userId, 'budgets/set');
$month  = trim($_POST['month'] ?? '');
$limitRaw = $_POST['amount_limit'] ?? '';
// H3: round to 2dp + business ceiling (0.01 .. 99999999.00).
$limit  = is_numeric($limitRaw) ? round((float) $limitRaw, 2) : 0.0;

// YYYY-MM with a real month range: the loose \d{2} form accepted 2026-15.
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    apiResponse((['ok' => false, 'error' => 'Invalid month format.']));
    exit;
}
if ($limit <= 0) {
    apiResponse((['ok' => false, 'error' => 'Budget must be greater than zero.']));
    exit;
}
if ($limit > 99999999.00) {
    apiResponse((['ok' => false, 'error' => 'Budget must be at most 99999999.00.']));
    exit;
}

// G-2 (PENTEST/moderately sever/G-2.md): the upsert silently replaced the
// stored limit with no trace. Capture the previous value so the response and
// the log can both show what changed.
$prevStmt = $conn->prepare('SELECT amount_limit FROM budgets WHERE user_id = ? AND budget_month = ?');
$prevStmt->bind_param('is', $userId, $month);
$prevStmt->execute();
$prevRow = $prevStmt->get_result()->fetch_assoc();
$prevStmt->close();
$previous = $prevRow !== null ? (float) $prevRow['amount_limit'] : null;

// Upsert: INSERT … ON DUPLICATE KEY UPDATE
$sql = "INSERT INTO budgets (user_id, budget_month, amount_limit)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE amount_limit = VALUES(amount_limit), updated_at = CURRENT_TIMESTAMP";
$stmt = $conn->prepare($sql);
$stmt->bind_param('isd', $userId, $month, $limit);

if ($stmt->execute()) {
    $stmt->close();
    logMessage('INFO', 'Budget ' . ($previous !== null ? 'updated' : 'created'), [
        'user_id'  => $userId,
        'month'    => $month,
        'previous' => $previous,
        'new'      => $limit,
    ]);
    idempotencyRespond($idemCtx, ['ok' => true, 'message' => 'Budget saved.', 'previous' => $previous], 200);
} else {
    $stmt->close();
    apiResponse((['ok' => false, 'error' => 'Failed to save budget.']));
}
$stmt->close();

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