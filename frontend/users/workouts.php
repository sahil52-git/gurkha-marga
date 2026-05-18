<?php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}
$userId = (int)$_SESSION['user_id'];
$user   = fetchOne('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$firstName  = explode(' ', trim($user['full_name']))[0];
$initials   = strtoupper(substr($user['full_name'], 0, 1));
$forceKey   = strtolower($user['target_force'] ?? 'british');
$expKey     = strtolower($user['experience_level'] ?? 'beginner');

$forceMap = [
    'british'   => 'British Army',
    'nepal'     => 'Nepal Army',
    'indian'    => 'Indian Army',
    'singapore' => 'Singapore Police Force',
    'french'    => 'French Foreign Legion',
];
$forceName = $forceMap[$forceKey] ?? ucfirst($forceKey);

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$workoutsRaw = fetchAll(
    "SELECT * FROM workouts
     WHERE is_published = 1
       AND (user_id = 0 OR user_id = ?)
     ORDER BY category, created_at ASC",
    [$userId]
);

$exercises = [];
foreach ($workoutsRaw as $w) {
    $steps   = json_decode($w['steps_json']   ?? '[]', true) ?: [];
    $muscles = json_decode($w['muscles_json'] ?? '[]', true) ?: [];
    $tips    = json_decode($w['tips_json']    ?? '[]', true) ?: [];

    $cleanSteps = [];
    foreach ($steps as $s) {
        $cleanSteps[] = [
            'title' => $s['title'] ?? '',
            'desc'  => $s['desc']  ?? '',
        ];
    }
    $cleanMuscles = [];
    foreach ($muscles as $m) {
        $cleanMuscles[] = [
            'name'  => $m['name']  ?? '',
            'type'  => $m['type']  ?? 'Primary',
            'color' => $m['color'] ?? '#3b82f6',
        ];
    }
    $cleanTips = [];
    foreach ($tips as $t) {
        $cleanTips[] = [
            'text' => $t['text'] ?? '',
        ];
    }

    $thumbFile = !empty($w['thumb_image'])
        ? '/gurkha-marga/frontend/uploads/workout_thumbs/' . $w['thumb_image']
        : null;

    $exercises[] = [
        'id'          => (int)$w['id'],
        'glb'         => $w['glb_file']       ?? '',
        'genre'       => $w['category']       ?? 'general',
        'title'       => $w['title'],
        'duration'    => $w['duration_label'] ?: ($w['duration_minutes'] . ' min'),
        'difficulty'  => $w['difficulty']     ?? 'intermediate',
        'description' => $w['description']    ?? '',
        'muscles'     => $cleanMuscles,
        'steps'       => $cleanSteps,
        'tips'        => $cleanTips,
        'thumb'       => $thumbFile,
    ];
}

$exercisesJson = json_encode($exercises, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$totalCount    = count($exercises);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Workouts — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:         #3b82f6;
    --gold:           #fbbf24;
    --success:        #10b981;
    --danger:         #ef4444;
    --text-primary:   #f8fafc;
    --text-secondary: #cbd5e1;
    --text-muted:     #64748b;
    --sidebar-bg:     rgba(15, 23, 42, 0.97);
    --card-bg:        rgba(30, 41, 59, 0.8);
    --hover-bg:       rgba(59, 130, 246, 0.08);
    --input-bg:       rgba(15, 23, 42, 0.6);
    --border:         rgba(255, 255, 255, 0.07);
    --border-hi:      rgba(255, 255, 255, 0.12);
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--text-primary);
    min-height: 100vh;
}

/* ── SIDEBAR ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: var(--sidebar-bg);
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
    border-radius: 12px; padding: .85rem; display: flex; align-items: center; gap: 10px;
}
.sidebar-av {
    width: 38px; height: 38px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
    border: 1.5px solid rgba(59, 130, 246, 0.35);
    display: flex; align-items: center; justify-content: center;
}
.sidebar-av img { width: 100%; height: 100%; object-fit: cover; }
.user-details h3 { font-size: .85rem; font-weight: 600; }
.user-details p  { font-size: .7rem; color: var(--gold); margin-top: 1px; }
.nav-menu { padding: 1.25rem 0; }
.nav-section-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    color: rgba(203, 213, 225, 0.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--text-secondary); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover-bg); color: var(--text-primary); border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239,68,68,.08); border-left-color: #ef4444; }

/* ── MAIN ── */
.main-content { margin-left: 260px; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(15, 23, 42, 0.92);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    padding: .9rem 2rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
}
.topbar-left { display: flex; align-items: center; gap: .75rem; }
.breadcrumb { font-size: .72rem; color: var(--text-muted); display: flex; align-items: center; gap: .4rem; }
.breadcrumb a { color: var(--text-secondary); text-decoration: none; }
.breadcrumb a:hover { color: var(--text-primary); }
.topbar-title { font-size: 1rem; font-weight: 700; }
.topbar-right { display: flex; align-items: center; gap: .6rem; }

/* ── FILTER BAR ── */
.filter-bar {
    background: rgba(30, 41, 59, 0.6);
    border-bottom: 1px solid var(--border);
    padding: 1rem 2rem;
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.filter-label { font-size: .72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; white-space: nowrap; }

.cat-dropdown-wrap { position: relative; }
.cat-btn {
    display: flex; align-items: center; gap: .5rem;
    padding: .45rem 1rem; border-radius: 8px;
    background: rgba(255,255,255,.06); border: 1px solid var(--border-hi);
    color: var(--text-secondary); font-size: .82rem; font-weight: 500;
    font-family: 'Poppins', sans-serif; cursor: pointer; transition: all .15s;
    white-space: nowrap;
}
.cat-btn:hover { background: rgba(255,255,255,.1); color: var(--text-primary); }
.cat-btn.active-filter { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); color: #93c5fd; }
.cat-btn svg { width: 13px; height: 13px; transition: transform .2s; }
.cat-btn.open svg { transform: rotate(180deg); }

.cat-dropdown {
    position: absolute; top: calc(100% + 6px); left: 0; z-index: 200;
    background: #1e293b; border: 1px solid var(--border-hi);
    border-radius: 10px; padding: .4rem; min-width: 200px;
    display: none; flex-direction: column; gap: 1px;
    box-shadow: 0 16px 40px rgba(0,0,0,.5);
}
.cat-dropdown.open { display: flex; animation: dropIn .15s ease; }
@keyframes dropIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }

