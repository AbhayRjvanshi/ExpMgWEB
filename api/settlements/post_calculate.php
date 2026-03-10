<?php
/**
 * api/settlements/post_calculate.php — Calculate supplementary settlement
 * for late (post-settlement) expenses in a group.
 * GET ?group_id=N
 * Returns members, contributions, settlements for post-settlement expenses only.
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

// Get post-settlement expenses
$sql = "SELECT e.id, e.user_id, e.paid_by, e.amount, e.expense_date, e.note,
               c.name AS category_name, u.username AS added_by, pb.username AS payer_username
        FROM expenses e
        JOIN categories c ON c.id = e.category_id
        JOIN users u ON u.id = e.user_id
        LEFT JOIN users pb ON pb.id = e.paid_by
        WHERE e.group_id = ? AND e.type = 'group' AND e.is_post_settlement = 1
        ORDER BY e.expense_date ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$expenses = [];
$contribRes = $stmt->get_result();
while ($r = $contribRes->fetch_assoc()) {
    $expenses[] = $r;
}
$stmt->close();

if (empty($expenses)) {
    echo json_encode([
        'ok'           => true,
        'has_expenses' => false,
        'total_spend'  => 0,
        'expenses'     => [],
        'members'      => [],
        'settlements'  => []
    ]);
    exit;
}

// Calculate contributions per member using paid_by
$contributions = [];
foreach ($expenses as $e) {
    $uid = $e['paid_by'] ? (int) $e['paid_by'] : (int) $e['user_id'];
    $contributions[$uid] = ($contributions[$uid] ?? 0) + (float) $e['amount'];
}

$totalSpend = array_sum($contributions);
$perPerson  = $memberCount > 0 ? round($totalSpend / $memberCount, 2) : 0;

// Build member details
$memberDetails = [];
foreach ($members as $m) {
    $contrib = $contributions[$m['user_id']] ?? 0;
    $balance = round($contrib - $perPerson, 2);
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

// Confirmation status
$stmt = $conn->prepare(
    'SELECT psc.user_id
     FROM post_settlement_confirmations psc
     JOIN group_members gm ON gm.group_id = psc.group_id AND gm.user_id = psc.user_id
     WHERE psc.group_id = ?'
);
$stmt->bind_param('i', $groupId);
$stmt->execute();
$confirmedUserIds = [];
$confRes = $stmt->get_result();
while ($cr = $confRes->fetch_assoc()) {
    $confirmedUserIds[] = (int) $cr['user_id'];
}
$stmt->close();

$userConfirmed = in_array($userId, $confirmedUserIds);

echo json_encode([
    'ok'              => true,
    'has_expenses'    => true,
    'group_id'        => $groupId,
    'member_count'    => $memberCount,
    'total_spend'     => $totalSpend,
    'per_person'      => $perPerson,
    'members'         => $memberDetails,
    'settlements'     => $settlements,
    'expenses'        => $expenses,
    'confirmed_users' => $confirmedUserIds,
    'confirmed_count' => count($confirmedUserIds),
    'user_confirmed'  => $userConfirmed
]);
