<?php
// frontend/users/progress.php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    if (isset($_GET['action'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit(); }
    header('Location: ../auth/login.php'); exit();
}

$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

// ── AJAX handlers ──────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'log_progress') {
    header('Content-Type: application/json');
    try {
        $weight  = isset($_POST['weight'])     && $_POST['weight']!==''     ? (float)$_POST['weight'] : null;
        $run5k   = (isset($_POST['run_5k_min']) && $_POST['run_5k_min']!=='')
                   ? (int)($_POST['run_5k_min']*60) + (int)($_POST['run_5k_sec']??0) : null;
        $pullups = isset($_POST['pullups'])  && $_POST['pullups']!==''  ? (int)$_POST['pullups']  : null;
        $pushups = isset($_POST['pushups'])  && $_POST['pushups']!==''  ? (int)$_POST['pushups']  : null;
        $situps  = isset($_POST['situps'])   && $_POST['situps']!==''   ? (int)$_POST['situps']   : null;
        $chest   = isset($_POST['chest_cm']) && $_POST['chest_cm']!=='' ? (float)$_POST['chest_cm'] : null;
        $notes   = trim($_POST['notes'] ?? '');
        execute(
            "INSERT INTO progress_logs (user_id,weight,run_5k_sec,pullups,pushups,situps,chest_cm,notes) VALUES (?,?,?,?,?,?,?,?)",
            [$userId,$weight,$run5k,$pullups,$pushups,$situps,$chest,$notes]
        );
        echo json_encode(['success'=>true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); }
    exit();
}

if ($action === 'delete_log') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        execute("DELETE FROM progress_logs WHERE id=? AND user_id=?", [$id, $userId]);
        echo json_encode(['success'=>true]);
    } else { http_response_code(400); echo json_encode(['error'=>'Invalid ID']); }
    exit();
}

// ── User data ──────────────────────────────────────────────────────────
$weight   = (float)($user['weight']   ?? 70);
$forceKey = strtolower($user['target_force'] ?? 'british');
$firstName   = explode(' ', trim($user['full_name']))[0];
$initials    = strtoupper(substr($user['full_name'], 0, 1));

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$forceMap = [
    'british'   => ['flag'=>'🇬🇧', 'name'=>'British Army'],
    'nepal'     => ['flag'=>'🇳🇵', 'name'=>'Nepal Army'],
    'indian'    => ['flag'=>'🇮🇳', 'name'=>'Indian Army'],
    'singapore' => ['flag'=>'🇸🇬', 'name'=>'Singapore Police Force'],
    'french'    => ['flag'=>'🇫🇷', 'name'=>'French Foreign Legion'],
];
$forceFlag = $forceMap[$forceKey]['flag'] ?? '🎖️';
$forceName = $forceMap[$forceKey]['name'] ?? ucfirst($forceKey);

// ── Real logs ─────────────────────────────────────────────────────────
$realLogs  = fetchAll("SELECT * FROM progress_logs WHERE user_id=? ORDER BY logged_at ASC LIMIT 365", [$userId]) ?: [];
$totalLogs = count($realLogs);
$hasData   = $totalLogs >= 1;

$latest     = $hasData ? end($realLogs) : null;
$curWeight  = $latest ? (float)$latest['weight']   : null;
$curRun     = $latest && $latest['run_5k_sec']  ? (int)$latest['run_5k_sec']  : null;
$curPullups = $latest && $latest['pullups']     ? (int)$latest['pullups']     : null;
$curPushups = $latest && $latest['pushups']     ? (int)$latest['pushups']     : null;
$curSitups  = $latest && $latest['situps']      ? (int)$latest['situps']      : null;

function fmtRun(int $s): string {
    return floor($s/60) . ':' . str_pad($s % 60, 2, '0', STR_PAD_LEFT);
}

// ── Chart data ─────────────────────────────────────────────────────────
$allLabels  = array_map(fn($l) => date('d M', strtotime($l['logged_at'])), $realLogs);
$allDates   = array_map(fn($l) => $l['logged_at'], $realLogs);
$allWeight  = array_map(fn($l) => $l['weight']     ? (float)$l['weight'] : null, $realLogs);
$allRun     = array_map(fn($l) => $l['run_5k_sec'] ? round((int)$l['run_5k_sec']/60, 2) : null, $realLogs);
$allPullups = array_map(fn($l) => $l['pullups']    ? (int)$l['pullups']  : null, $realLogs);
$allPushups = array_map(fn($l) => $l['pushups']    ? (int)$l['pushups']  : null, $realLogs);
$allSitups  = array_map(fn($l) => $l['situps']     ? (int)$l['situps']   : null, $realLogs);

