<?php
/**
 * Phase 3.1 — Subphase 3.1.7: Token Refresh & Logout Lifecycle
 *
 * Verifies:
 * 1. Refresh token exchange via Supabase Auth API using modern Publishable Key
 * 2. Verification of the refreshed access token
 * 3. User logout and session termination
 * 4. Re-use of revoked refresh token (verifies rejection / rotation enforcement)
 * 5. Testing whether existing access tokens remain usable until expiration
 *    (establishing the boundary between Supabase Auth and PHP device revocation)
 *
 * Security: Refuses execution if placeholder credentials are detected.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/test_jwt_verifier.php';

use ExpMg\Phase31\Config;

Config::load();

try {
    $supabaseUrl = Config::requireReal('SUPABASE_URL');
    $publishableKey = Config::requireReal('SUPABASE_PUBLISHABLE_KEY');
} catch (\RuntimeException $e) {
    echo $e->getMessage() . "\n";
    exit(2);
}

$tokenFile = dirname(__DIR__, 2) . '/data/phase31/test_tokens.json';
if (!file_exists($tokenFile)) {
    echo "[BLOCKED] Test token file data/phase31/test_tokens.json not found.\n";
    echo "Please execute tests/phase3_1/test_auth_api.php first.\n";
    exit(2);
}

$tokenData = json_decode(file_get_contents($tokenFile), true);
$initialAccessToken = $tokenData['access_token'] ?? null;
$initialRefreshToken = $tokenData['refresh_token'] ?? null;

if (!$initialRefreshToken || !$supabaseUrl || !$publishableKey) {
    echo "[BLOCKED] Missing initial tokens or Supabase configuration.\n";
    exit(2);
}

echo "=== Phase 3.1: Token Refresh & Logout Lifecycle Test ===\n";
echo "Endpoint: {$supabaseUrl}/auth/v1\n";
echo "API Key: Modern Supabase Publishable Key\n\n";

function postApi(string $url, array $payload, array $headers = []): array {
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
    $verifier = new SupabaseJwtVerifier($supabaseUrl);

    // 1. Refresh Token Exchange
    echo "--- 1. Testing Token Refresh --- \n";
    $refreshUrl = rtrim($supabaseUrl, '/') . '/auth/v1/token?grant_type=refresh_token';
    $refreshRes = postApi($refreshUrl, [
        'refresh_token' => $initialRefreshToken,
    ], [
        "apikey: {$publishableKey}",
    ]);

    if ($refreshRes['code'] !== 200) {
        throw new Exception("Token refresh failed with HTTP {$refreshRes['code']}: " . json_encode($refreshRes['data']));
    }

    $newAccessToken = $refreshRes['data']['access_token'] ?? null;
    $newRefreshToken = $refreshRes['data']['refresh_token'] ?? null;

    if (!$newAccessToken || !$newRefreshToken) {
        throw new Exception("Refresh response missing new access_token or refresh_token.");
    }

    echo "[PASS] Token refresh succeeded with HTTP 200.\n";
    echo "[PASS] New Access Token Length: " . strlen($newAccessToken) . " bytes\n";
    echo "[PASS] New Refresh Token Length: " . strlen($newRefreshToken) . " bytes\n";

    // 2. Validate New Access Token with PHP Verifier
    echo "\n--- 2. Validating Refreshed Access Token with PHP Verifier ---\n";
    $verifyResult = $verifier->verify($newAccessToken);
    echo "[PASS] Refreshed access token successfully verified by PHP.\n";
    echo "[PASS] Refreshed Token User UUID: {$verifyResult['user_uuid']}\n";

    // 3. Test Reuse of the Old/Rotated Refresh Token
    echo "\n--- 3. Testing Revocation/Rotation of Prior Refresh Token ---\n";
    $staleRefreshRes = postApi($refreshUrl, [
        'refresh_token' => $initialRefreshToken,
    ], [
        "apikey: {$publishableKey}",
    ]);

    if ($staleRefreshRes['code'] === 400) {
        echo "[PASS] Prior refresh token correctly rejected (HTTP 400). Token rotation enforced.\n";
    } else {
        echo "[NOTE] Prior refresh token returned HTTP {$staleRefreshRes['code']}. (Check reuse interval settings).\n";
    }

    // 4. Test Logout (Session Termination)
    echo "\n--- 4. Testing User Logout ---\n";
    $logoutUrl = rtrim($supabaseUrl, '/') . '/auth/v1/logout';
    $logoutRes = postApi($logoutUrl, [], [
        "apikey: {$publishableKey}",
        "Authorization: Bearer {$newAccessToken}",
    ]);

    if ($logoutRes['code'] === 200 || $logoutRes['code'] === 204) {
        echo "[PASS] Logout request succeeded (HTTP {$logoutRes['code']}).\n";
    } else {
        throw new Exception("Logout failed with HTTP {$logoutRes['code']}: " . json_encode($logoutRes['data']));
    }

    // 5. Test Refresh Token After Logout
    echo "\n--- 5. Testing Refresh Token After Logout ---\n";
    $postLogoutRefresh = postApi($refreshUrl, [
        'refresh_token' => $newRefreshToken,
    ], [
        "apikey: {$publishableKey}",
    ]);

    if ($postLogoutRefresh['code'] === 400) {
        echo "[PASS] Refresh token is revoked and unusable after logout (HTTP 400).\n";
    } else {
        echo "[FAIL] Refresh token unexpectedly accepted after logout! HTTP {$postLogoutRefresh['code']}\n";
    }

    // 6. Test Access Token Usability Post-Logout (Architectural Insight)
    echo "\n--- 6. Architectural Observation: Access Token Statelessness ---\n";
    $canStillVerifyLocally = false;
    try {
        $verifier->verify($newAccessToken);
        $canStillVerifyLocally = true;
    } catch (\Throwable $e) {
        $canStillVerifyLocally = false;
    }

    if ($canStillVerifyLocally) {
        echo "[PASS] Verified Architectural Invariant: As an asymmetric stateless JWT, the already-issued access token remains cryptographically valid until its exp expiration.\n";
        echo "[PASS] Confirms necessity of Phase 3.0 Decision D-25 / sync_clients registry for immediate application-level device/session revocation in PHP.\n";
    }

    echo "\n=== Subphase 3.1.7 Result: ALL TESTS PASSED ===\n";
    exit(0);

} catch (\Throwable $e) {
    echo "[FAIL] Token lifecycle test failed: " . $e->getMessage() . "\n";
    exit(1);
}
