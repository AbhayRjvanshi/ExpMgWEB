<?php
/**
 * api/lists/add_item.php — Add an item to a list.
 * POST: list_id, description, category_id (optional), priority (high|moderate|low)
 * Any member can add to a group list. Owner can add to personal list.
 * Notifies other group members.
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

$userId      = (int) $_SESSION['user_id'];
$listId      = (int) ($_POST['list_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$categoryId  = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
$priority    = $_POST['priority'] ?? 'low';
$price       = isset($_POST['price']) && $_POST['price'] !== '' ? round((float) $_POST['price'], 2) : null;

// M6 (PENTEST/moderately sever/M6.md): reject instead of silently coercing.
// 'urgent' used to become 'low' and price '-5' used to become NULL (turning a
// priced item into an unpriced one) -- both now fail with an explicit error.
if (!in_array($priority, ['high', 'moderate', 'low'], true)) {
    apiResponse((['ok' => false, 'error' => 'Priority must be high, moderate or low.']));
    exit;
}
if ($price !== null && ($price < 0.01 || $price > 99999999.00)) {
    apiResponse((['ok' => false, 'error' => 'Price must be between 0.01 and 99999999.00.']));
    exit;
}
if ($listId <= 0)          { apiResponse((['ok' => false, 'error' => 'Invalid list.'])); exit; }
if ($description === '')   { apiResponse((['ok' => false, 'error' => 'Description is required.'])); exit; }

// Fetch list for permission check
$stmt = $conn->prepare('SELECT user_id, group_id, name FROM lists WHERE id = ?');
$stmt->bind_param('i', $listId);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) { apiResponse((['ok' => false, 'error' => 'List not found.'])); exit; }

$groupId = $list['group_id'] ? (int)$list['group_id'] : null;

if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) { $chk->close(); apiResponse((['ok' => false, 'error' => 'Access denied.'])); exit; }
    $chk->close();
} else {
    if ((int)$list['user_id'] !== $userId) {
        apiResponse((['ok' => false, 'error' => 'Access denied.']));
        exit;
    }
}

// Insert item
$stmt = $conn->prepare(
    'INSERT INTO list_items (list_id, description, category_id, priority, price, added_by) VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('isisdi', $listId, $description, $categoryId, $priority, $price, $userId);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    // Notify group members if group list
    if ($groupId) {
        $username = $_SESSION['username'];
        $msg = "$username added \"{$description}\" to the list \"{$list['name']}\".";
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "list_item_add", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $listId, $groupId, $userId);
        $n->execute();
        $n->close();
    }

    apiResponse((['ok' => true, 'id' => $newId]));
} else {
    $stmt->close();
    apiResponse((['ok' => false, 'error' => 'Failed to add item.']));
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