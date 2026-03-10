<?php
/**
 * api/lists/details.php — Get a list and all its items (priority-sorted).
 * GET: list_id
 * Sort: high first, then moderate, then low. Within same priority → oldest first.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$listId = (int) ($_GET['list_id'] ?? 0);

if ($listId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid list.']);
    exit;
}

// Fetch list
$stmt = $conn->prepare(
    'SELECT l.id, l.name, l.user_id, l.group_id, l.created_at,
            u.username AS created_by, g.name AS group_name
     FROM lists l
     JOIN users u ON u.id = l.user_id
     LEFT JOIN `groups` g ON g.id = l.group_id
     WHERE l.id = ?'
);
$stmt->bind_param('i', $listId);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) {
    echo json_encode(['ok' => false, 'error' => 'List not found.']);
    exit;
}

// Permission: personal list → only owner; group list → must be member
if ($list['group_id']) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $gid = (int)$list['group_id'];
    $chk->bind_param('ii', $gid, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
    $chk->close();
} else {
    if ((int)$list['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
}

// Fetch items sorted by priority (high→moderate→low), then by created_at ASC
$stmt = $conn->prepare(
    "SELECT li.id, li.description, li.category_id, li.priority, li.is_checked,
            li.price, li.checked_at, li.expense_created,
            li.added_by, li.created_at,
            c.name AS category_name,
            u.username AS added_by_name
     FROM list_items li
     LEFT JOIN categories c ON c.id = li.category_id
     JOIN users u ON u.id = li.added_by
     WHERE li.list_id = ?
     ORDER BY FIELD(li.priority, 'high', 'moderate', 'low'), li.created_at ASC"
);
$stmt->bind_param('i', $listId);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $row['is_checked'] = (int)$row['is_checked'];
    $row['expense_created'] = (int)$row['expense_created'];
    $items[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'list' => $list, 'items' => $items]);
