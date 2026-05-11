<?php
// frontend/users/motivation.php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    if (!empty($_GET['action'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit(); }
    header('Location: ../auth/login.php'); exit();
}

$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$forceMap = [
    'british'   => ['name' => 'British Army',           'unit' => 'Brigade of Gurkhas'],
    'nepal'     => ['name' => 'Nepal Army',              'unit' => 'Nepal Army Recruitment'],
    'indian'    => ['name' => 'Indian Army',             'unit' => 'Indian Gorkha Rifles'],
    'singapore' => ['name' => 'Singapore Police Force',  'unit' => 'SPF Gurkha Contingent'],
    'french'    => ['name' => 'French Foreign Legion',   'unit' => 'Legion Etrangere'],
];

$forceKey  = strtolower($user['target_force'] ?? 'british');
$forceData = $forceMap[$forceKey] ?? $forceMap['british'];
$forceName = $forceData['name'];
$forceUnit = $forceData['unit'];
$firstName = explode(' ', $user['full_name'])[0];
$initials  = strtoupper(substr($user['full_name'], 0, 1));
$userEmail = $user['email'] ?? '';

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

// Motivation row
$motRow = fetchOne("SELECT * FROM user_motivation WHERE user_id = ?", [$userId]);
if (!$motRow) {
    execute("INSERT INTO user_motivation (user_id, streak_days, last_visit, email_daily, total_visits) VALUES (?, 1, CURDATE(), 1, 1)", [$userId]);
    $motRow = ['streak_days' => 1, 'last_visit' => date('Y-m-d'), 'email_daily' => 1, 'total_visits' => 1];
}

$streak  = (int)$motRow['streak_days'];
$emailOn = (int)$motRow['email_daily'];
$visits  = (int)$motRow['total_visits'];

// AJAX actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'mark_visit') {
    header('Content-Type: application/json');
    $last = $motRow['last_visit'] ?? '';
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($last === $today) {
        echo json_encode(['streak' => $streak, 'visits' => $visits, 'already' => true]);
    } elseif ($last === $yesterday) {
        $streak++; $visits++;
        execute("UPDATE user_motivation SET streak_days=?, last_visit=?, total_visits=? WHERE user_id=?", [$streak, $today, $visits, $userId]);
        echo json_encode(['streak' => $streak, 'visits' => $visits, 'incremented' => true]);
    } else {
        $streak = 1; $visits++;
        execute("UPDATE user_motivation SET streak_days=1, last_visit=?, total_visits=? WHERE user_id=?", [$today, $visits, $userId]);
        echo json_encode(['streak' => 1, 'visits' => $visits, 'reset' => true]);
    }
    exit();
}

if ($action === 'toggle_email') {
    header('Content-Type: application/json');
    $newVal = $emailOn ? 0 : 1;
    execute("UPDATE user_motivation SET email_daily=? WHERE user_id=?", [$newVal, $userId]);
    echo json_encode(['email_daily' => $newVal]);
    exit();
}

if ($action === 'send_test_email') {
    header('Content-Type: application/json');
    $dayIdx = (int)date('z');
    $testQuotes = [
        "The mountain does not care if you are tired. Neither does the finish line.",
        "A Gurkha does not ask if the task is possible. He asks only when it must be done.",
        "Discipline today is the freedom you earn tomorrow.",
        "Your competition is not another man. Your competition is who you were yesterday.",
        "Pain is temporary. Passing selection is permanent.",
    ];
    $quote   = $testQuotes[$dayIdx % count($testQuotes)];
    $subject = "Your Daily Gurkha Marga Motivation — " . date('d F Y');
    $body    = "Namaste {$firstName},\n\n\"{$quote}\"\n\nStreak: {$streak} days.\n\n— Gurkha Marga Team";
    $sent    = @mail($userEmail, $subject, $body, "From: noreply@gurkhamarga.com\r\nContent-Type: text/plain; charset=UTF-8");
    echo json_encode(['sent' => $sent, 'to' => $userEmail, 'quote' => $quote]);
    exit();
}