// ── Trend ─────────────────────────────────────────────────────────────
function calcTrend(array $logs, string $field, bool $lowerIsBetter = false): ?array {
    $vals = array_values(array_filter(array_column($logs, $field)));
    if (count($vals) < 2) return null;
    $first = $vals[0]; $last = $vals[count($vals)-1];
    $pct = round((($last - $first) / max(abs($first), 0.001)) * 100, 1);
    $improved = $lowerIsBetter ? $pct < 0 : $pct > 0;
    return ['pct' => abs($pct), 'dir' => $pct >= 0 ? '+' : '-', 'good' => $improved];
}
$tW  = calcTrend($realLogs, 'weight', true);
$tR  = calcTrend($realLogs, 'run_5k_sec', true);
$tPU = calcTrend($realLogs, 'pullups');
$tPS = calcTrend($realLogs, 'pushups');
$tSU = calcTrend($realLogs, 'situps');

$tableLogs = array_reverse($realLogs);

// ── CSV data for JS export ─────────────────────────────────────────────
$csvRows = [];
foreach (array_reverse($realLogs) as $log) {
    $csvRows[] = [
        date('Y-m-d', strtotime($log['logged_at'])),
        $log['weight'] ?? '',
        $log['run_5k_sec'] ? fmtRun((int)$log['run_5k_sec']) : '',
        $log['pullups'] ?? '',
        $log['pushups'] ?? '',
        $log['situps']  ?? '',
        $log['chest_cm'] ?? '',
        addslashes($log['notes'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Progress — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:  #3b82f6;
    --gold:    #fbbf24;
    --success: #10b981;
    --error:   #ef4444;
    --t1:      #f8fafc;
    --t2:      #cbd5e1;
    --t3:      #64748b;
    --bg:      #0f172a;
    --surface: rgba(30, 41, 59, 0.8);
    --surface-solid: #1e293b;
    --hover:   rgba(59, 130, 246, 0.08);
    --border:  rgba(255, 255, 255, 0.07);
    --border-hi: rgba(255, 255, 255, 0.12);
}

html { scroll-behavior: smooth; }
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--t1);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── SIDEBAR ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: rgba(15, 23, 42, 0.97);
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
    border-radius: 12px; padding: .85rem;
    display: flex; align-items: center; gap: 10px;
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
    color: rgba(203,213,225,.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--t2); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover); color: var(--t1); border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239,68,68,.08); border-left-color: var(--error); }

/* ── LAYOUT ── */
.main { margin-left: 260px; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    background: var(--surface);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    padding: .9rem 1.75rem;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 50;
}
.bc { font-size: .75rem; color: var(--t3); display: flex; align-items: center; gap: 5px; }
.bc a { color: var(--t2); text-decoration: none; }
.bc a:hover { color: var(--accent); }
.tb-right { display: flex; align-items: center; gap: .65rem; }
.force-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: .3rem .75rem; border-radius: 20px; font-size: .73rem; font-weight: 600;
    background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2); color: var(--gold);
}
.btn-ghost {
    padding: .45rem .9rem; border-radius: 8px; font-family: 'Poppins', sans-serif;
    font-size: .78rem; font-weight: 600; border: 1px solid var(--border);
    background: transparent; color: var(--t2); cursor: pointer; transition: all .18s;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.btn-ghost:hover { background: var(--surface-solid); color: var(--t1); }
.btn-primary {
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border-color: transparent; color: #fff;
}
.btn-primary:hover { opacity: .9; }

/* ── PAGE ── */
.page { padding: 1.75rem; }

/* ── SECTION HEADER ── */
.sec-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: .9rem; }
.sec-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--t3);
    display: flex; align-items: center; gap: .5rem;
}
.sec-title::after { content: ''; flex: 0 0 24px; height: 1px; background: var(--border); }
.sec-badge {
    padding: .2rem .6rem; border-radius: 6px; font-size: .67rem; font-weight: 600;
    background: rgba(251,191,36,.1); border: 1px solid rgba(251,191,36,.18); color: var(--gold);
}

