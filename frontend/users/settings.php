<?php
// frontend/users/settings.php
session_start();

define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$userId  = (int)$_SESSION['user_id'];
$success = $error = '';

// ── Force map ─────────────────────────────────────────────────────────────────
$forceMap = [
    'british'   => ['🇬🇧', 'British Army'],
    'nepal'     => ['🇳🇵', 'Nepal Army'],
    'indian'    => ['🇮🇳', 'Indian Army'],
    'singapore' => ['🇸🇬', 'Singapore Police Force'],
    'french'    => ['🇫🇷', 'French Foreign Legion'],
];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Deactivate account (soft delete)
    if (($_POST['action'] ?? '') === 'deactivate_account') {
        $pw = $_POST['deactivate_password'] ?? '';
        $u  = fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!password_verify($pw, $u['password'])) {
            $error = 'Incorrect password. Account not deactivated.';
        } else {
            try {
                query("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?", [$userId]);
                session_destroy();
                header('Location: ../auth/login.php');
                exit();
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'Failed to deactivate account. Try again.';
            }
        }
    }
}

// ── Fetch user ────────────────────────────────────────────────────────────────
$user = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

// ── Derived values ────────────────────────────────────────────────────────────
$forceKey    = $user['target_force'] ?? '';
$forceFlag   = $forceMap[$forceKey][0] ?? '🎖️';
$forceName   = $forceMap[$forceKey][1] ?? ucfirst($forceKey);
$memberSince = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A';
$lastLogin   = !empty($user['last_login'])  ? date('M j, Y · g:i A', strtotime($user['last_login'])) : 'N/A';
$expLabel    = ucfirst($user['experience_level'] ?? 'beginner');

// ── BMI ───────────────────────────────────────────────────────────────────────
$bmi = $bmiCategory = $bmiClass = null;
if (!empty($user['height']) && !empty($user['weight'])) {
    $h   = $user['height'] / 100;
    $bmi = round($user['weight'] / ($h * $h), 1);
    [$bmiCategory, $bmiClass] = match(true) {
        $bmi < 18.5 => ['Underweight', 'underweight'],
        $bmi < 25   => ['Healthy',     'normal'],
        $bmi < 30   => ['Overweight',  'overweight'],
        default     => ['Obese',       'obese'],
    };
}

// ── Login history for activity ────────────────────────────────────────────────
$loginHistory = fetchAll(
    "SELECT * FROM login_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
    [$userId]
);
$totalLogins   = count(array_filter($loginHistory, fn($l) => $l['status'] === 'success'));
$failedLogins  = count(array_filter($loginHistory, fn($l) => $l['status'] !== 'success'));

// ── Progress stats (computed from user data) ───────────────────────────────────
$expProgress = match($user['experience_level'] ?? '') {
    'beginner'     => 25,
    'intermediate' => 50,
    'advanced'     => 75,
    'expert'       => 100,
    default        => 0,
};
$daysSinceMember = !empty($user['created_at'])
    ? (int)((time() - strtotime($user['created_at'])) / 86400)
    : 0;