$dayOfYear = (int)date('z');
$milestones = [7 => 'One Week', 21 => 'Habit Formed', 30 => 'One Month', 66 => 'Automatic', 100 => 'Century', 200 => 'Iron Mind', 365 => 'Full Year'];
$nextMilestone     = null;
$nextMilestoneDays = 0;
foreach ($milestones as $days => $label) {
    if ($streak < $days) { $nextMilestone = $label; $nextMilestoneDays = $days - $streak; break; }
}
$earnedMilestone = null;
foreach (array_reverse($milestones, true) as $days => $label) {
    if ($streak >= $days) { $earnedMilestone = $label; break; }
}
$pct = min(100, round(($streak / 365) * 100));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Motivation — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:  #3b82f6;
    --gold:    #fbbf24;
    --success: #10b981;
    --error:   #ef4444;
    --warning: #f59e0b;
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
    background: var(--surface); backdrop-filter: blur(20px);
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
.btn-ghost.on { border-color: rgba(16,185,129,.35); color: #6ee7b7; background: rgba(16,185,129,.06); }

/* ── PAGE ── */
.page { padding: 1.75rem; max-width: 900px; }

/* ── SECTION HEADERS ── */
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

/* ── QUOTE CARD ── */
.quote-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 2rem 2rem 1.5rem;
    margin-bottom: 1.5rem; position: relative; overflow: hidden;
    border-left: 2.5px solid var(--accent);
}
.quote-meta {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.25rem; gap: 1rem;
}
.quote-day-label {
    font-size: .65rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
    color: var(--t3); display: flex; align-items: center; gap: .5rem;
}
.quote-day-label span {
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.2);
    color: #93c5fd; padding: .15rem .55rem; border-radius: 5px; font-size: .67rem;
}
.quote-nav { display: flex; gap: .4rem; }
.quote-nav-btn {
    width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--border);
    background: transparent; color: var(--t3); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s; font-size: .7rem;
}
.quote-nav-btn:hover { background: var(--surface-solid); color: var(--t1); border-color: var(--border-hi); }
.quote-nav-btn svg { width: 12px; height: 12px; stroke: currentColor; fill: none; }
.quote-text {
    font-size: 1.05rem; font-weight: 600; color: var(--t1); line-height: 1.65;
    margin-bottom: .75rem;
    opacity: 0; transform: translateY(6px);
    transition: opacity .4s ease, transform .4s ease;
}
.quote-text.visible { opacity: 1; transform: none; }
.quote-source {
    font-size: .72rem; color: var(--t3); font-weight: 500;
}
.quote-actions { display: flex; gap: .5rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.commit-btn {
    padding: .5rem 1.25rem; border-radius: 8px; font-family: 'Poppins', sans-serif;
    font-size: .78rem; font-weight: 600; border: none;
    background: linear-gradient(135deg, var(--accent), #6366f1);
    color: #fff; cursor: pointer; transition: all .2s;
    display: inline-flex; align-items: center; gap: 6px;
}
.commit-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59,130,246,.35); }
.commit-btn:active { transform: none; }
.commit-btn.done { background: linear-gradient(135deg, var(--success), #059669); }
.copy-btn {
    padding: .5rem .9rem; border-radius: 8px; font-family: 'Poppins', sans-serif;
    font-size: .78rem; font-weight: 600; border: 1px solid var(--border);
    background: transparent; color: var(--t2); cursor: pointer; transition: all .15s;
}
.copy-btn:hover { background: var(--surface-solid); color: var(--t1); }

/* ── STREAK ROW ── */
.streak-banner {
    display: flex; align-items: stretch; gap: 0;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; margin-bottom: 1.5rem;
}
.streak-main {
    padding: 1.25rem 1.75rem; border-right: 1px solid var(--border);
    display: flex; align-items: center; gap: 1rem; flex-shrink: 0;
}
.streak-number { font-size: 2.5rem; font-weight: 800; line-height: 1; color: var(--t1); }
.streak-label  { font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--t3); margin-top: 4px; }
.streak-prog-col { flex: 1; padding: 1.25rem 1.75rem; display: flex; flex-direction: column; justify-content: center; gap: .6rem; }
.prog-bar-wrap { display: flex; align-items: center; gap: .75rem; }
.prog-label { font-size: .68rem; color: var(--t3); white-space: nowrap; width: 90px; }
.prog-bar { flex: 1; height: 4px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; }
.prog-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--success), #34d399); transition: width .8s ease; }
.prog-count { font-size: .68rem; font-weight: 600; color: var(--t3); white-space: nowrap; }
.streak-next {
    font-size: .72rem; color: var(--t2);
}
.streak-next strong { color: #93c5fd; }
.streak-stats { display: flex; border-left: 1px solid var(--border); flex-shrink: 0; }
.streak-stat {
    padding: 1.25rem 1.25rem; text-align: center; border-right: 1px solid var(--border);
}
.streak-stat:last-child { border-right: none; }
.streak-stat-num   { font-size: 1.25rem; font-weight: 700; line-height: 1; }
.streak-stat-label { font-size: .6rem; color: var(--t3); margin-top: 4px; text-transform: uppercase; letter-spacing: .07em; }

/* Milestone pills */
.ms-row { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; }
.ms-pill {
    font-size: .65rem; font-weight: 600; padding: .18rem .55rem; border-radius: 5px;
    border: 1px solid var(--border); color: var(--t3);
}
.ms-pill.earned { border-color: rgba(251,191,36,.3); color: var(--gold); background: rgba(251,191,36,.06); }

/* ── DISCIPLINE GRID ── */
.discipline-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: .45rem; margin-bottom: 1.5rem;
}
.disc-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 1rem 1.1rem;
    cursor: pointer; position: relative; overflow: hidden;
    transition: border-color .18s; min-height: 90px;
    display: flex; flex-direction: column; justify-content: space-between;
}
.disc-card:hover { border-color: var(--border-hi); }
.disc-card.open  { border-left: 2.5px solid var(--accent); }
.disc-front { display: flex; flex-direction: column; gap: .3rem; }
.disc-num   { font-size: .6rem; font-weight: 600; color: var(--t3); letter-spacing: .1em; }
.disc-title { font-size: .86rem; font-weight: 600; color: var(--t1); line-height: 1.3; }
.disc-hint  { font-size: .65rem; color: var(--t3); margin-top: .5rem; }
.disc-back  {
    display: none; font-size: .77rem; color: var(--t2); line-height: 1.6; margin-top: .5rem;
}
.disc-card.open .disc-back  { display: block; }
.disc-card.open .disc-hint  { display: none; }

