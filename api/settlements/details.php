<?php
/**
 * api/settlements/details.php — Detailed expenses for a settlement period.
 * GET ?group_id=N&start=YYYY-MM-DD&end=YYYY-MM-DD
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId    = (int) $_SESSION['user_id'];
$groupId   = (int) ($_GET['group_id'] ?? 0);
$startDate = trim($_GET['start'] ?? '');
$endDate   = trim($_GET['end'] ?? '');

if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format.']);
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
    "SELECT e.id, e.amount, e.note, e.expense_date, e.user_id, e.paid_by,
            c.name AS category_name, u.username AS added_by, pb.username AS payer_username
     FROM expenses e
     JOIN categories c ON c.id = e.category_id
     JOIN users u ON u.id = e.user_id
     LEFT JOIN users pb ON pb.id = e.paid_by
     WHERE e.group_id = ? AND e.type = 'group'
       AND e.expense_date BETWEEN ? AND ?
     ORDER BY e.expense_date ASC, e.created_at ASC"
);
$stmt->bind_param('iss', $groupId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();
$expenses = [];
$total = 0;
while ($r = $result->fetch_assoc()) {
    $amt = (float) $r['amount'];
    $total += $amt;
    $expenses[] = [
        'id'              => (int) $r['id'],
        'amount'          => $amt,
        'note'            => $r['note'],
        'expense_date'    => $r['expense_date'],
        'category_name'   => $r['category_name'],
        'added_by'        => $r['added_by'],
        'payer_username'  => $r['payer_username'],
        'user_id'         => (int) $r['user_id'],
        'paid_by'         => $r['paid_by'] ? (int) $r['paid_by'] : null
    ];
}
$stmt->close();

echo json_encode([
    'ok'       => true,
    'expenses' => $expenses,
    'total'    => round($total, 2)
]);
