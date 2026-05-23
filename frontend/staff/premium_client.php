<?php
// frontend/staff/premium_client.php  —  Premium Clients list for staff
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['staff_logged_in'])) { header('Location: ../auth/login.php'); exit(); }

$staffId   = (int)$_SESSION['staff_id'];
$staffName = $_SESSION['staff_name']  ?? '';
$staffRole = $_SESSION['staff_role']  ?? 'consultant';

// ── Ensure columns exist ──────────────────────────────────────────────────────
try { query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_premium TINYINT(1) NOT NULL DEFAULT 0", []); } catch(Exception $e){}
try { query("ALTER TABLE users ADD COLUMN IF NOT EXISTS premium_since DATETIME DEFAULT NULL", []); } catch(Exception $e){}

// Sync premium status from active subscriptions
try {
    query("UPDATE users u
           INNER JOIN (
               SELECT user_id FROM subscriptions WHERE status='active' AND end_date >= NOW() GROUP BY user_id
           ) s ON u.id = s.user_id
           SET u.is_premium=1, u.premium_since=COALESCE(u.premium_since,NOW())", []);
} catch(Exception $e){}

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'recent';
$force  = $_GET['force'] ?? '';

$roleColumn = ($staffRole === 'dietitian') ? 'uc.dietitian_id' : 'uc.consultant_id';
$where  = ["$roleColumn = $staffId", "uc.is_active = 1"];
$params = [];

if ($search) {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($force) {
    $where[] = 'u.target_force=?';
    $params[] = $force;
}

$orderBy = match($sort) {
    'name'   => 'u.full_name ASC',
    'bmi'    => 'bmi_val ASC',
    default  => 'uc.updated_at DESC'
};

$clients = fetchAll(
    "SELECT u.*,
        ROUND(u.weight / POW(u.height/100, 2), 1) AS bmi_val
     FROM users u
     INNER JOIN user_consultants uc ON uc.user_id = u.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY " . $orderBy,
    $params
) ?: [];

// ── Per-client note/consult counts ────────────────────────────────────────────
$clientMeta = [];
foreach ($clients as $c) {
    $nc = 0; $cc = 0; $lastMsg = null;
    try { $nc = (int)(fetchOne("SELECT COUNT(*) AS n FROM staff_notes WHERE user_id=? AND staff_id=?", [$c['id'],$staffId])['n'] ?? 0); } catch(Exception $e){}
    try { $cc = (int)(fetchOne("SELECT COUNT(*) AS n FROM consultations WHERE user_id=? AND staff_id=?", [$c['id'],$staffId])['n'] ?? 0); } catch(Exception $e){}
    try {
        $lastMsg = fetchOne(
            "SELECT body,message_type,created_at,sender_type FROM chat_messages
             WHERE (sender_id=? AND consultant_id=?) OR (consultant_id=? AND sender_id=?)
             ORDER BY created_at DESC LIMIT 1",
            [$c['id'],$staffId,$staffId,$c['id']]
        );
    } catch(Exception $e){}
    $clientMeta[$c['id']] = ['notes'=>$nc,'consults'=>$cc,'last_msg'=>$lastMsg];
}

// ── Unread messages per user ──────────────────────────────────────────────────
$unreadPerUser = [];
try {
    $rows = fetchAll(
        "SELECT sender_id, COUNT(*) AS cnt FROM chat_messages
         WHERE consultant_id=? AND sender_type='user' AND is_read=0
         GROUP BY sender_id",
        [$staffId]
    ) ?: [];
    foreach ($rows as $r) $unreadPerUser[$r['sender_id']] = (int)$r['cnt'];
} catch(Exception $e){}

$totalUnread = array_sum($unreadPerUser);

// ── Helpers ───────────────────────────────────────────────────────────────────
$forceMap  = [
    'british'   => ['British Army',       'GB'],
    'nepal'     => ['Nepal Army',          'NP'],
    'indian'    => ['Indian Army',         'IN'],
    'singapore' => ['Singapore Police',   'SG'],
    'french'    => ['French Foreign Legion','FR'],
];
$forces    = array_keys($forceMap);

$accentMap = [
    'dietitian'  => ['#06b6d4','6,182,212'],
    'consultant' => ['#8b5cf6','139,92,246'],
    'admin'      => ['#3b82f6','59,130,246'],
    'superadmin' => ['#3b82f6','59,130,246'],
];
$ra        = $accentMap[$staffRole] ?? $accentMap['consultant'];
$accentHex = $ra[0]; $accentRgb = $ra[1];

function bmiData(float|null $bmi): array {
    if (!$bmi) return ['—', 'muted', '#64748b'];
    if ($bmi < 18.5) return ['Underweight', 'blue',  '#93c5fd'];
    if ($bmi < 25)   return ['Healthy',     'green', '#6ee7b7'];
    if ($bmi < 30)   return ['Overweight',  'gold',  '#fcd34d'];
    return ['Obese', 'red', '#fca5a5'];
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60).'m ago';
    if ($diff < 86400) return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('M j', strtotime($dt));
}
function av(string $name): string { return strtoupper(substr(trim($name),0,1)); }

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalBmi = 0; $bmiCount = 0; $forceCount = [];
foreach ($clients as $c) {
    if ($c['bmi_val']) { $totalBmi += $c['bmi_val']; $bmiCount++; }
    $f = $c['target_force'] ?? 'unknown';
    $forceCount[$f] = ($forceCount[$f]??0)+1;
}
$avgBmi = $bmiCount ? round($totalBmi/$bmiCount,1) : 0;
arsort($forceCount);
$topForce = array_key_first($forceCount) ?? '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Clients — Gurkha Marga Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --accent:     <?= $accentHex ?>;
    --accent-rgb: <?= $accentRgb ?>;
    --bg:         #0f172a;
    --surface:    rgba(30,41,59,.55);
    --panel:      #0f172a;
    --border:     rgba(255,255,255,.06);
    --border-hi:  rgba(255,255,255,.11);
    --text:       #f8fafc;
    --text-2:     #cbd5e1;
    --text-3:     #64748b;
    --gold:       #f59e0b;
    --green:      #10b981;
    --red:        #ef4444;
    --blue:       #3b82f6;
    --purple:     #8b5cf6;
    --font:       'Poppins',sans-serif;
    --mono:       'Courier New',monospace;
    --r:          10px;
    --sidebar:    252px;
}
html,body{min-height:100vh;background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#0f172a 100%);color:var(--text);font-family:var(--font)}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(var(--accent-rgb),.05),transparent);pointer-events:none;z-index:0}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:2px}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh;position:relative;z-index:1}

/* ── SIDEBAR ── */
.sb{width:var(--sidebar);flex-shrink:0;background:rgba(10,17,32,.97);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.sb-logo{padding:1.3rem 1.1rem .9rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
.sb-mark{width:36px;height:36px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden;background:transparent}
.sb-mark img{width:36px;height:36px;object-fit:contain}
.sb-brand{font-size:.95rem;font-weight:800;color:var(--gold);letter-spacing:-.02em}
.sb-sub{font-size:.55rem;color:var(--text-3);letter-spacing:.14em;text-transform:uppercase;margin-top:1px}
.sb-user{margin:.75rem .8rem;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:.65rem .8rem;display:flex;align-items:center;gap:.65rem}
.sb-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0}
.sb-uname{font-size:.78rem;font-weight:600}
.sb-urole{font-size:.62rem;color:var(--accent);text-transform:capitalize}
.sb-pulse{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 5px var(--green);margin-left:auto;animation:pulse 2.5s infinite;flex-shrink:0}
.sb-nav{flex:1;padding:.25rem 0}
.nav-sec{font-size:.56rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(71,85,105,.55);padding:.7rem 1.1rem .25rem}
.nav-a{display:flex;align-items:center;gap:.65rem;padding:.48rem 1.1rem;color:var(--text-3);text-decoration:none;font-size:.8rem;font-weight:500;border-left:2px solid transparent;transition:all .14s}
.nav-a:hover{color:var(--text-2);background:rgba(255,255,255,.03)}
.nav-a.active{color:var(--text);background:rgba(255,255,255,.05);border-left-color:var(--accent)}
.nav-a.danger{color:#f87171}.nav-a.danger:hover{background:rgba(239,68,68,.06)}
.nav-ico{width:14px;text-align:center;flex-shrink:0;opacity:.65}
.nav-a.active .nav-ico{opacity:1}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:.57rem;font-weight:700;padding:.08rem .38rem;border-radius:99px;min-width:16px;text-align:center}

/* ── MAIN ── */
.main{flex:1;min-width:0}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:50;background:rgba(10,17,32,.88);backdrop-filter:blur(24px);border-bottom:1px solid var(--border);padding:.75rem 1.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.breadcrumb{font-size:.7rem;color:var(--text-3);display:flex;align-items:center;gap:.4rem}
.breadcrumb a{color:var(--text-2);text-decoration:none;transition:color .14s}.breadcrumb a:hover{color:var(--text)}
.tb-right{display:flex;align-items:center;gap:.6rem}
.top-chip{font-size:.67rem;padding:.18rem .6rem;border-radius:20px;font-weight:600;white-space:nowrap}
.top-chip.gold{background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.18);color:var(--gold)}
.top-chip.red{background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.18);color:#fca5a5;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;transition:all .14s}
.top-chip.red:hover{background:rgba(239,68,68,.14)}

/* ── PAGE ── */
.page{padding:1.6rem 1.75rem 3rem}
.pg-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.4rem;flex-wrap:wrap}
.pg-title{font-size:1.3rem;font-weight:800;letter-spacing:-.03em}
.pg-sub{font-size:.78rem;color:var(--text-3);margin-top:.3rem}

/* ── STAT STRIP ── */
.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.4rem}
.stat-s{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:.9rem 1rem;position:relative;overflow:hidden;transition:border-color .16s}
.stat-s:hover{border-color:var(--border-hi)}
.stat-s::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);opacity:.6}
.stat-s.g::before{background:var(--green)}.stat-s.gold::before{background:var(--gold)}.stat-s.r::before{background:var(--red)}
.stat-s-lbl{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);margin-bottom:.5rem}
.stat-s-val{font-size:1.55rem;font-weight:700;letter-spacing:-.03em;font-family:var(--mono);line-height:1}
.stat-s-sub{font-size:.67rem;color:var(--text-3);margin-top:.25rem}

