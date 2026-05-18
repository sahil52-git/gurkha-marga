<?php
// frontend/staff/premium_client.php  —  Premium Clients list for staff
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['staff_logged_in'])) { header('Location: ../auth/login.php'); exit(); }

$staffId   = (int)$_SESSION['staff_id'];
$staffName = $_SESSION['staff_name']  ?? '';
$staffRole = $_SESSION['staff_role']  ?? 'consultant';

// ── Ensure is_premium exists ─────────────────────────────────────────────────
try { query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_premium TINYINT(1) NOT NULL DEFAULT 0", []); } catch(Exception $e){}
try { query("ALTER TABLE users ADD COLUMN IF NOT EXISTS premium_since DATETIME DEFAULT NULL", []); } catch(Exception $e){}

// Sync premium status from active subscriptions (if subscriptions table exists)
try {
    query("UPDATE users u
           INNER JOIN (
               SELECT user_id FROM subscriptions WHERE status='active' AND end_date >= NOW() GROUP BY user_id
           ) s ON u.id = s.user_id
           SET u.is_premium=1, u.premium_since=COALESCE(u.premium_since,NOW())", []);
} catch(Exception $e){}

// ── Search / filter ──────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'recent';
$force  = $_GET['force'] ?? '';

// Determine which column to filter by based on staff role
// Dietitians appear in dietitian_id column, consultants in consultant_id column
$roleColumn = ($staffRole === 'dietitian') ? 'uc.dietitian_id' : 'uc.consultant_id';

// Scope clients to ONLY users who have assigned THIS specific staff member
$where  = ["$roleColumn = $staffId", "uc.is_active = 1"];
$params = [];

if ($search) {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
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

// ── Per-client note/consult counts from this staff ───────────────────────────
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

// ── Unread messages count per user ────────────────────────────────────────────
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
$forceMap  = ['british'=>['British Army','🇬🇧'],'nepal'=>['Nepal Army','🇳🇵'],'indian'=>['Indian Army','🇮🇳'],'singapore'=>['Singapore Police','🇸🇬'],'french'=>['French Foreign Legion','🇫🇷']];
$forces    = array_keys($forceMap);
$accentMap = ['dietitian'=>['#06b6d4','6,182,212'],'consultant'=>['#8b5cf6','139,92,246'],'admin'=>['#3b82f6','59,130,246'],'superadmin'=>['#3b82f6','59,130,246']];
$ra        = $accentMap[$staffRole] ?? $accentMap['consultant'];
$accentHex = $ra[0]; $accentRgb = $ra[1];

function bmiCategory(float|null $bmi): array {
    if (!$bmi) return ['—','#64748b'];
    if ($bmi < 18.5) return ['Underweight','#3b82f6'];
    if ($bmi < 25)   return ['Healthy','#22c55e'];
    if ($bmi < 30)   return ['Overweight','#f59e0b'];
    return ['Obese','#ef4444'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Clients — Gurkha Marga Staff</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --accent:     <?= $accentHex ?>;
    --accent-rgb: <?= $accentRgb ?>;
    --bg:         #030712;
    --surface:    rgba(15,23,42,.8);
    --panel:      #0f172a;
    --border:     rgba(255,255,255,.07);
    --border-hi:  rgba(255,255,255,.13);
    --text:       #f1f5f9;
    --text-2:     #94a3b8;
    --text-3:     #475569;
    --gold:       #f59e0b;
    --green:      #22c55e;
    --red:        #ef4444;
    --font:       'DM Sans',sans-serif;
    --mono:       'DM Mono',monospace;
    --r:          12px;
    --sidebar:    240px;
}
html,body{min-height:100vh;background:var(--bg);color:var(--text);font-family:var(--font)}
body{background-image:radial-gradient(ellipse 80% 60% at 50% -20%,rgba(<?= $accentRgb ?>,.07),transparent)}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:2px}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sb{width:var(--sidebar);flex-shrink:0;background:rgba(3,7,18,.95);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.sb-logo{padding:1.25rem 1rem .9rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem}
.sb-ico{width:34px;height:34px;background:linear-gradient(135deg,var(--gold),#d97706);border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.8rem;color:#000;font-family:var(--mono);flex-shrink:0}
.sb-brand{font-size:.95rem;font-weight:800;color:var(--gold)}
.sb-sub{font-size:.58rem;color:var(--text-3);letter-spacing:.1em;text-transform:uppercase;margin-top:1px}
.sb-user{margin:.65rem .75rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r);padding:.6rem .8rem;display:flex;align-items:center;gap:.6rem}
.sb-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:#fff;flex-shrink:0}
.sb-uname{font-size:.78rem;font-weight:600}
.sb-urole{font-size:.62rem;color:var(--accent);text-transform:capitalize}
.sb-pulse{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);margin-left:auto;animation:pulse 2s infinite;flex-shrink:0}
.sb-nav{flex:1;padding:.3rem 0}
.nav-sec{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(71,85,105,.7);padding:.7rem 1rem .25rem}
.nav-a{display:flex;align-items:center;gap:.65rem;padding:.5rem 1rem;color:var(--text-2);text-decoration:none;font-size:.82rem;font-weight:500;border-left:2px solid transparent;transition:all .15s}
.nav-a:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-a.active{color:var(--text);background:rgba(255,255,255,.05);border-left-color:var(--accent)}
.nav-a.danger{color:#f87171}
.nav-a.danger:hover{background:rgba(239,68,68,.06)}
.nav-ico{width:15px;text-align:center;flex-shrink:0;font-size:.88rem}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:.6rem;font-weight:700;padding:.08rem .38rem;border-radius:99px;min-width:16px;text-align:center}

/* ── MAIN ── */
.main{flex:1;min-width:0}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:50;background:rgba(3,7,18,.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:.8rem 1.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.tb-left{display:flex;align-items:center;gap:.6rem}
.breadcrumb{font-size:.73rem;color:var(--text-3);display:flex;align-items:center;gap:.4rem}
.breadcrumb a{color:var(--text-2);text-decoration:none}
.breadcrumb a:hover{color:var(--text)}
.tb-right{display:flex;align-items:center;gap:.65rem}
.top-badge{font-size:.68rem;padding:.18rem .65rem;border-radius:20px;font-weight:600;white-space:nowrap}
.top-badge.gold{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:var(--gold)}
.top-badge.red{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}

/* ── PAGE ── */
.page{padding:1.6rem 1.75rem 3rem}

/* ── PAGE HEADER ── */
.pg-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
.pg-title{font-size:1.35rem;font-weight:800;letter-spacing:-.03em;display:flex;align-items:center;gap:.5rem}
.pg-sub{font-size:.78rem;color:var(--text-3);margin-top:.3rem}

/* ── FILTERS ── */
.filters{display:flex;align-items:center;gap:.65rem;margin-bottom:1.25rem;flex-wrap:wrap}
.search-wrap{position:relative;flex:1;max-width:320px}
.search-wrap svg{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--text-3)}
.search-inp{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:20px;color:var(--text);font-family:var(--font);font-size:.82rem;padding:.42rem 1rem .42rem 2.25rem;outline:none;transition:all .2s}
.search-inp:focus{border-color:rgba(var(--accent-rgb),.35);background:rgba(255,255,255,.07)}
.filter-select{background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:20px;color:var(--text-2);font-family:var(--font);font-size:.78rem;padding:.4rem .85rem;outline:none;cursor:pointer;transition:all .2s}
.filter-select:focus{border-color:rgba(var(--accent-rgb),.3)}
.filter-select option{background:#0f172a}
.filter-btn{padding:.4rem .85rem;border-radius:20px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text-2);font-family:var(--font);font-size:.78rem;cursor:pointer;transition:all .15s;text-decoration:none;white-space:nowrap}
.filter-btn:hover{border-color:var(--border-hi);color:var(--text)}
.filter-btn.active{background:rgba(var(--accent-rgb),.12);border-color:rgba(var(--accent-rgb),.3);color:var(--accent)}

/* ── STAT STRIP ── */
.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.5rem}
.stat-s{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:.9rem 1rem;position:relative;overflow:hidden}
.stat-s::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);opacity:.6}
.stat-s.g::before{background:var(--green)}.stat-s.gold::before{background:var(--gold)}.stat-s.r::before{background:var(--red)}
.stat-s-val{font-size:1.55rem;font-weight:700;letter-spacing:-.03em;font-family:var(--mono)}
.stat-s-lbl{font-size:.63rem;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}

/* ── CLIENT GRID ── */
.client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem}
.cc{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;display:block;transition:border-color .2s,transform .18s,box-shadow .2s;position:relative;animation:fadeUp .4s ease both}
.cc:hover{border-color:rgba(var(--accent-rgb),.3);transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,0,0,.3)}
.cc-top{padding:1.1rem 1.1rem .75rem;display:flex;align-items:flex-start;gap:.85rem}
.cc-av{width:46px;height:46px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),#7c3aed)}
.cc-name{font-weight:700;font-size:.92rem;letter-spacing:-.01em;line-height:1.2}
.cc-email{font-size:.72rem;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:185px}
.cc-chips{display:flex;gap:.35rem;margin-top:.5rem;flex-wrap:wrap}
.chip{font-size:.6rem;padding:.12rem .42rem;border-radius:5px;font-weight:600;white-space:nowrap}
.chip-force{background:rgba(255,255,255,.06);color:var(--text-2);border:1px solid var(--border)}
.chip-lvl  {background:rgba(var(--accent-rgb),.1);color:var(--accent);border:1px solid rgba(var(--accent-rgb),.2)}
.chip-prem {background:rgba(245,158,11,.1);color:var(--gold);border:1px solid rgba(245,158,11,.2)}
.chip-unread{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.cc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem;padding:.6rem 1.1rem;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.cc-m{text-align:center}
.cc-mv{font-family:var(--mono);font-size:.92rem;font-weight:600}
.cc-ml{font-size:.6rem;color:var(--text-3);margin-top:1px;text-transform:uppercase;letter-spacing:.05em}
.cc-footer{padding:.65rem 1.1rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.cc-last{font-size:.72rem;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.cc-actions{display:flex;gap:.35rem;flex-shrink:0}
.cc-act{padding:.28rem .65rem;border-radius:20px;font-size:.7rem;font-weight:600;text-decoration:none;transition:all .15s;white-space:nowrap}
.cc-act.msg{background:rgba(var(--accent-rgb),.12);border:1px solid rgba(var(--accent-rgb),.25);color:var(--accent)}
.cc-act.msg:hover{background:rgba(var(--accent-rgb),.22)}
.cc-act.view{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-2)}
.cc-act.view:hover{background:rgba(255,255,255,.1);color:var(--text)}
.cc-unread-dot{position:absolute;top:.75rem;right:.75rem;width:8px;height:8px;border-radius:50%;background:var(--red);box-shadow:0 0 6px var(--red)}

/* ── TABLE VIEW ── */
.data-table{width:100%;border-collapse:collapse}
.data-table th{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);padding:.6rem .9rem;text-align:left;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);white-space:nowrap}
.data-table td{padding:.75rem .9rem;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.03);vertical-align:middle}
.data-table tbody tr:hover{background:rgba(255,255,255,.02)}
.data-table tbody tr:last-child td{border-bottom:none}
.td-av{display:flex;align-items:center;gap:.75rem}
.tbl-av{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),#7c3aed)}
.tbl-wrap{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden}