// ── Determine active tab from POST ───────────────────────────────────────────
$activeTab = 'account';
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'deactivate_account') $activeTab = 'account';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings – Gurkha Marga</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --accent:         #3b82f6;
            --gold:           #fbbf24;
            --success:        #10b981;
            --error:          #ef4444;
            --warning:        #f59e0b;
            --danger:         #dc2626;
            --purple:         #8b5cf6;
            --text-primary:   #f8fafc;
            --text-secondary: #cbd5e1;
            --sidebar-bg:     rgba(15,23,42,.97);
            --card-bg:        rgba(30,41,59,.8);
            --hover-bg:       rgba(59,130,246,.1);
            --border:         rgba(255,255,255,.08);
            --input-bg:       rgba(15,23,42,.6);
        }

        body {
            font-family:'Poppins',sans-serif;
            background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%);
            color:var(--text-primary); min-height:100vh;
        }

        /* ── Sidebar ── */
        .sidebar {
            position:fixed; left:0; top:0; width:280px; height:100vh;
            background:var(--sidebar-bg); backdrop-filter:blur(20px);
            border-right:1px solid var(--border);
            padding:2rem 0; overflow-y:auto; z-index:1000;
            transition:transform .3s ease;
        }
        .sidebar-header { padding:0 1.5rem 1.5rem; border-bottom:1px solid var(--border); }
        .logo { display:flex; align-items:center; gap:10px; margin-bottom:1.25rem; }
        .brand-name {
            font-size:1.4rem; font-weight:800;
            background:linear-gradient(135deg,var(--gold),#f59e0b);
            background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .user-card {
            background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2);
            border-radius:14px; padding:1rem; display:flex; align-items:center; gap:12px;
        }
        .avatar {
            width:46px; height:46px; border-radius:50%;
            background:linear-gradient(135deg,var(--accent),var(--purple));
            display:flex; align-items:center; justify-content:center;
            font-size:1.2rem; font-weight:700; flex-shrink:0;
            border:2px solid rgba(59,130,246,.4);
        }
        .user-details h3 { font-size:.95rem; font-weight:600; }
        .user-details p  { font-size:.75rem; color:var(--gold); margin-top:2px; }
        .nav-menu { padding:1.5rem 0; }
        .nav-section-title {
            font-size:.7rem; font-weight:600; letter-spacing:.1em;
            color:rgba(203,213,225,.4); text-transform:uppercase;
            padding:.5rem 1.5rem; margin-bottom:.25rem;
        }
        .nav-item {
            padding:.7rem 1.5rem; display:flex; align-items:center; gap:12px;
            color:var(--text-secondary); text-decoration:none;
            transition:all .25s; border-left:3px solid transparent; font-size:.9rem;
        }
        .nav-item:hover, .nav-item.active {
            background:var(--hover-bg); color:var(--text-primary);
            border-left-color:var(--accent);
        }
        .nav-item svg { width:18px; height:18px; flex-shrink:0; }
        .nav-item.logout { color:#f87171; margin-top:.5rem; }
        .nav-item.logout:hover { background:rgba(239,68,68,.1); border-left-color:#ef4444; }

        /* ── Layout ── */
        .main-content { margin-left:280px; padding:2rem; min-height:100vh; }

        .topbar {
            background:var(--card-bg); border-radius:16px; padding:1.5rem 2rem;
            margin-bottom:2rem; display:flex; justify-content:space-between; align-items:center;
            border:1px solid var(--border);
        }
        .topbar h1 { font-size:1.6rem; font-weight:700; }
        .topbar p  { color:var(--text-secondary); font-size:.9rem; margin-top:4px; }
        .breadcrumb { display:flex; align-items:center; gap:8px; font-size:.85rem; color:var(--text-secondary); margin-bottom:.25rem; }
        .breadcrumb a { color:var(--accent); text-decoration:none; }

        /* ── Alerts ── */
        .alert {
            padding:1rem 1.25rem; border-radius:12px; margin-bottom:1.5rem;
            display:flex; align-items:center; gap:10px; font-size:.9rem; font-weight:500;
            animation:slideIn .3s ease;
        }
        @keyframes slideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.3); color:#6ee7b7; }
        .alert-error   { background:rgba(239,68,68,.12);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }

        /* ── Settings layout ── */
        .settings-layout { display:grid; grid-template-columns:220px 1fr; gap:2rem; }

        /* ── Settings sidebar nav ── */
        .settings-nav { position:sticky; top:2rem; }
        .settings-nav-item {
            display:flex; align-items:center; gap:10px; padding:.75rem 1rem;
            border-radius:10px; cursor:pointer; transition:all .25s;
            font-size:.88rem; color:var(--text-secondary); font-weight:500;
            border:none; background:none; width:100%; text-align:left; font-family:'Poppins',sans-serif;
            margin-bottom:.25rem;
        }
        .settings-nav-item:hover { background:var(--hover-bg); color:var(--text-primary); }
        .settings-nav-item.active {
            background:rgba(59,130,246,.15); color:var(--text-primary);
            border:1px solid rgba(59,130,246,.25);
        }
        .settings-nav-item svg { width:16px; height:16px; flex-shrink:0; }
        .settings-nav-divider { height:1px; background:var(--border); margin:.75rem 0; }

        /* ── Panels ── */
        .settings-panel { display:none; animation:fadeIn .3s ease; }
        .settings-panel.active { display:block; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

        /* ── Cards ── */
        .card {
            background:var(--card-bg); border-radius:16px; padding:1.75rem;
            border:1px solid var(--border); margin-bottom:1.5rem;
        }
        .card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:1px solid rgba(255,255,255,.06); }
        .card-title    { font-size:1rem; font-weight:600; display:flex; align-items:center; gap:8px; }
        .card-subtitle { font-size:.82rem; color:var(--text-secondary); margin-top:3px; }
        .card.danger   { border-color:rgba(220,38,38,.25); background:rgba(220,38,38,.04); }
        .card.success-card { border-color:rgba(16,185,129,.25); background:rgba(16,185,129,.04); }

        /* ── Info rows ── */
        .info-row {
            display:flex; justify-content:space-between; align-items:center;
            padding:.85rem 0; border-bottom:1px solid rgba(255,255,255,.05); font-size:.9rem;
        }
        .info-row:last-child { border-bottom:none; padding-bottom:0; }
        .info-label { color:var(--text-secondary); font-size:.85rem; display:flex; align-items:center; gap:6px; }
        .info-value { font-weight:500; text-align:right; }

        /* ── Status badges ── */
        .badge {
            padding:.3rem .8rem; border-radius:20px; font-size:.75rem; font-weight:600;
            display:inline-flex; align-items:center; gap:5px;
        }
        .badge.active    { background:rgba(16,185,129,.2);  color:#6ee7b7; }
        .badge.warning   { background:rgba(251,191,36,.2);  color:#fcd34d; }
        .badge.danger    { background:rgba(220,38,38,.2);   color:#fca5a5; }
        .badge.blue      { background:rgba(59,130,246,.2);  color:#93c5fd; }
        .badge.purple    { background:rgba(139,92,246,.2);  color:#c4b5fd; }

        /* ── Toggle switch ── */
        .toggle-row {
            display:flex; justify-content:space-between; align-items:center;
            padding:.9rem 0; border-bottom:1px solid rgba(255,255,255,.05);
        }
        .toggle-row:last-child { border-bottom:none; padding-bottom:0; }
        .toggle-info h4  { font-size:.9rem; font-weight:500; }
        .toggle-info p   { font-size:.78rem; color:var(--text-secondary); margin-top:2px; }
        .toggle { position:relative; width:48px; height:26px; flex-shrink:0; }
        .toggle input { opacity:0; width:0; height:0; }
        .toggle-slider {
            position:absolute; inset:0; background:rgba(255,255,255,.15);
            border-radius:13px; cursor:pointer; transition:.3s;
        }
        .toggle-slider::before {
            content:''; position:absolute; width:20px; height:20px;
            left:3px; top:3px; background:#fff; border-radius:50%; transition:.3s;
        }
        .toggle input:checked + .toggle-slider { background:var(--accent); }
        .toggle input:checked + .toggle-slider::before { transform:translateX(22px); }

        /* ── Progress bars ── */
        .prog-bar  { height:8px; background:rgba(255,255,255,.08); border-radius:4px; overflow:hidden; margin-top:.5rem; }
        .prog-fill { height:100%; border-radius:4px; transition:width .8s ease; }
        .prog-blue  { background:linear-gradient(90deg,var(--accent),#60a5fa); }
        .prog-gold  { background:linear-gradient(90deg,var(--gold),#fcd34d); }
        .prog-green { background:linear-gradient(90deg,var(--success),#34d399); }
        .prog-purple { background:linear-gradient(90deg,var(--purple),#a78bfa); }

        /* ── Stat cards ── */
        .mini-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
        .mini-stat {
            background:var(--card-bg); border:1px solid var(--border); border-radius:14px;
            padding:1.25rem; text-align:center; position:relative; overflow:hidden;
        }
        .mini-stat::before {
            content:''; position:absolute; top:0; left:0; right:0; height:3px;
        }
        .mini-stat.blue::before   { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .mini-stat.gold::before   { background:linear-gradient(90deg,#fbbf24,#fcd34d); }
        .mini-stat.green::before  { background:linear-gradient(90deg,#10b981,#34d399); }
        .mini-stat.purple::before { background:linear-gradient(90deg,#8b5cf6,#a78bfa); }
        .mini-stat-val { font-size:1.8rem; font-weight:700; }
        .mini-stat-lbl { font-size:.75rem; color:var(--text-secondary); margin-top:4px; }
        .mini-stat-icon { font-size:1.4rem; margin-bottom:.25rem; }

        /* ── Activity feed ── */
        .activity-item {
            display:flex; align-items:center; gap:12px; padding:.75rem 0;
            border-bottom:1px solid rgba(255,255,255,.05); font-size:.85rem;
        }
        .activity-item:last-child { border-bottom:none; }
        .activity-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .activity-dot.success { background:var(--success); }
        .activity-dot.failed  { background:var(--error); }
        .activity-body { flex:1; }
        .activity-title { color:var(--text-primary); font-weight:500; }
        .activity-time  { font-size:.75rem; color:var(--text-secondary); margin-top:1px; }
        .activity-badge { padding:.2rem .6rem; border-radius:6px; font-size:.73rem; font-weight:500; }
        .activity-badge.success { background:rgba(16,185,129,.15); color:#6ee7b7; }
        .activity-badge.failed  { background:rgba(239,68,68,.15);  color:#fca5a5; }

        /* ── BMI block ── */
        .bmi-badge-inline {
            padding:.3rem .9rem; border-radius:20px; font-size:.82rem; font-weight:600; display:inline-block;
        }
        .bmi-badge-inline.normal      { background:rgba(16,185,129,.2); color:#6ee7b7; }
        .bmi-badge-inline.underweight { background:rgba(59,130,246,.2);  color:#93c5fd; }
        .bmi-badge-inline.overweight  { background:rgba(251,191,36,.2);  color:#fcd34d; }
        .bmi-badge-inline.obese       { background:rgba(239,68,68,.2);   color:#fca5a5; }

        /* ── Redirect link card ── */
        .link-card {
            display:flex; align-items:center; justify-content:space-between;
            padding:1rem 1.25rem; background:rgba(15,23,42,.5); border:1px solid var(--border);
            border-radius:12px; text-decoration:none; transition:all .25s; margin-bottom:.75rem;
            color:var(--text-primary);
        }
        .link-card:hover { background:rgba(59,130,246,.1); border-color:rgba(59,130,246,.3); transform:translateX(4px); }
        .link-card-left { display:flex; align-items:center; gap:12px; }
        .link-card-icon { font-size:1.4rem; width:40px; height:40px; border-radius:10px; background:rgba(255,255,255,.06); display:flex; align-items:center; justify-content:center; }
        .link-card-title { font-size:.9rem; font-weight:500; }
        .link-card-desc  { font-size:.77rem; color:var(--text-secondary); margin-top:2px; }
        .link-arrow { color:var(--text-secondary); font-size:1.1rem; }

        /* ── Buttons ── */
        .btn {
            padding:.75rem 1.5rem; border:none; border-radius:10px; font-weight:600;
            cursor:pointer; transition:all .25s; font-family:'Poppins',sans-serif;
            display:inline-flex; align-items:center; gap:8px; font-size:.9rem; text-decoration:none;
        }
        .btn-primary { background:linear-gradient(135deg,var(--accent),var(--purple)); color:#fff; }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 4px 15px rgba(59,130,246,.4); }
        .btn-danger  { background:linear-gradient(135deg,var(--danger),#991b1b); color:#fff; }
        .btn-danger:hover  { transform:translateY(-2px); box-shadow:0 4px 15px rgba(220,38,38,.4); }
        .btn-warning { background:linear-gradient(135deg,var(--warning),#d97706); color:#fff; }
        .btn-warning:hover { transform:translateY(-2px); }
        .btn-outline {
            background:transparent; border:1.5px solid rgba(255,255,255,.15); color:var(--text-secondary);
        }
        .btn-outline:hover { background:rgba(255,255,255,.08); color:var(--text-primary); }
        .btn-sm { padding:.5rem 1rem; font-size:.82rem; }
        .btn-full { width:100%; justify-content:center; }

        /* ── Form ── */
        .form-group { display:flex; flex-direction:column; gap:.5rem; margin-bottom:1.25rem; }
        .form-label { font-size:.85rem; font-weight:500; color:var(--text-secondary); }
        .form-input {
            padding:12px 16px; border-radius:10px; background:var(--input-bg);
            border:1.5px solid rgba(255,255,255,.1); color:var(--text-primary);
            font-size:.95rem; font-family:'Poppins',sans-serif; width:100%;
            transition:border-color .25s, box-shadow .25s;
        }
        .form-input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
        .form-input::placeholder { color:rgba(203,213,225,.4); }

        /* ── Modal ── */
        .modal-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,.7); backdrop-filter:blur(4px);
            z-index:9999; display:none; align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal {
            background:#1e293b; border:1px solid rgba(220,38,38,.3); border-radius:20px;
            padding:2rem; max-width:420px; width:90%; animation:fadeIn .3s ease;
        }
        .modal h3 { font-size:1.15rem; color:#fca5a5; margin-bottom:.75rem; }
        .modal p  { color:var(--text-secondary); font-size:.88rem; margin-bottom:1.25rem; line-height:1.7; }
        .modal-actions { display:flex; gap:1rem; }

        /* ── Privacy items ── */
        .privacy-item {
            display:flex; align-items:flex-start; gap:14px; padding:1.1rem 0;
            border-bottom:1px solid rgba(255,255,255,.05);
        }
        .privacy-item:last-child { border-bottom:none; padding-bottom:0; }
        .privacy-icon { font-size:1.4rem; margin-top:2px; flex-shrink:0; }
        .privacy-title { font-size:.9rem; font-weight:500; margin-bottom:3px; }
        .privacy-desc  { font-size:.78rem; color:var(--text-secondary); line-height:1.5; }

        /* ── Appearance themes ── */
        .theme-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-top:1rem; }
        .theme-opt  { border-radius:12px; overflow:hidden; border:2px solid rgba(255,255,255,.1); cursor:pointer; transition:border-color .25s; }
        .theme-opt:hover { border-color:rgba(59,130,246,.5); }
        .theme-opt.selected { border-color:var(--accent); }
        .theme-preview { height:60px; display:flex; }
        .theme-preview-bar { width:30%; background:rgba(15,23,42,.9); }
        .theme-preview-main { flex:1; }
        .theme-name { font-size:.78rem; text-align:center; padding:.5rem; background:rgba(15,23,42,.5); }

        /* ── Mobile ── */
        .mobile-menu-btn {
            display:none; position:fixed; bottom:1.5rem; right:1.5rem;
            width:56px; height:56px; border-radius:50%;
            background:linear-gradient(135deg,var(--accent),var(--purple));
            border:none; color:#fff; font-size:1.4rem; cursor:pointer;
            box-shadow:0 4px 15px rgba(59,130,246,.4); z-index:999;
        }

        @media (max-width:1100px) { .settings-layout { grid-template-columns:1fr; } .settings-nav { position:static; display:flex; flex-wrap:wrap; gap:.5rem; } .settings-nav-item { flex:1; min-width:120px; justify-content:center; } .settings-nav-divider { display:none; } }
        @media (max-width:768px)  { .sidebar { transform:translateX(-100%); } .sidebar.active { transform:translateX(0); } .main-content { margin-left:0; } .mobile-menu-btn { display:flex; align-items:center; justify-content:center; } .mini-stats { grid-template-columns:1fr 1fr; } .modal-actions { flex-direction:column; } }
        @media (max-width:480px)  { .main-content { padding:1rem; } .mini-stats { grid-template-columns:1fr; } .theme-grid { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Gurkha Marga Logo"
                 style="width:40px;height:40px;object-fit:contain;"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <svg style="display:none" width="36" height="36" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="18" fill="#2c5f2d" stroke="#fbbf24" stroke-width="2"/>
                <path d="M20 8L24 16H16L20 8Z" fill="#fbbf24"/>
                <rect x="18" y="16" width="4" height="12" fill="#fbbf24"/>
                <path d="M12 28L20 24L28 28" stroke="#fbbf24" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
            <div class="user-details">
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= $expLabel ?> · <?= htmlspecialchars($forceName) ?></p>
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
        <a href="nutrition.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Nutrition
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
        </a>
        <a href="documents.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Documents
        </a>
        <a href="motivation.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            Motivation
        </a>
        <div class="nav-section-title" style="margin-top:1rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="settings.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- ── Main ── -->
<main class="main-content">

    <div class="topbar">
        <div>
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a> <span>/</span> <span>Settings</span>
            </div>
            <h1>Settings</h1>
            <p>Manage your account, privacy and preferences</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline">← Dashboard</a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="settings-layout">

        <!-- ── Settings Nav ── -->
        <div class="settings-nav">
            <button class="settings-nav-item active" onclick="showPanel('account', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Account Status
            </button>
            <button class="settings-nav-item" onclick="showPanel('progress', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Progress
            </button>
            <button class="settings-nav-item" onclick="showPanel('privacy', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Privacy Center
            </button>
            <button class="settings-nav-item" onclick="showPanel('notifications', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                Notifications
            </button>
            <button class="settings-nav-item" onclick="showPanel('appearance', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                Appearance
            </button>
            <div class="settings-nav-divider"></div>
            <button class="settings-nav-item" onclick="showPanel('activity', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Log
            </button>
            <button class="settings-nav-item" onclick="showPanel('help', this)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Help & Support
            </button>
        </div>

        <!-- ── Panels ── -->
        <div>

            <!-- ══ ACCOUNT STATUS ══ -->
            <div id="panel-account" class="settings-panel active">

                <!-- Status overview -->
                <div class="card success-card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">👤 Account Status</div>
                            <div class="card-subtitle">Your current account standing and details</div>
                        </div>
                        <span class="badge active">● Active</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Full Name</span>
                        <span class="info-value"><?= htmlspecialchars($user['full_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value" style="font-size:.85rem"><?= htmlspecialchars($user['email']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Account Type</span>
                        <span class="info-value"><span class="badge blue">Standard Member</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?= $memberSince ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Days Active</span>
                        <span class="info-value"><?= $daysSinceMember ?> days</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Login</span>
                        <span class="info-value" style="font-size:.83rem"><?= $lastLogin ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Target Force</span>
                        <span class="info-value"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Experience Level</span>
                        <span class="info-value"><span class="badge purple">⚔️ <?= $expLabel ?></span></span>
                    </div>
                </div>

                <!-- Quick links to manage -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">⚙️ Manage Account</div>
                            <div class="card-subtitle">Quick actions for your account</div>
                        </div>
                    </div>
                    <a href="profile.php" class="link-card">
                        <div class="link-card-left">
                            <div class="link-card-icon">✏️</div>
                            <div>
                                <div class="link-card-title">Edit Profile</div>
                                <div class="link-card-desc">Update name, age, gender, height, weight and target force</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                    <a href="profile.php#security" class="link-card" onclick="localStorage.setItem('profileTab','security')">
                        <div class="link-card-left">
                            <div class="link-card-icon">🔑</div>
                            <div>
                                <div class="link-card-title">Change Password</div>
                                <div class="link-card-desc">Update your account password and security settings</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                    <a href="profile.php" class="link-card">
                        <div class="link-card-left">
                            <div class="link-card-icon">🎖️</div>
                            <div>
                                <div class="link-card-title">Change Target Force</div>
                                <div class="link-card-desc">Switch your military recruitment target</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                </div>

                <!-- Deactivate -->
                <div class="card danger">
                    <div class="card-header">
                        <div>
                            <div class="card-title" style="color:#fca5a5">⚠️ Deactivate Account</div>
                            <div class="card-subtitle">Temporarily disable your account — you can reactivate by contacting support</div>
                        </div>
                    </div>
                    <p style="color:var(--text-secondary);font-size:.88rem;line-height:1.7;margin-bottom:1.25rem">
                        Deactivating your account will hide your profile and prevent login. Your data is preserved.
                        This is different from permanent deletion — you can request reactivation later.
                    </p>
                    <button class="btn btn-warning" onclick="document.getElementById('deactivateModal').classList.add('open')">
                        ⏸ Deactivate Account
                    </button>
                </div>
            </div>

            <!-- ══ PROGRESS DASHBOARD ══ -->
            <div id="panel-progress" class="settings-panel">

                <div class="mini-stats">
                    <div class="mini-stat blue">
                        <div class="mini-stat-icon">📅</div>
                        <div class="mini-stat-val"><?= $daysSinceMember ?></div>
                        <div class="mini-stat-lbl">Days as Member</div>
                    </div>
                    <div class="mini-stat gold">
                        <div class="mini-stat-icon">✅</div>
                        <div class="mini-stat-val"><?= $totalLogins ?></div>
                        <div class="mini-stat-lbl">Successful Logins</div>
                    </div>
                    <div class="mini-stat green">
                        <div class="mini-stat-icon">⚖️</div>
                        <div class="mini-stat-val"><?= $bmi ?? '—' ?></div>
                        <div class="mini-stat-lbl">Current BMI</div>
                    </div>
                    <div class="mini-stat purple">
                        <div class="mini-stat-icon">⚔️</div>
                        <div class="mini-stat-val"><?= $expProgress ?>%</div>
                        <div class="mini-stat-lbl">Experience Progress</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">📊 Physical Progress Snapshot</div>
                            <div class="card-subtitle">Based on your registered data</div>
                        </div>
                        <a href="dashboard.php" class="btn btn-outline btn-sm">Full Dashboard →</a>
                    </div>

                    <div style="margin-bottom:1.1rem">
                        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.35rem">
                            <span style="color:var(--text-secondary)">Height</span>
                            <span style="font-weight:600"><?= $user['height'] ?> cm</span>
                        </div>
                        <div class="prog-bar">
                            <?php $hPct = min(100, max(0, (($user['height'] - 100) / 150) * 100)); ?>
                            <div class="prog-fill prog-blue" style="width:<?= $hPct ?>%"></div>
                        </div>
                    </div>

                    <div style="margin-bottom:1.1rem">
                        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.35rem">
                            <span style="color:var(--text-secondary)">Weight</span>
                            <span style="font-weight:600"><?= $user['weight'] ?> kg</span>
                        </div>
                        <div class="prog-bar">
                            <?php $wPct = min(100, max(0, (($user['weight'] - 30) / 270) * 100)); ?>
                            <div class="prog-fill prog-green" style="width:<?= $wPct ?>%"></div>
                        </div>
                    </div>

                    <?php if ($bmi): ?>
                    <div style="margin-bottom:1.1rem">
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.85rem;margin-bottom:.35rem">
                            <span style="color:var(--text-secondary)">BMI</span>
                            <span style="font-weight:600"><?= $bmi ?> &nbsp;<span class="bmi-badge-inline <?= $bmiClass ?>"><?= $bmiCategory ?></span></span>
                        </div>
                        <div class="prog-bar">
                            <?php $markerPct = min(100, max(0, (($bmi - 10) / 30) * 100)); ?>
                            <div class="prog-fill prog-gold" style="width:<?= $markerPct ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.35rem">
                            <span style="color:var(--text-secondary)">Experience Level — <?= $expLabel ?></span>
                            <span style="font-weight:600"><?= $expProgress ?>%</span>
                        </div>
                        <div class="prog-bar">
                            <div class="prog-fill prog-purple" style="width:<?= $expProgress ?>%"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.7rem;color:rgba(203,213,225,.35);margin-top:.3rem">
                            <span>Beginner</span><span>Intermediate</span><span>Advanced</span><span>Expert</span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">🎖️ Training Target</div>
                            <div class="card-subtitle">Your current military recruitment goal</div>
                        </div>
                        <a href="profile.php" class="btn btn-outline btn-sm">Change →</a>
                    </div>
                    <div style="display:flex;align-items:center;gap:1.5rem;padding:1rem 0">
                        <div style="font-size:3.5rem"><?= $forceFlag ?></div>
                        <div>
                            <div style="font-size:1.2rem;font-weight:700"><?= htmlspecialchars($forceName) ?></div>
                            <div style="font-size:.82rem;color:var(--text-secondary);margin-top:4px">Target Military Force</div>
                            <div style="margin-top:.75rem">
                                <span class="badge purple">⚔️ <?= $expLabel ?></span>
                                <span class="badge blue" style="margin-left:.5rem">📅 <?= $daysSinceMember ?> days training</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ PRIVACY CENTER ══ -->
            <div id="panel-privacy" class="settings-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">🛡️ Privacy Center</div>
                            <div class="card-subtitle">How we use and protect your data</div>
                        </div>
                        <span class="badge active">✓ Protected</span>
                    </div>

                    <div class="privacy-item">
                        <div class="privacy-icon">🔒</div>
                        <div>
                            <div class="privacy-title">Password Encryption</div>
                            <div class="privacy-desc">Your password is hashed using bcrypt — we never store plain-text passwords and cannot view them.</div>
                        </div>
                    </div>
                    <div class="privacy-item">
                        <div class="privacy-icon">📋</div>
                        <div>
                            <div class="privacy-title">Data We Collect</div>
                            <div class="privacy-desc">We store your name, email, age, gender, height, weight, target force, experience level and login activity. No payment data is collected.</div>
                        </div>
                    </div>
                    <div class="privacy-item">
                        <div class="privacy-icon">🚫</div>
                        <div>
                            <div class="privacy-title">No Data Sharing</div>
                            <div class="privacy-desc">Your personal information is never sold or shared with third parties. All data stays within Gurkha Marga systems.</div>
                        </div>
                    </div>
                    <div class="privacy-item">
                        <div class="privacy-icon">🍪</div>
                        <div>
                            <div class="privacy-title">Session Cookies</div>
                            <div class="privacy-desc">We use PHP sessions to keep you logged in. Sessions expire after inactivity. "Remember Me" extends this to 30 days.</div>
                        </div>
                    </div>
                    <div class="privacy-item">
                        <div class="privacy-icon">📊</div>
                        <div>
                            <div class="privacy-title">Login Tracking</div>
                            <div class="privacy-desc">We log your IP address and login time for security purposes. You can view this in Activity Log. Logs are limited to the last 10 sessions.</div>
                        </div>
                    </div>
                    <div class="privacy-item">
                        <div class="privacy-icon">🗑️</div>
                        <div>
                            <div class="privacy-title">Right to Deletion</div>
                            <div class="privacy-desc">You can permanently delete your account and all associated data at any time from Profile → Danger Zone.</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">📁 Your Data Summary</div>
                            <div class="card-subtitle">A snapshot of what we store for your account</div>
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Personal Info</span>
                        <span class="info-value"><span class="badge active">Stored</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Login Records</span>
                        <span class="info-value"><?= count($loginHistory) ?> records stored</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Password</span>
                        <span class="info-value"><span class="badge blue">bcrypt hashed</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Data</span>
                        <span class="info-value"><span class="badge active">Not collected</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Third-party Sharing</span>
                        <span class="info-value"><span class="badge active">Never</span></span>
                    </div>
                    <div style="margin-top:1.25rem">
                        <a href="profile.php" class="btn btn-outline btn-sm">Manage or Delete My Data →</a>
                    </div>
                </div>
            </div>

            <!-- ══ NOTIFICATIONS ══ -->
            <div id="panel-notifications" class="settings-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">🔔 Notification Preferences</div>
                            <div class="card-subtitle">Control what alerts and emails you receive</div>
                        </div>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Welcome & Onboarding Emails</h4>
                            <p>Receive setup tips and getting started guides</p>
                        </div>
                        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Workout Reminders</h4>
                            <p>Daily reminders to stay on track with your training</p>
                        </div>
                        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Progress Reports</h4>
                            <p>Weekly summaries of your fitness achievements</p>
                        </div>
                        <label class="toggle"><input type="checkbox"><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Security Alerts</h4>
                            <p>Notify me of new logins or suspicious activity</p>
                        </div>
                        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Recruitment Updates</h4>
                            <p>News and updates about <?= htmlspecialchars($forceName) ?> recruitment</p>
                        </div>
                        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Motivational Messages</h4>
                            <p>Daily quotes and motivation to keep you going</p>
                        </div>
                        <label class="toggle"><input type="checkbox"><span class="toggle-slider"></span></label>
                    </div>

                    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border)">
                        <p style="font-size:.8rem;color:var(--text-secondary);margin-bottom:1rem">
                            Note: Notification settings are saved locally in your browser. Full email preference management coming soon.
                        </p>
                        <button class="btn btn-primary btn-sm" onclick="saveNotifPrefs()">Save Preferences</button>
                    </div>
                </div>
            </div>

            <!-- ══ APPEARANCE ══ -->
            <div id="panel-appearance" class="settings-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">🎨 Appearance</div>
                            <div class="card-subtitle">Customize how Gurkha Marga looks for you</div>
                        </div>
                    </div>

                    <div style="margin-bottom:1.5rem">
                        <div style="font-size:.88rem;font-weight:500;margin-bottom:.75rem">Color Theme</div>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                            <?php
                            $themes = [
                                ['blue',   '#3b82f6', '#8b5cf6', 'Ocean Blue'],
                                ['green',  '#10b981', '#059669', 'Military Green'],
                                ['gold',   '#fbbf24', '#f59e0b', 'Gurkha Gold'],
                                ['red',    '#ef4444', '#dc2626', 'Army Red'],
                                ['purple', '#8b5cf6', '#7c3aed', 'Royal Purple'],
                            ];
                            foreach ($themes as [$key, $c1, $c2, $name]):
                            ?>
                            <div onclick="setTheme('<?= $key ?>')"
                                 style="cursor:pointer;border-radius:10px;overflow:hidden;border:2px solid rgba(255,255,255,.1);transition:all .25s;min-width:80px"
                                 id="theme-<?= $key ?>">
                                <div style="height:40px;background:linear-gradient(135deg,<?= $c1 ?>,<?= $c2 ?>)"></div>
                                <div style="font-size:.72rem;text-align:center;padding:.4rem;background:rgba(15,23,42,.6)"><?= $name ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-bottom:1.5rem">
                        <div style="font-size:.88rem;font-weight:500;margin-bottom:.75rem">Sidebar Compact Mode</div>
                        <div class="toggle-row" style="border-bottom:none;padding:0">
                            <div class="toggle-info">
                                <h4>Compact Sidebar</h4>
                                <p>Show icons only — saves screen space on smaller displays</p>
                            </div>
                            <label class="toggle"><input type="checkbox" id="compactToggle" onchange="toggleCompact()"><span class="toggle-slider"></span></label>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:.88rem;font-weight:500;margin-bottom:.75rem">Font Size</div>
                        <div style="display:flex;gap:.5rem">
                            <?php foreach (['Small','Medium','Large'] as $sz): ?>
                            <button onclick="setFontSize('<?= $sz ?>')" id="fs-<?= $sz ?>"
                                    style="padding:.5rem 1rem;border-radius:8px;border:1.5px solid rgba(255,255,255,.15);background:transparent;color:var(--text-secondary);cursor:pointer;font-family:'Poppins',sans-serif;font-size:.82rem;transition:all .25s">
                                <?= $sz ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ ACTIVITY LOG ══ -->
            <div id="panel-activity" class="settings-panel">
                <div class="mini-stats" style="grid-template-columns:repeat(3,1fr)">
                    <div class="mini-stat blue">
                        <div class="mini-stat-icon">🔓</div>
                        <div class="mini-stat-val"><?= $totalLogins ?></div>
                        <div class="mini-stat-lbl">Successful Logins</div>
                    </div>
                    <div class="mini-stat" style="border-color:rgba(239,68,68,.2)">
                        <div class="mini-stat-icon">🔒</div>
                        <div class="mini-stat-val" style="color:#fca5a5"><?= $failedLogins ?></div>
                        <div class="mini-stat-lbl">Failed Attempts</div>
                    </div>
                    <div class="mini-stat gold">
                        <div class="mini-stat-icon">📅</div>
                        <div class="mini-stat-val"><?= $daysSinceMember ?></div>
                        <div class="mini-stat-lbl">Days Active</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">🕐 Login Activity</div>
                            <div class="card-subtitle">Last <?= count($loginHistory) ?> login events</div>
                        </div>
                    </div>
                    <?php if (empty($loginHistory)): ?>
                        <div style="text-align:center;padding:2rem;color:var(--text-secondary)">No activity recorded yet.</div>
                    <?php else: ?>
                        <?php foreach ($loginHistory as $log): ?>
                        <div class="activity-item">
                            <div class="activity-dot <?= $log['status'] ?>"></div>
                            <div class="activity-body">
                                <div class="activity-title">
                                    <?= $log['status'] === 'success' ? 'Successful login' : 'Failed login attempt' ?>
                                </div>
                                <div class="activity-time">
                                    <?= htmlspecialchars($log['ip_address'] ?? 'Unknown IP') ?>
                                    &nbsp;·&nbsp;
                                    <?= date('M j, Y · g:i A', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                            <span class="activity-badge <?= $log['status'] ?>"><?= ucfirst($log['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══ HELP & SUPPORT ══ -->
            <div id="panel-help" class="settings-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">❓ Help & Support</div>
                            <div class="card-subtitle">Find answers and get in touch</div>
                        </div>
                    </div>

                    <a href="dashboard.php" class="link-card">
                        <div class="link-card-left">
                            <div class="link-card-icon">🏠</div>
                            <div>
                                <div class="link-card-title">Go to Dashboard</div>
                                <div class="link-card-desc">Return to your main dashboard overview</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                    <a href="profile.php" class="link-card">
                        <div class="link-card-left">
                            <div class="link-card-icon">👤</div>
                            <div>
                                <div class="link-card-title">Edit Profile</div>
                                <div class="link-card-desc">Update your personal info and fitness goals</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                    <a href="workouts.php" class="link-card">
                        <div class="link-card-left">
                            <div class="link-card-icon">💪</div>
                            <div>
                                <div class="link-card-title">View Workouts</div>
                                <div class="link-card-desc">Browse and start your training sessions</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                    <a href="../auth/logout.php" class="link-card" style="border-color:rgba(239,68,68,.2)">
                        <div class="link-card-left">
                            <div class="link-card-icon">🚪</div>
                            <div>
                                <div class="link-card-title" style="color:#fca5a5">Logout</div>
                                <div class="link-card-desc">Sign out of your Gurkha Marga account</div>
                            </div>
                        </div>
                        <span class="link-arrow">→</span>
                    </a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">ℹ️ App Information</div>
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Platform</span>
                        <span class="info-value">Gurkha Marga</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Version</span>
                        <span class="info-value"><span class="badge blue">v1.0.0</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Your Account ID</span>
                        <span class="info-value">#<?= str_pad($userId, 5, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Data Region</span>
                        <span class="info-value">Nepal / Local Server</span>
                    </div>
                </div>
            </div>

        </div><!-- end panels -->
    </div><!-- end settings-layout -->
</main>

<!-- ── Deactivate Modal ── -->
<div class="modal-overlay" id="deactivateModal">
    <div class="modal">
        <h3>⏸ Deactivate Account</h3>
        <p>
            This will temporarily disable your account for
            <strong style="color:var(--text-primary)"><?= htmlspecialchars($user['full_name']) ?></strong>.
            You won't be able to log in until an admin reactivates it. Enter your password to confirm.
        </p>
        <form method="POST" action="settings.php">
            <input type="hidden" name="action" value="deactivate_account">
            <div class="form-group">
                <label class="form-label">Your Password</label>
                <input type="password" name="deactivate_password" class="form-input"
                       placeholder="Enter password to confirm" required autocomplete="current-password">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline btn-full"
                        onclick="document.getElementById('deactivateModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-warning btn-full">⏸ Deactivate</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

<script>
// ── Panel switching ──
function showPanel(name, btn) {
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.settings-nav-item').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    if (btn) btn.classList.add('active');
}

// ── Restore panel on error/success ──
<?php if (($error || $success) && $activeTab !== 'account'): ?>
window.addEventListener('load', () => showPanel('<?= $activeTab ?>', null));
<?php endif; ?>

// ── Notification save (localStorage simulation) ──
function saveNotifPrefs() {
    const btn = event.target;
    btn.textContent = '✓ Saved!';
    btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
    setTimeout(() => {
        btn.textContent = 'Save Preferences';
        btn.style.background = '';
    }, 2000);
}

// ── Theme switcher ──
function setTheme(key) {
    const themes = {
        blue:   ['#3b82f6','#8b5cf6'],
        green:  ['#10b981','#059669'],
        gold:   ['#fbbf24','#f59e0b'],
        red:    ['#ef4444','#dc2626'],
        purple: ['#8b5cf6','#7c3aed'],
    };
    document.querySelectorAll('[id^="theme-"]').forEach(el => el.style.borderColor = 'rgba(255,255,255,.1)');
    document.getElementById('theme-' + key).style.borderColor = themes[key][0];
    document.documentElement.style.setProperty('--accent', themes[key][0]);
    document.documentElement.style.setProperty('--purple', themes[key][1]);
    localStorage.setItem('gm-theme', key);
    localStorage.setItem('gm-theme-colors', JSON.stringify(themes[key]));
}

// ── Font size ──
function setFontSize(sz) {
    const sizes = { Small: '14px', Medium: '16px', Large: '18px' };
    document.body.style.fontSize = sizes[sz];
    document.querySelectorAll('[id^="fs-"]').forEach(b => {
        b.style.borderColor = 'rgba(255,255,255,.15)';
        b.style.color = 'var(--text-secondary)';
    });
    const btn = document.getElementById('fs-' + sz);
    if (btn) { btn.style.borderColor = 'var(--accent)'; btn.style.color = 'var(--text-primary)'; }
    localStorage.setItem('gm-fontsize', sz);
}

// ── Compact sidebar ──
function toggleCompact() {
    const sidebar = document.getElementById('sidebar');
    const compact  = document.getElementById('compactToggle').checked;
    sidebar.style.width = compact ? '70px' : '280px';
    document.querySelector('.main-content').style.marginLeft = compact ? '70px' : '280px';
    sidebar.querySelectorAll('.brand-name,.user-card,.nav-section-title').forEach(el => {
        el.style.display = compact ? 'none' : '';
    });
    sidebar.querySelectorAll('.nav-item').forEach(el => {
        el.style.justifyContent = compact ? 'center' : '';
        el.querySelectorAll('span,text').forEach(t => { if(t.nodeType===3||t.tagName==='SPAN') t.style && (t.style.display = compact ? 'none' : ''); });
    });
}

// ── Apply saved theme on load ──
window.addEventListener('load', function() {
    const saved = localStorage.getItem('gm-theme');
    if (saved) setTheme(saved);
    const fs = localStorage.getItem('gm-fontsize');
    if (fs) setFontSize(fs);

    // animate progress bars
    document.querySelectorAll('.prog-fill').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => { bar.style.width = w; }, 200);
    });
});

// ── Sidebar mobile ──
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const btn = document.querySelector('.mobile-menu-btn');
    if (window.innerWidth <= 768 && sidebar && btn && !sidebar.contains(e.target) && !btn.contains(e.target)) {
        sidebar.classList.remove('active');
    }
});

// ── Modal close on overlay click ──
document.getElementById('deactivateModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>