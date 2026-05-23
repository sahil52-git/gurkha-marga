<?php
// frontend/users/subscription_fixed.php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}

$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$sub       = getActiveSubscription($userId);
$isPremium = (bool)$sub;
$plans     = fetchAll("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY price");
$firstName    = explode(' ', trim($user['full_name']))[0];
$initials     = strtoupper(substr($user['full_name'], 0, 1));
$expKey       = strtolower($user['experience_level'] ?? 'beginner');

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

// ── eSewa config ─────────────────────────────────────────────────────────
require_once BASE_PATH . '/backend/config/Config.php';
$ESEWA_PRODUCT_CODE = Config::get('ESEWA_PRODUCT_CODE', 'EPAYTEST');
$ESEWA_SECRET_KEY   = Config::get('ESEWA_SECRET_KEY',   '8gBm/:&EnhH.1/q');
$ESEWA_GATEWAY_URL  = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];
$successUrl = $scheme . '://' . $host . '/gurkha-marga/frontend/payments/esewa_verify.php';
$failureUrl = $scheme . '://' . $host . '/gurkha-marga/frontend/users/subscription_fixed.php?status=failed';
$jsCreateSubUrl = '../payments/create_subscription.php';
$jsGetSigUrl    = '../payments/get_signature.php';

$selectedSlug = $_GET['plan'] ?? 'monthly';
$selectedPlan = null;
foreach ($plans as $p) {
    if ($p['slug'] === $selectedSlug) { $selectedPlan = $p; break; }
}
if (!$selectedPlan && !empty($plans)) $selectedPlan = $plans[0];

$initialAmount = (float)($selectedPlan['price'] ?? 499);
$initialUuid   = 'gm-' . time() . '-' . bin2hex(random_bytes(5));
$signMsg       = "total_amount={$initialAmount},transaction_uuid={$initialUuid},product_code={$ESEWA_PRODUCT_CODE}";
$initialSig    = base64_encode(hash_hmac('sha256', $signMsg, $ESEWA_SECRET_KEY, true));

$planDataJson = [];
foreach ($plans as $p) {
    $planDataJson[(int)$p['id']] = [
        'name'     => $p['name'],
        'price'    => (float)$p['price'],
        'duration' => (int)$p['duration_days'],
        'slug'     => $p['slug'],
    ];
}
$JS_planData     = json_encode($planDataJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$JS_activePlanId = json_encode((int)($selectedPlan['id'] ?? 0));
$JS_productCode  = json_encode($ESEWA_PRODUCT_CODE);
$JS_createSubUrl = json_encode($jsCreateSubUrl);
$JS_getSigUrl    = json_encode($jsGetSigUrl);

$history = fetchAll(
    "SELECT s.*, p.name AS plan_name, p.slug AS plan_slug
     FROM subscriptions s JOIN subscription_plans p ON p.id=s.plan_id
     WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 10",
    [$userId]
);

$subPct = 0;
if ($isPremium && !empty($sub['duration_days']) && (int)$sub['duration_days'] > 0) {
    $subPct = min(100, (int)round(daysRemaining($userId) / (int)$sub['duration_days'] * 100));
}

$statusMsg  = '';
$statusType = '';
if (isset($_GET['status'])) {
    match($_GET['status']) {
        'success' => [$statusMsg, $statusType] = ['Payment verified! Your premium subscription is now active.', 'success'],
        'failed'  => [$statusMsg, $statusType] = ['Payment failed or was cancelled. Please try again.', 'error'],
        'pending' => [$statusMsg, $statusType] = ["Payment is pending verification. We'll notify you once confirmed.", 'warn'],
        default   => null,
    };
}

$autoRedirect = ($_GET['status'] ?? '') === 'success'
    ? '<meta http-equiv="refresh" content="3;url=/gurkha-marga/frontend/users/questions.php">'
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscription — Gurkha Marga</title>
<?= $autoRedirect ?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:     #3b82f6;
    --gold:       #fbbf24;
    --success:    #10b981;
    --error:      #ef4444;
    --warn:       #f59e0b;
    --text-primary:   #f8fafc;
    --text-secondary: #cbd5e1;
    --text-muted:     #64748b;
    --sidebar-bg: rgba(15, 23, 42, 0.97);
    --card-bg:    rgba(30, 41, 59, 0.8);
    --hover-bg:   rgba(59, 130, 246, 0.08);
    --border:     rgba(255, 255, 255, 0.07);
    --border-hi:  rgba(255, 255, 255, 0.12);
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--text-primary);
    min-height: 100vh;
}