/* ── KPI STRIP ── */
.kpi-strip { display: grid; gap: .75rem; margin-bottom: 1.5rem; }
.kpi-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; padding: 1rem 1.1rem;
    display: flex; align-items: center; gap: .85rem;
    transition: border-color .18s;
}
.kpi-card:hover { border-color: var(--border-hi); }
.kpi-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.kpi-body { flex: 1; min-width: 0; }
.kpi-val { font-size: 1.1rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.kpi-lbl { font-size: .63rem; color: var(--t3); margin-top: 2px; }
.kpi-trend { font-size: .65rem; font-weight: 600; font-variant-numeric: tabular-nums; flex-shrink: 0; }

/* ── RANGE TABS ── */
.range-tabs { display: flex; gap: .3rem; }
.rtab {
    padding: .28rem .65rem; border-radius: 6px; font-size: .7rem; font-weight: 600;
    border: 1px solid var(--border); background: transparent; color: var(--t3);
    cursor: pointer; transition: all .15s; font-family: 'Poppins', sans-serif;
}
.rtab:hover { color: var(--t2); background: rgba(255,255,255,.04); }
.rtab.active { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.3); color: #93c5fd; }

/* ── CHARTS ── */
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; margin-bottom: 1.25rem; }
.chart-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; transition: border-color .18s;
}
.chart-card:hover { border-color: var(--border-hi); }
.chart-card.wide { grid-column: 1 / -1; }
.chart-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .85rem 1.1rem; border-bottom: 1px solid var(--border);
}
.chart-title { font-size: .8rem; font-weight: 600; display: flex; align-items: center; gap: .5rem; }
.ct-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.chart-meta { font-size: .65rem; color: var(--t3); }
.chart-body { padding: 1rem 1.1rem; }
.chart-wrap { position: relative; height: 180px; }

/* ── LOG FORM ── */
.log-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; margin-bottom: 1.25rem;
}
.log-card-head {
    padding: .85rem 1.1rem; border-bottom: 1px solid var(--border);
    font-size: .8rem; font-weight: 600;
}
.log-card-body { padding: 1.1rem; }
.form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: .7rem; margin-bottom: .85rem; }
.fg { display: flex; flex-direction: column; gap: .3rem; }
.fl { font-size: .62rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--t3); }
.fi {
    background: rgba(15,23,42,.6); border: 1px solid var(--border);
    border-radius: 7px; padding: .52rem .75rem;
    color: var(--t1); font-family: 'Poppins', sans-serif; font-size: .82rem;
    transition: border-color .15s; width: 100%;
}
.fi:focus { outline: none; border-color: rgba(59,130,246,.5); }
.fi::placeholder { color: var(--t3); }
.run-group { display: flex; gap: .35rem; align-items: center; }
.run-sep { color: var(--t3); font-size: .85rem; }
.form-footer { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.form-msg { font-size: .75rem; padding: .32rem .7rem; border-radius: 6px; display: none; }
.msg-ok  { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2); color: #6ee7b7; }
.msg-err { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.2);  color: #fca5a5; }

/* ── NOTE ── */
.note {
    display: flex; gap: .6rem; padding: .65rem .9rem; border-radius: 8px;
    margin-bottom: .9rem; font-size: .77rem; line-height: 1.55;
    background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.15); color: #7ab8ff;
}

/* ── TABLE ── */
.log-table-wrap {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden;
}
.log-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
.log-table th {
    font-size: .6rem; font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
    color: var(--t3); padding: .55rem .9rem; text-align: left;
    background: rgba(255,255,255,.02); border-bottom: 1px solid var(--border);
}
.log-table th:not(:first-child) { text-align: center; }
.log-table td {
    padding: .55rem .9rem; border-bottom: 1px solid rgba(255,255,255,.03); vertical-align: middle;
}
.log-table td:not(:first-child) { text-align: center; font-variant-numeric: tabular-nums; color: var(--t2); }
.log-table tbody tr:hover td { background: rgba(255,255,255,.02); }
.log-table tbody tr:last-child td { border-bottom: none; }
.log-date { font-weight: 600; font-size: .8rem; color: var(--t1); }
.log-date-sub { font-size: .65rem; color: var(--t3); margin-top: 1px; }
.del-btn {
    padding: .22rem .55rem; border-radius: 5px; font-size: .68rem; font-weight: 600;
    border: 1px solid rgba(239,68,68,.2); background: transparent; color: #fca5a5;
    cursor: pointer; transition: all .15s; font-family: 'Poppins', sans-serif;
}
.del-btn:hover { background: rgba(239,68,68,.1); }

