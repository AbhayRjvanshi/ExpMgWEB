<?php
/**
 * api/settlements/history.php — Past settlements for a group.
 * GET ?group_id=N
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_GET['group_id'] ?? 0);

if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group.']);
    exit;
}

// Verify membership
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Not a member of this group.']);
    exit;
}
$stmt->close();

$stmt = $conn->prepare(
    'SELECT s.id, s.payer_id, s.payee_id, s.amount, s.period_start, s.period_end, s.created_at,
            payer.username AS payer_name, payee.username AS payee_name
     FROM settlements s
     JOIN users payer ON payer.id = s.payer_id
     JOIN users payee ON payee.id = s.payee_id
     WHERE s.group_id = ?
     ORDER BY s.created_at DESC
     LIMIT 50'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$result = $stmt->get_result();
$history = [];
while ($r = $result->fetch_assoc()) {
    $history[] = [
        'id'           => (int) $r['id'],
        'payer_id'     => (int) $r['payer_id'],
        'payee_id'     => (int) $r['payee_id'],
        'payer_name'   => $r['payer_name'],
        'payee_name'   => $r['payee_name'],
        'amount'       => (float) $r['amount'],
        'period_start' => $r['period_start'],
        'period_end'   => $r['period_end'],
        'created_at'   => $r['created_at']
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'settlements' => $history]);
