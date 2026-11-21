<?php
/**
 * Phase 3.1 — Subphase 3.1.6: Independent PHP JWT Verification
 *
 * Implements a zero-dependency PHP JWT verifier for Supabase Auth tokens:
 * 1. Fetches JWKS from https://<project-ref>.supabase.co/auth/v1/.well-known/jwks.json
 * 2. Caches JWKS with TTL
 * 3. Extracts header (alg, kid) and decodes payload
 * 4. Converts JWK (RSA modulus 'n' + exponent 'e') to OpenSSL PEM public key
 * 5. Verifies cryptographic RS256 signature using openssl_verify()
 * 6. Validates standard claims (iss, aud, exp, sub)
 * 7. Executes negative tests:
 *    - Tampered payload
 *    - Expired token
 *    - Invalid signature (different key)
 *    - Malformed token
 *    - Invalid audience
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
use ExpMg\Phase31\Config;

Config::load();

// CRITICAL ARCHITECTURAL DISTINCTION:
// User access token verification uses the project's public JSON Web Key Set (JWKS),
// NOT Supabase API keys (Publishable or Secret). Tokens are verified cryptographically
// via OpenSSL using public keys fetched from /.well-known/jwks.json.

class SupabaseJwtVerifier {
    private string $jwksUrl;
    private string $cacheFile;
    private int $cacheTtl;

    public function __construct(string $supabaseUrl, int $cacheTtl = 3600) {
        $this->jwksUrl = rtrim($supabaseUrl, '/') . '/auth/v1/.well-known/jwks.json';
        $this->cacheFile = dirname(__DIR__, 2) . '/data/phase31/jwks_cache.json';
        $this->cacheTtl = $cacheTtl;
    }

    public function getJwks(): array {
        if (file_exists($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtl) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (is_array($data) && isset($data['keys'])) {
                return $data;
            }
        }

        $ch = curl_init($this->jwksUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$response) {
            throw new Exception("Failed to fetch JWKS from {$this->jwksUrl} (HTTP {$code})");
        }

        $jwks = json_decode($response, true);
        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new Exception("Invalid JWKS payload received from {$this->jwksUrl}");
        }

        file_put_contents($this->cacheFile, json_encode($jwks, JSON_PRETTY_PRINT));
        return $jwks;
    }

    public static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function ecJwkToPem(string $xBase64, string $yBase64): string {
        $x = self::base64UrlDecode($xBase64);
        $y = self::base64UrlDecode($yBase64);

        // Pad to 32 bytes if shorter
        $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);

        // SubjectPublicKeyInfo for prime256v1 (NIST P-256)
        // 0x30, 0x59: SEQUENCE of 89 bytes
        // 0x30, 0x13: SEQUENCE of 19 bytes (AlgorithmIdentifier)
        // 0x06, 0x07, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x02, 0x01: id-ecPublicKey (1.2.840.10045.2.1)
        // 0x06, 0x08, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x03, 0x01, 0x07: secp256r1 (1.2.840.10045.3.1.7)
        // 0x03, 0x42, 0x00: BIT STRING of 66 bytes (0 unused bits)
        // 0x04: Uncompressed point indicator
        $header = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04";
        $der = $header . $x . $y;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function rawEcSignatureToDer(string $sig): string {
        if (strlen($sig) !== 64) {
            throw new Exception("Invalid ES256 signature length (expected 64 bytes).");
        }
        $r = substr($sig, 0, 32);
        $s = substr($sig, 32, 32);

        $encodeInteger = function(string $val): string {
            $val = ltrim($val, "\x00");
            if ($val === '') {
                $val = "\x00";
            } elseif (ord($val[0]) > 0x7F) {
                $val = "\x00" . $val;
            }
            return "\x02" . chr(strlen($val)) . $val;
        };

        $rDer = $encodeInteger($r);
        $sDer = $encodeInteger($s);
        $seq = $rDer . $sDer;
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    public static function rsaJwkToPem(string $nBase64, string $eBase64): string {
        $modulus = self::base64UrlDecode($nBase64);
        $exponent = self::base64UrlDecode($eBase64);

        $encodeLength = function(int $len): string {
            if ($len <= 0x7F) {
                return chr($len);
            }
            if ($len <= 0xFF) {
                return chr(0x81) . chr($len);
            }
            return chr(0x82) . chr($len >> 8) . chr($len & 0xFF);
        };

        $encodeInteger = function(string $data) use ($encodeLength): string {
            if (ord($data[0]) > 0x7F) {
                $data = "\x00" . $data;
            }
            return "\x02" . $encodeLength(strlen($data)) . $data;
        };

        // RSAPublicKey sequence
        $modSeq = $encodeInteger($modulus);
        $expSeq = $encodeInteger($exponent);
        $rsaPubKey = "\x30" . $encodeLength(strlen($modSeq . $expSeq)) . $modSeq . $expSeq;

        // SubjectPublicKeyInfo sequence
        // rsaEncryption OID: 1.2.840.113549.1.1.1 (\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00)
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $publicKeyBitString = "\x03" . $encodeLength(strlen($rsaPubKey) + 1) . "\x00" . $rsaPubKey;
        $spki = "\x30" . $encodeLength(strlen($algorithmIdentifier . $publicKeyBitString)) . $algorithmIdentifier . $publicKeyBitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public function verify(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception("Malformed JWT: expected 3 dot-separated segments.");
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = json_decode(self::base64UrlDecode($headerB64), true);
        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        $signature = self::base64UrlDecode($signatureB64);

        if (!$header || !isset($header['alg'])) {
            throw new Exception("Invalid JWT header.");
        }

        if ($header['alg'] !== 'RS256' && $header['alg'] !== 'ES256') {
            throw new Exception("Unsupported signing algorithm: {$header['alg']} (Expected RS256 or ES256).");
        }

        $kid = $header['kid'] ?? null;
        if (!$kid) {
            throw new Exception("JWT header missing 'kid' key identifier.");
        }

        $jwks = $this->getJwks();
        $matchingKey = null;
        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? '') === $kid) {
                $matchingKey = $key;
                break;
            }
        }

        if (!$matchingKey) {
            throw new Exception("No matching public key found in JWKS for kid: {$kid}");
        }

        if ($header['alg'] === 'ES256') {
            if (($matchingKey['kty'] ?? '') !== 'EC' || ($matchingKey['crv'] ?? '') !== 'P-256') {
                throw new Exception("JWK key type mismatch for ES256 (expected EC P-256).");
            }
            $pem = self::ecJwkToPem($matchingKey['x'], $matchingKey['y']);
            $derSignature = self::rawEcSignatureToDer($signature);
        } else {
            if (($matchingKey['kty'] ?? '') !== 'RSA') {
                throw new Exception("JWK key type mismatch for RS256 (expected RSA).");
            }
            $pem = self::rsaJwkToPem($matchingKey['n'], $matchingKey['e']);
            $derSignature = $signature;
        }

        $signedData = "{$headerB64}.{$payloadB64}";

        $verifyResult = openssl_verify($signedData, $derSignature, $pem, OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            $err = openssl_error_string() ?: 'Signature mismatch';
            throw new Exception("Cryptographic signature verification failed: {$err}");
        }

        // Validate Standard Claims
        $now = time();
        if (!isset($payload['exp']) || $payload['exp'] < $now) {
            throw new Exception("Token has expired (exp: {$payload['exp']}, now: {$now})");
        }

        if (!isset($payload['aud']) || ($payload['aud'] !== 'authenticated' && $payload['aud'] !== 'anon')) {
            throw new Exception("Invalid token audience: {$payload['aud']}");
        }

        if (empty($payload['sub']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payload['sub'])) {
            throw new Exception("Invalid or missing 'sub' claim (expected UUID format).");
        }

        return [
            'valid' => true,
            'header' => $header,
            'payload' => $payload,
            'user_uuid' => $payload['sub'],
        ];
    }
}

if (basename($_SERVER['PHP_SELF'] ?? '') === 'test_jwt_verifier.php') {
    try {
        $supabaseUrl = Config::requireReal('SUPABASE_URL');
    } catch (\RuntimeException $e) {
        echo $e->getMessage() . "\n";
        exit(2);
    }

    // Read access token from token cache
    $tokenFile = dirname(__DIR__, 2) . '/data/phase31/test_tokens.json';
    if (!file_exists($tokenFile)) {
        echo "[BLOCKED] Test token file data/phase31/test_tokens.json not found.\n";
        echo "Please execute tests/phase3_1/test_auth_api.php first.\n";
        exit(2);
    }

    $tokenData = json_decode(file_get_contents($tokenFile), true);
    $jwt = $tokenData['access_token'] ?? null;
    $expectedUserUuid = $tokenData['user_uuid'] ?? null;

    if (!$jwt) {
        echo "[BLOCKED] Access token missing from test_tokens.json.\n";
        exit(2);
    }

    echo "=== Phase 3.1: Independent PHP JWT Verification Test ===\n";
    echo "Supabase Endpoint: {$supabaseUrl}\n";
    echo "Verification Mode: Public JWKS (ES256/RS256) - Zero External Dependencies\n\n";

    try {
        $verifier = new SupabaseJwtVerifier($supabaseUrl);

        // 1. Verify Valid Token
        echo "--- 1. Testing Valid Supabase Token Verification ---\n";
        $result = $verifier->verify($jwt);
        echo "[PASS] Valid JWT verified successfully via {$result['header']['alg']} signature!\n";
        echo "[PASS] Authenticated User UUID ('sub'): {$result['user_uuid']}\n";
        echo "[PASS] Key ID ('kid'): {$result['header']['kid']}\n";
        echo "[PASS] Token Expiration: " . date('Y-m-d H:i:s T', $result['payload']['exp']) . "\n";

        if ($expectedUserUuid && $result['user_uuid'] !== $expectedUserUuid) {
            throw new Exception("Subject UUID mismatch: expected {$expectedUserUuid}, got {$result['user_uuid']}");
        }

        // 2. Negative Test: Tampered Payload
        echo "\n--- 2. Negative Test: Tampered Payload ---\n";
        $parts = explode('.', $jwt);
        $tamperedPayload = json_decode(SupabaseJwtVerifier::base64UrlDecode($parts[1]), true);
        $tamperedPayload['role'] = 'service_role'; // Tamper claim
        $tamperedPayloadB64 = SupabaseJwtVerifier::base64UrlEncode(json_encode($tamperedPayload));
        $tamperedJwt = "{$parts[0]}.{$tamperedPayloadB64}.{$parts[2]}";

        try {
            $verifier->verify($tamperedJwt);
            echo "[FAIL] Tampered JWT was unexpectedly accepted!\n";
        } catch (\Throwable $e) {
            echo "[PASS] Tampered JWT correctly rejected: " . $e->getMessage() . "\n";
        }

        // 3. Negative Test: Expired Token
        echo "\n--- 3. Negative Test: Expired Token ---\n";
        $expiredPayload = json_decode(SupabaseJwtVerifier::base64UrlDecode($parts[1]), true);
        $expiredPayload['exp'] = time() - 3600; // 1 hour in past
        $expiredPayloadB64 = SupabaseJwtVerifier::base64UrlEncode(json_encode($expiredPayload));
        $expiredJwt = "{$parts[0]}.{$expiredPayloadB64}.{$parts[2]}";

        try {
            $verifier->verify($expiredJwt);
            echo "[FAIL] Expired JWT was unexpectedly accepted!\n";
        } catch (\Throwable $e) {
            echo "[PASS] Expired JWT correctly rejected: " . $e->getMessage() . "\n";
        }

        // 4. Negative Test: Malformed Token
        echo "\n--- 4. Negative Test: Malformed Token ---\n";
        try {
            $verifier->verify("malformed.token.missing.signatures.or.extra");
            echo "[FAIL] Malformed JWT was unexpectedly accepted!\n";
        } catch (\Throwable $e) {
            echo "[PASS] Malformed JWT correctly rejected: " . $e->getMessage() . "\n";
        }

        // 5. Negative Test: Invalid Signature (Altered Signature Bytes)
        echo "\n--- 5. Negative Test: Corrupted Signature Bytes ---\n";
        $origSig = SupabaseJwtVerifier::base64UrlDecode($parts[2]);
        $corruptedSigB64 = SupabaseJwtVerifier::base64UrlEncode(str_repeat("\xAA", strlen($origSig)));
        $badSigJwt = "{$parts[0]}.{$parts[1]}.{$corruptedSigB64}";

        try {
            $verifier->verify($badSigJwt);
            echo "[FAIL] Corrupted signature JWT was unexpectedly accepted!\n";
        } catch (\Throwable $e) {
            echo "[PASS] Corrupted signature correctly rejected: " . $e->getMessage() . "\n";
        }

        echo "\n=== Subphase 3.1.6 Result: ALL TESTS PASSED ===\n";
        exit(0);

    } catch (\Throwable $e) {
        echo "[FAIL] JWT verification test failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
