<?php
/**
 * api/expenses/delete.php — Remove an expense.
 * POST: id
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

$userId    = (int) $_SESSION['user_id'];
$expenseId = (int) ($_POST['id'] ?? 0);

if ($expenseId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid expense ID.']);
    exit;
}

// Fetch existing
$stmt = $conn->prepare('SELECT user_id, group_id, type, amount, expense_date FROM expenses WHERE id = ?');
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

// --- Permission check ---
if ($existing['type'] === 'personal') {
    if ((int)$existing['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'You can only delete your own personal expenses.']);
        exit;
    }
} else {
    // Group expense — only admin can delete
    $chk = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $gid = (int)$existing['group_id'];
    $chk->bind_param('ii', $gid, $userId);
    $chk->execute();
    $role = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$role || $role['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'error' => 'Only the group admin can delete group expenses.']);
        exit;
    }
}

// --- Delete ---
$stmt = $conn->prepare('DELETE FROM expenses WHERE id = ?');
$stmt->bind_param('i', $expenseId);

if ($stmt->execute()) {
    $stmt->close();

    // Notify group members if group expense deleted
    if ($existing['type'] === 'group' && $existing['group_id']) {
        $gid = (int)$existing['group_id'];
        $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
        $gStmt->bind_param('i', $gid);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        $groupName = $gRow ? $gRow['name'] : 'the group';

        $username = $_SESSION['username'];
        $msg = "$username removed an expense from $groupName.";
        $n = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "group_expense_delete", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $n->bind_param('siii', $msg, $gid, $gid, $userId);
        $n->execute();
        $n->close();
    }

    echo json_encode(['ok' => true]);
} else {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Failed to delete expense.']);
}
