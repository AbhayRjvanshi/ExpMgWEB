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

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
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

if ($expenseId <= 0)  { echo json_encode(['ok' => false, 'error' => 'Invalid expense ID.']); exit; }
if ($amount <= 0)     { echo json_encode(['ok' => false, 'error' => 'Amount must be > 0.']); exit; }
if ($categoryId <= 0) { echo json_encode(['ok' => false, 'error' => 'Select a category.']); exit; }

// Fetch existing expense
$stmt = $conn->prepare('SELECT user_id, group_id, type FROM expenses WHERE id = ?');
$stmt->bind_param('i', $expenseId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
    exit;
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

// --- Update ---
$stmt = $conn->prepare(
    'UPDATE expenses SET amount = ?, category_id = ?, note = ?, type = ?, group_id = ? WHERE id = ?'
);
$stmt->bind_param('dissii', $amount, $categoryId, $note, $type, $groupId, $expenseId);

if ($stmt->execute()) {
    $stmt->close();

    // Notify group members if group expense updated
    if ($existing['type'] === 'group' && $existing['group_id']) {
        $username = $_SESSION['username'];
        $msg = "$username updated an expense in the group.";
        $gid = (int)$existing['group_id'];
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "group_expense_update", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $expenseId, $gid, $userId);
        $n->execute();
        $n->close();
    }

    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to update expense.']);
}
