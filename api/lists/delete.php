<?php
/**
 * api/lists/delete.php — Delete a list.
 * POST: list_id
 * Personal list → only owner. Group list → any member can delete.
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
$listId = (int) ($_POST['list_id'] ?? 0);

if ($listId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid list.']);
    exit;
}

$stmt = $conn->prepare('SELECT user_id, group_id FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) {
    echo json_encode(['ok' => false, 'error' => 'List not found.']);
    exit;
}

// Permission check
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
        echo json_encode(['ok' => false, 'error' => 'You can only delete your own personal lists.']);
        exit;
    }
}

$stmt = $conn->prepare('DELETE FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to delete list.']);
}
