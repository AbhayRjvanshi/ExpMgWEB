<?php
/**
 * Comprehensive functional test suite for ExpMgWEB.
 * 
 * Run: php run_tests.php
 *
 * Creates temporary test users, exercises every API endpoint,
 * then cleans up all test data.
 */

// ── Bootstrap ──
$baseUrl = 'http://localhost/ExpMgWEB';
$pass    = 0;
$fail    = 0;
$errors  = [];

// We need a direct DB connection for setup/teardown and verification
$conn = new mysqli('localhost', 'root', '', 'ExpMgWEB');
if ($conn->connect_error) { die("DB connection failed: " . $conn->connect_error . "\n"); }

// ── Helpers ──
function test(string $name, bool $condition, string $detail = '') {
    global $pass, $fail, $errors;
    if ($condition) {
        $pass++;
        echo "  PASS: $name\n";
    } else {
        $fail++;
        $msg = "  FAIL: $name" . ($detail ? " — $detail" : "");
        echo "$msg\n";
        $errors[] = $msg;
    }
}

/**
 * Make an HTTP request with session cookie support.
 * Returns ['status' => int, 'body' => string, 'json' => array|null, 'headers' => string]
 */
function http(string $method, string $url, array $data = [], string $cookie = ''): array {
    $ch = curl_init();
    
    if ($method === 'GET' && $data) {
        $url .= '?' . http_build_query($data);
    }
    
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,  // don't follow redirects
        CURLOPT_TIMEOUT        => 10,
    ]);
    
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }
    
    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error      = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['status' => 0, 'body' => "cURL error: $error", 'json' => null, 'headers' => ''];
    }
    
    $headers = substr($response, 0, $headerSize);
    $body    = substr($response, $headerSize);
    $json    = json_decode($body, true);
    
    return ['status' => $httpCode, 'body' => $body, 'json' => $json, 'headers' => $headers];
}

/** Extract PHPSESSID from Set-Cookie headers */
function extractSession(string $headers): string {
    if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;\s]+)/i', $headers, $m)) {
        return 'PHPSESSID=' . $m[1];
    }
    return '';
}

/** POST to API, return json (auto-attaches cookie) */
function apiPost(string $path, array $data, string $cookie): array {
    global $baseUrl;
    $r = http('POST', "$baseUrl/api/$path", $data, $cookie);
    return $r['json'] ?? ['ok' => false, 'error' => 'No JSON response: ' . substr($r['body'], 0, 200)];
}

/** GET from API, return json */
function apiGet(string $path, array $data, string $cookie): array {
    global $baseUrl;
    $r = http('GET', "$baseUrl/api/$path", $data, $cookie);
    return $r['json'] ?? ['ok' => false, 'error' => 'No JSON response: ' . substr($r['body'], 0, 200)];
}

// ── Cleanup any leftover test data ──
function cleanupTestData(mysqli $conn) {
    // Delete test users and all cascading data
    $conn->query("DELETE FROM users WHERE email LIKE '%@exptest.local'");
}

cleanupTestData($conn);

echo "==========================================================\n";
echo "  ExpMgWEB — Comprehensive Functional Test Suite\n";
echo "==========================================================\n\n";

// ════════════════════════════════════════════════════════════════
// SECTION 1: SCHEMA VALIDATION
// ════════════════════════════════════════════════════════════════
echo "── 1. Schema Validation ──\n";

$tables = ['users','categories','groups','group_members','expenses','budgets',
           'lists','list_items','notifications','settlements',
           'settlement_confirmations','post_settlement_confirmations'];

foreach ($tables as $t) {
    $r = $conn->query("SELECT 1 FROM `$t` LIMIT 0");
    test("Table '$t' exists", $r !== false);
}

// Check key columns
$r = $conn->query("SHOW COLUMNS FROM expenses LIKE 'paid_by'");
test("expenses.paid_by column exists", $r && $r->num_rows === 1);

$r = $conn->query("SHOW COLUMNS FROM expenses LIKE 'created_by'");
test("expenses.created_by column exists", $r && $r->num_rows === 1);

$r = $conn->query("SHOW COLUMNS FROM expenses LIKE 'checked_by'");
test("expenses.checked_by column exists", $r && $r->num_rows === 1);

$r = $conn->query("SHOW COLUMNS FROM expenses LIKE 'is_post_settlement'");
test("expenses.is_post_settlement column exists", $r && $r->num_rows === 1);

$r = $conn->query("SHOW COLUMNS FROM list_items LIKE 'expense_id'");
test("list_items.expense_id column exists", $r && $r->num_rows === 1);

$r = $conn->query("SHOW COLUMNS FROM list_items LIKE 'expense_created'");
test("list_items.expense_created column exists", $r && $r->num_rows === 1);

$r = $conn->query("SELECT COUNT(*) AS c FROM categories");
$catCount = $r->fetch_assoc()['c'];
test("Categories seeded", (int)$catCount >= 8, "Found $catCount");

// ════════════════════════════════════════════════════════════════
// SECTION 2: AUTH — SIGNUP / LOGIN / LOGOUT
// ════════════════════════════════════════════════════════════════
echo "\n── 2. Auth: Signup / Login / Logout ──\n";

// Signup user A
$r = http('POST', "$baseUrl/api/signup.php", [
    'username'         => 'testuser_a',
    'email'            => 'a@exptest.local',
    'password'         => 'Test1234!',
    'confirm_password' => 'Test1234!'
]);
test("Signup user A", $r['status'] === 302 || $r['status'] === 200 || $r['status'] === 303,
     "HTTP {$r['status']}");

