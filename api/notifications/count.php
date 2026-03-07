<?php
/**
 * api/notifications/count.php — Lightweight endpoint returning only unread count.
 * Used for polling (every ~15s) to update the bell badge.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'count' => 0]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0');
$stmt->bind_param('i', $userId);
$stmt->execute();
$cnt = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

echo json_encode(['ok' => true, 'count' => $cnt]);
