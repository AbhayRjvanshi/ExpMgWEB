<?php
/**
 * api/expenses/list.php — Fetch expenses for a date or month.
 * GET params: date (YYYY-MM-DD) — returns expenses for that day
 *             month (YYYY-MM)   — returns expenses for that month
 * Returns JSON array of expenses.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$userId = (int) $_SESSION['user_id'];
$date   = trim($_GET['date']  ?? '');
$month  = trim($_GET['month'] ?? '');

// M4 (PENTEST/moderately sever/M4.md): calendar-valid date + real month
// range -- the shape-only checks accepted 2026-02-30 and 2026-13, which
// then queried empty ranges and reported success.
try {
    if ($date !== '') {
        validateDate($date, 'date');
    }
    if ($month !== '') {
        validateMonth($month, 'month');
    }
} catch (Exception $e) {
    apiResponse(['ok' => false, 'error' => 'Invalid date format.'], 400);
}
$total = 0;
$page = 1; $limit = 20; $offset = 0; // set per branch below

// Branch-tuned pagination: the ?date= branch keeps the documented default
// (20) and cap (50) pinned by the pagination contract (tests 32l/32o);
// month and custom-range queries feed full calendar views, charts, and PDF
// exports, so they use a high ceiling and never silently truncate.

// Build the query: personal expenses + group expenses for groups the user belongs to
// We use a UNION approach or a single query with OR conditions.

if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    [$page, $limit, $offset] = parsePagination(20, 50);
    $countSql = "SELECT COUNT(*) AS total
                 FROM expenses e
                 LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
                 WHERE e.expense_date = ?
                   AND (
                       (e.type = 'personal' AND e.user_id = ?)
                       OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
                   )";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('isi', $userId, $date, $userId);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // ---- Expenses for a single day ----
    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.paid_by, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   pb.username AS payer_username,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' AND gm_me.role = 'admin' THEN 1
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN users pb ON pb.id = e.paid_by
            LEFT JOIN `groups` g ON g.id = e.group_id
            LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
            WHERE e.expense_date = ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
              )
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisiii', $userId, $userId, $date, $userId, $limit, $offset);

} elseif ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    // ---- Expenses for a whole month ----
    [$page, $limit, $offset] = parsePagination(500, 1000);
    $startDate = $month . '-01';
    $endDate   = date('Y-m-t', strtotime($startDate));

    $countSql = "SELECT COUNT(*) AS total
                 FROM expenses e
                 LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
                 WHERE e.expense_date BETWEEN ? AND ?
                   AND (
                       (e.type = 'personal' AND e.user_id = ?)
                       OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
                   )";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('issi', $userId, $startDate, $endDate, $userId);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.paid_by, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   pb.username AS payer_username,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' AND gm_me.role = 'admin' THEN 1
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN users pb ON pb.id = e.paid_by
            LEFT JOIN `groups` g ON g.id = e.group_id
            LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
            WHERE e.expense_date BETWEEN ? AND ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
              )
            ORDER BY e.expense_date ASC, e.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissiii', $userId, $userId, $startDate, $endDate, $userId, $limit, $offset);

} elseif (isset($_GET['start'], $_GET['end'])
         && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['start']))
         && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['end']))) {
    // ---- Expenses for a date range ----
    [$page, $limit, $offset] = parsePagination(500, 1000);
    $startDate = trim($_GET['start']);
    $endDate   = trim($_GET['end']);

    $countSql = "SELECT COUNT(*) AS total
                 FROM expenses e
                 LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
                 WHERE e.expense_date BETWEEN ? AND ?
                   AND (
                       (e.type = 'personal' AND e.user_id = ?)
                       OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
                   )";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('issi', $userId, $startDate, $endDate, $userId);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT e.id, e.amount, e.note, e.expense_date, e.type, e.category_id,
                   e.group_id, e.user_id, e.paid_by, e.created_at,
                   c.name AS category_name,
                   u.username AS added_by,
                   pb.username AS payer_username,
                   g.name AS group_name,
                   CASE
                       WHEN e.type = 'personal' AND e.user_id = ? THEN 1
                       WHEN e.type = 'group' AND gm_me.role = 'admin' THEN 1
                       ELSE 0
                   END AS can_edit
            FROM expenses e
            JOIN categories c ON c.id = e.category_id
            JOIN users u ON u.id = e.user_id
            LEFT JOIN users pb ON pb.id = e.paid_by
            LEFT JOIN `groups` g ON g.id = e.group_id
            LEFT JOIN group_members gm_me ON gm_me.group_id = e.group_id AND gm_me.user_id = ?
            WHERE e.expense_date BETWEEN ? AND ?
              AND (
                  (e.type = 'personal' AND e.user_id = ?)
                  OR (e.type = 'group' AND gm_me.user_id IS NOT NULL)
              )
            ORDER BY e.expense_date ASC, e.created_at DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissiii', $userId, $userId, $startDate, $endDate, $userId, $limit, $offset);

} else {
    apiResponse((['ok' => false, 'error' => 'Provide ?date=YYYY-MM-DD or ?month=YYYY-MM or ?start=YYYY-MM-DD&end=YYYY-MM-DD']));
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

apiResponse(([
    'ok' => true,
    'expenses' => $expenses,
    'pagination' => paginationMeta($page, $limit, $total)
]));

} catch (Throwable $e) {
    logMessage('ERROR', 'Unhandled endpoint exception', [
        'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
        'method'   => $_SERVER['REQUEST_METHOD'] ?? '',
        'error'    => $e->getMessage(),
        'file'     => $e->getFile(),
        'line'     => $e->getLine(),
    ]);
    apiError('Internal server error.', 500);
}