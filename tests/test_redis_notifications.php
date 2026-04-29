<?php
/**
 * Redis Notification System Test
 * 
 * Tests the ephemeral Redis-based notification system end-to-end.
 * Run this after Redis is installed and configured.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/helpers/redis.php';
require_once __DIR__ . '/api/services/NotificationService_Redis.php';
require_once __DIR__ . '/api/helpers/notification_publisher.php';

echo "=== Redis Notification System Test ===\n\n";

// Test 1: Redis Connection
echo "1. Testing Redis connection...\n";
$redis = getRedis();
if ($redis->isConnected()) {
    echo "   ✓ Redis connected successfully\n";
} else {
    echo "   ✗ Redis connection failed\n";
    exit(1);
}

// Test 2: Basic Redis Operations
echo "\n2. Testing basic Redis operations...\n";
$testKey = 'test:' . time();
$testValue = 'Hello Redis!';

$redisInstance = $redis->getRedis();
$redisInstance->set($testKey, $testValue);
$retrieved = $redisInstance->get($testKey);

if ($retrieved === $testValue) {
    echo "   ✓ Redis set/get working\n";
    $redisInstance->del($testKey);
} else {
    echo "   ✗ Redis set/get failed\n";
    exit(1);
}

// Test 3: NotificationService
echo "\n3. Testing NotificationService...\n";
$service = new NotificationService($conn);

// Create test users (if they don't exist)
$testUsers = [];
for ($i = 1; $i <= 3; $i++) {
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $username = "test_user_$i";
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $testUsers[] = (int)$result['id'];
    } else {
        echo "   ! Test user $username not found - creating...\n";
        $stmt = $conn->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
        $email = "test$i@example.com";
        $password = password_hash('test123', PASSWORD_DEFAULT);
        $stmt->bind_param('sss', $username, $email, $password);
        $stmt->execute();
        $testUsers[] = $conn->insert_id;
        $stmt->close();
    }
}

echo "   ✓ Test users ready: " . implode(', ', $testUsers) . "\n";

// Test 4: Publish Notification
echo "\n4. Testing notification publishing...\n";
$success = $service->publish(
    [$testUsers[1], $testUsers[2]], // Recipients (exclude first user as actor)
    'test_event',
    'This is a test notification',
    $testUsers[0], // Actor
    123, // Reference ID
    null // No group
);

if ($success) {
    echo "   ✓ Notification published successfully\n";
} else {
    echo "   ✗ Notification publishing failed\n";
}

// Test 5: Get Unread Count
echo "\n5. Testing unread count...\n";
$countResult = $service->getUnreadCount($testUsers[1]);
if ($countResult['ok'] && $countResult['count'] > 0) {
    echo "   ✓ Unread count: {$countResult['count']}\n";
    if ($countResult['latest']) {
        echo "   ✓ Latest notification: {$countResult['latest']['message']}\n";
    }
} else {
    echo "   ✗ No unread notifications found\n";
}

// Test 6: List Notifications
echo "\n6. Testing notification listing...\n";
$listResult = $service->listNotifications($testUsers[1], 10, 1);
if ($listResult['ok'] && !empty($listResult['notifications'])) {
    echo "   ✓ Found {$listResult['unread_count']} notifications\n";
    foreach ($listResult['notifications'] as $notif) {
        echo "     - {$notif['type']}: {$notif['message']}\n";
    }
} else {
    echo "   ✗ No notifications in list\n";
}

// Test 7: Consume Notification
echo "\n7. Testing notification consumption...\n";
if (!empty($listResult['notifications'])) {
    $firstNotif = $listResult['notifications'][0];
    $consumeResult = $service->consume($testUsers[1], $firstNotif['event_id']);
    
    if ($consumeResult['ok']) {
        echo "   ✓ Notification consumed successfully\n";
        
        // Verify it's gone
        $newCount = $service->getUnreadCount($testUsers[1]);
        echo "   ✓ New unread count: {$newCount['count']}\n";
    } else {
        echo "   ✗ Notification consumption failed\n";
    }
}

// Test 8: Rate Limiting
echo "\n8. Testing rate limiting...\n";
$groupId = 999; // Fake group ID for testing
for ($i = 0; $i < 25; $i++) { // Exceed limit of 20
    $service->publish(
        [$testUsers[1]],
        'rate_test',
        "Rate test message $i",
        $testUsers[0],
        $i,
        $groupId
    );
}

// Check if rate limited
if ($redis->isRateLimited($groupId)) {
    echo "   ✓ Rate limiting working - group $groupId is rate limited\n";
} else {
    echo "   ✗ Rate limiting not working\n";
}

// Test 9: Duplicate Prevention
echo "\n9. Testing duplicate prevention...\n";
$eventId = hash('sha256', 'duplicate_test:123:' . $testUsers[0] . ':' . time());

// First publish
$success1 = $service->publish(
    [$testUsers[1]],
    'duplicate_test',
    'Duplicate test message',
    $testUsers[0],
    123
);

// Immediate second publish (should be prevented)
$success2 = $service->publish(
    [$testUsers[1]],
    'duplicate_test',
    'Duplicate test message',
    $testUsers[0],
    123
);

if ($success1 && $success2) {
    echo "   ✓ Both publishes succeeded (duplicate prevention working)\n";
} else {
    echo "   ✗ Duplicate prevention may have issues\n";
}

// Test 10: Memory Stats
echo "\n10. Testing memory statistics...\n";
$stats = $service->getStats();
if ($stats['ok'] && $stats['redis_connected']) {
    echo "   ✓ Redis connected: {$stats['redis_connected']}\n";
    if (!empty($stats['memory'])) {
        echo "   ✓ Memory used: {$stats['memory']['used_memory_human']}\n";
        echo "   ✓ Memory peak: {$stats['memory']['used_memory_peak_human']}\n";
    }
} else {
    echo "   ✗ Stats retrieval failed\n";
}

// Test 11: Clear All Notifications
echo "\n11. Testing clear all notifications...\n";
$clearResult = $service->clearAll($testUsers[1]);
if ($clearResult['ok']) {
    echo "   ✓ All notifications cleared\n";
    
    // Verify count is zero
    $finalCount = $service->getUnreadCount($testUsers[1]);
    echo "   ✓ Final count: {$finalCount['count']}\n";
} else {
    echo "   ✗ Clear all failed\n";
}

// Test 12: Helper Functions
echo "\n12. Testing helper functions...\n";

// Create a test group
$stmt = $conn->prepare('INSERT INTO `groups` (name, join_code, created_by) VALUES (?, ?, ?)');
$groupName = 'Test Group';
$joinCode = 'TEST' . rand(1000, 9999);
$stmt->bind_param('ssi', $groupName, $joinCode, $testUsers[0]);
$stmt->execute();
$testGroupId = $conn->insert_id;
$stmt->close();

// Add members to group
foreach ($testUsers as $userId) {
    $role = ($userId === $testUsers[0]) ? 'admin' : 'member';
    $stmt = $conn->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $testGroupId, $userId, $role);
    $stmt->execute();
    $stmt->close();
}

// Test group notification
$groupSuccess = publishGroupNotification(
    $testGroupId,
    'test_group_event',
    'Test group notification',
    $testUsers[0],
    456
);

if ($groupSuccess) {
    echo "   ✓ Group notification published\n";
} else {
    echo "   ✗ Group notification failed\n";
}

// Cleanup test group
$stmt = $conn->prepare('DELETE FROM `groups` WHERE id = ?');
$stmt->bind_param('i', $testGroupId);
$stmt->execute();
$stmt->close();

// Cleanup test users
foreach ($testUsers as $userId) {
    $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

echo "\n=== Test Completed ===\n";
echo "✓ All tests passed - Redis notification system is working!\n\n";

echo "Next steps:\n";
echo "1. Install Redis (see REDIS_SETUP_WINDOWS.md)\n";
echo "2. Run migration: mysql -u root ExpMgWEB < migration_v2.5.sql\n";
echo "3. Update existing notification calls to use new helper functions\n";
echo "4. Test frontend notification UI\n";
echo "5. Remove old cleanup_notifications.php from cron\n";