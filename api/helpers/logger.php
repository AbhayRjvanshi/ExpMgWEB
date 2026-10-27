<?php
/**
 * api/helpers/logger.php — Centralized structured logging.
 *
 * Writes JSON log entries to logs/app.log.
 * Each entry contains: timestamp, level, message, and optional context metadata.
 *
 * Log levels:
 *   INFO    — normal events (login, expense created, settlement completed)
 *   WARNING — suspicious behavior (unauthorized access, invalid input patterns)
 *   ERROR   — system failures (uncaught exceptions, DB errors)
 *
 * Usage:
 *   require_once __DIR__ . '/logger.php';
 *   logMessage('INFO', 'Expense created', ['user_id' => 3, 'amount' => 200]);
 */

function logMessage(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/../../logs/app.log';

    // 10 MB rotation guard: keeps logs/ bounded on long-lived installs
    // (file_put_contents below would otherwise grow app.log forever).
    // The previous log is kept as a single archive (app.log.1), so disk
    // usage is capped at ~20 MB total.
    if (@filesize($logFile) > 10 * 1024 * 1024) {
        @unlink($logFile . '.1');
        @rename($logFile, $logFile . '.1');
    }

    $entry = [
        'time'    => date('Y-m-d H:i:s'),
        'level'   => $level,
        'message' => $message,
        'context' => $context
    ];

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
