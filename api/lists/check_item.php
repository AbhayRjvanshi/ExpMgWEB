<?php
/**
 * api/lists/check_item.php — Toggle checked state of a list item.
 * POST: item_id
 * For group lists: checking opens a confirmation popup on the frontend.
 *   If paid_by is provided, the expense is created immediately.
 *   If paid_by is missing on a group item check, the item is marked checked
 *   but expense creation is deferred (returns needs_confirm=true).
 * POST (group confirm): item_id, paid_by, [price]
 * Notifies other group members.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/validator.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);
$paidByParam = isset($_POST['paid_by']) ? (int) $_POST['paid_by'] : null;
$priceParam  = isset($_POST['price']) && $_POST['price'] !== '' ? floatval($_POST['price']) : null;
$confirmMode = isset($_POST['confirm']) && $_POST['confirm'] === '1';

if ($itemId <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid item.']); exit; }

// Fetch item + list
$stmt = $conn->prepare(
    'SELECT li.id, li.list_id, li.description, li.is_checked, li.price,
            li.category_id, li.expense_created, li.checked_at, li.expense_id,
            l.user_id AS list_owner, l.group_id, l.name AS list_name
     FROM list_items li
     JOIN lists l ON l.id = li.list_id
     WHERE li.id = ?'
);
$stmt->bind_param('i', $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) { echo json_encode(['ok' => false, 'error' => 'Item not found.']); exit; }

$groupId = $item['group_id'] ? (int)$item['group_id'] : null;

// Permission
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

// === Confirm mode: group item popup confirmed with paid_by ===
if ($confirmMode && $groupId) {
    if (!$paidByParam) {
        echo json_encode(['ok' => false, 'error' => 'Please select who paid for this item.']);
        exit;
    }
    // Validate paid_by is a group member
    $memChk = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $memChk->bind_param('ii', $groupId, $paidByParam);
    $memChk->execute();
    $memChk->store_result();
    if ($memChk->num_rows === 0) { $memChk->close(); echo json_encode(['ok' => false, 'error' => 'Payer must be a group member.']); exit; }
    $memChk->close();

    // Re-read current state
    $now   = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    $expenseCreated = false;
    $isPostSettlement = false;

    // Atomic confirm flow: popup price, expense creation, and the item state
    // transition run in ONE transaction with a single collapsed item UPDATE
    // (previously up to three untransacted UPDATEs: price, mark-checked, link).
    $conn->begin_transaction();
    try {
        // If a price was provided in the popup, update the item price
        if ($priceParam !== null && $priceParam > 0) {
            $upPrice = $conn->prepare('UPDATE list_items SET price = ? WHERE id = ?');
            $upPrice->bind_param('di', $priceParam, $itemId);
            $upPrice->execute();
            $upPrice->close();
            $item['price'] = $priceParam;
        }

        // Create expense if item has a price and expense not yet created
        $itemPrice = $item['price'] !== null ? floatval($item['price']) : ($priceParam ?? 0);
        $createdExpenseId = null;
        if ($itemPrice > 0 && !(int)$item['expense_created']) {
            $expAmount  = $itemPrice;
            $expCatId   = $item['category_id'] ? (int)$item['category_id'] : 8;
            $expNote    = $item['description'];
            $expDate    = $today;
            $expType    = 'group';
            $expGroupId = $groupId;

            // Post-settlement check
            $postSettl = 0;
            $lockStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
            $lockStmt->bind_param('i', $groupId);
            $lockStmt->execute();
            $lockRow = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            if ($lockRow && $lockRow['last_end'] && $expDate <= $lockRow['last_end']) {
                $postSettl = 1;
            }

            $ins = $conn->prepare(
                'INSERT INTO expenses (user_id, paid_by, group_id, amount, category_id, note, expense_date, type, is_post_settlement, created_by, checked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->bind_param('iiidisssiii', $userId, $paidByParam, $expGroupId, $expAmount, $expCatId, $expNote, $expDate, $expType, $postSettl, $userId, $userId);
            if ($ins->execute()) {
                $createdExpenseId = $ins->insert_id;
                $expenseCreated = true;
                $isPostSettlement = (bool)$postSettl;
            }
            $ins->close();
        }

        // ONE collapsed item update — checked state (only when not already
        // checked, preserving the original checked_at 10-minute-window anchor
        // from the initial check) plus the expense link.
        if ($createdExpenseId !== null) {
            if (!(int)$item['is_checked']) {
                $updItem = $conn->prepare('UPDATE list_items SET is_checked = 1, checked_at = ?, expense_created = 1, expense_id = ? WHERE id = ?');
                $updItem->bind_param('sii', $now, $createdExpenseId, $itemId);
            } else {
                $updItem = $conn->prepare('UPDATE list_items SET expense_created = 1, expense_id = ? WHERE id = ?');
                $updItem->bind_param('ii', $createdExpenseId, $itemId);
            }
        } elseif (!(int)$item['is_checked']) {
            $updItem = $conn->prepare('UPDATE list_items SET is_checked = 1, checked_at = ? WHERE id = ?');
            $updItem->bind_param('si', $now, $itemId);
        } else {
            $updItem = null;
        }
        if ($updItem) {
            $updItem->execute();
            $updItem->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        logMessage('ERROR', 'Check-confirm transaction failed', [
            'item_id' => $itemId,
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        echo json_encode(['ok' => false, 'error' => 'Failed to confirm purchase.']);
        exit;
    }

    // Notify group
    $username = $_SESSION['username'];
    $msg = "$username checked \"{$item['description']}\" in the list \"{$item['list_name']}\".";
    $listId = (int)$item['list_id'];
    $n = $conn->prepare(
        'INSERT INTO notifications (user_id, message, type, reference_id)
         SELECT user_id, ?, "list_item_check", ?
         FROM group_members WHERE group_id = ? AND user_id != ?'
    );
    $n->bind_param('siii', $msg, $listId, $groupId, $userId);
    $n->execute();
    $n->close();

    echo json_encode([
        'ok' => true,
        'is_checked' => 1,
        'expense_created' => $expenseCreated,
        'is_post_settlement' => $isPostSettlement
    ]);
    exit;
}

// === Normal toggle ===
$newState = (int)$item['is_checked'] ? 0 : 1;
$now   = date('Y-m-d H:i:s');
$today = date('Y-m-d');

if ($newState === 0) {
    // --- Unchecking: enforce 10-minute window ---
    if ($item['checked_at']) {
        $checkedTs = strtotime($item['checked_at']);
        $elapsed   = time() - $checkedTs;
        if ($elapsed > 600) {
            echo json_encode(['ok' => false, 'error' => 'Cannot uncheck — more than 10 minutes have passed.']);
            exit;
        }
    }

    // Delete the linked expense and reset the item atomically: ONE transaction
    // with a single collapsed UPDATE (previously up to two untransacted UPDATEs
    // left a window where the expense was gone but the item still claimed one).
    $conn->begin_transaction();
    try {
        if ((int)$item['expense_created']) {
            // Use stored expense_id for precise deletion; fall back to matching if missing
            if (!empty($item['expense_id'])) {
                $del = $conn->prepare('DELETE FROM expenses WHERE id = ?');
                $del->bind_param('i', $item['expense_id']);
            } else {
                $del = $conn->prepare(
                    'DELETE FROM expenses
                     WHERE note = ? AND expense_date = ?
                       AND amount = ? AND type = ?
                       AND group_id <=> ?
                     ORDER BY created_at DESC LIMIT 1'
                );
                $expType = $groupId ? 'group' : 'personal';
                $expAmt  = floatval($item['price']);
                $expDate = $item['checked_at'] ? date('Y-m-d', strtotime($item['checked_at'])) : $today;
                $del->bind_param('ssdsi', $item['description'], $expDate, $expAmt, $expType, $groupId);
            }
            $del->execute();
            $del->close();
        }

        $stmt = $conn->prepare('UPDATE list_items SET is_checked = 0, checked_at = NULL, expense_created = 0, expense_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        logMessage('ERROR', 'Uncheck transaction failed', [
            'item_id' => $itemId,
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        echo json_encode(['ok' => false, 'error' => 'Failed to uncheck item.']);
        exit;
    }

    // Notify group on uncheck (best-effort, outside the transaction)
    if ($groupId) {
        $username = $_SESSION['username'];
        $msg = "$username unchecked \"{$item['description']}\" in the list \"{$item['list_name']}\".";
        $listId = (int)$item['list_id'];
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "list_item_check", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $listId, $groupId, $userId);
        $n->execute();
        $n->close();
    }

    echo json_encode(['ok' => true, 'is_checked' => 0, 'expense_created' => false, 'is_post_settlement' => false]);
    exit;
}

// --- Checking item ---
// For group lists: just mark checked and return needs_confirm for popup
if ($groupId) {
    $stmt = $conn->prepare('UPDATE list_items SET is_checked = 1, checked_at = ? WHERE id = ?');
    $stmt->bind_param('si', $now, $itemId);
    if ($stmt->execute()) {
        $stmt->close();

        // Fetch group members for the popup dropdown
        $memStmt = $conn->prepare(
            'SELECT gm.user_id, u.username FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ? ORDER BY u.username ASC'
        );
        $memStmt->bind_param('i', $groupId);
        $memStmt->execute();
        $members = [];
        $memRes = $memStmt->get_result();
        while ($m = $memRes->fetch_assoc()) $members[] = $m;
        $memStmt->close();

        echo json_encode([
            'ok' => true,
            'is_checked' => 1,
            'needs_confirm' => true,
            'item' => [
                'id' => (int)$item['id'],
                'description' => $item['description'],
                'price' => $item['price'],
                'category_name' => null,
                'date' => $today
            ],
            'members' => $members
        ]);
    } else {
        $stmt->close();
        echo json_encode(['ok' => false, 'error' => 'Failed to update item.']);
    }
    exit;
}

// For personal lists: check and auto-create the expense atomically — ONE
// transaction, expense INSERT first, then a single collapsed item UPDATE
// (previously UPDATE → INSERT → second UPDATE, all untransacted, risking an
// orphan expense with expense_created = 0 on failure between the two writes).
$expenseCreated = false;
$conn->begin_transaction();
try {
    $createdExpenseId = null;
    if ($item['price'] !== null && floatval($item['price']) > 0 && !(int)$item['expense_created']) {
        $expAmount   = floatval($item['price']);
        $expCatId    = $item['category_id'] ? (int)$item['category_id'] : 8;
        $expNote     = $item['description'];
        $expDate     = $today;
        $expType     = 'personal';

        $ins = $conn->prepare(
            'INSERT INTO expenses (user_id, paid_by, group_id, amount, category_id, note, expense_date, type, is_post_settlement, created_by)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, ?)'
        );
        $ins->bind_param('iisisssi', $userId, $userId, $expAmount, $expCatId, $expNote, $expDate, $expType, $userId);
        if ($ins->execute()) {
            $createdExpenseId = $ins->insert_id;
            $expenseCreated = true;
        }
        $ins->close();
    }

    if ($createdExpenseId !== null) {
        $stmt = $conn->prepare('UPDATE list_items SET is_checked = 1, checked_at = ?, expense_created = 1, expense_id = ? WHERE id = ?');
        $stmt->bind_param('sii', $now, $createdExpenseId, $itemId);
    } else {
        $stmt = $conn->prepare('UPDATE list_items SET is_checked = 1, checked_at = ? WHERE id = ?');
        $stmt->bind_param('si', $now, $itemId);
    }
    $stmt->execute();
    $stmt->close();
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    logMessage('ERROR', 'Personal check transaction failed', [
        'item_id' => $itemId,
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
    echo json_encode(['ok' => false, 'error' => 'Failed to update item.']);
    exit;
}

echo json_encode(['ok' => true, 'is_checked' => 1, 'expense_created' => $expenseCreated, 'is_post_settlement' => false]);
exit;
