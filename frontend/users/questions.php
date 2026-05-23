<?php
// frontend/users/questions.php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}

$userId  = (int)$_SESSION['user_id'];
$user    = fetchOne("SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$sub       = getActiveSubscription($userId);
$isPremium = (bool)$sub;
$daysLeft  = $isPremium ? daysRemaining($userId) : 0;

$forceKey  = strtolower($user['target_force'] ?? 'all');
$firstName = explode(' ', trim($user['full_name']))[0];
$initials  = strtoupper(substr($user['full_name'], 0, 1));

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$forceMap = [
    'british'   => ['flag' => 'GB', 'name' => 'British Army'],
    'nepal'     => ['flag' => 'NP', 'name' => 'Nepal Army'],
    'indian'    => ['flag' => 'IN', 'name' => 'Indian Army'],
    'singapore' => ['flag' => 'SG', 'name' => 'Singapore Police Force'],
    'french'    => ['flag' => 'FR', 'name' => 'French Foreign Legion'],
];
$forceFlag = $forceMap[$forceKey]['flag'] ?? strtoupper($forceKey);
$forceName = $forceMap[$forceKey]['name'] ?? ucfirst($forceKey);

$totalQs = (int)(fetchOne(
    "SELECT COUNT(*) as c FROM questions
     WHERE is_active=1 AND (target_force=? OR target_force='all')",
    [$forceKey]
)['c'] ?? 0);

$catCounts = [];
$CATEGORIES = ['math', 'english', 'general', 'past', 'physical'];
foreach ($CATEGORIES as $cat) {
    $catCounts[$cat] = (int)(fetchOne(
        "SELECT COUNT(*) as c FROM questions
         WHERE is_active=1 AND category=? AND (target_force=? OR target_force='all')",
        [$cat, $forceKey]
    )['c'] ?? 0);
}

$plans = fetchAll("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY price");

$previewQs = [];
if (!$isPremium) {
    $previewQs = fetchAll(
        "SELECT question_text FROM questions
         WHERE is_active=1 AND (target_force=? OR target_force='all')
         ORDER BY category, sort_order, id LIMIT 4",
        [$forceKey]
    ) ?: [];
}

$subPct = 0;
if ($isPremium && !empty($sub['duration_days']) && $sub['duration_days'] > 0) {
    $subPct = min(100, (int)round($daysLeft / $sub['duration_days'] * 100));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Questions — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:  #3b82f6;
    --gold:    #fbbf24;
    --success: #10b981;
    --error:   #ef4444;
    --t1:      #f8fafc;
    --t2:      #cbd5e1;
    --t3:      #64748b;
    --bg:      #0f172a;
    --surface: rgba(30, 41, 59, 0.8);
    --surface-solid: #1e293b;
    --hover:   rgba(59, 130, 246, 0.08);
    --border:  rgba(255, 255, 255, 0.07);
    --border-hi: rgba(255, 255, 255, 0.12);
}

html { scroll-behavior: smooth; }
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--t1);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ─── SIDEBAR ─── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: rgba(15, 23, 42, 0.97);
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
.user-card {
    background: rgba(59, 130, 246, 0.07);
    border: 1px solid rgba(59, 130, 246, 0.18);
    border-radius: 12px; padding: .85rem;
    display: flex; align-items: center; gap: 10px;
}
.sidebar-av {
    width: 38px; height: 38px; border-radius: 50%; overflow: hidden;
    flex-shrink: 0; border: 1.5px solid rgba(59, 130, 246, 0.35);
    display: flex; align-items: center; justify-content: center;
}
.sidebar-av img { width: 100%; height: 100%; object-fit: cover; }
.user-details h3 { font-size: .85rem; font-weight: 600; }
.user-details p  { font-size: .7rem; color: var(--gold); margin-top: 1px; }
.nav-menu { padding: 1.25rem 0; }
.nav-section-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    color: rgba(203,213,225,.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--t2); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover); color: var(--t1); border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239,68,68,.08); border-left-color: var(--error); }
.nav-badge {
    margin-left: auto; font-size: .63rem; padding: .15rem .42rem; border-radius: 99px;
    background: rgba(59,130,246,.2); color: #6ab4ff; font-weight: 600;
}
.sidebar-sub {
    margin: 1rem 1.25rem 0; padding: .85rem; border-radius: 10px;
    background: rgba(251,191,36,.05); border: 1px solid rgba(251,191,36,.15);
}
.sub-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; }
.sub-label { font-size: .67rem; color: var(--t3); }
.sub-val { font-size: .78rem; font-weight: 600; color: var(--gold); }
.sub-bar { height: 3px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; margin-top:.5rem; }
.sub-fill { height: 100%; background: linear-gradient(90deg, var(--gold), #f59e0b); border-radius: 2px; }

/* ─── LAYOUT ─── */
.main { margin-left: 260px; min-height: 100vh; }

/* ─── TOPBAR ─── */
.topbar {
    background: var(--surface); backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    padding: .9rem 1.75rem;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 50;
}
.bc { font-size: .75rem; color: var(--t3); display: flex; align-items: center; gap: 5px; }
.bc a { color: var(--t2); text-decoration: none; }
.bc a:hover { color: var(--accent); }
.tb-right { display: flex; align-items: center; gap: .65rem; }
.force-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: .3rem .75rem; border-radius: 20px; font-size: .73rem; font-weight: 600;
    background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2); color: var(--gold);
}
.btn-ghost {
    padding: .45rem .9rem; border-radius: 8px; font-family: 'Poppins', sans-serif;
    font-size: .78rem; font-weight: 600; border: 1px solid var(--border);
    background: transparent; color: var(--t2); cursor: pointer; transition: all .18s;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.btn-ghost:hover { background: var(--surface-solid); color: var(--t1); }
.btn-gold { background: linear-gradient(135deg, var(--gold), #f59e0b); border-color: transparent; color: #0a0800; font-weight: 700; }
.btn-gold:hover { opacity:.9; transform:translateY(-1px); }

/* ─── PAGE ─── */
.page { padding: 1.75rem; max-width: 860px; }

/* ─── SECTION LABEL ─── */
.sec-label {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
    color: var(--t3); margin-bottom: .75rem;
    display: flex; align-items: center; gap: .5rem;
}
.sec-label::after { content: ''; flex: 0 0 24px; height: 1px; background: var(--border); }

/* ─── CATEGORY STATS STRIP ─── */
.cat-strip {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: .5rem; margin-bottom: 1.25rem;
}
.cat-pill {
    background: var(--surface); border: 1px solid var(--border); border-radius: 9px;
    padding: .65rem .6rem; text-align: center; cursor: pointer; transition: all .18s;
}
.cat-pill:hover   { border-color: var(--border-hi); }
.cat-pill.active  { border-color: rgba(59,130,246,.4); background: rgba(59,130,246,.08); }
.cat-pill-name    { font-size: .67rem; font-weight: 700; color: var(--t2); text-transform: uppercase; letter-spacing: .06em; }
.cat-pill-count   { font-size: 1.1rem; font-weight: 800; color: var(--t1); margin: .2rem 0 .1rem; }
.cat-pill-sub     { font-size: .62rem; color: var(--t3); }

/* ─── VIEW TABS ─── */
.view-tabs {
    display: flex; gap: .4rem; margin-bottom: 1.25rem;
    background: rgba(15,23,42,.5); border: 1px solid var(--border);
    border-radius: 10px; padding: .3rem;
}
.view-tab {
    flex: 1; padding: .55rem .75rem; border-radius: 7px; font-family: 'Poppins',sans-serif;
    font-size: .78rem; font-weight: 600; border: none; background: transparent;
    color: var(--t3); cursor: pointer; transition: all .2s;
}
.view-tab:hover { color: var(--t2); }
.view-tab.active { background: var(--surface-solid); color: var(--t1); box-shadow: 0 2px 8px rgba(0,0,0,.3); }

/* ─── LOADING / EMPTY STATES ─── */
.state-box {
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    padding: 2.5rem; text-align: center; color: var(--t3); font-size: .82rem; line-height: 1.65;
}
.state-box .state-title { font-size: .95rem; font-weight: 600; color: var(--t2); margin-bottom: .4rem; }
.spinner {
    width: 24px; height: 24px; border: 2px solid rgba(59,130,246,.2);
    border-top-color: var(--accent); border-radius: 50%;
    animation: spin .7s linear infinite; margin: 0 auto .75rem;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── QUIZ ARENA ─── */
.quiz-arena {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden; margin-bottom: 1.25rem;
}
.quiz-topbar {
    padding: .85rem 1.4rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.cat-tag {
    font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    padding: .25rem .65rem; border-radius: 5px;
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.25); color: #93c5fd;
}
.quiz-prog-wrap { flex: 1; display: flex; align-items: center; gap: .75rem; min-width: 120px; }
.q-count { font-size: .72rem; color: var(--t3); white-space: nowrap; }
.q-prog-bar { flex: 1; height: 4px; background: rgba(255,255,255,.07); border-radius: 2px; overflow: hidden; }
.q-prog-fill { height: 100%; background: linear-gradient(90deg, var(--accent), #818cf8); border-radius: 2px; transition: width .5s ease; }
.live-score { display: flex; gap: .85rem; font-size: .75rem; }
.live-score span { color: var(--t3); }
.live-score b { color: var(--t1); }
.live-score .sc-ok  { color: #6ee7b7; }
.live-score .sc-bad { color: #fca5a5; }

/* ─── QUESTION BODY ─── */
.quiz-body { padding: 1.5rem 1.4rem; }
.q-label {
    font-size: .67rem; font-weight: 700; color: var(--t3); letter-spacing: .08em;
    text-transform: uppercase; margin-bottom: .75rem;
    display: flex; align-items: center; gap: .5rem;
}
.q-num-box {
    width: 22px; height: 22px; border-radius: 5px;
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 800; color: #93c5fd;
}
.q-text { font-size: .98rem; font-weight: 600; line-height: 1.6; color: var(--t1); margin-bottom: 1.25rem; }

/* file attachment */
.q-file-link {
    display: inline-flex; align-items: center; gap: 5px;
    padding: .3rem .75rem; border-radius: 6px; font-size: .72rem; font-weight: 600;
    background: rgba(139,92,246,.1); border: 1px solid rgba(139,92,246,.25); color: #c4b5fd;
    text-decoration: none; margin-bottom: 1rem;
}
.q-file-link:hover { background: rgba(139,92,246,.18); }

/* ─── OPTIONS ─── */
.options { display: flex; flex-direction: column; gap: .5rem; }
.opt {
    display: flex; align-items: center; gap: .9rem;
    padding: .8rem 1.1rem; border-radius: 10px;
    border: 1px solid var(--border); background: rgba(15,23,42,.5);
    color: var(--t2); cursor: pointer; transition: all .18s;
    font-family: 'Poppins',sans-serif; font-size: .85rem; font-weight: 500;
    text-align: left; width: 100%;
}
.opt:hover:not(:disabled) { border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.08); color: var(--t1); transform: translateX(3px); }
.opt:disabled { cursor: default; }
.opt.correct { border-color: rgba(16,185,129,.5); background: rgba(16,185,129,.1); color: #6ee7b7; transform: none; }
.opt.wrong   { border-color: rgba(239,68,68,.5);  background: rgba(239,68,68,.1);  color: #fca5a5; transform: none; }
.opt-letter {
    width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 800; color: var(--t3); transition: all .18s;
}
.opt:hover:not(:disabled) .opt-letter { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); color: #93c5fd; }
.opt.correct .opt-letter { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.5); color: #6ee7b7; }
.opt.wrong   .opt-letter { background: rgba(239,68,68,.2);  border-color: rgba(239,68,68,.5);  color: #fca5a5; }
.opt-icon { margin-left: auto; font-size: .85rem; flex-shrink: 0; }

/* ─── FEEDBACK ─── */
.feedback {
    margin-top: .9rem; padding: .75rem 1rem; border-radius: 9px;
    font-size: .8rem; line-height: 1.65; display: none;
}
.feedback.show { display: block; animation: fadeUp .2s ease; }
.feedback.fb-ok  { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2); color: #a7f3d0; }
.feedback.fb-err { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.2);  color: #fecaca; }
.fb-label { font-weight: 700; margin-bottom: .2rem; font-size: .82rem; }

/* ─── QUIZ FOOTER ─── */
.quiz-footer {
    padding: .85rem 1.4rem; border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.quiz-hint { font-size: .72rem; color: var(--t3); }
.btn-next {
    padding: .52rem 1.4rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .82rem; font-weight: 700; border: none;
    background: linear-gradient(135deg, var(--accent), #6366f1);
    color: #fff; cursor: pointer; transition: all .2s;
    display: flex; align-items: center; gap: 6px;
}
.btn-next:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59,130,246,.35); }
.btn-next:disabled { opacity: .35; cursor: default; transform: none; }

/* ─── QUIZ END ─── */
.quiz-end { display: none; text-align: center; padding: 2.5rem 2rem; }
.quiz-end.show { display: block; animation: fadeUp .35s ease; }
.end-score {
    display: inline-flex; align-items: center; justify-content: center;
    width: 84px; height: 84px; border-radius: 50%;
    border: 3px solid rgba(251,191,36,.35); font-size: 1.5rem; font-weight: 800; color: var(--gold);
    margin-bottom: 1rem; background: radial-gradient(circle, rgba(251,191,36,.07), transparent);
}
.end-grade  { font-size: 1.15rem; font-weight: 700; margin-bottom: .35rem; }
.end-sub    { font-size: .82rem; color: var(--t3); line-height: 1.65; margin-bottom: 1.5rem; }
.end-actions { display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; }
.btn-retry {
    padding: .55rem 1.4rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .8rem; font-weight: 700;
    border: 1px solid rgba(251,191,36,.35); background: rgba(251,191,36,.08);
    color: var(--gold); cursor: pointer; transition: all .18s;
}
.btn-retry:hover { background: rgba(251,191,36,.16); }
.btn-other {
    padding: .55rem 1.4rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .8rem; font-weight: 700;
    border: 1px solid rgba(59,130,246,.35); background: rgba(59,130,246,.08);
    color: #93c5fd; cursor: pointer; transition: all .18s;
}
.btn-other:hover { background: rgba(59,130,246,.16); }

/* ─── ANALYTICS ─── */
.analytics {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 1.4rem; margin-bottom: 1.25rem;
}
.an-title { font-size: .85rem; font-weight: 700; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; }
.an-title span { font-size: .7rem; color: var(--t3); font-weight: 400; }
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .6rem; margin-bottom: 1.1rem; }
.stat-box {
    background: rgba(15,23,42,.55); border: 1px solid var(--border);
    border-radius: 10px; padding: .85rem .7rem; text-align: center;
}
.stat-val { font-size: 1.5rem; font-weight: 800; line-height: 1; }
.stat-lbl { font-size: .62rem; color: var(--t3); margin-top: .3rem; text-transform: uppercase; letter-spacing: .06em; }
.stat-box.s-ok  .stat-val { color: var(--success); }
.stat-box.s-err .stat-val { color: var(--error);   }
.stat-box.s-pct .stat-val { color: var(--gold);    }
.stat-box.s-str .stat-val { color: #a78bfa;        }
.cat-rows-title { font-size: .67rem; font-weight: 600; color: var(--t3); text-transform: uppercase; letter-spacing:.08em; margin-bottom: .65rem; }
.cat-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .55rem; }
.cat-row-name { font-size: .75rem; color: var(--t2); width: 110px; flex-shrink: 0; text-transform: capitalize; }
.cat-bar { flex: 1; height: 6px; background: rgba(255,255,255,.06); border-radius: 3px; overflow: hidden; }
.cat-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, var(--accent), #818cf8); transition: width .6s cubic-bezier(.4,0,.2,1); }
.cat-row-stat { font-size: .7rem; color: var(--t3); width: 58px; text-align: right; white-space: nowrap; }
.empty-an { font-size: .77rem; color: var(--t3); text-align: center; padding: .75rem 0; }

/* ─── PAST PAPERS VIEW ─── */
.past-section { margin-bottom: 1.75rem; }
.past-section-title {
    font-size: .62rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
    color: var(--t3); margin-bottom: .65rem;
    display: flex; align-items: center; gap: .5rem;
}
.past-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Past Paper card */
.pp-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; margin-bottom: .6rem;
    transition: border-color .18s;
}
.pp-card:hover { border-color: var(--border-hi); }
.pp-card-header {
    display: flex; align-items: center; gap: .9rem;
    padding: .9rem 1.1rem; cursor: pointer; user-select: none;
}
.pp-card-header:hover { background: rgba(255,255,255,.015); }
.pp-num {
    width: 26px; height: 26px; border-radius: 6px; flex-shrink: 0;
    background: rgba(59,130,246,.1); display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #93c5fd;
}
.pp-title { flex: 1; font-size: .87rem; font-weight: 500; line-height: 1.4; color: var(--t1); }
.pp-badges { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
.pp-type-badge {
    font-size: .58rem; font-weight: 700; padding: .15rem .45rem; border-radius: 4px;
    text-transform: uppercase; letter-spacing: .04em;
}
.pp-type-pdf  { background: rgba(239,68,68,.1);   border: 1px solid rgba(239,68,68,.25);   color: #fca5a5; }
.pp-type-doc  { background: rgba(59,130,246,.1);  border: 1px solid rgba(59,130,246,.25);  color: #93c5fd; }
.pp-type-img  { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2);   color: #6ee7b7; }
.pp-type-vid  { background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2);   color: var(--gold); }
.pp-type-text { background: rgba(139,92,246,.08); border: 1px solid rgba(139,92,246,.2);   color: #c4b5fd; }
.pp-chevron {
    width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
    font-size: .55rem; color: var(--t3); transition: transform .22s, color .18s; flex-shrink: 0;
}
.pp-card.open .pp-chevron { transform: rotate(180deg); color: var(--accent); }
.pp-card-body {
    display: none; padding: 1rem 1.1rem 1.1rem;
    border-top: 1px solid var(--border); background: rgba(15,23,42,.4);
}
.pp-card.open .pp-card-body { display: block; }

/* PDF Embed */
.pdf-embed-wrap {
    border-radius: 8px; overflow: hidden; border: 1px solid var(--border);
    margin-bottom: .75rem; background: #1a1a2e;
}
.pdf-embed-wrap iframe {
    width: 100%; height: 520px; border: none; display: block;
}
.pdf-fallback {
    padding: 1.5rem; text-align: center;
    font-size: .8rem; color: var(--t3); line-height: 1.65;
}
.pdf-fallback .pdf-icon { font-size: 2rem; margin-bottom: .5rem; }
.pdf-fallback a {
    color: var(--accent); text-decoration: none; font-weight: 600;
}
.pdf-fallback a:hover { text-decoration: underline; }

/* Image preview */
.img-preview {
    max-width: 100%; border-radius: 8px; border: 1px solid var(--border);
    margin-bottom: .75rem; display: block;
}

/* Answer/Explanation text */
.pp-answer-text {
    font-size: .82rem; line-height: 1.7; color: var(--t2); margin-bottom: .75rem;
}
.pp-answer-text p { margin-bottom: .5rem; }

/* Download / Open button */
.pp-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.pp-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: .38rem .85rem; border-radius: 7px; font-family: 'Poppins',sans-serif;
    font-size: .72rem; font-weight: 600; text-decoration: none; transition: all .18s;
    border: 1px solid rgba(59,130,246,.3); background: rgba(59,130,246,.08); color: #93c5fd;
}
.pp-btn:hover { background: rgba(59,130,246,.16); }
.pp-btn.dl { border-color: rgba(16,185,129,.3); background: rgba(16,185,129,.08); color: #6ee7b7; }
.pp-btn.dl:hover { background: rgba(16,185,129,.16); }

/* MCQ inside past papers */
.mcq-opts-list { display: flex; flex-direction: column; gap: .3rem; margin: .5rem 0 .75rem; }
.mcq-opt-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .5rem .75rem; border-radius: 7px;
    font-size: .8rem; color: var(--t2);
    background: rgba(15,23,42,.5); border: 1px solid var(--border);
}
.mcq-opt-item.is-correct { border-color: rgba(16,185,129,.35); color: #6ee7b7; background: rgba(16,185,129,.06); }
.mcq-opt-letter {
    width: 22px; height: 22px; border-radius: 5px; flex-shrink: 0; font-size: .62rem; font-weight: 800;
    background: rgba(255,255,255,.04); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center; color: var(--t3);
}
.mcq-opt-item.is-correct .mcq-opt-letter { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.4); color: #6ee7b7; }

/* ─── Q&A ACCORDION (legacy, now used as sub-section inside past papers) ─── */
.q-list { display: flex; flex-direction: column; gap: .45rem; margin-bottom: 1.25rem; }
.q-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 11px; overflow: hidden; transition: border-color .18s;
}
.q-card:hover { border-color: var(--border-hi); }
.q-card.open  { border-color: rgba(59,130,246,.25); }
.q-header {
    display: flex; align-items: center; gap: .85rem;
    padding: .85rem 1.1rem; cursor: pointer; user-select: none;
}
.q-header:hover { background: rgba(255,255,255,.02); }
.q-num {
    width: 26px; height: 26px; border-radius: 6px; flex-shrink: 0;
    background: rgba(59,130,246,.1); display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #93c5fd;
}
.q-text2  { flex: 1; font-size: .87rem; font-weight: 500; line-height: 1.4; }
.q-chevron {
    width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
    font-size: .55rem; color: var(--t3); transition: transform .22s, color .18s; flex-shrink: 0;
}
.q-card.open .q-chevron { transform: rotate(180deg); color: var(--accent); }
.q-body2 {
    display: none; padding: .8rem 1.1rem 1rem;
    font-size: .82rem; line-height: 1.7; color: var(--t2);
    border-top: 1px solid var(--border); background: rgba(15,23,42,.4);
}
.q-card.open .q-body2 { display: block; }
.cat-group-label {
    font-size: .62rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
    color: var(--t3); margin: 1rem 0 .45rem;
    display: flex; align-items: center; gap: .5rem;
}
.cat-group-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.type-badge {
    font-size: .58rem; font-weight: 700; padding: .12rem .4rem; border-radius: 4px;
    text-transform: uppercase; letter-spacing: .04em;
    background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.2); color: #93c5fd;
}
.type-badge.tb-text { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.2); color: #6ee7b7; }

/* ─── PAYWALL ─── */
.paywall {
    background: var(--surface); border: 1px solid rgba(251,191,36,.2);
    border-radius: 14px; padding: 2.25rem 1.75rem; text-align: center; margin-bottom: 1.5rem;
}
.paywall-title { font-size: 1.05rem; font-weight: 700; color: var(--gold); margin-bottom: .5rem; }
.paywall-desc  { font-size: .82rem; color: var(--t3); line-height: 1.65; max-width: 420px; margin: 0 auto 1.5rem; }
.plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .65rem; max-width: 480px; margin: 0 auto 1.25rem; }
.plan-card {
    background: rgba(15,23,42,.6); border: 1px solid var(--border); border-radius: 10px;
    padding: 1rem .85rem; text-align: center; text-decoration: none; display: block;
    transition: all .18s; position: relative;
}
.plan-card:hover { border-color: rgba(251,191,36,.4); transform: translateY(-2px); }
.plan-card.featured { border-color: rgba(251,191,36,.4); background: rgba(251,191,36,.04); }
.plan-best {
    position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
    background: linear-gradient(135deg, var(--gold), #f59e0b); color: #0a0800;
    font-size: .6rem; font-weight: 700; padding: .18rem .6rem; border-radius: 99px; white-space: nowrap;
}
.plan-price { font-size: 1.25rem; font-weight: 700; color: var(--gold); line-height: 1; margin-bottom: .2rem; }
.plan-price small { font-size: .68rem; color: var(--t3); font-weight: 400; }
.plan-name     { font-size: .76rem; font-weight: 600; margin-bottom: .12rem; }
.plan-duration { font-size: .68rem; color: var(--t3); }
.paywall-note { font-size: .7rem; color: var(--t3); margin-top: .9rem; line-height: 1.6; }
.q-preview { background: var(--surface); border: 1px solid var(--border); border-radius: 11px; overflow: hidden; margin-bottom: .45rem; }
.q-preview-header { display: flex; align-items: center; gap: .85rem; padding: .85rem 1.1rem; }
.q-preview-lock {
    width: 26px; height: 26px; border-radius: 6px; flex-shrink: 0;
    background: rgba(251,191,36,.08); display: flex; align-items: center; justify-content: center;
}
.q-preview-text { flex: 1; font-size: .87rem; font-weight: 500; color: var(--t2); }
.q-preview-body {
    padding: .65rem 1.1rem .85rem; border-top: 1px solid var(--border); font-size: .78rem;
    line-height: 1.65; color: var(--t3); filter: blur(3.5px); user-select: none; pointer-events: none;
}

/* ─── ANIMATIONS ─── */
@keyframes fadeUp { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
.fade  { animation: fadeUp .35s ease both; }
.d1 { animation-delay: .04s; }
.d2 { animation-delay: .09s; }
.d3 { animation-delay: .14s; }
.d4 { animation-delay: .19s; }

/* ─── MOBILE ─── */
.mobile-fab {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .page { padding: 1rem; }
    .topbar { padding: .8rem 1rem; }
    .mobile-fab { display: flex; align-items: center; justify-content: center; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .cat-strip { grid-template-columns: repeat(3, 1fr); }
    .plans-grid { grid-template-columns: 1fr 1fr; }
    .pdf-embed-wrap iframe { height: 340px; }
}
</style>
</head>
<body>

<!-- ─── SIDEBAR ─── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Logo" onerror="this.style.display='none'">
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="sidebar-av" id="sbAvContainer"></div>
            <div class="user-details">
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= htmlspecialchars($forceFlag) ?> · <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="workouts.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="progress.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Progress
        </a>
        <a href="nutrition.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Nutrition
        </a>
        <a href="questions.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
            <span class="nav-badge"><?= $totalQs ?></span>
        </a>
        <a href="documents.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Documents
        </a>
        <a href="motivation.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            Motivation
        </a>
        <div class="nav-section-title" style="margin-top:.75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="settings.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="subscription_fixed.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
        </a>
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
    <?php if ($isPremium): ?>
    <div class="sidebar-sub">
        <div class="sub-row">
            <span class="sub-label">Subscription</span>
            <span style="font-size:.67rem;color:#6ee7b7;font-weight:600">Active</span>
        </div>
        <div class="sub-val"><?= $daysLeft ?> days remaining</div>
        <div class="sub-bar"><div class="sub-fill" style="width:<?= $subPct ?>%"></div></div>
    </div>
    <?php endif; ?>
</aside>

<!-- ─── MAIN ─── -->
<div class="main">
    <header class="topbar">
        <nav class="bc">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Questions</span>
        </nav>
        <div class="tb-right">
            <span class="force-badge"><?= htmlspecialchars($forceFlag) ?> <?= htmlspecialchars($forceName) ?></span>
            <?php if (!$isPremium): ?>
                <a href="subscription_fixed.php" class="btn-ghost btn-gold">Unlock Access</a>
            <?php else: ?>
                <a href="chat.php" class="btn-ghost">Chat</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">

    <?php if ($isPremium): ?>
    <!-- ═══════════════════ PREMIUM ═══════════════════ -->

        <!-- Category strip with live counts -->
        <div class="sec-label fade d1">Categories</div>
        <div class="cat-strip fade d1" id="catStrip">
            <?php
            $catLabels = ['math' => 'Math', 'english' => 'English', 'general' => 'General', 'past' => 'Past Papers', 'physical' => 'Physical'];
            foreach ($CATEGORIES as $cat): ?>
            <div class="cat-pill <?= $cat === 'math' ? 'active' : '' ?>"
                 data-cat="<?= $cat ?>"
                 onclick="selectCat('<?= $cat ?>')">
                <div class="cat-pill-name"><?= $catLabels[$cat] ?></div>
                <div class="cat-pill-count" id="count-<?= $cat ?>"><?= $catCounts[$cat] ?></div>
                <div class="cat-pill-sub">questions</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- View tabs — renamed -->
        <div class="view-tabs fade d2">
            <button class="view-tab active" id="tabQuiz" onclick="switchView('quiz')">MCQs</button>
            <button class="view-tab" id="tabQA"   onclick="switchView('qa')">Past Papers</button>
        </div>

        <!-- ── MCQ QUIZ VIEW ── -->
        <div id="viewQuiz">
            <div class="quiz-arena fade d3" id="quizArena">
                <div class="quiz-topbar">
                    <span class="cat-tag" id="catTag">Math</span>
                    <div class="quiz-prog-wrap">
                        <span class="q-count">Q <b id="qNum">—</b> / <b id="qTot">—</b></span>
                        <div class="q-prog-bar"><div class="q-prog-fill" id="qProgFill" style="width:0%"></div></div>
                    </div>
                    <div class="live-score">
                        <span class="sc-ok">Correct <b id="liveOk">0</b></span>
                        <span class="sc-bad">Wrong <b id="liveErr">0</b></span>
                    </div>
                </div>

                <div class="quiz-body" id="quizLoading">
                    <div class="state-box">
                        <div class="spinner"></div>
                        Loading questions...
                    </div>
                </div>

                <div class="quiz-body" id="quizBody" style="display:none">
                    <div class="q-label">
                        <div class="q-num-box" id="qNumBox">1</div>
                        Question
                    </div>
                    <div class="q-text" id="qText"></div>
                    <div id="qFileWrap"></div>
                    <div class="options" id="optGrid"></div>
                    <div id="textRevealWrap"></div>
                    <div class="feedback" id="feedback"></div>
                </div>

                <div class="quiz-end" id="quizEnd">
                    <div class="end-score" id="endRing">—</div>
                    <div class="end-grade" id="endGrade">Quiz Complete</div>
                    <div class="end-sub"   id="endSub"></div>
                    <div class="end-actions">
                        <button class="btn-retry" onclick="restartQuiz()">Retry</button>
                        <button class="btn-other" onclick="switchCatNext()">Next Category</button>
                    </div>
                </div>

                <div class="quiz-footer" id="quizFooter">
                    <div class="quiz-hint" id="qHint">Select an answer to continue</div>
                    <button class="btn-next" id="btnNext" disabled onclick="nextQ()">
                        Next
                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>

            <!-- Analytics -->
            <div class="analytics fade d4" id="analyticsBox">
                <div class="an-title">
                    Progress Report
                    <span id="anMeta">Answer questions to see your stats</span>
                </div>
                <div class="stat-grid">
                    <div class="stat-box s-ok">  <div class="stat-val" id="aOk">0</div>   <div class="stat-lbl">Correct</div></div>
                    <div class="stat-box s-err">  <div class="stat-val" id="aErr">0</div>  <div class="stat-lbl">Wrong</div></div>
                    <div class="stat-box s-pct">  <div class="stat-val" id="aPct">—</div>  <div class="stat-lbl">Accuracy</div></div>
                    <div class="stat-box s-str">  <div class="stat-val" id="aStr">0</div>  <div class="stat-lbl">Best Streak</div></div>
                </div>
                <div class="cat-rows-title">By Category</div>
                <div id="catRows"><div class="empty-an">No answers yet.</div></div>
            </div>
        </div><!-- /viewQuiz -->

        <!-- ── PAST PAPERS VIEW ── -->
        <div id="viewQA" style="display:none">
            <div class="state-box fade d2" id="qaLoading">
                <div class="spinner"></div>
                Loading past papers...
            </div>
            <div id="qaContent"></div>
        </div>

    <?php else: ?>
    <!-- ═══════════════════ FREE / PAYWALL ═══════════════════ -->

        <?php if (!empty($previewQs)): ?>
        <div class="sec-label fade d1">Preview <span style="color:var(--t3);font-size:.7rem;font-weight:400;margin-left:.3rem"><?= $totalQs ?> questions locked</span></div>
        <?php foreach ($previewQs as $q): ?>
        <div class="q-preview fade d1">
            <div class="q-preview-header">
                <div class="q-preview-lock">
                    <svg width="13" height="13" fill="none" stroke="#fbbf24" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <div class="q-preview-text"><?= htmlspecialchars($q['question_text']) ?></div>
            </div>
            <div class="q-preview-body">
                Subscribe to unlock the answer, full quiz practice, and progress tracking tailored to <?= htmlspecialchars($forceName) ?>.
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="paywall fade d2" style="margin-top:1.25rem">
            <div style="margin-bottom:.75rem">
                <svg width="40" height="40" fill="none" stroke="rgba(251,191,36,.6)" viewBox="0 0 24 24" style="margin:0 auto"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div class="paywall-title">Subscribe to Access Questions</div>
            <div class="paywall-desc">
                Hundreds of MCQs and past papers across Math, English, General Knowledge, Past Papers, and Physical Tests — with instant feedback and a personal analytics dashboard tailored to <?= htmlspecialchars($forceName) ?>.
            </div>
            <?php if (!empty($plans)): ?>
            <div class="plans-grid">
                <?php foreach ($plans as $plan):
                    $isQ = ($plan['slug'] === 'quarterly'); ?>
                <a href="subscription_fixed.php?plan=<?= htmlspecialchars($plan['slug'], ENT_QUOTES) ?>"
                   class="plan-card <?= $isQ ? 'featured' : '' ?>">
                    <?php if ($isQ): ?><div class="plan-best">Best Value</div><?php endif; ?>
                    <div class="plan-price">Rs <?= number_format((float)$plan['price'], 0) ?><small>/<?= $isQ ? '3mo' : 'mo' ?></small></div>
                    <div class="plan-name"><?= htmlspecialchars($plan['name']) ?></div>
                    <div class="plan-duration"><?= (int)$plan['duration_days'] ?> days access</div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <a href="subscription_fixed.php" class="btn-ghost btn-gold" style="font-size:.83rem;padding:.65rem 1.75rem;margin-bottom:.65rem">
                Subscribe via eSewa
            </a>
            <div class="paywall-note">Instant access after payment · Secured by eSewa · Cancel anytime</div>
        </div>

    <?php endif; ?>

    </div><!-- /page -->
</div><!-- /main -->

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">&#9776;</button>

<script>
const IS_PREMIUM        = <?= json_encode($isPremium) ?>;
const SAVED_AVATAR_TYPE = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL   = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CFG  = <?= $avatarConfigJson ?>;
const USER_INITIAL      = <?= json_encode($initials) ?>;
const CATS              = ['math','english','general','past','physical'];
const CAT_LABELS        = { math:'Math', english:'English', general:'General Knowledge', past:'Past Papers', physical:'Physical' };
const FILE_BASE         = '/gurkha-marga/frontend/uploads/question_files/';

// ── Avatar ───────────────────────────────────────────────────────────────────
const AV_OPTIONS = {
    bg:[
        {id:'grad1',grad:['#1e3a5f','#2563eb']},{id:'grad2',grad:['#14532d','#16a34a']},
        {id:'grad3',grad:['#7f1d1d','#dc2626']},{id:'grad4',grad:['#312e81','#7c3aed']},
        {id:'grad5',grad:['#78350f','#d97706']},{id:'grad6',grad:['#134e4a','#0d9488']},
        {id:'grad7',grad:['#0f172a','#1e293b']},{id:'grad8',grad:['#4c0519','#e11d48']},
    ],
    skin:[
        {id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},
        {id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},
        {id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'},
    ],
    hairColor:[
        {id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},
        {id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},
        {id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},{id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'},
    ],
    eyes:[
        {id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},
        {id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},
        {id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'},
    ],
};

function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size/2, cy = size/2;
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)              || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o=>o.id===state.skin)          || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o=>o.id===state.eyes)          || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const hStyle = state.hairStyle || 'short';
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0,bg.grad[0]); grad.addColorStop(1,bg.grad[1]);
    ctx.fillStyle=grad; ctx.beginPath(); ctx.arc(cx,cy,cx,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]); ctx.fill();
    const shirt=ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle=shirt; ctx.beginPath(); ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle=hCol.color==='#F5F5F5'?'#C0A080':hCol.color;
    ctx.lineWidth=3.5; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-28,cy-16); ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx+7,cy-16);  ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16); ctx.stroke();
    ctx.fillStyle='#fff';
    ctx.beginPath(); ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=eyeC.color;
    ctx.beginPath(); ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='#000';
    ctx.beginPath(); ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.6)';
    ctx.beginPath(); ctx.arc(cx-13,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+19,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle='rgba(0,0,0,0.18)'; ctx.lineWidth=2; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-3,cy+2); ctx.lineTo(cx,cy+12); ctx.lineTo(cx+3,cy+2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx-9,cy+14); ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14); ctx.stroke();
    ctx.strokeStyle='rgba(0,0,0,0.3)'; ctx.lineWidth=2.5;
    ctx.beginPath(); ctx.moveTo(cx-14,cy+24); ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24); ctx.stroke();
    ctx.fillStyle=hCol.color;
    if(hStyle==='bald'){}
    else if(hStyle==='medium'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.ellipse(cx-44,cy+8,9,32,-0.2,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+44,cy+8,9,32,0.2,-Math.PI/2,Math.PI/2,true); ctx.fill();
    } else if(hStyle==='long'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.roundRect(cx-50,cy-10,12,75,6); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx+38,cy-10,12,75,6); ctx.fill();
    } else if(hStyle==='curly'){
        for(let a=0;a<Math.PI*2;a+=0.35){const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24;if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}}
        ctx.beginPath(); ctx.ellipse(cx,cy-42,40,18,0,Math.PI,0); ctx.fill();
    } else if(hStyle==='bun'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,44,20,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-43,88,18);
        ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.fill();
    } else if(hStyle==='mohawk'){
        ctx.beginPath(); ctx.moveTo(cx-10,cy-40); ctx.lineTo(cx,cy-80); ctx.lineTo(cx+10,cy-40); ctx.closePath(); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-10,cy-50,20,14,2); ctx.fill();
    } else {
        ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-40,88,20);
        ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
    }
    if(acc.includes('beard')){
        ctx.fillStyle=hCol.color;
        ctx.beginPath(); ctx.ellipse(cx,cy+36,30,18,0,0,Math.PI); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-30,cy+18,60,20,4); ctx.fill();
    }
    if(acc.includes('glasses')){
        ctx.strokeStyle='#64748b'; ctx.lineWidth=2.5; ctx.fillStyle='rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx-32,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-5); ctx.lineTo(cx+8,cy-5); ctx.stroke();
    }
    if(acc.includes('sunglasses')){
        ctx.fillStyle='#111827'; ctx.strokeStyle='#374151'; ctx.lineWidth=2;
        ctx.beginPath(); ctx.roundRect(cx-34,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-7); ctx.lineTo(cx+8,cy-7); ctx.stroke();
    }
    const ring=ctx.createLinearGradient(0,0,size,size);
    ring.addColorStop(0,bg.grad[0]+'88'); ring.addColorStop(1,bg.grad[1]+'88');
    ctx.strokeStyle=ring; ctx.lineWidth=size<60?2:4;
    ctx.beginPath(); ctx.arc(cx,cy,cx-2,0,Math.PI*2); ctx.stroke();
}

