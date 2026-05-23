<?php

if (!defined('LAYOUT_LOADED')) define('LAYOUT_LOADED', true);

$isLoggedIn = !empty($_SESSION['admin_logged_in']);
$adminName  = $_SESSION['admin_name']  ?? '';
$adminRole  = $_SESSION['admin_role']  ?? '';
$adminEmail = $_SESSION['admin_email'] ?? '';
$adminId    = $_SESSION['admin_id']    ?? 0;

if (!$isLoggedIn) { header('Location: ../auth/login.php'); exit(); }

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentPage = str_replace('admin_', '', $currentPage);

$pageLabels = [
    'dashboard' => 'Dashboard',
    'users'     => 'Users',
    'staff'     => 'Staff',
    'workouts'  => 'Workouts',
    'questions' => 'Questions',
    'balance'   => 'Balance',
    'settings'  => 'Settings',
];

$flash = $_SESSION['flash'] ?? ['type' => '', 'msg' => ''];
unset($_SESSION['flash']);

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function redirectTo(string $url): void {
    header("Location: $url"); exit();
}
function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageLabels[$currentPage] ?? 'Admin' ?> — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --bg:          #0f172a;
    --bg2:         #1e293b;
    --surface:     rgba(30,41,59,0.8);
    --surface2:    rgba(15,23,42,0.97);
    --elevated:    rgba(15,23,42,0.6);
    --accent:      #3b82f6;
    --accent-dim:  rgba(59,130,246,0.12);
    --accent-hi:   rgba(59,130,246,0.25);
    --gold:        #fbbf24;
    --gold-dim:    rgba(251,191,36,0.1);
    --gold-hi:     rgba(251,191,36,0.25);
    --green:       #10b981;
    --green-dim:   rgba(16,185,129,0.12);
    --green-hi:    rgba(16,185,129,0.25);
    --red:         #ef4444;
    --red-dim:     rgba(239,68,68,0.12);
    --red-hi:      rgba(239,68,68,0.25);
    --amber:       #f59e0b;
    --amber-dim:   rgba(245,158,11,0.12);
    --text:        #f8fafc;
    --text-2:      #cbd5e1;
    --text-3:      #64748b;
    --border:      rgba(255,255,255,0.07);
    --border-hi:   rgba(255,255,255,0.12);
    --sidebar-w:   260px;
    --font:        'Poppins', sans-serif;
    --mono:        'IBM Plex Mono', monospace;
    --r:           12px;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%);background-attachment:fixed;color:var(--text);min-height:100vh;overflow-x:hidden;font-size:14px}

