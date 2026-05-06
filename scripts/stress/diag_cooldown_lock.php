<?php
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../api/helpers/rate_limiter.php';

$ip = 'diag-ui-cooldown';
$action = 'api_diag';
$cooldownAction = 'cooldown:' . $action;

$safeIp = $conn->real_escape_string($ip);
$safeAction = $conn->real_escape_string($action);
$safeCooldown = $conn->real_escape_string($cooldownAction);

$conn->query("DELETE FROM rate_limits WHERE ip_address='{$safeIp}' AND (action='{$safeAction}' OR action='{$safeCooldown}')");

for ($i = 0; $i < 65; $i++) {
    recordRateLimit($conn, $ip, $action, 60);
}

$allowed = checkRateLimit($conn, $ip, $action, 60, 60);
$retry1 = rateLimitRetryAfter($conn, $ip, $action, 60);

checkRateLimit($conn, $ip, $action, 60, 60);
$retry2 = rateLimitRetryAfter($conn, $ip, $action, 60);

sleep(2);
checkRateLimit($conn, $ip, $action, 60, 60);
$retry3 = rateLimitRetryAfter($conn, $ip, $action, 60);

echo json_encode([
    'allowed' => $allowed,
    'retry1' => $retry1,
    'retry2' => $retry2,
    'retry3_after_2s' => $retry3,
], JSON_PRETTY_PRINT) . PHP_EOL;
