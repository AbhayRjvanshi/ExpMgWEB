<?php
/**
 * api/helpers/response.php — Centralized API response & error handling.
 *
 * Provides consistent JSON responses with proper HTTP status codes.
 * All functions output JSON and call exit() — nothing runs after them.
 */

require_once __DIR__ . '/logger.php';

function apiRequestContext(): array {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $endpoint = $uri !== '' ? $uri : $script;
    return [
        'endpoint' => $endpoint,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
}

function apiEnsureJsonHeaders(int $status): void {
    http_response_code($status);
    header('Content-Type: application/json');
}

function apiInstallGlobalErrorHandler(): void {
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    set_exception_handler(function (Throwable $e): void {
        logMessage('ERROR', 'Unhandled API exception', array_merge(apiRequestContext(), [
            'error' => $e->getMessage(),
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        apiEnsureJsonHeaders(500);
        echo json_encode([
            'ok' => false,
            'success' => false,
            'error' => 'Internal server error.',
            'code' => 500
        ]);
        exit;
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        logMessage('ERROR', 'Fatal API shutdown', array_merge(apiRequestContext(), [
            'error' => $error['message'] ?? 'Fatal error',
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0
        ]));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        apiEnsureJsonHeaders(500);
        echo json_encode([
            'ok' => false,
            'success' => false,
            'error' => 'Internal server error.',
            'code' => 500
        ]);
    });
}

/**
 * Send a JSON response derived from a service result array.
 * Automatically maps 'ok' => true to HTTP 200 and 'ok' => false to HTTP 400.
 * Pass an explicit $status to override.
 */
function apiResponse(array $result, int $status = 0): void {
    if ($status === 0) {
        $status = ($result['ok'] ?? false) ? 200 : 400;
    }
    $result['ok'] = (bool) ($result['ok'] ?? ($status >= 200 && $status < 300));
    $result['success'] = $result['ok'];
    $result['code'] = $status;

    if (!$result['ok']) {
        logMessage('ERROR', 'API failure response', array_merge(apiRequestContext(), [
            'status_code' => $status,
            'error' => (string) ($result['error'] ?? 'Unknown API failure')
        ]));
    }

    apiEnsureJsonHeaders($status);
    echo json_encode($result);
    exit;
}

/**
 * Send a JSON success response and exit.
 * Merges ['ok' => true] with any additional data.
 */
function apiSuccess(array $data = [], int $status = 200): void {
    apiEnsureJsonHeaders($status);
    echo json_encode(array_merge([
        'ok' => true,
        'success' => true,
        'code' => $status
    ], $data));
    exit;
}

/**
 * Send a JSON error response and exit.
 * Default HTTP status is 400 (Bad Request).
 */
function apiError(string $message, int $status = 400): void {
    logMessage('ERROR', 'API error', array_merge(apiRequestContext(), [
        'status_code' => $status,
        'error' => $message
    ]));

    apiEnsureJsonHeaders($status);
    echo json_encode([
        'ok' => false,
        'success' => false,
        'error' => $message,
        'code' => $status
    ]);
    exit;
}

apiInstallGlobalErrorHandler();
