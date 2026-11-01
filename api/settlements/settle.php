<?php
/**
 * api/settlements/settle.php — Record a settlement.
 * POST: group_id, payer_id, payee_id, amount, period_start, period_end
 *
 * C1/C2/C3 (PENTEST/highly sever/): every write now goes through the gated
 * recordSettlement() in settlement_helpers.php. This endpoint validates the
 * actor (admin), the dates (calendar-valid, ordered, not in the future) and
 * the amount ceiling, then delegates. The confirmation gate, member-party
 * check, computed-transfer match and duplicate guard all live in the helper.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
try {
requirePostMethod();

requireAuth();

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../settlements/settlement_helpers.php';
$idemCtx    = idempotencyGuard((int) $_SESSION['user_id'], 'settlements/settle');

$userId     = (int) $_SESSION['user_id'];
$groupId    = (int) ($_POST['group_id'] ?? 0);
$payerId    = (int) ($_POST['payer_id'] ?? 0);
$payeeId    = (int) ($_POST['payee_id'] ?? 0);
$periodStart = trim($_POST['period_start'] ?? '');
$periodEnd   = trim($_POST['period_end'] ?? '');

// H3: amount normalized + ceilinged (0.01 .. 99999999.00). Sub-cent inputs
// (0.001) and overflows (1e300) now fail here instead of inside MySQL.
$amount = normalizeMoney($_POST['amount'] ?? null);

if ($groupId <= 0 || $payerId <= 0 || $payeeId <= 0 || $amount === null) {
    apiResponse((['ok' => false, 'error' => 'Invalid settlement data.']));
    exit;
}

// C2: calendar-valid dates (the shape-only regex accepted 2026-02-30, which
// MariaDB then coerced to 0000-00-00 and poisoned the settlement cursor).
try {
    validateDate($periodStart, 'period_start');
    validateDate($periodEnd, 'period_end');
} catch (Exception $e) {
    logMessage('ERROR', 'Rejected settlement period dates', [
        'user_id' => $userId,
        'group_id' => $groupId,
        'reason'  => $e->getMessage(),
    ]);
    apiResponse((['ok' => false, 'error' => 'Invalid date format.']));
    exit;
}
if ($periodStart > $periodEnd) {
    apiResponse((['ok' => false, 'error' => 'Invalid period: start date is after end date.']));
    exit;
}
// C2 (future-lock DoS): a far-future period_end would arm the settlement lock
// against every date that will ever exist, freezing the group's bookkeeping.
if ($periodEnd > date('Y-m-d')) {
    apiResponse((['ok' => false, 'error' => 'Invalid period: end date cannot be in the future.']));
    exit;
}

// Verify user is admin of this group
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    apiResponse((['ok' => false, 'error' => 'Not a member of this group.']));
    exit;
}
if ($membership['role'] !== 'admin') {
    apiResponse((['ok' => false, 'error' => 'Only the group admin can record settlements.']));
    exit;
}

// Gated writer: C3 member parties + C1 confirmation gate + computed-transfer
// match + duplicate guard, all inside one transaction (see settlement_helpers.php).
$result = recordSettlement($conn, $groupId, $userId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);
if (!$result['ok']) {
    apiResponse((['ok' => false, 'error' => $result['error']]));
    exit;
}

idempotencyRespond($idemCtx, ['ok' => true, 'message' => 'Settlement recorded.', 'id' => $result['id']], 200);

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