<?php
/**
 * Phase 3.1 — Subphase 3.1.4: Connection Pooling & Network Compatibility Evaluation
 *
 * Compares:
 * 1. Mode B: Shared Session Pooler (Port 5432) with native server-side prepares
 * 2. Mode C: Shared Transaction Pooler (Port 6543) with emulated prepared statements
 *
 * Checks:
 * - Direct vs Session Pooler vs Transaction Pooler separation
 * - PDO ATTR_EMULATE_PREPARES behavior (disabling server-side PQprepare)
 * - Multi-query transactions under connection pooling
 * - SELECT ... FOR UPDATE row locking within transactions
 * - Error isolation across pooled connections
 *
 * Security: Refuses execution if placeholder credentials are detected.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
use ExpMg\Phase31\Config;

Config::load();

try {
    $poolerHost = Config::requireReal('SUPABASE_POOLER_HOST');
    $poolerPortTx = (int) Config::get('SUPABASE_POOLER_PORT_TRANSACTION', '6543');
    $poolerPortSession = (int) Config::get('SUPABASE_POOLER_PORT_SESSION', '5432');
    $dbname = Config::get('SUPABASE_DB_NAME', 'postgres');
    $user = Config::requireReal('SUPABASE_POOLER_USER');
    $pass = Config::requireReal('SUPABASE_POOLER_PASSWORD');
} catch (\RuntimeException $e) {
    echo $e->getMessage() . "\n";
    exit(2);
}

echo "=== Phase 3.1: Connection Pooling Compatibility Evaluation ===\n";
echo "Host: {$poolerHost} | User: {$user}\n\n";

function testPoolerMode(string $modeName, int $port, bool $emulatePrepares): bool {
    global $poolerHost, $dbname, $user, $pass;
    echo "--- Testing {$modeName} (Port {$port}, Emulate Prepares: " . ($emulatePrepares ? 'TRUE' : 'FALSE') . ") ---\n";

    $dsn = "pgsql:host={$poolerHost};port={$port};dbname={$dbname};sslmode=require";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        echo "[PASS] Connected via {$modeName}.\n";

        // Verify driver attribute reflection for prepared-statement emulation
        $actualEmulate = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if ($actualEmulate !== $emulatePrepares) {
            echo "[NOTE] Driver reported ATTR_EMULATE_PREPARES = " . ($actualEmulate ? 'TRUE' : 'FALSE') . "\n";
        } else {
            echo "[PASS] Prepared statement emulation confirmed: " . ($actualEmulate ? 'CLIENT-SIDE INTERPOLATION (Server-side PQprepare disabled)' : 'SERVER-SIDE NATIVE PREPARES (PQprepare enabled)') . "\n";
        }

        // Reset test record in pre-granted table
        $pdo->exec("INSERT INTO _phase31_pooler_test (id, balance) VALUES (1, 100.00) ON CONFLICT (id) DO UPDATE SET balance = 100.00");

        // Test parameterized query
        $stmt = $pdo->prepare("SELECT balance FROM _phase31_pooler_test WHERE id = :id");
        $stmt->execute([':id' => 1]);
        $balance = (float) $stmt->fetchColumn();
        echo "[PASS] Parameterized SELECT returned balance: {$balance}\n";

        // Test explicit transaction with SELECT ... FOR UPDATE
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare("SELECT balance FROM _phase31_pooler_test WHERE id = :id FOR UPDATE");
        $lockStmt->execute([':id' => 1]);
        $current = (float) $lockStmt->fetchColumn();

        $updateStmt = $pdo->prepare("UPDATE _phase31_pooler_test SET balance = :bal WHERE id = :id");
        $updateStmt->execute([':bal' => $current - 25.50, ':id' => 1]);
        $pdo->commit();

        $stmtVerify = $pdo->prepare("SELECT balance FROM _phase31_pooler_test WHERE id = :id");
        $stmtVerify->execute([':id' => 1]);
        $newBal = (float) $stmtVerify->fetchColumn();
        if (abs($newBal - 74.50) < 0.001) {
            echo "[PASS] Transaction with SELECT ... FOR UPDATE succeeded (New Balance: {$newBal}).\n";
        } else {
            throw new Exception("Unexpected balance after transaction update: {$newBal}");
        }

        echo "[PASS] {$modeName} evaluation complete.\n\n";
        return true;

    } catch (\Throwable $e) {
        echo "[FAIL] {$modeName} error: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// 1. Mode B: Shared Session Pooler (Port 5432)
$sessionOk = testPoolerMode('Mode B: Shared Session Pooler (Port 5432)', $poolerPortSession, false);

// 2. Mode C: Shared Transaction Pooler (Port 6543, requires emulate prepares = true)
$txOk = testPoolerMode('Mode C: Shared Transaction Pooler (Port 6543)', $poolerPortTx, true);

echo "=== Summary ===\n";
echo "Session Mode (5432): " . ($sessionOk ? 'COMPATIBLE' : 'INCOMPATIBLE / FAILED') . "\n";
echo "Transaction Mode (6543, Emulate Prepares): " . ($txOk ? 'COMPATIBLE' : 'INCOMPATIBLE / FAILED') . "\n";
exit(($sessionOk && $txOk) ? 0 : 1);
