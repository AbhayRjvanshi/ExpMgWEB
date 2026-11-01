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
require_once __DIR__ . '/../helpers/response.php';
try {
requirePostMethod();
// Session is initialised (hardened cookie flags) by api/middleware/auth.php.
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/logger.php';

requireAuth();

require_once __DIR__ . '/../../config/idempotency.php';
require_once __DIR__ . '/../helpers/validator.php';
$idemCtx = idempotencyGuard((int) $_SESSION['user_id'], 'settlements/post_confirm');

$userId  = (int) $_SESSION['user_id'];
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    apiResponse((['ok' => false, 'error' => 'Invalid group.']));
    exit;
}

// Verify membership (the JOIN also captures the group's name for the
// notifications below — no extra SELECT after the write)
$stmt = $conn->prepare(
    'SELECT gm.role, g.name AS group_name
     FROM group_members gm
     JOIN `groups` g ON g.id = gm.group_id
     WHERE gm.group_id = ? AND gm.user_id = ?'
);
$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    apiResponse((['ok' => false, 'error' => 'You are not a member of this group.']));
    exit;
}
$groupName = $membership['group_name'] ?? 'the group';

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
    apiResponse((['ok' => false, 'error' => 'No members in group.']));
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
    apiResponse((['ok' => false, 'error' => 'No post-settlement expenses to confirm.']));
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

        // 3. Map each late expense date to its settlement period_end — O(D + P)
        // pointer-walk over both sorted arrays (periodEnds is ASC from the
        // query; psDates sorted here) instead of the O(D × P) nested loop.
        sort($psDates);
        $affectedEnds = [];
        $peIdx = 0;
        $peCount = count($periodEnds);
        foreach ($psDates as $d) {
            while ($peIdx < $peCount && $periodEnds[$peIdx] < $d) {
                $peIdx++;
            }
            if ($peIdx < $peCount) {
                $affectedEnds[$periodEnds[$peIdx]] = true;
            }
        }

        // 4. For each affected period, recalculate and update.
        // All six statements below are prepared ONCE and re-bound per period
        // (previously re-prepared inside the loop for every affected period).
        $stmtContribPrev = $conn->prepare(
            "SELECT paid_by, SUM(amount) AS total FROM expenses
             WHERE group_id = ? AND type = 'group'
               AND expense_date > ? AND expense_date <= ?
             GROUP BY paid_by"
        );
        $stmtContribFirst = $conn->prepare(
            "SELECT paid_by, SUM(amount) AS total FROM expenses
             WHERE group_id = ? AND type = 'group'
               AND expense_date <= ?
             GROUP BY paid_by"
        );
        $stmtMinPrev = $conn->prepare(
            "SELECT MIN(expense_date) AS first_date FROM expenses
             WHERE group_id = ? AND type = 'group'
               AND expense_date > ? AND expense_date <= ?"
        );
        $stmtMinFirst = $conn->prepare(
            "SELECT MIN(expense_date) AS first_date FROM expenses
             WHERE group_id = ? AND type = 'group' AND expense_date <= ?"
        );
        $stmtDelPeriod = $conn->prepare('DELETE FROM settlements WHERE group_id = ? AND period_end = ?');
        $stmtInsSettl = $conn->prepare(
            'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach (array_keys($affectedEnds) as $periodEnd) {
            // Find previous period_end
            $prevEnd = null;
            foreach ($periodEnds as $pe) {
                if ($pe < $periodEnd) $prevEnd = $pe;
            }

            // Get ALL expenses in this period (original + late) — hoisted
            // statements, re-bound per period.
            if ($prevEnd) {
                $eStmt = $stmtContribPrev;
                $eStmt->bind_param('iss', $groupId, $prevEnd, $periodEnd);
            } else {
                $eStmt = $stmtContribFirst;
                $eStmt->bind_param('is', $groupId, $periodEnd);
            }
            $eStmt->execute();
            $contributions = [];
            $eRes = $eStmt->get_result();
            while ($r = $eRes->fetch_assoc()) {
                $contributions[(int)$r['paid_by']] = (float)$r['total'];
            }

            $totalSpend = array_sum($contributions);
            $perPerson  = $memberCount > 0 ? round($totalSpend / $memberCount, 2) : 0;

            // Recalculate period_start — hoisted statements, re-bound per period.
            if ($prevEnd) {
                $dStmt = $stmtMinPrev;
                $dStmt->bind_param('iss', $groupId, $prevEnd, $periodEnd);
            } else {
                $dStmt = $stmtMinFirst;
                $dStmt->bind_param('is', $groupId, $periodEnd);
            }
            $dStmt->execute();
            $dRow = $dStmt->get_result()->fetch_assoc();
            $periodStart = $dRow['first_date'] ?? $periodEnd;

            // Calculate settlements using shared helper
            $balances = [];
            foreach ($memberIds as $mid) {
                $contrib = $contributions[$mid] ?? 0;
                $balances[] = ['user_id' => $mid, 'amount' => round($contrib - $perPerson, 2)];
            }
            $newSettlements = calculateSettlements($balances);

            // Delete old settlement records for this period — hoisted
            $stmtDelPeriod->bind_param('is', $groupId, $periodEnd);
            $stmtDelPeriod->execute();

            // Insert updated settlement records — hoisted
            foreach ($newSettlements as $s) {
                $stmtInsSettl->bind_param(
                    'iiiidss',
                    $groupId, $userId, $s['payer_id'], $s['payee_id'], $s['amount'], $periodStart, $periodEnd
                );
                $stmtInsSettl->execute();
            }
            if (empty($newSettlements)) {
                $zero = 0.0;
                $stmtInsSettl->bind_param(
                    'iiiidss',
                    $groupId, $userId, $userId, $userId, $zero, $periodStart, $periodEnd
                );
                $stmtInsSettl->execute();
            }
        }

        // Close the hoisted statements after the loop
        $stmtContribPrev->close();
        $stmtContribFirst->close();
        $stmtMinPrev->close();
        $stmtMinFirst->close();
        $stmtDelPeriod->close();
        $stmtInsSettl->close();

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

        // Notify group members ($groupName captured during the membership check)
        $msg = "All members confirmed — late expenses settlement completed in $groupName. Past settlement records updated.";
        $nStmt = $conn->prepare(
            'INSERT INTO notifications (user_id, message, type, reference_id)
             SELECT user_id, ?, "settlement", ?
             FROM group_members WHERE group_id = ? AND user_id != ?'
        );
        $nStmt->bind_param('siii', $msg, $groupId, $groupId, $userId);
        $nStmt->execute();
        $nStmt->close();

        idempotencyRespond($idemCtx, [
            'ok'          => true,
            'all_settled' => true,
            'message'     => 'All members have confirmed. Late expenses settlement is complete! Past records updated.'
        ], 200);
    } catch (Exception $e) {
        $conn->rollback();
        logMessage('ERROR', 'Post-settlement finalization failed', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        apiResponse((['ok' => false, 'error' => 'Failed to finalize settlement.']));
    }
} else {
    // Notify other members ($groupName captured during the membership check)
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

    idempotencyRespond($idemCtx, [
        'ok'          => true,
        'all_settled' => false,
        'remaining'   => $remaining,
        'message'     => 'Your confirmation has been recorded.'
    ], 200);
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