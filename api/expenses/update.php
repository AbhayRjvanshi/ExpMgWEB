<?php
/**
 * api/expenses/update.php — Edit an existing expense.
 * POST: id, amount, category_id, note, type, group_id (optional)
 * Permission: personal → owner only; group → admin only.
 * Returns JSON.
 */
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
$idemCtx = idempotencyGuard((int) $_SESSION['user_id'], 'expenses/update');

$userId      = (int) $_SESSION['user_id'];
$expenseId  = (int) ($_POST['id'] ?? 0);
// L-5 (PENTEST/low severity/L-5.md): field-merge PATCH semantics. The old
// replace-all behaviour required the full payload and -- for the fields
// without hard validation (note, group_id, paid_by) -- silently wiped them
// with ok:true when a partial payload passed the two hard gates.
// Omitted fields now inherit the stored values.
$amountRaw  = $_POST['amount'] ?? null;
$amount     = $amountRaw !== null && is_numeric($amountRaw) ? round((float) $amountRaw, 2) : null;
$categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : null;
$note       = array_key_exists('note', $_POST) ? trim($_POST['note']) : null;
$typeRaw    = $_POST['type'] ?? null;
$type       = $typeRaw === null ? null : ($typeRaw === 'group' ? 'group' : 'personal');
$groupId    = isset($_POST['group_id']) && $_POST['group_id'] !== '' ? (int) $_POST['group_id'] : (array_key_exists('group_id', $_POST) ? null : null);
$paidBy     = isset($_POST['paid_by']) && $_POST['paid_by'] !== '' ? (int) $_POST['paid_by'] : (array_key_exists('paid_by', $_POST) ? null : null);

if ($expenseId <= 0)  { echo json_encode(['ok' => false, 'error' => 'Invalid expense ID.']); exit; }
if ($amount !== null && $amount <= 0)     { echo json_encode(['ok' => false, 'error' => 'Amount must be > 0.']); exit; }
if ($amount !== null && $amount > 99999999.00) { echo json_encode(['ok' => false, 'error' => 'Amount must be at most 99999999.00.']); exit; }
if ($typeRaw !== null && !in_array($type, ['personal', 'group'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Type must be personal or group.']); exit;
}
if ($categoryId !== null && $categoryId <= 0) { echo json_encode(['ok' => false, 'error' => 'Select a category.']); exit; }
if ($note !== null && mb_strlen($note) > 255) { echo json_encode(['ok' => false, 'error' => 'Note is too long (max 255 characters).']); exit; }

// Fetch existing expense
$stmt = $conn->prepare('SELECT user_id, group_id, type, expense_date, amount, category_id, note, paid_by FROM expenses WHERE id = ?');
$stmt->bind_param('i', $expenseId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
    exit;
}

// L-5: merge omitted fields from the stored row.
$amount     = $amount     ?? (float) $existing['amount'];
$categoryId = $categoryId ?? (int) $existing['category_id'];
$note       = $note       ?? (string) $existing['note'];
$type       = $type       ?? (string) $existing['type'];
$groupId    = array_key_exists('group_id', $_POST) ? $groupId : ($existing['group_id'] !== null ? (int) $existing['group_id'] : null);
$paidBy     = array_key_exists('paid_by', $_POST) ? $paidBy  : ($existing['paid_by']  !== null ? (int) $existing['paid_by']  : null);

// --- Settlement lock check: block if expense is within a settled period ---
if ($existing['type'] === 'group' && $existing['group_id']) {
    $lockGid = (int)$existing['group_id'];
    $lockStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
    $lockStmt->bind_param('i', $lockGid);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if ($lockRow && $lockRow['last_end'] && $existing['expense_date'] <= $lockRow['last_end']) {
        echo json_encode(['ok' => false, 'error' => 'This expense date has already been settled and cannot be modified.']);
        exit;
    }
}
// Also check the target group if moving expense into a (different) group
if ($type === 'group' && $groupId && $groupId !== (int)($existing['group_id'] ?? 0)) {
    $lockStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
    $lockStmt->bind_param('i', $groupId);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if ($lockRow && $lockRow['last_end'] && $existing['expense_date'] <= $lockRow['last_end']) {
        echo json_encode(['ok' => false, 'error' => 'Cannot move expense into a group with a settled period covering this date.']);
        exit;
    }
}

// --- Permission check ---
if ($existing['type'] === 'personal') {
    if ((int)$existing['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'You can only edit your own personal expenses.']);
        exit;
    }
} else {
    // Group expense — only admin can edit (the JOIN also captures the group's
    // name for the notification below — no second SELECT after the UPDATE)
    $chk = $conn->prepare(
        'SELECT gm.role, g.name AS group_name
         FROM group_members gm
         JOIN `groups` g ON g.id = gm.group_id
         WHERE gm.group_id = ? AND gm.user_id = ?'
    );
    $gid = (int)$existing['group_id'];
    $chk->bind_param('ii', $gid, $userId);
    $chk->execute();
    $role = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$role || $role['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'error' => 'Only the group admin can edit group expenses.']);
        exit;
    }
    $groupName = $role['group_name'] ?? 'the group';
}

// For personal expenses, payer is always the current user
if ($type !== 'group') $paidBy = $userId;
// L-5: a personal expense never keeps a group attachment (create.php
// semantics); with merge semantics this matters when converting group -> personal.
if ($type !== 'group') $groupId = null;

// --- Update ---
$stmt = $conn->prepare(
    'UPDATE expenses SET amount = ?, category_id = ?, note = ?, type = ?, group_id = ?, paid_by = ? WHERE id = ?'
);
$stmt->bind_param('dissiii', $amount, $categoryId, $note, $type, $groupId, $paidBy, $expenseId);

if ($stmt->execute()) {
    $stmt->close();

    // Notify group members if group expense updated
    // ($groupName was captured during the permission check above)
    if ($existing['type'] === 'group' && $existing['group_id']) {
        $username = $_SESSION['username'];
        $msg = "$username updated an expense in $groupName.";
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "group_expense_update", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $gid, $gid, $userId);
        $n->execute();
        $n->close();
    }

    idempotencyRespond($idemCtx, ['ok' => true], 200);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to update expense.']);
}
