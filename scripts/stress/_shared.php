<?php

declare(strict_types=1);

if (!function_exists('stress_http_request_core')) {
    function stress_http_request_core(string $method, string $url, array $data = [], string $cookie = '', array $headers = [], int $timeout = 30): array
    {
        $ch = curl_init();

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        if ($cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $started = microtime(true);
        $response = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 0, 'headers' => '', 'body' => '', 'json' => null, 'error' => $error, 'elapsed_ms' => $elapsedMs];
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $json = json_decode($body, true);

        return [
            'status' => $status,
            'headers' => $rawHeaders,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
            'error' => '',
            'elapsed_ms' => $elapsedMs,
        ];
    }

    function stress_extract_cookie_core(string $headers, string $name): string
    {
        if (preg_match_all('/Set-Cookie:\\s*' . preg_quote($name, '/') . '=([^;\\s]+)/i', $headers, $matches) && !empty($matches[1])) {
            return (string) end($matches[1]);
        }

        return '';
    }

    function stress_db_scalar_core(mysqli $conn, string $sql, string $types = '', array $params = []): mixed
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $row[0] ?? null;
    }
}

if (!function_exists('curlRequest')) {
    function curlRequest(string $method, string $url, array $data = [], string $cookie = '', array $headers = []): array
    {
        return stress_http_request_core($method, $url, $data, $cookie, $headers, 30);
    }
}

if (!function_exists('httpRequest')) {
    function httpRequest(string $method, string $url, array $data = [], string $cookie = '', array $headers = []): array
    {
        return stress_http_request_core($method, $url, $data, $cookie, $headers, 30);
    }
}

if (!function_exists('httpReq')) {
    function httpReq(string $method, string $url, array $data = [], string $cookie = '', array $headers = []): array
    {
        return stress_http_request_core($method, $url, $data, $cookie, $headers, 30);
    }
}

if (!function_exists('extractCookie')) {
    function extractCookie(string $headers, string $name): string
    {
        return stress_extract_cookie_core($headers, $name);
    }
}

if (!function_exists('extractCookieValue')) {
    function extractCookieValue(string $headers, string $name): string
    {
        return stress_extract_cookie_core($headers, $name);
    }
}

if (!function_exists('cookieVal')) {
    function cookieVal(string $headers, string $name): string
    {
        return stress_extract_cookie_core($headers, $name);
    }
}

if (!function_exists('dbScalar')) {
    function dbScalar(mysqli $conn, string $sql, string $types = '', array $params = []): mixed
    {
        return stress_db_scalar_core($conn, $sql, $types, $params);
    }
}

if (!function_exists('dbSingleInt')) {
    function dbSingleInt(mysqli $conn, string $sql, string $types = '', array $params = []): int
    {
        return (int) stress_db_scalar_core($conn, $sql, $types, $params);
    }
}

if (!function_exists('dbInt')) {
    function dbInt(mysqli $conn, string $sql, string $types = '', array $params = []): int
    {
        return (int) stress_db_scalar_core($conn, $sql, $types, $params);
    }
}