/* ── SIDEBAR ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: var(--sidebar-bg);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    padding: 1.75rem 0; overflow-y: auto; z-index: 1000;
    transition: transform .3s ease;
}
.sidebar-header { padding: 0 1.25rem 1.25rem; border-bottom: 1px solid var(--border); }
.logo { display: flex; align-items: center; gap: 10px; margin-bottom: 1.1rem; }
.logo img { width: 34px; height: 34px; object-fit: contain; }
.brand-name {
    font-size: 1.25rem; font-weight: 800;
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.user-card {
    background: rgba(59, 130, 246, 0.07);
    border: 1px solid rgba(59, 130, 246, 0.18);
    border-radius: 12px; padding: .85rem; display: flex; align-items: center; gap: 10px;
}
.sidebar-av {
    width: 38px; height: 38px; border-radius: 50%; overflow: hidden;
    flex-shrink: 0; border: 1.5px solid rgba(59, 130, 246, 0.35);
    display: flex; align-items: center; justify-content: center;
}
.sidebar-av img { width: 100%; height: 100%; object-fit: cover; }
.user-details h3 { font-size: .85rem; font-weight: 600; }
.user-details p  { font-size: .7rem; color: var(--gold); margin-top: 1px; }

.nav-menu { padding: 1.25rem 0; }
.nav-section-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    color: rgba(203, 213, 225, 0.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--text-secondary); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover-bg); color: var(--text-primary);
    border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239, 68, 68, 0.08); border-left-color: #ef4444; }

/* Sub widget in sidebar footer */
.sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid var(--border); margin-top: auto; }
.sub-widget { background: rgba(30,41,59,0.8); border: 1px solid var(--border); border-radius: 12px; padding: .85rem; }
.sub-widget-row { display: flex; justify-content: space-between; align-items: center; font-size: .72rem; color: var(--text-muted); margin-bottom: .4rem; }
.sub-bar { height: 4px; background: rgba(255,255,255,.08); border-radius: 2px; overflow: hidden; margin-top: .5rem; }
.sub-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--gold), #f97316); }

/* ── MAIN ── */
.main-content { margin-left: 260px; padding: 1.75rem; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    background: var(--card-bg); backdrop-filter: blur(20px);
    border-radius: 14px; padding: 1.25rem 1.75rem; margin-bottom: 1.75rem;
    display: flex; justify-content: space-between; align-items: center;
    border: 1px solid var(--border);
}
.topbar-title { font-size: 1.3rem; font-weight: 700; }
.topbar-sub   { font-size: .75rem; color: var(--text-secondary); margin-top: .2rem; }

