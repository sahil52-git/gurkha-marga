<?php
/**
 * Balance — Gurkha Marga Admin
 * Financial overview: eSewa payments, revenue analytics, subscription ledger
 */
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// ── Auth guard (mirror admin pattern) ──────────────────────────────────────
// (admin_layout.php handles auth; this file assumes it's already guarded)

// ── Date filter ─────────────────────────────────────────────────────────────
$range    = $_GET['range'] ?? '30';  // 7 / 30 / 90 / 180 / 365 / all
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$rangeMap = [
    '7'   => ['INTERVAL 7 DAY',   'Last 7 Days'],
    '30'  => ['INTERVAL 30 DAY',  'Last 30 Days'],
    '90'  => ['INTERVAL 90 DAY',  'Last 3 Months'],
    '180' => ['INTERVAL 180 DAY', 'Last 6 Months'],
    '365' => ['INTERVAL 365 DAY', 'Last Year'],
    'all' => [null,               'All Time'],
];
[$intervalSql, $rangeLabel] = $rangeMap[$range] ?? $rangeMap['30'];
$whereDate = $intervalSql ? "AND s.created_at >= DATE_SUB(NOW(), $intervalSql)" : '';
$whereAll  = $intervalSql ? "WHERE created_at >= DATE_SUB(NOW(), $intervalSql)"  : '';

// ── Aggregate Stats ──────────────────────────────────────────────────────────
$stats = [];
try {
    // Total revenue across all statuses that represent a completed payment
    $stats['total_collected'] = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE status IN('active','expired') $whereAll"
    )['s'] ?? 0);

    $stats['active_revenue'] = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE status='active' $whereAll"
    )['s'] ?? 0);

    $stats['expired_revenue'] = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE status='expired' $whereAll"
    )['s'] ?? 0);

    $stats['pending_revenue'] = (float)(fetchOne(
        "SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE status='pending' $whereAll"
    )['s'] ?? 0);

    $stats['txn_count'] = (int)(fetchOne(
        "SELECT COUNT(*) as c FROM subscriptions WHERE status IN('active','expired') $whereAll"
    )['c'] ?? 0);

    $stats['active_subs'] = (int)(fetchOne(
        "SELECT COUNT(*) as c FROM subscriptions WHERE status='active' AND expires_at > NOW()"
    )['c'] ?? 0);

    $stats['pending_count'] = (int)(fetchOne(
        "SELECT COUNT(*) as c FROM subscriptions WHERE status='pending' $whereAll"
    )['c'] ?? 0);

    // Revenue by plan
    $planRevenue = fetchAll(
        "SELECT p.name, p.slug, COUNT(s.id) as cnt, COALESCE(SUM(s.amount),0) as total
         FROM subscriptions s JOIN subscription_plans p ON p.id=s.plan_id
         WHERE s.status IN('active','expired') $whereDate
         GROUP BY p.id ORDER BY total DESC"
    );

    // Daily revenue chart (last 30 days regardless of range filter for chart)
    $dailyRevenue = fetchAll(
        "SELECT DATE_FORMAT(created_at,'%b %d') as day_label, DATE(created_at) as day_date,
                COALESCE(SUM(amount),0) as total, COUNT(*) as cnt
         FROM subscriptions WHERE status IN('active','expired') AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
         GROUP BY DATE(created_at) ORDER BY day_date ASC"
    );

    // Monthly revenue (last 12 months)
    $monthlyRevenue = fetchAll(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') as m,
                DATE_FORMAT(MIN(created_at),'%b %Y') as label,
                COALESCE(SUM(amount),0) as total, COUNT(*) as cnt
         FROM subscriptions WHERE status IN('active','expired') AND created_at>=DATE_SUB(NOW(),INTERVAL 12 MONTH)
         GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY m ASC"
    );

    // Revenue by status breakdown
    $statusBreakdown = fetchAll(
        "SELECT status, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
         FROM subscriptions $whereAll GROUP BY status"
    );

    // Paginated transaction ledger
    $totalTxns = (int)(fetchOne(
        "SELECT COUNT(*) as c FROM subscriptions s $whereDate"
    )['c'] ?? 0);
    $transactions = fetchAll(
        "SELECT s.*, u.full_name, u.email, p.name as plan_name, p.slug as plan_slug
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN subscription_plans p ON p.id = s.plan_id
         $whereDate
         ORDER BY s.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );

    // Top payers
    $topPayers = fetchAll(
        "SELECT u.full_name, u.email, COUNT(s.id) as sub_count,
                COALESCE(SUM(s.amount),0) as total_paid
         FROM subscriptions s JOIN users u ON u.id=s.user_id
         WHERE s.status IN('active','expired')
         GROUP BY s.user_id ORDER BY total_paid DESC LIMIT 8"
    );

    // Expiring soon (next 7 days)
    $expiringSoon = fetchAll(
        "SELECT s.*, u.full_name, u.email, p.name as plan_name
         FROM subscriptions s JOIN users u ON u.id=s.user_id JOIN subscription_plans p ON p.id=s.plan_id
         WHERE s.status='active' AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
         ORDER BY s.expires_at ASC LIMIT 10"
    );

} catch(Exception $e) {
    $stats = array_fill_keys(['total_collected','active_revenue','expired_revenue','pending_revenue','txn_count','active_subs','pending_count'], 0);
    $planRevenue = $dailyRevenue = $monthlyRevenue = $statusBreakdown = $transactions = $topPayers = $expiringSoon = [];
    $totalTxns = 0;
}

