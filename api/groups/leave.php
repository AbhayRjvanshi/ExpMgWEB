<?php
/**
 * api/groups/leave.php — Leave a group.
 * POST: group_id
 * Admin cannot leave — they must delete the group instead (or transfer, future feature).
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

// Check membership + role
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    echo json_encode(['ok' => false, 'error' => 'You are not a member of this group.']);
    exit;
}

if ($member['role'] === 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Admin cannot leave the group. Delete the group instead.']);
    exit;
}

// Remove member
$stmt = $conn->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
