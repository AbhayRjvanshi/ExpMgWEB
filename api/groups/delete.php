<?php
/**
 * api/groups/delete.php — Delete a group (admin only).
 * POST: group_id
 * Cascades: all group_members, expenses (via ON DELETE CASCADE / SET NULL).
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
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group.']);
    exit;
}

// Only creator (admin) can delete
$stmt = $conn->prepare('SELECT created_by FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    echo json_encode(['ok' => false, 'error' => 'Group not found.']);
    exit;
}

if ((int)$group['created_by'] !== $userId) {
    echo json_encode(['ok' => false, 'error' => 'Only the group creator can delete the group.']);
    exit;
}

$stmt = $conn->prepare('DELETE FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to delete group.']);
}
