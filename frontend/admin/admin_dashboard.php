<?php
/**
 * Admin Dashboard — Gurkha Marga
 */
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// ── Core Stats ───────────────────────────────────────────────────────────────
$stats = [];
try {
    $stats['total_users']    = (int)(fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0);
    $stats['active_users']   = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=1")['c'] ?? 0);
    $stats['inactive_users'] = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=0")['c'] ?? 0);
    $stats['new_today']      = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE DATE(created_at)=CURDATE()")['c'] ?? 0);
    $stats['new_week']       = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")['c'] ?? 0);
    $stats['new_month']      = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")['c'] ?? 0);
    $stats['new_3month']     = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)")['c'] ?? 0);
    $stats['new_6month']     = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 180 DAY)")['c'] ?? 0);
    $stats['new_year']       = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 365 DAY)")['c'] ?? 0);

    try { $stats['workouts']    = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts")['c'] ?? 0); }     catch(Exception $e){ $stats['workouts'] = 0; }
    try { $stats['staff_count'] = (int)(fetchOne("SELECT COUNT(*) as c FROM admin_staff WHERE is_active=1")['c'] ?? 0); } catch(Exception $e){ $stats['staff_count'] = 0; }

    try {
        $stats['premium_users']  = (int)(fetchOne("SELECT COUNT(DISTINCT user_id) as c FROM subscriptions WHERE status='active' AND expires_at > NOW()")['c'] ?? 0);
        $stats['total_revenue']  = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE status IN('active','expired')")['s'] ?? 0);
        $stats['revenue_month']  = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")['s'] ?? 0);
        $stats['revenue_week']   = (float)(fetchOne("SELECT COALESCE(SUM(amount),0) as s FROM subscriptions WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")['s'] ?? 0);
        $stats['pending_subs']   = (int)(fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE status='pending'")['c'] ?? 0);
    } catch(Exception $e) {
        $stats['premium_users'] = 0; $stats['total_revenue'] = 0;
        $stats['revenue_month'] = 0; $stats['revenue_week']  = 0; $stats['pending_subs'] = 0;
    }

    $bmiRow = fetchOne("SELECT AVG(weight/((height/100)*(height/100))) as avg_bmi FROM users WHERE height>0 AND weight>0");
    $stats['avg_bmi'] = $bmiRow ? round((float)($bmiRow['avg_bmi'] ?? 0), 1) : null;
    $ageRow = fetchOne("SELECT AVG(age) as avg_age FROM users WHERE age>0");
    $stats['avg_age'] = $ageRow ? round((float)($ageRow['avg_age'] ?? 0), 1) : null;

    $forceStats  = fetchAll("SELECT target_force, COUNT(*) as cnt FROM users WHERE target_force!='' AND target_force IS NOT NULL GROUP BY target_force ORDER BY cnt DESC") ?: [];
    $expStats    = fetchAll("SELECT experience_level, COUNT(*) as cnt FROM users WHERE experience_level IS NOT NULL AND experience_level!='' GROUP BY experience_level ORDER BY cnt DESC") ?: [];
    $genderStats = fetchAll("SELECT gender, COUNT(*) as cnt FROM users WHERE gender IS NOT NULL AND gender!='' GROUP BY gender") ?: [];
    $bmiStats    = fetchAll("SELECT CASE WHEN (weight/((height/100)*(height/100)))<18.5 THEN 'Underweight' WHEN (weight/((height/100)*(height/100)))<25 THEN 'Healthy' WHEN (weight/((height/100)*(height/100)))<30 THEN 'Overweight' ELSE 'Obese' END as category, COUNT(*) as cnt FROM users WHERE height>0 AND weight>0 GROUP BY category") ?: [];
    $dailySignups = fetchAll("SELECT DATE_FORMAT(created_at,'%b %d') as day_label, DATE(created_at) as day_date, COUNT(*) as cnt FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day_date ASC") ?: [];

    try {
        $monthlyRevenue = fetchAll("SELECT DATE_FORMAT(created_at,'%Y-%m') as m, DATE_FORMAT(MIN(created_at),'%b %Y') as label, COALESCE(SUM(amount),0) as total FROM subscriptions WHERE created_at>=DATE_SUB(NOW(),INTERVAL 12 MONTH) AND status IN('active','expired') GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY m ASC") ?: [];
    } catch(Exception $e) { $monthlyRevenue = []; }

    try {
        $recentSubs = fetchAll("SELECT s.*, u.full_name, u.email, p.name as plan_name FROM subscriptions s JOIN users u ON u.id=s.user_id JOIN subscription_plans p ON p.id=s.plan_id ORDER BY s.created_at DESC LIMIT 8") ?: [];
    } catch(Exception $e) { $recentSubs = []; }

    $recentUsers = fetchAll("SELECT id, full_name, email, target_force, experience_level, created_at, is_active FROM users ORDER BY created_at DESC LIMIT 8") ?: [];

} catch(Exception $e) {
    $stats = array_fill_keys(['total_users','active_users','inactive_users','new_today','new_week','new_month','new_3month','new_6month','new_year','workouts','staff_count','premium_users','total_revenue','revenue_month','revenue_week','pending_subs','avg_bmi','avg_age'], 0);
    $forceStats = $expStats = $genderStats = $bmiStats = $dailySignups = $monthlyRevenue = $recentSubs = $recentUsers = [];
}

// ── Derived maps ─────────────────────────────────────────────────────────────
$forceMap  = []; foreach($forceStats  as $r) $forceMap[$r['target_force']]    = (int)$r['cnt'];
$expMap    = []; foreach($expStats    as $r) $expMap[$r['experience_level']]   = (int)$r['cnt'];
$genderMap = []; foreach($genderStats as $r) $genderMap[$r['gender']]         = (int)$r['cnt'];
$bmiMap    = []; foreach($bmiStats    as $r) $bmiMap[$r['category']]          = (int)$r['cnt'];

$forceTotal  = max(array_sum($forceMap), 1);
$expTotal    = max(array_sum($expMap), 1);
$genderTotal = max(array_sum($genderMap), 1);
$bmiTotal    = max(array_sum($bmiMap), 1);
$activeRate  = $stats['total_users'] > 0 ? round($stats['active_users'] / $stats['total_users'] * 100, 1) : 0;
$premiumRate = $stats['total_users'] > 0 ? round($stats['premium_users'] / $stats['total_users'] * 100, 1) : 0;

// Daily registrations chart (last 30 days, fill gaps)
$dailyLabels = []; $dailyCounts = [];
$drLabelMap  = [];
foreach ($dailySignups as $row) $drLabelMap[$row['day_label']] = (int)$row['cnt'];
for ($i = 29; $i >= 0; $i--) {
    $d = date('M d', strtotime("-{$i} days"));
    $dailyLabels[] = $d;
    $dailyCounts[] = $drLabelMap[$d] ?? 0;
}

// Monthly revenue chart arrays
$revLabels  = [];
$revAmounts = [];
foreach ($monthlyRevenue as $r) {
    $revLabels[]  = $r['label'];
    $revAmounts[] = (float)$r['total'];
}

// Force chart data
$forceChartLabels = [];
$forceChartCounts = [];
foreach ($forceStats as $f) {
    $forceChartLabels[] = ucwords(str_replace('_', ' ', $f['target_force']));
    $forceChartCounts[] = (int)$f['cnt'];
}

// Period growth — safe max calculation
$periodValues = [
    $stats['new_week'], $stats['new_month'],
    $stats['new_3month'], $stats['new_6month'], $stats['new_year'],
];
$maxPeriod = max(array_merge($periodValues, [1]));

$periods = [
    ['label' => 'Last 7 Days',   'val' => $stats['new_week'],   'color' => '#3b6ff5'],
    ['label' => 'Last 30 Days',  'val' => $stats['new_month'],  'color' => '#2da44e'],
    ['label' => 'Last 3 Months', 'val' => $stats['new_3month'], 'color' => '#8b5cf6'],
    ['label' => 'Last 6 Months', 'val' => $stats['new_6month'], 'color' => '#c9973a'],
    ['label' => 'Last Year',     'val' => $stats['new_year'],   'color' => '#06b6d4'],
];
?>

<!-- ── PAGE STYLES ──────────────────────────────────────────────────────────── -->
<style>
/* Extend layout CSS — scoped additions only */
.dash-section-label {
    font-size: .59rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase;
    color: var(--text-dim); display: flex; align-items: center; gap: .6rem;
    margin-bottom: .9rem;
}
.dash-section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Period strip */
.period-strip { display: grid; grid-template-columns: repeat(5,1fr); gap: .75rem; margin-bottom: 1.25rem; }
.period-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 1rem 1.1rem;
    transition: border-color .18s;
}
.period-card:hover { border-color: var(--border2); }
.p-label { font-size: .61rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--text-dim); margin-bottom: .3rem; }
.p-val   { font-family: var(--mono); font-size: 1.55rem; font-weight: 500; color: var(--text); letter-spacing: -.03em; line-height: 1; }
.p-sub   { font-size: .63rem; color: var(--text-sub); margin-top: .2rem; }
.period-bar  { height: 3px; background: var(--elevated); border-radius: 2px; margin-top: .75rem; overflow: hidden; }
.period-fill { height: 100%; border-radius: 2px; transition: width 1.1s cubic-bezier(.4,0,.2,1) .2s; width: 0%; }

