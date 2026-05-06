<?php
/**
 * api/groups/remove_member.php — Remove a member from a group (admin only).
 * POST: group_id, user_id
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

$actorId  = (int) $_SESSION['user_id'];
$groupId  = (int) ($_POST['group_id'] ?? 0);
$targetId = (int) ($_POST['user_id'] ?? 0);

if ($groupId <= 0 || $targetId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// Actor must be admin in this group.
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $actorId);
$stmt->execute();
$actorMembership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$actorMembership || $actorMembership['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Only the group admin can remove members.']);
    exit;
}

// Target must be a member in this group.
$stmt = $conn->prepare(
    'SELECT gm.role, u.username
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = ? AND gm.user_id = ?'
);
$stmt->bind_param('ii', $groupId, $targetId);
$stmt->execute();
$targetMembership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$targetMembership) {
    echo json_encode(['ok' => false, 'error' => 'User is not a member of this group.']);
    exit;
}

// Keep exactly one effective admin by disallowing admin removal from this endpoint.
if ($targetMembership['role'] === 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Admin cannot be removed from the group.']);
    exit;
}

$stmt = $conn->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $targetId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'Failed to remove member.']);
    exit;
}

// Notify removed member.
$adminName = $_SESSION['username'] ?? 'Admin';
$gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
$gStmt->bind_param('i', $groupId);
$gStmt->execute();
$gRow = $gStmt->get_result()->fetch_assoc();
$gStmt->close();
$groupName = $gRow ? $gRow['name'] : 'a group';

$msg = "$adminName removed you from the group \"$groupName\".";
$nStmt = $conn->prepare('INSERT INTO notifications (user_id, message, type, reference_id) VALUES (?, ?, "group_member_remove", ?)');
$nStmt->bind_param('isi', $targetId, $msg, $groupId);
$nStmt->execute();
$nStmt->close();

echo json_encode([
    'ok' => true,
    'removed_user_id' => $targetId,
    'removed_username' => $targetMembership['username']
]);
