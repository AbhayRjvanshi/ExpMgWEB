<?php
/**
 * api/expenses/unpriced.php — List unpriced checked items waiting for price entry.
 * GET: month (optional, YYYY-MM) — filters by checked_at month; defaults to all
 * Returns items that are checked, have no price, and haven't been converted to expenses.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];

// Unpriced items: checked, no price, expense not created
// Personal lists owned by user + group lists where user is a member
$sql = "SELECT li.id, li.description, li.category_id, li.checked_at, li.list_id,
               c.name AS category_name,
               l.name AS list_name, l.group_id,
               g.name AS group_name
        FROM list_items li
        JOIN lists l ON l.id = li.list_id
        LEFT JOIN categories c ON c.id = li.category_id
        LEFT JOIN `groups` g ON g.id = l.group_id
        WHERE li.is_checked = 1
          AND li.price IS NULL
          AND li.expense_created = 0
          AND (
              (l.group_id IS NULL AND l.user_id = ?)
              OR
              (l.group_id IS NOT NULL AND l.group_id IN (
                  SELECT group_id FROM group_members WHERE user_id = ?
              ))
          )
        ORDER BY li.checked_at DESC, li.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'items' => $items]);