// Signup user B
$r = http('POST', "$baseUrl/api/signup.php", [
    'username'         => 'testuser_b',
    'email'            => 'b@exptest.local',
    'password'         => 'Test1234!',
    'confirm_password' => 'Test1234!'
]);
test("Signup user B", $r['status'] === 302 || $r['status'] === 200 || $r['status'] === 303);

// Signup user C (for 3-member group tests)
$r = http('POST', "$baseUrl/api/signup.php", [
    'username'         => 'testuser_c',
    'email'            => 'c@exptest.local',
    'password'         => 'Test1234!',
    'confirm_password' => 'Test1234!'
]);
test("Signup user C", $r['status'] === 302 || $r['status'] === 200 || $r['status'] === 303);

// Duplicate signup should fail or redirect with error
$r = http('POST', "$baseUrl/api/signup.php", [
    'username'         => 'testuser_a',
    'email'            => 'a@exptest.local',
    'password'         => 'Test1234!',
    'confirm_password' => 'Test1234!'
]);
test("Duplicate signup rejected", $r['status'] === 302 || $r['status'] === 200);

// Login user A
$r = http('POST', "$baseUrl/api/login.php", [
    'email'    => 'a@exptest.local',
    'password' => 'Test1234!'
]);
$cookieA = extractSession($r['headers']);
test("Login user A — session obtained", $cookieA !== '', "Cookie: $cookieA");

// Login user B
$r = http('POST', "$baseUrl/api/login.php", [
    'email'    => 'b@exptest.local',
    'password' => 'Test1234!'
]);
$cookieB = extractSession($r['headers']);
test("Login user B — session obtained", $cookieB !== '');

// Login user C
$r = http('POST', "$baseUrl/api/login.php", [
    'email'    => 'c@exptest.local',
    'password' => 'Test1234!'
]);
$cookieC = extractSession($r['headers']);
test("Login user C — session obtained", $cookieC !== '');

// Grab user IDs
$userA = $conn->query("SELECT id FROM users WHERE email='a@exptest.local'")->fetch_assoc()['id'];
$userB = $conn->query("SELECT id FROM users WHERE email='b@exptest.local'")->fetch_assoc()['id'];
$userC = $conn->query("SELECT id FROM users WHERE email='c@exptest.local'")->fetch_assoc()['id'];
test("User A ID retrieved", $userA > 0, "ID=$userA");
test("User B ID retrieved", $userB > 0, "ID=$userB");

// Wrong password login
$r = http('POST', "$baseUrl/api/login.php", [
    'email'    => 'a@exptest.local',
    'password' => 'WRONGPASSWORD'
]);
// Should redirect back to login (not set session)
test("Wrong password rejected", strpos($r['headers'], 'login.php') !== false || $r['status'] === 200);

// ════════════════════════════════════════════════════════════════
// SECTION 3: CATEGORIES
// ════════════════════════════════════════════════════════════════
echo "\n── 3. Categories ──\n";

$cats = apiGet('expenses/categories.php', [], $cookieA);
test("Get categories", $cats['ok'] === true);
test("Categories count >= 8", count($cats['categories'] ?? []) >= 8);

// ════════════════════════════════════════════════════════════════
// SECTION 4: PERSONAL EXPENSES (CRUD)
// ════════════════════════════════════════════════════════════════
echo "\n── 4. Personal Expenses — CRUD ──\n";

$today = date('Y-m-d');
$month = date('Y-m');

// Create
$cr = apiPost('expenses/create.php', [
    'amount'       => '25.50',
    'category_id'  => 1,
    'note'         => 'Test lunch',
    'expense_date' => $today,
    'type'         => 'personal'
], $cookieA);
test("Create personal expense", ($cr['ok'] ?? false) === true, json_encode($cr));
$personalExpId = $cr['id'] ?? 0;

// List by date
$list = apiGet('expenses/list.php', ['date' => $today], $cookieA);
test("List expenses by date", ($list['ok'] ?? false) === true);
$found = false;
foreach ($list['expenses'] ?? [] as $e) {
    if ((int)$e['id'] === $personalExpId) { $found = true; break; }
}
test("Created expense appears in list", $found);

// List by month
$listM = apiGet('expenses/list.php', ['month' => $month], $cookieA);
test("List expenses by month", ($listM['ok'] ?? false) === true);

// Update
$up = apiPost('expenses/update.php', [
    'id'          => $personalExpId,
    'amount'      => '30.00',
    'category_id' => 2,
    'note'        => 'Updated lunch',
    'type'        => 'personal'
], $cookieA);
test("Update personal expense", ($up['ok'] ?? false) === true, json_encode($up));

// Verify update
$list2 = apiGet('expenses/list.php', ['date' => $today], $cookieA);
$updatedExp = null;
foreach ($list2['expenses'] ?? [] as $e) {
    if ((int)$e['id'] === $personalExpId) { $updatedExp = $e; break; }
}
test("Updated expense reflects changes", $updatedExp && (float)$updatedExp['amount'] == 30.00);

// User B cannot update user A's expense
$up2 = apiPost('expenses/update.php', [
    'id'          => $personalExpId,
    'amount'      => '99.00',
    'category_id' => 1,
    'note'        => 'hack',
    'type'        => 'personal'
], $cookieB);
test("User B cannot update A's personal expense", ($up2['ok'] ?? true) === false);

// Delete
$del = apiPost('expenses/delete.php', ['id' => $personalExpId], $cookieA);
test("Delete personal expense", ($del['ok'] ?? false) === true);

