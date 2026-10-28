<?php
/**
 * api/settlements/settle.php — Record a settlement.
 * POST: group_id, payer_id, payee_id, amount, period_start, period_end
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
$idemCtx    = idempotencyGuard((int) $_SESSION['user_id'], 'settlements/settle');

$userId     = (int) $_SESSION['user_id'];
$groupId    = (int) ($_POST['group_id'] ?? 0);
$payerId    = (int) ($_POST['payer_id'] ?? 0);
$payeeId    = (int) ($_POST['payee_id'] ?? 0);
$amount     = (float) ($_POST['amount'] ?? 0);
$periodStart = trim($_POST['period_start'] ?? '');
$periodEnd   = trim($_POST['period_end'] ?? '');

if ($groupId <= 0 || $payerId <= 0 || $payeeId <= 0 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid settlement data.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format.']);
    exit;
}
if ($periodStart > $periodEnd) {
    echo json_encode(['ok' => false, 'error' => 'Invalid period: start date is after end date.']);
    exit;
}

// Verify user is admin of this group
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    echo json_encode(['ok' => false, 'error' => 'Not a member of this group.']);
    exit;
}
if ($membership['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Only the group admin can record settlements.']);
    exit;
}

// Duplicate guard + INSERT inside a transaction so a double-click cannot
// record the same settlement twice.
$conn->begin_transaction();
try {
    $dup = $conn->prepare(
        'SELECT id FROM settlements
         WHERE group_id = ? AND payer_id = ? AND payee_id = ? AND amount = ?
           AND period_start = ? AND period_end = ?
         LIMIT 1'
    );
    $dup->bind_param('iiidss', $groupId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);
    $dup->execute();
    $dupRow = $dup->get_result()->fetch_assoc();
    $dup->close();

    if ($dupRow) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'This settlement has already been recorded.']);
        exit;
    }

    $stmt = $conn->prepare(
        'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iiiidss', $groupId, $userId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Failed to record settlement.']);
        exit;
    }
    $newId = (int) $stmt->insert_id;
    $stmt->close();
    $conn->commit();

    idempotencyRespond($idemCtx, ['ok' => true, 'message' => 'Settlement recorded.', 'id' => $newId], 200);
} catch (Exception $e) {
    $conn->rollback();
    logMessage('ERROR', 'Settlement insert transaction failed', [
        'group_id' => $groupId,
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
    echo json_encode(['ok' => false, 'error' => 'Failed to record settlement.']);
}
