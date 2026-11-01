<?php
/**
 * settlement_helpers.php — Shared greedy debt-minimization algorithm.
 *
 * calculateSettlements(array $memberBalances): array
 *   Input:  [  ['user_id' => int, 'amount' => float (positive = overpaid, negative = underpaid)], ... ]
 *   Output: [  ['payer_id' => int, 'payee_id' => int, 'amount' => float], ... ]
 *
 * recordSettlement(...): array  — the single gated write path for a
 *   caller-supplied settlement (used by settle.php). See the docblock below.
 */

function calculateSettlements(array $memberBalances): array {
    $creditors = [];
    $debtors   = [];
    foreach ($memberBalances as $m) {
        $bal = round((float)$m['amount'], 2);
        if ($bal > 0.005) {
            $creditors[] = ['user_id' => (int)$m['user_id'], 'amount' => $bal];
        } elseif ($bal < -0.005) {
            $debtors[] = ['user_id' => (int)$m['user_id'], 'amount' => abs($bal)];
        }
    }

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

    return $settlements;
}

/**
 * C1/C2/C3 (PENTEST/highly sever/): the single gated write path for a
 * caller-supplied settlement (used by settle.php). Every documented
 * invariant is enforced HERE, inside one transaction, instead of
 * per-endpoint:
 *
 *   - C3: payer and payee are DISTINCT members of the group;
 *   - C1: every active group member has a confirmation row for this period
 *         (SELECT ... FOR UPDATE — the same primitive confirm.php uses);
 *   - C1: the requested (payer, payee, amount) matches an unsettled transfer
 *         computed from the group's actual expenses via calculateSettlements()
 *         (same derivation as confirm.php: expenses after the last settlement,
 *         equal per-person split, greedy minimization);
 *   - exact-duplicate guard (moved from the previous inline check).
 *
 * The caller (settle.php) performs the actor admin/membership check, the date
 * validation (validateDate + ordering + period_end <= today) and the amount
 * ceiling before invoking this function.
 *
 * @return array ['ok' => true, 'id' => int] on success,
 *               ['ok' => false, 'error' => string] on any gate failure.
 */
function recordSettlement(mysqli $conn, int $groupId, int $actorId, int $payerId, int $payeeId,
                          float $amount, string $periodStart, string $periodEnd): array {
    $conn->begin_transaction();
    try {
        // ---- C3: distinct member parties ------------------------------------
        if ($payerId === $payeeId) {
            throw new Exception('Payer and payee must be different members of this group.');
        }
        $chk = $conn->prepare('SELECT COUNT(*) AS c FROM group_members WHERE group_id = ? AND user_id IN (?, ?)');
        $chk->bind_param('iii', $groupId, $payerId, $payeeId);
        $chk->execute();
        $memberParties = (int) $chk->get_result()->fetch_assoc()['c'];
        $chk->close();
        if ($memberParties !== 2) {
            throw new Exception('Payer and payee must be members of this group.');
        }

        // ---- C1: every active member must have confirmed this period --------
        $mStmt = $conn->prepare('SELECT COUNT(*) AS c FROM group_members WHERE group_id = ?');
        $mStmt->bind_param('i', $groupId);
        $mStmt->execute();
        $memberCount = (int) $mStmt->get_result()->fetch_assoc()['c'];
        $mStmt->close();
        if ($memberCount === 0) {
            throw new Exception('No members in group.');
        }

        $cStmt = $conn->prepare(
            'SELECT COUNT(DISTINCT sc.user_id) AS c
               FROM settlement_confirmations sc
               JOIN group_members gm ON gm.group_id = sc.group_id AND gm.user_id = sc.user_id
              WHERE sc.group_id = ? AND sc.period_end = ?
              FOR UPDATE'
        );
        $cStmt->bind_param('is', $groupId, $periodEnd);
        $cStmt->execute();
        $confirmedCount = (int) $cStmt->get_result()->fetch_assoc()['c'];
        $cStmt->close();
        if ($confirmedCount < $memberCount) {
            throw new Exception('All group members must confirm the settlement before it can be recorded.');
        }

        // ---- C1: the transfer must match the computed unsettled transfers ---
        $lastStmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
        $lastStmt->bind_param('i', $groupId);
        $lastStmt->execute();
        $lastSettlementDate = $lastStmt->get_result()->fetch_assoc()['last_end'];
        $lastStmt->close();

        if ($lastSettlementDate) {
            $sumStmt = $conn->prepare(
                "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
                   FROM expenses e
                  WHERE e.group_id = ? AND e.type = 'group' AND e.expense_date > ? AND e.is_post_settlement = 0
                  GROUP BY e.paid_by"
            );
            $sumStmt->bind_param('is', $groupId, $lastSettlementDate);
        } else {
            $sumStmt = $conn->prepare(
                "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
                   FROM expenses e
                  WHERE e.group_id = ? AND e.type = 'group' AND e.is_post_settlement = 0
                  GROUP BY e.paid_by"
            );
            $sumStmt->bind_param('i', $groupId);
        }
        $sumStmt->execute();
        $contributions = [];
        $sumRes = $sumStmt->get_result();
        while ($row = $sumRes->fetch_assoc()) {
            $contributions[(int) $row['paid_by']] = (float) $row['total'];
        }
        $sumStmt->close();

        $idStmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
        $idStmt->bind_param('i', $groupId);
        $idStmt->execute();
        $memberIds = [];
        $idRes = $idStmt->get_result();
        while ($row = $idRes->fetch_assoc()) {
            $memberIds[] = (int) $row['user_id'];
        }
        $idStmt->close();

        $totalSpend = array_sum($contributions);
        $perPerson  = round($totalSpend / $memberCount, 2);
        $balances   = [];
        foreach ($memberIds as $mid) {
            $balances[] = ['user_id' => $mid, 'amount' => round(($contributions[$mid] ?? 0) - $perPerson, 2)];
        }
        $computed = calculateSettlements($balances);

        $matches = false;
        foreach ($computed as $t) {
            if ((int) $t['payer_id'] === $payerId
                && (int) $t['payee_id'] === $payeeId
                && abs((float) $t['amount'] - $amount) < 0.005) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            throw new Exception('Settlement does not match the computed unsettled transfers.');
        }

        // ---- duplicate guard (exact match, as before) ------------------------
        $dup = $conn->prepare(
            'SELECT id FROM settlements
              WHERE group_id = ? AND payer_id = ? AND payee_id = ? AND amount = ?
                AND period_start = ? AND period_end = ?
              LIMIT 1'
        );
        $dup->bind_param('iiidss', $groupId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);
        $dup->execute();
        $dupRow = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($dupRow) {
            throw new Exception('This settlement has already been recorded.');
        }

        // ---- INSERT ----------------------------------------------------------
        $stmt = $conn->prepare(
            'INSERT INTO settlements (group_id, settled_by, payer_id, payee_id, amount, period_start, period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiidss', $groupId, $actorId, $payerId, $payeeId, $amount, $periodStart, $periodEnd);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to record settlement.');
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        $conn->commit();
        return ['ok' => true, 'id' => $newId];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) { /* not in a transaction */ }
        logMessage('ERROR', 'recordSettlement gate failure', [
            'group_id' => $groupId,
            'actor_id' => $actorId,
            'reason'   => $e->getMessage(),
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
