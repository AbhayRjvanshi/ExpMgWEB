<?php
/**
 * api/lists/delete.php — Delete a list.
 * POST: list_id
 * Personal list → only owner. Group list → admin or list creator only.
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
$listId = (int) ($_POST['list_id'] ?? 0);

if ($listId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid list.']));
    exit;
}

$stmt = $conn->prepare('SELECT user_id, group_id FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) {
    apiResponse((['ok' => false, 'error' => 'List not found.']));
    exit;
}

// Permission check
if ($list['group_id']) {
    $chk = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $gid = (int)$list['group_id'];
    $chk->bind_param('ii', $gid, $userId);
    $chk->execute();
    $role = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$role) {
        apiResponse((['ok' => false, 'error' => 'Access denied.']));
        exit;
    }
    // Only admin or the list creator can delete a group list
    if ($role['role'] !== 'admin' && (int)$list['user_id'] !== $userId) {
        apiResponse((['ok' => false, 'error' => 'Only the group admin or list creator can delete this list.']));
        exit;
    }
} else {
    if ((int)$list['user_id'] !== $userId) {
        apiResponse((['ok' => false, 'error' => 'You can only delete your own personal lists.']));
        exit;
    }
}

$stmt = $conn->prepare('DELETE FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
if ($stmt->execute()) {
    $stmt->close();
    apiResponse((['ok' => true]));
} else {
    $stmt->close();
    apiResponse((['ok' => false, 'error' => 'Failed to delete list.']));
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