<?php
/**
 * settlement_helpers.php — Shared greedy debt-minimization algorithm.
 *
 * calculateSettlements(array $memberBalances): array
 *   Input:  [  ['user_id' => int, 'amount' => float (positive = overpaid, negative = underpaid)], ... ]
 *   Output: [  ['payer_id' => int, 'payee_id' => int, 'amount' => float], ... ]
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
