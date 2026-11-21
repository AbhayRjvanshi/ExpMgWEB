<?php
/**
 * Phase 3.1 — Subphase 3.1.8: PHP Hosting Compatibility & Outbound Network Probe
 *
 * Checks:
 * 1. PHP Runtime Capabilities (SAPI, memory_limit, max_execution_time)
 * 2. Mandatory Extension Availability for Hybrid Architecture
 * 3. Outbound Network & Port Accessibility:
 *    - Port 443 (HTTPS / Supabase Auth GoTrue API & JWKS)
 *    - Port 5432 (PostgreSQL Direct / Session Mode)
 *    - Port 6543 (Supavisor Connection Pooler Transaction Mode)
 * 4. SSL/TLS Negotiation & Certificate Validation
 * 5. DNS Resolution (IPv4 vs IPv6)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
use ExpMg\Phase31\Config;

Config::load(null, false);

$supabaseUrl = Config::get('SUPABASE_URL', 'https://supabase.com');
$dbHost = Config::get('SUPABASE_DB_HOST', 'db.supabase.co');
$poolerHost = Config::get('SUPABASE_POOLER_HOST') ?: $dbHost;

echo "=== Phase 3.1: PHP Hosting Environment & Network Compatibility ===\n\n";

// 1. Runtime Environment
echo "--- 1. PHP Runtime Profile ---\n";
echo "PHP Version:        " . PHP_VERSION . "\n";
echo "PHP SAPI:           " . PHP_SAPI . "\n";
echo "OS / Architecture:  " . PHP_OS . " (" . php_uname('m') . ")\n";
echo "Memory Limit:       " . ini_get('memory_limit') . "\n";
echo "Max Execution Time: " . ini_get('max_execution_time') . "s\n";
echo "Default Timezone:   " . date_default_timezone_get() . "\n";

// 2. Mandatory Extensions
echo "\n--- 2. Extension Audit ---\n";
$mandatoryExtensions = [
    'pdo' => 'Database abstraction layer',
    'pdo_pgsql' => 'PostgreSQL PDO driver (Database communication)',
    'openssl' => 'Asymmetric JWT signature verification (RS256)',
    'curl' => 'HTTP client for Supabase Auth API & JWKS retrieval',
    'json' => 'Payload serialization and token decoding',
    'mbstring' => 'Multi-byte string operations and length checks',
    'session' => 'Legacy website BFF session bridge support',
    'filter' => 'Input validation and sanitization',
];

$allExtensionsOk = true;
foreach ($mandatoryExtensions as $ext => $desc) {
    $loaded = extension_loaded($ext);
    echo ($loaded ? "[PASS] " : "[FAIL] ") . str_pad($ext, 12) . " - {$desc}\n";
    if (!$loaded) {
        $allExtensionsOk = false;
    }
}

// 3. DNS & Outbound Network Probes
echo "\n--- 3. Outbound Network & Port Accessibility Probes ---\n";

function probeSocket(string $host, int $port, int $timeout = 5, bool $useSsl = false): array {
    $target = $useSsl ? "ssl://{$host}" : $host;
    $start = microtime(true);
    $errno = 0;
    $errstr = '';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'capture_peer_cert' => true,
        ],
    ]);

    $fp = @stream_socket_client(
        "{$target}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    $durationMs = round((microtime(true) - $start) * 1000, 2);

    if ($fp) {
        fclose($fp);
        return [
            'success' => true,
            'latency_ms' => $durationMs,
            'error' => null,
        ];
    }

    return [
        'success' => false,
        'latency_ms' => $durationMs,
        'error' => "({$errno}) {$errstr}",
    ];
}

// Extract host from Supabase URL
$parsedUrl = parse_url($supabaseUrl);
$apiHost = $parsedUrl['host'] ?? 'supabase.com';

// Probe Port 443 (HTTPS)
$res443 = probeSocket($apiHost, 443, 5, true);
echo ($res443['success'] ? "[PASS] " : "[FAIL] ") . "Port 443 (HTTPS to {$apiHost}): " .
    ($res443['success'] ? "Connected & SSL Handshake Validated ({$res443['latency_ms']} ms)" : "Failed: {$res443['error']}") . "\n";

// Probe Port 5432 (PostgreSQL Session Mode)
$res5432 = probeSocket($dbHost, 5432, 5, false);
echo ($res5432['success'] ? "[PASS] " : "[NOTE] ") . "Port 5432 (PostgreSQL Direct/Session to {$dbHost}): " .
    ($res5432['success'] ? "Reachable ({$res5432['latency_ms']} ms)" : "Unreachable or not configured: {$res5432['error']}") . "\n";

// Probe Port 6543 (Supavisor Transaction Pooler)
$res6543 = probeSocket($poolerHost, 6543, 5, false);
echo ($res6543['success'] ? "[PASS] " : "[NOTE] ") . "Port 6543 (Supavisor Pooler to {$poolerHost}): " .
    ($res6543['success'] ? "Reachable ({$res6543['latency_ms']} ms)" : "Unreachable or not configured: {$res6543['error']}") . "\n";

// 4. DNS Resolution Check
echo "\n--- 4. DNS Resolution (IPv4 / IPv6) ---\n";
$ipAddresses = gethostbynamel($apiHost);
if ($ipAddresses) {
    echo "[PASS] Resolved {$apiHost} to: " . implode(', ', $ipAddresses) . "\n";
} else {
    echo "[FAIL] Failed to resolve {$apiHost}\n";
}

echo "\n=========================================================\n";
$exitCode = ($allExtensionsOk && $res443['success']) ? 0 : 1;
exit($exitCode);
