<?php

if (!defined('LAYOUT_LOADED')) define('LAYOUT_LOADED', true);

$isLoggedIn = !empty($_SESSION['admin_logged_in']);
$adminName  = $_SESSION['admin_name']  ?? '';
$adminRole  = $_SESSION['admin_role']  ?? '';
$adminEmail = $_SESSION['admin_email'] ?? '';
$adminId    = $_SESSION['admin_id']    ?? 0;

if (!$isLoggedIn) { header('Location: ../auth/login.php'); exit(); }

// Determine current page from filename
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentPage = str_replace('admin_', '', $currentPage);

$pageLabels = [
    'dashboard' => 'Dashboard',
    'users'     => 'Users',
    'staff'     => 'Staff',
    'workouts'  => 'Workouts',
    'settings'  => 'Settings',
];

// Flash message support
$flash = $_SESSION['flash'] ?? ['type'=>'','msg'=>''];
unset($_SESSION['flash']);

function setFlash($type, $msg) {
    $_SESSION['flash'] = ['type'=>$type,'msg'=>$msg];
}

function redirectTo($url) {
    header("Location: $url"); exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageLabels[$currentPage] ?? 'Admin' ?> — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --bg:#08090d;
    --surface:#0d0f16;
    --surface2:#111320;
    --elevated:#161928;
    --border:rgba(255,255,255,.06);
    --border2:rgba(255,255,255,.11);
    --accent:#3b6ff5;
    --accent-dim:rgba(59,111,245,.12);
    --accent-border:rgba(59,111,245,.22);
    --gold:#c9973a;
    --gold-dim:rgba(201,151,58,.1);
    --gold-border:rgba(201,151,58,.22);
    --green:#2da44e;
    --green-dim:rgba(45,164,78,.1);
    --green-border:rgba(45,164,78,.2);
    --red:#cc3333;
    --red-dim:rgba(204,51,51,.1);
    --red-border:rgba(204,51,51,.2);
    --amber:#c97c2d;
    --amber-dim:rgba(201,124,45,.1);
    --text:#e8e9f0;
    --text-sub:#8b8fa8;
    --text-dim:#555870;
    --font:'IBM Plex Sans',sans-serif;
    --mono:'IBM Plex Mono',monospace;
    --sidebar:252px;
    --r:8px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;font-size:14px}

/* ── SIDEBAR ── */
.sidebar{position:fixed;left:0;top:0;width:var(--sidebar);height:100vh;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;transition:transform .25s ease}
.sb-head{padding:1.25rem 1.1rem 1rem;border-bottom:1px solid var(--border)}
.sb-logo{display:flex;align-items:center;gap:10px}
.logo-mark{width:32px;height:32px;background:var(--accent);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-mark svg{width:16px;height:16px;fill:none;stroke:#fff;stroke-width:2.2}
.logo-title{font-weight:700;font-size:.9rem;letter-spacing:.01em;color:var(--text)}
.logo-sub{font-size:.63rem;color:var(--text-dim);letter-spacing:.12em;text-transform:uppercase;margin-top:2px}
.sb-user{margin:.75rem;background:var(--elevated);border:1px solid var(--border);border-radius:var(--r);padding:.8rem;display:flex;align-items:center;gap:9px}
.av{width:34px;height:34px;border-radius:6px;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;color:#fff;flex-shrink:0;font-family:var(--mono)}
.sb-uname{font-size:.83rem;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px}
.sb-urole{font-size:.65rem;color:var(--text-sub);margin-top:2px;text-transform:capitalize;letter-spacing:.04em}
.status-dot{width:6px;height:6px;border-radius:50%;background:var(--green);margin-left:auto;flex-shrink:0;box-shadow:0 0 6px var(--green)}
.sb-nav{padding:.5rem 0;flex:1}
.nav-section{font-size:.59rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--text-dim);padding:.85rem 1.1rem .3rem}
.nav-link{display:flex;align-items:center;gap:9px;padding:.52rem 1.1rem;color:var(--text-sub);text-decoration:none;font-size:.83rem;font-weight:500;border-left:2px solid transparent;transition:all .12s;white-space:nowrap}
.nav-link svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.7;flex-shrink:0;opacity:.7}
.nav-link:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-link.active{color:var(--text);background:var(--accent-dim);border-left-color:var(--accent)}
.nav-link.active svg{opacity:1}
.nav-link.danger{color:#cc5555}
.nav-link.danger:hover{background:var(--red-dim);color:var(--red)}
.sb-ver{padding:.75rem 1.1rem;font-size:.63rem;color:var(--text-dim);border-top:1px solid var(--border)}

/* ── LAYOUT ── */
.layout{margin-left:var(--sidebar);min-height:100vh;display:flex;flex-direction:column}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:50;background:rgba(8,9,13,.94);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:.72rem 1.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.topbar-left{display:flex;align-items:center;gap:.75rem}
.topbar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--text-sub);padding:.2rem}
.topbar-toggle svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2}
.topbar-title{font-weight:700;font-size:.9rem}
.topbar-date{font-size:.68rem;color:var(--text-dim);margin-top:1px;font-family:var(--mono)}
.topbar-right{display:flex;align-items:center;gap:.6rem}
.topbar-badge{background:var(--elevated);border:1px solid var(--border);color:var(--text-sub);padding:.18rem .65rem;border-radius:4px;font-size:.68rem;font-family:var(--mono);text-transform:capitalize;letter-spacing:.04em}
.topbar-email{font-size:.73rem;color:var(--text-dim);font-family:var(--mono)}
.logout-link{font-size:.74rem;color:var(--text-dim);text-decoration:none;padding:.28rem .55rem;border-radius:5px;transition:all .12s;border:1px solid transparent}
.logout-link:hover{color:var(--red);background:var(--red-dim);border-color:var(--red-border)}

