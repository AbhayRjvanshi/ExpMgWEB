<?php
/**
 * api/expenses/summary.php — Aggregated spending data for a month.
 * GET ?month=YYYY-MM
 * Returns:
 *   total_spent, personal_total, group_total, group_share,
 *   by_category [{name, total}], by_day [{day, personal_total, group_total}],
 *   budget {amount_limit} | null
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];
$month  = trim($_GET['month'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['ok' => false, 'error' => 'Provide ?month=YYYY-MM']);
    exit;
}

$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// --- Total spent (personal + group where user is member) ---
$sql = "SELECT
            COALESCE(SUM(e.amount), 0) AS total_spent,
            COALESCE(SUM(CASE WHEN e.type='personal' THEN e.amount ELSE 0 END), 0) AS personal_total,
            COALESCE(SUM(CASE WHEN e.type='group'    THEN e.amount ELSE 0 END), 0) AS group_total,
            COALESCE(SUM(CASE WHEN e.type='group' THEN e.amount / NULLIF(gm.member_count, 0) ELSE 0 END), 0) AS group_share
        FROM expenses e
        LEFT JOIN (
            SELECT group_id, COUNT(*) AS member_count
            FROM group_members
            GROUP BY group_id
        ) gm ON gm.group_id = e.group_id
        WHERE e.expense_date BETWEEN ? AND ?
          AND (
              (e.type = 'personal' AND e.user_id = ?)
              OR
              (e.type = 'group' AND e.group_id IN (
                  SELECT group_id FROM group_members WHERE user_id = ?
              ))
          )";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssii', $startDate, $endDate, $userId, $userId);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- By category ---
$sql = "SELECT c.name, COALESCE(SUM(e.amount), 0) AS total
        FROM expenses e
        JOIN categories c ON c.id = e.category_id
        WHERE e.expense_date BETWEEN ? AND ?
          AND (
              (e.type = 'personal' AND e.user_id = ?)
              OR
              (e.type = 'group' AND e.group_id IN (
                  SELECT group_id FROM group_members WHERE user_id = ?
              ))
          )
        GROUP BY c.id, c.name
        ORDER BY total DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssii', $startDate, $endDate, $userId, $userId);
$stmt->execute();
$byCat = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $byCat[] = ['name' => $r['name'], 'total' => (float) $r['total']];
}
$stmt->close();

// --- By day ---
$sql = "SELECT
            DAY(e.expense_date) AS day_num,
            COALESCE(SUM(CASE WHEN e.type='personal' THEN e.amount ELSE 0 END), 0) AS personal_total,
            COALESCE(SUM(CASE WHEN e.type='group' THEN e.amount ELSE 0 END), 0) AS group_total
        FROM expenses e
        WHERE e.expense_date BETWEEN ? AND ?
          AND (
              (e.type = 'personal' AND e.user_id = ?)
              OR
              (e.type = 'group' AND e.group_id IN (
                  SELECT group_id FROM group_members WHERE user_id = ?
              ))
          )
        GROUP BY day_num
        ORDER BY day_num ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssii', $startDate, $endDate, $userId, $userId);
$stmt->execute();
$byDay = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $byDay[] = [
        'day' => (int) $r['day_num'],
        'personal_total' => (float) $r['personal_total'],
        'group_total' => (float) $r['group_total']
    ];
}
$stmt->close();

// --- Budget for this month ---
$sql = "SELECT amount_limit FROM budgets WHERE user_id = ? AND budget_month = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $userId, $month);
$stmt->execute();
$budgetRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'ok'             => true,
    'total_spent'    => (float) $totals['total_spent'],
    'personal_total' => (float) $totals['personal_total'],
    'group_total'    => (float) $totals['group_total'],
    'group_share'    => (float) $totals['group_share'],
    'by_category'    => $byCat,
    'by_day'         => $byDay,
    'budget'         => $budgetRow ? (float) $budgetRow['amount_limit'] : null,
    'month'          => $month
]);