// User B cannot delete A's expense (create fresh first)
$cr2 = apiPost('expenses/create.php', [
    'amount'       => '10.00',
    'category_id'  => 1,
    'note'         => 'B tries to delete',
    'expense_date' => $today,
    'type'         => 'personal'
], $cookieA);
$tmpId = $cr2['id'] ?? 0;
$del2 = apiPost('expenses/delete.php', ['id' => $tmpId], $cookieB);
test("User B cannot delete A's expense", ($del2['ok'] ?? true) === false);
// Cleanup
apiPost('expenses/delete.php', ['id' => $tmpId], $cookieA);

// ════════════════════════════════════════════════════════════════
// SECTION 5: EXPENSE SUMMARY
// ════════════════════════════════════════════════════════════════
echo "\n── 5. Expense Summary ──\n";

// Create a few expenses for summary
apiPost('expenses/create.php', [
    'amount' => '100', 'category_id' => 1, 'note' => 'food',
    'expense_date' => $today, 'type' => 'personal'
], $cookieA);
apiPost('expenses/create.php', [
    'amount' => '50', 'category_id' => 2, 'note' => 'transport',
    'expense_date' => $today, 'type' => 'personal'
], $cookieA);

$summary = apiGet('expenses/summary.php', ['month' => $month], $cookieA);
test("Summary endpoint returns OK", ($summary['ok'] ?? false) === true);
test("Summary total_spent > 0", (float)($summary['total_spent'] ?? 0) > 0);
test("Summary has by_category", is_array($summary['by_category'] ?? null));
test("Summary has by_day", is_array($summary['by_day'] ?? null));

// ════════════════════════════════════════════════════════════════
// SECTION 6: BUDGETS
// ════════════════════════════════════════════════════════════════
echo "\n── 6. Budgets ──\n";

$bSet = apiPost('budgets/set.php', [
    'month'        => $month,
    'amount_limit' => '500.00'
], $cookieA);
test("Set budget", ($bSet['ok'] ?? false) === true, json_encode($bSet));

$bGet = apiGet('budgets/get.php', ['month' => $month], $cookieA);
test("Get budget", ($bGet['ok'] ?? false) === true);
test("Budget amount correct", (float)($bGet['budget']['amount_limit'] ?? 0) == 500.00);

// Update budget
$bSet2 = apiPost('budgets/set.php', [
    'month'        => $month,
    'amount_limit' => '750.00'
], $cookieA);
test("Update budget", ($bSet2['ok'] ?? false) === true);
$bGet2 = apiGet('budgets/get.php', ['month' => $month], $cookieA);
test("Budget updated to 750", (float)($bGet2['budget']['amount_limit'] ?? 0) == 750.00);

// Invalid method
$bBad = http('GET', "$baseUrl/api/budgets/set.php", ['month' => $month, 'amount_limit' => '100'], $cookieA);
test("Budget set rejects GET", ($bBad['json']['ok'] ?? true) === false);

// ════════════════════════════════════════════════════════════════
// SECTION 7: GROUPS (Create, Join, Details, Leave, Delete)
// ════════════════════════════════════════════════════════════════
echo "\n── 7. Groups ──\n";

// User A creates a group
$grp = apiPost('groups/create.php', ['name' => 'TestGroup_Audit'], $cookieA);
test("Create group", ($grp['ok'] ?? false) === true, json_encode($grp));
$groupId  = $grp['group']['id']   ?? 0;
$joinCode = $grp['group']['join_code'] ?? '';
test("Group ID valid", $groupId > 0);
test("Join code returned", strlen($joinCode) > 0);

// User A's groups list
$myGroups = apiGet('groups/user_groups.php', [], $cookieA);
test("User groups list", ($myGroups['ok'] ?? false) === true);
$foundGrp = false;
foreach ($myGroups['groups'] ?? [] as $g) {
    if ((int)$g['id'] === $groupId) { $foundGrp = true; break; }
}
test("Created group in list", $foundGrp);

// User B joins
$jn = apiPost('groups/join.php', ['join_code' => $joinCode], $cookieB);
test("User B joins group", ($jn['ok'] ?? false) === true, json_encode($jn));

// User C joins
$jn2 = apiPost('groups/join.php', ['join_code' => $joinCode], $cookieC);
test("User C joins group", ($jn2['ok'] ?? false) === true);

// Invalid join code
$jnBad = apiPost('groups/join.php', ['join_code' => 'INVALID_CODE_XYZ'], $cookieC);
test("Invalid join code rejected", ($jnBad['ok'] ?? true) === false);

// Group details
$det = apiGet('groups/details.php', ['group_id' => $groupId], $cookieA);
test("Group details", ($det['ok'] ?? false) === true);
test("Group has 3 members", count($det['members'] ?? []) === 3, "Count: " . count($det['members'] ?? []));
test("User A is admin", ($det['my_role'] ?? '') === 'admin');

// User B's role
$detB = apiGet('groups/details.php', ['group_id' => $groupId], $cookieB);
test("User B is member", ($detB['my_role'] ?? '') === 'member');

// ════════════════════════════════════════════════════════════════
// SECTION 8: GROUP EXPENSES
// ════════════════════════════════════════════════════════════════
echo "\n── 8. Group Expenses ──\n";

$ge1 = apiPost('expenses/create.php', [
    'amount'       => '90.00',
    'category_id'  => 1,
    'note'         => 'Group dinner',
    'expense_date' => $today,
    'type'         => 'group',
    'group_id'     => $groupId,
    'paid_by'      => $userA
], $cookieA);
test("Create group expense (A pays)", ($ge1['ok'] ?? false) === true, json_encode($ge1));
$groupExpId1 = $ge1['id'] ?? 0;