/* ── BUTTONS ── */
.btn {
    padding: .55rem 1.1rem; border: none; border-radius: 8px;
    font-weight: 600; cursor: pointer; transition: all .2s;
    font-family: 'Poppins', sans-serif; display: inline-flex;
    align-items: center; gap: 7px; font-size: .82rem; text-decoration: none;
}
.btn-primary { background: linear-gradient(135deg, var(--accent), #8b5cf6); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59, 130, 246, 0.35); }
.btn-gold { background: linear-gradient(135deg, var(--gold), #f59e0b); color: #0a0800; }
.btn-gold:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(251,191,36,.35); }
.btn-outline {
    background: transparent; border: 1px solid rgba(255,255,255,.12);
    color: var(--text-secondary);
}
.btn-outline:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }

/* ── CARD ── */
.card {
    background: var(--card-bg); backdrop-filter: blur(12px);
    border-radius: 14px; padding: 1.35rem;
    border: 1px solid var(--border); transition: border-color .2s;
    animation: fadeIn .35s ease both;
}
.card:hover { border-color: var(--border-hi); }
.card-label {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    text-transform: uppercase; color: var(--text-muted); margin-bottom: 1rem;
    display: flex; align-items: center; gap: .5rem;
}
.card-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

/* ── STATUS BANNER ── */
.status-banner {
    padding: 1rem 1.35rem; border-radius: 12px; margin-bottom: 1.5rem;
    font-size: .88rem; font-weight: 500; display: flex; align-items: flex-start; gap: .75rem;
    animation: fadeIn .3s ease;
}
.status-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }
.status-error   { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: #fca5a5; }
.status-warn    { background: rgba(245,158,11,.1);  border: 1px solid rgba(245,158,11,.3);  color: #fcd34d; }
.redirect-bar { height: 3px; background: rgba(255,255,255,.07); border-radius: 2px; overflow: hidden; margin-top: .6rem; }
.redirect-fill { height: 100%; background: linear-gradient(90deg, var(--success), #34d399); border-radius: 2px; animation: fillBar 3s linear forwards; }
@keyframes fillBar { from { width: 0% } to { width: 100% } }

/* ── ACTIVE SUB CARD ── */
.active-sub-card {
    background: rgba(16,185,129,.07);
    border: 1px solid rgba(16,185,129,.25);
    border-radius: 14px; padding: 1.5rem;
    display: flex; align-items: center; gap: 1.25rem;
    margin-bottom: 1.5rem; animation: fadeIn .35s ease;
}
.active-sub-icon { font-size: 2.5rem; flex-shrink: 0; }
.active-sub-name { font-size: 1.1rem; font-weight: 700; color: #6ee7b7; }
.active-sub-meta { font-size: .78rem; color: var(--text-secondary); margin-top: .3rem; line-height: 1.6; }
.active-sub-actions { display: flex; gap: .5rem; margin-top: .85rem; flex-wrap: wrap; }

/* ── PLANS GRID ── */
.plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.plan-card {
    background: var(--card-bg); backdrop-filter: blur(12px);
    border: 1.5px solid var(--border); border-radius: 14px; padding: 1.5rem;
    cursor: pointer; transition: all .25s; position: relative; overflow: hidden;
    animation: fadeIn .35s ease both;
}
.plan-card:hover { border-color: rgba(251,191,36,.4); transform: translateY(-3px); box-shadow: 0 8px 32px rgba(0,0,0,.4); }
.plan-card.selected { border-color: var(--gold); background: rgba(251,191,36,.05); }
.plan-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.plan-card.monthly::before   { background: linear-gradient(90deg, var(--accent), #60a5fa); }
.plan-card.quarterly::before { background: linear-gradient(90deg, var(--gold), #f97316); }
.plan-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    color: #0a0800; font-size: .68rem; font-weight: 700;
    padding: .25rem .7rem; border-radius: 99px; margin-bottom: .75rem;
}
.plan-price { font-size: 1.9rem; font-weight: 800; color: var(--gold); margin-bottom: .2rem; letter-spacing: -.03em; }
.plan-price sup { font-size: .95rem; font-weight: 500; }
.plan-price small { font-size: .82rem; font-weight: 400; color: var(--text-muted); }
.plan-name { font-size: .97rem; font-weight: 700; margin-bottom: .3rem; }
.plan-desc { font-size: .78rem; color: var(--text-secondary); line-height: 1.5; margin-bottom: .9rem; }
.plan-features { list-style: none; }
.plan-features li {
    font-size: .78rem; color: var(--text-secondary); padding: .28rem 0;
    display: flex; align-items: flex-start; gap: 7px;
}
.plan-features li::before { content: '✓'; color: var(--success); font-weight: 700; flex-shrink: 0; }
.plan-select-btn { width: 100%; margin-top: 1.1rem; justify-content: center; }

/* ── PAYMENT AREA ── */
.payment-card {
    background: var(--card-bg); backdrop-filter: blur(12px);
    border: 1px solid var(--border); border-radius: 14px;
    padding: 1.5rem; margin-bottom: 1.25rem; animation: fadeIn .4s ease .1s both;
}
.payment-header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 1rem; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border);
}
.payment-title { font-size: .95rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; }
.payment-badge { font-size: .68rem; color: var(--text-muted); background: rgba(255,255,255,.05); border: 1px solid var(--border); border-radius: 6px; padding: .2rem .55rem; }

.pay-summary { background: rgba(15,23,42,.5); border-radius: 10px; padding: 1.1rem; margin-bottom: 1.1rem; }
.pay-row { display: flex; justify-content: space-between; align-items: center; padding: .45rem 0; font-size: .85rem; border-bottom: 1px solid rgba(255,255,255,.04); }
.pay-row:last-child { border-bottom: none; padding-top: .65rem; font-weight: 700; font-size: .95rem; }
.pay-row .lbl { color: var(--text-muted); }
.pay-row .val { font-family: 'Poppins', monospace; }
.pay-row .val.total { color: var(--gold); font-size: 1.05rem; }

.esewa-btn {
    width: 100%; padding: .9rem; border: none; border-radius: 10px;
    background: linear-gradient(135deg, #4ade80, #16a34a);
    color: #fff; font-size: .95rem; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: .65rem;
    transition: all .2s; font-family: 'Poppins', sans-serif;
}
.esewa-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(74,222,128,.3); }
.esewa-btn:disabled { opacity: .6; cursor: not-allowed; }
.esewa-logo { background: #fff; border-radius: 5px; padding: 2px 7px; font-weight: 900; font-size: .85rem; color: #16a34a; }
.payment-note { font-size: .72rem; color: var(--text-muted); text-align: center; margin-top: .75rem; }

/* ── HISTORY TABLE ── */
.hist-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.hist-table th {
    text-align: left; padding: .55rem .75rem; color: var(--text-muted);
    font-weight: 600; font-size: .68rem; text-transform: uppercase; letter-spacing: .08em;
    border-bottom: 1px solid var(--border);
}
.hist-table td { padding: .7rem .75rem; border-bottom: 1px solid rgba(255,255,255,.04); color: var(--text-secondary); }
.hist-table tr:last-child td { border-bottom: none; }
.status-chip { padding: .18rem .55rem; border-radius: 6px; font-size: .68rem; font-weight: 600; }
.s-active    { background: rgba(16,185,129,.15);  color: #6ee7b7; }
.s-expired   { background: rgba(100,116,139,.15); color: #94a3b8; }
.s-pending   { background: rgba(251,191,36,.15);  color: var(--gold); }
.s-cancelled { background: rgba(239,68,68,.15);   color: #fca5a5; }

/* ── SPINNER ── */
.spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── MOBILE ── */
.mobile-menu-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 1rem; }
    .plans-grid { grid-template-columns: 1fr; }
    .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Logo" onerror="this.style.display='none'">
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="sidebar-av" id="sbAvContainer"></div>
            <div class="user-details">
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= $isPremium ? '⭐ Premium Member' : 'Free Member' ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="workouts.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="progress.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Progress
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions & Quiz
        </a>
        <?php if ($isPremium): ?>
        <a href="chat.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            Chat with Expert
        </a>
        <?php endif; ?>
        <div class="nav-section-title" style="margin-top:.75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="subscription_fixed.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
        </a>
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="sub-widget">
            <?php if ($isPremium): ?>
                <div class="sub-widget-row"><span>Subscription</span><span style="color:var(--success)">Active ✓</span></div>
                <div style="font-size:.9rem;font-weight:700;color:var(--gold);margin-top:.25rem"><?= daysRemaining($userId) ?> days left</div>
                <div class="sub-bar"><div class="sub-fill" style="width:<?= $subPct ?>%"></div></div>
            <?php else: ?>
                <div class="sub-widget-row"><span>Subscription</span><span style="color:var(--error)">Free</span></div>
                <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem">Upgrade for full access</div>
                <a href="subscription_fixed.php" class="btn btn-gold" style="width:100%;justify-content:center;margin-top:.65rem;font-size:.78rem;padding:.45rem .75rem">⭐ Go Premium</a>
            <?php endif; ?>
        </div>
    </div>
</aside>

<!-- MAIN -->
<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <div class="topbar-title">Subscription</div>
            <div class="topbar-sub">Manage your premium access</div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center">
            <?php if ($isPremium): ?>
            <a href="questions.php" class="btn btn-primary"> Start Quiz</a>
            <?php if (function_exists('getUserConsultants')): ?>
            <a href="chat.php" class="btn btn-outline">💬 Chat</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Banner -->
    <?php if ($statusMsg): ?>
    <div class="status-banner status-<?= $statusType ?>">
        <div style="flex:1">
            <?= htmlspecialchars($statusMsg) ?>
            <?php if ($statusType === 'success'): ?>
            <div class="redirect-bar"><div class="redirect-fill"></div></div>
            <div style="font-size:.7rem;margin-top:.3rem;opacity:.7">Redirecting to Questions in 3 seconds…</div>
            <?php endif; ?>
        </div>
        <?php if ($statusType === 'success'): ?>
        <a href="questions.php" class="btn btn-gold" style="font-size:.78rem;padding:.4rem .9rem;white-space:nowrap">Go Now →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Active Subscription Card -->
    <?php if ($isPremium): ?>
    <div class="active-sub-card">
        <!-- <div class="active-sub-icon">🏆</div> -->
        <div style="flex:1">
            <div class="active-sub-name"><?= htmlspecialchars($sub['plan_name']) ?> — Active</div>
            <div class="active-sub-meta">
                Started <?= date('d M Y', strtotime($sub['starts_at'])) ?>
                &nbsp;·&nbsp; Expires <strong style="color:var(--gold)"><?= date('d M Y', strtotime($sub['expires_at'])) ?></strong>
                &nbsp;·&nbsp; <?= daysRemaining($userId) ?> days remaining
            </div>
            <div class="active-sub-actions">
                <a href="questions.php" class="btn btn-gold" style="font-size:.8rem;padding:.45rem 1rem"> Quiz Practice</a>
                <a href="chat.php" class="btn btn-outline" style="font-size:.8rem;padding:.45rem 1rem">💬 Chat with Expert</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Plan Cards -->
    <div class="card-label" style="margin-bottom:1rem">Choose Your Plan</div>
    <div class="plans-grid">
        <?php foreach ($plans as $i => $plan): ?>
        <?php $isSel = ($selectedPlan && (int)$plan['id'] === (int)$selectedPlan['id']); ?>
        <div class="plan-card <?= htmlspecialchars($plan['slug']) ?> <?= $isSel ? 'selected' : '' ?>"
             onclick="selectPlan(<?= (int)$plan['id'] ?>, '<?= htmlspecialchars($plan['slug'], ENT_QUOTES) ?>')"
             style="animation-delay:<?= $i * .06 ?>s">
            <?php if ($plan['slug'] === 'quarterly'): ?>
            <div class="plan-badge">🔥 Best Value</div>
            <?php endif; ?>
            <div class="plan-price">
                <sup>रू</sup><?= number_format((float)$plan['price'], 0) ?><small>/<?= $plan['slug'] === 'quarterly' ? '3 months' : 'month' ?></small>
            </div>
            <div class="plan-name"><?= htmlspecialchars($plan['name']) ?></div>
            <div class="plan-desc"><?= htmlspecialchars($plan['description'] ?? '') ?></div>
            <ul class="plan-features">
                <li>All premium Q&amp;A for your target force</li>
                <li>Full quiz &amp; MCQ practice with analytics</li>
                <li>Direct chat with certified consultant</li>
                <li>Chat with registered dietician</li>
                <li>Send files, images, voice messages</li>
                <li>Personalised nutrition plans</li>
                <li>Priority response within 24 hours</li>
                <?php if ($plan['slug'] === 'quarterly'): ?>
                <li>Save रू <?= number_format(499 * 3 - (float)$plan['price'], 0) ?> vs monthly</li>
                <?php endif; ?>
            </ul>
            <button class="btn <?= $isSel ? 'btn-gold' : 'btn-outline' ?> plan-select-btn">
                <?= $isSel ? '✓ Selected' : 'Select Plan' ?>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Payment -->
    <div class="payment-card">
        <div class="payment-header">
            <div class="payment-title">🔐 Pay with eSewa</div>
            <div class="payment-badge">Test mode · EPAYTEST</div>
        </div>
        <div class="pay-summary">
            <div class="pay-row">
                <span class="lbl">Plan</span>
                <span class="val" id="summaryPlan"><?= htmlspecialchars($selectedPlan['name'] ?? '—') ?></span>
            </div>
            <div class="pay-row">
                <span class="lbl">Duration</span>
                <span class="val" id="summaryDuration"><?= isset($selectedPlan['duration_days']) ? (int)$selectedPlan['duration_days'] . ' days' : '—' ?></span>
            </div>
            <div class="pay-row">
                <span class="lbl">Subtotal</span>
                <span class="val" id="summarySubtotal">रू <?= number_format((float)($selectedPlan['price'] ?? 0), 2) ?></span>
            </div>
            <div class="pay-row">
                <span class="lbl">Total</span>
                <span class="val total" id="summaryTotal">रू <?= number_format((float)($selectedPlan['price'] ?? 0), 2) ?></span>
            </div>
        </div>

        <form id="esewaForm" action="<?= htmlspecialchars($ESEWA_GATEWAY_URL) ?>" method="POST">
            <input type="hidden" name="amount"                   id="f_amount"    value="<?= $initialAmount ?>">
            <input type="hidden" name="tax_amount"                                value="0">
            <input type="hidden" name="total_amount"             id="f_total"     value="<?= $initialAmount ?>">
            <input type="hidden" name="transaction_uuid"         id="f_uuid"      value="<?= htmlspecialchars($initialUuid) ?>">
            <input type="hidden" name="product_code"                              value="<?= htmlspecialchars($ESEWA_PRODUCT_CODE) ?>">
            <input type="hidden" name="product_service_charge"                    value="0">
            <input type="hidden" name="product_delivery_charge"                   value="0">
            <input type="hidden" name="success_url"                               value="<?= htmlspecialchars($successUrl) ?>">
            <input type="hidden" name="failure_url"                               value="<?= htmlspecialchars($failureUrl) ?>">
            <input type="hidden" name="signed_field_names"                        value="total_amount,transaction_uuid,product_code">
            <input type="hidden" name="signature"                id="f_signature" value="<?= htmlspecialchars($initialSig) ?>">
            <input type="hidden" name="plan_id"                  id="f_plan_id"   value="<?= (int)($selectedPlan['id'] ?? 0) ?>">

            <button type="button" class="esewa-btn" id="payBtn" onclick="preparePayment()">
                <span class="esewa-logo">eSewa</span>
                Pay रू <span id="btnAmount"><?= number_format((float)($selectedPlan['price'] ?? 0), 0) ?></span> Securely
            </button>
        </form>
        <div class="payment-note">🔒 Secured by eSewa · Instant activation after payment · Cancel anytime</div>
    </div>

    <!-- Payment History -->
    <?php if (!empty($history)): ?>
    <div class="card-label" style="margin:1.5rem 0 1rem">Payment History</div>
    <div class="card" style="padding:0;overflow:hidden">
        <table class="hist-table">
            <thead><tr>
                <th>Plan</th><th>Amount (रू)</th><th>Status</th><th>Started</th><th>Expires</th><th>eSewa Ref</th>
            </tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td style="font-weight:500;color:var(--text-primary)"><?= htmlspecialchars($h['plan_name']) ?></td>
                    <td style="color:var(--gold);font-weight:700"><?= number_format((float)$h['amount'], 0) ?></td>
                    <td><span class="status-chip s-<?= htmlspecialchars($h['status']) ?>"><?= ucfirst($h['status']) ?></span></td>
                    <td><?= $h['starts_at']  ? date('d M Y', strtotime($h['starts_at']))  : '—' ?></td>
                    <td><?= $h['expires_at'] ? date('d M Y', strtotime($h['expires_at'])) : '—' ?></td>
                    <td style="font-size:.7rem;color:var(--text-muted)"><?= htmlspecialchars($h['esewa_ref_id'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>

<button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
const planData     = <?= $JS_planData ?>;
const PRODUCT_CODE = <?= $JS_productCode ?>;
const CREATE_URL   = <?= $JS_createSubUrl ?>;
const SIG_URL      = <?= $JS_getSigUrl ?>;
let activePlanId   = <?= $JS_activePlanId ?>;

// ── Avatar (mirrors dashboard.php) ───────────────────────────────────────
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

const AV_OPTIONS = {
    bg: [
        { id:'grad1', grad:['#1e3a5f','#2563eb'] }, { id:'grad2', grad:['#14532d','#16a34a'] },
        { id:'grad3', grad:['#7f1d1d','#dc2626'] }, { id:'grad4', grad:['#312e81','#7c3aed'] },
        { id:'grad5', grad:['#78350f','#d97706'] }, { id:'grad6', grad:['#134e4a','#0d9488'] },
        { id:'grad7', grad:['#0f172a','#1e293b'] }, { id:'grad8', grad:['#4c0519','#e11d48'] },
    ],
    skin: [
        { id:'s1', color:'#FDDBB4' }, { id:'s2', color:'#F5C89A' }, { id:'s3', color:'#D4956A' },
        { id:'s4', color:'#C47C45' }, { id:'s5', color:'#A05C2C' }, { id:'s6', color:'#7D3F17' },
        { id:'s7', color:'#5C2B0D' }, { id:'s8', color:'#3B1A08' },
    ],
    hairColor: [
        { id:'black', color:'#1a1a1a' }, { id:'brown', color:'#5C3A1E' }, { id:'auburn', color:'#922B21' },
        { id:'blonde', color:'#D4A843' }, { id:'gray', color:'#9CA3AF' }, { id:'white', color:'#F5F5F5' },
        { id:'red', color:'#B91C1C' }, { id:'blue', color:'#1D4ED8' }, { id:'purple', color:'#7C3AED' }, { id:'green', color:'#15803D' },
    ],
    eyes: [
        { id:'brown', color:'#6B3F1A' }, { id:'hazel', color:'#8B6914' }, { id:'green', color:'#15803D' },
        { id:'blue', color:'#1D4ED8' }, { id:'gray', color:'#6B7280' }, { id:'black', color:'#111827' },
        { id:'amber', color:'#D97706' }, { id:'violet', color:'#7C3AED' },
    ],
};

function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size / 2, cy = size / 2;
    const bg   = AV_OPTIONS.bg.find(o => o.id === state.bg)              || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o => o.id === state.skin)          || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o => o.id === state.hairColor)|| AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o => o.id === state.eyes)          || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const hStyle = state.hairStyle || 'short';
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0, bg.grad[0]); grad.addColorStop(1, bg.grad[1]);
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(cx,cy,cx,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]); ctx.fill();
    const shirt = ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle = shirt;
    ctx.beginPath(); ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle = hCol.color==='#F5F5F5'?'#C0A080':hCol.color;
    ctx.lineWidth=3.5; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-28,cy-16); ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx+7,cy-16);  ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16); ctx.stroke();
    ctx.fillStyle='#fff';
    ctx.beginPath(); ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=eyeC.color;
    ctx.beginPath(); ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='#000';
    ctx.beginPath(); ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.6)';
    ctx.beginPath(); ctx.arc(cx-13,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+19,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle='rgba(0,0,0,0.18)'; ctx.lineWidth=2; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-3,cy+2); ctx.lineTo(cx,cy+12); ctx.lineTo(cx+3,cy+2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx-9,cy+14); ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14); ctx.stroke();
    ctx.strokeStyle='rgba(0,0,0,0.3)'; ctx.lineWidth=2.5;
    ctx.beginPath(); ctx.moveTo(cx-14,cy+24); ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24); ctx.stroke();
    ctx.fillStyle=hCol.color;
    if(hStyle!=='bald'){
        ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-40,88,20);
        ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
    }
    if(acc.includes('beard')){
        ctx.fillStyle=hCol.color;
        ctx.beginPath(); ctx.ellipse(cx,cy+36,30,18,0,0,Math.PI); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-30,cy+18,60,20,4); ctx.fill();
    }
    if(acc.includes('glasses')){
        ctx.strokeStyle='#64748b'; ctx.lineWidth=2.5; ctx.fillStyle='rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx-32,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-5); ctx.lineTo(cx+8,cy-5); ctx.stroke();
    }
}

