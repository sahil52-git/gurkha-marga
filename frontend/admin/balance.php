<?php
// ════════════════════════════════════════════════════════════════════════════
//  balance.php — Revenue & Financial Dashboard
//  Inherits sidebar, CSS variables, helpers from admin_layout.php
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

// ── Load layout (auth guard, sidebar, CSS, helpers) ───────────────────────
require_once __DIR__ . '/admin_layout.php';

// ── Date range filter ─────────────────────────────────────────────────────
$range   = $_GET['range'] ?? '30';
$pg      = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($pg - 1) * $perPage;

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

// ── Live DB queries ───────────────────────────────────────────────────────
try {
    $totalCollected = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions WHERE status IN('active','expired') $whereGlobal")['s'] ?? 0);
    $activeRevenue  = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions WHERE status='active' $whereGlobal")['s'] ?? 0);
    $expiredRevenue = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions WHERE status='expired' $whereGlobal")['s'] ?? 0);
    $pendingRevenue = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) AS s FROM subscriptions WHERE status='pending' $whereGlobal")['s'] ?? 0);
    $txnCount       = (int)(fetchOne("SELECT COUNT(*) AS c FROM subscriptions WHERE status IN('active','expired') $whereGlobal")['c'] ?? 0);
    $activeSubs     = (int)(fetchOne("SELECT COUNT(*) AS c FROM subscriptions WHERE status='active' AND expires_at > NOW()")['c'] ?? 0);
    $pendingCount   = (int)(fetchOne("SELECT COUNT(*) AS c FROM subscriptions WHERE status='pending' $whereGlobal")['c'] ?? 0);
    $avgTxn         = $txnCount > 0 ? $totalCollected / $txnCount : 0;

    $planRevenue    = fetchAll("SELECT p.name, p.slug, COUNT(s.id) AS cnt, COALESCE(SUM(s.amount),0) AS total FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.status IN('active','expired') $whereDate GROUP BY p.id ORDER BY total DESC") ?: [];
    $dailyRevenue   = fetchAll("SELECT DATE_FORMAT(created_at,'%b %d') AS lbl, DATE(created_at) AS d, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM subscriptions WHERE status IN('active','expired') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d ASC") ?: [];
    $monthlyRevenue = fetchAll("SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, DATE_FORMAT(MIN(created_at),'%b %Y') AS label, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM subscriptions WHERE status IN('active','expired') AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY m ASC") ?: [];
    $statusBreakdown= fetchAll("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM subscriptions WHERE 1=1 $whereGlobal GROUP BY status") ?: [];
    $totalTxns      = (int)(fetchOne("SELECT COUNT(*) AS c FROM subscriptions s WHERE 1=1 $whereDate")['c'] ?? 0);
    $transactions   = fetchAll("SELECT s.*, u.full_name, u.email, p.name AS plan_name, p.slug AS plan_slug FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN subscription_plans p ON p.id = s.plan_id WHERE 1=1 $whereDate ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset") ?: [];
    $topPayers      = fetchAll("SELECT u.full_name, u.email, COUNT(s.id) AS cnt, COALESCE(SUM(s.amount),0) AS total FROM subscriptions s JOIN users u ON u.id = s.user_id WHERE s.status IN('active','expired') GROUP BY s.user_id ORDER BY total DESC LIMIT 6") ?: [];
    $expiringSoon   = fetchAll("SELECT s.*, u.full_name, u.email, p.name AS plan_name FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN subscription_plans p ON p.id = s.plan_id WHERE s.status = 'active' AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) ORDER BY s.expires_at ASC LIMIT 8") ?: [];
    $recentPayments = fetchAll("SELECT s.amount, s.status, s.created_at, u.full_name, p.name AS plan_name, p.slug FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN subscription_plans p ON p.id = s.plan_id WHERE s.status IN('active','expired','pending') ORDER BY s.created_at DESC LIMIT 5") ?: [];
} catch (Exception $e) {
    $totalCollected = $activeRevenue = $expiredRevenue = $pendingRevenue
        = $txnCount = $activeSubs = $pendingCount = $avgTxn = 0;
    $planRevenue = $dailyRevenue = $monthlyRevenue = $statusBreakdown
        = $transactions = $topPayers = $expiringSoon = $recentPayments = [];
    $totalTxns = 0;
}