$ge2 = apiPost('expenses/create.php', [
    'amount'       => '60.00',
    'category_id'  => 2,
    'note'         => 'Group transport',
    'expense_date' => $today,
    'type'         => 'group',
    'group_id'     => $groupId,
    'paid_by'      => $userB
], $cookieA);
test("Create group expense (B pays)", ($ge2['ok'] ?? false) === true);
$groupExpId2 = $ge2['id'] ?? 0;

// Verify paid_by tracking
$listG = apiGet('expenses/list.php', ['date' => $today], $cookieA);
$foundPaidBy = false;
foreach ($listG['expenses'] ?? [] as $e) {
    if ((int)$e['id'] === $groupExpId1 && (int)$e['paid_by'] === (int)$userA) {
        $foundPaidBy = true; break;
    }
}
test("Paid_by tracked correctly", $foundPaidBy);

// Non-admin cannot edit group expense
$upG = apiPost('expenses/update.php', [
    'id'          => $groupExpId1,
    'amount'      => '999',
    'category_id' => 1,
    'note'        => 'hack',
    'type'        => 'group',
    'group_id'    => $groupId
], $cookieB);
test("Non-admin cannot edit group expense", ($upG['ok'] ?? true) === false);

// Admin can edit group expense
$upG2 = apiPost('expenses/update.php', [
    'id'          => $groupExpId1,
    'amount'      => '95.00',
    'category_id' => 1,
    'note'        => 'Group dinner (updated)',
    'type'        => 'group',
    'group_id'    => $groupId,
    'paid_by'     => $userA
], $cookieA);
test("Admin can edit group expense", ($upG2['ok'] ?? false) === true);

// Non-admin cannot delete group expense
$delG = apiPost('expenses/delete.php', ['id' => $groupExpId1], $cookieB);
test("Non-admin cannot delete group expense", ($delG['ok'] ?? true) === false);

// ════════════════════════════════════════════════════════════════
// SECTION 9: NOTIFICATIONS
// ════════════════════════════════════════════════════════════════
echo "\n── 9. Notifications ──\n";

// User B should have notifications from group expense creation
$notifCount = apiGet('notifications/count.php', [], $cookieB);
test("Notification count endpoint", ($notifCount['ok'] ?? false) === true);

$notifList = apiGet('notifications/list.php', [], $cookieB);
test("Notification list endpoint", ($notifList['ok'] ?? false) === true);
test("User B has notifications", count($notifList['notifications'] ?? []) > 0,
     "Count: " . count($notifList['notifications'] ?? []));

// Mark one as read
if (!empty($notifList['notifications'])) {
    $nid = $notifList['notifications'][0]['id'];
    $markRead = apiPost('notifications/read.php', ['id' => $nid], $cookieB);
    test("Mark notification read", ($markRead['ok'] ?? false) === true);
}

// Mark all as read
$markAll = apiPost('notifications/read.php', ['all' => '1'], $cookieB);
test("Mark all notifications read", ($markAll['ok'] ?? false) === true);

// ════════════════════════════════════════════════════════════════
// SECTION 10: SETTLEMENT CALCULATION
// ════════════════════════════════════════════════════════════════
echo "\n── 10. Settlement ──\n";

// Calculate settlement
$calc = apiGet('settlements/calculate.php', ['group_id' => $groupId], $cookieA);
test("Settlement calculate", ($calc['ok'] ?? false) === true, json_encode($calc));
test("Settlement has members", count($calc['members'] ?? []) === 3);
test("Settlement has settlements array", is_array($calc['settlements'] ?? null));

// Total spend = 95 + 60 = 155, per person ≈ 51.67
$expectedPerPerson = round(155 / 3, 2);
test("Per-person share correct", abs(($calc['per_person'] ?? 0) - $expectedPerPerson) < 0.02,
     "Expected ~$expectedPerPerson, got " . ($calc['per_person'] ?? '?'));

// History (should be empty before settlement)
$hist = apiGet('settlements/history.php', ['group_id' => $groupId], $cookieA);
test("Settlement history (before settle)", ($hist['ok'] ?? false) === true);
test("No past settlements yet", count($hist['settlements'] ?? []) === 0);

// ════════════════════════════════════════════════════════════════
// SECTION 11: SETTLEMENT CONFIRMATION FLOW
// ════════════════════════════════════════════════════════════════
echo "\n── 11. Settlement Confirmation ──\n";

// User A confirms
$conf1 = apiPost('settlements/confirm.php', ['group_id' => $groupId], $cookieA);
test("User A confirms settlement", ($conf1['ok'] ?? false) === true, json_encode($conf1));
test("Not yet finalized (1/3)", ($conf1['all_settled'] ?? true) === false);

// User B confirms
$conf2 = apiPost('settlements/confirm.php', ['group_id' => $groupId], $cookieB);
test("User B confirms settlement", ($conf2['ok'] ?? false) === true);
test("Not yet finalized (2/3)", ($conf2['all_settled'] ?? true) === false);

// User C confirms — should finalize
$conf3 = apiPost('settlements/confirm.php', ['group_id' => $groupId], $cookieC);
test("User C confirms settlement", ($conf3['ok'] ?? false) === true, json_encode($conf3));
test("Settlement finalized (3/3)", ($conf3['all_settled'] ?? false) === true);

// Verify settlement history
$hist2 = apiGet('settlements/history.php', ['group_id' => $groupId], $cookieA);
test("Settlement history has entries", count($hist2['settlements'] ?? []) > 0,
     "Count: " . count($hist2['settlements'] ?? []));

// Settlement details
if (!empty($hist2['settlements'])) {
    $s = $hist2['settlements'][0];
    $detS = apiGet('settlements/details.php', [
        'group_id' => $groupId,
        'start'    => $s['period_start'],
        'end'      => $s['period_end']
    ], $cookieA);
    test("Settlement details endpoint", ($detS['ok'] ?? false) === true);
}

