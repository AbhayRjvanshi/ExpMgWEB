<?php
/**
 * Notification Cleanup Script
 * 
 * Automatically removes notifications older than 3 days.
 * 
 * Retention Policy:
 * - Notifications older than 3 days are permanently deleted
 * - Cleanup runs daily via cron job
 * - Batch deletion prevents table locks
 * - All operations are logged
 * 
 * Usage:
 *   php scripts/cleanup_notifications.php
 * 
 * Cron Schedule (daily at 3 AM):
 *   0 3 * * * php /path/to/scripts/cleanup_notifications.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/logger.php';

// Configuration
define('RETENTION_DAYS', 3);
define('BATCH_SIZE', 10000);
define('MAX_ITERATIONS', 100); // Safety limit to prevent infinite loops

// Start cleanup
$startTime = microtime(true);
$totalDeleted = 0;
$iterations = 0;

echo "=== Notification Cleanup Started ===\n";
echo "Retention Policy: " . RETENTION_DAYS . " days\n";
echo "Batch Size: " . BATCH_SIZE . " rows\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// Log cleanup start
logMessage('INFO', 'Notification cleanup started', [
    'retention_days' => RETENTION_DAYS,
    'batch_size' => BATCH_SIZE
]);

try {
    // Check current notification count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications");
    $countStmt->execute();
    $beforeCount = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    echo "Notifications before cleanup: " . number_format($beforeCount) . "\n";
    
    // Check how many are expired
    $retentionDays = RETENTION_DAYS;
    $expiredStmt = $conn->prepare("
        SELECT COUNT(*) as expired 
        FROM notifications 
        WHERE created_at < NOW() - INTERVAL ? DAY
    ");
    $expiredStmt->bind_param('i', $retentionDays);
    $expiredStmt->execute();
    $expiredCount = $expiredStmt->get_result()->fetch_assoc()['expired'];
    $expiredStmt->close();
    
    echo "Expired notifications found: " . number_format($expiredCount) . "\n\n";
    
    if ($expiredCount === 0) {
        echo "No expired notifications to clean up.\n";
        logMessage('INFO', 'Notification cleanup completed - no expired records', [
            'total_notifications' => $beforeCount,
            'deleted' => 0,
            'duration_seconds' => round(microtime(true) - $startTime, 2)
        ]);
        exit(0);
    }
    
    // Batch deletion loop
    echo "Starting batch deletion...\n";
    
    $deleteStmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE created_at < NOW() - INTERVAL ? DAY 
        LIMIT ?
    ");
    
    while ($iterations < MAX_ITERATIONS) {
        $iterations++;
        
        // Execute batch deletion
        $retentionDays = RETENTION_DAYS;
        $batchSize = BATCH_SIZE;
        $deleteStmt->bind_param('ii', $retentionDays, $batchSize);
        $deleteStmt->execute();
        $rowsDeleted = $deleteStmt->affected_rows;
        
        $totalDeleted += $rowsDeleted;
        
        echo "Batch $iterations: Deleted $rowsDeleted rows (Total: " . number_format($totalDeleted) . ")\n";
        
        // If fewer rows than batch size were deleted, we're done
        if ($rowsDeleted < BATCH_SIZE) {
            break;
        }
        
        // Small delay to prevent overwhelming the database
        usleep(100000); // 100ms
    }
    
    $deleteStmt->close();
    
    // Check final count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications");
    $countStmt->execute();
    $afterCount = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    $duration = round(microtime(true) - $startTime, 2);
    
    echo "\n=== Cleanup Completed ===\n";
    echo "Notifications before: " . number_format($beforeCount) . "\n";
    echo "Notifications after:  " . number_format($afterCount) . "\n";
    echo "Total deleted:        " . number_format($totalDeleted) . "\n";
    echo "Iterations:           $iterations\n";
    echo "Duration:             {$duration}s\n";
    
    // Log successful cleanup
    logMessage('INFO', 'Notification cleanup completed successfully', [
        'before_count' => $beforeCount,
        'after_count' => $afterCount,
        'deleted' => $totalDeleted,
        'iterations' => $iterations,
        'duration_seconds' => $duration
    ]);
    
    // Alert if cleanup took too long or deleted too many
    if ($duration > 60) {
        logMessage('WARNING', 'Notification cleanup took longer than expected', [
            'duration_seconds' => $duration,
            'deleted' => $totalDeleted
        ]);
    }
    
    if ($totalDeleted > 1000000) {
        logMessage('WARNING', 'Notification cleanup deleted unusually high number of records', [
            'deleted' => $totalDeleted,
            'possible_issue' => 'notification_volume_spike'
        ]);
    }
    
    exit(0);
    
} catch (Exception $e) {
    $errorMsg = "Notification cleanup failed: " . $e->getMessage();
    echo "\n✗ ERROR: $errorMsg\n";
    
    logMessage('ERROR', 'Notification cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'deleted_before_error' => $totalDeleted
    ]);
    
    exit(1);
}
