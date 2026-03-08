<?php
/**
 * api/expenses/create.php — Add a new expense.
 * POST: amount, category_id, note, expense_date, type, group_id (optional)
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

$userId      = (int) $_SESSION['user_id'];
$amount      = floatval($_POST['amount'] ?? 0);
$categoryId  = (int) ($_POST['category_id'] ?? 0);
$note        = trim($_POST['note'] ?? '');
$expenseDate = trim($_POST['expense_date'] ?? '');
$type        = ($_POST['type'] ?? 'personal') === 'group' ? 'group' : 'personal';
$groupId     = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;

// --- Validation ---
$errors = [];
if ($amount <= 0)                         $errors[] = 'Amount must be greater than 0.';
if ($categoryId <= 0)                     $errors[] = 'Please select a category.';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) $errors[] = 'Invalid date format.';

// If group, ensure user is a member of that group
if ($type === 'group') {
    if (!$groupId) {
        $errors[] = 'Please select a group for a group expense.';
    } else {
        $chk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
        $chk->bind_param('ii', $groupId, $userId);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows === 0) $errors[] = 'You are not a member of this group.';
        $chk->close();
    }
}

if (!empty($errors)) {
    echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// --- Settlement lock check: block if expense date falls within a settled period ---
if ($type === 'group' && $groupId) {
    $lockStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
    $lockStmt->bind_param('i', $groupId);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if ($lockRow && $lockRow['last_end'] && $expenseDate <= $lockRow['last_end']) {
        echo json_encode(['ok' => false, 'error' => 'This expense date has already been settled and cannot be modified.']);
        exit;
    }
}

// --- Insert ---
$stmt = $conn->prepare(
    'INSERT INTO expenses (user_id, group_id, amount, category_id, note, expense_date, type)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iidisss', $userId, $groupId, $amount, $categoryId, $note, $expenseDate, $type);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    // --- Notification: if group expense, notify other members ---
    if ($type === 'group' && $groupId) {
        $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        $groupName = $gRow ? $gRow['name'] : 'the group';

        $username = $_SESSION['username'];
        $msg = "$username added a new expense to $groupName.";
        $notifStmt = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "group_expense_add", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $notifStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
        $notifStmt->execute();
        $notifStmt->close();
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to save expense.']);
    $stmt->close();
}