function renderSidebarAvatar() {
    const c = document.getElementById('sbAvContainer'); if (!c) return; c.innerHTML = '';
    if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
        const img = document.createElement('img');
        img.src = SAVED_PHOTO_URL;
        img.style.cssText = 'width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror = () => renderInitialSb(c);
        c.appendChild(img);
    } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CFG) {
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CFG, 38);
    } else { renderInitialSb(c); }
}
function renderInitialSb(c) {
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    c.appendChild(d);
}

// ════════════════════════════════════════════════════════
// ── QUIZ ENGINE ─────────────────────────────────────────
// ════════════════════════════════════════════════════════
if (IS_PREMIUM) {

let currentCat   = 'math';
let questionBank = {};
let qIdx         = 0;
let answered     = false;
const stats      = {};
CATS.forEach(c => { stats[c] = { correct:0, wrong:0, streak:0, best:0, answered:0 }; });

async function fetchCat(cat) {
    if (questionBank[cat]) return questionBank[cat];
    try {
        const res  = await fetch('api_questions.php?cat=' + cat);
        const data = await res.json();
        questionBank[cat] = data.questions || [];
        return questionBank[cat];
    } catch (e) {
        questionBank[cat] = [];
        return [];
    }
}

async function selectCat(cat) {
    currentCat = cat;
    qIdx       = 0;
    answered   = false;

    document.querySelectorAll('.cat-pill').forEach(p => {
        p.classList.toggle('active', p.dataset.cat === cat);
    });
    document.getElementById('catTag').textContent = CAT_LABELS[cat] || cat;

    document.getElementById('quizEnd').classList.remove('show');
    document.getElementById('quizBody').style.display    = 'none';
    document.getElementById('quizFooter').style.display  = '';
    document.getElementById('quizLoading').style.display = '';

    const qs = await fetchCat(cat);
    document.getElementById('quizLoading').style.display = 'none';

    if (!qs.length) {
        document.getElementById('quizBody').style.display = '';
        document.getElementById('qText').textContent      = 'No MCQ questions available for this category yet.';
        document.getElementById('optGrid').innerHTML      = '';
        document.getElementById('qFileWrap').innerHTML    = '';
        document.getElementById('textRevealWrap').innerHTML = '';
        document.getElementById('feedback').className     = 'feedback';
        document.getElementById('btnNext').disabled       = true;
        document.getElementById('qHint').textContent      = '';
        document.getElementById('qNum').textContent       = '—';
        document.getElementById('qTot').textContent       = '—';
        document.getElementById('qProgFill').style.width  = '0%';
        return;
    }

    // Filter to only MCQ for the quiz view
    const mcqOnly = qs.filter(q => q.type === 'mcq');
    questionBank[cat + '_mcq'] = mcqOnly;

    if (!mcqOnly.length) {
        document.getElementById('quizBody').style.display = '';
        document.getElementById('qText').textContent      = 'No MCQ questions in this category. Check Past Papers for study material.';
        document.getElementById('optGrid').innerHTML      = '';
        document.getElementById('qFileWrap').innerHTML    = '';
        document.getElementById('textRevealWrap').innerHTML = '';
        document.getElementById('feedback').className     = 'feedback';
        document.getElementById('btnNext').disabled       = true;
        document.getElementById('qHint').textContent      = '';
        document.getElementById('qNum').textContent       = '—';
        document.getElementById('qTot').textContent       = '—';
        document.getElementById('qProgFill').style.width  = '0%';
        return;
    }

    showQuestion();
}

function showQuestion() {
    const qs    = questionBank[currentCat + '_mcq'] || questionBank[currentCat] || [];
    const q     = qs[qIdx];
    const total = qs.length;
    answered    = false;

    document.getElementById('qNum').textContent      = qIdx + 1;
    document.getElementById('qTot').textContent      = total;
    document.getElementById('qNumBox').textContent   = qIdx + 1;
    document.getElementById('qProgFill').style.width = ((qIdx + 1) / total * 100) + '%';
    document.getElementById('qText').textContent     = q.q;
    document.getElementById('btnNext').disabled      = true;
    document.getElementById('qHint').textContent     = 'Select an answer to continue';

    const fw = document.getElementById('qFileWrap');
    if (q.file) {
        const ext = q.file.split('.').pop().toUpperCase();
        fw.innerHTML = `<a class="q-file-link" href="${FILE_BASE + q.file}" target="_blank">
            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
            View ${ext} attachment
        </a>`;
    } else { fw.innerHTML = ''; }

    const fb = document.getElementById('feedback');
    fb.className = 'feedback'; fb.innerHTML = '';
    document.getElementById('textRevealWrap').innerHTML = '';

    const grid = document.getElementById('optGrid');
    grid.innerHTML = '';

    if (q.opts && q.opts.length) {
        ['A','B','C','D'].forEach((letter, i) => {
            if (q.opts[i] === undefined || q.opts[i] === null || q.opts[i] === '') return;
            const btn = document.createElement('button');
            btn.className = 'opt';
            btn.innerHTML = `<span class="opt-letter">${letter}</span><span style="flex:1">${escHtml(String(q.opts[i]))}</span><span class="opt-icon" id="oi-${i}"></span>`;
            btn.onclick = () => pickAnswer(i);
            grid.appendChild(btn);
        });
    }

    document.getElementById('quizBody').style.display = '';
    updateLiveScore();
}

function pickAnswer(chosen) {
    if (answered) return;
    answered = true;
    const qs = questionBank[currentCat + '_mcq'] || questionBank[currentCat] || [];
    const q  = qs[qIdx];
    const st = stats[currentCat];
    const btns = document.querySelectorAll('.opt');

    btns.forEach((b, i) => {
        b.disabled = true;
        if (i === q.ans) {
            b.classList.add('correct');
            document.getElementById('oi-' + i).textContent = '✓';
        }
    });

    const fb = document.getElementById('feedback');
    if (chosen === q.ans) {
        btns[chosen].classList.add('correct');
        st.correct++; st.streak++; st.answered++;
        if (st.streak > st.best) st.best = st.streak;
        fb.className = 'feedback fb-ok show';
        fb.innerHTML = `<div class="fb-label">Correct</div>${q.exp ? escHtml(q.exp) : ''}`;
        document.getElementById('qHint').textContent = 'Well done — next question';
    } else {
        btns[chosen].classList.add('wrong');
        document.getElementById('oi-' + chosen).textContent = '✗';
        st.wrong++; st.streak = 0; st.answered++;
        fb.className = 'feedback fb-err show';
        fb.innerHTML = `<div class="fb-label">Incorrect</div>${q.exp ? escHtml(q.exp) : ''}`;
        document.getElementById('qHint').textContent = 'Review the explanation above';
    }

    document.getElementById('btnNext').disabled = false;
    updateLiveScore();
    updateAnalytics();
}

function nextQ() {
    qIdx++;
    const qs = questionBank[currentCat + '_mcq'] || questionBank[currentCat] || [];
    if (!qs || qIdx >= qs.length) { showEnd(); return; }
    showQuestion();
}

function showEnd() {
    document.getElementById('quizBody').style.display   = 'none';
    document.getElementById('quizFooter').style.display = 'none';
    const st  = stats[currentCat];
    const pct = st.answered ? Math.round(st.correct / st.answered * 100) : 0;
    let grade = 'Keep going — practice makes perfect.';
    if (pct >= 90)      grade = 'Outstanding result.';
    else if (pct >= 75) grade = 'Excellent work.';
    else if (pct >= 60) grade = 'Good performance.';
    else if (pct >= 40) grade = 'Decent effort — review your mistakes.';

    document.getElementById('endRing').textContent  = pct + '%';
    document.getElementById('endGrade').textContent = grade;
    document.getElementById('endSub').textContent   =
        `You answered ${st.correct} of ${st.answered} correctly in ${CAT_LABELS[currentCat] || currentCat}.`;
    document.getElementById('quizEnd').classList.add('show');
    updateAnalytics();
}

function restartQuiz() {
    const st = stats[currentCat];
    st.correct = 0; st.wrong = 0; st.streak = 0; st.best = 0; st.answered = 0;
    qIdx = 0;
    document.getElementById('quizEnd').classList.remove('show');
    document.getElementById('quizBody').style.display   = '';
    document.getElementById('quizFooter').style.display = '';
    showQuestion();
}

function switchCatNext() {
    const idx  = CATS.indexOf(currentCat);
    const next = CATS[(idx + 1) % CATS.length];
    selectCat(next);
}

function updateLiveScore() {
    let tc = 0, tw = 0;
    CATS.forEach(c => { tc += stats[c].correct; tw += stats[c].wrong; });
    document.getElementById('liveOk').textContent  = tc;
    document.getElementById('liveErr').textContent = tw;
    document.getElementById('aOk').textContent     = tc;
    document.getElementById('aErr').textContent    = tw;
}

function updateAnalytics() {
    let tc = 0, tw = 0, bs = 0;
    CATS.forEach(c => { tc += stats[c].correct; tw += stats[c].wrong; bs = Math.max(bs, stats[c].best); });
    const tot = tc + tw;
    const pct = tot ? Math.round(tc / tot * 100) : null;
    document.getElementById('aPct').textContent = pct !== null ? pct + '%' : '—';
    document.getElementById('aStr').textContent = bs;
    if (tot) document.getElementById('anMeta').textContent = `${tot} answered · ${tc} correct`;

    const answeredCats = CATS.filter(c => stats[c].answered > 0);
    const rows = document.getElementById('catRows');
    if (!answeredCats.length) {
        rows.innerHTML = '<div class="empty-an">No answers yet.</div>';
        return;
    }
    rows.innerHTML = answeredCats.map(cat => {
        const s   = stats[cat];
        const tot = s.correct + s.wrong;
        const p   = tot ? Math.round(s.correct / tot * 100) : 0;
        return `
            <div class="cat-row">
                <div class="cat-row-name">${CAT_LABELS[cat] || cat}</div>
                <div class="cat-bar"><div class="cat-bar-fill" style="width:${p}%"></div></div>
                <div class="cat-row-stat">${s.correct}/${tot} · ${p}%</div>
            </div>`;
    }).join('');
}

// ── View switcher ────────────────────────────────────────
function switchView(v) {
    document.getElementById('viewQuiz').style.display = v === 'quiz' ? '' : 'none';
    document.getElementById('viewQA').style.display   = v === 'qa'   ? '' : 'none';
    document.getElementById('tabQuiz').classList.toggle('active', v === 'quiz');
    document.getElementById('tabQA').classList.toggle('active',   v === 'qa');
    if (v === 'qa' && !document.getElementById('qaContent').children.length) {
        loadPastPapers();
    }
}

// ── File type helpers ─────────────────────────────────────
function getFileExt(filename) {
    return filename ? filename.split('.').pop().toLowerCase() : '';
}

function getFileBadgeClass(ext) {
    if (['pdf'].includes(ext)) return 'pp-type-pdf';
    if (['doc','docx'].includes(ext)) return 'pp-type-doc';
    if (['png','jpg','jpeg','gif','webp'].includes(ext)) return 'pp-type-img';
    if (['mp4','webm','mov'].includes(ext)) return 'pp-type-vid';
    return 'pp-type-text';
}

function getFileBadgeLabel(ext) {
    if (!ext) return 'TEXT';
    return ext.toUpperCase();
}

// Build the body HTML for a past-paper card
function buildPPBody(q) {
    let html = '';

    // ── File viewer ──
    if (q.file) {
        const ext  = getFileExt(q.file);
        const url  = FILE_BASE + q.file;
        const isPdf = ext === 'pdf';
        const isImg = ['png','jpg','jpeg','gif','webp'].includes(ext);
        const isVid = ['mp4','webm'].includes(ext);
        const isDoc = ['doc','docx'].includes(ext);

        if (isPdf) {
            html += `
            <div class="pdf-embed-wrap">
                <iframe
                    src="${url}#toolbar=1&navpanes=1&scrollbar=1"
                    title="PDF Viewer"
                    loading="lazy"
                    onerror="this.parentElement.innerHTML='<div class=pdf-fallback><div class=pdf-icon>📄</div><p>Unable to display PDF inline.</p><a href=${url} target=_blank>Open PDF in new tab →</a></div>'">
                </iframe>
            </div>
            <div class="pp-actions">
                <a href="${url}" target="_blank" class="pp-btn">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Open in new tab
                </a>
                <a href="${url}" download class="pp-btn dl">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download PDF
                </a>
            </div>`;
        } else if (isImg) {
            html += `<img src="${url}" alt="Attachment" class="img-preview" loading="lazy">
            <div class="pp-actions">
                <a href="${url}" target="_blank" class="pp-btn">View full size</a>
                <a href="${url}" download class="pp-btn dl">Download</a>
            </div>`;
        } else if (isVid) {
            html += `<video controls style="width:100%;border-radius:8px;border:1px solid var(--border);margin-bottom:.75rem">
                <source src="${url}">
                <p style="font-size:.78rem;color:var(--t3)">Your browser doesn't support video. <a href="${url}" target="_blank" style="color:var(--accent)">Download it</a>.</p>
            </video>`;
        } else if (isDoc) {
            html += `
            <div style="background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:8px;padding:1rem;margin-bottom:.75rem;text-align:center">
                <div style="font-size:1.5rem;margin-bottom:.5rem">📝</div>
                <div style="font-size:.8rem;color:var(--t2);margin-bottom:.75rem">Word document attached</div>
                <div class="pp-actions" style="justify-content:center">
                    <a href="${url}" target="_blank" class="pp-btn">Open document</a>
                    <a href="${url}" download class="pp-btn dl">Download</a>
                </div>
            </div>`;
        } else {
            html += `<div class="pp-actions" style="margin-bottom:.75rem">
                <a href="${url}" target="_blank" class="pp-btn">Open attachment</a>
                <a href="${url}" download class="pp-btn dl">Download</a>
            </div>`;
        }
    }

    // ── MCQ options ──
    if (q.type === 'mcq' && q.opts && q.opts.length) {
        const letters = ['A','B','C','D'];
        html += '<div class="mcq-opts-list">' +
            q.opts.map((o, i) =>
                `<div class="mcq-opt-item ${i===q.ans?'is-correct':''}">
                    <span class="mcq-opt-letter">${letters[i]}</span>
                    <span>${escHtml(String(o))}</span>
                    ${i===q.ans ? '<span style="margin-left:auto;font-size:.7rem;font-weight:700">✓ Correct</span>' : ''}
                </div>`
            ).join('') +
        '</div>';
    }

    // ── Explanation / Answer text ──
    if (q.exp) {
        html += `<div class="pp-answer-text">${escHtml(q.exp).replace(/\n/g,'<br>')}</div>`;
    } else if (!q.file) {
        html += `<div class="pp-answer-text" style="color:var(--t3);font-style:italic">Answer will be available soon.</div>`;
    }

    return html;
}

// ── Load Past Papers view (all questions) ─────────────────
async function loadPastPapers() {
    const loading = document.getElementById('qaLoading');
    const content = document.getElementById('qaContent');
    loading.style.display = '';
    content.innerHTML     = '';

    const all = {};
    for (const cat of CATS) {
        const qs = await fetchCat(cat);
        if (qs.length) all[cat] = qs;
    }

    loading.style.display = 'none';

    const catKeys = Object.keys(all);
    if (!catKeys.length) {
        content.innerHTML = '<div class="state-box"><div class="state-title">No content available yet.</div></div>';
        return;
    }

    let num = 0;
    catKeys.forEach(cat => {
        const section = document.createElement('div');
        section.className = 'past-section';

        const titleEl = document.createElement('div');
        titleEl.className   = 'past-section-title';
        titleEl.textContent = CAT_LABELS[cat] || cat;
        section.appendChild(titleEl);

        all[cat].forEach(q => {
            num++;
            const ext = getFileExt(q.file);
            const badgeClass = q.file ? getFileBadgeClass(ext) : 'pp-type-text';
            const badgeLabel = q.file ? getFileBadgeLabel(ext) : (q.type === 'mcq' ? 'MCQ' : 'TEXT');

            const card = document.createElement('div');
            card.className = 'pp-card';
            card.id = 'pp-' + q.id;

            card.innerHTML = `
                <div class="pp-card-header" onclick="togglePPCard(${q.id})">
                    <div class="pp-num">${num}</div>
                    <div class="pp-title">${escHtml(q.q)}</div>
                    <div class="pp-badges">
                        <span class="pp-type-badge ${badgeClass}">${escHtml(badgeLabel)}</span>
                    </div>
                    <div class="pp-chevron">&#9660;</div>
                </div>
                <div class="pp-card-body">${buildPPBody(q)}</div>`;

            section.appendChild(card);
        });

        content.appendChild(section);
    });
}

function togglePPCard(id) {
    const card = document.getElementById('pp-' + id); if (!card) return;
    const wasOpen = card.classList.contains('open');
    document.querySelectorAll('.pp-card.open').forEach(c => c.classList.remove('open'));
    if (!wasOpen) card.classList.add('open');
}

// ── Helper ───────────────────────────────────────────────
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Boot ─────────────────────────────────────────────────
selectCat('math');
updateAnalytics();

} // end IS_PREMIUM

// ── Mobile sidebar ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    document.addEventListener('click', e => {
        const sb  = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth <= 768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target))
            sb.classList.remove('active');
    });
});
</script>
</body>
</html>