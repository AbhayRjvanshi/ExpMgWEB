<?php
/**
 * api/expenses/list.php — Fetch expenses for a date or month.
 * GET params: date (YYYY-MM-DD) — returns expenses for that day
 *             month (YYYY-MM)   — returns expenses for that month
 * Returns JSON array of expenses.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$date   = trim($_GET['date']  ?? '');
$month  = trim($_GET['month'] ?? '');

// Build the query: personal expenses + group expenses for groups the user belongs to
// We use a UNION approach or a single query with OR conditions.

if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    // ---- Expenses for a single day ----
    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' THEN (
                           SELECT COUNT(*) FROM group_members gm2
                           WHERE gm2.group_id = e.group_id AND gm2.user_id = ? AND gm2.role = 'admin'
                       )
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN `groups` g ON g.id = e.group_id
            WHERE e.expense_date = ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR
                  (e.type = 'group' AND e.group_id IN (
                      SELECT group_id FROM group_members WHERE user_id = ?
                  ))
              )
            ORDER BY e.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisii', $userId, $userId, $date, $userId, $userId);

} elseif ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    // ---- Expenses for a whole month ----
    $startDate = $month . '-01';
    $endDate   = date('Y-m-t', strtotime($startDate));

    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' THEN (
                           SELECT COUNT(*) FROM group_members gm2
                           WHERE gm2.group_id = e.group_id AND gm2.user_id = ? AND gm2.role = 'admin'
                       )
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN `groups` g ON g.id = e.group_id
            WHERE e.expense_date BETWEEN ? AND ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR
                  (e.type = 'group' AND e.group_id IN (
                      SELECT group_id FROM group_members WHERE user_id = ?
                  ))
              )
            ORDER BY e.expense_date ASC, e.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissii', $userId, $userId, $startDate, $endDate, $userId, $userId);

} elseif (isset($_GET['start'], $_GET['end'])
         && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['start']))
         && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['end']))) {
    // ---- Expenses for a date range ----
    $startDate = trim($_GET['start']);
    $endDate   = trim($_GET['end']);

    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' THEN (
                           SELECT COUNT(*) FROM group_members gm2
                           WHERE gm2.group_id = e.group_id AND gm2.user_id = ? AND gm2.role = 'admin'
                       )
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN `groups` g ON g.id = e.group_id
            WHERE e.expense_date BETWEEN ? AND ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR
                  (e.type = 'group' AND e.group_id IN (
                      SELECT group_id FROM group_members WHERE user_id = ?
                  ))
              )
            ORDER BY e.expense_date ASC, e.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissii', $userId, $userId, $startDate, $endDate, $userId, $userId);

} else {
    echo json_encode(['ok' => false, 'error' => 'Provide ?date=YYYY-MM-DD or ?month=YYYY-MM or ?start=YYYY-MM-DD&end=YYYY-MM-DD']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$expenses = [];
while ($row = $result->fetch_assoc()) {
    $row['can_edit'] = (int)$row['can_edit'] > 0;
    $row['settled'] = false;
    $expenses[] = $row;
}
$stmt->close();

// For group expenses, determine if they are settled
// A group expense is settled if there's a settlement with period_end >= expense_date for that group
$groupIds = [];
foreach ($expenses as $e) {
    if ($e['type'] === 'group' && $e['group_id']) {
        $groupIds[(int)$e['group_id']] = true;
    }
}
if (!empty($groupIds)) {
    $gids = array_keys($groupIds);
    $placeholders = implode(',', array_fill(0, count($gids), '?'));
    $types = str_repeat('i', count($gids));
    $sql = "SELECT group_id, MAX(period_end) AS last_end FROM settlements WHERE group_id IN ($placeholders) GROUP BY group_id";
    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param($types, ...$gids);
    $stmt2->execute();
    $settlMap = [];
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) {
        $settlMap[(int)$r['group_id']] = $r['last_end'];
    }
    $stmt2->close();

    foreach ($expenses as &$e) {
        if ($e['type'] === 'group' && $e['group_id']) {
            $gid = (int)$e['group_id'];
            if (isset($settlMap[$gid]) && $e['expense_date'] <= $settlMap[$gid]) {
                $e['settled'] = true;
                $e['can_edit'] = false; // settled expenses are immutable
            }
        }
    }
    unset($e);
}

echo json_encode(['ok' => true, 'expenses' => $expenses]);
