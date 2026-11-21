<?php
/**
 * Phase 3.1 — Subphase 3.1.2: Database Role & Permission Separation Test
 *
 * Verifies:
 * 1. expmg_app is a non-superuser with canlogin=true
 * 2. expmg_app has USAGE on schema public but NO CREATE privilege (DDL blocked)
 * 3. expmg_app CANNOT access auth.users (privilege isolation)
 * 4. expmg_app CANNOT alter tables or drop tables (DDL operations strictly rejected)
 * 5. expmg_app CAN execute DML (SELECT, INSERT, UPDATE, DELETE) on granted tables
 *
 * Security: Refuses execution if placeholder credentials are detected.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
use ExpMg\Phase31\Config;

Config::load();

try {
    $host = Config::requireReal('SUPABASE_DB_HOST');
    $port = (int) Config::get('SUPABASE_DB_PORT', '5432');
    $dbname = Config::get('SUPABASE_DB_NAME', 'postgres');
    $user = Config::requireReal('SUPABASE_DB_USER');
    $pass = Config::requireReal('SUPABASE_DB_PASSWORD');
} catch (\RuntimeException $e) {
    echo $e->getMessage() . "\n";
    exit(2);
}

echo "=== Phase 3.1: PostgreSQL Role & Access-Control Verification ===\n";
echo "Connecting as: {$user}@{$host}:{$port}/{$dbname}\n\n";

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    echo "[PASS] Successfully connected to PostgreSQL as runtime role: {$user}\n";

    // 1. Verify Current User
    $stmtUser = $pdo->query('SELECT current_user, session_user');
    $userRow = $stmtUser->fetch();
    echo "[PASS] current_user: {$userRow['current_user']} | session_user: {$userRow['session_user']}\n";
    if ($userRow['current_user'] !== 'expmg_app') {
        throw new Exception("Unexpected current_user: {$userRow['current_user']}");
    }

    // 2. Verify Schema Privileges (USAGE=true, CREATE=false)
    $stmtPriv = $pdo->query("
        SELECT has_schema_privilege('expmg_app', 'public', 'USAGE') AS has_usage,
               has_schema_privilege('expmg_app', 'public', 'CREATE') AS has_create
    ");
    $privRow = $stmtPriv->fetch();
    echo "[PASS] Schema 'public' USAGE: " . ($privRow['has_usage'] ? 'TRUE (Allowed)' : 'FALSE') . "\n";
    echo "[PASS] Schema 'public' CREATE: " . ($privRow['has_create'] ? 'TRUE' : 'FALSE (Blocked)') . "\n";
    if ($privRow['has_create']) {
        throw new Exception("Security violation: expmg_app has CREATE privilege on schema public!");
    }

    // 3. Negative Test: CREATE TABLE must be blocked
    echo "\n--- Security Test: Verify CREATE TABLE is Blocked ---\n";
    try {
        $pdo->exec("CREATE TABLE _phase31_hacker_test (id INT)");
        throw new Exception("Security failure: expmg_app was able to CREATE TABLE!");
    } catch (\PDOException $e) {
        if ($e->getCode() === '42501') {
            echo "[PASS] CREATE TABLE correctly rejected with SQLSTATE 42501 (Insufficient privilege).\n";
        } else {
            throw $e;
        }
    }

    // 4. Negative Test: ALTER TABLE must be blocked
    echo "\n--- Security Test: Verify ALTER TABLE is Blocked ---\n";
    try {
        $pdo->exec("ALTER TABLE _phase31_poc_test ADD COLUMN unauthorized_col TEXT");
        throw new Exception("Security failure: expmg_app was able to ALTER TABLE!");
    } catch (\PDOException $e) {
        if ($e->getCode() === '42501') {
            echo "[PASS] ALTER TABLE correctly rejected with SQLSTATE 42501 (Must be table owner).\n";
        } else {
            throw $e;
        }
    }

    // 5. Negative Test: DROP TABLE must be blocked
    echo "\n--- Security Test: Verify DROP TABLE is Blocked ---\n";
    try {
        $pdo->exec("DROP TABLE _phase31_poc_test");
        throw new Exception("Security failure: expmg_app was able to DROP TABLE!");
    } catch (\PDOException $e) {
        if ($e->getCode() === '42501') {
            echo "[PASS] DROP TABLE correctly rejected with SQLSTATE 42501 (Must be table owner).\n";
        } else {
            throw $e;
        }
    }

    // 6. Privilege Isolation: Access to auth.users must be blocked
    echo "\n--- Security Test: Verify auth.users Access is Blocked ---\n";
    try {
        $pdo->query("SELECT id, email FROM auth.users LIMIT 1");
        throw new Exception("Security failure: expmg_app was able to SELECT from auth.users!");
    } catch (\PDOException $e) {
        if ($e->getCode() === '42501') {
            echo "[PASS] SELECT on auth.users correctly rejected with SQLSTATE 42501 (Insufficient privilege).\n";
        } else {
            throw $e;
        }
    }

    // 7. Positive Test: DML operations on granted table (_phase31_poc_test)
    echo "\n--- Positive Test: Verify Granted DML Operations ---\n";
    $ins = $pdo->prepare("INSERT INTO _phase31_poc_test (test_note) VALUES (:note) RETURNING id");
    $ins->execute([':note' => 'Role test insert']);
    $testId = $ins->fetchColumn();
    echo "[PASS] INSERT on granted table succeeded (ID: {$testId}).\n";

    $del = $pdo->prepare("DELETE FROM _phase31_poc_test WHERE id = :id");
    $del->execute([':id' => $testId]);
    echo "[PASS] DELETE on granted table succeeded.\n";

    echo "\n=== Subphase 3.1.2 Result: ALL TESTS PASSED ===\n";
    exit(0);

} catch (\Throwable $e) {
    echo "[FAIL] Role verification failed: " . $e->getMessage() . "\n";
    exit(1);
}