/* ── SIDEBAR ── */
.sidebar{
    position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;
    background:var(--surface2);backdrop-filter:blur(20px);
    border-right:1px solid var(--border);
    display:flex;flex-direction:column;z-index:100;
    overflow-y:auto;transition:transform .25s ease;
}
.sb-head{padding:1.5rem 1.25rem 1.1rem;border-bottom:1px solid var(--border)}
.sb-logo{display:flex;align-items:center;gap:10px;margin-bottom:1rem}
.sb-logo img{width:34px;height:34px;object-fit:contain;border-radius:8px}
.sb-logo-text .brand{
    font-size:1.1rem;font-weight:800;
    background:linear-gradient(135deg,var(--gold),#f59e0b);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.sb-logo-text .sub{font-size:.6rem;color:var(--text-3);letter-spacing:.1em;text-transform:uppercase;margin-top:1px}
.sb-admin-badge{
    display:flex;align-items:center;gap:7px;
    padding:.55rem .85rem;border-radius:10px;
    background:var(--gold-dim);border:1px solid var(--gold-hi);
    font-size:.72rem;font-weight:600;color:var(--gold);
}
.sb-admin-badge svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0}
.sb-nav{padding:.85rem 0;flex:1}
.nav-section{
    font-size:.6rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;
    color:rgba(100,116,139,0.6);padding:.7rem 1.25rem .25rem;
}
.nav-link{
    display:flex;align-items:center;gap:10px;
    padding:.58rem 1.25rem;
    color:var(--text-2);text-decoration:none;
    font-size:.83rem;font-weight:500;
    border-left:2.5px solid transparent;
    transition:all .15s;white-space:nowrap;
}
.nav-link svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;flex-shrink:0;opacity:.7;transition:opacity .15s}
.nav-link:hover{color:var(--text);background:rgba(59,130,246,0.06)}
.nav-link.active{color:var(--text);background:var(--accent-dim);border-left-color:var(--accent)}
.nav-link.active svg{opacity:1}
.nav-link.danger{color:#f87171}
.nav-link.danger:hover{background:var(--red-dim);border-left-color:var(--red)}
.sb-footer{padding:.85rem 1.25rem;font-size:.62rem;color:var(--text-3);border-top:1px solid var(--border)}

/* ── LAYOUT ── */
.layout{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}

/* ── TOPBAR ── */
.topbar{
    position:sticky;top:0;z-index:50;
    background:rgba(15,23,42,0.94);backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border);
    padding:.8rem 1.75rem;
    display:flex;align-items:center;justify-content:space-between;gap:1rem;
}
.topbar-left{display:flex;align-items:center;gap:.75rem}
.topbar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--text-3);padding:.2rem}
.topbar-toggle svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2}
.topbar-title{font-weight:700;font-size:.9rem}
.topbar-date{font-size:.67rem;color:var(--text-3);margin-top:1px;font-family:var(--mono)}
.topbar-right{display:flex;align-items:center;gap:.6rem}
.topbar-role{
    padding:.2rem .6rem;border-radius:6px;
    background:var(--gold-dim);border:1px solid var(--gold-hi);
    font-size:.67rem;font-weight:600;color:var(--gold);font-family:var(--mono);
    text-transform:capitalize;letter-spacing:.04em;
}
.topbar-email{font-size:.7rem;color:var(--text-3);font-family:var(--mono)}
.logout-link{
    font-size:.72rem;color:var(--text-3);text-decoration:none;
    padding:.28rem .6rem;border-radius:6px;
    border:1px solid transparent;transition:all .15s;cursor:pointer;
    background:none;font-family:var(--font);
}
.logout-link:hover{color:#f87171;background:var(--red-dim);border-color:var(--red-hi)}

/* ── PAGE ── */
.page{padding:1.75rem;flex:1}
.page-header{
    display:flex;align-items:flex-start;justify-content:space-between;
    margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;
}
.page-title{font-size:1.3rem;font-weight:700;letter-spacing:-.02em}
.page-sub{font-size:.75rem;color:var(--text-3);margin-top:.25rem}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem}
.stats-grid.cols-3{grid-template-columns:repeat(3,1fr)}
.stats-grid.cols-5{grid-template-columns:repeat(5,1fr)}
.stat-card{
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);padding:1.1rem 1.25rem;
    backdrop-filter:blur(12px);transition:border-color .18s;
    animation:fadeUp .35s ease both;
}
.stat-card:hover{border-color:var(--border-hi)}
.stat-label{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);margin-bottom:.5rem}
.stat-val{font-family:var(--mono);font-size:1.8rem;font-weight:500;line-height:1;color:var(--text);letter-spacing:-.03em}
.stat-sub{font-size:.68rem;color:var(--text-2);margin-top:.3rem}
.stat-chip{display:inline-block;font-size:.62rem;font-family:var(--mono);padding:.1rem .45rem;border-radius:4px;margin-top:.35rem;font-weight:600}
.stat-chip.up    {background:var(--green-dim);color:#6ee7b7;border:1px solid var(--green-hi)}
.stat-chip.warn  {background:var(--amber-dim);color:#fcd34d;border:1px solid rgba(245,158,11,.25)}
.stat-chip.neutral{background:rgba(255,255,255,.05);color:var(--text-2);border:1px solid var(--border)}

/* ── CARD ── */
.card{
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);overflow:hidden;
    backdrop-filter:blur(12px);
    animation:fadeUp .35s ease both;
    transition:border-color .18s;
}
.card:hover{border-color:var(--border-hi)}
.card-pad{padding:1.25rem}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.25rem;border-bottom:1px solid var(--border)}
.card-title{font-size:.7rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3)}
.card-action{font-size:.75rem;color:var(--accent);text-decoration:none;font-weight:500}
.card-action:hover{text-decoration:underline}

