<?php
/**
 * api/settlements/calculate.php — Calculate settlement for a group.
 * GET ?group_id=N
 * Returns members, contributions, per-person share, settlement instructions.
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
$groupId = (int) ($_GET['group_id'] ?? 0);

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

// Find the last settlement date for this group
$stmt = $conn->prepare('SELECT MAX(period_end) AS last_end FROM settlements WHERE group_id = ?');
$stmt->bind_param('i', $groupId);
$stmt->execute();
$lastRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$lastSettlementDate = $lastRow['last_end']; // null if no prior settlement

// Get all members
$stmt = $conn->prepare(
    'SELECT gm.user_id, u.username, gm.role
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = ?
     ORDER BY gm.role DESC, gm.joined_at ASC'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$members = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $members[] = [
        'user_id'  => (int) $r['user_id'],
        'username' => $r['username'],
        'role'     => $r['role']
    ];
}
$stmt->close();

$memberCount = count($members);
if ($memberCount === 0) {
    echo json_encode(['ok' => false, 'error' => 'No members in group.']);
    exit;
}

// Get group expenses since last settlement
if ($lastSettlementDate) {
    $sql = "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            WHERE e.group_id = ? AND e.type = 'group'
              AND e.expense_date > ?
              AND e.is_post_settlement = 0
            GROUP BY e.paid_by";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $sql = "SELECT e.paid_by, COALESCE(SUM(e.amount), 0) AS total
            FROM expenses e
            WHERE e.group_id = ? AND e.type = 'group'
              AND e.is_post_settlement = 0
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

// Total group spend
$totalSpend = array_sum($contributions);
$perPerson  = $memberCount > 0 ? round($totalSpend / $memberCount, 2) : 0;

// Build member details with contribution
$memberDetails = [];
foreach ($members as $m) {
    $contrib = $contributions[$m['user_id']] ?? 0;
    $balance = round($contrib - $perPerson, 2); // positive = creditor, negative = debtor
    $memberDetails[] = [
        'user_id'      => $m['user_id'],
        'username'     => $m['username'],
        'role'         => $m['role'],
        'contribution' => $contrib,
        'balance'      => $balance
    ];
}

// Calculate settlements using shared helper
$balances = [];
$usernames = [];
foreach ($memberDetails as $m) {
    $balances[] = ['user_id' => $m['user_id'], 'amount' => $m['balance']];
    $usernames[$m['user_id']] = $m['username'];
}
$rawSettlements = calculateSettlements($balances);
$settlements = [];
foreach ($rawSettlements as $s) {
    $settlements[] = [
        'from_id'       => $s['payer_id'],
        'from_username' => $usernames[$s['payer_id']] ?? '?',
        'to_id'         => $s['payee_id'],
        'to_username'   => $usernames[$s['payee_id']] ?? '?',
        'amount'        => $s['amount']
    ];
}

// Period dates from actual expense dates (avoids server timezone mismatches)
if ($lastSettlementDate) {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date FROM expenses WHERE group_id = ? AND type = 'group' AND expense_date > ? AND is_post_settlement = 0"
    );
    $stmt->bind_param('is', $groupId, $lastSettlementDate);
} else {
    $stmt = $conn->prepare(
        "SELECT MIN(expense_date) AS first_date, MAX(expense_date) AS last_date FROM expenses WHERE group_id = ? AND type = 'group' AND is_post_settlement = 0"
    );
    $stmt->bind_param('i', $groupId);
}
$stmt->execute();
$dateRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$periodStart = $dateRow['first_date']; // null if no expenses
$periodEnd   = $dateRow['last_date'] ? max($dateRow['last_date'], date('Y-m-d')) : date('Y-m-d');

// ---- Settlement confirmation status per member ----
$confirmations = [];
$stmt = $conn->prepare(
    'SELECT sc.user_id, sc.period_end, sc.confirmed_at
     FROM settlement_confirmations sc
     JOIN group_members gm ON gm.group_id = sc.group_id AND gm.user_id = sc.user_id
     WHERE sc.group_id = ?'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$confRes = $stmt->get_result();
while ($cr = $confRes->fetch_assoc()) {
    $confirmations[(int) $cr['user_id']] = [
        'period_end'   => $cr['period_end'],
        'confirmed_at' => $cr['confirmed_at']
    ];
}
$stmt->close();

// Count valid confirmations (period_end matches current period)
$confirmedUserIds = [];
foreach ($confirmations as $uid => $c) {
    if ($c['period_end'] === $periodEnd) {
        $confirmedUserIds[] = $uid;
    }
}

$userConfirmed = in_array($userId, $confirmedUserIds);

// Count unsettled post-settlement expenses for this group
$psStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM expenses
     WHERE group_id = ? AND type = 'group' AND is_post_settlement = 1"
);
$psStmt->bind_param('i', $groupId);
$psStmt->execute();
$postSettlementCount = (int) $psStmt->get_result()->fetch_assoc()['cnt'];
$psStmt->close();

echo json_encode([
    'ok'              => true,
    'group_id'        => $groupId,
    'member_count'    => $memberCount,
    'total_spend'     => $totalSpend,
    'per_person'      => $perPerson,
    'members'         => $memberDetails,
    'settlements'     => $settlements,
    'period_start'    => $periodStart,
    'period_end'      => $periodEnd,
    'last_settlement' => $lastSettlementDate,
    'confirmed_users' => $confirmedUserIds,
    'confirmed_count' => count($confirmedUserIds),
    'user_confirmed'  => $userConfirmed,
    'post_settlement_count' => $postSettlementCount
]);
