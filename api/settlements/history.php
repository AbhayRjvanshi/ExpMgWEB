<?php
/**
 * api/settlements/history.php — Past settlements for a group.
 * GET ?group_id=N
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

if ($groupId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid group.']));
    exit;
}

// Verify membership
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    apiResponse((['ok' => false, 'error' => 'Not a member of this group.']));
    exit;
}
$stmt->close();

// Settlement history can grow without bound, so it is genuinely paginated.
// Default ceiling stays high (500) so the UI keeps seeing full history.
list($page, $limit, $offset) = parsePagination(500, 1000);

$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM settlements WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$totalSettlements = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare(
    'SELECT s.id, s.payer_id, s.payee_id, s.amount, s.period_start, s.period_end, s.created_at,
            payer.username AS payer_name, payee.username AS payee_name
     FROM settlements s
     JOIN users payer ON payer.id = s.payer_id
     JOIN users payee ON payee.id = s.payee_id
     WHERE s.group_id = ?
     ORDER BY s.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('iii', $groupId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$history = [];
while ($r = $result->fetch_assoc()) {
    $history[] = [
        'id'           => (int) $r['id'],
        'payer_id'     => (int) $r['payer_id'],
        'payee_id'     => (int) $r['payee_id'],
        'payer_name'   => $r['payer_name'],
        'payee_name'   => $r['payee_name'],
        'amount'       => (float) $r['amount'],
        'period_start' => $r['period_start'],
        'period_end'   => $r['period_end'],
        'created_at'   => $r['created_at']
    ];
}
$stmt->close();

apiResponse(([
    'ok'          => true,
    'settlements' => $history,
    'pagination'  => paginationMeta($page, $limit, $totalSettlements)
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