/* ── ANIMATIONS ── */
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.fade { animation: fadeIn .35s ease both; }
.d1 { animation-delay: .04s; } .d2 { animation-delay: .09s; }
.d3 { animation-delay: .14s; } .d4 { animation-delay: .18s; }

/* ── MOBILE FAB ── */
.mobile-fab {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

/* ── PRINT / PDF ── */
@media print {
    .sidebar, .topbar, .log-card, .tb-right, .del-btn,
    .mobile-fab, .range-tabs, .no-print { display: none !important; }
    .main { margin-left: 0 !important; }
    .page { padding: 0 !important; }
    body { background: #fff !important; color: #000 !important; }
    .chart-card, .kpi-card, .log-table-wrap {
        border: 1px solid #e2e8f0 !important; background: #fff !important;
        box-shadow: none !important;
    }
    .kpi-val, .log-date, .chart-title { color: #1e293b !important; }
    .kpi-lbl, .chart-meta, .log-date-sub { color: #64748b !important; }
    .log-table td, .log-table th { color: #334155 !important; }
    .chart-wrap { height: 140px !important; }
    .charts-grid { grid-template-columns: 1fr 1fr !important; }
    .sec-title { color: #64748b !important; }
    .print-header { display: block !important; margin-bottom: 1.5rem; }
}
.print-header { display: none; }

@media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .page { padding: 1rem; }
    .topbar { padding: .8rem 1rem; }
    .mobile-fab { display: flex; align-items: center; justify-content: center; }
}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
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
                <p><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></p>
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
        <a href="progress.php" class="nav-item active">
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

<!-- ── MAIN ── -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <nav class="bc">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Progress</span>
        </nav>
        <div class="tb-right no-print">
            <span class="force-badge"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
            <?php if ($hasData): ?>
            <button class="btn-ghost" onclick="exportCSV()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                CSV
            </button>
            <button class="btn-ghost btn-primary" onclick="window.print()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                PDF
            </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">

        <!-- Print header (only visible when printing) -->
        <div class="print-header">
            <div style="font-size:1.1rem;font-weight:700;color:#1e293b">Gurkha Marga — Progress Report</div>
            <div style="font-size:.8rem;color:#64748b;margin-top:.2rem"><?= htmlspecialchars($user['full_name']) ?> &middot; <?= htmlspecialchars($forceName) ?> &middot; Generated <?= date('d M Y') ?></div>
        </div>

        <?php if ($hasData): ?>

        <!-- KPI STRIP — only fields that have data -->
        <?php
        $kpis = [];
        if ($curWeight)  $kpis[] = ['lbl'=>'Weight',   'val'=>$curWeight.' kg',    'color'=>'#fbbf24', 'bg'=>'rgba(251,191,36,.1)',   'trend'=>$tW];
        if ($curRun)     $kpis[] = ['lbl'=>'5K Run',   'val'=>fmtRun($curRun),     'color'=>'#93c5fd', 'bg'=>'rgba(59,130,246,.1)',   'trend'=>$tR];
        if ($curPullups) $kpis[] = ['lbl'=>'Pull-ups', 'val'=>$curPullups.' reps', 'color'=>'#6ee7b7', 'bg'=>'rgba(16,185,129,.1)',   'trend'=>$tPU];
        if ($curPushups) $kpis[] = ['lbl'=>'Push-ups', 'val'=>$curPushups.' reps', 'color'=>'#fb923c', 'bg'=>'rgba(249,115,22,.1)',   'trend'=>$tPS];
        if ($curSitups)  $kpis[] = ['lbl'=>'Sit-ups',  'val'=>$curSitups.' reps',  'color'=>'#c4b5fd', 'bg'=>'rgba(139,92,246,.1)',   'trend'=>$tSU];
        $cols = min(count($kpis), 5);
        ?>
        <?php if (!empty($kpis)): ?>
        <div class="fade d1">
            <div class="sec-header">
                <div class="sec-title">Current Metrics</div>
                <span class="sec-badge"><?= $totalLogs ?> session<?= $totalLogs !== 1 ? 's' : '' ?> logged</span>
            </div>
            <div class="kpi-strip" style="grid-template-columns:repeat(<?= $cols ?>,1fr)">
            <?php foreach ($kpis as $k): ?>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:<?= $k['bg'] ?>">
                        <svg width="16" height="16" fill="none" stroke="<?= $k['color'] ?>" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-val" style="color:<?= $k['color'] ?>"><?= $k['val'] ?></div>
                        <div class="kpi-lbl"><?= $k['lbl'] ?></div>
                    </div>
                    <?php if ($k['trend']): ?>
                    <div class="kpi-trend" style="color:<?= $k['trend']['good'] ? '#6ee7b7' : '#fca5a5' ?>">
                        <?= $k['trend']['dir'] ?><?= $k['trend']['pct'] ?>%
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ANALYTICS -->
        <?php if ($totalLogs >= 2): ?>
        <div class="fade d2">
            <div class="sec-header">
                <div class="sec-title">Analytics</div>
                <div class="range-tabs no-print">
                    <button class="rtab" onclick="setRange(7,this)">7D</button>
                    <button class="rtab" onclick="setRange(14,this)">14D</button>
                    <button class="rtab active" onclick="setRange(30,this)">30D</button>
                    <button class="rtab" onclick="setRange(90,this)">3M</button>
                    <button class="rtab" onclick="setRange(9999,this)">All</button>
                </div>
            </div>

            <div class="charts-grid">
                <?php if (array_filter($allWeight)): ?>
                <div class="chart-card">
                    <div class="chart-head">
                        <div class="chart-title"><span class="ct-dot" style="background:#fbbf24"></span>Weight</div>
                        <span class="chart-meta">kg over time</span>
                    </div>
                    <div class="chart-body"><div class="chart-wrap"><canvas id="chartWeight"></canvas></div></div>
                </div>
                <?php endif; ?>
                <?php if (array_filter($allRun)): ?>
                <div class="chart-card">
                    <div class="chart-head">
                        <div class="chart-title"><span class="ct-dot" style="background:#93c5fd"></span>5K Run</div>
                        <span class="chart-meta">minutes — lower is better</span>
                    </div>
                    <div class="chart-body"><div class="chart-wrap"><canvas id="chartRun"></canvas></div></div>
                </div>
                <?php endif; ?>
                <?php if (array_filter($allPullups)): ?>
                <div class="chart-card">
                    <div class="chart-head">
                        <div class="chart-title"><span class="ct-dot" style="background:#6ee7b7"></span>Pull-ups</div>
                        <span class="chart-meta">max reps</span>
                    </div>
                    <div class="chart-body"><div class="chart-wrap"><canvas id="chartPullups"></canvas></div></div>
                </div>
                <?php endif; ?>
                <?php if (array_filter($allPushups) || array_filter($allSitups)): ?>
                <div class="chart-card">
                    <div class="chart-head">
                        <div class="chart-title"><span class="ct-dot" style="background:#fb923c"></span>Push-ups &amp; Sit-ups</div>
                        <span class="chart-meta">reps per session</span>
                    </div>
                    <div class="chart-body"><div class="chart-wrap"><canvas id="chartStrength"></canvas></div></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SESSION TABLE -->
        <?php if (!empty($tableLogs)): ?>
        <div class="fade d3">
            <div class="sec-header">
                <div class="sec-title">Session History</div>
            </div>
            <div class="log-table-wrap">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Weight</th>
                            <th>5K Run</th>
                            <th>Pull-ups</th>
                            <th>Push-ups</th>
                            <th>Sit-ups</th>
                            <th>Chest</th>
                            <th>Notes</th>
                            <th class="no-print">Del</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($tableLogs, 0, 30) as $log): ?>
                    <tr id="lr-<?= $log['id'] ?>">
                        <td>
                            <div class="log-date"><?= date('d M Y', strtotime($log['logged_at'])) ?></div>
                            <div class="log-date-sub"><?= date('D', strtotime($log['logged_at'])) ?></div>
                        </td>
                        <td><?= $log['weight']     ? $log['weight'].' kg'   : '—' ?></td>
                        <td><?= $log['run_5k_sec'] ? fmtRun((int)$log['run_5k_sec']) : '—' ?></td>
                        <td><?= $log['pullups']    ?: '—' ?></td>
                        <td><?= $log['pushups']    ?: '—' ?></td>
                        <td><?= $log['situps']     ?: '—' ?></td>
                        <td><?= $log['chest_cm']   ? $log['chest_cm'].' cm' : '—' ?></td>
                        <td style="font-size:.72rem;color:var(--t3);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($log['notes'] ?: '—') ?>
                        </td>
                        <td class="no-print">
                            <button class="del-btn" onclick="delLog(<?= (int)$log['id'] ?>)">×</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // hasData ?>

        <!-- LOG FORM — always visible -->
        <div class="fade d4 no-print" style="margin-top:1.5rem">
            <div class="sec-header">
                <div class="sec-title">Log Session</div>
            </div>
            <div class="log-card">
                <div class="log-card-head">New Training Session</div>
                <div class="log-card-body">
                    <div class="note">Fill only what you measured today — blank fields are skipped.</div>
                    <form id="logForm" onsubmit="submitLog(event)">
                        <div class="form-grid">
                            <div class="fg">
                                <label class="fl">Weight (kg)</label>
                                <input class="fi" type="number" name="weight" step="0.1" min="30" max="200" placeholder="e.g. <?= $weight ?>">
                            </div>
                            <div class="fg">
                                <label class="fl">5K Run (min : sec)</label>
                                <div class="run-group">
                                    <input class="fi" type="number" name="run_5k_min" min="0" max="99" placeholder="mm" style="width:68px">
                                    <span class="run-sep">:</span>
                                    <input class="fi" type="number" name="run_5k_sec" min="0" max="59" placeholder="ss" style="width:68px">
                                </div>
                            </div>
                            <div class="fg">
                                <label class="fl">Pull-ups</label>
                                <input class="fi" type="number" name="pullups" min="0" max="100" placeholder="reps">
                            </div>
                            <div class="fg">
                                <label class="fl">Push-ups</label>
                                <input class="fi" type="number" name="pushups" min="0" max="300" placeholder="reps">
                            </div>
                            <div class="fg">
                                <label class="fl">Sit-ups</label>
                                <input class="fi" type="number" name="situps" min="0" max="300" placeholder="reps">
                            </div>
                            <div class="fg">
                                <label class="fl">Chest (cm)</label>
                                <input class="fi" type="number" name="chest_cm" step="0.1" min="50" max="130" placeholder="cm">
                            </div>
                            <div class="fg" style="grid-column:1/-1">
                                <label class="fl">Notes (optional)</label>
                                <input class="fi" type="text" name="notes" maxlength="255" placeholder="e.g. Morning run, felt strong on pull-ups...">
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn-ghost btn-primary" id="logBtn">Save Session</button>
                            <button type="button" class="btn-ghost" onclick="document.getElementById('logForm').reset()">Clear</button>
                            <div class="form-msg" id="logMsg"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /page -->
</div><!-- /main -->

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">&#9776;</button>

<script>
/* ── PHP → JS data ── */
const ALL_DATES   = <?= json_encode(array_values($allDates)) ?>;
const ALL_LABELS  = <?= json_encode(array_values($allLabels)) ?>;
const ALL_WEIGHT  = <?= json_encode(array_values($allWeight)) ?>;
const ALL_RUN     = <?= json_encode(array_values($allRun)) ?>;
const ALL_PULLUPS = <?= json_encode(array_values($allPullups)) ?>;
const ALL_PUSHUPS = <?= json_encode(array_values($allPushups)) ?>;
const ALL_SITUPS  = <?= json_encode(array_values($allSitups)) ?>;
const CSV_ROWS    = <?= json_encode($csvRows) ?>;
const HAS_CHARTS  = <?= ($totalLogs >= 2) ? 'true' : 'false' ?>;

/* ── Avatar (identical to nutrition.php) ── */
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

const AV_OPTIONS = {
    bg:[
        {id:'grad1',grad:['#1e3a5f','#2563eb']},{id:'grad2',grad:['#14532d','#16a34a']},
        {id:'grad3',grad:['#7f1d1d','#dc2626']},{id:'grad4',grad:['#312e81','#7c3aed']},
        {id:'grad5',grad:['#78350f','#d97706']},{id:'grad6',grad:['#134e4a','#0d9488']},
        {id:'grad7',grad:['#0f172a','#1e293b']},{id:'grad8',grad:['#4c0519','#e11d48']},
    ],
    skin:[
        {id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},
        {id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},
        {id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'},
    ],
    hairColor:[
        {id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},
        {id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},
        {id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},{id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'},
    ],
    eyes:[
        {id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},
        {id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},
        {id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'},
    ],
};

function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size/2, cy = size/2;
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)             || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o=>o.id===state.skin)          || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o=>o.id===state.eyes)          || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const hStyle = state.hairStyle || 'short';
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0,bg.grad[0]); grad.addColorStop(1,bg.grad[1]);
    ctx.fillStyle=grad; ctx.beginPath(); ctx.arc(cx,cy,cx,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]); ctx.fill();
    const shirt=ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle=shirt; ctx.beginPath(); ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle=hCol.color==='#F5F5F5'?'#C0A080':hCol.color;
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
    if(hStyle==='bald'){}
    else if(hStyle==='medium'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.ellipse(cx-44,cy+8,9,32,-0.2,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+44,cy+8,9,32,0.2,-Math.PI/2,Math.PI/2,true); ctx.fill();
    } else if(hStyle==='long'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.roundRect(cx-50,cy-10,12,75,6); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx+38,cy-10,12,75,6); ctx.fill();
    } else if(hStyle==='curly'){
        for(let a=0;a<Math.PI*2;a+=0.35){
            const rx=cx+Math.cos(a)*42, ry=(cy-30)+Math.sin(a)*24;
            if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}
        }
        ctx.beginPath(); ctx.ellipse(cx,cy-42,40,18,0,Math.PI,0); ctx.fill();
    } else if(hStyle==='bun'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,44,20,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-43,88,18);
        ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.fill();
    } else if(hStyle==='mohawk'){
        ctx.beginPath(); ctx.moveTo(cx-10,cy-40); ctx.lineTo(cx,cy-80); ctx.lineTo(cx+10,cy-40); ctx.closePath(); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-10,cy-50,20,14,2); ctx.fill();
    } else {
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
    if(acc.includes('sunglasses')){
        ctx.fillStyle='#111827'; ctx.strokeStyle='#374151'; ctx.lineWidth=2;
        ctx.beginPath(); ctx.roundRect(cx-34,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-7); ctx.lineTo(cx+8,cy-7); ctx.stroke();
    }
    const ring=ctx.createLinearGradient(0,0,size,size);
    ring.addColorStop(0,bg.grad[0]+'88'); ring.addColorStop(1,bg.grad[1]+'88');
    ctx.strokeStyle=ring; ctx.lineWidth=size<60?2:4;
    ctx.beginPath(); ctx.arc(cx,cy,cx-2,0,Math.PI*2); ctx.stroke();
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
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    container.appendChild(d);
}