/* ── FILTERS ── */
.filters{display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;flex-wrap:wrap}
.search-wrap{position:relative;flex:1;max-width:300px}
.search-wrap svg{position:absolute;left:.72rem;top:50%;transform:translateY(-50%);width:13px;height:13px;color:var(--text-3);pointer-events:none}
.search-inp{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:20px;color:var(--text);font-family:var(--font);font-size:.8rem;padding:.4rem 1rem .4rem 2.2rem;outline:none;transition:all .18s}
.search-inp:focus{border-color:rgba(var(--accent-rgb),.32);background:rgba(255,255,255,.06)}
.filter-select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:20px;color:var(--text-2);font-family:var(--font);font-size:.76rem;padding:.38rem .8rem;outline:none;cursor:pointer;transition:all .16s}
.filter-select:focus{border-color:rgba(var(--accent-rgb),.28)}
.filter-select option{background:#1e293b}
.filter-btn{padding:.38rem .8rem;border-radius:20px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text-3);font-family:var(--font);font-size:.76rem;cursor:pointer;transition:all .14s;text-decoration:none;white-space:nowrap}
.filter-btn:hover{border-color:var(--border-hi);color:var(--text)}

/* ── VIEW TOGGLE ── */
.view-toggle{display:flex;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:20px;padding:2px;gap:2px;margin-left:auto}
.vt-btn{padding:.28rem .65rem;border-radius:18px;border:none;background:transparent;color:var(--text-3);font-family:var(--font);font-size:.73rem;cursor:pointer;transition:all .14s;display:flex;align-items:center;gap:.3rem}
.vt-btn.active{background:rgba(var(--accent-rgb),.14);color:var(--accent)}

