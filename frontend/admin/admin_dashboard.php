<?php
/**
 * Admin Dashboard — Gurkha Marga
 * User & activity overview only. Revenue → balance.php
 */
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// ── Stats ────────────────────────────────────────────────────────────────────
try {
    $totalUsers    = (int)(fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0);
    $activeUsers   = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=1")['c'] ?? 0);
    $inactiveUsers = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=0")['c'] ?? 0);
    $newToday      = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE DATE(created_at)=CURDATE()")['c'] ?? 0);
    $newWeek       = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")['c'] ?? 0);
    $newMonth      = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")['c'] ?? 0);
    $new3Month     = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)")['c'] ?? 0);
    $new6Month     = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 180 DAY)")['c'] ?? 0);
    $newYear       = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 365 DAY)")['c'] ?? 0);

    try { $workoutCount = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts")['c'] ?? 0); }
    catch(Exception $e){ $workoutCount = 0; }

    try { $premiumUsers = (int)(fetchOne("SELECT COUNT(DISTINCT user_id) as c FROM subscriptions WHERE status='active' AND expires_at>NOW()")['c'] ?? 0); }
    catch(Exception $e){ $premiumUsers = 0; }

    $bmiRow  = fetchOne("SELECT AVG(weight/((height/100)*(height/100))) as v FROM users WHERE height>0 AND weight>0");
    $ageRow  = fetchOne("SELECT AVG(age) as v FROM users WHERE age>0");
    $avgBmi  = $bmiRow ? round((float)($bmiRow['v'] ?? 0), 1) : null;
    $avgAge  = $ageRow ? round((float)($ageRow['v'] ?? 0), 1) : null;

    $forceStats  = fetchAll("SELECT target_force, COUNT(*) as cnt FROM users WHERE target_force!='' GROUP BY target_force ORDER BY cnt DESC") ?: [];
    $expStats    = fetchAll("SELECT experience_level, COUNT(*) as cnt FROM users WHERE experience_level!='' GROUP BY experience_level ORDER BY cnt DESC") ?: [];
    $genderStats = fetchAll("SELECT gender, COUNT(*) as cnt FROM users WHERE gender!='' GROUP BY gender") ?: [];
    $bmiStats    = fetchAll("SELECT CASE WHEN (weight/((height/100)*(height/100)))<18.5 THEN 'Underweight' WHEN (weight/((height/100)*(height/100)))<25 THEN 'Healthy' WHEN (weight/((height/100)*(height/100)))<30 THEN 'Overweight' ELSE 'Obese' END as category, COUNT(*) as cnt FROM users WHERE height>0 AND weight>0 GROUP BY category") ?: [];

    // Daily signups last 30 days
    $dailySignups = fetchAll("SELECT DATE_FORMAT(created_at,'%b %d') as lbl, DATE(created_at) as d, COUNT(*) as cnt FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d ASC") ?: [];

    $recentUsers = fetchAll("SELECT id,full_name,email,target_force,experience_level,created_at,is_active FROM users ORDER BY created_at DESC LIMIT 10") ?: [];

} catch(Exception $e) {
    $totalUsers = $activeUsers = $inactiveUsers = $newToday = $newWeek = $newMonth = $new3Month = $new6Month = $newYear = $workoutCount = $premiumUsers = 0;
    $avgBmi = $avgAge = null;
    $forceStats = $expStats = $genderStats = $bmiStats = $dailySignups = $recentUsers = [];
}

// Maps
$forceMap  = []; foreach($forceStats  as $r) $forceMap[$r['target_force']]   = (int)$r['cnt'];
$expMap    = []; foreach($expStats    as $r) $expMap[$r['experience_level']]  = (int)$r['cnt'];
$genderMap = []; foreach($genderStats as $r) $genderMap[$r['gender']]        = (int)$r['cnt'];
$bmiMap    = []; foreach($bmiStats    as $r) $bmiMap[$r['category']]         = (int)$r['cnt'];

