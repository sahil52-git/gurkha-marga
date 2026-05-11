<?php
/**
 * Dashboard — Gurkha Marga
 * Minimalist rebuild matching profile.php color system
 */
declare(strict_types=1);
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}

$userId = (int)$_SESSION['user_id'];
$user   = fetchOne('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

/* ── helpers ── */
function calcBmi(float $w, float $h): ?float {
    if ($h <= 0) return null;
    return round($w / (($h / 100) ** 2), 1);
}
function bmiInfo(float $bmi): array {
    return match(true) {
        $bmi < 18.5 => ['label' => 'Underweight', 'key' => 'under',  'color' => '#93c5fd'],
        $bmi < 25.0 => ['label' => 'Healthy',     'key' => 'normal', 'color' => '#6ee7b7'],
        $bmi < 30.0 => ['label' => 'Overweight',  'key' => 'over',   'color' => '#fcd34d'],
        default     => ['label' => 'Obese',        'key' => 'obese',  'color' => '#fca5a5'],
    };
}

/* ── user data ── */
$weight   = (float)($user['weight'] ?? 0);
$height   = (float)($user['height'] ?? 0);
$age      = (int)  ($user['age']    ?? 0);
$gender   = $user['gender']           ?? 'male';
$forceKey = strtolower($user['target_force']   ?? 'british');
$expKey   = strtolower($user['experience_level'] ?? 'beginner');
$firstName   = explode(' ', trim($user['full_name']))[0];
$initials    = strtoupper(substr($user['full_name'], 0, 1));
$memberSince = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A';
$lastLogin   = !empty($user['last_login'])  ? date('M j, Y · g:i A', strtotime($user['last_login'])) : 'N/A';

$forceMap = [
    'british'   => ['🇬🇧', 'British Army'],
    'nepal'     => ['🇳🇵', 'Nepal Army'],
    'indian'    => ['🇮🇳', 'Indian Army'],
    'singapore' => ['🇸🇬', 'Singapore Police Force'],
    'french'    => ['🇫🇷', 'French Foreign Legion'],
];
$forceFlag = $forceMap[$forceKey][0] ?? '🎖️';
$forceName = $forceMap[$forceKey][1] ?? ucfirst($forceKey);

$bmi     = ($weight && $height) ? calcBmi($weight, $height) : null;
$bmiData = $bmi ? bmiInfo($bmi) : null;

/* ── BMI ring math ── */
$bmiCirc       = 2 * M_PI * 48;
$bmiDashOffset = $bmiCirc;
$bmiColor      = '#3b82f6';
if ($bmi && $bmiData) {
    $bmiColor      = $bmiData['color'];
    $fraction      = min(1, max(0, ($bmi - 10) / 30));
    $bmiDashOffset = $bmiCirc * (1 - $fraction);
}

/* ── progress logs ── */
$progressLogs = [];
try {
    $progressLogs = fetchAll(
        "SELECT * FROM progress_logs WHERE user_id = ? ORDER BY logged_at DESC LIMIT 30",
        [$userId]
    ) ?: [];
} catch (Exception $e) {}

$latestLog = $progressLogs[0] ?? null;
$curWeight = $latestLog ? (float)$latestLog['weight']  : $weight;
$curRun    = $latestLog ? (int)$latestLog['run_5k_sec'] : null;
$curPullups= $latestLog ? (int)$latestLog['pullups']    : null;
$curPushups= $latestLog ? (int)$latestLog['pushups']    : null;

/* ── chart data: last 30 days from progress_logs ── */
$chartLabels  = [];
$chartWeight  = [];
$chartPullups = [];
$chartRun     = [];

foreach (array_reverse($progressLogs) as $log) {
    $chartLabels[]  = date('d M', strtotime($log['logged_at']));
    $chartWeight[]  = $log['weight']     ? (float)$log['weight']                       : null;
    $chartPullups[] = $log['pullups']    ? (int)$log['pullups']                         : null;
    $chartRun[]     = $log['run_5k_sec'] ? round((int)$log['run_5k_sec'] / 60, 2) : null;
}

/* ── workout stats ── */
$workoutCount = 0; $totalMinutes = 0;
try {
    $ws = fetchOne("SELECT COUNT(*) as c, COALESCE(SUM(duration_minutes),0) as m FROM workouts WHERE user_id=?", [$userId]);
    $workoutCount = (int)($ws['c'] ?? 0);
    $totalMinutes = (int)($ws['m'] ?? 0);
} catch (Exception $e) {}

/* ── workout chart: sessions per day last 30 days ── */
$workoutActivity = [];
try {
    $rows = fetchAll(
        "SELECT DATE(created_at) as d, COUNT(*) as cnt
         FROM workouts WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at) ORDER BY d ASC",
        [$userId]
    ) ?: [];
    foreach ($rows as $r) {
        $workoutActivity[$r['d']] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

$wLabels = []; $wData = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $wLabels[] = date('d M', strtotime($date));
    $wData[]   = $workoutActivity[$date] ?? 0;
}

/* ── streak ── */
$streak = 0;
try {
    $wDates = fetchAll("SELECT DATE(created_at) as d FROM workouts WHERE user_id=? GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 60", [$userId]);
    $today = new DateTime();
    foreach ($wDates as $i => $row) {
        if ($row['d'] !== (clone $today)->modify("-{$i} days")->format('Y-m-d')) break;
        $streak++;
    }
} catch (Exception $e) {}

/* ── avatar data from profile ── */
$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$expProgress = match($expKey) {
    'beginner' => 25, 'intermediate' => 50, 'advanced' => 75, 'expert' => 100, default => 0,
};

$hour     = (int)date('H');
$greeting = match(true) { $hour < 12 => 'Good morning', $hour < 17 => 'Good afternoon', $hour < 21 => 'Good evening', default => 'Good night' };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:     #3b82f6;
    --gold:       #fbbf24;
    --success:    #10b981;
    --error:      #ef4444;
    --text-primary:   #f8fafc;
    --text-secondary: #cbd5e1;
    --text-muted:     #64748b;
    --sidebar-bg: rgba(15, 23, 42, 0.97);
    --card-bg:    rgba(30, 41, 59, 0.8);
    --card-bg-solid: #1e293b;
    --hover-bg:   rgba(59, 130, 246, 0.08);
    --input-bg:   rgba(15, 23, 42, 0.6);
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

/* ── MAIN ── */
.main-content { margin-left: 260px; padding: 1.75rem; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    background: var(--card-bg);
    backdrop-filter: blur(20px);
    border-radius: 14px; padding: 1.25rem 1.75rem; margin-bottom: 1.75rem;
    display: flex; justify-content: space-between; align-items: center;
    border: 1px solid var(--border);
}
.topbar-greeting { font-size: .75rem; color: var(--text-muted); margin-bottom: .2rem; letter-spacing: .04em; }
.topbar-name { font-size: 1.3rem; font-weight: 700; }
.topbar-date { font-size: .75rem; color: var(--text-secondary); margin-top: .2rem; }
.btn {
    padding: .55rem 1.1rem; border: none; border-radius: 8px;
    font-weight: 600; cursor: pointer; transition: all .2s;
    font-family: 'Poppins', sans-serif; display: inline-flex;
    align-items: center; gap: 7px; font-size: .82rem; text-decoration: none;
}
.btn-primary { background: linear-gradient(135deg, var(--accent), #8b5cf6); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59, 130, 246, 0.35); }
.btn-outline {
    background: transparent; border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--text-secondary);
}
.btn-outline:hover { background: rgba(255, 255, 255, 0.06); color: var(--text-primary); }

/* ── GRID LAYOUT ── */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 1rem;
}
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }

/* ── CARD ── */
.card {
    background: var(--card-bg);
    backdrop-filter: blur(12px);
    border-radius: 14px; padding: 1.35rem;
    border: 1px solid var(--border);
    transition: border-color .2s;
}
.card:hover { border-color: var(--border-hi); }
.card-label {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    text-transform: uppercase; color: var(--text-muted); margin-bottom: 1rem;
    display: flex; align-items: center; gap: .5rem;
}
.card-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ── USER DETAIL CARD ── */
.user-detail-grid { display: grid; gap: .6rem; }
.detail-row { display: flex; justify-content: space-between; align-items: center; }
.detail-key { font-size: .78rem; color: var(--text-muted); }
.detail-val { font-size: .82rem; font-weight: 500; text-align: right; }
.tag {
    display: inline-flex; align-items: center;
    padding: .2rem .6rem; border-radius: 20px; font-size: .7rem; font-weight: 500;
    background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.1);
}
.tag.gold  { background: rgba(251,191,36,.12); border-color: rgba(251,191,36,.25); color: var(--gold); }
.tag.blue  { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.25); color: #93c5fd; }
.tag.green { background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.25); color: #6ee7b7; }

/* ── BMI CARD ── */
.bmi-center { display: flex; flex-direction: column; align-items: center; gap: .75rem; padding: .25rem 0; }
.bmi-ring-wrap { position: relative; width: 110px; height: 110px; }
.bmi-ring-wrap svg { transform: rotate(-90deg); }
.bmi-ring-inner {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
}
.bmi-number { font-size: 1.7rem; font-weight: 800; line-height: 1; }
.bmi-unit   { font-size: .6rem; color: var(--text-muted); margin-top: 1px; }
.bmi-badge  {
    padding: .25rem .75rem; border-radius: 20px;
    font-size: .72rem; font-weight: 600; letter-spacing: .03em;
}
.bmi-badge.normal      { background: rgba(16,185,129,.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
.bmi-badge.underweight { background: rgba(59,130,246,.15);  color: #93c5fd; border: 1px solid rgba(59,130,246,.25); }
.bmi-badge.overweight  { background: rgba(251,191,36,.15);  color: #fcd34d; border: 1px solid rgba(251,191,36,.25); }
.bmi-badge.obese       { background: rgba(239,68,68,.15);   color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
.bmi-stats { display: flex; gap: 1.25rem; width: 100%; justify-content: center; border-top: 1px solid var(--border); padding-top: .85rem; }
.bmi-stat { text-align: center; }
.bmi-stat-val { font-size: .95rem; font-weight: 700; }
.bmi-stat-lbl { font-size: .62rem; color: var(--text-muted); margin-top: 2px; }

/* ── LATEST METRICS from progress ── */
.metric-list { display: grid; gap: .55rem; }
.metric-row  { display: flex; align-items: center; gap: .75rem; }
.metric-key  { font-size: .75rem; color: var(--text-muted); flex: 1; }
.metric-val  { font-size: .85rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.metric-bar  { flex: 1; height: 3px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; }
.metric-fill { height: 100%; border-radius: 2px; transition: width 1s ease .3s; width: 0%; }
.no-data-note { font-size: .75rem; color: var(--text-muted); text-align: center; padding: .75rem 0; line-height: 1.6; }
.no-data-note a { color: var(--accent); text-decoration: none; }
.no-data-note a:hover { text-decoration: underline; }

/* ── ACTIVITY CHART ── */
.chart-tabs { display: flex; gap: .25rem; margin-bottom: 1rem; }
.chart-tab {
    padding: .3rem .7rem; border-radius: 6px; font-size: .7rem;
    font-weight: 600; cursor: pointer; border: 1px solid var(--border);
    background: transparent; color: var(--text-muted);
    font-family: 'Poppins', sans-serif; transition: all .15s;
}
.chart-tab.active {
    background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.3);
    color: #93c5fd;
}
.chart-wrap { height: 160px; position: relative; }
.chart-summary { display: flex; gap: 1.25rem; margin-top: .85rem; padding-top: .85rem; border-top: 1px solid var(--border); }
.chart-stat { }
.chart-stat-val { font-size: 1.05rem; font-weight: 700; line-height: 1; }
.chart-stat-lbl { font-size: .65rem; color: var(--text-muted); margin-top: 3px; }
.streak-inline {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem .85rem; border-radius: 8px;
    background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2);
    margin-left: auto;
}
.streak-inline .s-num { font-size: 1.1rem; font-weight: 800; color: var(--gold); }
.streak-inline .s-lbl { font-size: .65rem; color: rgba(251,191,36,.7); }

/* ── PROGRESS TREND (from progress_logs) ── */
.trend-chart-wrap { height: 130px; position: relative; }

/* ── QUICK LINKS ── */
.quick-links { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.quick-link {
    display: flex; align-items: center; gap: .7rem;
    padding: .75rem .9rem; border-radius: 10px;
    background: rgba(255,255,255,.04); border: 1px solid var(--border);
    text-decoration: none; color: var(--text-secondary);
    font-size: .8rem; font-weight: 500; transition: all .2s;
}
.quick-link:hover { background: var(--hover-bg); color: var(--text-primary); border-color: rgba(59,130,246,.25); }
.quick-link svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .6; }

/* ── MOBILE ── */
.mobile-menu-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
.card { animation: fadeIn .35s ease both; }
.card:nth-child(1) { animation-delay: .04s; }
.card:nth-child(2) { animation-delay: .08s; }
.card:nth-child(3) { animation-delay: .12s; }
.card:nth-child(4) { animation-delay: .16s; }
.card:nth-child(5) { animation-delay: .20s; }

@media (max-width: 1100px) {
    .dashboard-grid { grid-template-columns: 1fr 1fr; }
    .span-3 { grid-column: span 2; }
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 1rem; }
    .dashboard-grid { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
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
                <p><?= ucfirst($expKey) ?> · <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item active">
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
        <div class="nav-section-title" style="margin-top: .75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="settings.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <!-- ★ NEW — Subscription link added below Settings -->
        <a href="subscription_fixed.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
        </a>
        <!-- ★ END NEW -->
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- MAIN -->
<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <div class="topbar-greeting"><?= $greeting ?></div>
            <div class="topbar-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="topbar-date"><?= date('l, F j, Y') ?></div>
        </div>
        <div style="display: flex; gap: .5rem; align-items: center;">
            <?php if ($streak > 0): ?>
            <span style="font-size: .75rem; color: var(--gold); padding: .35rem .7rem; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2); border-radius: 8px; font-weight: 600;">
                <?= $streak ?> day streak
            </span>
            <?php endif; ?>
            <a href="progress.php" class="btn btn-outline">Log Progress</a>
            <a href="workouts.php" class="btn btn-primary">New Workout</a>
        </div>
    </div>

    <!-- GRID -->
    <div class="dashboard-grid">

        <!-- 1. USER DETAILS -->
        <div class="card">
            <div class="card-label">Account</div>
            <div class="user-detail-grid">
                <div class="detail-row">
                    <span class="detail-key">Name</span>
                    <span class="detail-val"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key">Email</span>
                    <span class="detail-val" style="font-size: .72rem; color: var(--text-secondary);"><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key">Age</span>
                    <span class="detail-val"><?= (int)$user['age'] ?> yrs</span>
                </div>
                <div class="detail-row">
                    <span class="detail-key">Gender</span>
                    <span class="detail-val"><?= ucfirst($user['gender'] ?? 'N/A') ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key">Member since</span>
                    <span class="detail-val" style="font-size: .75rem;"><?= $memberSince ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key">Last login</span>
                    <span class="detail-val" style="font-size: .7rem; color: var(--text-secondary);"><?= $lastLogin ?></span>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .35rem;">
                    <span class="tag gold"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
                    <span class="tag blue"><?= ucfirst($expKey) ?></span>
                    <?php if ($height && $weight): ?>
                    <span class="tag"><?= $height ?>cm · <?= $weight ?>kg</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 2. BMI ANALYSIS -->
        <div class="card">
            <div class="card-label">BMI Analysis</div>
            <?php if ($bmi && $bmiData): ?>
            <?php
                $bmiCirc2 = 2 * M_PI * 48;
                $bmiFrac  = min(1, max(0, ($bmi - 10) / 30));
                $bmiDash  = round($bmiCirc2 * (1 - $bmiFrac), 2);
            ?>
            <div class="bmi-center">
                <div class="bmi-ring-wrap">
                    <svg width="110" height="110" viewBox="0 0 110 110">
                        <circle cx="55" cy="55" r="48" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="8"/>
                        <circle cx="55" cy="55" r="48" fill="none"
                            stroke="<?= $bmiData['color'] ?>" stroke-width="8"
                            stroke-linecap="round"
                            stroke-dasharray="<?= round($bmiCirc2, 2) ?>"
                            stroke-dashoffset="<?= round($bmiCirc2, 2) ?>"
                            id="bmiRingCircle"
                            data-target="<?= $bmiDash ?>"/>
                    </svg>
                    <div class="bmi-ring-inner">
                        <div class="bmi-number" style="color: <?= $bmiData['color'] ?>"><?= $bmi ?></div>
                        <div class="bmi-unit">BMI</div>
                    </div>
                </div>
                <span class="bmi-badge <?= $bmiData['key'] === 'normal' ? 'normal' : ($bmiData['key'] === 'under' ? 'underweight' : ($bmiData['key'] === 'over' ? 'overweight' : 'obese')) ?>">
                    <?= $bmiData['label'] ?>
                </span>
                <div class="bmi-stats">
                    <div class="bmi-stat">
                        <div class="bmi-stat-val"><?= $height ?> cm</div>
                        <div class="bmi-stat-lbl">Height</div>
                    </div>
                    <div class="bmi-stat">
                        <div class="bmi-stat-val"><?= $weight ?> kg</div>
                        <div class="bmi-stat-lbl">Weight</div>
                    </div>
                    <?php
                        $h = $height / 100;
                        $ideal = round(21.75 * $h * $h, 1);
                    ?>
                    <div class="bmi-stat">
                        <div class="bmi-stat-val"><?= $ideal ?> kg</div>
                        <div class="bmi-stat-lbl">Ideal weight</div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data-note">
                Complete your height and weight in <a href="profile.php">Profile</a> to see your BMI analysis.
            </div>
            <?php endif; ?>
        </div>

        <!-- 3. LATEST PROGRESS METRICS (from progress_logs) -->
        <div class="card">
            <div class="card-label">Latest Progress</div>
            <?php if ($latestLog): ?>
            <?php
                $standards = [
                    'british'   => ['pullups' => 6,  'pushups' => 44, 'run' => 630],
                    'nepal'     => ['pullups' => 9,  'pushups' => 40, 'run' => 810],
                    'indian'    => ['pullups' => 6,  'pushups' => 40, 'run' => 330],
                    'singapore' => ['pullups' => 5,  'pushups' => 30, 'run' => 690],
                    'french'    => ['pullups' => 10, 'pushups' => 50, 'run' => 480],
                ];
                $std = $standards[$forceKey] ?? $standards['british'];
                $loggedDate = date('M j, Y', strtotime($latestLog['logged_at']));
            ?>
            <div style="font-size: .68rem; color: var(--text-muted); margin-bottom: .85rem;">Logged <?= $loggedDate ?></div>
            <div class="metric-list">
                <?php if ($latestLog['weight']): ?>
                <div class="metric-row">
                    <span class="metric-key">Weight</span>
                    <span class="metric-val"><?= (float)$latestLog['weight'] ?> kg</span>
                </div>
                <?php endif; ?>
                <?php if ($latestLog['run_5k_sec']): $secs = (int)$latestLog['run_5k_sec']; $runPct = min(100, round(($std['run'] / $secs) * 100)); ?>
                <div class="metric-row">
                    <span class="metric-key">5K Run</span>
                    <span class="metric-val"><?= floor($secs/60) ?>:<?= str_pad((string)($secs%60),2,'0',STR_PAD_LEFT) ?></span>
                </div>
                <div style="padding: 0; margin-top: -.3rem; margin-bottom: .15rem;">
                    <div class="metric-bar"><div class="metric-fill" data-w="<?= $runPct ?>" style="background: #93c5fd;"></div></div>
                </div>
                <?php endif; ?>
                <?php if ($latestLog['pullups']): $puPct = min(100, round(((int)$latestLog['pullups'] / max(1,$std['pullups'])) * 100)); ?>
                <div class="metric-row">
                    <span class="metric-key">Pull-ups</span>
                    <span class="metric-val"><?= (int)$latestLog['pullups'] ?> reps</span>
                </div>
                <div style="margin-top: -.3rem; margin-bottom: .15rem;">
                    <div class="metric-bar"><div class="metric-fill" data-w="<?= $puPct ?>" style="background: #6ee7b7;"></div></div>
                </div>
                <?php endif; ?>
                <?php if ($latestLog['pushups']): $psPct = min(100, round(((int)$latestLog['pushups'] / max(1,$std['pushups'])) * 100)); ?>
                <div class="metric-row">
                    <span class="metric-key">Push-ups</span>
                    <span class="metric-val"><?= (int)$latestLog['pushups'] ?> reps</span>
                </div>
                <div style="margin-top: -.3rem; margin-bottom: .15rem;">
                    <div class="metric-bar"><div class="metric-fill" data-w="<?= $psPct ?>" style="background: #a78bfa;"></div></div>
                </div>
                <?php endif; ?>
                <?php if ($latestLog['situps']): ?>
                <div class="metric-row">
                    <span class="metric-key">Sit-ups</span>
                    <span class="metric-val"><?= (int)$latestLog['situps'] ?> reps</span>
                </div>
                <?php endif; ?>
            </div>
            <a href="progress.php" style="display:block; margin-top: .85rem; font-size: .72rem; color: var(--accent); text-decoration: none; text-align: right;">View full progress →</a>
            <?php else: ?>
            <div class="no-data-note">
                No sessions logged yet.<br>
                <a href="progress.php">Log your first session</a> to track progress here.
            </div>
            <?php endif; ?>
        </div>

        <!-- 4. ACTIVITY OVERVIEW (workouts) — span 2 -->
        <div class="card span-2">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: .85rem;">
                <div class="card-label" style="margin-bottom: 0;">Activity Overview</div>
                <div class="chart-tabs">
                    <button class="chart-tab active" onclick="switchChart('workouts', this)">Workouts</button>
                    <button class="chart-tab" onclick="switchChart('weight', this)">Weight</button>
                    <button class="chart-tab" onclick="switchChart('pullups', this)">Pull-ups</button>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="activityChart"></canvas>
            </div>
            <div class="chart-summary">
                <div class="chart-stat">
                    <div class="chart-stat-val"><?= $workoutCount ?></div>
                    <div class="chart-stat-lbl">Total workouts</div>
                </div>
                <div class="chart-stat">
                    <div class="chart-stat-val"><?= $totalMinutes > 60 ? round($totalMinutes / 60, 1) . 'h' : $totalMinutes . 'm' ?></div>
                    <div class="chart-stat-lbl">Training time</div>
                </div>
                <div class="chart-stat">
                    <div class="chart-stat-val"><?= count($progressLogs) ?></div>
                    <div class="chart-stat-lbl">Progress logs</div>
                </div>
                <?php if ($streak > 0): ?>
                <div class="streak-inline">
                    <span class="s-num"><?= $streak ?></span>
                    <span class="s-lbl">day streak</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 5. QUICK LINKS -->
        <div class="card">
            <div class="card-label">Quick Actions</div>
            <div class="quick-links">
                <a href="workouts.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Workouts
                </a>
                <a href="progress.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
                    Progress
                </a>
                <a href="nutrition.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
                    Nutrition
                </a>
                <a href="profile.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Profile
                </a>
                <a href="questions.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Questions
                </a>
                <a href="motivation.php" class="quick-link">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    Motivation
                </a>
            </div>
        </div>

    </div><!-- /grid -->
</main>

<button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
// ── Avatar system (mirrors profile.php) ──
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

    const bg   = AV_OPTIONS.bg.find(o => o.id === state.bg)         || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o => o.id === state.skin)     || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o => o.id === state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o => o.id === state.eyes)     || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const hStyle = state.hairStyle || 'short';

    // BG
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0, bg.grad[0]); grad.addColorStop(1, bg.grad[1]);
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(cx, cy, cx, 0, Math.PI*2); ctx.fill();

    // Neck
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14, cy+32, 28, 22, [4,4,0,0]); ctx.fill();

    // Shoulders
    const shirt = ctx.createLinearGradient(cx-55, cy+50, cx+55, size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle = shirt;
    ctx.beginPath(); ctx.ellipse(cx, cy+62, 58, 28, 0, 0, Math.PI*2); ctx.fill();

    // Head
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx, cy+2, 44, 52, 0, 0, Math.PI*2); ctx.fill();

    // Ears
    ctx.beginPath(); ctx.ellipse(cx-44, cy+5, 8, 11, 0, 0, Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44, cy+5, 8, 11, 0, 0, Math.PI*2); ctx.fill();

    // Eyebrows
    ctx.strokeStyle = hCol.color === '#F5F5F5' ? '#C0A080' : hCol.color;
    ctx.lineWidth = 3.5; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx-28, cy-16); ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx+7, cy-16);  ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16); ctx.stroke();

    // Eyes
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = eyeC.color;
    ctx.beginPath(); ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = '#000';
    ctx.beginPath(); ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.beginPath(); ctx.arc(cx-13,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+19,cy-7,2.2,0,Math.PI*2); ctx.fill();

    // Nose
    ctx.strokeStyle = 'rgba(0,0,0,0.18)'; ctx.lineWidth = 2; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx-3,cy+2); ctx.lineTo(cx,cy+12); ctx.lineTo(cx+3,cy+2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx-9,cy+14); ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14); ctx.stroke();

    // Mouth
    ctx.strokeStyle = 'rgba(0,0,0,0.3)'; ctx.lineWidth = 2.5;
    ctx.beginPath(); ctx.moveTo(cx-14,cy+24); ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24); ctx.stroke();

    // Hair
    ctx.fillStyle = hCol.color;
    if (hStyle === 'bald') {
        // nothing
    } else if (hStyle === 'medium') {
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.ellipse(cx-44,cy+8,9,32,-0.2,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+44,cy+8,9,32,0.2,-Math.PI/2,Math.PI/2,true); ctx.fill();
    } else if (hStyle === 'long') {
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.roundRect(cx-50,cy-10,12,75,6); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx+38,cy-10,12,75,6); ctx.fill();
    } else if (hStyle === 'curly') {
        for (let a=0; a<Math.PI*2; a+=0.35) {
            const rx=cx+Math.cos(a)*42, ry=(cy-30)+Math.sin(a)*24;
            if (ry < cy-10) { ctx.beginPath(); ctx.arc(rx,ry,9,0,Math.PI*2); ctx.fill(); }
        }
        ctx.beginPath(); ctx.ellipse(cx,cy-42,40,18,0,Math.PI,0); ctx.fill();
    } else if (hStyle === 'bun') {
        ctx.beginPath(); ctx.ellipse(cx,cy-40,44,20,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-43,88,18);
        ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.fill();
    } else if (hStyle === 'mohawk') {
        ctx.beginPath(); ctx.moveTo(cx-10,cy-40); ctx.lineTo(cx,cy-80); ctx.lineTo(cx+10,cy-40); ctx.closePath(); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-10,cy-50,20,14,2); ctx.fill();
    } else { // short (default)
        ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-40,88,20);
        ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
    }

    // Beard
    if (acc.includes('beard')) {
        ctx.fillStyle = hCol.color;
        ctx.beginPath(); ctx.ellipse(cx,cy+36,30,18,0,0,Math.PI); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-30,cy+18,60,20,4); ctx.fill();
    }
    // Glasses
    if (acc.includes('glasses')) {
        ctx.strokeStyle = '#64748b'; ctx.lineWidth = 2.5; ctx.fillStyle = 'rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx-32,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-5); ctx.lineTo(cx+8,cy-5); ctx.stroke();
    }
    // Sunglasses
    if (acc.includes('sunglasses')) {
        ctx.fillStyle = '#111827'; ctx.strokeStyle = '#374151'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.roundRect(cx-34,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-7); ctx.lineTo(cx+8,cy-7); ctx.stroke();
    }

    // Ring glow
    const ring = ctx.createLinearGradient(0,0,size,size);
    ring.addColorStop(0, bg.grad[0]+'88'); ring.addColorStop(1, bg.grad[1]+'88');
    ctx.strokeStyle = ring; ctx.lineWidth = size < 60 ? 2 : 4;
    ctx.beginPath(); ctx.arc(cx, cy, cx-2, 0, Math.PI*2); ctx.stroke();
}