/* ── GRID ── */
.grid-2   {display:grid;grid-template-columns:1fr 1fr;gap:1.1rem}
.grid-3   {display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.1rem}
.grid-dash{display:grid;grid-template-columns:3fr 2fr;gap:1.1rem}
.grid-dash2{display:grid;grid-template-columns:2fr 1fr;gap:1.1rem}
.mb{margin-bottom:1.1rem}

/* ── CHART AREA ── */
.chart-area{padding:1.25rem;height:200px;position:relative}
.chart-area.tall{height:260px}

/* ── DISTRIBUTION BARS ── */
.dist-list{padding:.75rem 1.25rem 1rem}
.dist-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.65rem}
.dist-row:last-child{margin-bottom:0}
.dist-lbl{font-size:.75rem;width:115px;flex-shrink:0;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dist-track{flex:1;height:4px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden}
.dist-fill{height:100%;border-radius:3px;transition:width .9s cubic-bezier(.4,0,.2,1) .2s;width:0%}
.dist-cnt{font-family:var(--mono);font-size:.68rem;color:var(--text-3);min-width:55px;text-align:right}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;min-width:500px}
.data-table th{
    text-align:left;padding:.6rem 1rem;
    font-size:.61rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    color:var(--text-3);border-bottom:1px solid var(--border);
    background:rgba(15,23,42,0.4);white-space:nowrap;
}
.data-table td{
    padding:.7rem 1rem;font-size:.81rem;
    border-bottom:1px solid rgba(255,255,255,.035);
    vertical-align:middle;
}
.data-table tbody tr:last-child td{border-bottom:none}
.data-table tbody tr:hover td{background:rgba(59,130,246,.035)}
.tbl-av{
    width:28px;height:28px;border-radius:6px;
    background:var(--accent-dim);border:1px solid var(--accent-hi);
    display:inline-flex;align-items:center;justify-content:center;
    font-size:.68rem;font-weight:700;color:#93c5fd;
    margin-right:.6rem;font-family:var(--mono);vertical-align:middle;flex-shrink:0;
}
.tbl-av.gold{background:var(--gold-dim);border-color:var(--gold-hi);color:var(--gold)}
.td-name{display:flex;align-items:center}
.mono{font-family:var(--mono);font-size:.78rem}
.dim{font-size:.75rem;color:var(--text-2)}
.empty-row{text-align:center;color:var(--text-3);padding:3rem!important;font-size:.82rem}

