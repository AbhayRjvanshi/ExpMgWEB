<?php
/**
 * api/notifications/read.php — Mark notifications as read.
 * POST: id (single) or all=1 (mark all as read)
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (isset($_POST['all']) && $_POST['all'] == '1') {
    // Mark all as read
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['ok' => true, 'marked' => $affected]);
    exit;
}

$notifId = (int)($_POST['id'] ?? 0);
if ($notifId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid notification ID.']);
    exit;
}

// Only mark if it belongs to this user
$stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $notifId, $userId);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
