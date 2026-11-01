<?php
/**
 * api/groups/details.php — Get full details of a group (members, expenses).
 * GET: group_id
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_GET['group_id'] ?? 0);

// Expenses are paginated; the member list is not (max 10 members per group,
// and the UI needs the whole roster to render roles and the settle button).
list($page, $limit, $offset) = parsePagination(500, 1000);

if ($groupId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid group.']));
    exit;
}

// Verify membership
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$myMembership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$myMembership) {
    apiResponse((['ok' => false, 'error' => 'You are not a member of this group.']));
    exit;
}

// Group info
$stmt = $conn->prepare('SELECT id, name, join_code, created_by, max_members, created_at FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Members
$stmt = $conn->prepare(
    'SELECT gm.user_id, u.username, gm.role, gm.joined_at
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = ?
     ORDER BY gm.role DESC, gm.joined_at ASC'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$result  = $stmt->get_result();
$members = [];
while ($row = $result->fetch_assoc()) $members[] = $row;
$stmt->close();

// Past group expenses (paginated, 500 per page by default)
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS cnt
     FROM expenses
     WHERE group_id = ? AND type = "group"'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$totalExpenses = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare(
    'SELECT e.id, e.amount, e.note, e.expense_date, e.category_id, e.paid_by,
            c.name AS category_name, u.username AS added_by, pb.username AS payer_username, e.created_at
     FROM expenses e
     JOIN categories c ON c.id = e.category_id
     JOIN users u ON u.id = e.user_id
     LEFT JOIN users pb ON pb.id = e.paid_by
     WHERE e.group_id = ? AND e.type = "group"
     ORDER BY e.expense_date DESC, e.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('iii', $groupId, $limit, $offset);
$stmt->execute();
$result   = $stmt->get_result();
$expenses = [];
while ($row = $result->fetch_assoc()) $expenses[] = $row;
$stmt->close();

apiResponse(([
    'ok'         => true,
    'group'      => $group,
    'my_role'    => $myMembership['role'],
    'members'    => $members,
    'expenses'   => $expenses,
    'pagination' => paginationMeta($page, $limit, $totalExpenses)
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