/* Balance inset card */
.balance-inset {
    background: var(--elevated); border: 1px solid var(--gold-border);
    border-radius: var(--r); padding: 1.1rem 1.25rem; margin-bottom: .85rem;
    position: relative; overflow: hidden;
}
.balance-inset::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--gold), rgba(201,151,58,.2));
}
.bal-label  { font-size: .61rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--text-dim); margin-bottom: .4rem; }
.bal-amount { font-family: var(--mono); font-size: 1.7rem; font-weight: 500; color: var(--gold); letter-spacing: -.03em; line-height: 1; }
.bal-currency { font-size: .9rem; font-weight: 400; margin-right: .15rem; }
.bal-sub    { font-size: .67rem; color: var(--text-sub); margin-top: .3rem; }
.bal-row    { display: flex; gap: 1.25rem; margin-top: .85rem; padding-top: .85rem; border-top: 1px solid var(--border); }
.bal-stat-val { font-family: var(--mono); font-size: .92rem; font-weight: 500; }
.bal-stat-lbl { font-size: .61rem; color: var(--text-dim); margin-top: 2px; }

/* Distribution bars */
.dist-divider { border: none; border-top: 1px solid var(--border); margin: .85rem 0; }
.dist-section-title { font-size: .59rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: .6rem; }

