<?php
/**
 * balance.php — Gurkha Marga Admin
 * Standalone dynamic financial dashboard.
 * Queries: subscriptions (id, user_id, plan_id, amount, status,
 *          esewa_ref_id, starts_at, expires_at, created_at)
 *          + users (id, full_name, email)
 *          + subscription_plans (id, name, slug, price, duration_days)
 */
session_start();

/* ── auth guard ─────────────────────────────────────────────────────────── */
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../auth/login.php'); exit();
}

define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

/* ── helpers ─────────────────────────────────────────────────────────────── */
if (!function_exists('setFlash')) {
    function setFlash(string $t, string $m): void { $_SESSION['flash'] = ['type'=>$t,'message'=>$m]; }
}
if (!function_exists('getFlash')) {
    function getFlash(): ?array {
        if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }
        return null;
    }
}

/* ── date range filter ───────────────────────────────────────────────────── */
$range    = $_GET['range'] ?? '30';
$pg       = max(1,(int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($pg - 1) * $perPage;

$rangeMap = [
    '7'   => ['INTERVAL 7 DAY',   'Last 7 Days'],
    '30'  => ['INTERVAL 30 DAY',  'Last 30 Days'],
    '90'  => ['INTERVAL 90 DAY',  'Last 3 Months'],
    '180' => ['INTERVAL 180 DAY', 'Last 6 Months'],
    '365' => ['INTERVAL 365 DAY', 'Last Year'],
    'all' => [null,               'All Time'],
];
[$intervalSql, $rangeLabel] = $rangeMap[$range] ?? $rangeMap['30'];
$whereDate   = $intervalSql ? "AND s.created_at >= DATE_SUB(NOW(), $intervalSql)" : '';
$whereGlobal = $intervalSql ? "AND created_at >= DATE_SUB(NOW(), $intervalSql)"   : '';

/* ── live DB queries ─────────────────────────────────────────────────────── */
try {
    /* KPI totals — only confirmed (active/expired) payments count as revenue */
    $totalCollected = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions
         WHERE status IN('active','expired') $whereGlobal"
    )['s'] ?? 0);

    $activeRevenue  = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions
         WHERE status='active' $whereGlobal"
    )['s'] ?? 0);

    $expiredRevenue = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions
         WHERE status='expired' $whereGlobal"
    )['s'] ?? 0);

    $pendingRevenue = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions
         WHERE status='pending' $whereGlobal"
    )['s'] ?? 0);

    $txnCount   = (int)(fetchOne(
        "SELECT COUNT(*) AS c FROM subscriptions
         WHERE status IN('active','expired') $whereGlobal"
    )['c'] ?? 0);

    $activeSubs = (int)(fetchOne(
        "SELECT COUNT(*) AS c FROM subscriptions
         WHERE status='active' AND expires_at > NOW()"
    )['c'] ?? 0);

    $pendingCount = (int)(fetchOne(
        "SELECT COUNT(*) AS c FROM subscriptions
         WHERE status='pending' $whereGlobal"
    )['c'] ?? 0);

    $avgTxn = $txnCount > 0 ? $totalCollected / $txnCount : 0;

    /* Revenue by plan */
    $planRevenue = fetchAll(
        "SELECT p.name, p.slug, COUNT(s.id) AS cnt,
                COALESCE(SUM(s.amount),0) AS total
         FROM subscriptions s
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.status IN('active','expired') $whereDate
         GROUP BY p.id ORDER BY total DESC"
    ) ?: [];

    /* Daily revenue — always last 30 days for the bar chart */
    $dailyRevenue = fetchAll(
        "SELECT DATE_FORMAT(created_at,'%b %d') AS lbl,
                DATE(created_at) AS d,
                COALESCE(SUM(amount),0) AS total,
                COUNT(*) AS cnt
         FROM subscriptions
         WHERE status IN('active','expired')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at) ORDER BY d ASC"
    ) ?: [];

    /* Monthly revenue — last 12 months */
    $monthlyRevenue = fetchAll(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS m,
                DATE_FORMAT(MIN(created_at),'%b %Y') AS label,
                COALESCE(SUM(amount),0) AS total,
                COUNT(*) AS cnt
         FROM subscriptions
         WHERE status IN('active','expired')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY m ASC"
    ) ?: [];

    /* Status breakdown */
    $statusBreakdown = fetchAll(
        "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
         FROM subscriptions
         WHERE 1=1 $whereGlobal
         GROUP BY status"
    ) ?: [];

    /* Transaction ledger */
    $totalTxns = (int)(fetchOne(
        "SELECT COUNT(*) AS c FROM subscriptions s WHERE 1=1 $whereDate"
    )['c'] ?? 0);

    $transactions = fetchAll(
        "SELECT s.*, u.full_name, u.email, p.name AS plan_name, p.slug AS plan_slug
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE 1=1 $whereDate
         ORDER BY s.created_at DESC
         LIMIT $perPage OFFSET $offset"
    ) ?: [];

    /* Top payers */
    $topPayers = fetchAll(
        "SELECT u.full_name, u.email,
                COUNT(s.id) AS cnt,
                COALESCE(SUM(s.amount),0) AS total
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         WHERE s.status IN('active','expired')
         GROUP BY s.user_id ORDER BY total DESC LIMIT 6"
    ) ?: [];

    /* Expiring within 7 days */
    $expiringSoon = fetchAll(
        "SELECT s.*, u.full_name, u.email, p.name AS plan_name
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.status = 'active'
           AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
         ORDER BY s.expires_at ASC LIMIT 8"
    ) ?: [];

    /* Recent payments — last 5 for the live feed widget */
    $recentPayments = fetchAll(
        "SELECT s.amount, s.status, s.created_at,
                u.full_name, p.name AS plan_name, p.slug
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.status IN('active','expired','pending')
         ORDER BY s.created_at DESC LIMIT 5"
    ) ?: [];

} catch (Exception $e) {
    $totalCollected = $activeRevenue = $expiredRevenue = $pendingRevenue
        = $txnCount = $activeSubs = $pendingCount = $avgTxn = 0;
    $planRevenue = $dailyRevenue = $monthlyRevenue = $statusBreakdown
        = $transactions = $topPayers = $expiringSoon = $recentPayments = [];
    $totalTxns = 0;
}

