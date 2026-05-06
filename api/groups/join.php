<?php
/**
 * api/groups/join.php — Join a group using a join code.
 * POST: join_code
 * Business rule: max 10 members per group.
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

$userId   = (int) $_SESSION['user_id'];
$joinCode = strtoupper(trim($_POST['join_code'] ?? ''));

if ($joinCode === '') {
    echo json_encode(['ok' => false, 'error' => 'Please enter a join code.']);
    exit;
}

// Find the group
$stmt = $conn->prepare('SELECT id, name, created_by, max_members FROM `groups` WHERE join_code = ?');
$stmt->bind_param('s', $joinCode);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    echo json_encode(['ok' => false, 'error' => 'No group found with that code.']);
    exit;
}

$groupId = (int)$group['id'];

// Already a member?
$stmt = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'You are already a member of this group.']);
    exit;
}
$stmt->close();

// Check member count
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM group_members WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$cnt = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($cnt >= (int)$group['max_members']) {
    echo json_encode(['ok' => false, 'error' => 'This group is full (max ' . $group['max_members'] . ' members).']);
    exit;
}

// Add as member
$stmt = $conn->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, "member")');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->close();

// Notify all group members that a new member joined
$username = $_SESSION['username'];
$msg = "$username joined the group \"{$group['name']}\".";
$nStmt = $conn->prepare(
    'INSERT INTO notifications (user_id, message, type, reference_id)
     SELECT user_id, ?, "group_join", ?
     FROM group_members WHERE group_id = ? AND user_id != ?'
);
$nStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
$nStmt->execute();
$nStmt->close();

echo json_encode([
    'ok' => true,
    'group' => [
        'id'   => $groupId,
        'name' => $group['name'],
        'role' => 'member'
    ]
]);