/* Recent table row hover already in layout */
.tbl-force { display: inline-block; padding: .13rem .45rem; border-radius: 3px; font-size: .65rem; font-weight: 600; background: var(--accent-dim); color: #7fa3f7; border: 1px solid var(--accent-border); font-family: var(--mono); }
.tbl-online { display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; }
.tbl-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dot-green { background: var(--green); }
.dot-red   { background: var(--red); }

/* Donut legend */
.donut-legend { display: flex; gap: .85rem; flex-wrap: wrap; justify-content: center; margin-top: .65rem; }
.dl-item { display: flex; align-items: center; gap: 5px; font-size: .7rem; color: var(--text-sub); }
.dl-dot  { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

/* Live pulse on topbar date */
.live-mark {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: var(--green); margin-right: 4px; vertical-align: middle;
    box-shadow: 0 0 0 0 rgba(45,164,78,.7);
    animation: livePulse 2.2s infinite;
}
@keyframes livePulse {
    0%  { box-shadow: 0 0 0 0 rgba(45,164,78,.7); }
    70% { box-shadow: 0 0 0 7px rgba(45,164,78,0); }
    100%{ box-shadow: 0 0 0 0 rgba(45,164,78,0); }
}

@media(max-width:1200px){ .period-strip{ grid-template-columns:repeat(3,1fr); } }
@media(max-width:800px) { .period-strip{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:500px) { .period-strip{ grid-template-columns:1fr; } }
</style>

<!-- ── PAGE HEADER ──────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub"><span class="live-mark"></span>Live snapshot &mdash; <?= date('d M Y, H:i:s') ?></p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="balance.php" class="btn btn-ghost">
            <svg viewBox="0 0 24 24"><path d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Balance
        </a>
        <a href="admin_staff.php?action=create" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Staff
        </a>
    </div>
</div>

<!-- ── KPI STRIP ──────────────────────────────────────────────────────────────── -->
<div class="dash-section-label">Platform Metrics</div>

<div class="stats-grid mb">
    <div class="stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-val"><?= number_format($stats['total_users']) ?></div>
        <div class="stat-sub">All-time registrations</div>
        <span class="stat-chip up">+<?= $stats['new_month'] ?> this month</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Users</div>
        <div class="stat-val"><?= number_format($stats['active_users']) ?></div>
        <div class="stat-sub"><?= $activeRate ?>% of total</div>
        <span class="stat-chip <?= $activeRate >= 70 ? 'up' : 'warn' ?>"><?= $activeRate ?>% rate</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Premium Users</div>
        <div class="stat-val"><?= number_format($stats['premium_users']) ?></div>
        <div class="stat-sub"><?= $premiumRate ?>% conversion</div>
        <span class="stat-chip neutral"><?= $stats['pending_subs'] ?> pending</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Workouts</div>
        <div class="stat-val"><?= number_format($stats['workouts']) ?></div>
        <div class="stat-sub">Logged sessions</div>
        <span class="stat-chip neutral"><?= $stats['staff_count'] ?> staff active</span>
    </div>
</div>

<div class="stats-grid cols-3 mb">
    <div class="stat-card">
        <div class="stat-label">Avg. BMI</div>
        <div class="stat-val"><?= $stats['avg_bmi'] ?? '—' ?></div>
        <div class="stat-sub">Users with body data</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg. Age</div>
        <div class="stat-val"><?= $stats['avg_age'] ?? '—' ?></div>
        <div class="stat-sub">Years, across all users</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Inactive Users</div>
        <div class="stat-val"><?= number_format($stats['inactive_users']) ?></div>
        <div class="stat-sub">Disabled accounts</div>
        <span class="stat-chip neutral">Today: +<?= $stats['new_today'] ?></span>
    </div>
</div>

<!-- ── PERIOD BREAKDOWN ───────────────────────────────────────────────────────── -->
<div class="dash-section-label">User Growth by Period</div>
<div class="period-strip">
    <?php foreach ($periods as $p):
        $pct = $maxPeriod > 0 ? (int)round($p['val'] / $maxPeriod * 100) : 0;
    ?>
    <div class="period-card">
        <div class="p-label"><?= htmlspecialchars($p['label']) ?></div>
        <div class="p-val"><?= number_format($p['val']) ?></div>
        <div class="p-sub">registrations</div>
        <div class="period-bar">
            <div class="period-fill" data-w="<?= $pct ?>" style="background:<?= $p['color'] ?>; width:0%"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── BALANCE + DAILY CHART ─────────────────────────────────────────────────── -->
<div class="dash-section-label">Revenue & Registrations</div>
<div class="grid-dash mb">

    <!-- Daily chart -->
    <div class="card">
        <div class="card-head">
            <div class="card-title">Daily Registrations — Last 30 Days</div>
            <span class="stat-chip up" style="margin:0">+<?= $stats['new_month'] ?> total</span>
        </div>
        <div class="chart-area tall">
            <canvas id="chartDaily"></canvas>
        </div>
    </div>

    <!-- Balance + Donut stack -->
    <div style="display:flex;flex-direction:column;gap:.85rem">
        <div class="balance-inset">
            <div class="bal-label">Platform Balance</div>
            <div class="bal-amount"><span class="bal-currency">&#2352;&#2369;</span><?= number_format($stats['total_revenue'], 0) ?></div>
            <div class="bal-sub">All-time confirmed eSewa payments</div>
            <div class="bal-row">
                <div>
                    <div class="bal-stat-val" style="color:#3dc96a">&#2352;&#2369; <?= number_format($stats['revenue_month'], 0) ?></div>
                    <div class="bal-stat-lbl">This month</div>
                </div>
                <div>
                    <div class="bal-stat-val" style="color:#7fa3f7">&#2352;&#2369; <?= number_format($stats['revenue_week'], 0) ?></div>
                    <div class="bal-stat-lbl">This week</div>
                </div>
                <div>
                    <div class="bal-stat-val" style="color:var(--gold)"><?= $stats['premium_users'] ?></div>
                    <div class="bal-stat-lbl">Active subs</div>
                </div>
            </div>
            <a href="balance.php" class="btn btn-ghost btn-sm" style="margin-top:.85rem;width:100%;justify-content:center">
                Full Balance Report &rarr;
            </a>
        </div>

        <div class="card" style="flex:1">
            <div class="card-head">
                <div class="card-title">Active vs Inactive</div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:center;padding:1rem;gap:.5rem">
                <canvas id="chartActive" style="max-width:150px;max-height:150px"></canvas>
                <div class="donut-legend">
                    <span class="dl-item"><span class="dl-dot" style="background:#2da44e"></span>Active <?= $stats['active_users'] ?></span>
                    <span class="dl-item"><span class="dl-dot" style="background:#cc3333"></span>Inactive <?= $stats['inactive_users'] ?></span>
                    <span class="dl-item"><span class="dl-dot" style="background:#c9973a"></span>Premium <?= $stats['premium_users'] ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── REVENUE TREND + FORCE CHART ───────────────────────────────────────────── -->
<div class="dash-section-label">Revenue Trend & Force Distribution</div>
<div class="grid-dash2 mb">
    <div class="card">
        <div class="card-head">
            <div class="card-title">Monthly Revenue — Last 12 Months</div>
        </div>
        <div class="chart-area tall">
            <canvas id="chartRevenue"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-head">
            <div class="card-title">Force Distribution</div>
        </div>
        <div class="chart-area tall">
            <canvas id="chartForce"></canvas>
        </div>
    </div>
</div>

<!-- ── DISTRIBUTIONS ──────────────────────────────────────────────────────────── -->
<div class="dash-section-label">User Breakdown</div>
<div class="grid-3 mb">

    <!-- BMI -->
    <div class="card">
        <div class="card-head"><div class="card-title">BMI Distribution</div></div>
        <div class="dist-list">
            <?php
            $bmiDefs = [
                'Healthy'     => ['#2da44e', 'Healthy (18.5–25)'],
                'Underweight' => ['#3b6ff5', 'Below 18.5'],
                'Overweight'  => ['#c97c2d', '25–30'],
                'Obese'       => ['#cc3333', 'Above 30'],
            ];
            foreach ($bmiDefs as $cat => [$col, $hint]):
                $cnt = $bmiMap[$cat] ?? 0;
                $pct = $bmiTotal > 0 ? (int)round($cnt / $bmiTotal * 100) : 0;
            ?>
            <div class="dist-row">
                <div class="dist-lbl" title="<?= $hint ?>"><?= $cat ?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?= $pct ?>" style="background:<?= $col ?>; width:0%"></div></div>
                <div class="dist-cnt"><?= $cnt ?> <span>(<?= $pct ?>%)</span></div>
            </div>
            <?php endforeach; ?>
            <?php if (!array_sum($bmiMap)): ?>
            <div style="color:var(--text-dim);font-size:.75rem;text-align:center;padding:.75rem 0">No BMI data yet</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Experience + Gender -->
    <div class="card">
        <div class="card-head"><div class="card-title">Experience Level</div></div>
        <div class="dist-list">
            <?php
            $expDefs = ['beginner'=>'#555870','intermediate'=>'#3b6ff5','advanced'=>'#c97c2d','expert'=>'#2da44e'];
            foreach ($expDefs as $lvl => $col):
                $cnt = $expMap[$lvl] ?? 0;
                $pct = $expTotal > 0 ? (int)round($cnt / $expTotal * 100) : 0;
            ?>
            <div class="dist-row">
                <div class="dist-lbl"><?= ucfirst($lvl) ?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?= $pct ?>" style="background:<?= $col ?>; width:0%"></div></div>
                <div class="dist-cnt"><?= $cnt ?> <span>(<?= $pct ?>%)</span></div>
            </div>
            <?php endforeach; ?>

            <hr class="dist-divider">
            <div class="dist-section-title">Gender Split</div>
            <?php
            $gcols = ['male'=>'#3b6ff5','female'=>'#c97c2d','other'=>'#8b8fa8'];
            foreach (['male','female','other'] as $g):
                $cnt = $genderMap[$g] ?? 0;
                $pct = $genderTotal > 0 ? (int)round($cnt / $genderTotal * 100) : 0;
            ?>
            <div class="dist-row">
                <div class="dist-lbl"><?= ucfirst($g) ?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?= $pct ?>" style="background:<?= $gcols[$g] ?>; width:0%"></div></div>
                <div class="dist-cnt"><?= $cnt ?> <span>(<?= $pct ?>%)</span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Target Force bars -->
    <div class="card">
        <div class="card-head"><div class="card-title">Target Force</div></div>
        <div class="dist-list">
            <?php
            $fNames  = ['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police','french'=>'French Legion'];
            $fColors = ['british'=>'#3b6ff5','nepal'=>'#cc3333','indian'=>'#c97c2d','singapore'=>'#8b5cf6','french'=>'#2da44e'];
            foreach ($fNames as $key => $name):
                $cnt = $forceMap[$key] ?? 0;
                $pct = $forceTotal > 0 ? (int)round($cnt / $forceTotal * 100) : 0;
            ?>
            <div class="dist-row">
                <div class="dist-lbl"><?= $name ?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?= $pct ?>" style="background:<?= $fColors[$key] ?? 'var(--accent)' ?>; width:0%"></div></div>
                <div class="dist-cnt"><?= $cnt ?> <span>(<?= $pct ?>%)</span></div>
            </div>
            <?php endforeach; ?>
            <?php if (!array_sum($forceMap)): ?>
            <div style="color:var(--text-dim);font-size:.75rem;text-align:center;padding:.75rem 0">No data yet</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── RECENT ACTIVITY ────────────────────────────────────────────────────────── -->
<div class="dash-section-label">Recent Activity</div>
<div class="grid-2 mb">

    <!-- Recent Users -->
    <div class="card">
        <div class="card-head">
            <div class="card-title">Recent Registrations</div>
            <a href="admin_users.php" class="card-action">View all &rarr;</a>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>User</th>
                    <th>Force</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td>
                        <div class="td-name">
                            <span class="tbl-av"><?= strtoupper(substr($u['full_name'],0,1)) ?></span>
                            <div>
                                <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($u['full_name']) ?></div>
                                <div class="dim"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="tbl-force"><?= htmlspecialchars(ucfirst($u['target_force'] ?? '—')) ?></span></td>
                    <td class="dim"><?= ucfirst($u['experience_level'] ?? '—') ?></td>
                    <td>
                        <span class="tbl-online">
                            <span class="tbl-dot <?= $u['is_active'] ? 'dot-green' : 'dot-red' ?>"></span>
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="mono dim"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentUsers)): ?>
                <tr><td colspan="5" class="empty-row">No users yet</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Subscriptions -->
    <div class="card">
        <div class="card-head">
            <div class="card-title">Recent Subscriptions</div>
            <a href="balance.php" class="card-action">Balance &rarr;</a>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>User</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentSubs as $s): ?>
                <tr>
                    <td>
                        <div class="td-name">
                            <span class="tbl-av gold"><?= strtoupper(substr($s['full_name'],0,1)) ?></span>
                            <div>
                                <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($s['full_name']) ?></div>
                                <div class="dim"><?= htmlspecialchars($s['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="dim"><?= htmlspecialchars($s['plan_name'] ?? '—') ?></td>
                    <td class="mono" style="color:var(--gold);font-weight:600">&#2352;&#2369; <?= number_format((float)$s['amount'], 0) ?></td>
                    <td>
                        <?php
                        $sc = $s['status'] ?? 'pending';
                        $badgeClass = match($sc){
                            'active'    => 'badge-green',
                            'expired'   => 'badge-muted',
                            'pending'   => 'badge-amber',
                            'cancelled' => 'badge-red',
                            default     => 'badge-muted',
                        };
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= ucfirst($sc) ?></span>
                    </td>
                    <td class="mono dim"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentSubs)): ?>
                <tr><td colspan="5" class="empty-row">No subscriptions yet</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── CHARTS ────────────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = '#555870';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'IBM Plex Mono', monospace";
