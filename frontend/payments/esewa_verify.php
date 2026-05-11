<?php
// Place this file at: C:\xampp\htdocs\gurkha-marga\frontend\payments\esewa_verify.php

// Path math:
//   dirname(__FILE__)                       = gurkha-marga/frontend/payments
//   dirname(dirname(__FILE__))              = gurkha-marga/frontend
//   dirname(dirname(dirname(__FILE__)))     = gurkha-marga  <-- BASE_PATH
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

// ── 1. Parse eSewa response ────────────────────────────────────────────────
$rawData     = $_GET['data'] ?? '';
$decodedJson = $rawData ? base64_decode($rawData) : '';
$esewaData   = $decodedJson ? json_decode($decodedJson, true) : [];

if (empty($esewaData)) {
    $esewaData = [
        'transaction_uuid' => $_GET['transaction_uuid'] ?? $_POST['transaction_uuid'] ?? '',
        'total_amount'     => $_GET['total_amount']     ?? $_POST['total_amount']     ?? '',
        'status'           => $_GET['status']           ?? $_POST['status']           ?? '',
        'product_code'     => $_GET['product_code']     ?? $_POST['product_code']     ?? '',
        'ref_id'           => $_GET['refId']            ?? $_POST['refId']            ?? '',
    ];
}

$txnUUID     = $esewaData['transaction_uuid'] ?? '';
$status      = $esewaData['status']           ?? '';
$refId       = $esewaData['transaction_code'] ?? $esewaData['ref_id'] ?? '';
$amount      = (float)($esewaData['total_amount'] ?? 0);
$productCode = $esewaData['product_code']     ?? '';

// ── 2. Get pending subscription from cookie ────────────────────────────────
$pendingSubId = (int)($_COOKIE['pending_sub_id'] ?? 0);
$pendingUUID  = $_COOKIE['pending_uuid'] ?? '';

if (!$txnUUID || !$pendingSubId) {
    showResult('failed', 'Missing payment data. Please contact support.', null);
    exit();
}

// ── 3. Verify with eSewa API ───────────────────────────────────────────────
$verifyUrl    = 'https://rc-epay.esewa.com.np/api/epay/transaction/status/';
$verifyParams = http_build_query([
    'product_code'     => $productCode ?: 'EPAYTEST',
    'total_amount'     => $amount,
    'transaction_uuid' => $txnUUID,
]);

$verified    = false;
$apiResponse = '';

try {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 15,
        'header'  => 'Accept: application/json',
    ]]);
    $apiResponse = @file_get_contents("{$verifyUrl}?{$verifyParams}", false, $ctx);
    if ($apiResponse) {
        $apiData  = json_decode($apiResponse, true);
        $verified = isset($apiData['status']) && strtolower($apiData['status']) === 'complete';
    }
} catch (Exception $e) {
    error_log('eSewa verify error: ' . $e->getMessage());
}

// ── 4. Record the transaction ──────────────────────────────────────────────
$sub = fetchOne("SELECT * FROM subscriptions WHERE id=?", [$pendingSubId]);
if ($sub) {
    insert(
        "INSERT INTO payment_transactions
         (subscription_id, user_id, amount, esewa_transaction_uuid, esewa_ref_id, esewa_status, raw_response, verified)
         VALUES (?,?,?,?,?,?,?,?)",
        [
            $pendingSubId, $sub['user_id'], $amount,
            $txnUUID, $refId, $status,
            json_encode($esewaData), (int)$verified
        ]
    );
}

// ── 5. Activate if verified ────────────────────────────────────────────────
if ($verified && $sub) {
    $activated = activateSubscription($pendingSubId, $refId);
    setcookie('pending_sub_id', '', time() - 3600, '/');
    setcookie('pending_uuid',   '', time() - 3600, '/');

    if ($activated) {
        showResult('success', 'Your subscription is now active!', $sub);
    } else {
        showResult('pending', 'Payment received but activation is pending. Please contact support.', $sub);
    }
} else {
    execute("UPDATE subscriptions SET status='cancelled' WHERE id=?", [$pendingSubId]);
    showResult('failed', 'Payment could not be verified. Please try again or contact support.', null);
}