/* ── CLIENT GRID ── */
.client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(295px,1fr));gap:.9rem}
.cc{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;display:block;transition:border-color .18s,transform .16s,box-shadow .18s;position:relative;animation:fadeUp .4s ease both}
.cc:hover{border-color:rgba(var(--accent-rgb),.28);transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,0,0,.25)}
.cc-top{padding:1.05rem 1.05rem .7rem;display:flex;align-items:flex-start;gap:.8rem}
.cc-av{width:42px;height:42px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),var(--purple))}
.cc-name{font-weight:700;font-size:.88rem;letter-spacing:-.01em;line-height:1.25}
.cc-email{font-size:.69rem;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.cc-chips{display:flex;gap:.3rem;margin-top:.45rem;flex-wrap:wrap}
.chip{font-size:.58rem;padding:.1rem .4rem;border-radius:4px;font-weight:600;white-space:nowrap;letter-spacing:.04em}
.chip-force {background:rgba(255,255,255,.05);color:var(--text-3);border:1px solid var(--border);text-transform:uppercase}
.chip-lvl   {background:rgba(var(--accent-rgb),.09);color:var(--accent);border:1px solid rgba(var(--accent-rgb),.18)}
.chip-prem  {background:rgba(245,158,11,.09);color:var(--gold);border:1px solid rgba(245,158,11,.18)}
.chip-unread{background:rgba(239,68,68,.09);color:#fca5a5;border:1px solid rgba(239,68,68,.18)}
.cc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem;padding:.6rem 1.05rem;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.cc-m{text-align:center}
.cc-mv{font-family:var(--mono);font-size:.88rem;font-weight:600}
.cc-ml{font-size:.58rem;color:var(--text-3);margin-top:1px;text-transform:uppercase;letter-spacing:.05em}
.cc-footer{padding:.6rem 1.05rem;display:flex;align-items:center;justify-content:space-between;gap:.45rem}
.cc-last{font-size:.69rem;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.cc-actions{display:flex;gap:.3rem;flex-shrink:0}
.cc-act{padding:.26rem .6rem;border-radius:18px;font-size:.68rem;font-weight:600;text-decoration:none;transition:all .14s;white-space:nowrap}
.cc-act.msg {background:rgba(var(--accent-rgb),.1);border:1px solid rgba(var(--accent-rgb),.22);color:var(--accent)}
.cc-act.msg:hover{background:rgba(var(--accent-rgb),.18)}
.cc-unread-dot{position:absolute;top:.7rem;right:.7rem;width:7px;height:7px;border-radius:50%;background:var(--red);box-shadow:0 0 5px var(--red)}

/* ── TABLE VIEW ── */
.tbl-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.data-table{width:100%;border-collapse:collapse}
.data-table th{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);padding:.55rem .85rem;text-align:left;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);white-space:nowrap}
.data-table td{padding:.7rem .85rem;font-size:.8rem;border-bottom:1px solid rgba(255,255,255,.035);vertical-align:middle}
.data-table tbody tr:hover{background:rgba(255,255,255,.02)}
.data-table tbody tr:last-child td{border-bottom:none}
.td-av{display:flex;align-items:center;gap:.7rem}
.tbl-av{width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),var(--purple))}