$totalPages = $totalTxns > 0 ? ceil($totalTxns / $perPage) : 1;

// Build daily chart arrays (last 30 days, fill gaps)
$dLabels = []; $dRevenue = []; $dCount = [];
$drMap   = [];
foreach (($dailyRevenue ?? []) as $r) $drMap[$r['day_label']] = $r;
for ($i = 29; $i >= 0; $i--) {
    $d = date('M d', strtotime("-{$i} days"));
    $dLabels[]  = $d;
    $dRevenue[] = isset($drMap[$d]) ? (float)$drMap[$d]['total'] : 0;
    $dCount[]   = isset($drMap[$d]) ? (int)$drMap[$d]['cnt']     : 0;
}

// Monthly chart arrays
$mLabels  = array_column($monthlyRevenue ?? [], 'label');
$mRevenue = array_map('floatval', array_column($monthlyRevenue ?? [], 'total'));

// Plan stats
$planTotal = array_sum(array_column($planRevenue ?? [], 'total')) ?: 1;

// Status map
$statusMap = [];
foreach (($statusBreakdown ?? []) as $r) $statusMap[$r['status']] = ['cnt'=>(int)$r['cnt'], 'total'=>(float)$r['total']];
?>

<style>
/* ── Reset & Root ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg-base:  #0a1220;
    --bg-card:  rgba(13, 22, 38, 0.92);
    --bg-card2: rgba(18, 30, 52, 0.85);
    --accent:   #e8b84b;
    --accent2:  #c9973a;
    --blue:     #3b82f6;
    --green:    #10b981;
    --red:      #ef4444;
    --purple:   #8b5cf6;
    --teal:     #06b6d4;
    --text-1:   #f0f4ff;
    --text-2:   #94a3b8;
    --text-3:   #475569;
    --border:   rgba(255,255,255,0.055);
    --border-hi:rgba(232,184,75,0.25);
    --mono:     'IBM Plex Mono', 'Fira Code', monospace;
    --sans:     'Poppins', sans-serif;
}

body { font-family: var(--sans); color: var(--text-1); }

.bal-page { padding: 1.75rem; }

/* ── Header ── */
.pg-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;
}
.pg-head h1 {
    font-size: 1.5rem; font-weight: 800;
    background: linear-gradient(135deg, var(--accent), #f5d080);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.pg-head p { font-size: .72rem; color: var(--text-2); margin-top: .2rem; font-family: var(--mono); }
.pg-head-right { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: .5rem 1rem; border-radius: 8px; font-size: .78rem; font-weight: 600;
    cursor: pointer; border: none; font-family: var(--sans); text-decoration: none;
    transition: all .2s;
}
.btn-gold   { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: #0a0800; }
.btn-gold:hover   { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(232,184,75,.3); }
.btn-ghost  { background: rgba(255,255,255,.06); border: 1px solid var(--border); color: var(--text-2); }
.btn-ghost:hover  { background: rgba(255,255,255,.1); color: var(--text-1); }
.btn-active { background: rgba(232,184,75,.15); border: 1px solid rgba(232,184,75,.35); color: var(--accent); }
.btn svg { width: 13px; height: 13px; }

/* ── Section label ── */
.section-label {
    font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
    color: var(--text-3); display: flex; align-items: center; gap: .6rem; margin-bottom: .9rem;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ── Card ── */
.card {
    background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
    backdrop-filter: blur(14px); animation: fadeUp .4s ease both;
}
.card-inner { padding: 1.25rem 1.35rem; }
.card-head  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.card-title { font-size: .82rem; font-weight: 700; }
.card-sub   { font-size: .65rem; color: var(--text-3); }
@keyframes fadeUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

/* ── Hero balance card ── */
.hero-card {
    background: linear-gradient(135deg, rgba(232,184,75,.1) 0%, rgba(13,22,38,.97) 60%);
    border: 1px solid rgba(232,184,75,.3);
    border-radius: 16px; padding: 2rem 2rem 1.5rem;
    position: relative; overflow: hidden; margin-bottom: 1.25rem;
    animation: fadeUp .3s ease;
}
.hero-card::before {
    content: ''; position: absolute; top: -60px; right: -60px;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(232,184,75,.12) 0%, transparent 70%);
    pointer-events: none;
}
.hero-card::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
}
.hero-label    { font-size: .62rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; color: var(--accent2); margin-bottom: .5rem; }
.hero-amount   { font-size: 3.2rem; font-weight: 900; font-family: var(--mono); color: var(--accent); letter-spacing: -.04em; line-height: 1; }
.hero-currency { font-size: 1.4rem; font-weight: 600; color: var(--accent2); margin-right: .3rem; }
.hero-sub      { font-size: .75rem; color: var(--text-2); margin-top: .4rem; }
.hero-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: .75rem; margin-top: 1.5rem; padding-top: 1.5rem;
    border-top: 1px solid rgba(255,255,255,.06);
}
.hero-stat-val { font-size: 1.15rem; font-weight: 800; font-family: var(--mono); }
.hero-stat-lbl { font-size: .62rem; color: var(--text-3); margin-top: 3px; }

/* ── KPI cards ── */
.kpi-row { display: grid; grid-template-columns: repeat(3,1fr); gap: .85rem; margin-bottom: 1.25rem; }
.kpi-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 12px; padding: 1.1rem 1.25rem; position: relative; overflow: hidden;
    transition: border-color .2s, transform .2s;
}
.kpi-card:hover { border-color: var(--border-hi); transform: translateY(-2px); }
.kpi-card::before { content:''; position:absolute; top:0;left:0;right:0;height:2px; }
.kpi-card.green::before  { background: linear-gradient(90deg,var(--green),#34d399); }
.kpi-card.red::before    { background: linear-gradient(90deg,var(--red),#f87171); }
.kpi-card.purple::before { background: linear-gradient(90deg,var(--purple),#a78bfa); }
.kpi-card.blue::before   { background: linear-gradient(90deg,var(--blue),#60a5fa); }
.kpi-card.gold::before   { background: linear-gradient(90deg,var(--accent),#f5d080); }
.kpi-label { font-size: .62rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--text-3); margin-bottom: .4rem; }
.kpi-value { font-size: 1.55rem; font-weight: 800; font-family: var(--mono); letter-spacing: -.03em; }
.kpi-sub   { font-size: .68rem; color: var(--text-2); margin-top: .3rem; }
.chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: .15rem .5rem; border-radius: 20px; font-size: .63rem; font-weight: 600; margin-top: .4rem;
}
.chip-green  { background:rgba(16,185,129,.15);  color:#34d399; border:1px solid rgba(16,185,129,.2); }
.chip-warn   { background:rgba(232,184,75,.12);   color:var(--accent); border:1px solid rgba(232,184,75,.2); }
.chip-red    { background:rgba(239,68,68,.15);   color:#fca5a5; border:1px solid rgba(239,68,68,.2); }
.chip-purple { background:rgba(139,92,246,.15);  color:#a78bfa; border:1px solid rgba(139,92,246,.2); }

/* ── Chart grids ── */
.chart-row  { display: grid; grid-template-columns: 3fr 2fr; gap: 1rem; margin-bottom: 1.25rem; }
.chart-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
.chart-area { height: 200px; }
.chart-area.sm { height: 160px; }

/* ── Plan breakdown ── */
.plan-rows { display: grid; gap: .5rem; }
.plan-row  { display: flex; align-items: center; gap: .75rem; padding: .6rem .85rem; border-radius: 8px; background: rgba(255,255,255,.03); border: 1px solid var(--border); transition: background .15s; }
.plan-row:hover { background: rgba(255,255,255,.05); }
.plan-icon   { font-size: 1.1rem; }
.plan-name-w { flex: 1; }
.plan-n      { font-size: .8rem; font-weight: 600; }
.plan-cnt    { font-size: .65rem; color: var(--text-3); margin-top: 1px; }
.plan-amount { font-size: .95rem; font-weight: 800; font-family: var(--mono); color: var(--accent); }
.plan-pct    { font-size: .65rem; color: var(--text-3); text-align: right; }
.plan-bar-wrap { width: 80px; }
.plan-bar    { height: 4px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; }
.plan-fill   { height: 100%; border-radius: 2px; transition: width 1.2s ease .3s; width: 0%; }

/* ── Transaction ledger ── */
.ledger-wrap { overflow-x: auto; }
.ledger-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
.ledger-table th {
    text-align: left; padding: .5rem .8rem;
    font-size: .61rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: var(--text-3); border-bottom: 1px solid var(--border);
}
.ledger-table td {
    padding: .7rem .8rem; border-bottom: 1px solid rgba(255,255,255,.03);
    font-size: .79rem; color: var(--text-2);
}
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-table tr:hover td { background: rgba(255,255,255,.02); color: var(--text-1); }
.u-name  { font-weight: 600; color: var(--text-1); }
.u-email { font-size: .67rem; color: var(--text-3); }
.ref-code { font-size: .68rem; font-family: var(--mono); color: var(--text-3); max-width: 130px; overflow: hidden; text-overflow: ellipsis; display: block; }
.amt-cell { font-family: var(--mono); font-weight: 700; color: var(--accent); }
.status-chip { display: inline-flex; padding: .16rem .5rem; border-radius: 6px; font-size: .64rem; font-weight: 700; }
.s-active    { background:rgba(16,185,129,.12); color:#6ee7b7; border:1px solid rgba(16,185,129,.2); }
.s-expired   { background:rgba(100,116,139,.1); color:#94a3b8; border:1px solid rgba(100,116,139,.2); }
.s-pending   { background:rgba(232,184,75,.12); color:var(--accent); border:1px solid rgba(232,184,75,.2); }
.s-cancelled { background:rgba(239,68,68,.12); color:#fca5a5; border:1px solid rgba(239,68,68,.2); }

/* ── Pagination ── */
.pagination { display: flex; gap: .35rem; flex-wrap: wrap; justify-content: center; margin-top: 1.25rem; }
.pag-btn {
    padding: .35rem .7rem; border-radius: 6px; font-size: .75rem; font-weight: 600;
    text-decoration: none; transition: all .15s; font-family: var(--mono);
    background: rgba(255,255,255,.05); border: 1px solid var(--border); color: var(--text-2);
}
.pag-btn:hover   { background: rgba(255,255,255,.1); color: var(--text-1); }
.pag-btn.current { background: rgba(232,184,75,.15); border-color: rgba(232,184,75,.35); color: var(--accent); }
.pag-btn.disabled{ opacity: .35; cursor: not-allowed; pointer-events: none; }

/* ── Top payers ── */
.payers-list { display: grid; gap: .45rem; }
.payer-row {
    display: flex; align-items: center; gap: .75rem;
    padding: .6rem .85rem; border-radius: 8px;
    background: rgba(255,255,255,.03); border: 1px solid var(--border);
    transition: background .15s;
}
.payer-row:hover { background: rgba(255,255,255,.055); }
.payer-rank { font-size: .75rem; font-weight: 800; font-family: var(--mono); color: var(--text-3); width: 20px; flex-shrink: 0; }
.payer-info { flex: 1; }
.payer-name  { font-size: .8rem; font-weight: 600; color: var(--text-1); }
.payer-email { font-size: .65rem; color: var(--text-3); }
.payer-amt   { font-size: .9rem; font-weight: 800; font-family: var(--mono); color: var(--accent); }
.payer-cnt   { font-size: .65rem; color: var(--text-3); text-align: right; }

/* ── Expiry widget ── */
.expiry-list { display: grid; gap: .4rem; }
.expiry-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: .55rem .85rem; border-radius: 8px;
    background: rgba(239,68,68,.05); border: 1px solid rgba(239,68,68,.12);
}
.expiry-name { font-size: .78rem; font-weight: 600; color: var(--text-1); }
.expiry-date { font-size: .65rem; color: #fca5a5; font-family: var(--mono); }
.expiry-days { font-size: .7rem; font-weight: 700; color: #f87171; }

/* ── Filter bar ── */
.filter-bar { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center; }
.filter-label { font-size: .68rem; color: var(--text-3); margin-right: .2rem; }

/* ── Export notice ── */
.export-row { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }

/* ── Responsive ── */
@media (max-width:1100px) {
    .hero-grid { grid-template-columns: repeat(2,1fr); }
    .kpi-row   { grid-template-columns: 1fr 1fr; }
    .chart-row, .chart-row2 { grid-template-columns: 1fr; }
}
@media (max-width:700px) {
    .bal-page { padding: 1rem; }
    .hero-amount { font-size: 2.2rem; }
    .kpi-row  { grid-template-columns: 1fr; }
    .hero-grid{ grid-template-columns: 1fr 1fr; }
}
</style>

<div class="bal-page">

<!-- ─────────────────────────────────────────────────────── -->
<!-- PAGE HEADER -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="pg-head">
    <div>
        <h1>💰 Balance & Revenue</h1>
        <p>eSewa payments · <?= htmlspecialchars($rangeLabel) ?> · <?= date('d M Y, H:i') ?></p>
    </div>
    <div class="pg-head-right export-row">
        <!-- Range filter -->
        <div class="filter-bar" style="margin-bottom:0">
            <span class="filter-label">Range:</span>
            <?php foreach ($rangeMap as $k => [$_, $lbl]): ?>
            <a href="?range=<?= $k ?>&page=1" class="btn <?= $range === $k ? 'btn-active' : 'btn-ghost' ?>" style="padding:.35rem .75rem;font-size:.7rem">
                <?= htmlspecialchars($lbl) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="admin_dashboard.php" class="btn btn-ghost">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- HERO BALANCE CARD -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="hero-card">
    <div class="hero-label">Total Collected (<?= htmlspecialchars($rangeLabel) ?>)</div>
    <div class="hero-amount"><span class="hero-currency">रू</span><?= number_format($stats['total_collected'], 2) ?></div>
    <div class="hero-sub">Confirmed eSewa payments from active &amp; expired subscriptions</div>

    <div class="hero-grid">
        <div>
            <div class="hero-stat-val" style="color:#34d399">रू <?= number_format($stats['active_revenue'], 0) ?></div>
            <div class="hero-stat-lbl">Active subs</div>
        </div>
        <div>
            <div class="hero-stat-val" style="color:#94a3b8">रू <?= number_format($stats['expired_revenue'], 0) ?></div>
            <div class="hero-stat-lbl">Expired subs</div>
        </div>
        <div>
            <div class="hero-stat-val" style="color:var(--accent)"><?= number_format($stats['txn_count']) ?></div>
            <div class="hero-stat-lbl">Transactions</div>
        </div>
        <div>
            <div class="hero-stat-val" style="color:#f87171">रू <?= number_format($stats['pending_revenue'], 0) ?></div>
            <div class="hero-stat-lbl">Pending (unverified)</div>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- KPI ROW -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="kpi-row">
    <div class="kpi-card green">
        <div class="kpi-label">Active Subscriptions</div>
        <div class="kpi-value" style="color:#34d399"><?= $stats['active_subs'] ?></div>
        <div class="kpi-sub">Currently live accounts</div>
        <span class="chip chip-green">Auto-renew via eSewa</span>
    </div>
    <div class="kpi-card gold">
        <div class="kpi-label">Avg. Revenue / Txn</div>
        <div class="kpi-value" style="color:var(--accent)">
            रू <?= $stats['txn_count'] > 0 ? number_format($stats['total_collected'] / $stats['txn_count'], 0) : '0' ?>
        </div>
        <div class="kpi-sub">Per confirmed payment</div>
        <span class="chip chip-warn"><?= $stats['txn_count'] ?> total transactions</span>
    </div>
    <div class="kpi-card red">
        <div class="kpi-label">Pending Verification</div>
        <div class="kpi-value" style="color:#f87171"><?= $stats['pending_count'] ?></div>
        <div class="kpi-sub">Payments awaiting eSewa confirm</div>
        <span class="chip chip-red">Needs review</span>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- CHARTS ROW -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="section-label">Revenue Trends</div>
<div class="chart-row">
    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">Daily Revenue — Last 30 Days</span>
            </div>
            <div class="chart-area">
                <canvas id="chartDailyRev"></canvas>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">Monthly Revenue — Last 12 Months</span>
            </div>
            <div class="chart-area">
                <canvas id="chartMonthly"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="chart-row2">
    <!-- Plan breakdown -->
    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">Revenue by Plan</span>
                <span class="card-sub">Confirmed payments</span>
            </div>
            <?php if (!empty($planRevenue)): ?>
            <div class="plan-rows">
                <?php
                $planIcons = ['monthly'=>'📅','quarterly'=>'🏆','annual'=>'🎖️'];
                foreach ($planRevenue as $p):
                    $pPct = $planTotal > 0 ? round((float)$p['total'] / $planTotal * 100) : 0;
                    $pColor = ['monthly'=>'#3b82f6','quarterly'=>'#e8b84b','annual'=>'#10b981'][$p['slug'] ?? 'monthly'] ?? '#8b5cf6';
                ?>
                <div class="plan-row">
                    <div class="plan-icon"><?= $planIcons[$p['slug'] ?? 'monthly'] ?? '📦' ?></div>
                    <div class="plan-name-w">
                        <div class="plan-n"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="plan-cnt"><?= $p['cnt'] ?> subscriptions</div>
                    </div>
                    <div class="plan-bar-wrap">
                        <div class="plan-bar"><div class="plan-fill" data-w="<?= $pPct ?>" style="background:<?= $pColor ?>; width:0%"></div></div>
                        <div style="font-size:.6rem;color:var(--text-3);margin-top:2px"><?= $pPct ?>%</div>
                    </div>
                    <div style="text-align:right">
                        <div class="plan-amount">रू <?= number_format((float)$p['total'], 0) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--text-3);font-size:.78rem;text-align:center;padding:1.5rem 0">No revenue data yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment status donut + breakdown -->
    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">Payment Status</span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
                <canvas id="chartStatus" style="max-width:150px;max-height:150px"></canvas>
                <div style="width:100%;display:grid;gap:.4rem">
                    <?php
                    $statusCfg = [
                        'active'    => ['#10b981','Active'],
                        'expired'   => ['#94a3b8','Expired'],
                        'pending'   => ['#e8b84b','Pending'],
                        'cancelled' => ['#ef4444','Cancelled'],
                    ];
                    foreach ($statusCfg as $st => [$col, $lbl]):
                        $d = $statusMap[$st] ?? ['cnt'=>0,'total'=>0];
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:.75rem;padding:.3rem .5rem;border-radius:6px;background:rgba(255,255,255,.03)">
                        <span style="display:flex;align-items:center;gap:6px;">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>;display:inline-block;flex-shrink:0"></span>
                            <span style="color:var(--text-2)"><?= $lbl ?></span>
                        </span>
                        <span>
                            <span style="font-family:var(--mono);font-weight:700;color:<?= $col ?>"><?= $d['cnt'] ?></span>
                            <span style="color:var(--text-3);margin-left:.4rem;font-size:.67rem">रू <?= number_format($d['total'], 0) ?></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- TOP PAYERS + EXPIRING SOON -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="section-label">Subscribers</div>
<div class="chart-row2">
    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">🏅 Top Payers</span>
                <span class="card-sub">By lifetime revenue</span>
            </div>
            <?php if (!empty($topPayers)): ?>
            <div class="payers-list">
                <?php foreach ($topPayers as $i => $pay): ?>
                <div class="payer-row">
                    <div class="payer-rank"><?= $i + 1 ?>.</div>
                    <div class="payer-info">
                        <div class="payer-name"><?= htmlspecialchars($pay['full_name']) ?></div>
                        <div class="payer-email"><?= htmlspecialchars($pay['email']) ?></div>
                    </div>
                    <div style="text-align:right">
                        <div class="payer-amt">रू <?= number_format((float)$pay['total_paid'], 0) ?></div>
                        <div class="payer-cnt"><?= $pay['sub_count'] ?> sub<?= $pay['sub_count'] > 1 ? 's' : '' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--text-3);font-size:.78rem;text-align:center;padding:1.5rem 0">No payer data</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-inner">
            <div class="card-head">
                <span class="card-title">⚠️ Expiring Soon</span>
                <span class="card-sub">Within 7 days</span>
            </div>
            <?php if (!empty($expiringSoon)): ?>
            <div class="expiry-list">
                <?php foreach ($expiringSoon as $ex):
                    $daysLeft = max(0, ceil((strtotime($ex['expires_at']) - time()) / 86400));
                ?>
                <div class="expiry-row">
                    <div>
                        <div class="expiry-name"><?= htmlspecialchars($ex['full_name']) ?></div>
                        <div class="expiry-date"><?= htmlspecialchars($ex['plan_name']) ?> · expires <?= date('d M', strtotime($ex['expires_at'])) ?></div>
                    </div>
                    <div class="expiry-days"><?= $daysLeft ?>d left</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 0;gap:.5rem">
                <div style="font-size:2rem">✅</div>
                <div style="font-size:.8rem;color:var(--text-2);font-weight:600">No renewals due soon</div>
                <div style="font-size:.7rem;color:var(--text-3)">All subscriptions are healthy</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- TRANSACTION LEDGER -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="section-label">Transaction Ledger</div>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-inner">
        <div class="card-head">
            <span class="card-title">All Payments — <?= htmlspecialchars($rangeLabel) ?></span>
            <span class="card-sub"><?= number_format($totalTxns) ?> transactions</span>
        </div>
    </div>
    <div class="ledger-wrap">
        <table class="ledger-table">
            <thead><tr>
                <th>User</th>
                <th>Plan</th>
                <th>Amount</th>
                <th>Status</th>
                <th>eSewa Ref</th>
                <th>UUID</th>
                <th>Started</th>
                <th>Expires</th>
                <th>Paid At</th>
            </tr></thead>
            <tbody>
            <?php if (!empty($transactions)): ?>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td>
                        <div class="u-name"><?= htmlspecialchars($t['full_name']) ?></div>
                        <div class="u-email"><?= htmlspecialchars($t['email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($t['plan_name']) ?></td>
                    <td class="amt-cell">रू <?= number_format((float)$t['amount'], 0) ?></td>
                    <td><span class="status-chip s-<?= htmlspecialchars($t['status']) ?>"><?= ucfirst($t['status']) ?></span></td>
                    <td><span class="ref-code"><?= htmlspecialchars($t['esewa_ref_id'] ?? '—') ?></span></td>
                    <td><span class="ref-code"><?= htmlspecialchars(substr($t['transaction_uuid'] ?? '—', 0, 18)) ?>…</span></td>
                    <td style="font-size:.68rem;font-family:var(--mono)"><?= $t['starts_at']  ? date('d M Y', strtotime($t['starts_at']))  : '—' ?></td>
                    <td style="font-size:.68rem;font-family:var(--mono)"><?= $t['expires_at'] ? date('d M Y', strtotime($t['expires_at'])) : '—' ?></td>
                    <td style="font-size:.68rem;font-family:var(--mono)"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-3)">No transactions found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="card-inner" style="padding-top:0">
        <div class="pagination">
            <a href="?range=<?= $range ?>&page=<?= max(1, $page - 1) ?>" class="pag-btn <?= $page <= 1 ? 'disabled' : '' ?>">← Prev</a>
            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
            <a href="?range=<?= $range ?>&page=<?= $p ?>" class="pag-btn <?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="?range=<?= $range ?>&page=<?= min($totalPages, $page + 1) ?>" class="pag-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next →</a>
        </div>
        <p style="text-align:center;font-size:.68rem;color:var(--text-3);margin-top:.6rem">
            Page <?= $page ?> of <?= $totalPages ?> · <?= $totalTxns ?> total records
        </p>
    </div>
    <?php endif; ?>
</div>

</div><!-- /bal-page -->

<!-- ─────────────────────────────────────────────────────── -->
<!-- CHARTS -->
<!-- ─────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = '#475569';
Chart.defaults.borderColor = 'rgba(255,255,255,0.04)';
Chart.defaults.font.family = "'IBM Plex Mono', monospace";
Chart.defaults.font.size   = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(6,11,20,.97)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(232,184,75,.3)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 8;
Chart.defaults.plugins.tooltip.titleColor      = '#94a3b8';
Chart.defaults.plugins.tooltip.bodyColor       = '#f0f4ff';

// Daily revenue bar
new Chart(document.getElementById('chartDailyRev'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dLabels) ?>,
        datasets: [
            {
                label: 'Revenue (रू)',
                data: <?= json_encode($dRevenue) ?>,
                backgroundColor: 'rgba(232,184,75,0.22)',
                borderColor: '#e8b84b',
                borderWidth: 1.5, borderRadius: 3,
                yAxisID: 'y',
            },
            {
                label: 'Transactions',
                data: <?= json_encode($dCount) ?>,
                type: 'line',
                borderColor: '#3b82f6',
                backgroundColor: 'transparent',
                borderWidth: 2, pointRadius: 2,
                pointBackgroundColor: '#3b82f6',
                tension: 0.4, yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true, labels: { color:'#64748b', boxWidth:10, font:{size:10} } } },
        scales: {
            x: { grid:{color:'rgba(255,255,255,.03)'}, ticks:{maxTicksLimit:10,maxRotation:40,font:{size:9}} },
            y: { grid:{color:'rgba(255,255,255,.03)'}, beginAtZero:true, ticks:{callback:v=>'रू'+Number(v).toLocaleString()} },
            y1: { position:'right', grid:{display:false}, beginAtZero:true, ticks:{precision:0} }
        }
    }
});

// Monthly revenue line
new Chart(document.getElementById('chartMonthly'), {
    type: 'line',
    data: {
        labels: <?= json_encode($mLabels ?: ['No data']) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode($mRevenue ?: [0]) ?>,
            borderColor: '#e8b84b',
            backgroundColor: 'rgba(232,184,75,0.07)',
            tension: 0.4, pointRadius: 4,
            pointBackgroundColor: '#e8b84b',
            fill: true, borderWidth: 2,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{
            x:{ grid:{color:'rgba(255,255,255,.03)'} },
            y:{ grid:{color:'rgba(255,255,255,.03)'}, beginAtZero:true, ticks:{callback:v=>'रू'+Number(v).toLocaleString()} }
        }
    }
});

// Status donut
new Chart(document.getElementById('chartStatus'), {
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
            backgroundColor: ['rgba(16,185,129,.6)','rgba(148,163,184,.4)','rgba(232,184,75,.55)','rgba(239,68,68,.5)'],
            borderColor: ['#10b981','#94a3b8','#e8b84b','#ef4444'],
            borderWidth: 1.5,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true, cutout:'65%',
        plugins:{
            legend:{ display:true, position:'bottom', labels:{ font:{size:9}, color:'#64748b', boxWidth:9, padding:6 } }
        }
    }
});

// Animate plan fill bars
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-w]').forEach(el => { el.style.width = el.dataset.w + '%'; });
});
</script>

<?php if (function_exists('layoutFooter')) layoutFooter(); ?>