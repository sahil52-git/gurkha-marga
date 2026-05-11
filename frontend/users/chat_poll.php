<?php
// frontend/users/chat_poll.php
// Called every 4s by the chat JS to get new messages
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    echo json_encode(['messages'=>[]]); exit();
}

$userId      = (int)$_SESSION['user_id'];
$sub         = getActiveSubscription($userId);
if (!$sub) { echo json_encode(['messages'=>[]]); exit(); }

$consultantId = (int)($_GET['consultant_id'] ?? 0);
$afterId      = (int)($_GET['after'] ?? 0);

// Verify assignment
$assigned = fetchOne(
    "SELECT 1 FROM user_consultants WHERE user_id=? AND consultant_id=?",
    [$userId, $consultantId]
);
if (!$assigned) { echo json_encode(['messages'=>[]]); exit(); }

$messages = fetchAll(
    "SELECT m.*, c.full_name AS consultant_name
     FROM chat_messages m
     JOIN consultants c ON c.id=m.consultant_id
     WHERE m.subscription_id=? AND m.consultant_id=? AND m.id>?
     ORDER BY m.created_at ASC LIMIT 50",
    [$sub['id'], $consultantId, $afterId]
);

// Mark as read
if (!empty($messages)) {
    execute(
        "UPDATE chat_messages SET is_read=1
         WHERE subscription_id=? AND consultant_id=? AND sender_type='consultant' AND is_read=0",
        [$sub['id'], $consultantId]
    );
}

echo json_encode(['messages' => $messages]);