<?php
/**
 * api/lists/user_lists.php — Fetch all lists the user can see.
 * Personal lists they own + group lists from their groups.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$userId = (int) $_SESSION['user_id'];

// High ceiling by design: the lists UI renders every list at once and never
// sends ?limit=, so a 20-row default would silently truncate the sidebar.
list($page, $limit, $offset) = parsePagination(500, 1000);

$countSql = "SELECT COUNT(*) AS cnt
        FROM lists l
        WHERE
            (l.group_id IS NULL AND l.user_id = ?)
            OR
            (l.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))";

$stmt = $conn->prepare($countSql);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$totalLists = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$sql = "SELECT l.id, l.name, l.user_id, l.group_id, l.created_at,
               u.username AS created_by,
               g.name AS group_name,
               li_counts.item_count, li_counts.checked_count
        FROM lists l
        JOIN users u ON u.id = l.user_id
        LEFT JOIN `groups` g ON g.id = l.group_id
        LEFT JOIN (
            SELECT list_id,
                   COUNT(*) AS item_count,
                   COALESCE(SUM(is_checked = 1), 0) AS checked_count
            FROM list_items
            GROUP BY list_id
        ) li_counts ON li_counts.list_id = l.id
        WHERE
            (l.group_id IS NULL AND l.user_id = ?)
            OR
            (l.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iiii', $userId, $userId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$lists = [];
while ($row = $result->fetch_assoc()) {
    $lists[] = $row;
}
$stmt->close();

apiResponse(([
    'ok'         => true,
    'lists'      => $lists,
    'pagination' => paginationMeta($page, $limit, $totalLists)
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