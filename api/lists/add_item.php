<?php
/**
 * api/lists/add_item.php — Add an item to a list.
 * POST: list_id, description, category_id (optional), priority (high|moderate|low)
 * Any member can add to a group list. Owner can add to personal list.
 * Notifies other group members.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId      = (int) $_SESSION['user_id'];
$listId      = (int) ($_POST['list_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$categoryId  = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
$priority    = $_POST['priority'] ?? 'low';
$price       = isset($_POST['price']) && $_POST['price'] !== '' ? floatval($_POST['price']) : null;

if ($price !== null && $price < 0) $price = null;

if (!in_array($priority, ['high', 'moderate', 'low'])) $priority = 'low';
if ($listId <= 0)          { echo json_encode(['ok' => false, 'error' => 'Invalid list.']); exit; }
if ($description === '')   { echo json_encode(['ok' => false, 'error' => 'Description is required.']); exit; }

// Fetch list for permission check
$stmt = $conn->prepare('SELECT user_id, group_id, name FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) { echo json_encode(['ok' => false, 'error' => 'List not found.']); exit; }

$groupId = $list['group_id'] ? (int)$list['group_id'] : null;

if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) { $chk->close(); echo json_encode(['ok' => false, 'error' => 'Access denied.']); exit; }
    $chk->close();
} else {
    if ((int)$list['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
}

// Insert item
$stmt = $conn->prepare(
    'INSERT INTO list_items (list_id, description, category_id, priority, price, added_by) VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('isisdi', $listId, $description, $categoryId, $priority, $price, $userId);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    // Notify group members if group list
    if ($groupId) {
        $username = $_SESSION['username'];
        $msg = "$username added \"{$description}\" to the list \"{$list['name']}\".";
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "list_item_add", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $listId, $groupId, $userId);
        $n->execute();
        $n->close();
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to add item.']);
}