// ── Render the result page ─────────────────────────────────────────────────
function showResult(string $type, string $message, ?array $sub): void {
    $isSuccess = $type === 'success';
    $isPending = $type === 'pending';

    $title  = $isSuccess ? 'Payment Successful!' : ($isPending ? 'Payment Pending' : 'Payment Failed');
    $emoji  = $isSuccess ? '🎉' : ($isPending ? '⏳' : '❌');
    $color  = $isSuccess ? '#10b981' : ($isPending ? '#fbbf24' : '#ef4444');
    $bgClr  = $isSuccess ? 'rgba(16,185,129,.08)' : ($isPending ? 'rgba(251,191,36,.08)' : 'rgba(239,68,68,.08)');
    $border = $isSuccess ? 'rgba(16,185,129,.25)' : ($isPending ? 'rgba(251,191,36,.25)' : 'rgba(239,68,68,.25)');

    $planName  = htmlspecialchars($sub['plan_name'] ?? '');
    $expiresAt = !empty($sub['expires_at']) ? date('d M Y', strtotime($sub['expires_at'])) : '';
    $redirect  = $isSuccess
        ? '<meta http-equiv="refresh" content="4;url=/gurkha-marga/frontend/users/questions.php">'
        : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?> — Gurkha Marga</title>
<?= $redirect ?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Poppins',sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;color:#f8fafc}
.card{background:rgba(30,41,59,.9);border:1px solid <?= $border ?>;border-radius:20px;padding:3rem 2.5rem;max-width:480px;width:100%;text-align:center;box-shadow:0 25px 60px rgba(0,0,0,.4);animation:popIn .5s cubic-bezier(.34,1.56,.64,1) both}
@keyframes popIn{from{opacity:0;transform:scale(.85) translateY(20px)}to{opacity:1;transform:none}}
.emoji{font-size:5rem;display:block;margin-bottom:1.25rem;animation:bounce 1s ease infinite alternate}
@keyframes bounce{from{transform:translateY(0)}to{transform:translateY(-10px)}}
.badge{display:inline-flex;align-items:center;gap:6px;padding:.35rem 1rem;border-radius:99px;background:<?= $bgClr ?>;border:1px solid <?= $border ?>;color:<?= $color ?>;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:1.25rem}
h1{font-size:1.5rem;font-weight:800;color:<?= $color ?>;margin-bottom:.6rem}
.msg{font-size:.88rem;color:#94a3b8;line-height:1.7;margin-bottom:1.75rem}
.details{background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.15);border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:1.75rem;text-align:left}
.drow{display:flex;justify-content:space-between;align-items:center;padding:.45rem 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.drow:last-child{border-bottom:none}
.dlbl{color:#64748b}.dval{color:#e2e8f0;font-weight:600}
.feats{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-bottom:1.75rem;text-align:left}
.feat{background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.12);border-radius:9px;padding:.65rem .85rem;font-size:.75rem;color:#94a3b8;line-height:1.5;display:flex;align-items:flex-start;gap:.5rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:.8rem 2.25rem;border-radius:10px;font-family:'Poppins',sans-serif;font-size:.88rem;font-weight:700;text-decoration:none;transition:all .2s;border:none;cursor:pointer;width:100%}
.btn-primary{background:linear-gradient(135deg,<?= $color ?>,<?= $isSuccess?'#059669':($isPending?'#d97706':'#dc2626') ?>);color:#fff}
.btn-primary:hover{opacity:.9;transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.3)}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,.1);color:#94a3b8;margin-top:.65rem}
.btn-ghost:hover{background:rgba(255,255,255,.05);color:#e2e8f0}
.redir-note{font-size:.73rem;color:#475569;margin-top:1rem;line-height:1.6}
.redir-bar{width:100%;height:3px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden;margin-top:.6rem}
.redir-fill{height:100%;background:linear-gradient(90deg,#10b981,#34d399);border-radius:2px;animation:fillBar 4s linear forwards}
@keyframes fillBar{from{width:0%}to{width:100%}}
</style>
</head>
<body>
<div class="card">
    <span class="emoji"><?= $emoji ?></span>
    <div class="badge">
        <?php if ($isSuccess): ?>✓ Payment Verified
        <?php elseif ($isPending): ?>⏳ Pending Review
        <?php else: ?>✗ Payment Failed<?php endif; ?>
    </div>
    <h1><?= $title ?></h1>
    <p class="msg"><?= htmlspecialchars($message) ?></p>

    <?php if ($isSuccess): ?>
    <div class="details">
        <?php if ($planName): ?>
        <div class="drow"><span class="dlbl">Plan</span><span class="dval">⭐ <?= $planName ?></span></div>
        <?php endif; ?>
        <?php if ($expiresAt): ?>
        <div class="drow"><span class="dlbl">Access until</span><span class="dval"><?= $expiresAt ?></span></div>
        <?php endif; ?>
        <div class="drow"><span class="dlbl">Status</span><span class="dval" style="color:#10b981">Active ✓</span></div>
    </div>
    <div class="feats">
        <div class="feat"><span>🎯</span><span>Full Quiz &amp; MCQ Practice</span></div>
        <div class="feat"><span>💬</span><span>Premium AI Consultant</span></div>
        <div class="feat"><span>🥗</span><span>Expert Dietician Chat</span></div>
        <div class="feat"><span>📊</span><span>Progress Analytics</span></div>
    </div>
    <a href="/gurkha-marga/frontend/users/questions.php" class="btn btn-primary">🚀 Start Learning Now</a>
    <div class="redir-note">
        Redirecting automatically in 4 seconds…
        <div class="redir-bar"><div class="redir-fill"></div></div>
    </div>

    <?php elseif ($isPending): ?>
    <a href="/gurkha-marga/frontend/users/subscription.php" class="btn btn-primary">View Subscription Status</a>
    <a href="/gurkha-marga/frontend/users/dashboard.php" class="btn btn-ghost">← Go to Dashboard</a>

    <?php else: ?>
    <a href="/gurkha-marga/frontend/users/subscription.php" class="btn btn-primary">🔄 Try Again</a>
    <a href="/gurkha-marga/frontend/users/dashboard.php" class="btn btn-ghost">← Go to Dashboard</a>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    exit();
}