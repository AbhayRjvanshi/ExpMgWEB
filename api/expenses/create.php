<?php
/**
 * api/expenses/create.php — Add a new expense.
 * POST: amount, category_id, note, expense_date, type, group_id (optional)
 * Returns JSON.
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
$idemCtx = idempotencyGuard((int) $_SESSION['user_id'], 'expenses/create');

$userId      = (int) $_SESSION['user_id'];
$rawAmount   = $_POST['amount'] ?? '';
// H3: round to 2dp first so sub-cent inputs (0.001) fail the > 0 check here
// instead of crashing on chk_expense_amount inside strict-mode MySQL.
$amount      = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
$categoryId  = (int) ($_POST['category_id'] ?? 0);
$note        = trim($_POST['note'] ?? '');
$expenseDate = trim($_POST['expense_date'] ?? '');
// M6: exact-match the discriminator -- anything that is not exactly 'group'
// used to silently become a PERSONAL expense (money vanishing from the group
// ledger with ok:true).
$typeRaw     = $_POST['type'] ?? 'personal';
$type        = $typeRaw === 'group' ? 'group' : 'personal';
$groupId     = !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;
$paidBy      = !empty($_POST['paid_by']) ? (int) $_POST['paid_by'] : null;

// --- Validation ---
$errors = [];
if (!is_numeric($rawAmount))              $errors[] = 'Amount must be a numeric value.';
elseif ($amount <= 0)                     $errors[] = 'Amount must be greater than zero.';
elseif ($amount > 99999999.00)            $errors[] = 'Amount must be at most 99999999.00.';
if (!empty($_POST['type']) && !in_array($typeRaw, ['personal', 'group'], true)) {
    $errors[] = 'Type must be personal or group.';
}
if ($categoryId <= 0)                     $errors[] = 'Please select a category.';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
    $errors[] = 'Invalid date format.';
} else {
    // Calendar-valid date: the regex alone accepts impossible dates such as
    // 2026-99-55 or 2026-02-30, which MySQL then silently coerces.
    $dateParts = explode('-', $expenseDate);
    if (!checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
        $errors[] = 'Invalid calendar date.';
    }
}
// Note length guard (expenses.note is VARCHAR(255))
if (mb_strlen($note) > 255)               $errors[] = 'Note is too long (max 255 characters).';

$groupName = 'the group'; // captured during the membership check below
// If group, ensure user is a member of that group (the JOIN also captures the
// group's name so the notification below needs no second SELECT after INSERT)
if ($type === 'group') {
    if (!$groupId) {
        $errors[] = 'Please select a group for a group expense.';
    } else {
        $chk = $conn->prepare(
            'SELECT gm.id, g.name AS group_name
             FROM group_members gm
             JOIN `groups` g ON g.id = gm.group_id
             WHERE gm.group_id = ? AND gm.user_id = ?'
        );
        $chk->bind_param('ii', $groupId, $userId);
        $chk->execute();
        $chkRow = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$chkRow) {
            $errors[] = 'You are not a member of this group.';
        } else {
            $groupName = $chkRow['group_name'];
        }
    }
    // Validate paid_by for group expenses
    if (!$paidBy) {
        $errors[] = 'Please select who paid for this expense.';
    } elseif ($groupId) {
        $pbChk = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
        $pbChk->bind_param('ii', $groupId, $paidBy);
        $pbChk->execute();
        $pbChk->store_result();
        if ($pbChk->num_rows === 0) $errors[] = 'The payer must be a member of this group.';
        $pbChk->close();
    }
} else {
    // Personal expenses: payer is always the current user
    $paidBy = $userId;
}

if (!empty($errors)) {
    apiResponse((['ok' => false, 'error' => implode(' ', $errors)]));
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
        apiResponse((['ok' => false, 'error' => 'This expense date has already been settled and cannot be modified.']));
        exit;
    }
}

// --- Insert ---
$stmt = $conn->prepare(
    'INSERT INTO expenses (user_id, paid_by, group_id, amount, category_id, note, expense_date, type, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iiidisssi', $userId, $paidBy, $groupId, $amount, $categoryId, $note, $expenseDate, $type, $userId);

if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();

    logMessage('INFO', 'Expense created', [
        'expense_id' => (int) $newId,
        'user_id'    => $userId,
        'amount'     => $amount,
        'type'       => $type,
        'group_id'   => $groupId,
    ]);

    // --- Notification: if group expense, notify other members ---
    // $groupName was captured during the membership check above.
    if ($type === 'group' && $groupId) {
        $username = $_SESSION['username'];
        $msg = "$username added a new expense to $groupName.";
        $notifStmt = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "group_expense_add", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $notifStmt->bind_param('siii', $msg, $newId, $groupId, $userId);
        $notifStmt->execute();
        $notifStmt->close();
    }

    idempotencyRespond($idemCtx, ['ok' => true, 'id' => $newId], 200);
} else {
    apiResponse((['ok' => false, 'error' => 'Failed to save expense.']));
    $stmt->close();
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