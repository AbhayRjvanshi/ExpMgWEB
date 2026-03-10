<?php
/**
 * api/settlements/post_confirm.php — Confirm supplementary (post-settlement) settlement.
 * POST: group_id
 *
 * Marks the current user as having confirmed the post-settlement adjustment.
 * When ALL group members have confirmed, the post-settlement expenses are
 * cleared (is_post_settlement set to 0 — they become normal settled expenses)
 * and confirmations are cleared.
 */
require_once __DIR__ . '/settlement_helpers.php';
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

// Get member IDs and count
$stmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$memberIds = [];
$mRes = $stmt->get_result();
while ($mr = $mRes->fetch_assoc()) {
    $memberIds[] = (int) $mr['user_id'];
}
$stmt->close();
$memberCount = count($memberIds);

if ($memberCount === 0) {
    echo json_encode(['ok' => false, 'error' => 'No members in group.']);
    exit;
}

// Check there are post-settlement expenses
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM expenses
     WHERE group_id = ? AND type = 'group' AND is_post_settlement = 1"
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$psCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($psCount === 0) {
    echo json_encode(['ok' => false, 'error' => 'No post-settlement expenses to confirm.']);
    exit;
}

// Record this user's confirmation
$stmt = $conn->prepare(
    'INSERT INTO post_settlement_confirmations (group_id, user_id)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE confirmed_at = CURRENT_TIMESTAMP'
);
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$stmt->close();

