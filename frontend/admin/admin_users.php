<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_users.php — User Management (view, enable/disable, delete only)
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

// ── Flash helpers (inline so no admin_layout dependency needed) ────────────
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $msg): void {
        $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
    }
}
if (!function_exists('getFlash')) {
    function getFlash(): ?array {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}
if (!function_exists('redirectTo')) {
    function redirectTo(string $page): void {
        header('Location: ' . $page);
        exit();
    }
}

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ── Actions ────────────────────────────────────────────────────────────────
if ($action === 'delete' && $editId) {
    try {
        query("DELETE FROM users WHERE id=?", [$editId]);
        setFlash('success', 'User deleted successfully.');
    } catch (Exception $e) {
        setFlash('error', 'Delete failed.');
    }
    redirectTo('admin_users.php');
}

if ($action === 'toggle' && $editId) {
    try {
        query("UPDATE users SET is_active=NOT is_active WHERE id=?", [$editId]);
        setFlash('success', 'User status updated.');
    } catch (Exception $e) {
        setFlash('error', 'Status update failed.');
    }
    redirectTo('admin_users.php');
}

// ── Query ──────────────────────────────────────────────────────────────────
$search  = $_GET['search'] ?? '';
$uFilter = $_GET['filter'] ?? '';
$uPage   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset  = ($uPage - 1) * $perPage;

$where  = ['1=1']; $params = [];
if ($search) {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($uFilter === 'active')        { $where[] = 'is_active=1'; }
elseif ($uFilter === 'inactive')  { $where[] = 'is_active=0'; }
elseif (in_array($uFilter, ['british','nepal','indian','singapore','french'])) {
    $where[] = 'target_force=?'; $params[] = $uFilter;
}
$whereSQL   = implode(' AND ', $where);
$usersTotal = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE $whereSQL", $params)['c'] ?? 0);
$usersList  = fetchAll("SELECT * FROM users WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
$usersPages = (int)ceil($usersTotal / $perPage);

$forceNames = ['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police','french'=>'French Legion'];
$forceFlags = ['british'=>'🇬🇧','nepal'=>'🇳🇵','indian'=>'🇮🇳','singapore'=>'🇸🇬','french'=>'🇫🇷'];

// Quick stats
$activeCount   = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=1")['c'] ?? 0);
$inactiveCount = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=0")['c'] ?? 0);
$todayCount    = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE DATE(created_at)=CURDATE()")['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — Gurkha Marga Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:         #3b82f6;
    --gold:           #fbbf24;
    --success:        #10b981;
    --error:          #ef4444;
    --text-primary:   #f8fafc;
    --text-secondary: #cbd5e1;
    --text-muted:     #64748b;
    --sidebar-bg:     rgba(15, 23, 42, 0.97);
    --card-bg:        rgba(30, 41, 59, 0.8);
    --hover-bg:       rgba(59, 130, 246, 0.08);
    --border:         rgba(255, 255, 255, 0.07);
    --border-hi:      rgba(255, 255, 255, 0.12);
    --red-soft:       rgba(239, 68, 68, 0.08);
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
.admin-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: .5rem .85rem; border-radius: 10px;
    background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2);
    font-size: .75rem; font-weight: 600; color: var(--gold);
    width: 100%;
}
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
.nav-item.logout:hover { background: var(--red-soft); border-left-color: #ef4444; }

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
.topbar-sub  { font-size: .75rem; color: var(--text-muted); margin-bottom: .2rem; letter-spacing: .04em; }
.topbar-title{ font-size: 1.3rem; font-weight: 700; }
.topbar-date { font-size: .75rem; color: var(--text-secondary); margin-top: .2rem; }

/* ── FLASH ── */
.flash {
    padding: .85rem 1.2rem; border-radius: 10px; margin-bottom: 1.25rem;
    font-size: .82rem; font-weight: 500; display: flex; align-items: center; gap: .6rem;
}
.flash.success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.25); color: #6ee7b7; }
.flash.error   { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.25);  color: #fca5a5; }

/* ── STAT CARDS ── */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
.stat-card {
    background: var(--card-bg); border-radius: 14px; padding: 1.1rem 1.3rem;
    border: 1px solid var(--border); backdrop-filter: blur(12px);
    animation: fadeIn .35s ease both;
}
.stat-card:nth-child(1) { animation-delay: .04s; }
.stat-card:nth-child(2) { animation-delay: .08s; }
.stat-card:nth-child(3) { animation-delay: .12s; }
.stat-card:nth-child(4) { animation-delay: .16s; }
.stat-label { font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: .5rem; }
.stat-value { font-size: 1.8rem; font-weight: 800; line-height: 1; }
.stat-value.blue   { color: #93c5fd; }
.stat-value.green  { color: #6ee7b7; }
.stat-value.red    { color: #fca5a5; }
.stat-value.gold   { color: var(--gold); }
.stat-sub { font-size: .68rem; color: var(--text-muted); margin-top: .3rem; }

/* ── FILTER BAR ── */
.filter-bar {
    background: var(--card-bg); border-radius: 12px;
    padding: .85rem 1.2rem; margin-bottom: 1rem;
    border: 1px solid var(--border); display: flex; align-items: center;
    gap: .65rem; flex-wrap: wrap;
}
.filter-bar input[type="text"],
.filter-bar select {
    background: rgba(15,23,42,0.6); border: 1px solid var(--border);
    color: var(--text-primary); border-radius: 8px; padding: .45rem .85rem;
    font-size: .8rem; font-family: 'Poppins', sans-serif;
    outline: none; transition: border-color .2s;
}
.filter-bar input[type="text"]:focus,
.filter-bar select:focus { border-color: rgba(59,130,246,.4); }
.filter-bar select option { background: #1e293b; }
.btn {
    padding: .5rem 1.1rem; border: none; border-radius: 8px;
    font-weight: 600; cursor: pointer; transition: all .2s;
    font-family: 'Poppins', sans-serif; display: inline-flex;
    align-items: center; gap: 7px; font-size: .8rem; text-decoration: none;
}
.btn-ghost {
    background: transparent; border: 1px solid var(--border);
    color: var(--text-secondary);
}
.btn-ghost:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }
.btn-clear {
    background: transparent; border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5; padding: .45rem .85rem;
}
.btn-clear:hover { background: var(--red-soft); }
.results-count { margin-left: auto; font-size: .72rem; color: var(--text-muted); font-variant-numeric: tabular-nums; }

/* ── TABLE CARD ── */
.card {
    background: var(--card-bg); backdrop-filter: blur(12px);
    border-radius: 14px; padding: 0;
    border: 1px solid var(--border); overflow: hidden;
    animation: fadeIn .35s ease .2s both;
}
.tbl-wrap { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead tr {
    background: rgba(15,23,42,0.5);
    border-bottom: 1px solid var(--border);
}
.data-table th {
    padding: .85rem 1rem; text-align: left;
    font-size: .65rem; font-weight: 700; letter-spacing: .09em;
    text-transform: uppercase; color: var(--text-muted); white-space: nowrap;
}
.data-table tbody tr {
    border-bottom: 1px solid rgba(255,255,255,.04);
    transition: background .15s;
}
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(59,130,246,.04); }
.data-table td { padding: .85rem 1rem; font-size: .82rem; vertical-align: middle; }
.mono { font-variant-numeric: tabular-nums; letter-spacing: .02em; }
.dim  { color: var(--text-secondary); }

/* ── AVATAR ── */
.td-user { display: flex; align-items: center; gap: .75rem; }
.tbl-av {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 800; color: #fff;
    border: 1.5px solid rgba(59,130,246,.3);
}
.td-name  { font-weight: 500; font-size: .84rem; }
.td-email { font-size: .72rem; color: var(--text-muted); margin-top: 1px; }

/* ── BADGES ── */
.badge {
    display: inline-flex; align-items: center; padding: .2rem .6rem;
    border-radius: 20px; font-size: .68rem; font-weight: 600; letter-spacing: .03em;
    white-space: nowrap;
}
.badge-green { background: rgba(16,185,129,.12); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
.badge-red   { background: rgba(239,68,68,.12);  color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
.badge-muted { background: rgba(255,255,255,.06); color: var(--text-secondary); border: 1px solid var(--border); }
.badge-blue  { background: rgba(59,130,246,.12); color: #93c5fd; border: 1px solid rgba(59,130,246,.25); }

/* ── ACTIONS ── */
.tbl-actions { display: flex; gap: .35rem; align-items: center; }
.act-btn {
    padding: .3rem .7rem; border-radius: 6px; font-size: .72rem; font-weight: 600;
    cursor: pointer; border: none; font-family: 'Poppins', sans-serif;
    text-decoration: none; transition: all .15s; display: inline-flex; align-items: center; gap: 4px;
}
.act-btn.toggle-on {
    background: rgba(239,68,68,.12); color: #fca5a5;
    border: 1px solid rgba(239,68,68,.2);
}
.act-btn.toggle-on:hover { background: rgba(239,68,68,.22); }
.act-btn.toggle-off {
    background: rgba(16,185,129,.12); color: #6ee7b7;
    border: 1px solid rgba(16,185,129,.2);
}
.act-btn.toggle-off:hover { background: rgba(16,185,129,.22); }
.act-btn.del {
    background: rgba(239,68,68,.08); color: #f87171;
    border: 1px solid rgba(239,68,68,.15);
}
.act-btn.del:hover { background: rgba(239,68,68,.18); }

/* ── EMPTY ── */
.empty-row { text-align: center; padding: 3rem 1rem; color: var(--text-muted); font-size: .85rem; }
.empty-icon { font-size: 2rem; margin-bottom: .5rem; opacity: .4; }

/* ── PAGINATION ── */
.pagination {
    display: flex; gap: .35rem; align-items: center; justify-content: center;
    padding: 1.1rem; border-top: 1px solid var(--border);
}
.pg-btn {
    width: 32px; height: 32px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; font-size: .78rem; font-weight: 600;
    text-decoration: none; color: var(--text-secondary);
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    transition: all .15s;
}
.pg-btn:hover, .pg-btn.active {
    background: rgba(59,130,246,.18); color: #93c5fd;
    border-color: rgba(59,130,246,.35);
}

/* ── CONFIRM MODAL ── */
.modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,.65); backdrop-filter: blur(6px);
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.confirm-modal {
    background: #1e293b; border: 1px solid var(--border-hi);
    border-radius: 16px; padding: 2rem; max-width: 400px; width: 90%;
    animation: slideUp .25s ease;
}
.confirm-icon { font-size: 2.5rem; text-align: center; margin-bottom: 1rem; }
.confirm-title { font-size: 1.05rem; font-weight: 700; text-align: center; margin-bottom: .5rem; }
.confirm-text  { font-size: .82rem; color: var(--text-secondary); text-align: center; line-height: 1.6; margin-bottom: 1.5rem; }
.confirm-name  { color: var(--text-primary); font-weight: 600; }
.confirm-foot  { display: flex; gap: .65rem; }
.confirm-foot .btn { flex: 1; justify-content: center; }
.btn-cancel { background: rgba(255,255,255,.07); border: 1px solid var(--border); color: var(--text-secondary); }
.btn-cancel:hover { background: rgba(255,255,255,.12); }
.btn-danger { background: rgba(239,68,68,.85); color: #fff; }
.btn-danger:hover { background: #ef4444; transform: translateY(-1px); }
.btn-warning { background: rgba(251,191,36,.15); color: var(--gold); border: 1px solid rgba(251,191,36,.3); }
.btn-warning:hover { background: rgba(251,191,36,.25); }

/* ── MOBILE ── */
.mobile-menu-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

@keyframes fadeIn   { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@keyframes slideUp  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: none; } }

@media (max-width: 1100px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 1rem; }
    .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .topbar { flex-direction: column; align-items: flex-start; gap: .75rem; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Gurkha Marga" onerror="this.style.display='none'">
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="admin-badge">
            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Admin Panel
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Management</div>
        <a href="admin_dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="admin_users.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Users
        </a>
        <a href="admin_workouts.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="admin_progress.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
            Progress
        </a>

        <!-- ── FINANCE (matches admin_layout.php) ── -->
        <div class="nav-section-title" style="margin-top:.75rem">Finance</div>
        <a href="balance.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Balance
        </a>

        <div class="nav-section-title" style="margin-top:.75rem">System</div>
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
            <div class="topbar-sub">Admin Panel</div>
            <div class="topbar-title">User Management</div>
            <div class="topbar-date"><?= date('l, F j, Y') ?></div>
        </div>
        <div style="display:flex; gap:.5rem; align-items:center;">
            <span style="font-size:.75rem; color:var(--text-muted); padding:.4rem .85rem; background:rgba(255,255,255,.05); border:1px solid var(--border); border-radius:8px;">
                <?= number_format($usersTotal) ?> total users
            </span>
        </div>
    </div>

    <!-- FLASH -->
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="flash <?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '✕' ?>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-label">Total Users</div>
            <div class="stat-value blue"><?= number_format($usersTotal) ?></div>
            <div class="stat-sub">All registered accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active</div>
            <div class="stat-value green"><?= number_format($activeCount) ?></div>
            <div class="stat-sub">Enabled accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Inactive</div>
            <div class="stat-value red"><?= number_format($inactiveCount) ?></div>
            <div class="stat-sub">Disabled accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Joined Today</div>
            <div class="stat-value gold"><?= number_format($todayCount) ?></div>
            <div class="stat-sub">New today</div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <form method="GET" style="display:contents">
            <input type="text" name="search" placeholder="Search name or email…"
                   value="<?= htmlspecialchars($search) ?>" style="max-width:240px; min-width:160px;">
            <select name="filter" onchange="this.form.submit()" style="max-width:180px;">
                <option value="">All Users</option>
                <option value="active"    <?= $uFilter==='active'   ?'selected':'' ?>>Active</option>
                <option value="inactive"  <?= $uFilter==='inactive' ?'selected':'' ?>>Inactive</option>
                <optgroup label="Target Force">
                    <option value="british"   <?= $uFilter==='british'   ?'selected':'' ?>>🇬🇧 British Army</option>
                    <option value="nepal"     <?= $uFilter==='nepal'     ?'selected':'' ?>>🇳🇵 Nepal Army</option>
                    <option value="indian"    <?= $uFilter==='indian'    ?'selected':'' ?>>🇮🇳 Indian Army</option>
                    <option value="singapore" <?= $uFilter==='singapore' ?'selected':'' ?>>🇸🇬 Singapore Police</option>
                    <option value="french"    <?= $uFilter==='french'    ?'selected':'' ?>>🇫🇷 French Legion</option>
                </optgroup>
            </select>
            <button type="submit" class="btn btn-ghost">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                Search
            </button>
            <?php if ($search || $uFilter): ?>
            <a href="admin_users.php" class="btn btn-clear">✕ Clear</a>
            <?php endif; ?>
            <span class="results-count"><?= number_format($usersTotal) ?> result<?= $usersTotal !== 1 ? 's' : '' ?></span>
        </form>
    </div>

    <!-- TABLE -->
    <div class="card">
        <div class="tbl-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Age / Gender</th>
                        <th>BMI</th>
                        <th>Force</th>
                        <th>Level</th>
                        <th>Phone</th>
                        <th>Joined</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($usersList)): ?>
                <tr>
                    <td colspan="10" class="empty-row">
                        <div class="empty-icon">👤</div>
                        No users found matching your criteria.
                    </td>
                </tr>
                <?php else: foreach ($usersList as $u):
                    $bmi = ($u['height'] > 0 && $u['weight'] > 0)
                        ? round($u['weight'] / (($u['height'] / 100) ** 2), 1) : null;
                    $bmiClass = '';
                    if ($bmi) {
                        if ($bmi < 18.5)     $bmiClass = 'badge-blue';
                        elseif ($bmi < 25.0) $bmiClass = 'badge-green';
                        elseif ($bmi < 30.0) $bmiClass = '';
                        else                 $bmiClass = 'badge-red';
                    }
                ?>
                <tr>
                    <td class="mono dim" style="font-size:.75rem"><?= $u['id'] ?></td>
                    <td>
                        <div class="td-user">
                            <div class="tbl-av"><?= strtoupper(substr($u['full_name'], 0, 1)) ?></div>
                            <div>
                                <div class="td-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                <div class="td-email"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="mono">
                        <?= $u['age'] ? $u['age'].'y' : '—' ?>
                        <span style="color:var(--text-muted); font-size:.72rem;"> / <?= $u['gender'] ? ucfirst($u['gender']) : '—' ?></span>
                    </td>
                    <td>
                        <?php if ($bmi): ?>
                        <span class="badge <?= $bmiClass ?>"><?= $bmi ?></span>
                        <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                    </td>
                    <td class="dim" style="white-space:nowrap;">
                        <?= ($forceFlags[$u['target_force']] ?? '') ?>
                        <?= $forceNames[$u['target_force']] ?? '—' ?>
                    </td>
                    <td><span class="badge badge-muted"><?= ucfirst($u['experience_level'] ?? '—') ?></span></td>
                    <td class="mono dim" style="font-size:.75rem;"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                    <td class="mono dim" style="font-size:.75rem; white-space:nowrap;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>">
                            <?= $u['is_active'] ? '● Active' : '○ Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="tbl-actions">
                            <!-- Toggle -->
                            <button class="act-btn <?= $u['is_active'] ? 'toggle-on' : 'toggle-off' ?>"
                                onclick="openToggle(
                                    'admin_users.php?action=toggle&id=<?= $u['id'] ?>',
                                    '<?= addslashes($u['full_name']) ?>',
                                    <?= $u['is_active'] ? 'true' : 'false' ?>
                                )">
                                <?= $u['is_active'] ? 'Disable' : ' Enable' ?>
                            </button>
                            <!-- Delete -->
                            <button class="act-btn del"
                                onclick="openDelete(
                                    'admin_users.php?action=delete&id=<?= $u['id'] ?>',
                                    '<?= addslashes($u['full_name']) ?>'
                                )">
                                 Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($usersPages > 1): ?>
        <div class="pagination">
            <?php if ($uPage > 1): ?>
            <a href="?p=<?= $uPage-1 ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>" class="pg-btn">‹</a>
            <?php endif; ?>
            <?php for ($i = max(1, $uPage-3); $i <= min($usersPages, $uPage+3); $i++): ?>
            <a href="?p=<?= $i ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>"
               class="pg-btn <?= $i === $uPage ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($uPage < $usersPages): ?>
            <a href="?p=<?= $uPage+1 ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>" class="pg-btn">›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- TOGGLE CONFIRM MODAL -->
<div class="modal-overlay" id="modal-toggle">
    <div class="confirm-modal">
        <div class="confirm-icon" id="toggle-icon">⏸</div>
        <div class="confirm-title" id="toggle-title">Disable Account?</div>
        <div class="confirm-text">
            This will <span id="toggle-action-word">disable</span> the account for
            <span class="confirm-name" id="toggle-name"></span>.
            They will <span id="toggle-effect">not be able to log in</span> until re-enabled.
        </div>
        <div class="confirm-foot">
            <button class="btn btn-cancel" onclick="closeModal('modal-toggle')">Cancel</button>
            <a href="#" class="btn btn-warning" id="toggle-confirm-btn">Confirm</a>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-overlay" id="modal-delete">
    <div class="confirm-modal">
        <div class="confirm-icon">⚠️</div>
        <div class="confirm-title">Delete User Account?</div>
        <div class="confirm-text">
            You are about to permanently delete
            <span class="confirm-name" id="delete-name"></span>'s account.
            This action <strong>cannot be undone</strong> and all their data will be lost.
        </div>
        <div class="confirm-foot">
            <button class="btn btn-cancel" onclick="closeModal('modal-delete')">Cancel</button>
            <a href="#" class="btn btn-danger" id="delete-confirm-btn">Delete Permanently</a>
        </div>
    </div>
</div>

<button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
function openToggle(url, name, isActive) {
    document.getElementById('toggle-name').textContent = name;
    document.getElementById('toggle-confirm-btn').href = url;
    if (isActive) {
        document.getElementById('toggle-icon').textContent = '⏸';
        document.getElementById('toggle-title').textContent = 'Disable Account?';
        document.getElementById('toggle-action-word').textContent = 'disable';
        document.getElementById('toggle-effect').textContent = 'not be able to log in';
    } else {
        document.getElementById('toggle-icon').textContent = '▶';
        document.getElementById('toggle-title').textContent = 'Enable Account?';
        document.getElementById('toggle-action-word').textContent = 'enable';
        document.getElementById('toggle-effect').textContent = 'be able to log in again';
    }
    document.getElementById('modal-toggle').classList.add('open');
}

function openDelete(url, name) {
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-confirm-btn').href = url;
    document.getElementById('modal-delete').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    }
});
</script>

</body>
</html>