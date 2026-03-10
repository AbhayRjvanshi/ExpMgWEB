<?php
/**
 * api/budgets/set.php — Create or update a monthly budget.
 * POST: month (YYYY-MM), amount_limit (number > 0)
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$month  = trim($_POST['month'] ?? '');
$limit  = floatval($_POST['amount_limit'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid month format.']);
    exit;
}
if ($limit <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Budget must be greater than zero.']);
    exit;
}

// Upsert: INSERT … ON DUPLICATE KEY UPDATE
$sql = "INSERT INTO budgets (user_id, budget_month, amount_limit)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE amount_limit = VALUES(amount_limit), updated_at = CURRENT_TIMESTAMP";
$stmt = $conn->prepare($sql);
$stmt->bind_param('isd', $userId, $month, $limit);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'message' => 'Budget saved.']);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to save budget.']);
}
$stmt->close();
