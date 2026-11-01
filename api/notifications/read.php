<?php
/**
 * api/notifications/read.php — Mark notifications as read.
 * POST: id (single) or all=1 (mark all as read)
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';
require_once __DIR__ . '/../helpers/response.php';
try {
requirePostMethod();

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse((['ok' => false, 'error' => 'Invalid method.']));
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (isset($_POST['all']) && $_POST['all'] == '1') {
    // Mark all as read
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    apiResponse((['ok' => true, 'marked' => $affected]));
    exit;
}

// event_id is the canonical name (ephemeral store, section 34); 'id' remains
// as the legacy alias older clients still send. Both resolve to the MySQL row id.
$notifId = (int)($_POST['event_id'] ?? $_POST['id'] ?? 0);
if ($notifId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid notification ID.']));
    exit;
}

// Only mark if it belongs to this user
$stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $notifId, $userId);
$stmt->execute();
// L-1/G-4 (PENTEST/low severity/): the mark-all branch already returns the
// affected count; the single-id branch now does too. A zero count covers
// "doesn't exist" and "not yours" identically (no enumeration oracle), and
// the WARNING log makes probing visible without changing the wire contract.
$marked = $stmt->affected_rows;
$stmt->close();

if ($marked === 0) {
    logMessage('WARNING', 'Notification mark had no effect', [
        'user_id' => $userId,
        'event_id' => $notifId,
    ]);
}

apiResponse((['ok' => true, 'marked' => $marked]));

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