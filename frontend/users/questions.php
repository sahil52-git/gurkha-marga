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
    'british'   => ['flag'=>'🇬🇧', 'name'=>'British Army'],
    'nepal'     => ['flag'=>'🇳🇵', 'name'=>'Nepal Army'],
    'indian'    => ['flag'=>'🇮🇳', 'name'=>'Indian Army'],
    'singapore' => ['flag'=>'🇸🇬', 'name'=>'Singapore Police Force'],
    'french'    => ['flag'=>'🇫🇷', 'name'=>'French Foreign Legion'],
];
$forceFlag = $forceMap[$forceKey]['flag'] ?? '🎖️';
$forceName = $forceMap[$forceKey]['name'] ?? ucfirst($forceKey);

// All text questions from DB (for premium accordion view — keep for backward compat)
$allQs = fetchAll(
    "SELECT * FROM questions
     WHERE is_active=1 AND (target_force=? OR target_force='all')
     ORDER BY category, sort_order, id",
    [$forceKey]
);
$totalQs = count($allQs);

// Plans for paywall
$plans = fetchAll("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY price");

// Subscription progress bar
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
<title>Questions & Quiz — Gurkha Marga</title>
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
.btn-gold {
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    border-color: transparent; color: #0a0800; font-weight: 700;
}
.btn-gold:hover { opacity:.9; transform:translateY(-1px); }

/* ─── PAGE ─── */
.page { padding: 1.75rem; max-width: 860px; }

/* ─── SECTION HEADER ─── */
.sec-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: .9rem; }
.sec-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--t3);
    display: flex; align-items: center; gap: .5rem;
}
.sec-title::after { content: ''; flex: 0 0 24px; height: 1px; background: var(--border); }
.sec-badge {
    padding: .2rem .6rem; border-radius: 6px; font-size: .67rem; font-weight: 600;
    background: rgba(251,191,36,.1); border: 1px solid rgba(251,191,36,.18); color: var(--gold);
}

