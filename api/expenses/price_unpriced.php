<?php
/**
 * api/expenses/price_unpriced.php — Add a price to an unpriced item and convert it to an expense.
 * POST: item_id, price
 * The expense is recorded with the original checked_at date, not the current date.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);
$price  = floatval($_POST['price'] ?? 0);
$paidBy = !empty($_POST['paid_by']) ? (int) $_POST['paid_by'] : null;

if ($itemId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid item.']); exit; }
if ($price <= 0)  { echo json_encode(['ok' => false, 'error' => 'Price must be greater than 0.']); exit; }

// Fetch the unpriced item
$stmt = $conn->prepare(
    'SELECT li.id, li.description, li.category_id, li.checked_at, li.is_checked,
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

if (!$item) { echo json_encode(['ok' => false, 'error' => 'Item not found.']); exit; }
if (!(int)$item['is_checked']) { echo json_encode(['ok' => false, 'error' => 'Item is not checked.']); exit; }
if ((int)$item['expense_created']) { echo json_encode(['ok' => false, 'error' => 'Expense already created for this item.']); exit; }

$groupId = $item['group_id'] ? (int)$item['group_id'] : null;

// Permission check
if ($groupId) {
    $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $chk->bind_param('ii', $groupId, $userId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) { $chk->close(); echo json_encode(['ok' => false, 'error' => 'Access denied.']); exit; }
    $chk->close();
} else {
    if ((int)$item['list_owner'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
}

// Use checked_at date as the expense date (the date the item was completed)
$expDate    = date('Y-m-d', strtotime($item['checked_at']));
$expCatId   = $item['category_id'] ? (int)$item['category_id'] : 8; // default to 'Others'
$expNote    = $item['description'];
$expType    = $groupId ? 'group' : 'personal';

// For group items, paid_by is mandatory; for personal, it's the user
if ($groupId) {
    if (!$paidBy) {
        echo json_encode(['ok' => false, 'error' => 'Please select who paid for this item.']);
        exit;
    }
    // Validate paid_by is a group member
    $pbChk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $pbChk->bind_param('ii', $groupId, $paidBy);
    $pbChk->execute();
    $pbChk->store_result();
    if ($pbChk->num_rows === 0) { $pbChk->close(); echo json_encode(['ok' => false, 'error' => 'Payer must be a group member.']); exit; }
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

// Create the expense
$ins = $conn->prepare(
    'INSERT INTO expenses (user_id, paid_by, group_id, amount, category_id, note, expense_date, type, is_post_settlement, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$ins->bind_param('iiidisssii', $userId, $paidBy, $groupId, $price, $expCatId, $expNote, $expDate, $expType, $isPostSettlement, $userId);

if ($ins->execute()) {
    $expenseId = $ins->insert_id;
    $ins->close();

    // Update list item: set price, mark expense_created, store expense_id
    $upd = $conn->prepare('UPDATE list_items SET price = ?, expense_created = 1, expense_id = ? WHERE id = ?');
    $upd->bind_param('dii', $price, $expenseId, $itemId);
    $upd->execute();
    $upd->close();

    echo json_encode(['ok' => true, 'expense_id' => $expenseId, 'is_post_settlement' => (bool)$isPostSettlement]);
} else {
    $ins->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to create expense.']);
}