$totalPages = max(1, (int)ceil($totalTxns / $perPage));

/* ── build chart arrays ─────────────────────────────────────────────────── */
$drMap = [];
foreach ($dailyRevenue as $r) $drMap[$r['lbl']] = $r;
$dLabels = []; $dRevenue = []; $dCount = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('M d', strtotime("-{$i} days"));
    $dLabels[]  = $d;
    $dRevenue[] = isset($drMap[$d]) ? (float)$drMap[$d]['total'] : 0;
    $dCount[]   = isset($drMap[$d]) ? (int)$drMap[$d]['cnt']    : 0;
}
$mLabels  = array_column($monthlyRevenue, 'label');
$mRevenue = array_map('floatval', array_column($monthlyRevenue, 'total'));

$statusMap = [];
foreach ($statusBreakdown as $r) {
    $statusMap[$r['status']] = ['cnt' => (int)$r['cnt'], 'total' => (float)$r['total']];
}
$planTotal = max(array_sum(array_column($planRevenue, 'total')), 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Balance & Revenue — Gurkha Marga Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:      #3b82f6;
    --gold:        #fbbf24;
    --gold-dim:    rgba(251,191,36,0.10);
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
    --sidebar-bg:  rgba(15,23,42,0.97);
    --card:        rgba(30,41,59,0.80);
    --hover:       rgba(59,130,246,0.08);
    --border:      rgba(255,255,255,0.07);
    --border-hi:   rgba(255,255,255,0.12);
    --red-soft:    rgba(239,68,68,0.08);
    --mono:        'IBM Plex Mono', monospace;
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
}

/* ═══════════════════ SIDEBAR ═══════════════════ */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: var(--sidebar-bg);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    overflow-y: auto; z-index: 1000;
    transition: transform .3s ease;
}
.sb-head { padding: 1.5rem 1.25rem 1.1rem; border-bottom: 1px solid var(--border); }
.sb-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 1rem; }
.sb-logo img { width: 34px; height: 34px; object-fit: contain; border-radius: 8px; }
.brand {
    font-size: 1.1rem; font-weight: 800;
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
}
.brand-sub { font-size: .6rem; color: var(--text-3); letter-spacing: .1em; text-transform: uppercase; }
.admin-badge {
    display: flex; align-items: center; gap: 7px;
    padding: .55rem .85rem; border-radius: 10px;
    background: var(--gold-dim); border: 1px solid var(--gold-hi);
    font-size: .72rem; font-weight: 600; color: var(--gold);
}
.admin-badge svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; }