/* ── Chart.js defaults ── */
Chart.defaults.color           = '#64748b';
Chart.defaults.borderColor     = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family     = "'Poppins',sans-serif";
Chart.defaults.font.size       = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,.97)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(255,255,255,.1)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 6;
Chart.defaults.plugins.tooltip.titleColor      = '#cbd5e1';
Chart.defaults.plugins.tooltip.bodyColor       = '#f8fafc';

let charts = {};

function filterByRange(range) {
    if (range >= 9999) return { labels:ALL_LABELS, weight:ALL_WEIGHT, run:ALL_RUN, pullups:ALL_PULLUPS, pushups:ALL_PUSHUPS, situps:ALL_SITUPS };
    const cutoff = new Date(); cutoff.setDate(cutoff.getDate() - range);
    const idxs = ALL_DATES.map((d,i)=>({d:new Date(d),i})).filter(x=>x.d>=cutoff).map(x=>x.i);
    return {
        labels:  idxs.map(i=>ALL_LABELS[i]),
        weight:  idxs.map(i=>ALL_WEIGHT[i]),
        run:     idxs.map(i=>ALL_RUN[i]),
        pullups: idxs.map(i=>ALL_PULLUPS[i]),
        pushups: idxs.map(i=>ALL_PUSHUPS[i]),
        situps:  idxs.map(i=>ALL_SITUPS[i]),
    };
}