/* ── EMPTY ── */
.empty{text-align:center;padding:4rem 2rem;color:var(--text-3)}
.empty-ico{font-size:2.5rem;margin-bottom:.75rem;opacity:.25}
.empty-title{font-size:.9rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem}
.empty-text{font-size:.79rem}

/* ── VIEW TOGGLE ── */
.view-toggle{display:flex;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:20px;padding:2px;gap:2px}
.vt-btn{padding:.3rem .7rem;border-radius:18px;border:none;background:transparent;color:var(--text-3);font-family:var(--font);font-size:.76rem;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.35rem}
.vt-btn.active{background:rgba(var(--accent-rgb),.15);color:var(--accent)}

/* ── BADGE ── */
.badge{display:inline-block;padding:.18rem .5rem;border-radius:5px;font-size:.67rem;font-weight:600;white-space:nowrap}
.b-green{background:rgba(34,197,94,.1);color:#86efac;border:1px solid rgba(34,197,94,.2)}
.b-red{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.b-gold{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.b-blue{background:rgba(59,130,246,.1);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
.b-muted{background:rgba(255,255,255,.05);color:var(--text-3);border:1px solid var(--border)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}50%{box-shadow:0 0 0 5px rgba(34,197,94,0)}}
@media(max-width:1100px){.stat-strip{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.sb{display:none}.client-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.client-grid{grid-template-columns:1fr}.stat-strip{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="layout">

<!-- ── SIDEBAR ── -->
<aside class="sb">
    <div class="sb-logo">
        <div class="sb-ico">GM</div>
        <div><div class="sb-brand">Gurkha Marga</div><div class="sb-sub">Staff Portal</div></div>
    </div>
    <div class="sb-user">
        <div class="sb-av"><?= av($staffName) ?></div>
        <div><div class="sb-uname"><?= htmlspecialchars($staffName) ?></div><div class="sb-urole"><?= htmlspecialchars($staffRole) ?></div></div>
        <div class="sb-pulse"></div>
    </div>
    <nav class="sb-nav">
        <div class="nav-sec">Main</div>
        <a href="staff_dashboard.php" class="nav-a"><span class="nav-ico">📊</span>Overview</a>
        <a href="premium_client.php" class="nav-a active"><span class="nav-ico">⭐</span>My Clients</a>
        <div class="nav-sec">Tools</div>
        <a href="staff_dashboard.php?page=notes" class="nav-a"><span class="nav-ico">📝</span>Notes</a>
        <a href="staff_dashboard.php?page=consult" class="nav-a"><span class="nav-ico">🗂</span>Consultations</a>
        <?php if (in_array($staffRole,['dietitian','admin','superadmin'])): ?>
        <a href="staff_dashboard.php?page=meals" class="nav-a"><span class="nav-ico">🥗</span>Meal Plans</a>
        <?php endif; ?>
        <div class="nav-sec">Communicate</div>
        <a href="premium_message.php" class="nav-a">
            <span class="nav-ico">✉️</span>Messages
            <?php if ($totalUnread > 0): ?><span class="nav-badge"><?= $totalUnread ?></span><?php endif; ?>
        </a>
        <div class="nav-sec">Account</div>
        <a href="staff_dashboard.php?page=settings" class="nav-a"><span class="nav-ico">⚙️</span>Settings</a>
        <a href="staff_dashboard.php?page=logout" class="nav-a danger" onclick="return confirm('Log out?')"><span class="nav-ico">🚪</span>Logout</a>
    </nav>
</aside>

<!-- ── MAIN ── -->
<div class="main">
    <header class="topbar">
        <div class="tb-left">
            <nav class="breadcrumb">
                <a href="staff_dashboard.php">Staff Portal</a>
                <span>/</span>
                <span>My Clients</span>
            </nav>
        </div>
        <div class="tb-right">
            <span class="top-badge gold">👥 <?= count($clients) ?> Assigned</span>
            <?php if ($totalUnread > 0): ?>
            <a href="premium_message.php" class="top-badge red">✉️ <?= $totalUnread ?> unread</a>
            <?php endif; ?>
            <span style="font-size:.72rem;color:var(--text-3);font-family:var(--mono)"><?= date('D, d M Y') ?></span>
        </div>
    </header>

    <div class="page">

        <div class="pg-hdr">
            <div>
                <h1 class="pg-title">👥 My Clients</h1>
                <p class="pg-sub"><?= count($clients) ?> active <?= count($clients)===1?'member':'members' ?> assigned to you</p>
            </div>
        </div>

        <!-- Stats -->
        <?php
        $totalBmi    = 0; $bmiCount = 0;
        $forceCount  = [];
        foreach ($clients as $c) {
            if ($c['bmi_val']) { $totalBmi += $c['bmi_val']; $bmiCount++; }
            $f = $c['target_force'] ?? 'unknown'; $forceCount[$f] = ($forceCount[$f]??0)+1;
        }
        $avgBmi = $bmiCount ? round($totalBmi/$bmiCount,1) : 0;
        arsort($forceCount); $topForce = array_key_first($forceCount) ?? '—';
        ?>
        <div class="stat-strip">
            <div class="stat-s">
                <div class="stat-s-val"><?= count($clients) ?></div>
                <div class="stat-s-lbl">Total Clients</div>
            </div>
            <div class="stat-s g">
                <div class="stat-s-val"><?= $avgBmi ?: '—' ?></div>
                <div class="stat-s-lbl">Avg BMI</div>
            </div>
            <div class="stat-s gold">
                <div class="stat-s-val"><?= $totalUnread ?></div>
                <div class="stat-s-lbl">Unread Msgs</div>
            </div>
            <div class="stat-s r">
                <div class="stat-s-val"><?= $forceCount[$topForce] ?? 0 ?></div>
                <div class="stat-s-lbl"><?= ($forceMap[$topForce][0] ?? ucfirst($topForce)) ?></div>
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
                <?php foreach ($forces as $f): ?><option value="<?= $f ?>" <?= $force===$f?'selected':'' ?>><?= $forceMap[$f][1] ?> <?= $forceMap[$f][0] ?></option><?php endforeach; ?>
            </select>
            <select class="filter-select" name="sort" onchange="this.form.submit()">
                <option value="recent" <?= $sort==='recent'?'selected':'' ?>>Most Recent</option>
                <option value="name"   <?= $sort==='name'?'selected':'' ?>>Name A–Z</option>
                <option value="bmi"    <?= $sort==='bmi'?'selected':'' ?>>BMI</option>
            </select>
            <?php if ($search||$force): ?><a href="premium_client.php" class="filter-btn">✕ Clear</a><?php endif; ?>
            <div class="view-toggle" style="margin-left:auto">
                <button type="button" class="vt-btn active" id="gridBtn" onclick="setView('grid')">⊞ Grid</button>
                <button type="button" class="vt-btn" id="listBtn" onclick="setView('list')">☰ List</button>
            </div>
        </form>

        <!-- GRID VIEW -->
        <div id="gridView">
            <?php if (empty($clients)): ?>
            <div class="empty">
                <div class="empty-ico">👥</div>
                <div class="empty-title">No clients assigned yet<?= $search?' match your search':'' ?></div>
                <div class="empty-text">Clients will appear here once users select you from the Expert Team page.</div>
            </div>
            <?php else: ?>
            <div class="client-grid">
                <?php foreach ($clients as $c):
                    $bmi = $c['bmi_val'];
                    [$bmiCat, $bmiColor] = bmiCategory((float)$bmi);
                    $meta    = $clientMeta[$c['id']] ?? [];
                    $unread  = $unreadPerUser[$c['id']] ?? 0;
                    $lm      = $meta['last_msg'] ?? null;
                    $lastTxt = '';
                    if ($lm) {
                        $pfx = $lm['sender_type']==='user' ? 'Client: ' : 'You: ';
                        $lastTxt = $pfx . match($lm['message_type']){
                            'image'=>'📷 Photo','file'=>'📎 File','voice'=>'🎤 Voice',
                            default=>mb_substr($lm['body']??'',0,40).(mb_strlen($lm['body']??'')>40?'…':'')
                        };
                    }
                    $fi = $forceMap[$c['target_force'] ?? ''] ?? [ucfirst($c['target_force']??'—'),''];
                ?>
                <div class="cc" style="animation-delay:<?= array_search($c,$clients)*.04 ?>s">
                    <?php if ($unread): ?><div class="cc-unread-dot"></div><?php endif; ?>
                    <div class="cc-top">
                        <div class="cc-av"><?= av($c['full_name']) ?></div>
                        <div style="flex:1;min-width:0">
                            <div class="cc-name"><?= htmlspecialchars($c['full_name']) ?></div>
                            <div class="cc-email"><?= htmlspecialchars($c['email']) ?></div>
                            <div class="cc-chips">
                                <?php if ($c['is_premium'] ?? 0): ?><span class="chip chip-prem">⭐ Premium</span><?php endif; ?>
                                <span class="chip chip-force"><?= $fi[1] ?> <?= $fi[0] ?></span>
                                <span class="chip chip-lvl"><?= ucfirst($c['experience_level']??'—') ?></span>
                                <?php if ($unread): ?><span class="chip chip-unread"><?= $unread ?> new msg<?= $unread>1?'s':'' ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="cc-metrics">
                        <div class="cc-m"><div class="cc-mv"><?= $bmi ?: '—' ?></div><div class="cc-ml">BMI</div></div>
                        <div class="cc-m"><div class="cc-mv" style="color:<?= $bmiColor ?>;font-size:.76rem"><?= $bmiCat ?></div><div class="cc-ml">Status</div></div>
                        <div class="cc-m"><div class="cc-mv"><?= $c['weight']?$c['weight'].'kg':'—' ?></div><div class="cc-ml">Weight</div></div>
                    </div>
                    <div class="cc-footer">
                        <div class="cc-last"><?= $lastTxt ? htmlspecialchars($lastTxt) : '<span style="color:var(--text-3);font-size:.7rem">No messages yet</span>' ?></div>
                        <div class="cc-actions">
                            <a href="premium_message.php?uid=<?= $c['id'] ?>" class="cc-act msg">💬 Chat</a>
                            <a href="staff_dashboard.php?page=profile&uid=<?= $c['id'] ?>" class="cc-act view">Profile</a>
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
            <div class="empty">
                <div class="empty-ico">👥</div>
                <div class="empty-title">No clients assigned yet</div>
                <div class="empty-text">Clients will appear here once users select you from the Expert Team page.</div>
            </div>
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
                            [$bmiCat,$bmiColor] = bmiCategory((float)$bmi);
                            $meta   = $clientMeta[$c['id']] ?? [];
                            $unread = $unreadPerUser[$c['id']] ?? 0;
                            $fi     = $forceMap[$c['target_force']??''] ?? [ucfirst($c['target_force']??'—'),''];
                            $lm     = $meta['last_msg'] ?? null;
                        ?>
                        <tr>
                            <td>
                                <div class="td-av">
                                    <div class="tbl-av"><?= av($c['full_name']) ?></div>
                                    <div>
                                        <div style="font-weight:600"><?= htmlspecialchars($c['full_name']) ?></div>
                                        <div style="font-size:.7rem;color:var(--text-3)"><?= htmlspecialchars($c['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-size:.78rem"><?= $fi[1] ?> <?= $fi[0] ?></td>
                            <td><span class="badge b-blue"><?= ucfirst($c['experience_level']??'—') ?></span></td>
                            <td><span style="font-family:var(--mono);color:<?= $bmiColor ?>"><?= $bmi ?: '—' ?></span></td>
                            <td style="font-family:var(--mono)"><?= $c['weight']?$c['weight'].'kg':'—' ?></td>
                            <td style="font-family:var(--mono)"><?= $c['height']?$c['height'].'cm':'—' ?></td>
                            <td style="font-family:var(--mono)"><?= (int)$c['age'] ?></td>
                            <td style="font-family:var(--mono)"><?= $meta['notes'] ?? 0 ?></td>
                            <td style="font-family:var(--mono)"><?= $meta['consults'] ?? 0 ?></td>
                            <td style="font-size:.73rem;color:var(--text-3)"><?= $lm ? timeAgo($lm['created_at']) : '—' ?></td>
                            <td>
                                <div style="display:flex;gap:.35rem">
                                    <?php if ($unread): ?><span class="badge b-red"><?= $unread ?> new</span><?php endif; ?>
                                    <a href="premium_message.php?uid=<?= $c['id'] ?>" class="cc-act msg" style="font-size:.68rem">💬</a>
                                    <a href="staff_dashboard.php?page=profile&uid=<?= $c['id'] ?>" class="cc-act view" style="font-size:.68rem">View</a>
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

    </div><!-- /.page -->
</div><!-- /.main -->
</div><!-- /.layout -->

<script>
function setView(v){
    const grid=document.getElementById('gridView');const list=document.getElementById('listView');
    const gb=document.getElementById('gridBtn');const lb=document.getElementById('listBtn');
    if(v==='grid'){grid.style.display='';list.style.display='none';gb.classList.add('active');lb.classList.remove('active')}
    else{grid.style.display='none';list.style.display='';lb.classList.add('active');gb.classList.remove('active')}
    localStorage.setItem('pref_view',v);
}
// Restore preference
const pv=localStorage.getItem('pref_view');if(pv)setView(pv);
// Submit form on search enter
document.querySelector('.search-inp')?.addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('filterForm').submit()});
</script>
</body>
</html>