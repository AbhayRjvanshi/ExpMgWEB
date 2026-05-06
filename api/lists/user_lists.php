<?php
/**
 * api/lists/user_lists.php — Fetch all lists the user can see.
 * Personal lists they own + group lists from their groups.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];

$sql = "SELECT l.id, l.name, l.user_id, l.group_id, l.created_at,
               u.username AS created_by,
               g.name AS group_name,
               (SELECT COUNT(*) FROM list_items li WHERE li.list_id = l.id) AS item_count,
               (SELECT COUNT(*) FROM list_items li WHERE li.list_id = l.id AND li.is_checked = 1) AS checked_count
        FROM lists l
        JOIN users u ON u.id = l.user_id
        LEFT JOIN `groups` g ON g.id = l.group_id
        WHERE
            (l.group_id IS NULL AND l.user_id = ?)
            OR
            (l.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))
        ORDER BY l.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$lists = [];
while ($row = $result->fetch_assoc()) {
    $lists[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'lists' => $lists]);
