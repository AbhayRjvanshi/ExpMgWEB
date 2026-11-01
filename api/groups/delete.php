<?php
/**
 * api/groups/delete.php — Delete a group (admin only).
 * POST: group_id
 * Cascades: all group_members, expenses (via ON DELETE CASCADE / SET NULL).
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

// Only creator (admin) can delete
$stmt = $conn->prepare('SELECT created_by FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    apiResponse((['ok' => false, 'error' => 'Group not found.']));
    exit;
}

if ((int)$group['created_by'] !== $userId) {
    apiResponse((['ok' => false, 'error' => 'Only the group creator can delete the group.']));
    exit;
}

// Get group name + members before cascade delete
$gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
$gStmt->bind_param('i', $groupId);
$gStmt->execute();
$gRow = $gStmt->get_result()->fetch_assoc();
$gStmt->close();
$groupName = $gRow ? $gRow['name'] : 'the group';

$mStmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ?');
$mStmt->bind_param('ii', $groupId, $userId);
$mStmt->execute();
$mRes = $mStmt->get_result();
$memberIds = [];
while ($r = $mRes->fetch_assoc()) $memberIds[] = (int)$r['user_id'];
$mStmt->close();

$stmt = $conn->prepare('DELETE FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $groupId);
if ($stmt->execute()) {
    $stmt->close();

    // Notify former members
    if (!empty($memberIds)) {
        $username = $_SESSION['username'];
        $msg = "$username deleted the group \"$groupName\".";
        $nStmt = $conn->prepare('INSERT INTO notifications (user_id, message, type, reference_id) VALUES (?, ?, "group_delete", ?)');
        foreach ($memberIds as $mid) {
            $nStmt->bind_param('isi', $mid, $msg, $groupId);
            $nStmt->execute();
        }
        $nStmt->close();
    }

    apiResponse((['ok' => true]));
} else {
    $stmt->close();
    apiResponse((['ok' => false, 'error' => 'Failed to delete group.']));
}

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