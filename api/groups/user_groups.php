<?php
/**
 * api/groups/user_groups.php — Return groups the current user belongs to (for dropdowns).
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    'SELECT g.id, g.name, gm.role
     FROM `groups` g
     JOIN group_members gm ON gm.group_id = g.id
     WHERE gm.user_id = ?
     ORDER BY g.name'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$groups = [];
while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'groups' => $groups]);