// Check if ALL members have confirmed
$stmt = $conn->prepare(
    'SELECT COUNT(*) AS cnt FROM post_settlement_confirmations psc
     JOIN group_members gm ON gm.group_id = psc.group_id AND gm.user_id = psc.user_id
     WHERE psc.group_id = ?'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$confirmedCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$remaining = $memberCount - $confirmedCount;

if ($remaining <= 0) {
    // ALL confirmed → recalculate affected settlement periods and finalize
    $conn->begin_transaction();
    try {
        // 1. Get all post-settlement expense dates
        $psStmt = $conn->prepare(
            "SELECT DISTINCT expense_date FROM expenses
             WHERE group_id = ? AND type = 'group' AND is_post_settlement = 1"
        );
        $psStmt->bind_param('i', $groupId);
        $psStmt->execute();
        $psDates = [];
        $psRes = $psStmt->get_result();
        while ($r = $psRes->fetch_assoc()) {
            $psDates[] = $r['expense_date'];
        }
        $psStmt->close();

        // 2. Get all distinct settlement period_ends for this group (ordered)
        $peStmt = $conn->prepare(
            'SELECT DISTINCT period_end FROM settlements WHERE group_id = ? ORDER BY period_end ASC'
        );
        $peStmt->bind_param('i', $groupId);
        $peStmt->execute();
        $periodEnds = [];
        $peRes = $peStmt->get_result();
        while ($r = $peRes->fetch_assoc()) {
            $periodEnds[] = $r['period_end'];
        }
        $peStmt->close();

        // 3. Map each late expense date to its settlement period_end
        $affectedEnds = [];
        foreach ($psDates as $d) {
            foreach ($periodEnds as $pe) {
                if ($d <= $pe) {
                    $affectedEnds[$pe] = true;
                    break;
                }
            }
        }

        // 4. For each affected period, recalculate and update
        foreach (array_keys($affectedEnds) as $periodEnd) {
            // Find previous period_end
            $prevEnd = null;
            foreach ($periodEnds as $pe) {
                if ($pe < $periodEnd) $prevEnd = $pe;
            }

            // Get ALL expenses in this period (original + late)
            if ($prevEnd) {
                $eStmt = $conn->prepare(
                    "SELECT paid_by, SUM(amount) AS total FROM expenses
                     WHERE group_id = ? AND type = 'group'
                       AND expense_date > ? AND expense_date <= ?
                     GROUP BY paid_by"
                );
                $eStmt->bind_param('iss', $groupId, $prevEnd, $periodEnd);
            } else {
                $eStmt = $conn->prepare(
                    "SELECT paid_by, SUM(amount) AS total FROM expenses
                     WHERE group_id = ? AND type = 'group'
                       AND expense_date <= ?
                     GROUP BY paid_by"
                );
                $eStmt->bind_param('is', $groupId, $periodEnd);
            }
            $eStmt->execute();
            $contributions = [];
            $eRes = $eStmt->get_result();
            while ($r = $eRes->fetch_assoc()) {
                $contributions[(int)$r['paid_by']] = (float)$r['total'];
            }
            $eStmt->close();

            $totalSpend = array_sum($contributions);
            $perPerson  = $memberCount > 0 ? round($totalSpend / $memberCount, 2) : 0;

            // Recalculate period_start
            if ($prevEnd) {
                $dStmt = $conn->prepare(
                    "SELECT MIN(expense_date) AS first_date FROM expenses
                     WHERE group_id = ? AND type = 'group'
                       AND expense_date > ? AND expense_date <= ?"
                );
                $dStmt->bind_param('iss', $groupId, $prevEnd, $periodEnd);
            } else {
                $dStmt = $conn->prepare(
                    "SELECT MIN(expense_date) AS first_date FROM expenses
                     WHERE group_id = ? AND type = 'group' AND expense_date <= ?"
                );
                $dStmt->bind_param('is', $groupId, $periodEnd);
            }
            $dStmt->execute();
            $dRow = $dStmt->get_result()->fetch_assoc();
            $dStmt->close();
            $periodStart = $dRow['first_date'] ?? $periodEnd;

            // Calculate settlements using shared helper
            $balances = [];
            foreach ($memberIds as $mid) {
                $contrib = $contributions[$mid] ?? 0;
                $balances[] = ['user_id' => $mid, 'amount' => round($contrib - $perPerson, 2)];
            }
            $newSettlements = calculateSettlements($balances);

            // Delete old settlement records for this period
            $delS = $conn->prepare('DELETE FROM settlements WHERE group_id = ? AND period_end = ?');
            $delS->bind_param('is', $groupId, $periodEnd);
            $delS->execute();
            $delS->close();

            // Insert updated settlement records
            $insS = $conn->prepare(
                'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($newSettlements as $s) {
                $insS->bind_param(
                    'iiiidss',
                    $groupId, $userId, $s['payer_id'], $s['payee_id'], $s['amount'], $periodStart, $periodEnd
                );
                $insS->execute();
            }
            if (empty($newSettlements)) {
                $zero = 0.0;
                $insS->bind_param('iiiidss', $groupId, $userId, $userId, $userId, $zero, $periodStart, $periodEnd);
                $insS->execute();
            }
            $insS->close();
        }

        // 5. Clear the post-settlement flag
        $upd = $conn->prepare(
            "UPDATE expenses SET is_post_settlement = 0
             WHERE group_id = ? AND type = 'group' AND is_post_settlement = 1"
        );
        $upd->bind_param('i', $groupId);
        $upd->execute();
        $upd->close();

        // 6. Clear all post-settlement confirmations
        $del = $conn->prepare('DELETE FROM post_settlement_confirmations WHERE group_id = ?');
        $del->bind_param('i', $groupId);
        $del->execute();
        $del->close();

        $conn->commit();

        // Notify group members
        $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
        $gStmt->bind_param('i', $groupId);
        $gStmt->execute();
        $gRow = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
        $groupName = $gRow ? $gRow['name'] : 'the group';

        $msg = "All members confirmed — late expenses settlement completed in $groupName. Past settlement records updated.";
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
            'message'     => 'All members have confirmed. Late expenses settlement is complete! Past records updated.'
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Failed to finalize settlement.']);
    }
} else {
    // Notify other members
    $gStmt = $conn->prepare('SELECT name FROM `groups` WHERE id = ?');
    $gStmt->bind_param('i', $groupId);
    $gStmt->execute();
    $gRow = $gStmt->get_result()->fetch_assoc();
    $gStmt->close();
    $groupName = $gRow ? $gRow['name'] : 'the group';

    $username = $_SESSION['username'];
    $msg = "$username confirmed late expenses settlement in $groupName. $remaining member(s) remaining.";
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
        'message'     => 'Your confirmation has been recorded.'
    ]);
}