/* ─── ACTIVE SUB BANNER ─── */
.sub-banner {
    display: flex; align-items: center; gap: 1rem;
    padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem;
    background: rgba(16,185,129,.07); border: 1px solid rgba(16,185,129,.2);
}
.sub-banner-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    background: rgba(16,185,129,.12); display: flex; align-items: center; justify-content: center;
}
.sub-banner-body { flex: 1; }
.sub-banner-title { font-size: .85rem; font-weight: 600; color: #6ee7b7; }
.sub-banner-sub   { font-size: .7rem; color: var(--t3); margin-top: 2px; }

/* ─── VIEW SWITCHER (premium) ─── */
.view-switcher {
    display: flex; gap: .4rem; margin-bottom: 1.25rem;
    background: rgba(15,23,42,.5); border: 1px solid var(--border);
    border-radius: 10px; padding: .3rem;
}
.view-btn {
    flex: 1; padding: .55rem .75rem; border-radius: 7px; font-family: 'Poppins',sans-serif;
    font-size: .78rem; font-weight: 600; border: none; background: transparent;
    color: var(--t3); cursor: pointer; transition: all .2s; display: flex;
    align-items: center; justify-content: center; gap: 6px;
}
.view-btn:hover { color: var(--t2); }
.view-btn.active { background: var(--surface-solid); color: var(--t1); box-shadow: 0 2px 8px rgba(0,0,0,.3); }

/* ─── QUIZ TABS ─── */
.quiz-tabs { display: flex; gap: .5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.quiz-tab {
    padding: .48rem 1rem; border-radius: 8px; font-size: .78rem; font-weight: 600;
    border: 1px solid var(--border); background: transparent; color: var(--t2);
    cursor: pointer; transition: all .18s; font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 5px;
}
.quiz-tab:hover { background: var(--hover); color: var(--t1); }
.quiz-tab.active { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); color: #93c5fd; }

/* ─── QUIZ ARENA ─── */
.quiz-arena {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden; margin-bottom: 1.25rem;
}
.quiz-topbar {
    padding: 1rem 1.4rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.quiz-cat-pill {
    padding: .28rem .75rem; border-radius: 99px; font-size: .7rem; font-weight: 700;
    display: flex; align-items: center; gap: 4px;
}
.pill-math    { background: rgba(59,130,246,.15);  border: 1px solid rgba(59,130,246,.3);  color: #93c5fd; }
.pill-english { background: rgba(16,185,129,.15);  border: 1px solid rgba(16,185,129,.3);  color: #6ee7b7; }
.pill-general { background: rgba(251,191,36,.12);  border: 1px solid rgba(251,191,36,.3);  color: var(--gold); }
.pill-past    { background: rgba(139,92,246,.15);  border: 1px solid rgba(139,92,246,.3);  color: #c4b5fd; }
.pill-physical{ background: rgba(239,68,68,.12);   border: 1px solid rgba(239,68,68,.3);   color: #fca5a5; }
.quiz-progress-wrap { flex: 1; display: flex; align-items: center; gap: .75rem; min-width: 120px; }
.q-count { font-size: .72rem; color: var(--t3); white-space: nowrap; }
.q-prog-bar { flex: 1; height: 5px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden; }
.q-prog-fill { height: 100%; background: linear-gradient(90deg,var(--accent),#818cf8); border-radius:3px; transition:width .5s ease; }
.live-score { display: flex; gap: .85rem; font-size: .75rem; }
.live-score span { display: flex; align-items: center; gap: 4px; color: var(--t3); }
.live-score span b { color: var(--t1); }

/* ─── QUESTION BODY ─── */
.quiz-body { padding: 1.5rem 1.4rem; }
.q-num-tag {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .68rem; font-weight: 700; color: var(--t3); margin-bottom: .9rem;
    text-transform: uppercase; letter-spacing: .08em;
}
.q-num-box {
    width: 22px; height: 22px; border-radius: 5px;
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 800; color: #93c5fd;
}
.q-text { font-size: .98rem; font-weight: 600; line-height: 1.6; color: var(--t1); margin-bottom: 1.35rem; }

/* ─── OPTIONS ─── */
.options { display: flex; flex-direction: column; gap: .55rem; }
.opt {
    display: flex; align-items: center; gap: .9rem;
    padding: .82rem 1.1rem; border-radius: 10px;
    border: 1px solid var(--border); background: rgba(15,23,42,.5);
    color: var(--t2); cursor: pointer; transition: all .18s;
    font-family: 'Poppins',sans-serif; font-size: .85rem; font-weight: 500;
    text-align: left; width: 100%;
}
.opt:hover:not(:disabled) {
    border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.08); color: var(--t1);
    transform: translateX(3px);
}
.opt:disabled { cursor: default; }
.opt.correct {
    border-color: rgba(16,185,129,.5); background: rgba(16,185,129,.1);
    color: #6ee7b7; transform: none;
}
.opt.wrong {
    border-color: rgba(239,68,68,.5); background: rgba(239,68,68,.1);
    color: #fca5a5; transform: none;
}
.opt-letter {
    width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 800; color: var(--t3);
    transition: all .18s;
}
.opt:hover:not(:disabled) .opt-letter { background: rgba(59,130,246,.15); border-color:rgba(59,130,246,.4); color:#93c5fd; }
.opt.correct .opt-letter { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.5); color: #6ee7b7; }
.opt.wrong   .opt-letter { background: rgba(239,68,68,.2);  border-color: rgba(239,68,68,.5);  color: #fca5a5; }
.opt-icon { margin-left: auto; font-size: .9rem; flex-shrink: 0; }

/* ─── FEEDBACK ─── */
.feedback {
    margin-top: 1rem; padding: .8rem 1rem; border-radius: 9px;
    font-size: .8rem; line-height: 1.65; display: none;
}
.feedback.show { display: block; animation: fadeUp .25s ease; }
.feedback.fb-correct { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.2); color: #a7f3d0; }
.feedback.fb-wrong   { background: rgba(239,68,68,.08);  border: 1px solid rgba(239,68,68,.2);  color: #fecaca; }
.feedback-label { font-weight: 700; margin-bottom: .2rem; font-size: .82rem; }

/* ─── QUIZ FOOTER ─── */
.quiz-footer {
    padding: .9rem 1.4rem; border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.quiz-hint { font-size: .72rem; color: var(--t3); }
.btn-next {
    padding: .55rem 1.5rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .82rem; font-weight: 700; border: none;
    background: linear-gradient(135deg, var(--accent), #6366f1);
    color: #fff; cursor: pointer; transition: all .2s;
    display: flex; align-items: center; gap: 6px;
}
.btn-next:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59,130,246,.35); }
.btn-next:disabled { opacity: .35; cursor: default; transform: none; }

/* ─── QUIZ END ─── */
.quiz-end {
    display: none; text-align: center;
    padding: 2.75rem 2rem;
}
.quiz-end.show { display: block; animation: fadeUp .4s ease; }
.end-trophy { font-size: 4rem; margin-bottom: .75rem; display: block; }
.end-grade  { font-size: 1.25rem; font-weight: 800; margin-bottom: .35rem; }
.end-sub    { font-size: .83rem; color: var(--t3); line-height: 1.65; margin-bottom: 1.5rem; }
.end-score-ring {
    display: inline-flex; align-items: center; justify-content: center;
    width: 90px; height: 90px; border-radius: 50%;
    border: 4px solid rgba(251,191,36,.35);
    font-size: 1.5rem; font-weight: 800; color: var(--gold);
    margin-bottom: 1.25rem;
    background: radial-gradient(circle, rgba(251,191,36,.08), transparent);
}
.end-actions { display: flex; gap: .65rem; justify-content: center; flex-wrap: wrap; }
.btn-restart {
    padding: .6rem 1.5rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .82rem; font-weight: 700;
    border: 1px solid rgba(251,191,36,.4);
    background: rgba(251,191,36,.1); color: var(--gold); cursor: pointer; transition: all .18s;
}
.btn-restart:hover { background: rgba(251,191,36,.18); }
.btn-next-cat {
    padding: .6rem 1.5rem; border-radius: 8px; font-family: 'Poppins',sans-serif;
    font-size: .82rem; font-weight: 700;
    border: 1px solid rgba(59,130,246,.4);
    background: rgba(59,130,246,.12); color: #93c5fd; cursor: pointer; transition: all .18s;
}
.btn-next-cat:hover { background: rgba(59,130,246,.2); }

/* ─── ANALYTICS ─── */
.analytics {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 1.5rem; margin-bottom: 1.25rem;
}
.analytics-title { font-size: .88rem; font-weight: 700; margin-bottom: 1.2rem; display: flex; align-items: center; justify-content: space-between; }
.analytics-title span { font-size: .7rem; color: var(--t3); font-weight: 400; }
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .65rem; margin-bottom: 1.25rem; }
.stat-box {
    background: rgba(15,23,42,.55); border: 1px solid var(--border);
    border-radius: 10px; padding: .9rem .75rem; text-align: center;
}
.stat-val { font-size: 1.55rem; font-weight: 800; line-height: 1; }
.stat-lbl { font-size: .65rem; color: var(--t3); margin-top: .3rem; text-transform: uppercase; letter-spacing: .06em; }
.stat-box.s-correct .stat-val { color: var(--success); }
.stat-box.s-wrong   .stat-val { color: var(--error);   }
.stat-box.s-pct     .stat-val { color: var(--gold);    }
.stat-box.s-streak  .stat-val { color: #a78bfa;        }

.cat-breakdown { }
.cat-breakdown-title { font-size: .7rem; font-weight: 600; color: var(--t3); text-transform: uppercase; letter-spacing:.08em; margin-bottom: .75rem; }
.cat-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .6rem; }
.cat-row-icon { font-size: .85rem; flex-shrink: 0; }
.cat-row-name { font-size: .78rem; color: var(--t2); width: 120px; flex-shrink: 0; }
.cat-bar { flex: 1; height: 7px; background: rgba(255,255,255,.06); border-radius: 4px; overflow: hidden; }
.cat-bar-inner { height: 100%; border-radius: 4px; transition: width .6s cubic-bezier(.4,0,.2,1); }
.bar-math     { background: linear-gradient(90deg, #3b82f6, #818cf8); }
.bar-english  { background: linear-gradient(90deg, #10b981, #34d399); }
.bar-general  { background: linear-gradient(90deg, #fbbf24, #f97316); }
.bar-past     { background: linear-gradient(90deg, #8b5cf6, #ec4899); }
.bar-physical { background: linear-gradient(90deg, #ef4444, #f97316); }
.cat-row-stat { font-size: .72rem; color: var(--t3); width: 60px; text-align: right; white-space: nowrap; }
.empty-analytics { font-size: .78rem; color: var(--t3); text-align: center; padding: 1rem 0; }

/* ─── ACCORDION (text questions, premium) ─── */
.q-list { display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1.5rem; }
.q-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; transition: border-color .18s;
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
.q-text2  { flex: 1; font-size: .88rem; font-weight: 500; line-height: 1.4; }
.q-chevron {
    width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
    font-size: .6rem; color: var(--t3); transition: transform .22s, color .18s; flex-shrink: 0;
}
.q-card.open .q-chevron { transform: rotate(180deg); color: var(--accent); }
.q-body2 {
    display: none; padding: .85rem 1.1rem 1rem;
    font-size: .82rem; line-height: 1.7; color: var(--t2);
    border-top: 1px solid var(--border); background: rgba(15,23,42,.4);
}
.q-card.open .q-body2 { display: block; }
.cat-label {
    font-size: .62rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
    color: var(--t3); margin: 1rem 0 .5rem;
    display: flex; align-items: center; gap: .5rem;
}
.cat-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.note {
    display: flex; gap: .6rem; padding: .65rem .9rem; border-radius: 8px;
    margin-bottom: .9rem; font-size: .77rem; line-height: 1.55;
    background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.15); color: #7ab8ff;
}

/* ─── PAYWALL (free user) ─── */
.paywall {
    background: var(--surface); border: 1px solid rgba(251,191,36,.2);
    border-radius: 16px; padding: 2.5rem 2rem; text-align: center; margin-bottom: 1.5rem;
}
.paywall-title { font-size: 1.1rem; font-weight: 700; color: var(--gold); margin-bottom: .5rem; }
.paywall-desc  { font-size: .82rem; color: var(--t3); line-height: 1.65; max-width: 440px; margin: 0 auto 1.75rem; }
.plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; max-width: 500px; margin: 0 auto 1.5rem; }
.plan-card {
    background: rgba(15,23,42,.6); border: 1px solid var(--border);
    border-radius: 10px; padding: 1.1rem .9rem; cursor: pointer;
    transition: all .18s; text-align: center; position: relative;
    text-decoration: none; display: block;
}
.plan-card:hover { border-color: rgba(251,191,36,.4); transform: translateY(-2px); }
.plan-card.featured { border-color: rgba(251,191,36,.45); background: rgba(251,191,36,.04); }
.plan-best {
    position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
    background: linear-gradient(135deg, var(--gold), #f59e0b); color: #0a0800;
    font-size: .62rem; font-weight: 700; padding: .2rem .65rem; border-radius: 99px; white-space: nowrap;
}
.plan-price { font-size: 1.3rem; font-weight: 700; color: var(--gold); line-height: 1; margin-bottom: .2rem; }
.plan-price small { font-size: .7rem; color: var(--t3); font-weight: 400; }
.plan-name     { font-size: .78rem; font-weight: 600; margin-bottom: .15rem; }
.plan-duration { font-size: .7rem; color: var(--t3); }
.paywall-note { font-size: .72rem; color: var(--t3); margin-top: 1rem; line-height: 1.6; }
.q-preview { background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:.5rem; }
.q-preview-header { display:flex;align-items:center;gap:.85rem;padding:.85rem 1.1rem; }
.q-preview-lock { width:26px;height:26px;border-radius:6px;flex-shrink:0;background:rgba(251,191,36,.08);display:flex;align-items:center;justify-content:center; }
.q-preview-text { flex:1;font-size:.88rem;font-weight:500;color:var(--t2); }
.q-preview-body { padding:.7rem 1.1rem .9rem;border-top:1px solid var(--border);font-size:.8rem;line-height:1.65;color:var(--t3);filter:blur(3.5px);user-select:none;pointer-events:none; }

/* ─── ANIMATIONS ─── */
@keyframes fadeUp { from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none} }
.fade  { animation: fadeUp .35s ease both; }
.d1{animation-delay:.04s}.d2{animation-delay:.09s}.d3{animation-delay:.14s}.d4{animation-delay:.19s}

/* ─── MOBILE ─── */
.mobile-fab {
    display:none; position:fixed; bottom:1.25rem; right:1.25rem;
    width:50px; height:50px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    border:none; color:#fff; font-size:1.2rem; cursor:pointer;
    box-shadow:0 4px 15px rgba(59,130,246,.4); z-index:999;
}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%)}.sidebar.active{transform:translateX(0)}
    .main{margin-left:0}.page{padding:1rem}.topbar{padding:.8rem 1rem}
    .mobile-fab{display:flex;align-items:center;justify-content:center}
    .stat-grid{grid-template-columns:1fr 1fr}
    .plans-grid{grid-template-columns:1fr 1fr}
    .quiz-topbar{gap:.6rem}
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
                <p><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></p>
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
        <div class="nav-section-title" style="margin-top: .75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="settings.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <!-- ★ NEW — Subscription link added below Settings -->
        <a href="subscription_fixed.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
        </a>
        <!-- ★ END NEW -->
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
    <?php if ($isPremium): ?>
    <div class="sidebar-sub">
        <div class="sub-row">
            <span class="sub-label">Subscription</span>
            <span style="font-size:.67rem;color:#6ee7b7;font-weight:600">Active ✓</span>
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
            <span><?= $isPremium ? 'Questions & Quiz' : 'Questions' ?></span>
        </nav>
        <div class="tb-right">
            <span class="force-badge"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
            <?php if (!$isPremium): ?>
            <a href="subscription_fixed.php" class="btn-ghost btn-gold">⭐ Unlock Access</a>
            <?php else: ?>
            <a href="chat.php" class="btn-ghost btn-gold">💬 Chat</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">

    <?php if ($isPremium): ?>
    <!-- ═══════════════════════ PREMIUM VIEW ═══════════════════════ -->

        <!-- Sub active banner -->
        <div class="sub-banner fade d1">
            <div class="sub-banner-icon">
                <svg width="18" height="18" fill="none" stroke="#6ee7b7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="sub-banner-body">
                <div class="sub-banner-title">Premium Active — <?= $daysLeft ?> days remaining</div>
                <div class="sub-banner-sub"><?= htmlspecialchars($sub['plan_name']) ?> · expires <?= date('d M Y', strtotime($sub['expires_at'])) ?> · Full quiz access unlocked</div>
            </div>
        </div>

        <!-- View switcher: Quiz or Q&A -->
        <div class="view-switcher fade d2" id="viewSwitcher">
            <button class="view-btn active" id="btnViewQuiz" onclick="switchView('quiz')">
                 Quiz Practice
            </button>
            <button class="view-btn" id="btnViewQA" onclick="switchView('qa')">
                 Q&amp;A Library
                <span class="nav-badge" style="margin-left:4px"><?= $totalQs ?></span>
            </button>
        </div>

        <!-- ── QUIZ VIEW ── -->
        <div id="viewQuiz">
            <!-- Category tabs -->
            <div class="quiz-tabs fade d3" id="quizTabs">
                <button class="quiz-tab active" data-cat="math"     onclick="loadCat('math')">📐 Math</button>
                <button class="quiz-tab"         data-cat="english"  onclick="loadCat('english')">📖 English</button>
                <button class="quiz-tab"         data-cat="general"  onclick="loadCat('general')">🌐 General</button>
                <button class="quiz-tab"         data-cat="past"     onclick="loadCat('past')">📜 Past Papers</button>
                <button class="quiz-tab"         data-cat="physical" onclick="loadCat('physical')">🏋️ Physical</button>
            </div>

            <!-- Quiz card -->
            <div class="quiz-arena fade d4" id="quizArena">
                <!-- top bar -->
                <div class="quiz-topbar">
                    <div class="quiz-cat-pill pill-math" id="catPill">📐 Mathematics</div>
                    <div class="quiz-progress-wrap">
                        <span class="q-count">Q <b id="qNum">1</b>/<b id="qTot">10</b></span>
                        <div class="q-prog-bar"><div class="q-prog-fill" id="qProgFill" style="width:10%"></div></div>
                    </div>
                    <div class="live-score">
                        <span>✅ <b id="liveCorrect">0</b></span>
                        <span>❌ <b id="liveWrong">0</b></span>
                    </div>
                </div>

                <!-- question -->
                <div class="quiz-body" id="quizBody">
                    <div class="q-num-tag">
                        <div class="q-num-box" id="qNumBox">1</div>
                        Question
                    </div>
                    <div class="q-text" id="qText">Loading…</div>
                    <div class="options" id="optGrid"></div>
                    <div class="feedback" id="feedback"></div>
                </div>

                <!-- quiz end screen -->
                <div class="quiz-end" id="quizEnd">
                    <span class="end-trophy" id="endEmoji">🏆</span>
                    <div class="end-score-ring" id="endRing">0%</div>
                    <div class="end-grade" id="endGrade">Quiz Complete!</div>
                    <div class="end-sub" id="endSub"></div>
                    <div class="end-actions">
                        <button class="btn-restart" onclick="restartCat()">🔄 Retry</button>
                        <button class="btn-next-cat" onclick="nextCat()">Next Category →</button>
                    </div>
                </div>

                <!-- footer -->
                <div class="quiz-footer" id="quizFooter">
                    <div class="quiz-hint" id="qHint">Choose the best answer</div>
                    <button class="btn-next" id="btnNext" disabled onclick="nextQ()">
                        Next <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>

            <!-- Analytics -->
            <div class="analytics fade d4" id="analyticsBox">
                <div class="analytics-title">
                    📊 Your Progress Report
                    <span id="analyticsMeta">Answer questions to track performance</span>
                </div>
                <div class="stat-grid">
                    <div class="stat-box s-correct"><div class="stat-val" id="aCorrect">0</div><div class="stat-lbl">Correct</div></div>
                    <div class="stat-box s-wrong">  <div class="stat-val" id="aWrong">0</div>  <div class="stat-lbl">Wrong</div></div>
                    <div class="stat-box s-pct">    <div class="stat-val" id="aPct">—</div>    <div class="stat-lbl">Accuracy</div></div>
                    <div class="stat-box s-streak"> <div class="stat-val" id="aStreak">0</div> <div class="stat-lbl">Best Streak</div></div>
                </div>
                <div class="cat-breakdown">
                    <div class="cat-breakdown-title">Category Breakdown</div>
                    <div id="catRows"><div class="empty-analytics">Start answering questions to see your breakdown here.</div></div>
                </div>
            </div>
        </div><!-- /viewQuiz -->

        <!-- ── Q&A LIBRARY VIEW ── -->
        <div id="viewQA" style="display:none">
            <div class="sec-header fade d2">
                <div class="sec-title">Q&amp;A Library</div>
                <span class="sec-badge"><?= $totalQs ?> for <?= htmlspecialchars($forceName) ?></span>
            </div>
            <div class="note fade d2">
                Select a question to expand the full expert answer. All content is tailored to <?= htmlspecialchars($forceName) ?>.
            </div>
            <?php if (empty($allQs)): ?>
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:2.5rem;text-align:center;color:var(--t3);font-size:.82rem;line-height:1.65">
                    No text questions available yet for <?= htmlspecialchars($forceName) ?>.<br>Content is updated regularly — check back soon.
                </div>
            <?php else: ?>
                <?php
                // Group by category
                $grouped = [];
                foreach ($allQs as $q) $grouped[$q['category']][] = $q;
                $qNum = 0;
                foreach ($grouped as $cat => $qs): ?>
                    <div class="cat-label fade d2"><?= htmlspecialchars($cat) ?></div>
                    <div class="q-list fade d3">
                    <?php foreach ($qs as $q): $qNum++; ?>
                        <div class="q-card" id="qc-<?= (int)$q['id'] ?>">
                            <div class="q-header" onclick="toggleQ(<?= (int)$q['id'] ?>)">
                                <div class="q-num"><?= $qNum ?></div>
                                <div class="q-text2"><?= htmlspecialchars($q['question_text']) ?></div>
                                <div class="q-chevron">&#9660;</div>
                            </div>
                            <div class="q-body2">
                                <?php if (!empty($q['answer_text'])): ?>
                                    <?= nl2br(htmlspecialchars($q['answer_text'])) ?>
                                <?php else: ?>
                                    <span style="color:var(--t3);font-style:italic">Answer will be available soon.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div><!-- /viewQA -->

    <?php else: ?>
    <!-- ═══════════════════════ FREE USER: PAYWALL ═══════════════════════ -->

        <div class="fade d1">
            <!-- Blurred previews -->
            <?php if (!empty($allQs)): ?>
            <div class="sec-header">
                <div class="sec-title">Preview</div>
                <span class="sec-badge"><?= $totalQs ?> questions locked</span>
            </div>
            <?php foreach (array_slice($allQs, 0, 3) as $q): ?>
            <div class="q-preview">
                <div class="q-preview-header">
                    <div class="q-preview-lock">
                        <svg width="13" height="13" fill="none" stroke="#fbbf24" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div class="q-preview-text"><?= htmlspecialchars($q['question_text']) ?></div>
                </div>
                <div class="q-preview-body">
                    (A) Option one &nbsp; (B) Option two &nbsp; (C) Option three &nbsp; (D) Option four —
                    Subscribe to unlock the answer, full quiz practice, and detailed progress tracking tailored to <?= htmlspecialchars($forceName) ?>.
                    Expert explanations and instant feedback included with every plan.
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Paywall card -->
            <div class="paywall" style="margin-top:1.25rem">
                <div style="font-size:2.5rem;margin-bottom:.75rem">🔐</div>
                <div class="paywall-title">Subscribe to Access Questions &amp; Quizzes</div>
                <div class="paywall-desc">
                    Get expert answers + hundreds of MCQs across Math, English, General Knowledge,
                    Past Papers &amp; Physical Tests — with instant feedback and a personal analytics dashboard tailored to <?= htmlspecialchars($forceName) ?>.
                </div>
                <?php if (!empty($plans)): ?>
                <div class="plans-grid">
                    <?php foreach ($plans as $plan):
                        $isQ = ($plan['slug'] === 'quarterly'); ?>
                    <a href="subscription_fixed.php?plan=<?= htmlspecialchars($plan['slug'], ENT_QUOTES) ?>" class="plan-card <?= $isQ ? 'featured' : '' ?>">
                        <?php if ($isQ): ?><div class="plan-best">Best Value</div><?php endif; ?>
                        <div class="plan-price">Rs <?= number_format((float)$plan['price'], 0) ?><small>/<?= $isQ?'3mo':'mo' ?></small></div>
                        <div class="plan-name"><?= htmlspecialchars($plan['name']) ?></div>
                        <div class="plan-duration"><?= (int)$plan['duration_days'] ?> days access</div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <a href="subscription_fixed.php" class="btn-ghost btn-gold" style="font-size:.85rem;padding:.7rem 2rem;margin-bottom:.75rem">
                    ⭐ Subscribe via eSewa
                </a>
                <div class="paywall-note">
                    Instant access after payment &nbsp;·&nbsp; Secured by eSewa &nbsp;·&nbsp; Cancel anytime
                </div>
            </div>
        </div>

    <?php endif; ?>

    </div><!-- /page -->
</div><!-- /main -->

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">&#9776;</button>

<script>
// ── PHP data ──────────────────────────────────────────────────────────────
const IS_PREMIUM        = <?= json_encode($isPremium) ?>;
const SAVED_AVATAR_TYPE = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL   = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CFG  = <?= $avatarConfigJson ?>;
const USER_INITIAL      = <?= json_encode($initials) ?>;

// ── Avatar (same logic as original questions.php) ─────────────────────────
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
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)             || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o=>o.id===state.skin)         || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor)|| AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o=>o.id===state.eyes)         || AV_OPTIONS.eyes[0];
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
        for(let a=0;a<Math.PI*2;a+=0.35){ const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24; if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}}
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
    const c = document.getElementById('sbAvContainer'); if(!c) return; c.innerHTML='';
    if (SAVED_AVATAR_TYPE==='photo' && SAVED_PHOTO_URL) {
        const img=document.createElement('img');
        img.src=SAVED_PHOTO_URL;
        img.style.cssText='width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror=()=>renderInitialSb(c);
        c.appendChild(img);
    } else if (SAVED_AVATAR_TYPE==='ai' && SAVED_AVATAR_CFG) {
        const canvas=document.createElement('canvas');
        canvas.style.cssText='width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CFG, 38);
    } else { renderInitialSb(c); }
}
function renderInitialSb(c) {
    const d=document.createElement('div');
    d.style.cssText='width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent=USER_INITIAL;
    c.appendChild(d);
}

// ── View switcher ─────────────────────────────────────────────────────────
function switchView(v) {
    document.getElementById('viewQuiz').style.display = v==='quiz' ? '' : 'none';
    document.getElementById('viewQA').style.display   = v==='qa'   ? '' : 'none';
    document.getElementById('btnViewQuiz').classList.toggle('active', v==='quiz');
    document.getElementById('btnViewQA').classList.toggle('active',   v==='qa');
}

// ── Q&A accordion ─────────────────────────────────────────────────────────
function toggleQ(id) {
    const card = document.getElementById('qc-' + id); if (!card) return;
    const wasOpen = card.classList.contains('open');
    document.querySelectorAll('.q-card.open').forEach(c=>c.classList.remove('open'));
    if (!wasOpen) card.classList.add('open');
}

// ════════════════════════════════════════════════════════════════════════
// ── QUIZ ENGINE ──────────────────────────────────────────────────────
// ════════════════════════════════════════════════════════════════════════
if (IS_PREMIUM) {

const CATS = ['math','english','general','past','physical'];

const CAT_META = {
    math:     { label:'📐 Mathematics',      pillClass:'pill-math',     barClass:'bar-math',     icon:'📐' },
    english:  { label:'📖 English',           pillClass:'pill-english',  barClass:'bar-english',  icon:'📖' },
    general:  { label:'🌐 General Knowledge', pillClass:'pill-general',  barClass:'bar-general',  icon:'🌐' },
    past:     { label:'📜 Past Papers',        pillClass:'pill-past',     barClass:'bar-past',     icon:'📜' },
    physical: { label:'🏋️ Physical',          pillClass:'pill-physical', barClass:'bar-physical', icon:'🏋️' },
};

const BANK = {
    math: [
        { q:'What is 15% of 240?',                           opts:['34','36','38','32'],                                             ans:1, exp:'15% × 240 = 0.15 × 240 = 36' },
        { q:'√169 = ?',                                       opts:['11','12','13','14'],                                             ans:2, exp:'13 × 13 = 169, so √169 = 13' },
        { q:'If 3x + 7 = 22, what is x?',                    opts:['3','4','5','6'],                                                 ans:2, exp:'3x = 22 − 7 = 15 → x = 5' },
        { q:'A train covers 360 km in 4 hours. What is its speed?', opts:['80 km/h','90 km/h','100 km/h','70 km/h'],               ans:1, exp:'Speed = 360 ÷ 4 = 90 km/h' },
        { q:'LCM of 12 and 18?',                             opts:['24','36','48','54'],                                             ans:1, exp:'LCM(12, 18) = 36' },
        { q:'Simple interest: P=Rs 1000, R=5%, T=3 years?',  opts:['Rs 100','Rs 150','Rs 200','Rs 125'],                            ans:1, exp:'SI = (1000 × 5 × 3) / 100 = Rs 150' },
        { q:'If a : b = 3 : 4, and b = 20, find a.',         opts:['12','14','15','16'],                                             ans:2, exp:'a = (3/4) × 20 = 15' },
        { q:'Area of a circle with radius 7 cm? (π ≈ 22/7)', opts:['154 cm²','144 cm²','164 cm²','132 cm²'],                        ans:0, exp:'A = πr² = (22/7) × 49 = 154 cm²' },
        { q:'What is 2³ × 3²?',                              opts:['64','72','54','48'],                                             ans:1, exp:'2³ = 8, 3² = 9 → 8 × 9 = 72' },
        { q:'A number increased by 20% becomes 96. Original?',opts:['80','76','82','84'],                                            ans:0, exp:'x × 1.20 = 96 → x = 80' },
    ],
    english: [
        { q:'Correct synonym of "Valiant":',                  opts:['Cowardly','Brave','Weak','Timid'],                               ans:1, exp:'"Valiant" means brave and courageous.' },
        { q:'Select the correctly spelled word:',             opts:['Accomodate','Accommodate','Acomodate','Accommodat'],             ans:1, exp:'"Accommodate" has double c and double m.' },
        { q:'She _____ to the market yesterday.',             opts:['go','goes','went','gone'],                                       ans:2, exp:'Past simple of "go" is "went".' },
        { q:'Which word is a conjunction?',                   opts:['Quickly','Although','Beautiful','Jump'],                         ans:1, exp:'"Although" is a subordinating conjunction.' },
        { q:'Antonym of "Abundant":',                         opts:['Plentiful','Scarce','Many','Ample'],                             ans:1, exp:'"Abundant" = plentiful; antonym = "Scarce".' },
        { q:'Passive voice of "She writes a letter."',        opts:['A letter is wrote by her','A letter is written by her','A letter was written by her','A letter has written by her'],ans:1,exp:'Present passive: "A letter is written by her."'},
        { q:'One-word substitute for "Fear of darkness":',    opts:['Acrophobia','Nyctophobia','Claustrophobia','Hydrophobia'],       ans:1, exp:'Nyctophobia is the fear of darkness.' },
        { q:'Fill in: The team _____ won the match.',         opts:['have','has','had','is'],                                         ans:1, exp:'"Team" is a collective singular noun → "has".' },
        { q:'Plural of "Criterion":',                         opts:['Criterions','Criteria','Criterias','Criteriums'],                ans:1, exp:'"Criteria" is the correct Greek-origin plural.' },
        { q:'Identify the adjective: "The brave soldier fought."', opts:['The','brave','soldier','fought'],                          ans:1, exp:'"Brave" modifies "soldier" — it is an adjective.' },
    ],
    general: [
        { q:'Capital of Nepal?',                              opts:['Pokhara','Lalitpur','Kathmandu','Biratnagar'],                   ans:2, exp:'Kathmandu is the capital and largest city of Nepal.' },
        { q:'Highest mountain in the world?',                 opts:['K2','Kangchenjunga','Lhotse','Mount Everest'],                  ans:3, exp:'Mount Everest (8,848.86 m) is the world\'s highest peak.' },
        { q:'Nepal shares its border with how many countries?',opts:['1','2','3','4'],                                               ans:1, exp:'Nepal borders India (south/east/west) and China (north) — 2 countries.' },
        { q:'National flower of Nepal?',                      opts:['Rose','Lotus','Rhododendron','Marigold'],                       ans:2, exp:'Rhododendron (Laliguras) is Nepal\'s national flower.' },
        { q:'DNA stands for:',                                opts:['Deoxyribonucleic Acid','Dioxynucleic Acid','Dinucleic Acid','Diribonucleic Acid'],ans:0,exp:'DNA = Deoxyribonucleic Acid.' },
        { q:'First persons to summit Everest:',               opts:['Messner & Habeler','Hillary & Tenzing','Mallory & Irvine','Hillary & Hillary'],ans:1,exp:'Edmund Hillary and Tenzing Norgay, 29 May 1953.' },
        { q:'Currency of Nepal?',                             opts:['Rupee','Taka','Dinar','Yuan'],                                  ans:0, exp:'Nepalese Rupee (NPR) is the official currency.' },
        { q:'Which organ purifies blood?',                    opts:['Heart','Lungs','Kidney','Liver'],                               ans:2, exp:'Kidneys filter waste from blood, excreting it as urine.' },
        { q:'How many planets in our solar system?',          opts:['7','8','9','10'],                                               ans:1, exp:'8 planets: Mercury, Venus, Earth, Mars, Jupiter, Saturn, Uranus, Neptune.' },
        { q:'Speed of light (approximate):',                  opts:['3×10⁵ km/s','3×10⁶ km/s','3×10⁴ km/s','3×10³ km/s'],         ans:0, exp:'Speed of light ≈ 3 × 10⁵ km/s (300,000 km/s).' },
    ],
    past: [
        { q:'Nepal Army was established in which year (BS)?', opts:['1744 BS','1776 BS','1853 BS','1860 BS'],                        ans:0, exp:'Nepal Army was formally established in 1744 BS.' },
        { q:'Equivalent of "General" rank in Nepal Army:',    opts:['Sahid','Rathiprashad','Maharathi','Pratap'],                   ans:2, exp:'"Maharathi" is equivalent to the rank of General.' },
        { q:'Accepted BMI range for Nepal Army selection:',   opts:['18.5–22','18.5–24.9','17–25','20–26'],                         ans:1, exp:'BMI 18.5–24.9 is the standard healthy acceptance range.' },
        { q:'Minimum height for Nepal Army male recruits:',   opts:['5\'2"','5\'3"','5\'4"','5\'5"'],                               ans:1, exp:'Minimum height: 5 feet 3 inches (≈160 cm).' },
        { q:'Nepal Army Day is celebrated on:',               opts:['Falgun 19','Falgun 20','Falgun 21','Falgun 22'],                ans:0, exp:'Nepal Army Day falls on Falgun 19 each year.' },
        { q:'CBRN stands for:',                               opts:['Chemical Biological Radiological Nuclear','Combat Battle Ready Network','Central Base Recon Network','Crisis Battle Resource Node'],ans:0,exp:'CBRN = Chemical, Biological, Radiological, Nuclear.' },
        { q:'The physical run test distance is:',             opts:['1.5 km','2 km','2.4 km','3 km'],                               ans:2, exp:'The standard run test is 2.4 km (1.5 miles).' },
        { q:'Supreme Commander of Nepal Army:',               opts:['Prime Minister','Defence Minister','President','Army Chief'],   ans:2, exp:'The President of Nepal is the Supreme Commander.' },
        { q:'Minimum push-ups required in Nepal Army test:',  opts:['20','25','30','35'],                                           ans:2, exp:'30 push-ups in 2 minutes is the standard benchmark.' },
        { q:'Nepal Army\'s official motto:',                   opts:['Jay Nepal','Sarva Shakti Man','Vijay','Amar Rahe'],            ans:1, exp:'"Sarva Shakti Man" (All Mighty) is Nepal Army\'s motto.' },
    ],
    physical: [
        { q:'Which exercise primarily targets the chest?',    opts:['Squat','Deadlift','Push-up','Pull-up'],                        ans:2, exp:'Push-ups target pectorals, triceps, and front deltoids.' },
        { q:'Good 2.4 km run target time for fitness:',       opts:['15 min','12 min','10 min','8 min'],                            ans:2, exp:'Under 10 minutes is a solid fitness benchmark.' },
        { q:'Vitamin produced when skin is exposed to sun:',  opts:['Vitamin A','Vitamin C','Vitamin D','Vitamin K'],               ans:2, exp:'UVB radiation triggers Vitamin D synthesis in skin.' },
        { q:'Calories per gram of protein:',                  opts:['4 kcal','7 kcal','9 kcal','2 kcal'],                          ans:0, exp:'Protein provides 4 kcal/g (same as carbohydrates).' },
        { q:'Ideal daily water intake for an adult:',         opts:['1 L','1.5 L','2 L','2.5–3 L'],                               ans:3, exp:'2.5–3 litres/day is recommended; more during exercise.' },
        { q:'Which stretch best targets the hamstrings?',     opts:['Quad stretch','Calf raise','Seated forward bend','Hip flexor stretch'],ans:2,exp:'Seated forward bends directly lengthen the hamstrings.' },
        { q:'Normal resting heart rate for a fit adult:',     opts:['40–50 bpm','60–100 bpm','100–120 bpm','50–60 bpm'],           ans:1, exp:'Normal resting HR = 60–100 bpm; athletes may be 40–60 bpm.' },
        { q:'Primary energy source for the body:',            opts:['Protein','Fat','Carbohydrate','Fibre'],                        ans:2, exp:'Carbohydrates convert to glucose — the body\'s primary fuel.' },
        { q:'DOMS stands for:',                               opts:['Delayed Onset Muscle Soreness','Direct Oxygen Muscle System','Daily Output Muscle Strength','Defined Output Muscle Set'],ans:0,exp:'DOMS = Delayed Onset Muscle Soreness, felt 12–72h after training.' },
        { q:'Good pull-up benchmark for military fitness:',   opts:['5','8','10','15'],                                            ans:2, exp:'10 pull-ups is a solid military fitness standard.' },
    ],
};

// ── State ──────────────────────────────────────────────────────────────
let currentCat = 'math';
let qIdx       = 0;
let answered   = false;

const stats = {};
CATS.forEach(c => { stats[c] = { correct:0, wrong:0, streak:0, best:0, answered:0 }; });

// ── Load category ──────────────────────────────────────────────────────
function loadCat(cat) {
    currentCat = cat; qIdx = 0;
    // tabs
    document.querySelectorAll('.quiz-tab').forEach(t => t.classList.toggle('active', t.dataset.cat === cat));
    // pill
    const m = CAT_META[cat];
    const pill = document.getElementById('catPill');
    pill.textContent = m.label;
    pill.className = 'quiz-cat-pill ' + m.pillClass;
    // reset end screen
    document.getElementById('quizEnd').classList.remove('show');
    document.getElementById('quizBody').style.display  = '';
    document.getElementById('quizFooter').style.display = '';
    showQuestion();
}

function showQuestion() {
    const q     = BANK[currentCat][qIdx];
    const total = BANK[currentCat].length;
    answered    = false;

    // progress
    document.getElementById('qNum').textContent      = qIdx + 1;
    document.getElementById('qTot').textContent      = total;
    document.getElementById('qNumBox').textContent   = qIdx + 1;
    document.getElementById('qProgFill').style.width = ((qIdx+1)/total*100) + '%';
    document.getElementById('qText').textContent     = q.q;
    document.getElementById('btnNext').disabled      = true;
    document.getElementById('qHint').textContent     = 'Choose the best answer';

    // clear feedback
    const fb = document.getElementById('feedback');
    fb.className = 'feedback'; fb.innerHTML = '';

    // build options
    const grid = document.getElementById('optGrid'); grid.innerHTML = '';
    ['A','B','C','D'].forEach((letter, i) => {
        const btn = document.createElement('button');
        btn.className = 'opt';
        btn.innerHTML = `<span class="opt-letter">${letter}</span><span style="flex:1">${q.opts[i]}</span><span class="opt-icon" id="oi-${i}"></span>`;
        btn.onclick = () => pickAnswer(i);
        grid.appendChild(btn);
    });

    updateLiveScore();
}

function pickAnswer(chosen) {
    if (answered) return;
    answered = true;
    const q  = BANK[currentCat][qIdx];
    const st = stats[currentCat];
    const btns = document.querySelectorAll('.opt');

    btns.forEach((b,i) => {
        b.disabled = true;
        if (i === q.ans) { b.classList.add('correct'); document.getElementById('oi-'+i).textContent='✓'; }
    });

    const fb = document.getElementById('feedback');
    if (chosen === q.ans) {
        btns[chosen].classList.add('correct');
        st.correct++; st.streak++; st.answered++;
        if (st.streak > st.best) st.best = st.streak;
        fb.className = 'feedback fb-correct show';
        fb.innerHTML = '<div class="feedback-label">✅ Correct!</div>' + q.exp;
        document.getElementById('qHint').textContent = '✓ Well done!';
    } else {
        btns[chosen].classList.add('wrong');
        document.getElementById('oi-'+chosen).textContent = '✗';
        st.wrong++; st.streak=0; st.answered++;
        fb.className = 'feedback fb-wrong show';
        fb.innerHTML = '<div class="feedback-label">❌ Incorrect</div>' + q.exp;
        document.getElementById('qHint').textContent = 'Review the explanation above';
    }

    document.getElementById('btnNext').disabled = false;
    updateLiveScore();
    updateAnalytics();
}

function nextQ() {
    qIdx++;
    if (qIdx >= BANK[currentCat].length) { showEnd(); return; }
    showQuestion();
}

function showEnd() {
    document.getElementById('quizBody').style.display   = 'none';
    document.getElementById('quizFooter').style.display = 'none';
    const st  = stats[currentCat];
    const pct = st.answered ? Math.round(st.correct/st.answered*100) : 0;
    let emoji='🏆', grade='Outstanding!';
    if (pct<40)      { emoji='😤'; grade='Keep going — practice makes perfect!'; }
    else if (pct<60) { emoji='💪'; grade='Good effort — review your wrong answers!'; }
    else if (pct<80) { emoji='🌟'; grade='Great performance!'; }
    else if (pct<100){ emoji='🎯'; grade='Excellent work!'; }

    document.getElementById('endEmoji').textContent = emoji;
    document.getElementById('endRing').textContent  = pct + '%';
    document.getElementById('endGrade').textContent = grade;
    document.getElementById('endSub').textContent   = `You answered ${st.correct} of ${st.answered} questions correctly in ${CAT_META[currentCat].label}.`;
    document.getElementById('quizEnd').classList.add('show');
    updateAnalytics();
}

function restartCat() {
    const st = stats[currentCat];
    st.correct=0; st.wrong=0; st.streak=0; st.best=0; st.answered=0;
    qIdx=0;
    document.getElementById('quizEnd').classList.remove('show');
    document.getElementById('quizBody').style.display   = '';
    document.getElementById('quizFooter').style.display = '';
    showQuestion();
}

function nextCat() {
    const idx  = CATS.indexOf(currentCat);
    const next = CATS[(idx+1) % CATS.length];
    loadCat(next);
}

function updateLiveScore() {
    let tc=0, tw=0;
    CATS.forEach(c => { tc+=stats[c].correct; tw+=stats[c].wrong; });
    document.getElementById('liveCorrect').textContent = tc;
    document.getElementById('liveWrong').textContent   = tw;
    document.getElementById('aCorrect').textContent    = tc;
    document.getElementById('aWrong').textContent      = tw;
}

function updateAnalytics() {
    // overall
    let tc=0,tw=0,bs=0;
    CATS.forEach(c => { tc+=stats[c].correct; tw+=stats[c].wrong; bs=Math.max(bs,stats[c].best); });
    const tot = tc+tw;
    const pct = tot ? Math.round(tc/tot*100) : null;
    document.getElementById('aPct').textContent    = pct!==null ? pct+'%' : '—';
    document.getElementById('aStreak').textContent = bs;
    if (tot) document.getElementById('analyticsMeta').textContent = `${tot} questions answered · ${tc} correct`;

    // category rows
    const rows = document.getElementById('catRows');
    const answered = CATS.filter(c => stats[c].answered > 0);
    if (!answered.length) { rows.innerHTML='<div class="empty-analytics">Start answering questions to see your breakdown here.</div>'; return; }
    rows.innerHTML = answered.map(cat => {
        const s   = stats[cat];
        const tot = s.correct + s.wrong;
        const p   = tot ? Math.round(s.correct/tot*100) : 0;
        const m   = CAT_META[cat];
        return `
            <div class="cat-row">
                <div class="cat-row-icon">${m.icon}</div>
                <div class="cat-row-name">${m.label.replace(/^[^\s]+\s/,'')}</div>
                <div class="cat-bar"><div class="cat-bar-inner ${m.barClass}" style="width:${p}%"></div></div>
                <div class="cat-row-stat">${s.correct}/${tot} · ${p}%</div>
            </div>`;
    }).join('');
}

// ── Boot ──────────────────────────────────────────────────────────────
loadCat('math');
updateAnalytics();

} // end IS_PREMIUM block

// ── Sidebar close on mobile ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    document.addEventListener('click', e => {
        const sb  = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth<=768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target))
            sb.classList.remove('active');
    });
});
</script>
</body>
</html>