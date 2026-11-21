<?php
/**
 * Phase 3.1 — Subphase 3.1.3: PHP Environment & PDO Driver Verification
 *
 * Verifies:
 * 1. PHP Version (8.2+)
 * 2. PDO core extension loaded
 * 3. pdo_pgsql extension loaded
 * 4. OpenSSL extension loaded (required for JWT verification)
 * 5. cURL extension loaded (required for Supabase Auth API calls)
 * 6. Native driver capabilities and encoding support
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$results = [
    'test' => 'PHP PDO PostgreSQL Driver & Environment Verification',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'os' => PHP_OS,
    'checks' => [],
    'status' => 'PASS',
];

function recordCheck(string $name, bool $passed, string $details): void {
    global $results;
    $results['checks'][] = [
        'name' => $name,
        'passed' => $passed,
        'details' => $details,
    ];
    if (!$passed) {
        $results['status'] = 'FAIL';
    }
    echo ($passed ? "[PASS] " : "[FAIL] ") . "{$name}: {$details}\n";
}

echo "=== Phase 3.1: PHP Environment & Driver Check ===\n\n";

// 1. PHP Version
$phpVersionOk = version_compare(PHP_VERSION, '8.2.0', '>=');
recordCheck('PHP Version', $phpVersionOk, "Current: " . PHP_VERSION . " (Required: >= 8.2.0)");

// 2. PDO Core
$pdoCoreOk = extension_loaded('pdo');
recordCheck('PDO Core Extension', $pdoCoreOk, $pdoCoreOk ? 'Loaded' : 'Missing');

// 3. pdo_pgsql
$pdoPgsqlOk = extension_loaded('pdo_pgsql');
recordCheck('PDO PostgreSQL Driver (pdo_pgsql)', $pdoPgsqlOk, $pdoPgsqlOk ? 'Loaded and available' : 'Missing (enable extension=pdo_pgsql in php.ini)');

// 4. pgsql driver list in PDO
$availableDrivers = \PDO::getAvailableDrivers();
$hasPgsqlInPdo = in_array('pgsql', $availableDrivers, true);
recordCheck('PDO Drivers List', $hasPgsqlInPdo, "Available drivers: " . implode(', ', $availableDrivers));

// 5. OpenSSL (for JWT RS256/ES256 verification)
$opensslOk = extension_loaded('openssl');
recordCheck('OpenSSL Extension', $opensslOk, $opensslOk ? 'Loaded' : 'Missing');

// 6. cURL (for Auth GoTrue API communication)
$curlOk = extension_loaded('curl');
recordCheck('cURL Extension', $curlOk, $curlOk ? 'Loaded' : 'Missing');

// 7. JSON extension
$jsonOk = extension_loaded('json');
recordCheck('JSON Extension', $jsonOk, $jsonOk ? 'Loaded' : 'Missing');

echo "\nResult Summary: " . $results['status'] . "\n";
echo "=================================================\n";

return $results['status'] === 'PASS' ? 0 : 1;
