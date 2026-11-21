<?php
/**
 * Phase 3.1 — Master Test Suite Runner
 *
 * Sequentially executes all verification test scripts:
 * 1. Subphase 3.1.3 (Driver): test_pdo_driver.php
 * 2. Subphase 3.1.2 (Roles):  test_roles.php
 * 3. Subphase 3.1.3 (CRUD):   test_db_connectivity.php
 * 4. Subphase 3.1.4 (Pooler): test_pooling_modes.php
 * 5. Subphase 3.1.5 (Auth):   test_auth_api.php
 * 6. Subphase 3.1.6 (JWT):    test_jwt_verifier.php
 * 7. Subphase 3.1.7 (Life):   test_token_lifecycle.php
 * 8. Subphase 3.1.8 (Host):   test_hosting_check.php
 */

declare(strict_types=1);

$tests = [
    'Subphase 3.1.3 (Driver)' => 'test_pdo_driver.php',
    'Subphase 3.1.2 (Roles)'  => 'test_roles.php',
    'Subphase 3.1.3 (CRUD)'   => 'test_db_connectivity.php',
    'Subphase 3.1.4 (Pooler)' => 'test_pooling_modes.php',
    'Subphase 3.1.5 (Auth)'   => 'test_auth_api.php',
    'Subphase 3.1.6 (JWT)'    => 'test_jwt_verifier.php',
    'Subphase 3.1.7 (Life)'   => 'test_token_lifecycle.php',
    'Subphase 3.1.8 (Host)'   => 'test_hosting_check.php',
];

echo "===============================================================\n";
echo "      PHASE 3.1 SUPABASE FEASIBILITY TEST SUITE RUNNER         \n";
echo "===============================================================\n\n";

$results = [];
$phpBin = PHP_BINARY;

foreach ($tests as $label => $file) {
    $filePath = __DIR__ . '/' . $file;
    echo ">> Running [{$label}] {$file}...\n";
    $cmd = escapeshellcmd("{$phpBin} {$filePath}");
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        echo "  [FAIL] Could not start process for {$file}\n";
        $results[$label] = ['status' => 'FAIL', 'exit' => -1];
        continue;
    }
    
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    
    if ($exitCode === 0) {
        echo "   -> [PASS] Completed successfully.\n\n";
        $results[$label] = ['status' => 'PASS', 'output' => $stdout];
    } else {
        echo "   -> [FAIL] Exited with code {$exitCode}.\n";
        echo "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}\n\n";
        $results[$label] = ['status' => 'FAIL', 'exit' => $exitCode, 'output' => $stdout . "\n" . $stderr];
    }
}

echo "===============================================================\n";
echo "                   FINAL TEST SUITE SUMMARY                    \n";
echo "===============================================================\n";

$allPassed = true;
foreach ($results as $label => $res) {
    echo str_pad($label, 30) . ": [" . $res['status'] . "]\n";
    if ($res['status'] !== 'PASS') {
        $allPassed = false;
    }
}

echo "\nOverall Suite Result: " . ($allPassed ? "100% ALL TESTS PASSED (FEASIBILITY CONFIRMED)" : "FAILURES DETECTED") . "\n";
exit($allPassed ? 0 : 1);
