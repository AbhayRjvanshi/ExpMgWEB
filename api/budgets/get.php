<?php
/**
 * api/budgets/get.php — Retrieve budget for a given month.
 * GET ?month=YYYY-MM
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$month  = trim($_GET['month'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['ok' => false, 'error' => 'Provide ?month=YYYY-MM']);
    exit;
}

$stmt = $conn->prepare("SELECT id, amount_limit, budget_month FROM budgets WHERE user_id = ? AND budget_month = ?");
$stmt->bind_param('is', $userId, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    echo json_encode(['ok' => true, 'budget' => ['id' => (int) $row['id'], 'amount_limit' => (float) $row['amount_limit'], 'month' => $row['budget_month']]]);
} else {
    echo json_encode(['ok' => true, 'budget' => null]);
}