/* ── RECORDS TABLE ── */
.records-list { display: flex; flex-direction: column; gap: .45rem; margin-bottom: 1.5rem; }
.record-row {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; overflow: hidden; transition: border-color .18s;
    border-left: 2.5px solid transparent;
}
.record-row:hover { border-color: var(--border-hi); }
.record-row.active-row { border-left-color: var(--accent); }
.record-header {
    display: flex; align-items: center; gap: .85rem;
    padding: .82rem 1.1rem; cursor: pointer; user-select: none;
}
.record-header:hover { background: rgba(255,255,255,.02); }
.record-num {
    width: 26px; height: 26px; border-radius: 6px; flex-shrink: 0;
    background: rgba(59,130,246,.1); display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #93c5fd;
}
.record-name { flex: 1; font-size: .88rem; font-weight: 600; color: var(--t1); }
.record-unit { font-size: .7rem; color: var(--t3); font-weight: 500; }
.record-badge {
    font-size: .65rem; font-weight: 600; padding: .18rem .55rem; border-radius: 5px; white-space: nowrap;
    background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.2); color: #fca5a5;
}
.record-chevron {
    width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
    color: var(--t3); transition: transform .22s, color .18s; flex-shrink: 0;
}
.record-chevron svg { width: 10px; height: 10px; stroke: currentColor; fill: none; }
.record-row.active-row .record-chevron { transform: rotate(180deg); color: var(--accent); }
.record-body {
    display: none; border-top: 1px solid var(--border);
    background: rgba(15,23,42,.4); padding: .85rem 1.1rem 1rem;
    font-size: .8rem; color: var(--t2); line-height: 1.65; font-style: italic;
}
.record-row.active-row .record-body { display: block; }