/* ── BADGES ── */
.badge{display:inline-block;padding:.16rem .5rem;border-radius:5px;font-size:.65rem;font-weight:600;white-space:nowrap;font-family:var(--mono);letter-spacing:.03em}
.badge-green {background:var(--green-dim);color:#6ee7b7;border:1px solid var(--green-hi)}
.badge-red   {background:var(--red-dim);color:#fca5a5;border:1px solid var(--red-hi)}
.badge-gold  {background:var(--gold-dim);color:var(--gold);border:1px solid var(--gold-hi)}
.badge-blue  {background:var(--accent-dim);color:#93c5fd;border:1px solid var(--accent-hi)}
.badge-muted {background:rgba(255,255,255,.06);color:var(--text-2);border:1px solid var(--border)}
.badge-amber {background:var(--amber-dim);color:#fcd34d;border:1px solid rgba(245,158,11,.22)}
.badge-purple{background:rgba(139,92,246,.12);color:#c4b5fd;border:1px solid rgba(139,92,246,.25)}

/* ── BUTTONS ── */
.btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:.52rem 1rem;border-radius:8px;
    font-family:var(--font);font-size:.8rem;font-weight:600;
    border:none;cursor:pointer;text-decoration:none;
    transition:all .15s;white-space:nowrap;
}
.btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0}
.btn-primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(59,130,246,.3)}
.btn-gold{background:linear-gradient(135deg,var(--gold),#f59e0b);color:#0a0800}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(251,191,36,.25)}
.btn-secondary{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-2)}
.btn-secondary:hover{color:var(--text);border-color:var(--border-hi);background:rgba(255,255,255,.1)}
.btn-ghost{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-2)}
.btn-ghost:hover{color:var(--text);border-color:var(--border-hi);background:rgba(255,255,255,.1)}
.btn-danger{background:var(--red-dim);color:#fca5a5;border:1px solid var(--red-hi)}
.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:.32rem .7rem;font-size:.75rem}

/* ── TABLE ACT BUTTONS ── */
.tbl-actions{display:flex;align-items:center;gap:4px;white-space:nowrap}
.act-btn{
    display:inline-flex;align-items:center;
    padding:.22rem .58rem;border-radius:5px;
    font-size:.7rem;font-weight:600;font-family:var(--font);
    text-decoration:none;border:1px solid var(--border);
    background:rgba(255,255,255,.04);color:var(--text-2);
    cursor:pointer;transition:all .12s;gap:3px;
}
.act-btn:hover{color:var(--text);border-color:var(--border-hi)}
.act-btn.edit:hover   {color:#93c5fd;border-color:var(--accent-hi);background:var(--accent-dim)}
.act-btn.toggle:hover {color:#fcd34d;border-color:rgba(245,158,11,.3);background:var(--amber-dim)}
.act-btn.del:hover    {color:#fca5a5;border-color:var(--red-hi);background:var(--red-dim)}

/* ── FLASH ── */
.flash{
    padding:.8rem 1.1rem;border-radius:9px;font-size:.82rem;
    margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;
    animation:flashIn .25s ease;
}
@keyframes flashIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.flash-success{background:var(--green-dim);border:1px solid var(--green-hi);color:#6ee7b7}
.flash-error  {background:var(--red-dim);border:1px solid var(--red-hi);color:#fca5a5}

/* ── MODALS ── */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);
    z-index:500;display:none;align-items:center;justify-content:center;padding:1rem;
}
.modal-overlay.open{display:flex;animation:fadeIn .18s ease}
.modal{
    background:#1e293b;border:1px solid var(--border-hi);border-radius:14px;
    padding:1.5rem;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;
    animation:slideUp .22s ease;
}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem}
.modal-title{font-size:.95rem;font-weight:700}
.modal-close{cursor:pointer;color:var(--text-3);font-size:1.1rem;line-height:1;padding:.3rem;background:none;border:none;transition:color .12s;text-decoration:none}
.modal-close:hover{color:var(--text)}
.modal-foot{display:flex;gap:.65rem;justify-content:flex-end;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border)}

/* ── LOGOUT MODAL ── */
.logout-modal-overlay{
    position:fixed;inset:0;
    background:rgba(0,0,0,.65);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:1rem;
    animation:fadeIn .18s ease;
}
.logout-modal-overlay.open{display:flex}
.logout-modal{
    background:#1e293b;
    border:1px solid rgba(255,255,255,0.1);
    border-radius:16px;
    padding:2rem 2rem 1.75rem;
    width:100%;
    max-width:380px;
    box-shadow:0 24px 64px rgba(0,0,0,.6);
    animation:slideUp .22s cubic-bezier(.34,1.56,.64,1);
}
.logout-modal-icon{
    width:44px;height:44px;border-radius:12px;
    background:var(--red-dim);border:1px solid var(--red-hi);
    display:flex;align-items:center;justify-content:center;
    margin-bottom:1.1rem;
}
.logout-modal-icon svg{width:20px;height:20px;fill:none;stroke:#fca5a5;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.logout-modal h3{
    font-size:1.05rem;font-weight:700;
    color:var(--text);margin-bottom:.45rem;
}
.logout-modal p{
    font-size:.82rem;color:var(--text-2);
    line-height:1.55;margin-bottom:1.5rem;
}
.logout-modal-btns{
    display:flex;gap:.65rem;justify-content:flex-end;
}
.logout-modal-btns .btn-cancel{
    padding:.55rem 1.15rem;border-radius:9px;
    font-size:.82rem;font-weight:600;font-family:var(--font);
    background:rgba(255,255,255,.06);
    border:1px solid var(--border-hi);
    color:var(--text-2);cursor:pointer;
    transition:all .15s;
}
.logout-modal-btns .btn-cancel:hover{
    background:rgba(255,255,255,.1);color:var(--text);
}
.logout-modal-btns .btn-logout{
    padding:.55rem 1.25rem;border-radius:9px;
    font-size:.82rem;font-weight:600;font-family:var(--font);
    background:var(--red-dim);
    border:1px solid var(--red-hi);
    color:#fca5a5;cursor:pointer;
    transition:all .15s;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;
}
.logout-modal-btns .btn-logout:hover{
    background:rgba(239,68,68,.22);color:#fecaca;
    border-color:rgba(239,68,68,.5);
}

/* ── FORMS ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{display:flex;flex-direction:column;gap:.35rem}
.field.full{grid-column:1/-1}
.field.row-check{flex-direction:row;align-items:center;gap:.55rem}
label,.lbl{font-size:.64rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.09em}
.field.row-check label{text-transform:none;font-size:.84rem;letter-spacing:0;color:var(--text);margin:0;font-weight:500}
input[type=text],input[type=email],input[type=password],input[type=number],select,textarea{
    background:rgba(15,23,42,0.6);border:1px solid var(--border);border-radius:8px;
    color:var(--text);font-family:var(--font);font-size:.83rem;
    padding:.62rem .88rem;transition:border-color .15s,box-shadow .15s;width:100%;
}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent-hi);box-shadow:0 0 0 3px rgba(59,130,246,.07)}
input::placeholder,textarea::placeholder{color:var(--text-3)}
select option{background:#1e293b}
textarea{resize:vertical;min-height:82px}
input[type=checkbox]{width:auto;accent-color:var(--green)}

/* ── FILTER BAR ── */
.filter-bar{
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);padding:.85rem 1.1rem;margin-bottom:1.1rem;
    display:flex;gap:.65rem;align-items:center;flex-wrap:wrap;
    backdrop-filter:blur(12px);
}

/* ── PAGINATION ── */
.pagination{display:flex;gap:.35rem;justify-content:center;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--border)}
.pg-btn{
    padding:.32rem .65rem;border-radius:6px;font-size:.75rem;font-family:var(--mono);
    text-decoration:none;background:rgba(255,255,255,.05);
    color:var(--text-2);border:1px solid var(--border);transition:all .12s;
}
.pg-btn:hover{color:var(--text);border-color:var(--border-hi)}
.pg-btn.active{background:var(--accent-dim);color:#93c5fd;border-color:var(--accent-hi)}

/* ── SECTION LABEL ── */
.section-label{
    font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
    color:var(--text-3);display:flex;align-items:center;gap:.6rem;margin-bottom:.9rem;
}
.section-label::after{content:'';flex:1;height:1px;background:var(--border)}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}

/* ── RESPONSIVE ── */
@media(max-width:1280px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:1000px){.grid-2,.grid-dash,.grid-dash2{grid-template-columns:1fr}.grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:900px){
    .sidebar{transform:translateX(-100%)}
    .sidebar.open{transform:translateX(0)}
    .layout{margin-left:0}
    .topbar-toggle{display:flex}
    .page{padding:1rem}
}
@media(max-width:640px){.stats-grid,.form-grid,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ── LOGOUT CONFIRMATION MODAL ── -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <svg viewBox="0 0 24 24">
                <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
        </div>
        <h3>Sign out</h3>
        <p>Are you sure you want to log out of the admin panel?</p>
        <div class="logout-modal-btns">
            <button class="btn-cancel" onclick="closeLogoutModal()">Cancel</button>
            <a href="admin_logout.php" class="btn-logout">
                <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                Log out
            </a>
        </div>
    </div>
</div>

<aside class="sidebar" id="sidebar">
    <div class="sb-head">
        <div class="sb-logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Gurkha Marga" onerror="this.style.display='none'">
            <div class="sb-logo-text">
                <div class="brand">Gurkha Marga</div>
                <div class="sub">Administration</div>
            </div>
        </div>
        <div class="sb-admin-badge">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <?= htmlspecialchars($adminRole ?: 'Admin Panel') ?>
        </div>
    </div>

    <nav class="sb-nav">
        <div class="nav-section">Overview</div>
        <a href="admin_dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="admin_users.php" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Users
        </a>
        <a href="admin_staff.php" class="nav-link <?= $currentPage === 'staff' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            Staff
        </a>

        <div class="nav-section">Content</div>
        <a href="admin_workouts.php" class="nav-link <?= $currentPage === 'workouts' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="admin_questions.php" class="nav-link <?= $currentPage === 'questions' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
        </a>

        <div class="nav-section">Finance</div>
        <a href="balance.php" class="nav-link <?= $currentPage === 'balance' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Balance
        </a>

        <div class="nav-section">Account</div>
        <a href="admin_settings.php" class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <!-- Sidebar logout — opens custom modal -->
        <a href="#" class="nav-link danger" onclick="openLogoutModal(); return false;">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
    <div class="sb-footer">Gurkha Marga Admin &mdash; v2.0 &mdash; <?= date('Y') ?></div>
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
            <span class="topbar-role"><?= htmlspecialchars($adminRole ?: 'admin') ?></span>
            <span class="topbar-email"><?= htmlspecialchars($adminEmail) ?></span>
            <!-- Topbar logout — opens custom modal -->
            <button class="logout-link" onclick="openLogoutModal()">Logout</button>
        </div>
    </header>

    <div class="page">

    <?php if (!empty($flash['msg'])): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '&#10003;' : '&#9888;' ?>
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

<?php
function layoutFooter(): void {
    echo <<<HTML
    </div><!-- .page -->
</div><!-- .layout -->

<script>
/* ── Sidebar toggle ── */
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const sb     = document.getElementById('sidebar');
    const toggle = document.querySelector('.topbar-toggle');
    if (window.innerWidth <= 900 && sb && !sb.contains(e.target) && toggle && !toggle.contains(e.target)) {
        sb.classList.remove('open');
    }
});

/* ── Generic modals ── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});
function confirmDelete(url, msg) {
    if (confirm(msg || 'Delete this item? This cannot be undone.')) window.location.href = url;
}

/* ── Logout modal ── */
function openLogoutModal() {
    document.getElementById('logoutModal').classList.add('open');
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('open');
}
/* Close on backdrop click */
document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogoutModal();
});
/* Close on Escape */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogoutModal();
});

/* ── Flash auto-dismiss ── */
document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .4s';
        el.style.opacity    = '0';
        setTimeout(() => el.remove(), 400);
    }, 4500);
});

/* ── Animate progress bars ── */
document.querySelectorAll('[data-w]').forEach(el => {
    el.style.width = el.dataset.w + '%';
});
</script>
</body>
</html>
HTML;
}