function renderSidebarAvatar(){
    const container=document.getElementById('sbAvContainer');
    if(!container) return;
    container.innerHTML='';
    if(SAVED_AVATAR_TYPE==='photo'&&SAVED_PHOTO_URL){
        const img=document.createElement('img');
        img.src=SAVED_PHOTO_URL;
        img.style.cssText='width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror=()=>renderInitialSb(container);
        container.appendChild(img);
    } else if(SAVED_AVATAR_TYPE==='ai'&&SAVED_AVATAR_CONFIG){
        const canvas=document.createElement('canvas');
        canvas.style.cssText='width:38px;height:38px;border-radius:50%;display:block';
        container.appendChild(canvas);
        drawAvatarOnCanvas(canvas,SAVED_AVATAR_CONFIG,38);
    } else {
        renderInitialSb(container);
    }
}

function renderInitialSb(container){
    const d=document.createElement('div');
    d.style.cssText='width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent=USER_INITIAL;
    container.appendChild(d);
}

// ── Plan selection ────────────────────────────────────────────────────────
function selectPlan(id, slug) {
    activePlanId = id;
    document.querySelectorAll('.plan-card').forEach(function(c) {
        c.classList.remove('selected');
        const b = c.querySelector('.plan-select-btn');
        if (b) { b.textContent = 'Select Plan'; b.className = 'btn btn-outline plan-select-btn'; }
    });
    const chosen = document.querySelector('.plan-card.' + slug);
    if (chosen) {
        chosen.classList.add('selected');
        const b = chosen.querySelector('.plan-select-btn');
        if (b) { b.textContent = '✓ Selected'; b.className = 'btn btn-gold plan-select-btn'; }
    }
    const p = planData[id];
    if (!p) return;
    document.getElementById('summaryPlan').textContent     = p.name;
    document.getElementById('summaryDuration').textContent = p.duration + ' days';
    document.getElementById('summarySubtotal').textContent = 'रू ' + p.price.toFixed(2);
    document.getElementById('summaryTotal').textContent    = 'रू ' + p.price.toFixed(2);
    document.getElementById('btnAmount').textContent       = Math.round(p.price).toLocaleString();
    document.getElementById('f_amount').value  = p.price;
    document.getElementById('f_total').value   = p.price;
    document.getElementById('f_plan_id').value = id;
}