/* ── EMAIL SECTION ── */
.email-row {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; padding: 1.25rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
    gap: 1.5rem; margin-bottom: 1.5rem;
}
.email-info-title { font-size: .88rem; font-weight: 600; color: var(--t1); margin-bottom: .25rem; }
.email-info-sub   { font-size: .75rem; color: var(--t3); line-height: 1.55; }
.email-controls   { display: flex; gap: .5rem; flex-shrink: 0; }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.fade { animation: fadeUp .35s ease both; }
.d1 { animation-delay: .04s; }
.d2 { animation-delay: .09s; }
.d3 { animation-delay: .14s; }
.d4 { animation-delay: .19s; }

/* ── MOBILE ── */
.mobile-fab {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
    align-items: center; justify-content: center;
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .page { padding: 1rem; }
    .topbar { padding: .8rem 1rem; }
    .mobile-fab { display: flex; }
    .streak-banner { flex-direction: column; }
    .streak-main { border-right: none; border-bottom: 1px solid var(--border); }
    .streak-stats { border-left: none; border-top: 1px solid var(--border); }
    .streak-stat { flex: 1; }
    .discipline-grid { grid-template-columns: 1fr 1fr; }
    .email-row { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .discipline-grid { grid-template-columns: 1fr; }
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
                <p><?= htmlspecialchars($forceName) ?></p>
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
        <a href="motivation.php" class="nav-item active">
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
    <header class="topbar">
        <nav class="bc">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Motivation</span>
        </nav>
        <div class="tb-right">
            <span class="force-badge"><?= htmlspecialchars($forceName) ?></span>
            <button class="btn-ghost <?= $emailOn ? 'on' : '' ?>" id="emailToggleBtn" onclick="toggleEmail()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <?= $emailOn ? 'Email On' : 'Email Off' ?>
            </button>
        </div>
    </header>

    <div class="page">

        <!-- Daily Quote -->
        <div class="sec-header fade d1">
            <div class="sec-title">Daily Quote</div>
            <span class="sec-badge">Day <?= $dayOfYear + 1 ?> / 365</span>
        </div>

        <div class="quote-card fade d2">
            <div class="quote-meta">
                <div class="quote-day-label">
                    <?= date('l, d F Y') ?>
                    <span><?= htmlspecialchars($forceUnit) ?></span>
                </div>
                <div class="quote-nav">
                    <button class="quote-nav-btn" onclick="prevQuote()" title="Previous">
                        <svg viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <button class="quote-nav-btn" onclick="nextQuote()" title="Next">
                        <svg viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>
            <div class="quote-text" id="quoteText">Loading...</div>
            <div class="quote-source" id="quoteSource"></div>
            <div class="quote-actions">
                <button class="commit-btn" id="commitBtn" onclick="commitToday()">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    Mark Today Done
                </button>
                <button class="copy-btn" onclick="copyQuote()">Copy Quote</button>
            </div>
        </div>

        <!-- Streak -->
        <div class="sec-header fade d2">
            <div class="sec-title">Discipline Streak</div>
            <?php if ($earnedMilestone): ?>
            <span class="sec-badge"><?= htmlspecialchars($earnedMilestone) ?></span>
            <?php endif; ?>
        </div>

        <div class="streak-banner fade d3">
            <div class="streak-main">
                <div>
                    <div class="streak-number" id="streakNum"><?= $streak ?></div>
                    <div class="streak-label">Day streak</div>
                </div>
            </div>
            <div class="streak-prog-col">
                <div class="prog-bar-wrap">
                    <span class="prog-label">To 365 days</span>
                    <div class="prog-bar"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
                    <span class="prog-count"><?= $pct ?>%</span>
                </div>
                <?php if ($nextMilestone): ?>
                <div class="streak-next">
                    Next milestone: <strong><?= htmlspecialchars($nextMilestone) ?></strong> — <?= $nextMilestoneDays ?> days away
                </div>
                <?php endif; ?>
                <div class="ms-row">
                    <?php foreach ($milestones as $d => $lbl): ?>
                    <span class="ms-pill <?= $streak >= $d ? 'earned' : '' ?>"><?= $d ?>d</span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="streak-stats">
                <div class="streak-stat">
                    <div class="streak-stat-num" style="color:#6ee7b7"><?= $visits ?></div>
                    <div class="streak-stat-label">Visits</div>
                </div>
                <div class="streak-stat">
                    <div class="streak-stat-num" style="color:#93c5fd"><?= $dayOfYear + 1 ?></div>
                    <div class="streak-stat-label">Day <?= date('Y') ?></div>
                </div>
            </div>
        </div>

        <!-- Discipline principles -->
        <div class="sec-header fade d3">
            <div class="sec-title">Discipline Principles</div>
            <span class="sec-badge">16 principles</span>
        </div>

        <div class="discipline-grid fade d4" id="disciplineGrid"></div>

        <!-- Email section -->
        <div class="sec-header fade d4">
            <div class="sec-title">Daily Email</div>
        </div>

        <div class="email-row fade d4">
            <div>
                <div class="email-info-title">One quote, every morning</div>
                <div class="email-info-sub">
                    Sending to <strong style="color:var(--t2)"><?= htmlspecialchars($userEmail) ?></strong> —
                    <?= $emailOn ? 'active' : 'currently paused' ?>
                </div>
            </div>
            <div class="email-controls">
                <button class="btn-ghost <?= $emailOn ? 'on' : '' ?>" id="emailMainBtn" onclick="toggleEmail()">
                    <?= $emailOn ? 'Pause' : 'Enable' ?>
                </button>
                <button class="btn-ghost" onclick="sendTestEmail(this)">Send Today</button>
            </div>
        </div>

    </div>
</div>

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">
    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>

<script>
const SAVED_AVATAR_TYPE = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL   = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CFG  = <?= $avatarConfigJson ?>;
const USER_INITIAL      = <?= json_encode($initials) ?>;
const DAY_OF_YEAR       = <?= $dayOfYear ?>;
let   emailOn           = <?= $emailOn ?>;
const USER_EMAIL        = <?= json_encode($userEmail) ?>;

// ── Avatar ────────────────────────────────────────────────────────────────
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
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)              || AV_OPTIONS.bg[0];
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
        for(let a=0;a<Math.PI*2;a+=0.35){const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24;if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}}
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
    const c = document.getElementById('sbAvContainer'); if (!c) return; c.innerHTML = '';
    if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
        const img = document.createElement('img');
        img.src = SAVED_PHOTO_URL;
        img.style.cssText = 'width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror = () => renderInitialSb(c);
        c.appendChild(img);
    } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CFG) {
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CFG, 38);
    } else { renderInitialSb(c); }
}
function renderInitialSb(c) {
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    c.appendChild(d);
}

