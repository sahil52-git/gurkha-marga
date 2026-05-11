<?php
// frontend/payments/create_subscription.php
// Lives at: gurkha-marga/frontend/payments/create_subscription.php
session_start();
// From frontend/payments/ → up 3 levels = project root
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$userId = (int)$_SESSION['user_id'];
$planId = (int)($_POST['plan_id'] ?? 0);
$uuid   = trim($_POST['uuid']    ?? '');

if (!$planId || !$uuid) {
    echo json_encode(['error' => 'Invalid params: plan_id and uuid required']); exit();
}

$plan = fetchOne("SELECT * FROM subscription_plans WHERE id=? AND is_active=1", [$planId]);
if (!$plan) {
    echo json_encode(['error' => 'Plan not found or inactive']); exit();
}

// Cancel any stale pending subs for this user
execute(
    "UPDATE subscriptions SET status='cancelled' WHERE user_id=? AND status='pending'",
    [$userId]
);

// Create pending subscription
$subId = createPendingSubscription($userId, $planId);

// Store UUID for verification later
execute("UPDATE subscriptions SET esewa_ref_id=? WHERE id=?", [$uuid, $subId]);

echo json_encode(['subscription_id' => (int)$subId, 'uuid' => $uuid]);