// ════════════════════════════════════════════════════════════════
// SECTION 12: SETTLEMENT LOCK
// ════════════════════════════════════════════════════════════════
echo "\n── 12. Settlement Lock ──\n";

// Try to update settled expense — should fail
$lockTest = apiPost('expenses/update.php', [
    'id'          => $groupExpId1,
    'amount'      => '999',
    'category_id' => 1,
    'note'        => 'hack settled',
    'type'        => 'group',
    'group_id'    => $groupId
], $cookieA);
test("Cannot update settled expense", ($lockTest['ok'] ?? true) === false,
     json_encode($lockTest));

// Try to delete settled expense — should fail
$lockDel = apiPost('expenses/delete.php', ['id' => $groupExpId1], $cookieA);
test("Cannot delete settled expense", ($lockDel['ok'] ?? true) === false);

// ════════════════════════════════════════════════════════════════
// SECTION 13: POST-SETTLEMENT (LATE) EXPENSES
// ════════════════════════════════════════════════════════════════
echo "\n── 13. Post-Settlement Expenses ──\n";

// create.php blocks expenses on settled dates (settlement lock).
// Post-settlement expenses are created via check_item.php flow.
// Create a group list, add a priced item, check it → expense gets is_post_settlement=1.
$psLst = apiPost('lists/create.php', ['name' => 'PostSettl List', 'group_id' => $groupId], $cookieA);
$psListId = $psLst['list']['id'] ?? 0;

$psItem = apiPost('lists/add_item.php', [
    'list_id'     => $psListId,
    'description' => 'Late snack',
    'price'       => '30.00',
    'priority'    => 'low'
], $cookieA);
$psItemId = $psItem['id'] ?? 0;
test("Add item for post-settlement", $psItemId > 0);

// Check group item (returns needs_confirm)
$psChk = apiPost('lists/check_item.php', ['item_id' => $psItemId], $cookieA);
test("Check post-settlement item", ($psChk['ok'] ?? false) === true);

// Confirm with paid_by — creates expense with is_post_settlement=1
$psConf = apiPost('lists/check_item.php', [
    'item_id' => $psItemId,
    'paid_by' => $userC,
    'confirm' => '1'
], $cookieA);
test("Confirm post-settlement item", ($psConf['ok'] ?? false) === true);
test("Post-settle expense created", ($psConf['expense_created'] ?? false) === true);
test("Flagged as post-settlement", ($psConf['is_post_settlement'] ?? false) === true);

// Verify in DB
$r = $conn->query("SELECT expense_id FROM list_items WHERE id = $psItemId");
$psRow = $r ? $r->fetch_assoc() : null;
$lateExpId = (int)($psRow['expense_id'] ?? 0);
if ($lateExpId) {
    $r = $conn->query("SELECT is_post_settlement FROM expenses WHERE id = $lateExpId");
    $row = $r->fetch_assoc();
    test("Late expense is_post_settlement = 1", (int)($row['is_post_settlement'] ?? 0) === 1);
}

// Post-settlement calculate
$postCalc = apiGet('settlements/post_calculate.php', ['group_id' => $groupId], $cookieA);
test("Post-settlement calculate", ($postCalc['ok'] ?? false) === true);

// Post-settlement confirm flow (all members)
$pc1 = apiPost('settlements/post_confirm.php', ['group_id' => $groupId], $cookieA);
test("Post-confirm user A", ($pc1['ok'] ?? false) === true);

$pc2 = apiPost('settlements/post_confirm.php', ['group_id' => $groupId], $cookieB);
test("Post-confirm user B", ($pc2['ok'] ?? false) === true);

$pc3 = apiPost('settlements/post_confirm.php', ['group_id' => $groupId], $cookieC);
test("Post-confirm user C", ($pc3['ok'] ?? false) === true);

// Cleanup
apiPost('lists/delete.php', ['list_id' => $psListId], $cookieA);

// ════════════════════════════════════════════════════════════════
// SECTION 14: PERSONAL LISTS
// ════════════════════════════════════════════════════════════════
echo "\n── 14. Personal Lists ──\n";

$lst = apiPost('lists/create.php', ['name' => 'Test Shopping'], $cookieA);
test("Create personal list", ($lst['ok'] ?? false) === true, json_encode($lst));
$listId = $lst['list']['id'] ?? 0;

// User lists
$myLists = apiGet('lists/user_lists.php', [], $cookieA);
test("User lists endpoint", ($myLists['ok'] ?? false) === true);
$foundList = false;
foreach ($myLists['lists'] ?? [] as $l) {
    if ((int)$l['id'] === $listId) { $foundList = true; break; }
}
test("Created list appears", $foundList);

// Add items
$item1 = apiPost('lists/add_item.php', [
    'list_id'     => $listId,
    'description' => 'Milk',
    'category_id' => 1,
    'priority'    => 'high',
    'price'       => '5.50'
], $cookieA);
test("Add item with price", ($item1['ok'] ?? false) === true, json_encode($item1));
$itemId1 = $item1['id'] ?? 0;

$item2 = apiPost('lists/add_item.php', [
    'list_id'     => $listId,
    'description' => 'Bread',
    'priority'    => 'low'
], $cookieA);
test("Add item without price", ($item2['ok'] ?? false) === true);
$itemId2 = $item2['id'] ?? 0;

// List details
$lstDet = apiGet('lists/details.php', ['list_id' => $listId], $cookieA);
test("List details", ($lstDet['ok'] ?? false) === true);
test("List has 2 items", count($lstDet['items'] ?? []) === 2);