function lineOpts(yMin, yMax) {
    return {
        responsive: true, maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        scales: {
            x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { maxTicksLimit: 7 } },
            y: { grid: { color: 'rgba(255,255,255,.04)' }, min: yMin, max: yMax, beginAtZero: false },
        },
        elements: { point: { radius: 3, hoverRadius: 6 } },
    };
}

function buildCharts(d) {
    ['weight','run','pullups','strength'].forEach(k => { if (charts[k]) { charts[k].destroy(); delete charts[k]; } });
    if (!HAS_CHARTS) return;

    const wVals  = d.weight.filter(Boolean);
    const rVals  = d.run.filter(Boolean);
    const puVals = d.pullups.filter(Boolean);

    const el = id => document.getElementById(id);

    if (el('chartWeight') && wVals.length >= 2) {
        charts.weight = new Chart(el('chartWeight'), {
            type: 'line',
            data: { labels: d.labels, datasets: [{ label: 'Weight (kg)', data: d.weight, borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,.08)', borderWidth: 2, pointBackgroundColor: '#fbbf24', tension: .35, fill: true }] },
            options: lineOpts(Math.min(...wVals)-2, Math.max(...wVals)+2),
        });
    }
    if (el('chartRun') && rVals.length >= 2) {
        charts.run = new Chart(el('chartRun'), {
            type: 'line',
            data: { labels: d.labels, datasets: [{ label: '5K Run (min)', data: d.run, borderColor: '#93c5fd', backgroundColor: 'rgba(147,197,253,.07)', borderWidth: 2, pointBackgroundColor: '#93c5fd', tension: .35, fill: true }] },
            options: lineOpts(Math.min(...rVals)-1, Math.max(...rVals)+1),
        });
    }
    if (el('chartPullups') && puVals.length >= 2) {
        charts.pullups = new Chart(el('chartPullups'), {
            type: 'line',
            data: { labels: d.labels, datasets: [{ label: 'Pull-ups', data: d.pullups, borderColor: '#6ee7b7', backgroundColor: 'rgba(110,231,183,.08)', borderWidth: 2, pointBackgroundColor: '#6ee7b7', tension: .35, fill: true }] },
            options: lineOpts(0, null),
        });
    }
    if (el('chartStrength')) {
        charts.strength = new Chart(el('chartStrength'), {
            type: 'bar',
            data: { labels: d.labels, datasets: [
                { label: 'Push-ups', data: d.pushups, backgroundColor: 'rgba(251,146,60,.35)', borderColor: '#fb923c', borderWidth: 1.5, borderRadius: 3, borderSkipped: false },
                { label: 'Sit-ups',  data: d.situps,  backgroundColor: 'rgba(196,181,253,.3)',  borderColor: '#c4b5fd', borderWidth: 1.5, borderRadius: 3, borderSkipped: false },
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { grid: { color: 'rgba(255,255,255,.04)' }, beginAtZero: true } },
                plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 8, padding: 12 } } },
            },
        });
    }
}

