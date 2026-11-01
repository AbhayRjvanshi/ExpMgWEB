<?php
/**
 * api/groups/user_groups.php — Return groups the current user belongs to (for dropdowns).
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {

requireAuth();

$userId = (int)$_SESSION['user_id'];

// High ceiling by design: group dropdowns load everything and never send
// ?limit=, so the generic 20-row default would truncate the list.
list($page, $limit, $offset) = parsePagination(500, 1000);

$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM group_members WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$totalGroups = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare(
    'SELECT g.id, g.name, gm.role
     FROM `groups` g
     JOIN group_members gm ON gm.group_id = g.id
     WHERE gm.user_id = ?
     ORDER BY g.name
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('iii', $userId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$groups = [];
while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}
$stmt->close();

apiResponse(([
    'ok'         => true,
    'groups'     => $groups,
    'pagination' => paginationMeta($page, $limit, $totalGroups)
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