$forceTotal  = max(array_sum($forceMap), 1);
$expTotal    = max(array_sum($expMap), 1);
$genderTotal = max(array_sum($genderMap), 1);
$bmiTotal    = max(array_sum($bmiMap), 1);
$activeRate  = $totalUsers > 0 ? round($activeUsers / $totalUsers * 100, 1) : 0;

// Daily chart arrays (last 30 days, fill gaps)
$drMap = [];
foreach ($dailySignups as $row) $drMap[$row['lbl']] = (int)$row['cnt'];
$dailyLabels = []; $dailyCounts = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('M d', strtotime("-{$i} days"));
    $dailyLabels[] = $d;
    $dailyCounts[] = $drMap[$d] ?? 0;
}

// Growth periods
$periods = [
    ['label'=>'Last 7 Days',   'val'=>$newWeek,   'color'=>'#3b82f6'],
    ['label'=>'Last 30 Days',  'val'=>$newMonth,  'color'=>'#10b981'],
    ['label'=>'Last 3 Months', 'val'=>$new3Month, 'color'=>'#8b5cf6'],
    ['label'=>'Last 6 Months', 'val'=>$new6Month, 'color'=>'#fbbf24'],
    ['label'=>'Last Year',     'val'=>$newYear,   'color'=>'#06b6d4'],
];
$maxPeriod = max(array_merge(array_column($periods,'val'), [1]));
?>

<!-- ── PAGE HEADER ── -->
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:5px;vertical-align:middle;animation:pulse 2s infinite"></span>
            Live snapshot &mdash; <?= date('d M Y, H:i:s') ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="balance.php" class="btn btn-gold">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Balance & Revenue
        </a>
        <a href="admin_staff.php?action=create" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create Staff
        </a>
    </div>
</div>