// ── Quotes ────────────────────────────────────────────────────────────────
const QUOTES = [
["The mountain does not care if you are tired. Neither does the finish line.","Gurkha Proverb"],
["A Gurkha does not ask if the task is possible. He asks only when it must be done.","Brigade of Gurkhas"],
["Discipline today is the freedom you earn tomorrow.","Military Maxim"],
["Your competition is not another man. Your competition is who you were yesterday.","Warrior Philosophy"],
["Pain is temporary. Passing selection is permanent.","Selection Camp Wisdom"],
["Every sunrise is a battle order. Will you obey it?","Morning Creed"],
["Iron sharpens iron. Train harder than the standard demands.","Proverbs 27:17"],
["A warrior is not defined by how many times he stands. He is defined by how many times he rises.","Ancient Martial Code"],
["The uniform is earned on the training ground, not on selection day.","Gurkha Instructor"],
["Sweat now. Bleed never.","Military Training Doctrine"],
["The man who rises at 5 AM has already won half the battle.","Sergeant Major's Wisdom"],
["Fear is a liar. Your body can do far more than your mind believes.","Special Forces Manual"],
["You do not get what you wish for. You get what you work for.","Training Ground Truth"],
["The standards do not lower for anyone. You must rise to meet them.","British Army Tradition"],
["Ayo Gorkhali — and with that cry, mountains have trembled.","Gurkha Battle Cry"],
["Champions are made in the quiet sessions no one sees.","Strength Coach Maxim"],
["Run when you are tired. That is when training begins.","Endurance Coach"],
["You are not tired. You are uncomfortable. There is a difference.","SAS Selection Instructor"],
["The enemy you will face has also been training. Train harder.","Intelligence Briefing"],
["A Gurkha's word is his contract. Your commitment today is your contract.","Gurkha Officers Mess"],
["Do not pray for easy battles. Pray to be a stronger fighter.","West Point Cadet Prayer"],
["Your ancestors ran barefoot through mountains. You have no excuse.","Nepali Hill Training Ethos"],
["The mind breaks first. Train it before you train the body.","Combat Psychology"],
["Run the route when it is raining. You will never fear rain again.","All-Weather Training"],
["Hard training, easy battle. Easy training, hard battle.","Suvorov's Maxim"],
["What you do in the darkness will be revealed in the light of selection day.","Training Principle"],
["Character is who you are when no one is watching.","John Wooden"],
["You are not behind. You are exactly where your effort has placed you.","Honest Reckoning"],
["A soldier's greatest enemy is not the enemy. It is complacency.","Military Philosophy"],
["The standard you walk past is the standard you accept.","Australian Army Chief"],
["Be the soldier who does the extra mile when no one assigns it.","Initiative Doctrine"],
["The only way to get fitter is to show up, every day.","Training Axiom"],
["A bad day of training is infinitely better than no day of training.","Minimum Effective Dose"],
["Your future self is watching your choices today. Make them proud.","Time Perspective"],
["Silence your doubts the only way that works — with action.","Action Over Anxiety"],
["The difference between a soldier and a civilian is the willingness to suffer on purpose.","Endurance Philosophy"],
["When your legs say stop, your history says continue.","Legacy Motivation"],
["You have survived 100% of your hardest days so far.","Resilience Reminder"],
["A man who masters himself can master any terrain.","Sun Tzu"],
["Your pace in training sets the ceiling for your pace in selection.","Specificity Principle"],
["Rise before the city wakes. Own the morning. Own the day.","Early Rise Creed"],
["Strength does not shout. It endures quietly and arrives on time.","The Silent Warrior"],
["Do not count the miles you have run. Count the days you did not stop.","Consistency Over Distance"],
["Commitment is doing what you said long after the mood that inspired it has left.","Darren Hardy"],
["Your selection day is a single day. Your preparation is every other day.","Preparation Arithmetic"],
["The best preparation for tomorrow is the complete execution of today.","Daily Excellence"],
["A warrior eats to fuel, not to comfort.","Nutritional Purpose"],
["Do not let a good day soften a great week. Maintain.","Complacency Warning"],
["Discipline is remembering what you want most, over what you want now.","Discipline Definition"],
["Train until you cannot get it wrong.","Mastery Standard"],
["Your body will give what your mind insists upon.","Mind Command"],
["The last month of preparation is the most important month. Treat it accordingly.","Final Month Gravity"],
["What you believe about yourself under pressure is what you will perform.","Belief Performance Link"],
["The only question selection asks is: are you ready? Answer with your training record.","Training as Answer"],
["365 days. A warrior's year. Now begin again — stronger, wiser, and already ahead.","Year Complete"],
];