.nav-wrap { padding: .85rem 0; flex: 1; }
.nav-section {
    font-size: .6rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase;
    color: rgba(100,116,139,0.6); padding: .7rem 1.25rem .25rem;
}
.nav-link {
    display: flex; align-items: center; gap: 10px;
    padding: .58rem 1.25rem; color: var(--text-2);
    text-decoration: none; font-size: .83rem; font-weight: 500;
    border-left: 2.5px solid transparent; transition: all .15s;
}
.nav-link svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 1.8; flex-shrink: 0; opacity: .7; }
.nav-link:hover, .nav-link.active {
    background: var(--hover); color: var(--text);
    border-left-color: var(--accent);
}
.nav-link.active svg, .nav-link:hover svg { opacity: 1; }
.nav-link.danger { color: #f87171; }
.nav-link.danger:hover { background: var(--red-soft); border-left-color: var(--red); }
.sb-foot {
    padding: .85rem 1.25rem; font-size: .62rem;
    color: var(--text-3); border-top: 1px solid var(--border);
}

/* ═══════════════════ LAYOUT ═══════════════════ */
.main { margin-left: 260px; padding: 1.75rem; min-height: 100vh; }

/* ═══════════════════ TOPBAR ═══════════════════ */
.topbar {
    background: var(--card); backdrop-filter: blur(20px);
    border-radius: 14px; padding: 1.25rem 1.75rem; margin-bottom: 1.5rem;
    display: flex; justify-content: space-between; align-items: flex-start;
    border: 1px solid var(--border); flex-wrap: wrap; gap: .75rem;
}
.topbar-title { font-size: 1.3rem; font-weight: 700; }
.topbar-sub   { font-size: .72rem; color: var(--text-3); margin-top: .2rem; }
.range-pills  { display: flex; gap: .35rem; flex-wrap: wrap; }
.pill {
    padding: .35rem .85rem; border-radius: 99px; font-size: .72rem;
    font-weight: 600; text-decoration: none; border: 1px solid var(--border);
    color: var(--text-2); background: rgba(255,255,255,.04);
    transition: all .15s; white-space: nowrap;
}
.pill:hover { border-color: var(--border-hi); color: var(--text); }
.pill.active { background: rgba(251,191,36,.15); border-color: var(--gold-hi); color: var(--gold); }

/* ═══════════════════ FLASH ═══════════════════ */
.flash {
    padding: .8rem 1.1rem; border-radius: 9px; font-size: .82rem;
    margin-bottom: 1.25rem; display: flex; align-items: center; gap: .6rem;
    animation: fadeUp .25s ease;
}
.flash.success { background: var(--green-dim); border: 1px solid var(--green-hi); color: #6ee7b7; }
.flash.error   { background: var(--red-dim);   border: 1px solid var(--red-hi);   color: #fca5a5; }

/* ═══════════════════ HERO CARD ═══════════════════ */
.hero {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; padding: 1.75rem 2rem 1.5rem;
    position: relative; overflow: hidden;
    margin-bottom: 1.25rem; backdrop-filter: blur(12px);
    animation: fadeUp .3s ease;
}
.hero::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--gold), rgba(251,191,36,0.1));
}
.hero::after {
    content: ''; position: absolute; top: -80px; right: -80px;
    width: 280px; height: 280px;
    background: radial-gradient(circle, rgba(251,191,36,.06) 0%, transparent 70%);
    pointer-events: none;
}
.hero-label { font-size: .62rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--gold); opacity: .75; margin-bottom: .45rem; }
.hero-amount { font-size: 3rem; font-weight: 800; font-family: var(--mono); color: var(--gold); letter-spacing: -.04em; line-height: 1; }
.hero-curr   { font-size: 1.3rem; font-weight: 600; opacity: .7; margin-right: .15rem; }
.hero-sub    { font-size: .73rem; color: var(--text-3); margin-top: .35rem; }
.hero-grid   {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: .85rem;
    margin-top: 1.35rem; padding-top: 1.35rem; border-top: 1px solid var(--border);
}
.hg-val { font-size: 1.05rem; font-weight: 700; font-family: var(--mono); }
.hg-lbl { font-size: .62rem; color: var(--text-3); margin-top: 3px; }