function renderSidebarAvatar() {
    const container = document.getElementById('sbAvContainer');
    if (!container) return;
    container.innerHTML = '';

    if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
        const img = document.createElement('img');
        img.src = SAVED_PHOTO_URL;
        img.style.cssText = 'width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror = () => renderInitialSb(container);
        container.appendChild(img);
    } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CONFIG) {
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'width:38px;height:38px;border-radius:50%;display:block';
        container.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CONFIG, 38);
    } else {
        renderInitialSb(container);
    }
}

function renderInitialSb(container) {
    container.innerHTML = '';
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    container.appendChild(d);
}

// ── Chart data from PHP ──
const WORKOUT_LABELS  = <?= json_encode($wLabels) ?>;
const WORKOUT_DATA    = <?= json_encode($wData) ?>;
const PROGRESS_LABELS = <?= json_encode(array_values($chartLabels)) ?>;
const PROGRESS_WEIGHT = <?= json_encode(array_values($chartWeight)) ?>;
const PROGRESS_PULLUPS= <?= json_encode(array_values($chartPullups)) ?>;
const PROGRESS_RUN    = <?= json_encode(array_values($chartRun)) ?>;

// ── Chart setup ──
Chart.defaults.color        = '#64748b';
Chart.defaults.borderColor  = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family  = "'Poppins', sans-serif";
Chart.defaults.font.size    = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,0.95)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(255,255,255,0.1)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding        = 10;
Chart.defaults.plugins.tooltip.cornerRadius   = 8;
Chart.defaults.plugins.tooltip.titleColor     = '#cbd5e1';
Chart.defaults.plugins.tooltip.bodyColor      = '#f8fafc';

