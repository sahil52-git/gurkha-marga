<?php
// frontend/payments/get_signature.php
// Lives at: gurkha-marga/frontend/payments/get_signature.php
session_start();
// From frontend/payments/ → up 3 levels = project root
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/config/Config.php';

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}

$amount      = trim($_POST['amount']       ?? '');
$uuid        = trim($_POST['uuid']         ?? '');
$productCode = trim($_POST['product_code'] ?? '');

if (!$amount || !$uuid || !$productCode) {
    echo json_encode(['error' => 'Missing params: amount, uuid, product_code required']); exit();
}

$secretKey = Config::get('ESEWA_SECRET_KEY', '8gBm/:&EnhH.1/q');
$message   = "total_amount={$amount},transaction_uuid={$uuid},product_code={$productCode}";
$signature = base64_encode(hash_hmac('sha256', $message, $secretKey, true));

echo json_encode(['signature' => $signature]);