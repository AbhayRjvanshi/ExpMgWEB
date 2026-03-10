<?php
/**
 * api/settlements/settle.php — Record a settlement.
 * POST: group_id, payer_id, payee_id, amount, period_start, period_end
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

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

$stmt = $conn->prepare(
    'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iiiidss', $groupId, $userId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'message' => 'Settlement recorded.', 'id' => (int) $stmt->insert_id]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to record settlement.']);
}
$stmt->close();
