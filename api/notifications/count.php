<?php
/**
 * api/notifications/count.php — Unread count + latest unread for popup detection.
 * Polled every ~10s to update the bell badge and trigger real-time popups.
 * Only counts notifications created today (daily lifecycle).
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

$userId = (int) $_SESSION['user_id'];

// Count unread for today only
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0 AND DATE(created_at) = CURDATE()');
$stmt->bind_param('i', $userId);
$stmt->execute();
$cnt = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Latest unread notification (for popup detection)
$latest = null;
$stmt = $conn->prepare('SELECT id, message, type, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND DATE(created_at) = CURDATE() ORDER BY id DESC LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($row) {
    $latest = ['id' => (int)$row['id'], 'message' => $row['message'], 'type' => $row['type'], 'created_at' => $row['created_at']];
}

echo json_encode(['ok' => true, 'count' => $cnt, 'latest' => $latest]);