/* ── PAGE ── */
.page{padding:1.6rem 1.75rem;flex:1}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
.page-title{font-size:1.22rem;font-weight:700;letter-spacing:-.015em}
.page-sub{font-size:.78rem;color:var(--text-sub);margin-top:.25rem}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.4rem}
.stats-grid.cols-3{grid-template-columns:repeat(3,1fr)}
.stats-grid.cols-6{grid-template-columns:repeat(6,1fr)}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:1.1rem 1.2rem;transition:border-color .18s}
.stat-card:hover{border-color:var(--border2)}
.stat-label{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:.5rem}
.stat-val{font-family:var(--mono);font-size:1.8rem;font-weight:500;line-height:1;color:var(--text);letter-spacing:-.03em}
.stat-sub{font-size:.7rem;color:var(--text-sub);margin-top:.35rem}
.stat-chip{display:inline-block;font-size:.65rem;font-family:var(--mono);padding:.1rem .42rem;border-radius:3px;margin-top:.35rem}
.stat-chip.up{background:var(--green-dim);color:#3dc96a;border:1px solid var(--green-border)}
.stat-chip.warn{background:var(--amber-dim);color:#e09a4d;border:1px solid rgba(201,124,45,.3)}
.stat-chip.neutral{background:var(--elevated);color:var(--text-sub);border:1px solid var(--border)}

/* ── CARD ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.card-pad{padding:1.25rem}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.25rem;border-bottom:1px solid var(--border)}
.card-title{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-sub)}
.card-action{font-size:.75rem;color:var(--accent);text-decoration:none;font-weight:500}
.card-action:hover{text-decoration:underline}

/* ── GRID ── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.1rem}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:1.1rem}
.grid-dash{display:grid;grid-template-columns:3fr 2fr;gap:1.1rem}
.grid-dash2{display:grid;grid-template-columns:2fr 1fr;gap:1.1rem}
.col-span-2{grid-column:1/-1}
.mb{margin-bottom:1.1rem}

/* ── CHART AREA ── */
.chart-area{padding:1.25rem;height:200px;position:relative}
.chart-area.tall{height:260px}

/* ── DISTRIBUTION BARS ── */
.dist-list{padding:.75rem 1.25rem 1rem}
.dist-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.65rem}
.dist-row:last-child{margin-bottom:0}
.dist-lbl{font-size:.75rem;width:115px;flex-shrink:0;color:var(--text-sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dist-track{flex:1;height:5px;background:var(--elevated);border-radius:3px;overflow:hidden}
.dist-fill{height:100%;border-radius:3px;transition:width .5s cubic-bezier(.4,0,.2,1)}
.dist-cnt{font-family:var(--mono);font-size:.7rem;color:var(--text-dim);min-width:55px;text-align:right}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;min-width:560px}
.data-table th{text-align:left;padding:.55rem 1rem;font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--border);white-space:nowrap}
.data-table td{padding:.65rem 1rem;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.025);vertical-align:middle}
.data-table tbody tr:last-child td{border-bottom:none}
.data-table tbody tr:hover td{background:rgba(255,255,255,.016)}
.tbl-av{width:26px;height:26px;border-radius:4px;background:var(--accent-dim);border:1px solid var(--accent-border);display:inline-flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:var(--accent);margin-right:.5rem;font-family:var(--mono);vertical-align:middle;flex-shrink:0}
.tbl-av.gold{background:var(--gold-dim);border-color:var(--gold-border);color:var(--gold)}
.td-name{display:flex;align-items:center}
.mono{font-family:var(--mono);font-size:.78rem}
.dim{font-size:.77rem;color:var(--text-sub)}
.empty-row{text-align:center;color:var(--text-dim);padding:3rem!important;font-size:.82rem}

