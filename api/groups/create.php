<?php
/**
 * api/groups/create.php — Create a new group.
 * POST: name
 * Business rule: a user can create at most 5 groups.
 * Returns JSON with the new group + join code.
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
$name   = trim($_POST['name'] ?? '');

if ($name === '' || strlen($name) > 100) {
    echo json_encode(['ok' => false, 'error' => 'Group name is required (max 100 chars).']);
    exit;
}

// Check max 5 groups created by this user
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM `groups` WHERE created_by = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int)$row['cnt'] >= 5) {
    echo json_encode(['ok' => false, 'error' => 'You can create at most 5 groups.']);
    exit;
}

// Generate unique join code (8 chars alphanumeric)
function generateJoinCode($conn) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $chk = $conn->prepare('SELECT id FROM `groups` WHERE join_code = ?');
        $chk->bind_param('s', $code);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
        if (!$exists) return $code;
    }
    return null;
}

$joinCode = generateJoinCode($conn);
if (!$joinCode) {
    echo json_encode(['ok' => false, 'error' => 'Failed to generate join code. Try again.']);
    exit;
}

// Create the group
$stmt = $conn->prepare('INSERT INTO `groups` (name, join_code, created_by) VALUES (?, ?, ?)');
$stmt->bind_param('ssi', $name, $joinCode, $userId);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to create group.']);
    exit;
}

$groupId = $stmt->insert_id;
$stmt->close();

// Add creator as admin member
$stmt = $conn->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, "admin")');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->close();

echo json_encode([
    'ok'   => true,
    'group' => [
        'id'        => $groupId,
        'name'      => $name,
        'join_code' => $joinCode,
        'role'      => 'admin'
    ]
]);