let chart = null;

const chartConfigs = {
    workouts: {
        labels: WORKOUT_LABELS,
        data:   WORKOUT_DATA,
        label:  'Workouts',
        color:  '#3b82f6',
        type:   'bar',
        fill:   false,
    },
    weight: {
        labels: PROGRESS_LABELS,
        data:   PROGRESS_WEIGHT,
        label:  'Weight (kg)',
        color:  '#fbbf24',
        type:   'line',
        fill:   true,
    },
    pullups: {
        labels: PROGRESS_LABELS,
        data:   PROGRESS_PULLUPS,
        label:  'Pull-ups',
        color:  '#6ee7b7',
        type:   'line',
        fill:   true,
    },
};

function buildChart(key) {
    const cfg = chartConfigs[key];
    if (chart) chart.destroy();

    const ctx = document.getElementById('activityChart').getContext('2d');
    const isBar = cfg.type === 'bar';

    chart = new Chart(ctx, {
        type: cfg.type,
        data: {
            labels: cfg.labels,
            datasets: [{
                label: cfg.label,
                data:  cfg.data,
                borderColor:     cfg.color,
                backgroundColor: isBar ? cfg.color + '55' : cfg.color + '18',
                borderWidth: isBar ? 0 : 2,
                borderRadius: isBar ? 4 : 0,
                borderSkipped: false,
                tension: 0.4,
                fill: cfg.fill,
                pointRadius: 3,
                pointBackgroundColor: cfg.color,
                hoverPointRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    ticks: { maxTicksLimit: 8 },
                },
                y: {
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    beginAtZero: isBar,
                    ticks: { precision: 0 },
                },
            },
        },
    });
}

function switchChart(key, btn) {
    document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    buildChart(key);
}

// ── BMI ring animation ──
function animateBmiRing() {
    const circle = document.getElementById('bmiRingCircle');
    if (!circle) return;
    const arr    = parseFloat(circle.getAttribute('stroke-dasharray'));
    const target = parseFloat(circle.dataset.target);
    circle.style.strokeDashoffset = arr;
    requestAnimationFrame(() => {
        circle.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(.4,0,.2,1)';
        circle.style.strokeDashoffset = target;
    });
}

// ── progress bar animation ──
function animateBars() {
    document.querySelectorAll('[data-w]').forEach(el => {
        el.style.width = el.dataset.w + '%';
    });
}

// ── init ──
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    buildChart('workouts');
    setTimeout(() => {
        animateBmiRing();
        animateBars();
    }, 300);
});
</script>

</body>
</html>