function setRange(range, btn) {
    document.querySelectorAll('.rtab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    buildCharts(filterByRange(range));
}

/* ── Log form ── */
async function submitLog(e) {
    e.preventDefault();
    const btn = document.getElementById('logBtn');
    const msg = document.getElementById('logMsg');
    btn.textContent = 'Saving…'; btn.disabled = true;
    const fd = new FormData(e.target);
    fd.append('action', 'log_progress');
    try {
        const r = await fetch('progress.php?action=log_progress', { method: 'POST', body: fd });
        const data = await r.json();
        msg.style.display = 'inline-flex';
        if (data.success) {
            msg.className = 'form-msg msg-ok'; msg.textContent = 'Saved — refreshing…';
            setTimeout(() => location.reload(), 1500);
        } else {
            msg.className = 'form-msg msg-err'; msg.textContent = 'Error: ' + (data.error || 'Save failed');
            btn.disabled = false; btn.textContent = 'Save Session';
        }
    } catch {
        msg.className = 'form-msg msg-err'; msg.textContent = 'Network error';
        msg.style.display = 'inline-flex'; btn.disabled = false; btn.textContent = 'Save Session';
    }
}

async function delLog(id) {
    if (!confirm('Delete this session?')) return;
    const fd = new FormData(); fd.append('action', 'delete_log'); fd.append('id', id);
    const r = await fetch('progress.php?action=delete_log', { method: 'POST', body: fd });
    const data = await r.json();
    if (data.success) {
        const row = document.getElementById('lr-' + id);
        if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(() => row.remove(), 300); }
    }
}

/* ── CSV export (data from PHP, no PHP inside JS loops) ── */
function exportCSV() {
    const header = ['Date','Weight(kg)','5K Run','Pull-ups','Push-ups','Sit-ups','Chest(cm)','Notes'];
    if (!CSV_ROWS.length) { alert('No data to export.'); return; }
    const rows = [header, ...CSV_ROWS];
    const csv  = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(new Blob([csv], { type: 'text/csv' })),
        download: 'progress_<?= date('Y-m-d') ?>.csv',
    });
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    if (HAS_CHARTS) buildCharts(filterByRange(30));
    document.addEventListener('click', e => {
        const sb  = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth <= 768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target)) {
            sb.classList.remove('active');
        }
    });
});
</script>
</body>
</html>