$totalPages = max(1, (int)ceil($totalTxns / $perPage));

// ── Build chart arrays ────────────────────────────────────────────────────
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

<!-- Page-level styles that extend the layout's base ──────────────────────── -->
<style>
/* ── Hero ── */
.hero {
    background: var(--card-bg, rgba(30,41,59,.8));
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    padding: 1.75rem 2rem 1.5rem;
    position: relative; overflow: hidden;
    margin-bottom: 1.25rem;
    backdrop-filter: blur(12px);
    animation: fadeUp .3s ease;
}
.hero::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background: linear-gradient(90deg, var(--gold), rgba(251,191,36,.1));
}
.hero::after {
    content:''; position:absolute; top:-80px; right:-80px;
    width:280px; height:280px;
    background: radial-gradient(circle, rgba(251,191,36,.06) 0%, transparent 70%);
    pointer-events:none;
}
.hero-label  { font-size:.62rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--gold); opacity:.75; margin-bottom:.45rem; }
.hero-amount { font-size:3rem; font-weight:800; font-family:var(--mono,'IBM Plex Mono',monospace); color:var(--gold); letter-spacing:-.04em; line-height:1; }
.hero-curr   { font-size:1.3rem; font-weight:600; opacity:.7; margin-right:.15rem; }
.hero-sub    { font-size:.73rem; color:var(--text-3, #64748b); margin-top:.35rem; }
.hero-grid   {
    display:grid; grid-template-columns:repeat(4,1fr); gap:.85rem;
    margin-top:1.35rem; padding-top:1.35rem; border-top:1px solid var(--border);
}
.hg-val { font-size:1.05rem; font-weight:700; font-family:var(--mono,'IBM Plex Mono',monospace); }
.hg-lbl { font-size:.62rem; color:var(--text-3,#64748b); margin-top:3px; }

/* ── KPI row ── */
.kpi-row { display:grid; grid-template-columns:repeat(3,1fr); gap:.85rem; margin-bottom:1.25rem; }
.kpi {
    background:var(--card-bg,rgba(30,41,59,.8)); border:1px solid var(--border);
    border-radius:var(--r,14px); padding:1.1rem 1.25rem;
    position:relative; overflow:hidden; backdrop-filter:blur(12px);
    transition:border-color .2s, transform .2s; animation:fadeUp .35s ease both;
}
.kpi:hover { border-color:var(--border-hi,rgba(255,255,255,.12)); transform:translateY(-2px); }
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
.kpi.green::before { background:linear-gradient(90deg,#10b981,#34d399); }
.kpi.gold::before  { background:linear-gradient(90deg,var(--gold),#f59e0b); }
.kpi.amber::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
.kpi-label { font-size:.62rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--text-3,#64748b); margin-bottom:.5rem; }
.kpi-val   { font-size:1.7rem; font-weight:800; font-family:var(--mono,'IBM Plex Mono',monospace); line-height:1; }
.kpi-sub   { font-size:.68rem; color:var(--text-2,#cbd5e1); margin-top:.35rem; }
.chip { display:inline-block; font-size:.62rem; font-family:var(--mono,'IBM Plex Mono',monospace); padding:.1rem .45rem; border-radius:4px; margin-top:.4rem; font-weight:600; }
.chip.up   { background:rgba(16,185,129,.12); color:#6ee7b7; border:1px solid rgba(16,185,129,.25); }
.chip.warn { background:rgba(245,158,11,.12); color:#fcd34d; border:1px solid rgba(245,158,11,.25); }
.chip.muted{ background:rgba(255,255,255,.05); color:var(--text-2,#cbd5e1); border:1px solid var(--border); }

/* ── Two-col grid layouts ── */
.g2  { display:grid; grid-template-columns:3fr 2fr; gap:1.1rem; margin-bottom:1.25rem; }
.g2b { display:grid; grid-template-columns:2fr 1fr; gap:1.1rem; margin-bottom:1.25rem; }
.col { display:flex; flex-direction:column; gap:1.1rem; }

/* ── Chart area ── */
.chart-wrap { padding:1.1rem 1.25rem; height:230px; position:relative; }

/* ── Plan rows ── */
.plan-row {
    display:flex; align-items:center; gap:.75rem;
    padding:.65rem .85rem; border-radius:9px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    margin-bottom:.4rem; transition:background .15s;
}
.plan-row:last-child  { margin-bottom:0; }
.plan-row:hover       { background:rgba(255,255,255,.055); }
.plan-bar-track { width:80px; height:4px; background:rgba(255,255,255,.06); border-radius:2px; overflow:hidden; }
.plan-bar-fill  { height:100%; border-radius:2px; transition:width .9s cubic-bezier(.4,0,.2,1) .2s; width:0; }

/* ── Status legend ── */
.donut-legend { display:grid; gap:.35rem; margin-top:.75rem; }
.dl-row {
    display:flex; justify-content:space-between; align-items:center;
    font-size:.74rem; padding:.28rem .55rem; border-radius:6px;
    background:rgba(255,255,255,.03);
}
.dl-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; display:inline-block; }

/* ── Live feed ── */
.feed-item {
    display:flex; align-items:center; gap:.75rem;
    padding:.6rem .85rem; border-bottom:1px solid rgba(255,255,255,.035);
}
.feed-item:last-child { border-bottom:none; }
.feed-av {
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg,var(--accent,#3b82f6),#8b5cf6);
    display:flex; align-items:center; justify-content:center;
    font-size:.75rem; font-weight:800; color:#fff;
}
.feed-av.gold { background:linear-gradient(135deg,var(--gold),#f59e0b); color:#0a0800; }
.feed-av.red  { background:linear-gradient(135deg,#ef4444,#fca5a5); }
.live-dot { width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block; animation:ldot 1.8s ease infinite; }
@keyframes ldot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }

/* ── Expiry / payer rows ── */
.expiry-row {
    display:flex; align-items:center; justify-content:space-between;
    padding:.55rem .85rem; border-radius:9px; margin-bottom:.4rem;
    background:rgba(239,68,68,.05); border:1px solid rgba(239,68,68,.12);
}
.expiry-row:last-child { margin-bottom:0; }
.payer-row {
    display:flex; align-items:center; gap:.75rem;
    padding:.6rem .85rem; border-radius:9px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    margin-bottom:.4rem; transition:background .15s;
}
.payer-row:last-child { margin-bottom:0; }
.payer-row:hover { background:rgba(255,255,255,.055); }
.payer-av {
    width:30px; height:30px; border-radius:8px;
    background:rgba(251,191,36,.1); border:1px solid rgba(251,191,36,.25);
    display:flex; align-items:center; justify-content:center;
    font-size:.72rem; font-weight:800; color:var(--gold); flex-shrink:0;
}

/* ── Ledger table ── */
.ledger { width:100%; border-collapse:collapse; white-space:nowrap; min-width:700px; }
.ledger th {
    text-align:left; padding:.6rem 1rem;
    font-size:.6rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
    color:var(--text-3,#64748b); border-bottom:1px solid var(--border);
    background:rgba(15,23,42,.4);
}
.ledger td {
    padding:.75rem 1rem; border-bottom:1px solid rgba(255,255,255,.035);
    font-size:.79rem; color:var(--text-2,#cbd5e1); vertical-align:middle;
}
.ledger tbody tr:last-child td { border-bottom:none; }
.ledger tbody tr:hover td { background:rgba(59,130,246,.03); color:var(--text,#f8fafc); }
.amt { font-family:var(--mono,'IBM Plex Mono',monospace); font-weight:700; color:var(--gold); }
.ref { font-size:.67rem; font-family:var(--mono,'IBM Plex Mono',monospace); color:var(--text-3,#64748b); max-width:130px; overflow:hidden; text-overflow:ellipsis; display:block; }

/* ── Range pills ── */
.range-pills { display:flex; gap:.35rem; flex-wrap:wrap; margin-left:auto; }
.pill {
    padding:.32rem .8rem; border-radius:99px; font-size:.72rem;
    font-weight:600; text-decoration:none; border:1px solid var(--border);
    color:var(--text-2,#cbd5e1); background:rgba(255,255,255,.04);
    transition:all .15s; white-space:nowrap;
}
.pill:hover  { border-color:var(--border-hi,rgba(255,255,255,.12)); color:var(--text,#f8fafc); }
.pill.active { background:rgba(251,191,36,.15); border-color:rgba(251,191,36,.3); color:var(--gold); }

@keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
@media (max-width:1200px) { .g2 { grid-template-columns:1fr; } .hero-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:1000px) { .kpi-row { grid-template-columns:1fr 1fr; } .g2b { grid-template-columns:1fr; } }
@media (max-width:768px)  { .kpi-row { grid-template-columns:1fr; } .hero-amount { font-size:2rem; } .hero-grid { grid-template-columns:repeat(2,1fr); } }
</style>

<!-- Page header -->
<div class="page-header" style="flex-wrap:wrap; gap:.75rem">
    <div>
        <h1 class="page-title">Balance &amp; Revenue</h1>
        <p class="page-sub">
            eSewa payments &nbsp;·&nbsp; <?= htmlspecialchars($rangeLabel) ?>
            &nbsp;·&nbsp; <?= date('d M Y, H:i') ?>
            &nbsp;·&nbsp; <span class="live-dot"></span>&nbsp;Live
        </p>
    </div>
    <div class="range-pills">
        <?php foreach ($rangeMap as $k => [$_, $lbl]): ?>
        <a href="?range=<?= $k ?>&page=1" class="pill <?= $range === $k ? 'active' : '' ?>">
            <?= htmlspecialchars($lbl) ?>
        </a>
        <?php endforeach; ?>
        <a href="balance.php?range=<?= $range ?>" class="pill" title="Refresh" style="padding:.32rem .65rem">⟳</a>
    </div>
</div>

<!-- Flash -->
<?php if (!empty($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="flash <?= $f['type'] ?>">
    <?= $f['type'] === 'success' ? '✓' : '⚠' ?> <?= htmlspecialchars($f['msg']) ?>
</div>
<?php endif; ?>

<!-- ═══ HERO ═══ -->
<div class="hero">
    <div class="hero-label">Total Revenue Collected — <?= htmlspecialchars($rangeLabel) ?></div>
    <div class="hero-amount"><span class="hero-curr">रू</span><?= number_format($totalCollected, 2) ?></div>
    <div class="hero-sub">Confirmed eSewa payments (active + expired subscriptions only)</div>
    <div class="hero-grid">
        <div>
            <div class="hg-val" style="color:#6ee7b7">रू <?= number_format($activeRevenue, 0) ?></div>
            <div class="hg-lbl">Active subscriptions</div>
        </div>
        <div>
            <div class="hg-val" style="color:var(--text-2,#cbd5e1)">रू <?= number_format($expiredRevenue, 0) ?></div>
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

<!-- ═══ CHARTS ═══ -->
<p class="section-label">Revenue Trends</p>
<div class="g2">
    <div class="card mb" style="animation-delay:.1s;margin-bottom:0">
        <div class="card-head">
            <div class="card-title">Daily Revenue — Last 30 Days</div>
            <span style="font-size:.67rem;color:var(--text-3);font-family:var(--mono,'IBM Plex Mono',monospace)">
                रू <?= number_format(array_sum($dRevenue), 0) ?> total
            </span>
        </div>
        <div class="chart-wrap"><canvas id="chartDaily"></canvas></div>
    </div>
    <div class="card mb" style="animation-delay:.15s;margin-bottom:0">
        <div class="card-head">
            <div class="card-title">Monthly — Last 12 Months</div>
        </div>
        <div class="chart-wrap"><canvas id="chartMonthly"></canvas></div>
    </div>
</div>

<!-- ═══ BREAKDOWN ═══ -->
<p class="section-label" style="margin-top:.25rem">Subscription Breakdown</p>
<div class="g2">
    <!-- Plan breakdown -->
    <div class="card mb" style="animation-delay:.18s;margin-bottom:0">
        <div class="card-head">
            <div class="card-title">Revenue by Plan</div>
            <span class="badge badge-muted">Confirmed only</span>
        </div>
        <div style="padding:1rem 1.25rem">
            <?php if (!empty($planRevenue)):
                $planColors = ['monthly'=>'#3b82f6','quarterly'=>'#fbbf24','annual'=>'#10b981'];
                $planIcons  = ['monthly'=>'📅','quarterly'=>'🏆','annual'=>'🎖️'];
                foreach ($planRevenue as $p):
                    $pct  = (int)round((float)$p['total'] / $planTotal * 100);
                    $pCol = $planColors[$p['slug'] ?? ''] ?? '#8b5cf6';
            ?>
            <div class="plan-row">
                <span style="font-size:1.1rem"><?= $planIcons[$p['slug'] ?? ''] ?? '📦' ?></span>
                <div style="flex:1">
                    <div style="font-size:.8rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($p['name']) ?></div>
                    <div style="font-size:.65rem;color:var(--text-3);margin-top:1px"><?= $p['cnt'] ?> sub<?= $p['cnt'] != 1 ? 's' : '' ?></div>
                </div>
                <div class="plan-bar-track">
                    <div class="plan-bar-fill" data-w="<?= $pct ?>" style="background:<?= $pCol ?>"></div>
                </div>
                <div style="text-align:right;min-width:85px">
                    <div style="font-size:.92rem;font-weight:800;font-family:var(--mono,'IBM Plex Mono',monospace);color:var(--gold)">रू <?= number_format((float)$p['total'], 0) ?></div>
                    <div style="font-size:.62rem;color:var(--text-3)"><?= $pct ?>%</div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-row"><div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">📊</div>No revenue data for this period</div>
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
                            <span class="dl-dot" style="background:<?= $col ?>"></span><?= $lbl ?>
                        </span>
                        <span>
                            <span style="font-family:var(--mono,'IBM Plex Mono',monospace);font-weight:700;color:<?= $col ?>"><?= $d['cnt'] ?></span>
                            <span style="color:var(--text-3);margin-left:.4rem;font-size:.65rem">रू <?= number_format($d['total'], 0) ?></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Recent payments -->
        <div class="card" style="animation-delay:.26s">
            <div class="card-head">
                <div class="card-title">
                    <span class="live-dot" style="margin-right:5px"></span>Recent Payments
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
                    <div style="font-size:.79rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($rp['full_name']) ?></div>
                    <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($rp['plan_name']) ?> · <?= date('d M Y, H:i', strtotime($rp['created_at'])) ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:.85rem;font-weight:800;font-family:var(--mono,'IBM Plex Mono',monospace);color:var(--gold)">रू <?= number_format((float)$rp['amount'],0) ?></div>
                    <span class="badge badge-<?= $isActive?'green':($isPend?'amber':'muted') ?>" style="font-size:.6rem"><?= ucfirst($rp['status']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-row"><div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">💳</div>No recent payments</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══ TOP PAYERS + EXPIRING SOON ═══ -->
<div class="g2b">
    <div class="card mb" style="animation-delay:.28s;margin-bottom:0">
        <div class="card-head">
            <div class="card-title">🏅 Highest Lifetime Revenue</div>
            <span style="font-size:.67rem;color:var(--text-3)">All time · confirmed only</span>
        </div>
        <div style="padding:1rem 1.25rem">
            <?php if (!empty($topPayers)):
                foreach ($topPayers as $i => $pay): ?>
            <div class="payer-row">
                <span style="font-size:.72rem;font-weight:800;font-family:var(--mono,'IBM Plex Mono',monospace);color:var(--text-3);width:18px;flex-shrink:0"><?= $i+1 ?>.</span>
                <div class="payer-av"><?= strtoupper(substr($pay['full_name'],0,1)) ?></div>
                <div style="flex:1;overflow:hidden">
                    <div style="font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($pay['full_name']) ?></div>
                    <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($pay['email']) ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:.9rem;font-weight:800;font-family:var(--mono,'IBM Plex Mono',monospace);color:var(--gold)">रू <?= number_format((float)$pay['total'],0) ?></div>
                    <div style="font-size:.62rem;color:var(--text-3)"><?= $pay['cnt'] ?> sub<?= $pay['cnt']>1?'s':'' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-row"><div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">🏅</div>No payer data yet</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb" style="animation-delay:.3s;margin-bottom:0">
        <div class="card-head">
            <div class="card-title">⚠ Expiring Soon</div>
            <span style="font-size:.67rem;color:var(--text-3)">Within 7 days</span>
        </div>
        <div style="padding:1rem 1.25rem">
            <?php if (!empty($expiringSoon)):
                foreach ($expiringSoon as $ex):
                    $dLeft = max(0, (int)ceil((strtotime($ex['expires_at'])-time())/86400)); ?>
            <div class="expiry-row">
                <div>
                    <div style="font-size:.79rem;font-weight:600"><?= htmlspecialchars($ex['full_name']) ?></div>
                    <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($ex['plan_name']) ?> · expires <?= date('d M', strtotime($ex['expires_at'])) ?></div>
                </div>
                <div style="font-size:.75rem;font-weight:800;color:#fca5a5;font-family:var(--mono,'IBM Plex Mono',monospace)"><?= $dLeft ?>d left</div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-row" style="padding:1.5rem">
                <div style="font-size:1.75rem;margin-bottom:.35rem">✅</div>
                <div style="font-size:.78rem;font-weight:600">All subscriptions healthy</div>
                <div style="font-size:.67rem;color:var(--text-3);margin-top:2px">No renewals due within 7 days</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══ TRANSACTION LEDGER ═══ -->
<p class="section-label" style="margin-top:.25rem">Transaction Ledger</p>
<div class="card mb" style="animation-delay:.32s">
    <div class="card-head">
        <div class="card-title">All Payments — <?= htmlspecialchars($rangeLabel) ?></div>
        <span style="font-size:.67rem;color:var(--text-3);font-family:var(--mono,'IBM Plex Mono',monospace)"><?= number_format($totalTxns) ?> records</span>
    </div>
    <div class="tbl-wrap">
        <table class="ledger">
            <thead><tr>
                <th>#</th><th>User</th><th>Plan</th><th>Amount</th><th>Status</th>
                <th>eSewa Ref</th><th>Started</th><th>Expires</th><th>Date</th>
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
                <td class="mono" style="color:var(--text-3);font-size:.7rem"><?= $offset + $idx + 1 ?></td>
                <td>
                    <div style="font-weight:600;font-size:.8rem"><?= htmlspecialchars($t['full_name']) ?></div>
                    <div style="font-size:.65rem;color:var(--text-3)"><?= htmlspecialchars($t['email']) ?></div>
                </td>
                <td><?= htmlspecialchars($t['plan_name']) ?></td>
                <td class="amt">रू <?= number_format((float)$t['amount'], 0) ?></td>
                <td><span class="badge <?= $badgeCls ?>"><?= ucfirst($sc) ?></span></td>
                <td><span class="ref"><?= htmlspecialchars($t['esewa_ref_id'] ?? '—') ?></span></td>
                <td class="mono" style="color:var(--text-3);font-size:.7rem"><?= $t['starts_at']  ? date('d M Y', strtotime($t['starts_at']))  : '—' ?></td>
                <td class="mono" style="color:var(--text-3);font-size:.7rem"><?= $t['expires_at'] ? date('d M Y', strtotime($t['expires_at'])) : '—' ?></td>
                <td class="mono" style="color:var(--text-3);font-size:.7rem;white-space:nowrap"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr><td colspan="9" class="empty-row">
                <div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">📋</div>
                No transactions found for this period.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <a href="?range=<?= $range ?>&page=<?= max(1,$pg-1) ?>" class="pg-btn <?= $pg<=1?'disabled':'' ?>">‹</a>
        <?php for ($p = max(1,$pg-3); $p <= min($totalPages,$pg+3); $p++): ?>
        <a href="?range=<?= $range ?>&page=<?= $p ?>" class="pg-btn <?= $p===$pg?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="?range=<?= $range ?>&page=<?= min($totalPages,$pg+1) ?>" class="pg-btn <?= $pg>=$totalPages?'disabled':'' ?>">›</a>
    </div>
    <p style="text-align:center;font-size:.65rem;color:var(--text-3);padding:.5rem 0 .85rem">
        Page <?= $pg ?> of <?= $totalPages ?> &bull; <?= number_format($totalTxns) ?> total
    </p>
    <?php endif; ?>
</div>

<!-- Chart.js (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = '#64748b';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Poppins', sans-serif";
Chart.defaults.font.size   = 10;
Chart.defaults.plugins.legend.display              = false;
Chart.defaults.plugins.tooltip.backgroundColor     = 'rgba(15,23,42,0.95)';
Chart.defaults.plugins.tooltip.borderColor         = 'rgba(251,191,36,0.3)';
Chart.defaults.plugins.tooltip.borderWidth         = 1;
Chart.defaults.plugins.tooltip.padding             = 10;
Chart.defaults.plugins.tooltip.cornerRadius        = 8;

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
                hoverBackgroundColor: 'rgba(251,191,36,0.35)', yAxisID: 'y',
            },
            {
                label: 'Transactions',
                data:  <?= json_encode($dCount) ?>,
                type: 'line', borderColor: '#3b82f6', backgroundColor: 'transparent',
                borderWidth: 2, pointRadius: 2.5, pointBackgroundColor: '#3b82f6',
                tension: 0.4, yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: true, labels: { color:'#64748b', boxWidth:10, font:{size:9}, padding:12 } },
            tooltip: { callbacks: { label: ctx => ctx.dataset.yAxisID==='y' ? ' रू '+ctx.parsed.y.toLocaleString() : ' '+ctx.parsed.y+' txns' } }
        },
        scales: {
            x:  { grid:{color:'rgba(255,255,255,.04)'}, ticks:{maxTicksLimit:10, maxRotation:40, font:{size:9}} },
            y:  { grid:{color:'rgba(255,255,255,.04)'}, beginAtZero:true, ticks:{callback:v=>'रू'+Number(v).toLocaleString()} },
            y1: { position:'right', grid:{display:false}, beginAtZero:true, ticks:{precision:0} }
        }
    }
});

new Chart(document.getElementById('chartMonthly'), {
    type: 'line',
    data: {
        labels: <?= json_encode($mLabels ?: ['No data']) ?>,
        datasets: [{
            label: 'Revenue',
            data:  <?= json_encode($mRevenue ?: [0]) ?>,
            borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,0.07)',
            tension: 0.4, pointRadius: 4, pointBackgroundColor: '#fbbf24',
            fill: true, borderWidth: 2,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend:{display:false} },
        scales: {
            x: { grid:{color:'rgba(255,255,255,.04)'} },
            y: { grid:{color:'rgba(255,255,255,.04)'}, beginAtZero:true, ticks:{callback:v=>'रू'+Number(v).toLocaleString()} }
        }
    }
});

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
            backgroundColor: ['rgba(16,185,129,.45)','rgba(148,163,184,.35)','rgba(251,191,36,.45)','rgba(239,68,68,.45)'],
            borderColor:     ['#10b981','#94a3b8','#fbbf24','#ef4444'],
            borderWidth: 1.5,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true, cutout:'65%',
        plugins: { legend:{display:false} }
    }
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.plan-bar-fill[data-w]').forEach(el => {
        el.style.width = el.dataset.w + '%';
    });
});

/* Auto-refresh every 60 s */
setTimeout(() => location.reload(), 60000);
</script>

<?php layoutFooter(); ?>

