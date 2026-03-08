<?php
/**
 * api/settlements/settle_all.php — Settle all outstanding debts for a group.
 * POST: group_id
 * Only the group admin can perform this action.
 * Calculates current settlements and records them all at once.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group.']);
    exit;
}

// Verify user is admin of this group
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    echo json_encode(['ok' => false, 'error' => 'You are not a member of this group.']);
    exit;
}
if ($membership['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Only the group admin can settle expenses.']);
    exit;
}

// Find the last settlement date for this group
$stmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$lastRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$lastSettlementDate = $lastRow['last_end'];

// Get all members
$stmt = $conn->prepare(
    'SELECT gm.user_id FROM group_members gm WHERE gm.group_id = ?'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$memberIds = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $memberIds[] = (int) $r['user_id'];
}
$stmt->close();

$memberCount = count($memberIds);
if ($memberCount === 0) {
    echo json_encode(['ok' => false, 'error' => 'No members in group.']);
    exit;
}

// Get group expenses since last settlement
if ($lastSettlementDate) {
    $sql = "SELECT e.user_id, COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            WHERE e.group_id = ? AND e.type = 'group'
              AND e.expense_date > ?
            GROUP BY e.user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $sql = "SELECT e.user_id, COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            WHERE e.group_id = ? AND e.type = 'group'
            GROUP BY e.user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $groupId);
}
$stmt->execute();
$contribRes = $stmt->get_result();
$contributions = [];
while ($r = $contribRes->fetch_assoc()) {
    $contributions[(int) $r['user_id']] = (float) $r['total'];
}
$stmt->close();

$totalSpend = array_sum($contributions);
if ($totalSpend <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No unsettled expenses to settle.']);
    exit;
}

$perPerson = round($totalSpend / $memberCount, 2);

// Build balances
$creditors = [];
$debtors   = [];
foreach ($memberIds as $mid) {
    $contrib = $contributions[$mid] ?? 0;
    $balance = round($contrib - $perPerson, 2);
    if ($balance > 0.005) {
        $creditors[] = ['user_id' => $mid, 'amount' => $balance];
    } elseif ($balance < -0.005) {
        $debtors[] = ['user_id' => $mid, 'amount' => abs($balance)];
    }
}

// Greedy settlement algorithm
$settlements = [];
$ci = 0;
$di = 0;
while ($ci < count($creditors) && $di < count($debtors)) {
    $pay = min($creditors[$ci]['amount'], $debtors[$di]['amount']);
    if ($pay > 0.005) {
        $settlements[] = [
            'payer_id' => $debtors[$di]['user_id'],
            'payee_id' => $creditors[$ci]['user_id'],
            'amount'   => round($pay, 2)
        ];
    }
    $creditors[$ci]['amount'] -= $pay;
    $debtors[$di]['amount']   -= $pay;
    if ($creditors[$ci]['amount'] < 0.005) $ci++;
    if ($debtors[$di]['amount'] < 0.005)   $di++;
}

// Determine period using actual expense dates (avoids server timezone mismatches)
if ($lastSettlementDate) {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date FROM expenses WHERE group_id = ? AND type = 'group' AND expense_date > ?"
    );
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date FROM expenses WHERE group_id = ? AND type = 'group'"
    );
    $stmt->bind_param('i', $groupId);
}
$stmt->execute();
$dateRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$periodStart = $dateRow['first_date'] ?? date('Y-m-d');
$periodEnd   = max($dateRow['last_date'] ?? date('Y-m-d'), date('Y-m-d'));

// Insert all settlements in a transaction
$conn->begin_transaction();
try {
    $insertStmt = $conn->prepare(
        'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($settlements as $s) {
        $insertStmt->bind_param(
            'iiiidss',
            $groupId, $userId, $s['payer_id'], $s['payee_id'], $s['amount'], $periodStart, $periodEnd
        );
        $insertStmt->execute();
    }
    // If everyone contributed equally (no settlements needed), insert a marker record
    if (empty($settlements)) {
        $insertStmt->bind_param(
            'iiiidss',
            $groupId, $userId, $userId, $userId, $zero = 0.0, $periodStart, $periodEnd
        );
        $insertStmt->execute();
    }
    $insertStmt->close();
    $conn->commit();

    // Notify group members about settlement
    $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
    $gStmt->bind_param('i', $groupId);
    $gStmt->execute();
    $gRow = $gStmt->get_result()->fetch_assoc();
    $gStmt->close();
    $groupName = $gRow ? $gRow['name'] : 'the group';

    $username = $_SESSION['username'];
    $msg = "$username settled all expenses in $groupName.";
    $nStmt = $conn->prepare(
        'INSERT INTO notifications (user_id, message, type, reference_id)
         SELECT user_id, ?, "settlement", ?
         FROM group_members WHERE group_id = ? AND user_id != ?'
    );
    $nStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
    $nStmt->execute();
    $nStmt->close();

    echo json_encode(['ok' => true, 'message' => 'All expenses have been settled.', 'count' => count($settlements)]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => 'Failed to record settlements.']);
}