Chart.defaults.font.size   = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(8,9,13,.97)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(59,111,245,.25)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 6;
Chart.defaults.plugins.tooltip.titleColor      = '#8b8fa8';
Chart.defaults.plugins.tooltip.bodyColor       = '#e8e9f0';

// Daily registrations
new Chart(document.getElementById('chartDaily'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [{
            label: 'Registrations',
            data: <?= json_encode($dailyCounts) ?>,
            backgroundColor: 'rgba(59,111,245,0.18)',
            borderColor: '#3b6ff5',
            borderWidth: 1.5, borderRadius: 3,
            hoverBackgroundColor: 'rgba(59,111,245,0.38)',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend:{display:false}, tooltip:{mode:'index'} },
        scales: {
            x: { grid:{color:'rgba(255,255,255,0.03)'}, ticks:{maxTicksLimit:10,maxRotation:40,font:{size:9}} },
            y: { grid:{color:'rgba(255,255,255,0.03)'}, beginAtZero:true, ticks:{precision:0} }
        }
    }
});

// Active / Inactive / Premium doughnut
new Chart(document.getElementById('chartActive'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Inactive','Premium'],
        datasets: [{
            data: [<?= (int)$stats['active_users'] ?>, <?= (int)$stats['inactive_users'] ?>, <?= (int)$stats['premium_users'] ?>],
            backgroundColor: ['rgba(45,164,78,.55)','rgba(204,51,51,.45)','rgba(201,151,58,.55)'],
            borderColor: ['#2da44e','#cc3333','#c9973a'],
            borderWidth: 1.5,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:true, cutout:'68%',
        plugins:{ legend:{display:false} }
    }
});

// Monthly revenue
new Chart(document.getElementById('chartRevenue'), {
    type: 'line',
    data: {
        labels: <?= json_encode($revLabels ?: ['No data']) ?>,
        datasets: [{
            label: 'Revenue (Rs)',
            data: <?= json_encode($revAmounts ?: [0]) ?>,
            borderColor: '#c9973a',
            backgroundColor: 'rgba(201,151,58,0.06)',
            tension: 0.4, pointRadius: 4,
            pointBackgroundColor: '#c9973a',
            fill: true, borderWidth: 1.8,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{
            x:{ grid:{color:'rgba(255,255,255,0.03)'} },
            y:{ grid:{color:'rgba(255,255,255,0.03)'}, beginAtZero:true, ticks:{precision:0} }
        }
    }
});

// Force horizontal bar
new Chart(document.getElementById('chartForce'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($forceChartLabels ?: ['No data']) ?>,
        datasets: [{
            label: 'Users',
            data: <?= json_encode($forceChartCounts ?: [0]) ?>,
            backgroundColor: ['rgba(59,111,245,.45)','rgba(204,51,51,.45)','rgba(201,124,45,.45)','rgba(139,92,246,.45)','rgba(45,164,78,.45)'],
            borderColor:     ['#3b6ff5','#cc3333','#c97c2d','#8b5cf6','#2da44e'],
            borderWidth: 1.5, borderRadius: 3,
        }]
    },
    options: {
        indexAxis: 'y', responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{
            x:{ grid:{color:'rgba(255,255,255,0.03)'}, ticks:{precision:0} },
            y:{ grid:{display:false} }
        }
    }
});

// Animate period bars + dist fills on load
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-w]').forEach(function(el){
        el.style.width = el.dataset.w + '%';
    });
});
</script>

<?php layoutFooter(); ?>