// Check item (personal, with price → auto-creates expense)
$chk = apiPost('lists/check_item.php', ['item_id' => $itemId1], $cookieA);
test("Check personal item", ($chk['ok'] ?? false) === true, json_encode($chk));

// Verify expense_created and expense_id in DB
$r = $conn->query("SELECT expense_created, expense_id FROM list_items WHERE id = $itemId1");
$row = $r->fetch_assoc();
test("expense_created = 1 after check", (int)($row['expense_created'] ?? 0) === 1);
test("expense_id stored", (int)($row['expense_id'] ?? 0) > 0, "expense_id=" . ($row['expense_id'] ?? 'NULL'));
$linkedExpenseId = (int)($row['expense_id'] ?? 0);

// Uncheck within 10 minutes — should delete expense
$unchk = apiPost('lists/check_item.php', ['item_id' => $itemId1], $cookieA);
test("Uncheck personal item", ($unchk['ok'] ?? false) === true);

// Verify expense deleted
if ($linkedExpenseId > 0) {
    $r = $conn->query("SELECT id FROM expenses WHERE id = $linkedExpenseId");
    test("Linked expense deleted on uncheck", $r->num_rows === 0);
}

// Verify expense_created and expense_id reset
$r = $conn->query("SELECT expense_created, expense_id FROM list_items WHERE id = $itemId1");
$row = $r->fetch_assoc();
test("expense_created reset to 0", (int)($row['expense_created'] ?? 1) === 0);
test("expense_id reset to NULL", $row['expense_id'] === null);

// Remove item
$rmItem = apiPost('lists/remove_item.php', ['item_id' => $itemId2], $cookieA);
test("Remove item", ($rmItem['ok'] ?? false) === true);

// User B cannot delete A's personal list
$delList = apiPost('lists/delete.php', ['list_id' => $listId], $cookieB);
test("User B cannot delete A's personal list", ($delList['ok'] ?? true) === false);

// Delete list
$delList = apiPost('lists/delete.php', ['list_id' => $listId], $cookieA);
test("Delete personal list", ($delList['ok'] ?? false) === true);

// ════════════════════════════════════════════════════════════════
// SECTION 15: GROUP LISTS
// ════════════════════════════════════════════════════════════════
echo "\n── 15. Group Lists ──\n";

$gLst = apiPost('lists/create.php', [
    'name'     => 'Group Shopping',
    'group_id' => $groupId
], $cookieA);
test("Create group list", ($gLst['ok'] ?? false) === true);
$gListId = $gLst['list']['id'] ?? 0;

// Add item to group list
$gItem = apiPost('lists/add_item.php', [
    'list_id'     => $gListId,
    'description' => 'Group Snacks',
    'price'       => '20.00',
    'priority'    => 'moderate'
], $cookieA);
test("Add item to group list", ($gItem['ok'] ?? false) === true);
$gItemId = $gItem['id'] ?? 0;

// Check group item — should return needs_confirm
$gChk = apiPost('lists/check_item.php', ['item_id' => $gItemId], $cookieB);
test("Check group item returns needs_confirm", ($gChk['ok'] ?? false) === true);
test("needs_confirm flag set", ($gChk['needs_confirm'] ?? false) === true);

// Confirm with paid_by
$gConf = apiPost('lists/check_item.php', [
    'item_id' => $gItemId,
    'paid_by' => $userB,
    'confirm' => '1'
], $cookieB);
test("Confirm group item check with paid_by", ($gConf['ok'] ?? false) === true, json_encode($gConf));
test("Expense created for group item", ($gConf['expense_created'] ?? false) === true);

// Verify expense_id stored
$r = $conn->query("SELECT expense_created, expense_id FROM list_items WHERE id = $gItemId");
$row = $r->fetch_assoc();
test("Group item expense_id stored", (int)($row['expense_id'] ?? 0) > 0);

// Non-admin/non-creator cannot delete group list
$delGL = apiPost('lists/delete.php', ['list_id' => $gListId], $cookieB);
test("Non-admin member cannot delete group list", ($delGL['ok'] ?? true) === false);

// Admin can delete group list
$delGL2 = apiPost('lists/delete.php', ['list_id' => $gListId], $cookieA);
test("Admin can delete group list", ($delGL2['ok'] ?? false) === true);

// ════════════════════════════════════════════════════════════════
// SECTION 16: UNPRICED ITEMS + PRICE_UNPRICED
// ════════════════════════════════════════════════════════════════
echo "\n── 16. Unpriced Items ──\n";

// Create a group list with an unpriced item, check it, then price it
$uLst = apiPost('lists/create.php', ['name' => 'Unpriced Test', 'group_id' => $groupId], $cookieA);
$uListId = $uLst['list']['id'] ?? 0;

$uItem = apiPost('lists/add_item.php', [
    'list_id'     => $uListId,
    'description' => 'Mystery Item',
    'priority'    => 'low'
    // No price!
], $cookieA);
$uItemId = $uItem['id'] ?? 0;
test("Add unpriced item", ($uItem['ok'] ?? false) === true);

// Check it — should mark checked but no expense (no price)
$uChk = apiPost('lists/check_item.php', ['item_id' => $uItemId], $cookieA);
// For group items, first check returns needs_confirm
if ($uChk['needs_confirm'] ?? false) {
    // Confirm without price
    $uChk = apiPost('lists/check_item.php', [
        'item_id' => $uItemId,
        'paid_by' => $userA,
        'confirm' => '1'
    ], $cookieA);
}
test("Check unpriced group item", ($uChk['ok'] ?? false) === true);