/* ═══════════════════ KPI ROW ═══════════════════ */
.kpi-row { display: grid; grid-template-columns: repeat(3,1fr); gap: .85rem; margin-bottom: 1.25rem; }
.kpi {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 14px; padding: 1.1rem 1.25rem;
    position: relative; overflow: hidden; backdrop-filter: blur(12px);
    transition: border-color .2s, transform .2s;
    animation: fadeUp .35s ease both;
}
.kpi:hover { border-color: var(--border-hi); transform: translateY(-2px); }
.kpi::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
.kpi.green::before { background: linear-gradient(90deg, var(--green), #34d399); }
.kpi.gold::before  { background: linear-gradient(90deg, var(--gold),  #f59e0b); }
.kpi.amber::before { background: linear-gradient(90deg, var(--amber), #fcd34d); }
.kpi-label { font-size: .62rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--text-3); margin-bottom: .5rem; }
.kpi-val   { font-size: 1.7rem; font-weight: 800; font-family: var(--mono); line-height: 1; }
.kpi-sub   { font-size: .68rem; color: var(--text-2); margin-top: .35rem; }
.chip {
    display: inline-block; font-size: .62rem; font-family: var(--mono);
    padding: .1rem .45rem; border-radius: 4px; margin-top: .4rem; font-weight: 600;
}
.chip.up   { background: var(--green-dim); color: #6ee7b7; border: 1px solid var(--green-hi); }
.chip.warn { background: var(--amber-dim); color: #fcd34d; border: 1px solid rgba(245,158,11,.25); }
.chip.muted{ background: rgba(255,255,255,.05); color: var(--text-2); border: 1px solid var(--border); }

/* ═══════════════════ SECTION LABEL ═══════════════════ */
.section-lbl {
    font-size: .6rem; font-weight: 700; letter-spacing: .14em;
    text-transform: uppercase; color: var(--text-3);
    display: flex; align-items: center; gap: .6rem; margin-bottom: .9rem;
}
.section-lbl::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ═══════════════════ CARDS ═══════════════════ */
.card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden; backdrop-filter: blur(12px);
    animation: fadeUp .35s ease both; transition: border-color .18s;
}
.card:hover { border-color: var(--border-hi); }
.card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .85rem 1.25rem; border-bottom: 1px solid var(--border);
}
.card-title { font-size: .7rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--text-3); }
.card-pad { padding: 1rem 1.25rem; }

/* ═══════════════════ GRID HELPERS ═══════════════════ */
.g2   { display: grid; grid-template-columns: 3fr 2fr;  gap: 1.1rem; margin-bottom: 1.25rem; }
.g2b  { display: grid; grid-template-columns: 2fr 1fr;  gap: 1.1rem; margin-bottom: 1.25rem; }
.col  { display: flex; flex-direction: column; gap: 1.1rem; }

/* ═══════════════════ CHART AREA ═══════════════════ */
.chart-wrap { padding: 1.1rem 1.25rem; height: 230px; position: relative; }

/* ═══════════════════ PLAN ROWS ═══════════════════ */
.plan-row {
    display: flex; align-items: center; gap: .75rem;
    padding: .65rem .85rem; border-radius: 9px;
    background: rgba(255,255,255,.03); border: 1px solid var(--border);
    margin-bottom: .4rem; transition: background .15s;
}
.plan-row:last-child { margin-bottom: 0; }
.plan-row:hover { background: rgba(255,255,255,.055); }
.plan-bar-track { width: 80px; height: 4px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; }
.plan-bar-fill  { height: 100%; border-radius: 2px; transition: width .9s cubic-bezier(.4,0,.2,1) .2s; width: 0; }

/* ═══════════════════ STATUS DONUT LEGEND ═══════════════════ */
.donut-legend { display: grid; gap: .35rem; margin-top: .75rem; }
.dl-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: .74rem; padding: .28rem .55rem; border-radius: 6px;
    background: rgba(255,255,255,.03);
}
.dl-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; display: inline-block; }

/* ═══════════════════ RECENT FEED ═══════════════════ */
.feed-item {
    display: flex; align-items: center; gap: .75rem;
    padding: .6rem .85rem; border-bottom: 1px solid rgba(255,255,255,.035);
}
.feed-item:last-child { border-bottom: none; }
.feed-av {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 800; color: #fff;
}
.feed-av.gold { background: linear-gradient(135deg, var(--gold), #f59e0b); color: #0a0800; }
.feed-av.red  { background: linear-gradient(135deg, var(--red), #fca5a5); }
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); display: inline-block; animation: pulse 1.8s ease infinite; }
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }

/* ═══════════════════ EXPIRY ROWS ═══════════════════ */
.expiry-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: .55rem .85rem; border-radius: 9px; margin-bottom: .4rem;
    background: rgba(239,68,68,.05); border: 1px solid rgba(239,68,68,.12);
}
.expiry-row:last-child { margin-bottom: 0; }

/* ═══════════════════ TOP PAYERS ═══════════════════ */
.payer-row {
    display: flex; align-items: center; gap: .75rem;
    padding: .6rem .85rem; border-radius: 9px;
    background: rgba(255,255,255,.03); border: 1px solid var(--border);
    margin-bottom: .4rem; transition: background .15s;
}
.payer-row:last-child { margin-bottom: 0; }
.payer-row:hover { background: rgba(255,255,255,.055); }
.payer-av {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--gold-dim); border: 1px solid var(--gold-hi);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 800; color: var(--gold); font-family: var(--mono);
    flex-shrink: 0;
}

/* ═══════════════════ LEDGER TABLE ═══════════════════ */
.tbl-wrap { overflow-x: auto; }
.ledger { width: 100%; border-collapse: collapse; white-space: nowrap; min-width: 700px; }
.ledger th {
    text-align: left; padding: .6rem 1rem;
    font-size: .6rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: var(--text-3); border-bottom: 1px solid var(--border);
    background: rgba(15,23,42,.4);
}
.ledger td {
    padding: .75rem 1rem; border-bottom: 1px solid rgba(255,255,255,.035);
    font-size: .79rem; color: var(--text-2); vertical-align: middle;
}
.ledger tbody tr:last-child td { border-bottom: none; }
.ledger tbody tr:hover td { background: rgba(59,130,246,.03); color: var(--text); }
.amt { font-family: var(--mono); font-weight: 700; color: var(--gold); }
.mono { font-family: var(--mono); font-size: .78rem; }
.dim  { color: var(--text-3); }
.ref  { font-size: .67rem; font-family: var(--mono); color: var(--text-3); max-width: 130px; overflow: hidden; text-overflow: ellipsis; display: block; }

/* ═══════════════════ BADGES ═══════════════════ */
.badge {
    display: inline-flex; align-items: center; padding: .18rem .55rem;
    border-radius: 5px; font-size: .65rem; font-weight: 600; white-space: nowrap;
    font-family: var(--mono); letter-spacing: .03em;
}
.badge-green  { background: var(--green-dim); color: #6ee7b7; border: 1px solid var(--green-hi); }
.badge-muted  { background: rgba(255,255,255,.06); color: var(--text-2); border: 1px solid var(--border); }
.badge-amber  { background: var(--amber-dim); color: #fcd34d; border: 1px solid rgba(245,158,11,.22); }
.badge-red    { background: var(--red-dim); color: #fca5a5; border: 1px solid var(--red-hi); }

/* ═══════════════════ PAGINATION ═══════════════════ */
.pagination {
    display: flex; gap: .35rem; justify-content: center;
    padding: 1rem; border-top: 1px solid var(--border);
}
.pg {
    width: 32px; height: 32px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; font-size: .78rem;
    text-decoration: none; color: var(--text-2);
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    transition: all .15s;
}
.pg:hover, .pg.active {
    background: rgba(59,130,246,.18); color: #93c5fd;
    border-color: rgba(59,130,246,.35);
}
.pg.disabled { opacity: .3; pointer-events: none; }

/* ═══════════════════ EMPTY STATE ═══════════════════ */
.empty { text-align: center; padding: 2.5rem 1rem; color: var(--text-3); font-size: .82rem; }
.empty-icon { font-size: 2rem; margin-bottom: .5rem; opacity: .4; }

/* ═══════════════════ MOBILE ═══════════════════ */
.mobile-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

/* ═══════════════════ ANIMATIONS ═══════════════════ */
@keyframes fadeUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

@media (max-width: 1200px) { .g2 { grid-template-columns: 1fr; } .hero-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 1000px) { .kpi-row { grid-template-columns: 1fr 1fr; } .g2b { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; padding: 1rem; }
    .kpi-row { grid-template-columns: 1fr; }
    .hero-amount { font-size: 2rem; }
    .hero-grid { grid-template-columns: repeat(2,1fr); }
    .mobile-btn { display: flex; align-items: center; justify-content: center; }
}
</style>
</head>
<body>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sb-head">
        <div class="sb-logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Gurkha Marga" onerror="this.style.display='none'">
            <div>
                <div class="brand">Gurkha Marga</div>
                <div class="brand-sub">Administration</div>
            </div>
        </div>
        <div class="admin-badge">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <?= htmlspecialchars($_SESSION['admin_role'] ?? 'Admin Panel') ?>
        </div>
    </div>
    <nav class="nav-wrap">
        <div class="nav-section">Overview</div>
        <a href="admin_dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="admin_users.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Users
        </a>
        <a href="admin_staff.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            Staff
        </a>
        <div class="nav-section">Content</div>
        <a href="admin_workouts.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="admin_progress.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
            Progress
        </a>
        <div class="nav-section">Finance</div>
        <a href="balance.php" class="nav-link active">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Balance
        </a>
        <div class="nav-section">Account</div>
        <a href="admin_settings.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="admin_logout.php" class="nav-link danger" onclick="return confirm('Log out?')">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
    <div class="sb-foot">Gurkha Marga Admin &mdash; <?= date('Y') ?></div>
</aside>

<!-- ═══════════════════ MAIN ═══════════════════ -->
<main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <div class="topbar-title">💰 Balance &amp; Revenue</div>
            <div class="topbar-sub">
                eSewa payments &nbsp;·&nbsp; <?= htmlspecialchars($rangeLabel) ?> &nbsp;·&nbsp; <?= date('d M Y, H:i') ?>
                &nbsp;·&nbsp; <span class="live-dot"></span>&nbsp;Live
            </div>
        </div>
        <div class="range-pills">
            <?php foreach ($rangeMap as $k => [$_, $lbl]): ?>
            <a href="?range=<?= $k ?>&page=1"
               class="pill <?= $range === $k ? 'active' : '' ?>">
                <?= htmlspecialchars($lbl) ?>
            </a>
            <?php endforeach; ?>
            <a href="balance.php?range=<?= $range ?>"
               class="pill" title="Refresh" style="padding:.35rem .65rem">⟳</a>
        </div>
    </div>

    <!-- FLASH -->
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="flash <?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <!-- ═══ HERO CARD ═══ -->
    <div class="hero">
        <div class="hero-label">Total Revenue Collected &mdash; <?= htmlspecialchars($rangeLabel) ?></div>
        <div class="hero-amount">
            <span class="hero-curr">रू</span><?= number_format($totalCollected, 2) ?>
        </div>
        <div class="hero-sub">Confirmed eSewa payments (active + expired subscriptions only)</div>
        <div class="hero-grid">
            <div>
                <div class="hg-val" style="color:#6ee7b7">रू <?= number_format($activeRevenue, 0) ?></div>
                <div class="hg-lbl">Active subscriptions</div>
            </div>
            <div>
                <div class="hg-val" style="color:var(--text-2)">रू <?= number_format($expiredRevenue, 0) ?></div>
                <div class="hg-lbl">Expired subscriptions</div>
            </div>
            <div>
                <div class="hg-val" style="color:#93c5fd"><?= number_format($txnCount) ?></div>
                <div class="hg-lbl">Confirmed transactions</div>
            </div>
            <div>
                <div class="hg-val" style="color:#fca5a5">रू <?= number_format($pendingRevenue, 0) ?></div>
                <div class="hg-lbl">Pending (unverified)</div>
            </div>
        </div>
    </div>

    <!-- ═══ KPI ROW ═══ -->
    <div class="kpi-row">
        <div class="kpi green" style="animation-delay:.04s">
            <div class="kpi-label">Active Subscriptions</div>
            <div class="kpi-val" style="color:#6ee7b7"><?= $activeSubs ?></div>
            <div class="kpi-sub">Currently live accounts</div>
            <span class="chip up">Via eSewa</span>
        </div>
        <div class="kpi gold" style="animation-delay:.08s">
            <div class="kpi-label">Avg. Revenue / Txn</div>
            <div class="kpi-val" style="color:var(--gold)">रू <?= number_format($avgTxn, 0) ?></div>
            <div class="kpi-sub">Per confirmed payment</div>
            <span class="chip muted"><?= $txnCount ?> transactions</span>
        </div>
        <div class="kpi amber" style="animation-delay:.12s">
            <div class="kpi-label">Pending Verification</div>
            <div class="kpi-val" style="color:#fcd34d"><?= $pendingCount ?></div>
            <div class="kpi-sub">Awaiting eSewa confirm</div>
            <span class="chip warn">Needs review</span>
        </div>
    </div>

    <!-- ═══ CHARTS ROW ═══ -->
    <div class="section-lbl">Revenue Trends</div>
    <div class="g2">
        <div class="card" style="animation-delay:.1s">
            <div class="card-head">
                <div class="card-title">Daily Revenue — Last 30 Days</div>
                <span style="font-size:.67rem;color:var(--text-3);font-family:var(--mono)">
                    रू <?= number_format(array_sum($dRevenue), 0) ?> total
                </span>
            </div>
            <div class="chart-wrap"><canvas id="chartDaily"></canvas></div>
        </div>
        <div class="card" style="animation-delay:.15s">
            <div class="card-head">
                <div class="card-title">Monthly Revenue — Last 12 Months</div>
            </div>
            <div class="chart-wrap"><canvas id="chartMonthly"></canvas></div>
        </div>
    </div>

    <!-- ═══ BREAKDOWN + RIGHT COL ═══ -->
    <div class="section-lbl">Subscription Breakdown</div>
    <div class="g2">
        <!-- Plan breakdown -->
        <div class="card" style="animation-delay:.18s">
            <div class="card-head">
                <div class="card-title">Revenue by Plan</div>
                <span class="chip muted">Confirmed only</span>
            </div>
            <div class="card-pad">
                <?php if (!empty($planRevenue)):
                    $planColors = ['monthly'=>'#3b82f6','quarterly'=>'#fbbf24','annual'=>'#10b981'];
                    $planIcons  = ['monthly'=>'📅','quarterly'=>'🏆','annual'=>'🎖️'];
                    foreach ($planRevenue as $p):
                        $pct   = (int)round((float)$p['total'] / $planTotal * 100);
                        $pCol  = $planColors[$p['slug'] ?? ''] ?? '#8b5cf6';
                ?>
                <div class="plan-row">
                    <span style="font-size:1.1rem"><?= $planIcons[$p['slug'] ?? ''] ?? '📦' ?></span>
                    <div style="flex:1">
                        <div style="font-size:.8rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:.65rem;color:var(--text-3);margin-top:1px"><?= $p['cnt'] ?> subscription<?= $p['cnt'] != 1 ? 's' : '' ?></div>
                    </div>
                    <div class="plan-bar-track">
                        <div class="plan-bar-fill" data-w="<?= $pct ?>" style="background:<?= $pCol ?>"></div>
                    </div>
                    <div style="text-align:right;min-width:85px">
                        <div style="font-size:.92rem;font-weight:800;font-family:var(--mono);color:var(--gold)">रू <?= number_format((float)$p['total'], 0) ?></div>
                        <div style="font-size:.62rem;color:var(--text-3)"><?= $pct ?>%</div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty"><div class="empty-icon">📊</div>No revenue data for this period</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right column -->
        <div class="col">
            <!-- Status donut -->
            <div class="card" style="animation-delay:.22s">
                <div class="card-head"><div class="card-title">Payment Status</div></div>
                <div style="padding:1rem 1.25rem">
                    <div style="display:flex;justify-content:center;margin-bottom:.75rem">
                        <canvas id="chartDonut" style="max-width:120px;max-height:120px"></canvas>
                    </div>
                    <div class="donut-legend">
                        <?php foreach ([
                            'active'    => ['#6ee7b7', 'Active'],
                            'expired'   => ['#94a3b8', 'Expired'],
                            'pending'   => ['#fcd34d', 'Pending'],
                            'cancelled' => ['#fca5a5', 'Cancelled'],
                        ] as $st => [$col, $lbl]):
                            $d = $statusMap[$st] ?? ['cnt'=>0,'total'=>0]; ?>
                        <div class="dl-row">
                            <span style="display:flex;align-items:center;gap:6px;color:var(--text-2)">
                                <span class="dl-dot" style="background:<?= $col ?>"></span>
                                <?= $lbl ?>
                            </span>
                            <span>
                                <span style="font-family:var(--mono);font-weight:700;color:<?= $col ?>"><?= $d['cnt'] ?></span>
                                <span style="color:var(--text-3);margin-left:.4rem;font-size:.65rem">रू <?= number_format($d['total'], 0) ?></span>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent payments live feed -->
            <div class="card" style="animation-delay:.26s">
                <div class="card-head">
                    <div class="card-title">
                        <span class="live-dot" style="margin-right:5px"></span>
                        Recent Payments
                    </div>
                    <span style="font-size:.67rem;color:var(--text-3)">Last 5</span>
                </div>
                <?php if (!empty($recentPayments)):
                    foreach ($recentPayments as $rp):
                        $isActive = $rp['status'] === 'active';
                        $isPend   = $rp['status'] === 'pending';
                        $avClass  = $isActive ? 'gold' : ($isPend ? 'red' : '');
                ?>
                <div class="feed-item">
                    <div class="feed-av <?= $avClass ?>"><?= strtoupper(substr($rp['full_name'],0,1)) ?></div>
                    <div style="flex:1;overflow:hidden">
                        <div style="font-size:.79rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($rp['full_name']) ?></div>
                        <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($rp['plan_name']) ?> · <?= date('d M Y, H:i', strtotime($rp['created_at'])) ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <div style="font-size:.85rem;font-weight:800;font-family:var(--mono);color:var(--gold)">रू <?= number_format((float)$rp['amount'],0) ?></div>
                        <span class="badge badge-<?= $isActive?'green':($isPend?'amber':'muted') ?>" style="font-size:.6rem"><?= ucfirst($rp['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty"><div class="empty-icon">💳</div>No recent payments</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══ EXPIRING SOON + TOP PAYERS ═══ -->
    <div class="g2b">
        <!-- Top payers -->
        <div class="card" style="animation-delay:.28s">
            <div class="card-head">
                <div class="card-title">🏅 Highest Lifetime Revenue</div>
                <span style="font-size:.67rem;color:var(--text-3)">All time · confirmed only</span>
            </div>
            <div class="card-pad">
                <?php if (!empty($topPayers)):
                    foreach ($topPayers as $i => $pay): ?>
                <div class="payer-row">
                    <span style="font-size:.72rem;font-weight:800;font-family:var(--mono);color:var(--text-3);width:18px;flex-shrink:0"><?= $i+1 ?>.</span>
                    <div class="payer-av"><?= strtoupper(substr($pay['full_name'],0,1)) ?></div>
                    <div style="flex:1;overflow:hidden">
                        <div style="font-size:.8rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($pay['full_name']) ?></div>
                        <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($pay['email']) ?></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <div style="font-size:.9rem;font-weight:800;font-family:var(--mono);color:var(--gold)">रू <?= number_format((float)$pay['total'],0) ?></div>
                        <div style="font-size:.62rem;color:var(--text-3)"><?= $pay['cnt'] ?> sub<?= $pay['cnt']>1?'s':'' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty"><div class="empty-icon">🏅</div>No payer data yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expiring soon -->
        <div class="card" style="animation-delay:.3s">
            <div class="card-head">
                <div class="card-title">⚠ Expiring Soon</div>
                <span style="font-size:.67rem;color:var(--text-3)">Within 7 days</span>
            </div>
            <div class="card-pad">
                <?php if (!empty($expiringSoon)):
                    foreach ($expiringSoon as $ex):
                        $dLeft = max(0, (int)ceil((strtotime($ex['expires_at'])-time())/86400)); ?>
                <div class="expiry-row">
                    <div>
                        <div style="font-size:.79rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($ex['full_name']) ?></div>
                        <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($ex['plan_name']) ?> · expires <?= date('d M', strtotime($ex['expires_at'])) ?></div>
                    </div>
                    <div style="font-size:.75rem;font-weight:800;color:#fca5a5;font-family:var(--mono)"><?= $dLeft ?>d left</div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty" style="padding:1.5rem">
                    <div style="font-size:1.75rem;margin-bottom:.35rem">✅</div>
                    <div style="font-size:.78rem;color:var(--text-2);font-weight:600">All subscriptions healthy</div>
                    <div style="font-size:.67rem;color:var(--text-3);margin-top:2px">No renewals due within 7 days</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══ TRANSACTION LEDGER ═══ -->
    <div class="section-lbl">Transaction Ledger</div>
    <div class="card" style="animation-delay:.32s; margin-bottom:2rem">
        <div class="card-head">
            <div class="card-title">All Payments — <?= htmlspecialchars($rangeLabel) ?></div>
            <span style="font-size:.67rem;color:var(--text-3);font-family:var(--mono)"><?= number_format($totalTxns) ?> records</span>
        </div>
        <div class="tbl-wrap">
            <table class="ledger">
                <thead><tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>eSewa Ref</th>
                    <th>Started</th>
                    <th>Expires</th>
                    <th>Date</th>
                </tr></thead>
                <tbody>
                <?php if (!empty($transactions)):
                    foreach ($transactions as $idx => $t):
                        $sc = $t['status'] ?? 'pending';
                        $badgeCls = match($sc) {
                            'active'    => 'badge-green',
                            'expired'   => 'badge-muted',
                            'pending'   => 'badge-amber',
                            'cancelled' => 'badge-red',
                            default     => 'badge-muted',
                        };
                ?>
                <tr>
                    <td class="dim mono" style="font-size:.7rem"><?= $offset + $idx + 1 ?></td>
                    <td>
                        <div style="font-weight:600;font-size:.8rem;color:var(--text)"><?= htmlspecialchars($t['full_name']) ?></div>
                        <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($t['email']) ?></div>
                    </td>
                    <td style="color:var(--text-2)"><?= htmlspecialchars($t['plan_name']) ?></td>
                    <td class="amt">रू <?= number_format((float)$t['amount'], 0) ?></td>
                    <td><span class="badge <?= $badgeCls ?>"><?= ucfirst($sc) ?></span></td>
                    <td><span class="ref"><?= htmlspecialchars($t['esewa_ref_id'] ?? '—') ?></span></td>
                    <td class="mono dim" style="font-size:.7rem"><?= $t['starts_at']  ? date('d M Y', strtotime($t['starts_at']))  : '—' ?></td>
                    <td class="mono dim" style="font-size:.7rem"><?= $t['expires_at'] ? date('d M Y', strtotime($t['expires_at'])) : '—' ?></td>
                    <td class="mono dim" style="font-size:.7rem;white-space:nowrap"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="9" class="empty">
                    <div class="empty-icon">📋</div>
                    No transactions found for this period.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?range=<?= $range ?>&page=<?= max(1,$pg-1) ?>" class="pg <?= $pg<=1?'disabled':'' ?>">‹</a>
            <?php for ($p = max(1,$pg-3); $p <= min($totalPages,$pg+3); $p++): ?>
            <a href="?range=<?= $range ?>&page=<?= $p ?>" class="pg <?= $p===$pg?'active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="?range=<?= $range ?>&page=<?= min($totalPages,$pg+1) ?>" class="pg <?= $pg>=$totalPages?'disabled':'' ?>">›</a>
        </div>
        <p style="text-align:center;font-size:.65rem;color:var(--text-3);padding:.5rem 0 .85rem">
            Page <?= $pg ?> of <?= $totalPages ?> &bull; <?= number_format($totalTxns) ?> total
        </p>
        <?php endif; ?>
    </div>

</main>

<button class="mobile-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Chart defaults ── */
Chart.defaults.color       = '#64748b';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Poppins', sans-serif";
Chart.defaults.font.size   = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,0.95)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(251,191,36,0.3)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 8;
Chart.defaults.plugins.tooltip.titleColor      = '#cbd5e1';
Chart.defaults.plugins.tooltip.bodyColor       = '#f8fafc';

/* ── Daily bar + line overlay ── */
new Chart(document.getElementById('chartDaily'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dLabels) ?>,
        datasets: [
            {
                label: 'Revenue (रू)',
                data:  <?= json_encode($dRevenue) ?>,
                backgroundColor: 'rgba(251,191,36,0.18)',
                borderColor: '#fbbf24', borderWidth: 1.5, borderRadius: 4,
                hoverBackgroundColor: 'rgba(251,191,36,0.35)',
                yAxisID: 'y',
            },
            {
                label: 'Transactions',
                data:  <?= json_encode($dCount) ?>,
                type: 'line',
                borderColor: '#3b82f6', backgroundColor: 'transparent',
                borderWidth: 2, pointRadius: 2.5,
                pointBackgroundColor: '#3b82f6',
                tension: 0.4, yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                labels: { color:'#64748b', boxWidth:10, font:{size:9}, padding:12 }
            },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.dataset.yAxisID==='y'
                        ? ' रू ' + ctx.parsed.y.toLocaleString()
                        : ' ' + ctx.parsed.y + ' txns'
                }
            }
        },
        scales: {
            x: { grid:{color:'rgba(255,255,255,.04)'}, ticks:{maxTicksLimit:10, maxRotation:40, font:{size:9}} },
            y: { grid:{color:'rgba(255,255,255,.04)'}, beginAtZero:true,
                 ticks:{callback:v=>'रू'+Number(v).toLocaleString()} },
            y1:{ position:'right', grid:{display:false}, beginAtZero:true, ticks:{precision:0} }
        }
    }
});

/* ── Monthly line chart ── */
new Chart(document.getElementById('chartMonthly'), {
    type: 'line',
    data: {
        labels: <?= json_encode($mLabels ?: ['No data']) ?>,
        datasets: [{
            label: 'Revenue',
            data:  <?= json_encode($mRevenue ?: [0]) ?>,
            borderColor: '#fbbf24',
            backgroundColor: 'rgba(251,191,36,0.07)',
            tension: 0.4, pointRadius: 4,
            pointBackgroundColor: '#fbbf24',
            fill: true, borderWidth: 2,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend:{display:false} },
        scales: {
            x: { grid:{color:'rgba(255,255,255,.04)'} },
            y: { grid:{color:'rgba(255,255,255,.04)'}, beginAtZero: true,
                 ticks:{callback:v=>'रू'+Number(v).toLocaleString()} }
        }
    }
});

/* ── Donut ── */
new Chart(document.getElementById('chartDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Expired','Pending','Cancelled'],
        datasets: [{
            data: [
                <?= (int)($statusMap['active']['cnt']    ?? 0) ?>,
                <?= (int)($statusMap['expired']['cnt']   ?? 0) ?>,
                <?= (int)($statusMap['pending']['cnt']   ?? 0) ?>,
                <?= (int)($statusMap['cancelled']['cnt'] ?? 0) ?>,
            ],
            backgroundColor: [
                'rgba(16,185,129,.45)','rgba(148,163,184,.35)',
                'rgba(251,191,36,.45)','rgba(239,68,68,.45)'
            ],
            borderColor: ['#10b981','#94a3b8','#fbbf24','#ef4444'],
            borderWidth: 1.5,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true, cutout:'65%',
        plugins:{ legend:{display:false} }
    }
});

/* ── Animate plan progress bars ── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.plan-bar-fill[data-w]').forEach(el => {
        el.style.width = el.dataset.w + '%';
    });
});

/* ── Sidebar mobile toggle ── */
document.addEventListener('click', function(e) {
    const sb = document.getElementById('sidebar');
    const btn = document.querySelector('.mobile-btn');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && btn && !btn.contains(e.target)) {
        sb.classList.remove('open');
    }
});

/* ── Auto-refresh every 60 seconds to pick up new payments ── */
setTimeout(() => location.reload(), 60000);
</script>

</body>
</html>