.cat-option {
    padding: .5rem .75rem; border-radius: 7px; font-size: .8rem; font-weight: 500;
    color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: space-between;
    transition: all .12s;
}
.cat-option:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }
.cat-option.selected { background: rgba(59,130,246,.15); color: #93c5fd; }
.cat-option-count { font-size: .68rem; color: var(--text-muted); background: rgba(255,255,255,.05); padding: .1rem .45rem; border-radius: 20px; }

.diff-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
.diff-pill {
    padding: .35rem .75rem; border-radius: 20px; font-size: .75rem; font-weight: 500;
    border: 1px solid var(--border); background: transparent;
    color: var(--text-muted); cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: all .15s;
}
.diff-pill:hover { color: var(--text-secondary); border-color: var(--border-hi); }
.diff-pill.active { color: #fff; border-color: transparent; }
.diff-pill[data-diff="all"].active        { background: #3b82f6; }
.diff-pill[data-diff="beginner"].active   { background: #10b981; }
.diff-pill[data-diff="intermediate"].active { background: #f59e0b; }
.diff-pill[data-diff="advanced"].active   { background: #ef4444; }

.search-wrap { position: relative; }
.search-wrap svg { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none; }
.search-input {
    padding: .45rem .85rem .45rem 2.2rem;
    background: rgba(255,255,255,.06); border: 1px solid var(--border-hi);
    border-radius: 8px; color: var(--text-primary); font-size: .82rem;
    font-family: 'Poppins', sans-serif; width: 220px; transition: all .15s;
}
.search-input:focus { outline: none; border-color: rgba(59,130,246,.4); background: rgba(255,255,255,.09); }
.search-input::placeholder { color: var(--text-muted); }
.filter-results { font-size: .75rem; color: var(--text-muted); margin-left: auto; white-space: nowrap; }
.filter-results strong { color: var(--text-secondary); }

/* ── PAGE ── */
.page { padding: 1.5rem 2rem 3rem; }

.section-group { margin-bottom: 2.5rem; }
.group-header {
    display: flex; align-items: center; gap: .75rem;
    margin-bottom: 1.1rem; padding-bottom: .75rem;
    border-bottom: 1px solid var(--border);
}
.group-name { font-size: .78rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--text-secondary); }
.group-line { color: var(--text-muted); font-size: .7rem; }
.group-count {
    margin-left: auto; font-size: .68rem; font-weight: 600; color: var(--text-muted);
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    border-radius: 20px; padding: .15rem .6rem;
}

/* ── EXERCISES GRID ── */
.exercises-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: .9rem;
}

/* ── EXERCISE CARD ── */
.ex-card {
    background: var(--card-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; cursor: pointer;
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
    display: flex; flex-direction: column;
}
.ex-card:hover {
    transform: translateY(-3px);
    border-color: var(--border-hi);
    box-shadow: 0 12px 32px rgba(0,0,0,.4);
}

.card-thumb {
    height: 170px; background: rgba(15,23,42,.8);
    position: relative; overflow: hidden; flex-shrink: 0;
}
.card-thumb canvas { position: absolute; inset: 0; width: 100% !important; height: 100% !important; display: block; }
.card-thumb-img {
    position: absolute; inset: 0; width: 100%; height: 100%;
    object-fit: cover; z-index: 1;
}
.thumb-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(160deg, transparent 45%, rgba(15,23,42,.7) 100%);
    z-index: 2; pointer-events: none;
}
.thumb-play {
    position: absolute; bottom: .75rem; right: .75rem; z-index: 3;
    width: 36px; height: 36px; border-radius: 50%;
    background: rgba(59,130,246,.85); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    transition: transform .15s, background .15s;
}
.ex-card:hover .thumb-play { transform: scale(1.1); background: var(--accent); }
.thumb-play svg { width: 13px; height: 13px; fill: #fff; margin-left: 2px; }

.diff-badge {
    position: absolute; top: .65rem; left: .65rem; z-index: 3;
    font-size: .6rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    padding: .18rem .5rem; border-radius: 4px; border: 1px solid currentColor;
}
.diff-badge.beginner     { color: #6ee7b7; background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.3); }
.diff-badge.intermediate { color: #fcd34d; background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.3); }
.diff-badge.advanced     { color: #fca5a5; background: rgba(239,68,68,.15);  border-color: rgba(239,68,68,.3); }
.diff-badge.expert       { color: #d8b4fe; background: rgba(168,85,247,.15); border-color: rgba(168,85,247,.3); }

.thumb-placeholder {
    position: absolute; inset: 0; z-index: 1;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .4rem;
}
.ph-label { font-size: .6rem; color: var(--text-muted); font-weight: 600; letter-spacing: .1em; text-transform: uppercase; }
.ph-icon-block { width: 48px; height: 48px; border-radius: 10px; background: rgba(255,255,255,.06); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; }
.ph-icon-block svg { width: 22px; height: 22px; color: var(--text-muted); }

.card-load {
    position: absolute; inset: 0; z-index: 4;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    background: rgba(15,23,42,.8); font-size: .7rem; color: var(--text-muted);
}
.spinner { width: 16px; height: 16px; border: 2px solid var(--border-hi); border-top-color: var(--accent); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.card-body { padding: .85rem 1rem; flex: 1; display: flex; flex-direction: column; gap: .4rem; }
.card-title { font-size: 1rem; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
.card-sub { font-size: .75rem; color: var(--text-secondary); line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.card-meta { display: flex; align-items: center; gap: .5rem; margin-top: auto; padding-top: .4rem; }
.diff-pips { display: flex; gap: 2px; }
.diff-pip { width: 18px; height: 3px; border-radius: 2px; background: rgba(255,255,255,.08); }
.card-duration {
    font-size: .68rem; color: var(--text-muted);
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    padding: .12rem .5rem; border-radius: 20px; margin-left: auto;
}

.card-foot {
    padding: .6rem 1rem;
    border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(15,23,42,.3);
}
.view-btn {
    font-size: .75rem; font-weight: 600; color: var(--accent);
    background: none; border: none; cursor: pointer; font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 4px; transition: gap .15s;
}
.view-btn:hover { gap: 7px; }
.view-btn svg { width: 13px; height: 13px; }
.steps-lbl { font-size: .68rem; color: var(--text-muted); }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
.empty-state h3 { font-size: 1.1rem; font-weight: 600; color: var(--text-secondary); margin-bottom: .5rem; }
.empty-state p { font-size: .85rem; }

/* ── MODAL ── */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.85); backdrop-filter: blur(10px);
    z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.open { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal {
    background: #1e293b; border: 1px solid var(--border-hi);
    border-radius: 16px; width: 100%; max-width: 1020px; max-height: 92vh;
    display: grid; grid-template-columns: 1fr 340px;
    overflow: hidden; animation: slideUp .25s ease; position: relative;
}
@keyframes slideUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:none; } }

.modal-viewer {
    position: relative; background: #0f172a; min-height: 480px;
    display: flex; flex-direction: column;
}
#modal-canvas-wrap { position: absolute; inset: 0; }
#modal-canvas-wrap canvas { width: 100% !important; height: 100% !important; display: block; }

.modal-thumb-img {
    position: absolute; inset: 0; width: 100%; height: 100%;
    object-fit: cover; z-index: 1;
}

.viewer-hud {
    position: absolute; bottom: 0; left: 0; right: 0; z-index: 5;
    padding: .85rem 1rem;
    background: linear-gradient(to top, rgba(15,23,42,.9) 0%, transparent 100%);
    display: flex; align-items: flex-end; justify-content: space-between;
    pointer-events: none;
}
.viewer-hint { font-size: .6rem; color: rgba(255,255,255,.25); letter-spacing: .05em; }
.viewer-controls { display: flex; gap: .35rem; pointer-events: all; align-items: center; }
.v-btn {
    height: 30px; padding: 0 .65rem;
    background: rgba(30,41,59,.9); backdrop-filter: blur(6px);
    border: 1px solid var(--border-hi); border-radius: 6px;
    color: var(--text-secondary); cursor: pointer; font-size: .72rem; font-weight: 600;
    font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 4px;
    transition: all .15s;
}
.v-btn:hover { border-color: var(--accent); color: var(--text-primary); }
.v-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* Zoom buttons — square, larger icon */
.v-btn-zoom {
    height: 30px; width: 30px; padding: 0;
    background: rgba(30,41,59,.9); backdrop-filter: blur(6px);
    border: 1px solid var(--border-hi); border-radius: 6px;
    color: var(--text-secondary); cursor: pointer; font-size: 1.1rem; font-weight: 700;
    font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center;
    transition: all .15s; line-height: 1; user-select: none;
}
.v-btn-zoom:hover { border-color: var(--accent); color: var(--text-primary); background: rgba(59,130,246,.18); }
.v-btn-zoom:active { transform: scale(.92); }

/* Thin divider between zoom pair and other controls */
.v-divider { width: 1px; height: 18px; background: var(--border-hi); margin: 0 .1rem; }

.viewer-tag {
    position: absolute; top: .9rem; left: .9rem; z-index: 5;
    background: rgba(59,130,246,.9); border-radius: 5px;
    padding: .2rem .55rem; font-size: .6rem; font-weight: 700;
    letter-spacing: .12em; text-transform: uppercase; color: #fff;
}
.viewer-loading {
    position: absolute; inset: 0; z-index: 6;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem;
    background: #0f172a;
}
.big-spinner { width: 38px; height: 38px; border: 3px solid var(--border-hi); border-top-color: var(--accent); border-radius: 50%; animation: spin .75s linear infinite; }
.viewer-loading p { font-size: .78rem; color: var(--text-muted); }

.modal-panel {
    display: flex; flex-direction: column;
    border-left: 1px solid var(--border); overflow: hidden;
}
.modal-head {
    padding: 1.25rem 1.25rem .9rem;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-close {
    position: absolute; top: .85rem; right: .85rem; z-index: 10;
    width: 30px; height: 30px; background: rgba(255,255,255,.07);
    border: 1px solid var(--border-hi); border-radius: 6px;
    cursor: pointer; color: var(--text-secondary); font-size: .85rem;
    display: flex; align-items: center; justify-content: center; transition: all .15s;
}
.modal-close:hover { background: rgba(239,68,68,.15); color: #fca5a5; border-color: rgba(239,68,68,.3); }
.modal-category { font-size: .62rem; font-weight: 700; letter-spacing: .15em; text-transform: uppercase; color: var(--text-muted); margin-bottom: .5rem; }
.modal-title { font-size: 1.5rem; font-weight: 800; line-height: 1.1; margin-bottom: .75rem; }
.modal-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
.m-chip { display: flex; align-items: center; gap: 4px; background: rgba(255,255,255,.05); border: 1px solid var(--border); border-radius: 20px; padding: .2rem .65rem; font-size: .7rem; color: var(--text-secondary); }

.modal-tabs { display: flex; border-bottom: 1px solid var(--border); padding: 0 1.25rem; flex-shrink: 0; }
.m-tab {
    padding: .65rem 0; margin-right: 1.25rem; font-size: .78rem; font-weight: 600;
    cursor: pointer; border: none; border-bottom: 2px solid transparent;
    background: none; color: var(--text-muted); font-family: 'Poppins', sans-serif;
    transition: all .15s;
}
.m-tab:hover { color: var(--text-primary); }
.m-tab.active { color: var(--accent); border-bottom-color: var(--accent); }

.modal-body { flex: 1; overflow-y: auto; padding: 1.1rem 1.25rem; }
.tab-pane { display: none; }
.tab-pane.active { display: block; }

.steps-list { list-style: none; }
.step-item { display: flex; gap: .75rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
.step-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.step-num { width: 24px; height: 24px; border-radius: 5px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .82rem; font-weight: 700; color: #fff; background: var(--accent); margin-top: 1px; }
.step-content h4 { font-size: .82rem; font-weight: 600; margin-bottom: .2rem; }
.step-content p  { font-size: .75rem; color: var(--text-secondary); line-height: 1.6; }

.muscles-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.muscle-chip { background: rgba(255,255,255,.04); border: 1px solid var(--border); border-radius: 8px; padding: .5rem .7rem; display: flex; align-items: flex-start; gap: .5rem; }
.m-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
.m-name { font-size: .76rem; font-weight: 600; }
.m-type { font-size: .63rem; color: var(--text-muted); margin-top: 1px; }

.tips-list { list-style: none; }
.tip-item { display: flex; gap: .65rem; align-items: flex-start; padding: .65rem 0; border-bottom: 1px solid var(--border); }
.tip-item:last-child { border-bottom: none; }
.tip-lbl { font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); flex-shrink: 0; margin-top: 2px; min-width: 24px; }
.tip-text { font-size: .76rem; color: var(--text-secondary); line-height: 1.6; }

.no-content-msg { font-size: .8rem; color: var(--text-muted); text-align: center; padding: 1.5rem 0; }

.modal-body::-webkit-scrollbar { width: 3px; }
.modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 2px; }

/* ── BUTTONS ── */
.btn {
    padding: .5rem 1rem; border: none; border-radius: 8px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins', sans-serif; font-size: .82rem;
    display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all .2s;
}
.btn-primary { background: linear-gradient(135deg, var(--accent), #8b5cf6); color: #fff; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(59,130,246,.35); }
.btn-outline { background: transparent; border: 1px solid rgba(255,255,255,.12); color: var(--text-secondary); }
.btn-outline:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }

/* ── MOBILE ── */
.mobile-menu-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

@media (max-width: 1100px) { .modal { grid-template-columns: 1fr; max-height: 95vh; overflow-y: auto; } .modal-viewer { min-height: 280px; } }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main-content { margin-left: 0; }
    .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
    .exercises-grid { grid-template-columns: 1fr 1fr; }
    .filter-bar { gap: .6rem; }
}
@media (max-width: 480px) { .exercises-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<!-- SIDEBAR -->
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
                <p><?= ucfirst($expKey) ?> · <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="workouts.php" class="nav-item active">
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
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
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
</aside>

<!-- MAIN -->
<div class="main-content">
    <div class="topbar">
        <div class="topbar-left">
            <nav class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <span>/</span>
                <span>Workouts</span>
            </nav>
            <span style="color:var(--border-hi)">·</span>
            <span class="topbar-title">Training Library</span>
        </div>
        <div class="topbar-right">
            <span id="totalCount" style="font-size:.75rem;color:var(--text-muted)"><?= $totalCount ?> exercise<?= $totalCount !== 1 ? 's' : '' ?></span>
            <a href="progress.php" class="btn btn-outline">Log Progress</a>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <span class="filter-label">Filter</span>
        <div class="cat-dropdown-wrap">
            <button class="cat-btn" id="catBtn" onclick="toggleCatDropdown()">
                <span id="catBtnLabel">All Categories</span>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
            </button>
            <div class="cat-dropdown" id="catDropdown"></div>
        </div>
        <div class="diff-pills">
            <button class="diff-pill active" data-diff="all"       onclick="setDiff('all',this)">All levels</button>
            <button class="diff-pill" data-diff="beginner"         onclick="setDiff('beginner',this)">Beginner</button>
            <button class="diff-pill" data-diff="intermediate"     onclick="setDiff('intermediate',this)">Intermediate</button>
            <button class="diff-pill" data-diff="advanced"         onclick="setDiff('advanced',this)">Advanced</button>
        </div>
        <div class="search-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" class="search-input" placeholder="Search exercises..." oninput="handleSearch(this.value)">
        </div>
        <div class="filter-results" id="filterResults"></div>
    </div>

    <div class="page" id="workoutsPage"></div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="exerciseModal">
    <div class="modal">
        <div class="modal-viewer">
            <div class="viewer-loading" id="viewerLoading">
                <div class="big-spinner"></div>
                <p id="viewerLoadText">Initialising 3D viewer...</p>
            </div>
            <div id="modal-canvas-wrap"></div>
            <div class="viewer-tag" id="viewerTag">3D LIVE</div>
            <div class="viewer-hud">
                <div class="viewer-hint">Drag to rotate</div>
                <div class="viewer-controls" id="viewerControls">
                    <button class="v-btn active" id="btnPlay"  onclick="toggleAnim()">Pause</button>
                    <button class="v-btn"         id="btnSlow"  onclick="setSpeed(0.4)">Slow</button>
                    <button class="v-btn"         id="btnFast"  onclick="setSpeed(1.8)">Fast</button>
                    <button class="v-btn"         id="btnReset" onclick="resetCamera()">Reset</button>
                    <div class="v-divider"></div>
                    <button class="v-btn-zoom" id="btnZoomIn"  onclick="zoomIn()"  title="Zoom in">+</button>
                    <button class="v-btn-zoom" id="btnZoomOut" onclick="zoomOut()" title="Zoom out">−</button>
                </div>
            </div>
        </div>
        <div class="modal-panel">
            <div class="modal-head">
                <button class="modal-close" onclick="closeModal()">✕</button>
                <div class="modal-category" id="modalCat"></div>
                <div class="modal-title" id="modalTitle"></div>
                <div class="modal-chips">
                    <div class="m-chip">Duration: <span id="modalDuration"></span></div>
                    <div class="m-chip"><span id="modalDiff"></span></div>
                </div>
            </div>
            <div class="modal-tabs">
                <button class="m-tab active" onclick="switchTab('steps',this)">Steps</button>
                <button class="m-tab"        onclick="switchTab('muscles',this)">Muscles</button>
                <button class="m-tab"        onclick="switchTab('tips',this)">Tips</button>
            </div>
            <div class="modal-body">
                <div class="tab-pane active" id="tab-steps"><ol class="steps-list" id="stepsList"></ol></div>
                <div class="tab-pane"        id="tab-muscles"><div class="muscles-grid" id="musclesList"></div></div>
                <div class="tab-pane"        id="tab-tips"><ul class="tips-list" id="tipsList"></ul></div>
            </div>
        </div>
    </div>
</div>

<button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script type="importmap">
{ "imports": { "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js", "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/" } }
</script>

<script type="module">
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// ── DATA FROM PHP ─────────────────────────────────────────────────────────────
const EXERCISES = <?= $exercisesJson ?>;

const CAT_LABELS = {
    military:'Military PT', strength:'Strength', cardio:'Cardio',
    endurance:'Endurance', hiit:'HIIT', flexibility:'Flexibility',
    general:'General', chest:'Chest', back:'Back', legs:'Legs',
    shoulders:'Shoulders', arms:'Arms', core:'Core & Abs', full_body:'Full Body',
};
const CAT_ORDER = ['military','chest','back','legs','shoulders','arms','core','cardio','endurance','full_body','hiit','flexibility','general','strength'];

// ── STATE ─────────────────────────────────────────────────────────────────────
let activeCategory = 'all';
let activeDiff     = 'all';
let searchQuery    = '';

// THREE.JS globals
let scene3, camera3, renderer3, controls3, mixer3, model3;
let animPaused = false, currentSpeed = 1;
const clock3   = new THREE.Clock();
let loopRunning = false;
let defCamPos   = new THREE.Vector3();
let defCamTgt   = new THREE.Vector3();
const thumbMap  = {};

// Zoom step factor (each click moves camera 15% closer/farther)
const ZOOM_FACTOR = 0.15;

// ── ZOOM CONTROLS ─────────────────────────────────────────────────────────────
window.zoomIn = function() {
    if (!camera3 || !controls3) return;
    const dir = new THREE.Vector3().subVectors(camera3.position, controls3.target);
    const dist = dir.length();
    const newDist = Math.max(controls3.minDistance, dist * (1 - ZOOM_FACTOR));
    dir.setLength(newDist);
    camera3.position.copy(controls3.target).add(dir);
    controls3.update();
};

window.zoomOut = function() {
    if (!camera3 || !controls3) return;
    const dir = new THREE.Vector3().subVectors(camera3.position, controls3.target);
    const dist = dir.length();
    const newDist = Math.min(controls3.maxDistance, dist * (1 + ZOOM_FACTOR));
    dir.setLength(newDist);
    camera3.position.copy(controls3.target).add(dir);
    controls3.update();
};

// ── CATEGORY DROPDOWN ─────────────────────────────────────────────────────────
function buildCatDropdown() {
    const dd = document.getElementById('catDropdown');
    const genres = [...new Set(EXERCISES.map(e => e.genre))];
    const cats = [{ id:'all', label:'All Categories' }, ...genres.map(g => ({ id:g, label: CAT_LABELS[g] || g }))];
    cats.forEach(cat => {
        const count = cat.id === 'all' ? EXERCISES.length : EXERCISES.filter(e => e.genre === cat.id).length;
        const opt = document.createElement('div');
        opt.className = 'cat-option' + (cat.id === 'all' ? ' selected' : '');
        opt.dataset.id = cat.id;
        opt.innerHTML = `${cat.label} <span class="cat-option-count">${count}</span>`;
        opt.onclick = () => selectCategory(cat.id, cat.label);
        dd.appendChild(opt);
    });
}

window.toggleCatDropdown = function() {
    document.getElementById('catBtn').classList.toggle('open');
    document.getElementById('catDropdown').classList.toggle('open');
};

function selectCategory(id, label) {
    activeCategory = id;
    document.getElementById('catBtnLabel').textContent = label;
    document.getElementById('catBtn').classList.toggle('active-filter', id !== 'all');
    document.getElementById('catBtn').classList.remove('open');
    document.getElementById('catDropdown').classList.remove('open');
    document.querySelectorAll('.cat-option').forEach(o => o.classList.toggle('selected', o.dataset.id === id));
    renderList();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.cat-dropdown-wrap')) {
        document.getElementById('catBtn')?.classList.remove('open');
        document.getElementById('catDropdown')?.classList.remove('open');
    }
});

window.setDiff = function(diff, btn) {
    activeDiff = diff;
    document.querySelectorAll('.diff-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    renderList();
};

window.handleSearch = function(q) {
    searchQuery = q.toLowerCase();
    renderList();
};

function getFiltered() {
    return EXERCISES.filter(e => {
        const catOk  = activeCategory === 'all' || e.genre === activeCategory;
        const diffOk = activeDiff === 'all' || e.difficulty === activeDiff;
        const qOk    = !searchQuery || e.title.toLowerCase().includes(searchQuery) || (e.description || '').toLowerCase().includes(searchQuery);
        return catOk && diffOk && qOk;
    });
}

// ── RENDER LIST ───────────────────────────────────────────────────────────────
const DIFF_COLORS = { beginner:'#10b981', intermediate:'#f59e0b', advanced:'#ef4444', expert:'#a855f7' };
const DIFF_PIPS   = { beginner:1, intermediate:2, advanced:3, expert:4 };

function renderList() {
    const filtered = getFiltered();
    const page = document.getElementById('workoutsPage');
    page.innerHTML = '';
    document.getElementById('filterResults').innerHTML = `<strong>${filtered.length}</strong> exercise${filtered.length !== 1 ? 's' : ''}`;

    if (!filtered.length) {
        page.innerHTML = EXERCISES.length === 0
            ? `<div class="empty-state"><h3>No exercises yet</h3><p>Ask your admin to add exercises to the library.</p></div>`
            : `<div class="empty-state"><h3>No exercises found</h3><p>Try adjusting the filters or search term.</p></div>`;
        return;
    }

    const grouped = {};
    filtered.forEach(e => { if (!grouped[e.genre]) grouped[e.genre] = []; grouped[e.genre].push(e); });
    const keys = [...CAT_ORDER.filter(k => grouped[k]), ...Object.keys(grouped).filter(k => !CAT_ORDER.includes(k))];

    keys.forEach(key => {
        const group = document.createElement('div');
        group.className = 'section-group';
        group.innerHTML = `
            <div class="group-header">
                <span class="group-name">${CAT_LABELS[key] || key}</span>
                <span class="group-line">—</span>
                <span class="group-count">${grouped[key].length} exercise${grouped[key].length !== 1 ? 's' : ''}</span>
            </div>
            <div class="exercises-grid" id="grid-${key}"></div>`;
        page.appendChild(group);
        const grid = group.querySelector('.exercises-grid');
        grouped[key].forEach(ex => grid.appendChild(buildCard(ex)));
        setTimeout(() => grouped[key].forEach(ex => { if (ex.glb && !ex.thumb) initThumb(ex); }), 60);
    });
}

// ── BUILD CARD ────────────────────────────────────────────────────────────────
function buildCard(ex) {
    const dc = DIFF_COLORS[ex.difficulty] || '#3b82f6';
    const dp = DIFF_PIPS[ex.difficulty]   || 1;
    const pips = [1,2,3,4].map(i => `<div class="diff-pip" style="background:${i<=dp?dc:'rgba(255,255,255,.08)'}"></div>`).join('');

    const card = document.createElement('div');
    card.className = 'ex-card';

    const thumbInner = ex.thumb
        ? `<img class="card-thumb-img" src="${ex.thumb}" alt="${ex.title}" onerror="this.style.display='none'">`
        : (ex.glb
            ? `<div class="card-load" id="cload-${ex.id}"><div class="spinner"></div></div>`
            : `<div class="thumb-placeholder" id="cph-${ex.id}">
                <div class="ph-icon-block">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg>
                </div>
                <div class="ph-label">${ex.title}</div>
               </div>`);

    card.innerHTML = `
        <div class="card-thumb" id="thumb-${ex.id}">
            ${thumbInner}
            <div class="thumb-overlay"></div>
            <span class="diff-badge ${ex.difficulty}">${ex.difficulty}</span>
            <div class="thumb-play">
                <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
            </div>
        </div>
        <div class="card-body">
            <div class="card-title">${ex.title}</div>
            <div class="card-sub">${ex.description || ''}</div>
            <div class="card-meta">
                <div class="diff-pips">${pips}</div>
                <span style="font-size:.68rem;color:${dc};font-weight:600;margin-left:3px;text-transform:capitalize">${ex.difficulty}</span>
                <div class="card-duration">${ex.duration}</div>
            </div>
        </div>
        <div class="card-foot">
            <button class="view-btn" onclick="event.stopPropagation();openExercise(${ex.id})">
                ${ex.glb ? 'View 3D' : 'View'}
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="9,18 15,12 9,6"/></svg>
            </button>
            <span class="steps-lbl">${ex.steps.length} step${ex.steps.length !== 1 ? 's' : ''}</span>
        </div>`;
    card.addEventListener('click', () => openExercise(ex.id));
    return card;
}

// ── THUMBNAIL 3D ─────────────────────────────────────────────────────────────
function initThumb(ex) {
    const wrap   = document.getElementById('thumb-' + ex.id);
    const loadEl = document.getElementById('cload-' + ex.id);
    if (!wrap || !ex.glb) return;

    const w = wrap.offsetWidth || 260, h = wrap.offsetHeight || 170;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setSize(w, h);
    renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
    renderer.setClearColor(0x0f172a, 1);
    renderer.domElement.style.cssText = 'position:absolute;inset:0;width:100%!important;height:100%!important;z-index:1';
    wrap.appendChild(renderer.domElement);

    const scene  = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(42, w / h, 0.01, 200);
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    const dl = new THREE.DirectionalLight(0xfff0e8, 1.2); dl.position.set(3,8,5); scene.add(dl);
    const fl = new THREE.DirectionalLight(0x3b82f6, 0.2); fl.position.set(-5,2,-3); scene.add(fl);

    new GLTFLoader().load('../../models/' + ex.glb,
        gltf => {
            const m   = gltf.scene;
            const box = new THREE.Box3().setFromObject(m);
            const sz  = box.getSize(new THREE.Vector3());
            const ctr = box.getCenter(new THREE.Vector3());

            m.position.set(-ctr.x, -box.min.y, -ctr.z);

            const maxDim = Math.max(sz.x, sz.y, sz.z);
            const sc = maxDim > 0.01 ? 1.6 / maxDim : 1;
            m.scale.setScalar(sc);

            const scaledH = sz.y * sc;
            const scaledD = Math.max(sz.x, sz.z) * sc;

            const fovR = THREE.MathUtils.degToRad(42);
            const distH = (scaledH * 0.6) / Math.tan(fovR / 2);
            const distD = (scaledD * 0.6) / Math.tan(fovR / 2);
            const dist  = Math.max(distH, distD) * 1.55;

            camera.position.set(0, scaledH * 0.45, dist);
            camera.lookAt(0, scaledH * 0.4, 0);
            scene.add(m);

            if (loadEl) loadEl.style.display = 'none';

            let mx = null;
            if (gltf.animations.length) {
                mx = new THREE.AnimationMixer(m);
                gltf.animations.forEach(clip => mx.clipAction(clip).play());
            }
            thumbMap[ex.id] = { renderer, scene, camera, mixer: mx, model: m, clock: new THREE.Clock() };
            runThumb(ex.id);
        },
        undefined,
        () => {
            if (loadEl) loadEl.style.display = 'none';
            const ph = document.createElement('div');
            ph.className = 'thumb-placeholder';
            ph.innerHTML = `<div class="ph-icon-block"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg></div><div class="ph-label">${ex.title}</div>`;
            wrap.appendChild(ph);
        }
    );
}

function runThumb(id) {
    const t = thumbMap[id]; if (!t) return;
    const loop = () => {
        requestAnimationFrame(loop);
        const d = t.clock.getDelta();
        if (t.mixer) t.mixer.update(d);
        if (t.model) t.model.rotation.y += 0.005;
        t.renderer.render(t.scene, t.camera);
    };
    loop();
}

// ── MODAL 3D ──────────────────────────────────────────────────────────────────
function initModal3D() {
    const wrap = document.getElementById('modal-canvas-wrap');
    wrap.innerHTML = '';
    const w = wrap.offsetWidth  || 660;
    const h = wrap.offsetHeight || 480;

    renderer3 = new THREE.WebGLRenderer({ antialias: true });
    renderer3.setSize(w, h);
    renderer3.setPixelRatio(Math.min(devicePixelRatio, 2));
    renderer3.shadowMap.enabled = true;
    renderer3.shadowMap.type    = THREE.PCFSoftShadowMap;
    renderer3.setClearColor(0x0f172a, 1);
    renderer3.toneMapping         = THREE.ACESFilmicToneMapping;
    renderer3.toneMappingExposure = 1.2;
    renderer3.domElement.style.display = 'block';
    wrap.appendChild(renderer3.domElement);

    scene3  = new THREE.Scene();
    camera3 = new THREE.PerspectiveCamera(42, w / h, 0.01, 500);

    scene3.add(new THREE.AmbientLight(0xffffff, 0.5));
    const key  = new THREE.DirectionalLight(0xfff5f0, 1.5); key.position.set(4,10,6);  key.castShadow=true; key.shadow.mapSize.set(2048,2048); scene3.add(key);
    const fill = new THREE.DirectionalLight(0x3b82f6, 0.3); fill.position.set(-6,3,-4); scene3.add(fill);
    const rim  = new THREE.DirectionalLight(0x6366f1, 0.18); rim.position.set(0,5,-8);  scene3.add(rim);

    const floor = new THREE.Mesh(
        new THREE.CircleGeometry(12, 64),
        new THREE.MeshStandardMaterial({ color: 0x0a1020, roughness: 0.95 })
    );
    floor.rotation.x = -Math.PI / 2; floor.receiveShadow = true; scene3.add(floor);
    scene3.add(new THREE.GridHelper(24, 24, 0x1a2035, 0x141925));

    controls3 = new OrbitControls(camera3, renderer3.domElement);
    controls3.enableDamping  = true;
    controls3.dampingFactor  = 0.07;
    controls3.minDistance    = 0.5;
    controls3.maxDistance    = 60;
    controls3.maxPolarAngle  = Math.PI * 0.82;
    // Disable scroll-wheel zoom — users use + / − buttons instead
    controls3.enableZoom     = false;

    new ResizeObserver(() => {
        const w2 = wrap.offsetWidth, h2 = wrap.offsetHeight;
        if (w2 > 0 && h2 > 0 && renderer3) {
            camera3.aspect = w2 / h2;
            camera3.updateProjectionMatrix();
            renderer3.setSize(w2, h2);
        }
    }).observe(wrap);

    if (!loopRunning) { loopRunning = true; runLoop(); }
}

function runLoop() {
    requestAnimationFrame(runLoop);
    const d = clock3.getDelta();
    if (mixer3 && !animPaused) mixer3.update(d);
    if (controls3) controls3.update();
    if (renderer3 && scene3 && camera3) renderer3.render(scene3, camera3);
}

function loadModal3D(glb) {
    const loadEl = document.getElementById('viewerLoading');
    const loadTx = document.getElementById('viewerLoadText');
    if (loadEl) loadEl.style.display = 'flex';
    if (loadTx) loadTx.textContent   = 'Loading model...';

    // Dispose previous model
    if (model3) {
        scene3.remove(model3);
        model3.traverse(c => {
            if (c.isMesh) {
                c.geometry?.dispose();
                (Array.isArray(c.material) ? c.material : [c.material]).forEach(m => m?.dispose());
            }
        });
        model3 = null;
    }
    if (mixer3) { mixer3.stopAllAction(); mixer3 = null; }
    clock3.getDelta();

    new GLTFLoader().load('../../models/' + glb,
        gltf => {
            model3 = gltf.scene;
            model3.traverse(c => { if (c.isMesh) { c.castShadow = true; c.receiveShadow = true; }});
            scene3.add(model3);

            // ── FIT MODEL TO FILL FRAME ON OPEN ────────────────────────────
            const box    = new THREE.Box3().setFromObject(model3);
            const sz     = box.getSize(new THREE.Vector3());
            const ctr    = box.getCenter(new THREE.Vector3());

            // Centre the model horizontally, sit on floor
            model3.position.set(-ctr.x, -box.min.y, -ctr.z);

            // Normalise to a stable world size
            const maxDim = Math.max(sz.x, sz.y, sz.z);
            const sf     = maxDim > 0.01 ? 1.8 / maxDim : 1;
            model3.scale.setScalar(sf);

            const scaledH = sz.y  * sf;
            const scaledW = sz.x  * sf;
            const scaledD = sz.z  * sf;
            const maxSpan = Math.max(scaledW, scaledD);

            // ── TIGHT AUTO-FIT: camera sits close enough that the model
            //    fills ~85% of the viewport height right away. ────────────
            const fovRad  = THREE.MathUtils.degToRad(camera3.fov);
            const aspect  = camera3.aspect;

            // How far back to fit each dimension with 10% padding
            const distForH = (scaledH * 0.5) / Math.tan(fovRad / 2) * 1.1;
            const distForW = (maxSpan * 0.5) / Math.tan((fovRad * aspect) / 2) * 1.1;

            // Take the larger — but cap at 1.4× (tight fit, not zoomed-out)
            const dist = Math.max(distForH, distForW) * 1.4;

            // Eye height at ~55% of model height for a natural perspective
            const eyeH = scaledH * 0.55;
            camera3.position.set(dist * 0.35, eyeH, dist);

            const tgt = new THREE.Vector3(0, scaledH * 0.4, 0);
            controls3.target.copy(tgt);
            controls3.update();

            // Store for Reset button
            defCamPos.set(dist * 0.35, eyeH, dist);
            defCamTgt.copy(tgt);

            // Animations
            if (gltf.animations.length) {
                mixer3 = new THREE.AnimationMixer(model3);
                const act = mixer3.clipAction(gltf.animations[0]);
                act.timeScale = currentSpeed;
                act.reset().play();
                animPaused = false;
                updatePlayBtn();
            }

            if (loadEl) loadEl.style.display = 'none';
        },
        p => {
            if (p.total > 0 && loadTx)
                loadTx.textContent = `Loading... ${Math.round(p.loaded / p.total * 100)}%`;
        },
        err => {
            console.warn('GLB missing:', glb, err);
            if (loadEl) loadEl.innerHTML = '<p style="color:#64748b;font-size:.8rem">3D model not available</p>';
        }
    );
}

// ── OPEN EXERCISE MODAL ───────────────────────────────────────────────────────
window.openExercise = function(id) {
    const ex = EXERCISES.find(e => e.id === id); if (!ex) return;
    const catLabel = CAT_LABELS[ex.genre] || ex.genre;
    const dc = DIFF_COLORS[ex.difficulty] || '#3b82f6';

    document.getElementById('modalCat').textContent      = catLabel.toUpperCase();
    document.getElementById('modalTitle').textContent    = ex.title;
    document.getElementById('modalDuration').textContent = ex.duration;
    document.getElementById('modalDiff').textContent     = ex.difficulty.charAt(0).toUpperCase() + ex.difficulty.slice(1);
    document.getElementById('modalDiff').style.color     = dc;

    // Steps
    document.getElementById('stepsList').innerHTML = ex.steps.length
        ? ex.steps.map((s,i) => `
            <li class="step-item">
                <div class="step-num">${i+1}</div>
                <div class="step-content"><h4>${s.title||'Step '+(i+1)}</h4><p>${s.desc||''}</p></div>
            </li>`).join('')
        : '<p class="no-content-msg">No steps added yet.</p>';

    // Muscles
    document.getElementById('musclesList').innerHTML = ex.muscles.length
        ? ex.muscles.map(m => `
            <div class="muscle-chip">
                <div class="m-dot" style="background:${m.color}"></div>
                <div><div class="m-name">${m.name}</div><div class="m-type">${m.type}</div></div>
            </div>`).join('')
        : '<p class="no-content-msg">No muscle data added.</p>';

    // Tips
    document.getElementById('tipsList').innerHTML = ex.tips.length
        ? ex.tips.map((t,i) => `
            <li class="tip-item">
                <span class="tip-lbl">${String(i+1).padStart(2,'0')}</span>
                <span class="tip-text">${t.text}</span>
            </li>`).join('')
        : '<p class="no-content-msg">No tips added yet.</p>';

    // Reset tabs
    document.querySelectorAll('.m-tab').forEach((t,i)   => t.classList.toggle('active', i===0));
    document.querySelectorAll('.tab-pane').forEach((p,i) => p.classList.toggle('active', i===0));

    document.getElementById('exerciseModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    const canvasWrap = document.getElementById('modal-canvas-wrap');
    const loadingEl  = document.getElementById('viewerLoading');
    const viewerTag  = document.getElementById('viewerTag');
    const viewerCtrl = document.getElementById('viewerControls');

    canvasWrap.querySelectorAll('img.modal-thumb-img').forEach(i => i.remove());

    if (ex.glb) {
        viewerTag.style.display  = '';
        viewerCtrl.style.display = '';
        requestAnimationFrame(() => setTimeout(() => {
            if (!renderer3) initModal3D();
            loadModal3D(ex.glb);
        }, 60));
    } else if (ex.thumb) {
        if (loadingEl) loadingEl.style.display = 'none';
        viewerTag.style.display  = 'none';
        viewerCtrl.style.display = 'none';
        const img = document.createElement('img');
        img.className = 'modal-thumb-img';
        img.src = ex.thumb;
        img.alt = ex.title;
        canvasWrap.appendChild(img);
    } else {
        if (loadingEl) loadingEl.innerHTML = '<p style="color:#64748b;font-size:.8rem">No preview available</p>';
        viewerTag.style.display  = 'none';
        viewerCtrl.style.display = 'none';
    }
};

window.closeModal = function() {
    document.getElementById('exerciseModal').classList.remove('open');
    document.body.style.overflow = '';
};
document.getElementById('exerciseModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});

function updatePlayBtn() {
    const btn = document.getElementById('btnPlay'); if (!btn) return;
    btn.textContent = animPaused ? 'Play' : 'Pause';
    btn.classList.toggle('active', !animPaused);
}
window.toggleAnim = function() {
    animPaused = !animPaused;
    if (mixer3) mixer3.timeScale = animPaused ? 0 : currentSpeed;
    updatePlayBtn();
};
window.setSpeed = function(s) {
    currentSpeed = s;
    if (mixer3) mixer3.timeScale = animPaused ? 0 : s;
    ['btnSlow','btnFast'].forEach(id => document.getElementById(id)?.classList.remove('active'));
    if (s < 0.7)       document.getElementById('btnSlow')?.classList.add('active');
    else if (s > 1.2)  document.getElementById('btnFast')?.classList.add('active');
};
window.resetCamera = function() {
    if (!camera3 || !controls3) return;
    camera3.position.copy(defCamPos);
    controls3.target.copy(defCamTgt);
    controls3.update();
};
window.switchTab = function(name, btn) {
    document.querySelectorAll('.m-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn?.classList.add('active');
    document.getElementById('tab-' + name)?.classList.add('active');
};

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── AVATAR ────────────────────────────────────────────────────────────────────
const SAVED_AVATAR_TYPE = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL   = <?= json_encode($photoUrl) ?>;
const USER_INITIAL      = <?= json_encode($initials) ?>;

function renderSbAv() {
    const c = document.getElementById('sbAvContainer'); if (!c) return;
    c.innerHTML = '';
    if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
        const img = document.createElement('img');
        img.src = SAVED_PHOTO_URL;
        img.style.cssText = 'width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror = () => renderInitAv(c);
        c.appendChild(img);
    } else { renderInitAv(c); }
}
function renderInitAv(c) {
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    c.appendChild(d);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
buildCatDropdown();
renderList();
renderSbAv();
</script>
</body>
</html>