/* ── BADGES ── */
.badge{display:inline-block;padding:.16rem .5rem;border-radius:4px;font-size:.63rem;font-weight:600;white-space:nowrap}
.b-green{background:rgba(16,185,129,.09);color:#6ee7b7;border:1px solid rgba(16,185,129,.18)}
.b-red  {background:rgba(239,68,68,.09); color:#fca5a5;border:1px solid rgba(239,68,68,.18)}
.b-gold {background:rgba(245,158,11,.09);color:#fcd34d;border:1px solid rgba(245,158,11,.18)}
.b-blue {background:rgba(59,130,246,.09);color:#93c5fd;border:1px solid rgba(59,130,246,.18)}
.b-muted{background:rgba(255,255,255,.05);color:var(--text-3);border:1px solid var(--border)}
.force-tag{font-size:.58rem;font-weight:700;letter-spacing:.07em;padding:.1rem .4rem;border-radius:3px;background:rgba(255,255,255,.05);color:var(--text-3);border:1px solid var(--border);text-transform:uppercase}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:4rem 2rem;color:var(--text-3)}
.empty-title{font-size:.86rem;font-weight:600;color:var(--text-2);margin-bottom:.3rem}
.empty-text{font-size:.76rem}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.45)}50%{box-shadow:0 0 0 5px rgba(16,185,129,0)}}
@media(max-width:1100px){.stat-strip{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.sb{display:none}.client-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.client-grid{grid-template-columns:1fr}.stat-strip{grid-template-columns:1fr 1fr}}

/* ── LOGOUT MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9000;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:#1e293b;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:1.75rem 1.5rem 1.25rem;width:100%;max-width:320px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.modal-title{font-size:.95rem;font-weight:700;margin-bottom:.4rem}
.modal-body{font-size:.8rem;color:var(--text-2);line-height:1.6;margin-bottom:1.25rem}
.modal-actions{display:flex;gap:.6rem;justify-content:flex-end}
.mbtn{padding:.48rem 1.1rem;border-radius:8px;font-size:.8rem;font-weight:600;font-family:var(--font);cursor:pointer;border:1px solid;transition:all .15s}
.mbtn-cancel{background:transparent;border-color:var(--border-hi);color:var(--text-2)}
.mbtn-cancel:hover{background:rgba(255,255,255,.06);color:var(--text)}
.mbtn-confirm{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#fca5a5}
.mbtn-confirm:hover{background:rgba(239,68,68,.22)}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sb">
    <div class="sb-logo">
        <div class="sb-mark"><img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="GM"></div>
        <div><div class="sb-brand">Gurkha Marga</div><div class="sb-sub">Staff Portal</div></div>
    </div>
    <div class="sb-user">
        <div class="sb-av"><?= av($staffName) ?></div>
        <div><div class="sb-uname"><?= htmlspecialchars($staffName) ?></div><div class="sb-urole"><?= htmlspecialchars($staffRole) ?></div></div>
        <div class="sb-pulse"></div>
    </div>
    <nav class="sb-nav">
        <div class="nav-sec">Main</div>
        <a href="premium_client.php" class="nav-a active">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            My Clients
        </a>
        <div class="nav-sec">Communicate</div>
        <a href="premium_message.php" class="nav-a">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            Messages
            <?php if ($totalUnread > 0): ?><span class="nav-badge"><?= $totalUnread ?></span><?php endif; ?>
        </a>
        <div class="nav-sec">Account</div>
        <a href="staff_dashboard.php?page=settings" class="nav-a">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="#" class="nav-a danger" onclick="showLogoutModal();return false;">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- MAIN -->
<div class="main">
    <header class="topbar">
        <nav class="breadcrumb">
            <a href="staff_dashboard.php">Staff Portal</a>
            <span>/</span>
            <span>My Clients</span>
        </nav>
        <div class="tb-right">
            <span class="top-chip gold"><?= count($clients) ?> Assigned</span>
            <?php if ($totalUnread > 0): ?>
            <a href="premium_message.php" class="top-chip red"><?= $totalUnread ?> unread</a>
            <?php endif; ?>
            <span style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)"><?= date('D, d M Y') ?></span>
        </div>
    </header>

    <div class="page">

        <div class="pg-hdr">
            <div>
                <h1 class="pg-title">My Clients</h1>
                <p class="pg-sub"><?= count($clients) ?> active <?= count($clients)===1?'member':'members' ?> assigned to you</p>
            </div>
        </div>

        <!-- Stat strip -->
        <div class="stat-strip">
            <div class="stat-s">
                <div class="stat-s-lbl">Total Clients</div>
                <div class="stat-s-val"><?= count($clients) ?></div>
                <div class="stat-s-sub">Assigned to you</div>
            </div>
            <div class="stat-s g">
                <div class="stat-s-lbl">Avg BMI</div>
                <div class="stat-s-val"><?= $avgBmi ?: '—' ?></div>
                <div class="stat-s-sub">Across all clients</div>
            </div>
            <div class="stat-s gold">
                <div class="stat-s-lbl">Unread Messages</div>
                <div class="stat-s-val"><?= $totalUnread ?></div>
                <div class="stat-s-sub">Needs response</div>
            </div>
            <div class="stat-s r">
                <div class="stat-s-lbl">Top Force</div>
                <div class="stat-s-val" style="font-size:1.1rem"><?= ($forceMap[$topForce][0] ?? ucfirst($topForce)) ?></div>
                <div class="stat-s-sub"><?= $forceCount[$topForce] ?? 0 ?> clients</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters" id="filterForm">
            <div class="search-wrap">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input class="search-inp" type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <select class="filter-select" name="force" onchange="this.form.submit()">
                <option value="">All Forces</option>
                <?php foreach ($forces as $f): ?>
                <option value="<?= $f ?>" <?= $force===$f?'selected':'' ?>><?= $forceMap[$f][1] ?> &mdash; <?= $forceMap[$f][0] ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-select" name="sort" onchange="this.form.submit()">
                <option value="recent" <?= $sort==='recent'?'selected':'' ?>>Most Recent</option>
                <option value="name"   <?= $sort==='name'?'selected':'' ?>>Name A&ndash;Z</option>
                <option value="bmi"    <?= $sort==='bmi'?'selected':'' ?>>BMI</option>
            </select>
            <?php if ($search||$force): ?>
            <a href="premium_client.php" class="filter-btn">Clear filters</a>
            <?php endif; ?>
            <div class="view-toggle">
                <button type="button" class="vt-btn active" id="gridBtn" onclick="setView('grid')">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    Grid
                </button>
                <button type="button" class="vt-btn" id="listBtn" onclick="setView('list')">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    List
                </button>
            </div>
        </form>

        <!-- GRID VIEW -->
        <div id="gridView">
            <?php if (empty($clients)): ?>
            <div class="empty">
                <div class="empty-title">No clients assigned yet<?= $search?' matching your search':'' ?></div>
                <div class="empty-text">Clients will appear here once users select you from the Expert Team page.</div>
            </div>
            <?php else: ?>
            <div class="client-grid">
                <?php foreach ($clients as $idx => $c):
                    $bmi = $c['bmi_val'];
                    [$bmiCat, $bmiClass, $bmiColor] = bmiData((float)$bmi);
                    $meta    = $clientMeta[$c['id']] ?? [];
                    $unread  = $unreadPerUser[$c['id']] ?? 0;
                    $lm      = $meta['last_msg'] ?? null;
                    $lastTxt = '';
                    if ($lm) {
                        $pfx = $lm['sender_type']==='user' ? 'Client: ' : 'You: ';
                        $lastTxt = $pfx . mb_substr($lm['body']??'',0,42).(mb_strlen($lm['body']??'')>42?'…':'');
                    }
                    $fi = $forceMap[$c['target_force'] ?? ''] ?? [ucfirst($c['target_force']??'—'),'—'];
                ?>
                <div class="cc" style="animation-delay:<?= $idx*.04 ?>s">
                    <?php if ($unread): ?><div class="cc-unread-dot"></div><?php endif; ?>
                    <div class="cc-top">
                        <div class="cc-av"><?= av($c['full_name']) ?></div>
                        <div style="flex:1;min-width:0">
                            <div class="cc-name"><?= htmlspecialchars($c['full_name']) ?></div>
                            <div class="cc-email"><?= htmlspecialchars($c['email']) ?></div>
                            <div class="cc-chips">
                                <?php if ($c['is_premium'] ?? 0): ?><span class="chip chip-prem">Premium</span><?php endif; ?>
                                <span class="chip chip-force"><?= $fi[1] ?></span>
                                <span class="chip chip-lvl"><?= ucfirst($c['experience_level']??'—') ?></span>
                                <?php if ($unread): ?><span class="chip chip-unread"><?= $unread ?> new</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="cc-metrics">
                        <div class="cc-m"><div class="cc-mv"><?= $bmi ?: '—' ?></div><div class="cc-ml">BMI</div></div>
                        <div class="cc-m"><div class="cc-mv" style="color:<?= $bmiColor ?>;font-size:.73rem"><?= $bmiCat ?></div><div class="cc-ml">Status</div></div>
                        <div class="cc-m"><div class="cc-mv"><?= $c['weight']?$c['weight'].'kg':'—' ?></div><div class="cc-ml">Weight</div></div>
                    </div>
                    <div class="cc-footer">
                        <div class="cc-last"><?= $lastTxt ? htmlspecialchars($lastTxt) : '<span style="color:var(--text-3)">No messages yet</span>' ?></div>
                        <div class="cc-actions">
                            <!-- REMOVED: Profile button — only Chat remains -->
                            <a href="premium_message.php?uid=<?= $c['id'] ?>" class="cc-act msg">Chat</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- LIST VIEW -->
        <div id="listView" style="display:none">
            <?php if (empty($clients)): ?>
            <div class="empty"><div class="empty-title">No clients assigned yet</div></div>
            <?php else: ?>
            <div class="tbl-wrap">
                <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Force</th>
                            <th>Level</th>
                            <th>BMI</th>
                            <th>Weight</th>
                            <th>Height</th>
                            <th>Age</th>
                            <th>Notes</th>
                            <th>Consults</th>
                            <th>Last Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $c):
                            $bmi = $c['bmi_val'];
                            [$bmiCat,$bmiClass,$bmiColor] = bmiData((float)$bmi);
                            $meta   = $clientMeta[$c['id']] ?? [];
                            $unread = $unreadPerUser[$c['id']] ?? 0;
                            $fi     = $forceMap[$c['target_force']??''] ?? [ucfirst($c['target_force']??'—'),'—'];
                            $lm     = $meta['last_msg'] ?? null;
                        ?>
                        <tr>
                            <td>
                                <div class="td-av">
                                    <div class="tbl-av"><?= av($c['full_name']) ?></div>
                                    <div>
                                        <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($c['full_name']) ?></div>
                                        <div style="font-size:.68rem;color:var(--text-3)"><?= htmlspecialchars($c['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="force-tag"><?= $fi[1] ?></span></td>
                            <td><span class="badge b-blue"><?= ucfirst($c['experience_level']??'—') ?></span></td>
                            <td><span style="font-family:var(--mono);color:<?= $bmiColor ?>;font-size:.8rem"><?= $bmi ?: '—' ?></span></td>
                            <td style="font-family:var(--mono);font-size:.78rem"><?= $c['weight']?$c['weight'].' kg':'—' ?></td>
                            <td style="font-family:var(--mono);font-size:.78rem"><?= $c['height']?$c['height'].' cm':'—' ?></td>
                            <td style="font-family:var(--mono);font-size:.78rem"><?= (int)$c['age'] ?></td>
                            <td style="font-family:var(--mono);font-size:.78rem"><?= $meta['notes'] ?? 0 ?></td>
                            <td style="font-family:var(--mono);font-size:.78rem"><?= $meta['consults'] ?? 0 ?></td>
                            <td style="font-size:.7rem;color:var(--text-3)"><?= $lm ? timeAgo($lm['created_at']) : '—' ?></td>
                            <td>
                                <div style="display:flex;gap:.3rem;align-items:center">
                                    <?php if ($unread): ?><span class="badge b-red" style="font-size:.6rem"><?= $unread ?></span><?php endif; ?>
                                    <!-- REMOVED: Profile button — only Chat remains -->
                                    <a href="premium_message.php?uid=<?= $c['id'] ?>" class="cc-act msg" style="font-size:.67rem">Chat</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>

<script>
function setView(v) {
    const grid = document.getElementById('gridView');
    const list = document.getElementById('listView');
    const gb = document.getElementById('gridBtn');
    const lb = document.getElementById('listBtn');
    if (v === 'grid') {
        grid.style.display = ''; list.style.display = 'none';
        gb.classList.add('active'); lb.classList.remove('active');
    } else {
        grid.style.display = 'none'; list.style.display = '';
        lb.classList.add('active'); gb.classList.remove('active');
    }
    localStorage.setItem('staff_view', v);
}
const pv = localStorage.getItem('staff_view');
if (pv) setView(pv);

document.querySelector('.search-inp')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('filterForm').submit();
});
function showLogoutModal(){document.getElementById('logoutModal').classList.add('show')}
function hideLogoutModal(){document.getElementById('logoutModal').classList.remove('show')}
document.addEventListener('keydown',e=>{if(e.key==='Escape')hideLogoutModal()});
</script>
<div class="modal-overlay" id="logoutModal" onclick="if(event.target===this)hideLogoutModal()">
    <div class="modal-box">
        <div class="modal-title">Sign out</div>
        <div class="modal-body">Are you sure you want to log out of the staff portal?</div>
        <div class="modal-actions">
            <button class="mbtn mbtn-cancel" onclick="hideLogoutModal()">Cancel</button>
            <a href="../auth/logout.php" class="mbtn mbtn-confirm" style="text-decoration:none">Log out</a>
        </div>
    </div>
</div>
</body>
</html>