<style>
@keyframes pulse {
    0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.6)}
    50%{box-shadow:0 0 0 6px rgba(16,185,129,0)}
}
.period-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:.85rem;margin-bottom:1.25rem}
.period-card{
    background:var(--surface);border:1px solid var(--border);border-radius:var(--r);
    padding:1rem 1.1rem;backdrop-filter:blur(12px);
    transition:border-color .2s;animation:fadeUp .35s ease both;
}
.period-card:hover{border-color:var(--border-hi)}
.p-label{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);margin-bottom:.3rem}
.p-val  {font-family:var(--mono);font-size:1.55rem;font-weight:500;color:var(--text);letter-spacing:-.03em;line-height:1}
.p-sub  {font-size:.63rem;color:var(--text-2);margin-top:.2rem}
.period-bar {height:3px;background:rgba(255,255,255,.05);border-radius:2px;margin-top:.75rem;overflow:hidden}
.period-fill{height:100%;border-radius:2px;transition:width 1.2s cubic-bezier(.4,0,.2,1) .3s;width:0%}
@media(max-width:1100px){.period-strip{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px) {.period-strip{grid-template-columns:repeat(2,1fr)}}
</style>

<!-- ── KPI STRIP ── -->
<div class="section-label">Platform Overview</div>
<div class="stats-grid mb">
    <div class="stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-val"><?= number_format($totalUsers) ?></div>
        <div class="stat-sub">All-time registrations</div>
        <span class="stat-chip up">+<?= $newMonth ?> this month</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Users</div>
        <div class="stat-val" style="color:#6ee7b7"><?= number_format($activeUsers) ?></div>
        <div class="stat-sub"><?= $activeRate ?>% of total</div>
        <span class="stat-chip <?= $activeRate >= 70 ? 'up' : 'warn' ?>"><?= $activeRate ?>% rate</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Premium Members</div>
        <div class="stat-val" style="color:var(--gold)"><?= number_format($premiumUsers) ?></div>
        <div class="stat-sub">Active subscriptions</div>
        <span class="stat-chip neutral"><?= $totalUsers > 0 ? round($premiumUsers/$totalUsers*100,1) : 0 ?>% conversion</span>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Workouts</div>
        <div class="stat-val" style="color:#93c5fd"><?= number_format($workoutCount) ?></div>
        <div class="stat-sub">Logged sessions</div>
        <span class="stat-chip neutral">+<?= $newToday ?> users today</span>
    </div>
</div>

<div class="stats-grid cols-3 mb">
    <div class="stat-card">
        <div class="stat-label">Avg. BMI</div>
        <div class="stat-val"><?= $avgBmi ?? '—' ?></div>
        <div class="stat-sub">Users with body data</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg. Age</div>
        <div class="stat-val"><?= $avgAge ?? '—' ?></div>
        <div class="stat-sub">Years, across all users</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Inactive Users</div>
        <div class="stat-val" style="color:#fca5a5"><?= number_format($inactiveUsers) ?></div>
        <div class="stat-sub">Disabled accounts</div>
        <span class="stat-chip neutral">+<?= $newToday ?> today</span>
    </div>
</div>

<!-- ── GROWTH BY PERIOD ── -->
<div class="section-label">User Growth by Period</div>
<div class="period-strip">
    <?php foreach ($periods as $i => $p):
        $pct = $maxPeriod > 0 ? (int)round($p['val'] / $maxPeriod * 100) : 0;
    ?>
    <div class="period-card" style="animation-delay:<?= $i * .06 ?>s">
        <div class="p-label"><?= $p['label'] ?></div>
        <div class="p-val"><?= number_format($p['val']) ?></div>
        <div class="p-sub">registrations</div>
        <div class="period-bar">
            <div class="period-fill" data-w="<?= $pct ?>" style="background:<?= $p['color'] ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── DAILY CHART + ACTIVE DONUT ── -->
<div class="section-label">Registration Activity</div>
<div class="grid-dash mb">

    <div class="card">
        <div class="card-head">
            <div class="card-title">Daily Registrations — Last 30 Days</div>
            <span class="stat-chip up" style="margin:0">+<?= $newMonth ?> total</span>
        </div>
        <div class="chart-area tall">
            <canvas id="chartDaily"></canvas>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:.85rem">
        <!-- Active vs Inactive donut -->
        <div class="card" style="flex:1">
            <div class="card-head"><div class="card-title">Account Status</div></div>
            <div style="display:flex;flex-direction:column;align-items:center;padding:1rem;gap:.65rem">
                <canvas id="chartActive" style="max-width:140px;max-height:140px"></canvas>
                <div style="display:flex;flex-direction:column;gap:.35rem;width:100%">
                    <?php foreach([
                        ['Active',  $activeUsers,   '#6ee7b7','rgba(16,185,129,.15)'],
                        ['Inactive',$inactiveUsers, '#fca5a5','rgba(239,68,68,.15)'],
                        ['Premium', $premiumUsers,  '#fcd34d','rgba(251,191,36,.15)'],
                    ] as [$lbl,$cnt,$col,$bg]): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem .6rem;border-radius:7px;background:<?= $bg ?>;font-size:.75rem">
                        <span style="display:flex;align-items:center;gap:6px;color:var(--text-2)">
                            <span style="width:7px;height:7px;border-radius:50%;background:<?= $col ?>;display:inline-block"></span>
                            <?= $lbl ?>
                        </span>
                        <span style="font-family:var(--mono);font-weight:700;color:<?= $col ?>"><?= number_format($cnt) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Quick links to balance -->
        <div class="card">
            <div class="card-head"><div class="card-title">Finance</div></div>
            <div style="padding:1rem">
                <a href="balance.php" class="btn btn-gold" style="width:100%;justify-content:center;margin-bottom:.5rem">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    View Balance & Revenue
                </a>
                <p style="font-size:.68rem;color:var(--text-3);text-align:center">
                    eSewa payments, subscriptions & revenue analytics
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ── DISTRIBUTIONS ── -->
<div class="section-label">User Breakdown</div>
<div class="grid-3 mb">

    <!-- BMI -->
    <div class="card">
        <div class="card-head"><div class="card-title">BMI Distribution</div></div>
        <div class="dist-list">
            <?php foreach(['Healthy'=>['#6ee7b7','18.5–25'],'Underweight'=>['#93c5fd','<18.5'],'Overweight'=>['#fcd34d','25–30'],'Obese'=>['#fca5a5','>30']] as $cat=>[$col,$hint]):
                $cnt=$bmiMap[$cat]??0; $pct=$bmiTotal>0?(int)round($cnt/$bmiTotal*100):0; ?>
            <div class="dist-row">
                <div class="dist-lbl" title="<?=$hint?>"><?=$cat?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?=$pct?>" style="background:<?=$col?>"></div></div>
                <div class="dist-cnt"><?=$cnt?> <span style="color:var(--text-3)">(<?=$pct?>%)</span></div>
            </div>
            <?php endforeach; ?>
            <?php if(!array_sum($bmiMap)): ?><div style="color:var(--text-3);font-size:.73rem;text-align:center;padding:.75rem 0">No BMI data yet</div><?php endif; ?>
        </div>
    </div>

    <!-- Experience + Gender -->
    <div class="card">
        <div class="card-head"><div class="card-title">Experience Level</div></div>
        <div class="dist-list">
            <?php foreach(['beginner'=>'#64748b','intermediate'=>'#3b82f6','advanced'=>'#f59e0b','expert'=>'#10b981'] as $lvl=>$col):
                $cnt=$expMap[$lvl]??0; $pct=$expTotal>0?(int)round($cnt/$expTotal*100):0; ?>
            <div class="dist-row">
                <div class="dist-lbl"><?=ucfirst($lvl)?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?=$pct?>" style="background:<?=$col?>"></div></div>
                <div class="dist-cnt"><?=$cnt?> <span style="color:var(--text-3)">(<?=$pct?>%)</span></div>
            </div>
            <?php endforeach; ?>
            <div style="border-top:1px solid var(--border);margin:.75rem 0"></div>
            <div style="font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);margin-bottom:.5rem">Gender Split</div>
            <?php foreach(['male'=>'#3b82f6','female'=>'#f59e0b','other'=>'#64748b'] as $g=>$col):
                $cnt=$genderMap[$g]??0; $pct=$genderTotal>0?(int)round($cnt/$genderTotal*100):0; ?>
            <div class="dist-row">
                <div class="dist-lbl"><?=ucfirst($g)?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?=$pct?>" style="background:<?=$col?>"></div></div>
                <div class="dist-cnt"><?=$cnt?> <span style="color:var(--text-3)">(<?=$pct?>%)</span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Target Force -->
    <div class="card">
        <div class="card-head"><div class="card-title">Target Force</div></div>
        <div class="dist-list">
            <?php
            $fNames  = ['british'=>'🇬🇧 British Army','nepal'=>'🇳🇵 Nepal Army','indian'=>'🇮🇳 Indian Army','singapore'=>'🇸🇬 Singapore Police','french'=>'🇫🇷 French Legion'];
            $fColors = ['british'=>'#3b82f6','nepal'=>'#ef4444','indian'=>'#f59e0b','singapore'=>'#8b5cf6','french'=>'#10b981'];
            foreach($fNames as $key=>$name):
                $cnt=$forceMap[$key]??0; $pct=$forceTotal>0?(int)round($cnt/$forceTotal*100):0; ?>
            <div class="dist-row">
                <div class="dist-lbl"><?=$name?></div>
                <div class="dist-track"><div class="dist-fill" data-w="<?=$pct?>" style="background:<?=$fColors[$key]?>"></div></div>
                <div class="dist-cnt"><?=$cnt?> <span style="color:var(--text-3)">(<?=$pct?>%)</span></div>
            </div>
            <?php endforeach; ?>
            <?php if(!array_sum($forceMap)): ?><div style="color:var(--text-3);font-size:.73rem;text-align:center;padding:.75rem 0">No data yet</div><?php endif; ?>
        </div>
    </div>
</div>

<!-- ── RECENT USERS ── -->
<div class="section-label">Recent Registrations</div>
<div class="card mb">
    <div class="card-head">
        <div class="card-title">Latest Sign-ups</div>
        <a href="admin_users.php" class="card-action">View all users →</a>
    </div>
    <div class="tbl-wrap">
        <table class="data-table">
            <thead><tr>
                <th>User</th><th>Force</th><th>Level</th><th>Status</th><th>Joined</th>
            </tr></thead>
            <tbody>
            <?php foreach($recentUsers as $u): ?>
            <tr>
                <td>
                    <div class="td-name">
                        <div class="tbl-av"><?=strtoupper(substr($u['full_name'],0,1))?></div>
                        <div>
                            <div style="font-weight:600;font-size:.82rem"><?=htmlspecialchars($u['full_name'])?></div>
                            <div style="font-size:.7rem;color:var(--text-3)"><?=htmlspecialchars($u['email'])?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="badge badge-blue" style="font-size:.62rem">
                        <?=htmlspecialchars(ucfirst($u['target_force']??'—'))?>
                    </span>
                </td>
                <td class="dim"><?=ucfirst($u['experience_level']??'—')?></td>
                <td>
                    <span class="badge <?=$u['is_active']?'badge-green':'badge-red'?>">
                        <?=$u['is_active']?'Active':'Inactive'?>
                    </span>
                </td>
                <td class="mono dim" style="font-size:.72rem;white-space:nowrap"><?=date('d M Y',strtotime($u['created_at']))?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($recentUsers)): ?>
            <tr><td colspan="5" class="empty-row">No users yet</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color      = '#64748b';
