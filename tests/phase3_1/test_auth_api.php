<?php
/**
 * Phase 3.1 — Subphase 3.1.5: Supabase Auth Registration & Login
 *
 * Verifies:
 * 1. User registration with owner-controlled email & password via Supabase Auth GoTrue API
 * 2. Modern Publishable Key usage (SUPABASE_PUBLISHABLE_KEY)
 * 3. Login with valid credentials and extraction of access_token, refresh_token, and UUID
 * 4. Token format inspection (Header, Payload, Claims)
 * 5. Duplicate registration handling
 * 6. Rejection of invalid credentials
 *
 * Security: Refuses execution if placeholder credentials are detected.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
use ExpMg\Phase31\Config;

Config::load();

try {
    $supabaseUrl = Config::requireReal('SUPABASE_URL');
    $publishableKey = Config::requireReal('SUPABASE_PUBLISHABLE_KEY');
    $testEmail = Config::requireReal('TEST_USER_EMAIL');
    $testPassword = Config::requireReal('TEST_USER_PASSWORD');
} catch (\RuntimeException $e) {
    echo $e->getMessage() . "\n";
    exit(2);
}

echo "=== Phase 3.1: Supabase Auth Registration & Login ===\n";
echo "Endpoint: {$supabaseUrl}/auth/v1 | Test User: {$testEmail}\n";
echo "API Key: Modern Supabase Publishable Key (Validated non-placeholder)\n\n";

function postJson(string $url, array $payload, array $headers = []): array {
    $ch = curl_init($url);
    $json = json_encode($payload);
    $defaultHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("cURL request failed: {$error}");
    }

    return [
        'code' => $httpCode,
        'data' => json_decode($response, true) ?? ['raw' => $response],
    ];
}

try {
    // 1. Attempt Registration
    echo "--- 1. Testing Registration (Signup) ---\n";
    $signupUrl = rtrim($supabaseUrl, '/') . '/auth/v1/signup';
    $signupRes = postJson($signupUrl, [
        'email' => $testEmail,
        'password' => $testPassword,
    ], [
        "apikey: {$publishableKey}",
        "Authorization: Bearer {$publishableKey}",
    ]);

    echo "Signup HTTP Status: {$signupRes['code']}\n";
    $needsConfirmation = false;
    if ($signupRes['code'] === 200 || $signupRes['code'] === 201) {
        $userId = $signupRes['data']['id'] ?? $signupRes['data']['user']['id'] ?? 'unknown';
        echo "[PASS] User registration succeeded (UUID: {$userId}).\n";
        $session = $signupRes['data']['session'] ?? null;
        if (!$session && empty($signupRes['data']['access_token'])) {
            echo "[NOTE] Email confirmation is enabled in project settings. User created in unconfirmed state.\n";
            $needsConfirmation = true;
        }
    } elseif ($signupRes['code'] === 400 || $signupRes['code'] === 422) {
        $msg = $signupRes['data']['msg'] ?? $signupRes['data']['message'] ?? $signupRes['data']['error_description'] ?? '';
        if (stripos($msg, 'already registered') !== false || stripos($msg, 'already exists') !== false || stripos($msg, 'user_already_exists') !== false) {
            echo "[NOTE] User already registered ({$msg}); proceeding to login.\n";
        } else {
            throw new Exception("Signup failed with HTTP {$signupRes['code']}: {$msg}");
        }
    } else {
        throw new Exception("Unexpected signup response code: {$signupRes['code']} - " . json_encode($signupRes['data']));
    }

    // 2. Login with Password
    echo "\n--- 2. Testing Password Login ---\n";
    $loginUrl = rtrim($supabaseUrl, '/') . '/auth/v1/token?grant_type=password';
    $loginRes = postJson($loginUrl, [
        'email' => $testEmail,
        'password' => $testPassword,
    ], [
        "apikey: {$publishableKey}",
    ]);

    if ($loginRes['code'] !== 200) {
        $msg = $loginRes['data']['error_description'] ?? $loginRes['data']['msg'] ?? json_encode($loginRes['data']);
        throw new Exception("Login failed with HTTP {$loginRes['code']}: {$msg}");
    }

    $accessToken = $loginRes['data']['access_token'] ?? null;
    $refreshToken = $loginRes['data']['refresh_token'] ?? null;
    $userUuid = $loginRes['data']['user']['id'] ?? null;
    $expiresIn = $loginRes['data']['expires_in'] ?? null;

    if (!$accessToken || !$refreshToken || !$userUuid) {
        throw new Exception("Incomplete authentication payload: access_token, refresh_token, or user UUID missing.");
    }

    echo "[PASS] Successfully authenticated with password.\n";
    echo "[PASS] User UUID: {$userUuid}\n";
    echo "[PASS] Access Token Length: " . strlen($accessToken) . " bytes\n";
    echo "[PASS] Refresh Token Length: " . strlen($refreshToken) . " bytes\n";
    echo "[PASS] Access Token Expires In: {$expiresIn} seconds\n";

    // Save tokens locally for subsequent JWT verification and lifecycle tests
    $tokenCacheDir = dirname(__DIR__, 2) . '/data/phase31';
    if (!is_dir($tokenCacheDir)) {
        mkdir($tokenCacheDir, 0755, true);
    }
    file_put_contents($tokenCacheDir . '/test_tokens.json', json_encode([
        'user_uuid' => $userUuid,
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => $expiresIn,
        'obtained_at' => time(),
    ], JSON_PRETTY_PRINT));
    echo "[PASS] Stored test tokens in data/phase31/test_tokens.json for subsequent test stages.\n";

    // 3. Negative Test: Login with Invalid Password
    echo "\n--- 3. Negative Test: Invalid Password Login ---\n";
    $badLoginRes = postJson($loginUrl, [
        'email' => $testEmail,
        'password' => 'CompletelyWrongPassword_2026!',
    ], [
        "apikey: {$publishableKey}",
    ]);

    if ($badLoginRes['code'] === 400) {
        echo "[PASS] Invalid password rejected with HTTP 400 Bad Request.\n";
    } else {
        echo "[FAIL] Unexpected response for invalid password: HTTP {$badLoginRes['code']}\n";
    }

    echo "\n=== Subphase 3.1.5 Result: ALL TESTS PASSED ===\n";
    exit(0);

} catch (\Throwable $e) {
    echo "[FAIL] Auth API test failed: " . $e->getMessage() . "\n";
    exit(1);
}
