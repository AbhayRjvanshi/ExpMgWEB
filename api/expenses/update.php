<?php
/**
 * api/expenses/update.php — Edit an existing expense.
 * POST: id, amount, category_id, note, type, group_id (optional)
 * Permission: personal → owner only; group → admin only.
 * Returns JSON.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId     = (int) $_SESSION['user_id'];
$expenseId  = (int) ($_POST['id'] ?? 0);
$amount     = floatval($_POST['amount'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$note       = trim($_POST['note'] ?? '');
$type       = ($_POST['type'] ?? 'personal') === 'group' ? 'group' : 'personal';
$groupId    = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;
$paidBy     = !empty($_POST['paid_by']) ? (int) $_POST['paid_by'] : null;

if ($expenseId <= 0)  { echo json_encode(['ok' => false, 'error' => 'Invalid expense ID.']); exit; }
if ($amount <= 0)     { echo json_encode(['ok' => false, 'error' => 'Amount must be > 0.']); exit; }
if ($categoryId <= 0) { echo json_encode(['ok' => false, 'error' => 'Select a category.']); exit; }

// Fetch existing expense
$stmt = $conn->prepare('SELECT user_id, group_id, type, expense_date FROM expenses WHERE id = ?');
$stmt->bind_param('i', $expenseId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
    exit;
}

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
    // Group expense — only admin can edit
    $chk = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $gid = (int)$existing['group_id'];
    $chk->bind_param('ii', $gid, $userId);
    $chk->execute();
    $role = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$role || $role['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'error' => 'Only the group admin can edit group expenses.']);
        exit;
    }
}

// For personal expenses, payer is always the current user
if ($type !== 'group') $paidBy = $userId;

// --- Update ---
$stmt = $conn->prepare(
    'UPDATE expenses SET amount = ?, category_id = ?, note = ?, type = ?, group_id = ?, paid_by = ? WHERE id = ?'
);
$stmt->bind_param('dissiii', $amount, $categoryId, $note, $type, $groupId, $paidBy, $expenseId);

if ($stmt->execute()) {
    $stmt->close();

    // Notify group members if group expense updated
    if ($existing['type'] === 'group' && $existing['group_id']) {
        $gid = (int)$existing['group_id'];
        $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
        $gStmt->bind_param('i', $gid);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        $groupName = $gRow ? $gRow['name'] : 'the group';

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

    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to update expense.']);
}