Chart.defaults.borderColor= 'rgba(255,255,255,0.05)';
Chart.defaults.font.family= "'Poppins', sans-serif";
Chart.defaults.font.size  = 10;
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,0.95)';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(59,130,246,0.25)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 8;
Chart.defaults.plugins.tooltip.titleColor      = '#cbd5e1';
Chart.defaults.plugins.tooltip.bodyColor       = '#f8fafc';

new Chart(document.getElementById('chartDaily'), {
    type: 'bar',
    data: {
        labels: <?=json_encode($dailyLabels)?>,
        datasets: [{
            label: 'Registrations',
            data: <?=json_encode($dailyCounts)?>,
            backgroundColor: 'rgba(59,130,246,0.2)',
            borderColor: '#3b82f6',
            borderWidth: 1.5, borderRadius: 4,
            hoverBackgroundColor: 'rgba(59,130,246,0.4)',
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{mode:'index'}},
        scales:{
            x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{maxTicksLimit:10,maxRotation:40,font:{size:9}}},
            y:{grid:{color:'rgba(255,255,255,.04)'},beginAtZero:true,ticks:{precision:0}}
        }
    }
});

new Chart(document.getElementById('chartActive'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Inactive','Premium'],
        datasets: [{
            data: [<?=(int)$activeUsers?>,<?=(int)$inactiveUsers?>,<?=(int)$premiumUsers?>],
            backgroundColor: ['rgba(16,185,129,.45)','rgba(239,68,68,.4)','rgba(251,191,36,.45)'],
            borderColor: ['#10b981','#ef4444','#fbbf24'],
            borderWidth: 1.5,
        }]
    },
    options: {responsive:true,maintainAspectRatio:true,cutout:'68%',plugins:{legend:{display:false}}}
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-w]').forEach(el => {
        el.style.width = el.dataset.w + '%';
    });
});
</script>

<?php layoutFooter(); ?>