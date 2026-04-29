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