let currentIdx = DAY_OF_YEAR;

function setQuote(idx) {
    const q  = QUOTES[idx % QUOTES.length];
    const el = document.getElementById('quoteText');
    const sr = document.getElementById('quoteSource');
    el.classList.remove('visible');
    setTimeout(() => {
        el.textContent = q[0];
        sr.textContent = q[1];
        el.classList.add('visible');
    }, 200);
}
function nextQuote() { currentIdx = (currentIdx + 1) % QUOTES.length; setQuote(currentIdx); }
function prevQuote() { currentIdx = (currentIdx - 1 + QUOTES.length) % QUOTES.length; setQuote(currentIdx); }

function copyQuote() {
    const text = document.getElementById('quoteText').textContent;
    navigator.clipboard.writeText('"' + text + '"').then(() => {
        const btn = document.querySelector('.copy-btn');
        const orig = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

// ── Commit / mark visit ───────────────────────────────────────────────────
function commitToday() {
    const btn = document.getElementById('commitBtn');
    btn.disabled = true;
    fetch('motivation.php?action=mark_visit', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.streak) document.getElementById('streakNum').textContent = d.streak;
            btn.classList.add('done');
            btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> Done for today';
        })
        .catch(() => { btn.disabled = false; });
}

// ── Discipline cards ──────────────────────────────────────────────────────
const disciplineData = [
    ['Train Daily',       'Soldiers who train daily outperform those who train hard occasionally. Daily wins the long game.'],
    ['Eat to Fuel',       'Food is ammunition. Treat it with the respect of a soldier and his kit.'],
    ['Sleep 8 Hours',     'Sleep is when your body repairs training damage and emerges stronger. Guard it.'],
    ['Own the Dawn',      '5 AM belongs to no one unless you claim it. Warriors who shaped history woke before the world.'],
    ['No Excuses',        'An excuse is a contract with failure. Tear it up. Do the work.'],
    ['Run Hills',         'Flat training produces flat soldiers. Seek gradients. The mountain is the point.'],
    ['Track Progress',    'What gets measured gets managed. Log every session. Watch the line trend upward.'],
    ['Drink Water',       '3 to 4 litres daily. More on training days. Dehydration is sabotage.'],
    ['Limit Screens',     'The recruiter evaluates your body and your character. Social media builds neither.'],
    ['Breathe Deep',      'Controlled breathing under stress is a trainable skill. Practice it daily.'],
    ['Speak Less',        'A warrior with quiet confidence is far more threatening than one who announces himself.'],
    ['Know the Standard', 'Know the exact standards for your target force. Train 20% above them.'],
    ['Discipline Over Mood','You will never be in the mood for the hard thing. Do it regardless.'],
    ['Review Weekly',     'Every Sunday: what did you achieve? What did you miss? What will you do differently?'],
    ['Cold Showers',      'Training under discomfort builds tolerance to discomfort. Start every morning with cold water.'],
    ['Chase the Standard','Not the spotlight. Not the approval. The standard that earns the uniform.'],
];

(function() {
    const grid = document.getElementById('disciplineGrid');
    disciplineData.forEach(([title, back], i) => {
        const card = document.createElement('div');
        card.className = 'disc-card';
        card.innerHTML = `
            <div class="disc-front">
                <div class="disc-num">${String(i + 1).padStart(2, '0')}</div>
                <div class="disc-title">${title}</div>
            </div>
            <div class="disc-hint">Tap to expand</div>
            <div class="disc-back">${back}</div>`;
        card.addEventListener('click', () => card.classList.toggle('open'));
        grid.appendChild(card);
    });
})();

// ── Records accordion ─────────────────────────────────────────────────────
function toggleRecord(i) {
    const row = document.getElementById('rec-' + i);
    if (!row) return;
    const wasOpen = row.classList.contains('active-row');
    document.querySelectorAll('.record-row').forEach(r => r.classList.remove('active-row'));
    if (!wasOpen) row.classList.add('active-row');
}

// ── Email ─────────────────────────────────────────────────────────────────
async function toggleEmail() {
    const resp = await fetch('motivation.php?action=toggle_email', { method: 'POST' });
    const data = await resp.json();
    emailOn = data.email_daily;
    ['emailToggleBtn', 'emailMainBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        if (id === 'emailToggleBtn') {
            btn.className = 'btn-ghost' + (emailOn ? ' on' : '');
            btn.innerHTML = (emailOn
                ? '<svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>'
                : '<svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>')
                + (emailOn ? ' Email On' : ' Email Off');
        } else {
            btn.className = 'btn-ghost' + (emailOn ? ' on' : '');
            btn.textContent = emailOn ? 'Pause' : 'Enable';
        }
    });
}

async function sendTestEmail(btn) {
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Sending...';
    try {
        const resp = await fetch('motivation.php?action=send_test_email', { method: 'POST' });
        const data = await resp.json();
        btn.textContent = data.sent ? 'Sent' : 'Failed';
        setTimeout(() => { btn.disabled = false; btn.textContent = orig; }, 3000);
    } catch(e) { btn.textContent = 'Error'; btn.disabled = false; }
}

// ── Boot ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    setQuote(DAY_OF_YEAR);
    setTimeout(() => {
        const bar = document.getElementById('progFill');
        if (bar) bar.style.width = Math.min(100, (<?= $streak ?> / 365 * 100)) + '%';
    }, 300);

    document.addEventListener('click', e => {
        const sb  = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth <= 768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target))
            sb.classList.remove('active');
    });
});
</script>
</body>
</html>