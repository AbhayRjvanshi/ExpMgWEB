<?php
/**
 * api/lists/remove_item.php — Remove an item from a list.
 * POST: item_id
 * Any member can remove from a group list. Owner can remove from personal list.
 * Notifies other group members.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);

if ($itemId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid item.']); exit; }

// Fetch item + list info
$stmt = $conn->prepare(
    'SELECT li.id, li.list_id, li.description, l.user_id AS list_owner, l.group_id, l.name AS list_name
     FROM list_items li
     JOIN lists l ON l.id = li.list_id
     WHERE li.id = ?'
);
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) { echo json_encode(['ok' => false, 'error' => 'Item not found.']); exit; }

$groupId = $item['group_id'] ? (int)$item['group_id'] : null;

// Permission
if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) { $chk->close(); echo json_encode(['ok' => false, 'error' => 'Access denied.']); exit; }
    $chk->close();
} else {
    if ((int)$item['list_owner'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
}

// Delete
$stmt = $conn->prepare('DELETE FROM list_items WHERE id = ?');
$stmt->bind_param('i', $itemId);

if ($stmt->execute()) {
    $stmt->close();

    // Notify group members
    if ($groupId) {
        $username = $_SESSION['username'];
        $msg = "$username removed \"{$item['description']}\" from the list \"{$item['list_name']}\".";
        $listId = (int)$item['list_id'];
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "list_item_remove", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $listId, $groupId, $userId);
        $n->execute();
        $n->close();
    }

    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to remove item.']);
}
