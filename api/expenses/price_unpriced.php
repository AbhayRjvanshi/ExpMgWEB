<?php
/**
 * api/expenses/price_unpriced.php — Add a price to an unpriced item and convert it to an expense.
 * POST: item_id, price
 * The expense is recorded with the original checked_at date, not the current date.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';
try {
requirePostMethod();

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse((['ok' => false, 'error' => 'Invalid method.']));
    exit;
}

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
$idemCtx = idempotencyGuard((int) $_SESSION['user_id'], 'expenses/price_unpriced');

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);
// H3: round to 2dp + business ceiling (0.01 .. 99999999.00) -- the priced
// amount flows straight into expenses.amount (DECIMAL(12,2)).
$priceRaw = $_POST['price'] ?? 0;
$price  = is_numeric($priceRaw) ? round((float) $priceRaw, 2) : 0.0;
$paidBy = !empty($_POST['paid_by']) ? (int) $_POST['paid_by'] : null;

if ($itemId <= 0) { apiResponse((['ok' => false, 'error' => 'Invalid item.'])); exit; }
if ($price <= 0)  { apiResponse((['ok' => false, 'error' => 'Price must be greater than 0.'])); exit; }
if ($price > 99999999.00) { apiResponse((['ok' => false, 'error' => 'Price must be at most 99999999.00.'])); exit; }

// Fetch the unpriced item
$stmt = $conn->prepare(
    'SELECT li.id, li.description, li.category_id, li.checked_at, li.created_at, li.is_checked,
            li.expense_created, li.list_id,
            l.user_id AS list_owner, l.group_id
     FROM list_items li
     JOIN lists l ON l.id = li.list_id
     WHERE li.id = ?'
);
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) { apiResponse((['ok' => false, 'error' => 'Item not found.'])); exit; }
if (!(int)$item['is_checked']) { apiResponse((['ok' => false, 'error' => 'Item is not checked.'])); exit; }
if ((int)$item['expense_created']) { apiResponse((['ok' => false, 'error' => 'Expense already created for this item.'])); exit; }

$groupId = $item['group_id'] ? (int)$item['group_id'] : null;

// Permission check
if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) { $chk->close(); apiResponse((['ok' => false, 'error' => 'Access denied.'])); exit; }
    $chk->close();
} else {
    if ((int)$item['list_owner'] !== $userId) {
        apiResponse((['ok' => false, 'error' => 'Access denied.']));
        exit;
    }
}

// Use checked_at date as the expense date (the date the item was completed).
// Guard against NULL/invalid checked_at so the expense never receives the
// Unix-epoch fallback date 1970-01-01 (strtotime(null) === false):
// fall back to created_at first, then to today's date.
$rawTimestamp = !empty($item['checked_at']) ? strtotime($item['checked_at']) : false;
if ($rawTimestamp === false || $rawTimestamp <= 0) {
    $rawTimestamp = !empty($item['created_at']) ? strtotime($item['created_at']) : false;
}
$expDate = ($rawTimestamp !== false && $rawTimestamp > 0)
    ? date('Y-m-d', $rawTimestamp)
    : date('Y-m-d');
$expCatId   = $item['category_id'] ? (int)$item['category_id'] : 8; // default to 'Others'
$expNote    = $item['description'];
$expType    = $groupId ? 'group' : 'personal';

// For group items, paid_by is mandatory; for personal, it's the user
if ($groupId) {
    if (!$paidBy) {
        apiResponse((['ok' => false, 'error' => 'Please select who paid for this item.']));
        exit;
    }
    // Validate paid_by is a group member
    $pbChk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $pbChk->bind_param('ii', $groupId, $paidBy);
    $pbChk->execute();
    $pbChk->store_result();
    if ($pbChk->num_rows === 0) { $pbChk->close(); apiResponse((['ok' => false, 'error' => 'Payer must be a group member.'])); exit; }
    $pbChk->close();
} else {
    $paidBy = $userId;
}

// Check if this is a post-settlement expense (group item whose checked_at falls within a settled period)
$isPostSettlement = 0;
if ($groupId) {
    $lockStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
    $lockStmt->bind_param('i', $groupId);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if ($lockRow && $lockRow['last_end'] && $expDate <= $lockRow['last_end']) {
        $isPostSettlement = 1;
    }
}

// Create the expense and link the item atomically: transaction + FOR UPDATE
// re-check + affected-rows guard so two concurrent pricing requests for the
// same item can never create duplicate expenses.
$conn->begin_transaction();
try {
    // Re-check under row lock — a concurrent request may have won the race.
    $lockItem = $conn->prepare('SELECT expense_created FROM list_items WHERE id = ? FOR UPDATE');
    $lockItem->bind_param('i', $itemId);
    $lockItem->execute();
    $fresh = $lockItem->get_result()->fetch_assoc();
    $lockItem->close();

    if ($fresh && (int)$fresh['expense_created']) {
        $conn->rollback();
        apiResponse((['ok' => false, 'error' => 'Expense already created for this item.']));
        exit;
    }

    $ins = $conn->prepare(
        'INSERT INTO expenses (user_id, paid_by, group_id, amount, category_id, note, expense_date, type, is_post_settlement, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param('iiidisssii', $userId, $paidBy, $groupId, $price, $expCatId, $expNote, $expDate, $expType, $isPostSettlement, $userId);

    if (!$ins->execute()) {
        $ins->close();
        $conn->rollback();
        apiResponse((['ok' => false, 'error' => 'Failed to create expense.']));
        exit;
    }
    $expenseId = $ins->insert_id;
    $ins->close();

    // Guarded link: AND expense_created = 0 makes the write idempotent even
    // if two requests somehow passed the FOR UPDATE re-check.
    $upd = $conn->prepare('UPDATE list_items SET price = ?, expense_created = 1, expense_id = ? WHERE id = ? AND expense_created = 0');
    $upd->bind_param('dii', $price, $expenseId, $itemId);
    $upd->execute();
    $linked = $conn->affected_rows;
    $upd->close();

    if ($linked !== 1) {
        $conn->rollback();
        apiResponse((['ok' => false, 'error' => 'Item was concurrently priced.']));
        exit;
    }

    $conn->commit();
    idempotencyRespond($idemCtx, ['ok' => true, 'expense_id' => $expenseId, 'is_post_settlement' => (bool)$isPostSettlement], 200);
} catch (Exception $e) {
    $conn->rollback();
    logMessage('ERROR', 'Unpriced pricing transaction failed', [
        'item_id' => $itemId,
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
    apiResponse((['ok' => false, 'error' => 'Failed to create expense.']));
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