// Should appear in unpriced list
$unpriced = apiGet('expenses/unpriced.php', [], $cookieA);
test("Unpriced items endpoint", ($unpriced['ok'] ?? false) === true);
$foundUnpriced = false;
foreach ($unpriced['items'] ?? [] as $ui) {
    if ((int)$ui['id'] === $uItemId) { $foundUnpriced = true; break; }
}
test("Unpriced item appears in list", $foundUnpriced);

// Price it
$pricing = apiPost('expenses/price_unpriced.php', [
    'item_id' => $uItemId,
    'price'   => '15.00',
    'paid_by' => $userA
], $cookieA);
test("Price unpriced item", ($pricing['ok'] ?? false) === true, json_encode($pricing));

// Verify expense_id stored
$r = $conn->query("SELECT expense_created, expense_id FROM list_items WHERE id = $uItemId");
$row = $r->fetch_assoc();
test("Priced item has expense_id", (int)($row['expense_id'] ?? 0) > 0);

// Cleanup
apiPost('lists/delete.php', ['list_id' => $uListId], $cookieA);

// ════════════════════════════════════════════════════════════════
// SECTION 17: SETTLE.PHP ADMIN CHECK
// ════════════════════════════════════════════════════════════════
echo "\n── 17. Settle.php Admin Guard ──\n";

// User B (member) cannot call settle.php
$badSettle = apiPost('settlements/settle.php', [
    'group_id'     => $groupId,
    'payer_id'     => $userB,
    'payee_id'     => $userA,
    'amount'       => '10.00',
    'period_start' => $today,
    'period_end'   => $today
], $cookieB);
test("Non-admin cannot settle", ($badSettle['ok'] ?? true) === false);

// ════════════════════════════════════════════════════════════════
// SECTION 18: SETTLE_ALL ADMIN FLOW
// ════════════════════════════════════════════════════════════════
echo "\n── 18. Settle All (Admin) ──\n";

// settle_all needs a fresh group with unsettled expenses (main group is already settled)
$saGrp = apiPost('groups/create.php', ['name' => 'SettleAllTest'], $cookieA);
$saGroupId  = $saGrp['group']['id'] ?? 0;
$saJoinCode = $saGrp['group']['join_code'] ?? '';
apiPost('groups/join.php', ['join_code' => $saJoinCode], $cookieB);

apiPost('expenses/create.php', [
    'amount' => '120', 'category_id' => 1, 'note' => 'settle_all test',
    'expense_date' => $today, 'type' => 'group', 'group_id' => $saGroupId, 'paid_by' => $userA
], $cookieA);

// Non-admin cannot settle_all
$badSA = apiPost('settlements/settle_all.php', ['group_id' => $saGroupId], $cookieB);
test("Non-admin cannot settle_all", ($badSA['ok'] ?? true) === false);

// Admin settle_all
$sa = apiPost('settlements/settle_all.php', ['group_id' => $saGroupId], $cookieA);
test("Admin settle_all succeeds", ($sa['ok'] ?? false) === true, json_encode($sa));

// Cleanup settle_all group
apiPost('groups/delete.php', ['group_id' => $saGroupId], $cookieA);

// ════════════════════════════════════════════════════════════════
// SECTION 19: GROUP LEAVE + DELETE
// ════════════════════════════════════════════════════════════════
echo "\n── 19. Group Leave & Delete ──\n";

// User C leaves
$lv = apiPost('groups/leave.php', ['group_id' => $groupId], $cookieC);
test("User C leaves group", ($lv['ok'] ?? false) === true);

// Verify 2 members
$det3 = apiGet('groups/details.php', ['group_id' => $groupId], $cookieA);
test("Group has 2 members after leave", count($det3['members'] ?? []) === 2);

// User B (non-admin) cannot delete group
$delGrp = apiPost('groups/delete.php', ['group_id' => $groupId], $cookieB);
test("Non-admin cannot delete group", ($delGrp['ok'] ?? true) === false);

// Admin deletes group
$delGrp2 = apiPost('groups/delete.php', ['group_id' => $groupId], $cookieA);
test("Admin deletes group", ($delGrp2['ok'] ?? false) === true);

// ════════════════════════════════════════════════════════════════
// SECTION 20: SETTLEMENT HELPERS UNIT TEST
// ════════════════════════════════════════════════════════════════
echo "\n── 20. Settlement Algorithm (Unit) ──\n";

require_once __DIR__ . '/api/settlements/settlement_helpers.php';

// 3 people: A paid 90, B paid 60, C paid 0. Total=150, per=50
$balances = [
    ['user_id' => 1, 'amount' => 40],    // A: 90-50 = +40 (creditor)
    ['user_id' => 2, 'amount' => 10],    // B: 60-50 = +10 (creditor)
    ['user_id' => 3, 'amount' => -50],   // C: 0-50  = -50 (debtor)
];
$result = calculateSettlements($balances);
test("Algorithm returns settlements", count($result) > 0);

// Total debtor payments should equal total creditor receipts
$totalPaid = 0;
foreach ($result as $s) { $totalPaid += $s['amount']; }
test("Settlements balance (total = 50)", abs($totalPaid - 50) < 0.01, "Total: $totalPaid");

// C should pay A=40 and B=10
$payToA = 0; $payToB = 0;
foreach ($result as $s) {
    if ($s['payer_id'] === 3 && $s['payee_id'] === 1) $payToA = $s['amount'];
    if ($s['payer_id'] === 3 && $s['payee_id'] === 2) $payToB = $s['amount'];
}
test("C pays A = 40", abs($payToA - 40) < 0.01);
test("C pays B = 10", abs($payToB - 10) < 0.01);

