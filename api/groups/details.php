<?php
/**
 * api/groups/details.php — Get full details of a group (members, expenses).
 * GET: group_id
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
$myMembership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$myMembership) {
    echo json_encode(['ok' => false, 'error' => 'You are not a member of this group.']);
    exit;
}

// Group info
$stmt = $conn->prepare('SELECT id, name, join_code, created_by, max_members, created_at FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Members
$stmt = $conn->prepare(
    'SELECT gm.user_id, u.username, gm.role, gm.joined_at
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = ?
     ORDER BY gm.role DESC, gm.joined_at ASC'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$result  = $stmt->get_result();
$members = [];
while ($row = $result->fetch_assoc()) $members[] = $row;
$stmt->close();

// Past group expenses (last 20)
$stmt = $conn->prepare(
    'SELECT e.id, e.amount, e.note, e.expense_date, e.category_id, e.paid_by,
            c.name AS category_name, u.username AS added_by, pb.username AS payer_username, e.created_at
     FROM expenses e
     JOIN categories c ON c.id = e.category_id
     JOIN users u ON u.id = e.user_id
     LEFT JOIN users pb ON pb.id = e.paid_by
     WHERE e.group_id = ? AND e.type = "group"
     ORDER BY e.expense_date DESC, e.created_at DESC
     LIMIT 20'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$result   = $stmt->get_result();
$expenses = [];
while ($row = $result->fetch_assoc()) $expenses[] = $row;
$stmt->close();

echo json_encode([
    'ok'       => true,
    'group'    => $group,
    'my_role'  => $myMembership['role'],
    'members'  => $members,
    'expenses' => $expenses
]);
