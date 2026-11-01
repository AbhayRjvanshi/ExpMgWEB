<?php
/**
 * api/groups/leave.php — Leave a group.
 * POST: group_id
 * Admin cannot leave — they must delete the group instead (or transfer, future feature).
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

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid group.']));
    exit;
}

// Check membership + role
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    apiResponse((['ok' => false, 'error' => 'You are not a member of this group.']));
    exit;
}

if ($member['role'] === 'admin') {
    apiResponse((['ok' => false, 'error' => 'Admin cannot leave the group. Delete the group instead.']));
    exit;
}

// Get group name for notification
$gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
$gStmt->bind_param('i', $groupId);
$gStmt->execute();
$gRow = $gStmt->get_result()->fetch_assoc();
$gStmt->close();
$groupName = $gRow ? $gRow['name'] : 'the group';

// Remove member
$stmt = $conn->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->close();

// Notify remaining group members
$username = $_SESSION['username'];
$msg = "$username left the group \"$groupName\".";
$nStmt = $conn->prepare(
    'INSERT INTO notifications (user_id, message, type, reference_id)
     SELECT user_id, ?, "group_leave", ?
     FROM group_members WHERE group_id = ?'
);
$nStmt->bind_param('sii', $msg, $groupId, $groupId);
$nStmt->execute();
$nStmt->close();

apiResponse((['ok' => true]));

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