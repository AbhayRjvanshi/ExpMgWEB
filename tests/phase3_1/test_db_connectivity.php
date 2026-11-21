<?php
/**
 * Phase 3.1 — Subphase 3.1.3: PostgreSQL PDO Connectivity & Basic Operations
 *
 * Verifies (Mode A: Direct PostgreSQL Connection):
 * 1. Connection establishment via PDO over SSL using application runtime role (expmg_app)
 * 2. Parameterized SELECT, INSERT, UPDATE, DELETE on pre-granted test table
 * 3. Transaction commit integrity
 * 4. Transaction rollback integrity
 * 5. Error handling on invalid credentials
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

echo "=== Phase 3.1: PostgreSQL PDO Connectivity & Operations Test ===\n";
echo "Mode A: Direct PostgreSQL Connection (Port {$port})\n";
echo "Target Host: {$host}:{$port} | DB: {$dbname} | Runtime User: {$user}\n\n";

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

try {
    // 1. Connection
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    echo "[PASS] Successfully connected to PostgreSQL via Direct Mode as runtime role '{$user}'.\n";

    // 2. Server Version
    $stmt = $pdo->query('SELECT version()');
    $version = $stmt->fetchColumn();
    echo "[PASS] PostgreSQL Version: {$version}\n";

    // 3. Parameterized INSERT
    $testNote = 'Feasibility Test ' . bin2hex(random_bytes(4));
    $insertStmt = $pdo->prepare("INSERT INTO _phase31_poc_test (test_note) VALUES (:note) RETURNING id");
    $insertStmt->execute([':note' => $testNote]);
    $newId = (int) $insertStmt->fetchColumn();
    echo "[PASS] Parameterized INSERT succeeded (Generated ID: {$newId}).\n";

    // 4. Parameterized SELECT
    $selectStmt = $pdo->prepare("SELECT * FROM _phase31_poc_test WHERE id = :id");
    $selectStmt->execute([':id' => $newId]);
    $row = $selectStmt->fetch();
    if ($row && $row['test_note'] === $testNote) {
        echo "[PASS] Parameterized SELECT verified exact record content.\n";
    } else {
        throw new Exception("SELECT failed to retrieve expected row data.");
    }

    // 5. Parameterized UPDATE
    $updatedNote = 'Updated Note ' . bin2hex(random_bytes(4));
    $updateStmt = $pdo->prepare("UPDATE _phase31_poc_test SET test_note = :note WHERE id = :id");
    $updateStmt->execute([':note' => $updatedNote, ':id' => $newId]);
    
    $selectUpdated = $pdo->prepare("SELECT test_note FROM _phase31_poc_test WHERE id = :id");
    $selectUpdated->execute([':id' => $newId]);
    if ($selectUpdated->fetchColumn() === $updatedNote) {
        echo "[PASS] Parameterized UPDATE verified record mutation.\n";
    } else {
        throw new Exception("UPDATE verification failed.");
    }

    // 6. Parameterized DELETE
    $deleteStmt = $pdo->prepare("DELETE FROM _phase31_poc_test WHERE id = :id");
    $deleteStmt->execute([':id' => $newId]);
    $selectDeleted = $pdo->prepare("SELECT COUNT(*) FROM _phase31_poc_test WHERE id = :id");
    $selectDeleted->execute([':id' => $newId]);
    if ((int)$selectDeleted->fetchColumn() === 0) {
        echo "[PASS] Parameterized DELETE verified record removal.\n";
    } else {
        throw new Exception("DELETE verification failed.");
    }

    // 7. Transaction Commit Test
    echo "\n--- Transaction Commit Test ---\n";
    $pdo->beginTransaction();
    $txInsert = $pdo->prepare("INSERT INTO _phase31_poc_test (test_note) VALUES (:note) RETURNING id");
    $txInsert->execute([':note' => 'Committed Note']);
    $txId = (int) $txInsert->fetchColumn();
    $pdo->commit();

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM _phase31_poc_test WHERE id = :id");
    $checkStmt->execute([':id' => $txId]);
    if ((int)$checkStmt->fetchColumn() === 1) {
        echo "[PASS] Transaction COMMIT verified: record persisted.\n";
    } else {
        throw new Exception("Transaction COMMIT failed: record missing.");
    }

    // Cleanup committed record
    $pdo->prepare("DELETE FROM _phase31_poc_test WHERE id = :id")->execute([':id' => $txId]);

    // 8. Transaction Rollback Test
    echo "\n--- Transaction Rollback Test ---\n";
    $pdo->beginTransaction();
    $rbInsert = $pdo->prepare("INSERT INTO _phase31_poc_test (test_note) VALUES (:note) RETURNING id");
    $rbInsert->execute([':note' => 'Rollback Note']);
    $rbId = (int) $rbInsert->fetchColumn();
    $pdo->rollBack();

    $checkRb = $pdo->prepare("SELECT COUNT(*) FROM _phase31_poc_test WHERE id = :id");
    $checkRb->execute([':id' => $rbId]);
    if ((int)$checkRb->fetchColumn() === 0) {
        echo "[PASS] Transaction ROLLBACK verified: uncommitted record discarded.\n";
    } else {
        throw new Exception("Transaction ROLLBACK failed: record was not discarded.");
    }

    // 9. Negative Test: Invalid Credentials
    echo "\n--- Negative Test: Invalid Credentials ---\n";
    try {
        $badPdo = new PDO($dsn, $user, 'InvalidPassword_Phase31!Wrong', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        echo "[FAIL] Connection with invalid password unexpectedly succeeded!\n";
    } catch (\PDOException $e) {
        echo "[PASS] Invalid password correctly rejected with PDOException (Code: {$e->getCode()}).\n";
    }

    echo "\n=== Subphase 3.1.3 Result: ALL TESTS PASSED ===\n";
    exit(0);

} catch (\Throwable $e) {
    echo "[FAIL] PostgreSQL PDO test failed: " . $e->getMessage() . "\n";
    exit(1);
}