/* ── BADGES ── */
.badge{display:inline-block;padding:.17rem .5rem;border-radius:3px;font-size:.67rem;font-weight:600;white-space:nowrap;font-family:var(--mono);letter-spacing:.04em}
.badge-green{background:var(--green-dim);color:#3dc96a;border:1px solid var(--green-border)}
.badge-red{background:var(--red-dim);color:#e05555;border:1px solid var(--red-border)}
.badge-gold{background:var(--gold-dim);color:var(--gold);border:1px solid var(--gold-border)}
.badge-blue{background:var(--accent-dim);color:#7fa3f7;border:1px solid var(--accent-border)}
.badge-muted{background:var(--elevated);color:var(--text-sub);border:1px solid var(--border)}
.badge-amber{background:var(--amber-dim);color:#e09a4d;border:1px solid rgba(201,124,45,.22)}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:.52rem 1rem;border-radius:6px;font-family:var(--font);font-size:.82rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:#2e5fe0;transform:translateY(-1px)}
.btn-danger{background:var(--red-dim);color:#e05555;border:1px solid var(--red-border)}
.btn-danger:hover{background:rgba(204,51,51,.2)}
.btn-ghost{background:var(--elevated);color:var(--text-sub);border:1px solid var(--border)}
.btn-ghost:hover{color:var(--text);border-color:var(--border2)}
.btn-sm{padding:.32rem .7rem;font-size:.76rem}

/* ── TABLE ACTION BUTTONS ── */
.tbl-actions{display:flex;align-items:center;gap:4px;white-space:nowrap}
.act-btn{display:inline-flex;align-items:center;padding:.22rem .55rem;border-radius:4px;font-size:.7rem;font-weight:600;font-family:var(--font);text-decoration:none;border:1px solid var(--border);background:var(--elevated);color:var(--text-sub);cursor:pointer;transition:all .12s;gap:3px}
.act-btn:hover{color:var(--text);border-color:var(--border2)}
.act-btn.edit:hover{color:#7fa3f7;border-color:var(--accent-border);background:var(--accent-dim)}
.act-btn.toggle:hover{color:#e09a4d;border-color:rgba(201,124,45,.3);background:var(--amber-dim)}
.act-btn.del:hover{color:#e05555;border-color:var(--red-border);background:var(--red-dim)}

/* ── FLASH ── */
.flash{padding:.75rem 1rem;border-radius:6px;font-size:.83rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:.65rem;animation:flashIn .25s ease}
@keyframes flashIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.flash-success{background:var(--green-dim);border:1px solid var(--green-border);color:#3dc96a}
.flash-error{background:var(--red-dim);border:1px solid var(--red-border);color:#e05555}

/* ── MODALS ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex;animation:fadeIn .18s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:1.5rem;width:100%;max-width:540px;max-height:92vh;overflow-y:auto;animation:slideUp .22s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem}
.modal-title{font-size:.97rem;font-weight:700}
.modal-close{cursor:pointer;color:var(--text-sub);font-size:1.1rem;line-height:1;padding:.3rem;background:none;border:none;transition:color .12s;text-decoration:none}
.modal-close:hover{color:var(--text)}
.modal-foot{display:flex;gap:.65rem;justify-content:flex-end;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border)}

/* ── FORMS ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{display:flex;flex-direction:column;gap:.35rem}
.field.full{grid-column:1/-1}
.field.row-check{flex-direction:row;align-items:center;gap:.55rem}
label,.lbl{font-size:.67rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.09em}
.field.row-check label{text-transform:none;font-size:.85rem;letter-spacing:0;color:var(--text);margin:0;font-weight:500}
input[type=text],input[type=email],input[type=password],input[type=number],select,textarea{background:rgba(255,255,255,.035);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:var(--font);font-size:.84rem;padding:.62rem .88rem;transition:border-color .15s,box-shadow .15s;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent-border);box-shadow:0 0 0 3px rgba(59,111,245,.07)}
input::placeholder,textarea::placeholder{color:var(--text-dim)}
select option{background:var(--elevated)}
textarea{resize:vertical;min-height:82px}
input[type=checkbox]{width:auto;accent-color:var(--green)}
input:disabled{opacity:.4;cursor:not-allowed}

/* ── TABS ── */
.tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:1.1rem;overflow-x:auto}
.tab{padding:.55rem 1rem;font-size:.82rem;font-weight:500;color:var(--text-sub);cursor:pointer;border-bottom:2px solid transparent;transition:all .12s;text-decoration:none;white-space:nowrap}
.tab:hover{color:var(--text)}
.tab.active{color:var(--text);border-bottom-color:var(--accent)}

/* ── FILTER BAR ── */
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:.8rem .95rem;margin-bottom:1.1rem;display:flex;gap:.65rem;align-items:center;flex-wrap:wrap}

/* ── PAGINATION ── */
.pagination{display:flex;gap:.35rem;justify-content:center;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--border)}
.pg-btn{padding:.32rem .6rem;border-radius:4px;font-size:.76rem;font-family:var(--mono);text-decoration:none;background:var(--elevated);color:var(--text-sub);border:1px solid var(--border);transition:all .12s}
.pg-btn:hover{color:var(--text);border-color:var(--border2)}
.pg-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}

/* ── DIVIDER ── */
.divider{border:none;border-top:1px solid var(--border);margin:1.25rem 0}

/* ── RESPONSIVE ── */
@media(max-width:1280px){.stats-grid{grid-template-columns:repeat(2,1fr)}.stats-grid.cols-6{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1000px){.grid-2{grid-template-columns:1fr}.grid-3{grid-template-columns:1fr 1fr}.grid-dash,.grid-dash2{grid-template-columns:1fr}}
@media(max-width:900px){
    .sidebar{transform:translateX(-100%)}
    .sidebar.open{transform:translateX(0)}
    .layout{margin-left:0}
    .topbar-toggle{display:flex}
    .page{padding:1rem}
}
@media(max-width:640px){.stats-grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.grid-3{grid-template-columns:1fr}.stats-grid.cols-6{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sb-head">
        <div class="sb-logo">
            <div class="logo-mark">
                <svg viewBox="0 0 16 16"><path d="M8 2L14 13H2L8 2Z" stroke-linejoin="round"/></svg>
            </div>
            <div>
                <div class="logo-title">Gurkha Marga</div>
                <div class="logo-sub">Administration</div>
            </div>
        </div>
    </div>

    <div class="sb-user">
        <div class="av"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
        <div style="overflow:hidden;flex:1">
            <div class="sb-uname"><?= htmlspecialchars($adminName) ?></div>
            <div class="sb-urole"><?= htmlspecialchars($adminRole) ?></div>
        </div>
        <div class="status-dot"></div>
    </div>

    <nav class="sb-nav">
        <div class="nav-section">Overview</div>
        <a href="admin_dashboard.php" class="nav-link <?= $currentPage==='dashboard'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a href="admin_users.php" class="nav-link <?= $currentPage==='users'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 014-4h4a4 4 0 014 4v2"/></svg>
            Users
        </a>
        <a href="admin_staff.php" class="nav-link <?= $currentPage==='staff'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            Staff
        </a>

        <div class="nav-section">Content</div>
        <a href="admin_workouts.php" class="nav-link <?= $currentPage==='workouts'?'active':'' ?>">
            🏋️‍♂️Workouts
        </a>

        <div class="nav-section">Account</div>
        <a href="admin_settings.php" class="nav-link <?= $currentPage==='settings'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            Settings
        </a>
        <a href="admin_logout.php" class="nav-link danger" onclick="return confirm('Log out?')">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </nav>
    <div class="sb-ver">v2.0 &mdash; <?= date('Y') ?></div>
</aside>

<div class="layout">
    <header class="topbar">
        <div class="topbar-left">
            <button class="topbar-toggle" onclick="toggleSidebar()">
                <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div>
                <div class="topbar-title"><?= $pageLabels[$currentPage] ?? 'Admin Panel' ?></div>
                <div class="topbar-date"><?= date('D, d M Y') ?> &bull; <?= date('H:i') ?></div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-badge"><?= htmlspecialchars($adminRole) ?></span>
            <span class="topbar-email"><?= htmlspecialchars($adminEmail) ?></span>
            <a href="admin_logout.php" class="logout-link" onclick="return confirm('Log out?')">Logout</a>
        </div>
    </header>

    <div class="page">

    <?php if (!empty($flash['msg'])): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type']==='success' ? '✓' : '⚠' ?>
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>
<?php
// End of layout header — each page closes </div></div> and adds JS footer
// Use layoutFooter() to close the layout
function layoutFooter() {
    echo <<<HTML
    </div><!-- .page -->
</div><!-- .layout -->

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const sb = document.getElementById('sidebar');
    const toggle = document.querySelector('.topbar-toggle');
    if (window.innerWidth <= 900 && sb && !sb.contains(e.target) && toggle && !toggle.contains(e.target)) {
        sb.classList.remove('open');
    }
});
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
function confirmDelete(url, msg) {
    if (confirm(msg || 'Delete this item? This cannot be undone.')) window.location.href = url;
}
document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .4s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 400);
    }, 4000);
});
</script>
</body>
</html>
HTML;
}