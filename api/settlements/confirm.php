<?php
/**
 * api/settlements/confirm.php — Individual settlement confirmation.
 * POST: group_id
 *
 * Marks the current user as having confirmed the current settlement period.
 * When ALL group members have confirmed, the settlement is finalized
 * (records inserted into the settlements table) and confirmations are cleared.
 */
require_once __DIR__ . '/settlement_helpers.php';
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/logger.php';

requireAuth();

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group.']);
    exit;
}

// Verify membership
$stmt = $conn->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    echo json_encode(['ok' => false, 'error' => 'You are not a member of this group.']);
    exit;
}

// ---- Compute current period (same logic as calculate.php) ----
$stmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$lastRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$lastSettlementDate = $lastRow['last_end'];

// Get all member IDs
$stmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
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

// Get total unsettled spending
if ($lastSettlementDate) {
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses
            WHERE group_id = ? AND type = 'group' AND expense_date > ? AND is_post_settlement = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses
            WHERE group_id = ? AND type = 'group' AND is_post_settlement = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $groupId);
}
$stmt->execute();
$totalSpend = (float) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

if ($totalSpend <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No unsettled expenses to confirm.']);
    exit;
}

// Compute period dates from actual expenses
if ($lastSettlementDate) {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date
         FROM expenses WHERE group_id = ? AND type = 'group' AND expense_date > ? AND is_post_settlement = 0"
    );
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date
         FROM expenses WHERE group_id = ? AND type = 'group' AND is_post_settlement = 0"
    );
    $stmt->bind_param('i', $groupId);
}
$stmt->execute();
$dateRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$periodStart = $dateRow['first_date'] ?? date('Y-m-d');
$periodEnd   = max($dateRow['last_date'] ?? date('Y-m-d'), date('Y-m-d'));

// ---- Record this user's confirmation (inside transaction to prevent race) ----
$conn->begin_transaction();

$stmt = $conn->prepare(
    'INSERT INTO settlement_confirmations (group_id, user_id, period_start, period_end)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE period_start = VALUES(period_start),
                             period_end   = VALUES(period_end),
                             confirmed_at = CURRENT_TIMESTAMP'
);
$stmt->bind_param('iiss', $groupId, $userId, $periodStart, $periodEnd);
$stmt->execute();
$stmt->close();

// ---- Check if ALL members have confirmed (use FOR UPDATE to prevent race) ----
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS cnt FROM settlement_confirmations sc
     JOIN group_members gm ON gm.group_id = sc.group_id AND gm.user_id = sc.user_id
     WHERE sc.group_id = ? AND sc.period_end = ?
     FOR UPDATE'
);
$stmt->bind_param('is', $groupId, $periodEnd);
$stmt->execute();
$confirmedCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$remaining = $memberCount - $confirmedCount;

if ($remaining <= 0) {
    // ---- ALL confirmed → finalize settlement ----
    // Recompute contributions (same as settle_all.php)
    if ($lastSettlementDate) {
        $sql = "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
                FROM expenses e
                WHERE e.group_id = ? AND e.type = 'group' AND e.expense_date > ? AND e.is_post_settlement = 0
                GROUP BY e.paid_by";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $groupId, $lastSettlementDate);
    } else {
        $sql = "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
                FROM expenses e
                WHERE e.group_id = ? AND e.type = 'group' AND e.is_post_settlement = 0
                GROUP BY e.paid_by";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $groupId);
    }
    $stmt->execute();
    $contribRes = $stmt->get_result();
    $contributions = [];
    while ($r = $contribRes->fetch_assoc()) {
        $contributions[(int) $r['paid_by']] = (float) $r['total'];
    }
    $stmt->close();

    $perPerson = round($totalSpend / $memberCount, 2);

    // Calculate settlements using shared helper
    $balances = [];
    foreach ($memberIds as $mid) {
        $contrib = $contributions[$mid] ?? 0;
        $balances[] = ['user_id' => $mid, 'amount' => round($contrib - $perPerson, 2)];
    }
    $settlements = calculateSettlements($balances);

    // Transaction already active from the confirmation insert above — finalize within it
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
        // Marker record if everyone contributed equally
        if (empty($settlements)) {
            $zero = 0.0;
            $insertStmt->bind_param(
                'iiiidss',
                $groupId, $userId, $userId, $userId, $zero, $periodStart, $periodEnd
            );
            $insertStmt->execute();
        }
        $insertStmt->close();

        // Clear all confirmations for this group
        $delStmt = $conn->prepare('DELETE FROM settlement_confirmations WHERE group_id = ?');
        $delStmt->bind_param('i', $groupId);
        $delStmt->execute();
        $delStmt->close();

        $conn->commit();

        // Notify group members
        $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        $groupName = $gRow ? $gRow['name'] : 'the group';

        $msg = "All members confirmed — settlement completed in $groupName.";
        $nStmt = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "settlement", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $nStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
        $nStmt->execute();
        $nStmt->close();

        echo json_encode([
            'ok'          => true,
            'all_settled' => true,
            'message'     => 'All members have confirmed. Settlement is complete!'
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        logMessage('ERROR', 'Settlement finalization failed', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        echo json_encode(['ok' => false, 'error' => 'Failed to finalize settlement.']);
    }
} else {
    // Commit the transaction that recorded this user's individual confirmation
    $conn->commit();

    // Notify other members that this user confirmed
    $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
    $gStmt->bind_param('i', $groupId);
    $gStmt->execute();
    $gRow = $gStmt->get_result()->fetch_assoc();
    $gStmt->close();
    $groupName = $gRow ? $gRow['name'] : 'the group';

    $username = $_SESSION['username'];
    $msg = "$username confirmed their settlement in $groupName. $remaining member(s) remaining.";
    $nStmt = $conn->prepare(
        'INSERT INTO notifications (user_id, message, type, reference_id)
         SELECT user_id, ?, "settlement", ?
         FROM group_members WHERE group_id = ? AND user_id != ?'
    );
    $nStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
    $nStmt->execute();
    $nStmt->close();

    echo json_encode([
        'ok'          => true,
        'all_settled' => false,
        'remaining'   => $remaining,
        'message'     => 'Your settlement has been confirmed.'
    ]);
}