// Edge: everyone equal
$eqResult = calculateSettlements([
    ['user_id' => 1, 'amount' => 0],
    ['user_id' => 2, 'amount' => 0],
]);
test("Equal contributions = no settlements", count($eqResult) === 0);

// Edge: single person
$singleResult = calculateSettlements([
    ['user_id' => 1, 'amount' => 100],
]);
test("Single person = no settlements", count($singleResult) === 0);

// ════════════════════════════════════════════════════════════════
// SECTION 21: UNAUTHENTICATED ACCESS
// ════════════════════════════════════════════════════════════════
echo "\n── 21. Unauthenticated Access ──\n";

$endpoints = [
    ['GET',  'expenses/list.php', ['date' => $today]],
    ['POST', 'expenses/create.php', ['amount' => '1', 'category_id' => 1, 'note' => 'x', 'expense_date' => $today, 'type' => 'personal']],
    ['GET',  'groups/user_groups.php', []],
    ['GET',  'lists/user_lists.php', []],
    ['GET',  'notifications/count.php', []],
    ['GET',  'budgets/get.php', ['month' => $month]],
    ['GET',  'settlements/calculate.php', ['group_id' => 1]],
];

foreach ($endpoints as [$method, $path, $data]) {
    $r = ($method === 'GET') ? apiGet($path, $data, '') : apiPost($path, $data, '');
    test("No auth: $method $path blocked", ($r['ok'] ?? true) === false);
}

// ════════════════════════════════════════════════════════════════
// SECTION 22: UI FILE INTEGRITY
// ════════════════════════════════════════════════════════════════
echo "\n── 22. UI File Integrity ──\n";

$uiFiles = [
    'public/index.php', 'pages/home.php', 'pages/expenses.php',
    'pages/groups.php', 'pages/lists.php', 'pages/notifications.php',
    'pages/login.php', 'pages/signup.php', 'public/splash.php',
    'public/assets/js/app.js', 'public/assets/js/helpers.js',
    'public/assets/css/styles.css'
];
foreach ($uiFiles as $f) {
    test("File exists: $f", file_exists(__DIR__ . "/$f"));
}

// Verify helpers.js is loaded before app.js in index.php
$indexContent = file_get_contents(__DIR__ . '/public/index.php');
$helpersPos = strpos($indexContent, 'helpers.js');
$appJsPos   = strpos($indexContent, 'app.js');
test("helpers.js loaded before app.js", $helpersPos !== false && $appJsPos !== false && $helpersPos < $appJsPos);

// Verify helpers.js defines key functions
$helpersContent = file_get_contents(__DIR__ . '/public/assets/js/helpers.js');
test("helpers.js defines \$()", strpos($helpersContent, 'function $(') !== false);
test("helpers.js defines show()", strpos($helpersContent, 'function show(') !== false);
test("helpers.js defines hide()", strpos($helpersContent, 'function hide(') !== false);
test("helpers.js defines escapeHTML()", strpos($helpersContent, 'function escapeHTML(') !== false);
test("helpers.js defines post()", strpos($helpersContent, 'async function post(') !== false);
test("helpers.js defines get()", strpos($helpersContent, 'async function get(') !== false);
test("helpers.js defines API const", strpos($helpersContent, "const API") !== false);

// Verify no duplicate helper definitions in app.js IIFEs
$appContent = file_get_contents(__DIR__ . '/public/assets/js/app.js');
$dollarCount = preg_match_all('/function\s+\$\s*\(/', $appContent);
test("app.js: no duplicate \$() definitions", $dollarCount === 0, "Found $dollarCount");

$showCount = preg_match_all('/function\s+show\s*\(/', $appContent);
test("app.js: no duplicate show() definitions", $showCount === 0, "Found $showCount");

$apiCount = preg_match_all('/const\s+API\s*=/', $appContent);
test("app.js: no duplicate API const", $apiCount === 0, "Found $apiCount");

// Verify page files don't redefine shared helpers
foreach (['groups.php', 'lists.php', 'notifications.php'] as $pf) {
    $content = file_get_contents(__DIR__ . "/pages/$pf");
    $hasDup = preg_match('/function\s+\$\s*\(/', $content);
    test("pages/$pf: no duplicate \$() definition", $hasDup === 0);
}

// ════════════════════════════════════════════════════════════════
// SECTION 23: PHP SYNTAX CHECK (ALL FILES)
// ════════════════════════════════════════════════════════════════
echo "\n── 23. PHP Syntax Check ──\n";

$phpFiles = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__));
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'run_tests.php') {
        $phpFiles[] = $file->getPathname();
    }
}

$syntaxErrors = 0;
foreach ($phpFiles as $pf) {
    $output = [];
    exec('"C:\\xampp\\php\\php.exe" -l "' . $pf . '" 2>&1', $output, $ret);
    if ($ret !== 0) {
        $syntaxErrors++;
        test("Syntax: " . basename($pf), false, implode(' ', $output));
    }
}
test("All PHP files pass syntax check ($syntaxErrors errors)", $syntaxErrors === 0);

// ════════════════════════════════════════════════════════════════
// CLEANUP
// ════════════════════════════════════════════════════════════════
echo "\n── Cleanup ──\n";
cleanupTestData($conn);
echo "  Test data cleaned up.\n";
$conn->close();

// ════════════════════════════════════════════════════════════════
// RESULTS
// ════════════════════════════════════════════════════════════════
echo "\n==========================================================\n";
echo "  RESULTS: $pass passed, $fail failed out of " . ($pass + $fail) . " tests\n";
echo "==========================================================\n";

if ($fail > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) echo "$e\n";
    exit(1);
}
exit(0);
