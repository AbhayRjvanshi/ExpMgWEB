<?php
/**
 * api/lists/create.php — Create a new list (personal or group).
 * POST: name, group_id (optional — omit or empty for personal)
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

$userId  = (int) $_SESSION['user_id'];
$name    = trim($_POST['name'] ?? '');
$groupId = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;

if ($name === '' || strlen($name) > 100) {
    echo json_encode(['ok' => false, 'error' => 'List name is required (max 100 chars).']);
    exit;
}

// If group list, verify user is a member of that group
if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        echo json_encode(['ok' => false, 'error' => 'You are not a member of this group.']);
        exit;
    }
    $chk->close();
}

$stmt = $conn->prepare('INSERT INTO lists (name, user_id, group_id) VALUES (?, ?, ?)');
$stmt->bind_param('sii', $name, $userId, $groupId);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['ok' => true, 'list' => ['id' => $newId, 'name' => $name, 'group_id' => $groupId]]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to create list.']);
}