function generateUUID() {
    return 'gm-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
}

function resetPayBtn() {
    const btn = document.getElementById('payBtn');
    if (!btn) return;
    btn.disabled = false;
    btn.innerHTML = '<span class="esewa-logo">eSewa</span> Pay रू <span id="btnAmount">' + Math.round(planData[activePlanId].price).toLocaleString() + '</span> Securely';
}

async function preparePayment() {
    if (!activePlanId) { alert('Please select a plan first.'); return; }
    const payBtn = document.getElementById('payBtn');
    payBtn.disabled = true;
    payBtn.innerHTML = '<div class="spinner"></div> Processing…';

    try {
        const p    = planData[activePlanId];
        const uuid = generateUUID();

        const subRes  = await fetch(CREATE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    'plan_id=' + encodeURIComponent(activePlanId) + '&uuid=' + encodeURIComponent(uuid)
        });
        const rawSub  = await subRes.text();
        let subData;
        try { subData = JSON.parse(rawSub); }
        catch(e) { throw new Error('create_subscription.php returned non-JSON:\n' + rawSub.substring(0, 300)); }
        if (subData.error) throw new Error('Subscription error: ' + subData.error);

        document.cookie = 'pending_sub_id=' + subData.subscription_id + ';path=/;max-age=3600;SameSite=Lax';
        document.cookie = 'pending_uuid='   + uuid + ';path=/;max-age=3600;SameSite=Lax';

        const sigRes = await fetch(SIG_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    'amount='        + encodeURIComponent(p.price)
                   + '&uuid='         + encodeURIComponent(uuid)
                   + '&product_code=' + encodeURIComponent(PRODUCT_CODE)
        });
        const rawSig = await sigRes.text();
        let sigData;
        try { sigData = JSON.parse(rawSig); }
        catch(e) { throw new Error('get_signature.php returned non-JSON:\n' + rawSig.substring(0, 300)); }
        if (sigData.error) throw new Error('Signature error: ' + sigData.error);

        document.getElementById('f_uuid').value      = uuid;
        document.getElementById('f_amount').value    = p.price;
        document.getElementById('f_total').value     = p.price;
        document.getElementById('f_signature').value = sigData.signature;
        document.getElementById('f_plan_id').value   = activePlanId;
        document.getElementById('esewaForm').submit();

    } catch(err) {
        console.error('[eSewa] Error:', err);
        alert('Payment setup failed:\n' + err.message);
        resetPayBtn();
    }
}

document.addEventListener('DOMContentLoaded', renderSidebarAvatar);
</script>
</body>
</html>