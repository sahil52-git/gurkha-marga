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

$forceMap   = [
    'british'   => 'British Army',
    'nepal'     => 'Nepal Army',
    'indian'    => 'Indian Army',
    'singapore' => 'Singapore Police Force',
    'french'    => 'French Foreign Legion',
];
$forceName = $forceMap[$forceKey] ?? ucfirst($forceKey);

/* avatar */
$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';
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

/* Category dropdown */
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

/* Difficulty filter */
.diff-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
.diff-pill {
    padding: .35rem .75rem; border-radius: 20px; font-size: .75rem; font-weight: 500;
    border: 1px solid var(--border); background: transparent;
    color: var(--text-muted); cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: all .15s;
}
.diff-pill:hover { color: var(--text-secondary); border-color: var(--border-hi); }
.diff-pill.active { color: #fff; border-color: transparent; }
.diff-pill[data-diff="all"].active   { background: #3b82f6; }
.diff-pill[data-diff="beginner"].active    { background: #10b981; }
.diff-pill[data-diff="intermediate"].active { background: #f59e0b; }
.diff-pill[data-diff="advanced"].active    { background: #ef4444; }

/* Search */
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

/* ── SECTION GROUP ── */
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
.diff-badge.beginner    { color: #6ee7b7; background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.3); }
.diff-badge.intermediate { color: #fcd34d; background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.3); }
.diff-badge.advanced    { color: #fca5a5; background: rgba(239,68,68,.15);  border-color: rgba(239,68,68,.3); }
.diff-badge.expert      { color: #d8b4fe; background: rgba(168,85,247,.15); border-color: rgba(168,85,247,.3); }

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
.diff-pip.on { background: currentColor; }
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

.viewer-hud {
    position: absolute; bottom: 0; left: 0; right: 0; z-index: 5;
    padding: .85rem 1rem;
    background: linear-gradient(to top, rgba(15,23,42,.9) 0%, transparent 100%);
    display: flex; align-items: flex-end; justify-content: space-between;
    pointer-events: none;
}
.viewer-hint { font-size: .6rem; color: rgba(255,255,255,.25); letter-spacing: .05em; }
.viewer-controls { display: flex; gap: .35rem; pointer-events: all; }
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
.m-chip {
    display: flex; align-items: center; gap: 4px;
    background: rgba(255,255,255,.05); border: 1px solid var(--border);
    border-radius: 20px; padding: .2rem .65rem; font-size: .7rem; color: var(--text-secondary);
}

.modal-tabs {
    display: flex; border-bottom: 1px solid var(--border);
    padding: 0 1.25rem; flex-shrink: 0;
}
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

/* scrollbar */
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
</aside>

<!-- MAIN -->
<div class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <nav class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <span>/</span>
                <span>Workouts</span>
            </nav>
            <span style="color: var(--border-hi);">·</span>
            <span class="topbar-title">Training Library</span>
        </div>
        <div class="topbar-right">
            <span id="totalCount" style="font-size:.75rem; color:var(--text-muted);">29 exercises</span>
            <a href="progress.php" class="btn btn-outline">Log Progress</a>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <span class="filter-label">Filter</span>

        <!-- Category dropdown -->
        <div class="cat-dropdown-wrap">
            <button class="cat-btn" id="catBtn" onclick="toggleCatDropdown()">
                <span id="catBtnLabel">All Categories</span>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
            </button>
            <div class="cat-dropdown" id="catDropdown"></div>
        </div>

        <!-- Difficulty pills -->
        <div class="diff-pills">
            <button class="diff-pill active" data-diff="all"          onclick="setDiff('all',this)">All levels</button>
            <button class="diff-pill" data-diff="beginner"     onclick="setDiff('beginner',this)">Beginner</button>
            <button class="diff-pill" data-diff="intermediate" onclick="setDiff('intermediate',this)">Intermediate</button>
            <button class="diff-pill" data-diff="advanced"     onclick="setDiff('advanced',this)">Advanced</button>
        </div>

        <!-- Search -->
        <div class="search-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" class="search-input" placeholder="Search exercises..." oninput="handleSearch(this.value)">
        </div>

        <div class="filter-results" id="filterResults"></div>
    </div>

    <!-- WORKOUT LIST -->
    <div class="page" id="workoutsPage"></div>

</div><!-- /main -->

<!-- MODAL -->
<div class="modal-overlay" id="exerciseModal">
    <div class="modal">
        <div class="modal-viewer">
            <div class="viewer-loading" id="viewerLoading">
                <div class="big-spinner"></div>
                <p id="viewerLoadText">Initialising 3D viewer...</p>
            </div>
            <div id="modal-canvas-wrap"></div>
            <div class="viewer-tag">3D LIVE</div>
            <div class="viewer-hud">
                <div class="viewer-hint">Drag to rotate · Scroll to zoom</div>
                <div class="viewer-controls">
                    <button class="v-btn active" id="btnPlay"  onclick="toggleAnim()">Pause</button>
                    <button class="v-btn"         id="btnSlow"  onclick="setSpeed(0.4)">Slow</button>
                    <button class="v-btn"         id="btnFast"  onclick="setSpeed(1.8)">Fast</button>
                    <button class="v-btn"         id="btnReset" onclick="resetCamera()">Reset</button>
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

<!-- THREE.JS -->
<script type="importmap">
{ "imports": { "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js", "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/" } }
</script>

<script type="module">
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// ── CATEGORIES ──
const CATEGORIES = [
    { id: 'all',         label: 'All Categories' },
    { id: 'chest',       label: 'Chest' },
    { id: 'back',        label: 'Back' },
    { id: 'legs',        label: 'Legs' },
    { id: 'shoulders',   label: 'Shoulders' },
    { id: 'arms',        label: 'Arms' },
    { id: 'core',        label: 'Core & Abs' },
    { id: 'cardio',      label: 'Cardio & Endurance' },
    { id: 'full_body',   label: 'Full Body' },
    { id: 'hiit',        label: 'HIIT & Power' },
    { id: 'flexibility', label: 'Flexibility' },
];

// ── EXERCISE DATABASE ──
// GLB files mapped carefully: exercise1.glb = push-up, etc.
// If your GLB naming differs from the exercise order, map correctly here.
const EXERCISES = [
  // ── CHEST ──
  { id:1,  glb:'exercise1.glb',  genre:'chest',       title:'Push-Up',
    duration:'3 × 20 reps', difficulty:'beginner',
    description:'Classic upper-body compound. Chest, triceps and anterior deltoids working together.',
    muscles:[{name:'Chest (Pectorals)',type:'Primary',color:'#3b82f6'},{name:'Triceps',type:'Primary',color:'#3b82f6'},{name:'Front Deltoids',type:'Secondary',color:'#64748b'},{name:'Core',type:'Stabiliser',color:'#64748b'}],
    steps:[{title:'Starting Position',desc:'Hands shoulder-width apart, fingers forward. Arms extended. Body a straight plank from head to heels.'},{title:'Lower Phase',desc:'Bend elbows at 45° to body. Lower chest toward floor in a controlled 2-second descent. Hips level.'},{title:'Bottom Position',desc:'Chest 2–3 cm from the floor. Elbows near 90°. Hold 1 count. Core braced.'},{title:'Push Phase',desc:'Drive hands into ground, exhale, push to full extension. Squeeze chest at top.'}],
    tips:[{text:'Body in one rigid plank — no hips dropping or pike.'},{text:'Exhale on the push, inhale on descent.'},{text:'Elbows point diagonally back, not flared wide.'}]
  },
  { id:2,  glb:'exercise2.glb',  genre:'chest',       title:'Wide Push-Up',
    duration:'3 × 15 reps', difficulty:'beginner',
    description:'Wider grip targets outer pectorals and increases chest stretch at the bottom.',
    muscles:[{name:'Outer Pectorals',type:'Primary',color:'#3b82f6'},{name:'Front Deltoids',type:'Secondary',color:'#64748b'},{name:'Triceps',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Setup',desc:'Hands 1.5× shoulder width apart. Body in strict plank.'},{title:'Descent',desc:'Lower chest straight down. Elbows flare wider than standard.'},{title:'Press',desc:'Drive palms outward into floor. Full extension at top.'}],
    tips:[{text:'Wider than shoulder-width — feel the chest stretch at the bottom.'}]
  },
  { id:3,  glb:'exercise3.glb',  genre:'chest',       title:'Diamond Push-Up',
    duration:'3 × 12 reps', difficulty:'intermediate',
    description:'Close-grip variation that shifts emphasis to inner chest and triceps.',
    muscles:[{name:'Inner Chest',type:'Primary',color:'#3b82f6'},{name:'Triceps',type:'Primary',color:'#3b82f6'},{name:'Front Deltoids',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Hand Position',desc:'Place hands together forming a diamond with thumbs and index fingers.'},{title:'Lower',desc:'Descend with elbows close to body. Chest toward hands.'},{title:'Press',desc:'Extend arms, keeping elbows tight throughout.'}],
    tips:[{text:'Elbows tucked tight — that is what activates inner chest and triceps.'}]
  },
  { id:4,  glb:'exercise4.glb',  genre:'chest',       title:'Dip',
    duration:'3 × 15 reps', difficulty:'intermediate',
    description:'Parallel bar dip for lower chest and tricep development.',
    muscles:[{name:'Triceps',type:'Primary',color:'#3b82f6'},{name:'Chest (Lower)',type:'Primary',color:'#3b82f6'},{name:'Front Deltoids',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Mount',desc:'Grip parallel bars, arms straight. Lean forward slightly for chest emphasis.'},{title:'Lower',desc:'Bend elbows, upper arms parallel to floor. 3-second controlled descent.'},{title:'Drive Up',desc:'Press through palms, extend arms fully. Lock out at top.'}],
    tips:[{text:'Lean forward = more chest. Upright = more triceps.'},{text:'Do not go below 90° at elbows if you have shoulder issues.'}]
  },

  // ── BACK ──
  { id:5,  glb:'exercise5.glb',  genre:'back',        title:'Pull-Up',
    duration:'4 × max reps', difficulty:'intermediate',
    description:'Overhand pull-up — a core Gurkha selection requirement targeting lats and biceps.',
    muscles:[{name:'Latissimus Dorsi',type:'Primary',color:'#3b82f6'},{name:'Biceps',type:'Primary',color:'#3b82f6'},{name:'Rear Deltoids',type:'Secondary',color:'#64748b'},{name:'Rhomboids',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Grip',desc:'Overhand grip, hands just outside shoulder width. Hang fully extended, shoulders packed down.'},{title:'Initiate',desc:'Retract shoulder blades first. Engage lats before arm bend.'},{title:'Pull',desc:'Drive elbows toward hips. Pull chest to bar. Chin clears the bar at top.'},{title:'Lower',desc:'Controlled 3-second eccentric back to full hang.'}],
    tips:[{text:'Lead with your chest toward the bar, not just your chin.'},{text:'Cross feet and squeeze glutes to eliminate kipping.'}]
  },
  { id:6,  glb:'exercise6.glb',  genre:'back',        title:'Chin-Up',
    duration:'3 × max reps', difficulty:'intermediate',
    description:'Underhand chin-up shifts more load onto biceps while still training the lats hard.',
    muscles:[{name:'Biceps',type:'Primary',color:'#3b82f6'},{name:'Latissimus Dorsi',type:'Primary',color:'#3b82f6'},{name:'Lower Traps',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Grip',desc:'Supinated (underhand) grip, hands shoulder-width. Full hang.'},{title:'Pull',desc:'Drive elbows down toward hips. Pull until chin over bar.'},{title:'Lower',desc:'Slowly lower to full extension. Dead-hang between reps.'}],
    tips:[{text:'Supinated grip = significantly more bicep recruitment than overhand.'}]
  },
  { id:7,  glb:'exercise7.glb',  genre:'back',        title:'Inverted Row',
    duration:'3 × 15 reps', difficulty:'beginner',
    description:'Bodyweight horizontal row using a low bar. Great back builder, beginner-friendly.',
    muscles:[{name:'Rhomboids',type:'Primary',color:'#3b82f6'},{name:'Middle Traps',type:'Primary',color:'#3b82f6'},{name:'Rear Deltoids',type:'Secondary',color:'#64748b'},{name:'Biceps',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Position',desc:'Hang under a bar at waist height, body in straight plank, arms extended.'},{title:'Row',desc:'Pull chest to bar by driving elbows back. Squeeze shoulder blades together.'},{title:'Lower',desc:'Extend arms fully. Maintain plank body position throughout.'}],
    tips:[{text:'Elevate feet to make it harder. Bend knees to make it easier.'}]
  },

  // ── LEGS ──
  { id:8,  glb:'exercise8.glb',  genre:'legs',        title:'Squat',
    duration:'4 × 25 reps', difficulty:'beginner',
    description:'Fundamental lower-body strength movement. The king of leg exercises.',
    muscles:[{name:'Quadriceps',type:'Primary',color:'#10b981'},{name:'Glutes',type:'Primary',color:'#10b981'},{name:'Hamstrings',type:'Secondary',color:'#64748b'},{name:'Core',type:'Stabiliser',color:'#64748b'}],
    steps:[{title:'Stance',desc:'Feet shoulder-width, toes slightly out (15–30°). Arms forward or clasped.'},{title:'Descent',desc:'Push knees out in line with toes. Hinge hips back and down. Chest tall.'},{title:'Depth',desc:'Thighs parallel to floor or below. Weight through full foot.'},{title:'Drive Up',desc:'Press floor away. Drive knees out. Hips and chest rise together. Lockout at top.'}],
    tips:[{text:'Keep eyes forward or slightly up — maintains upright torso.'},{text:'Feel 3 points of contact: big toe, little toe, heel — tripod foot.'}]
  },
  { id:9,  glb:'exercise9.glb',  genre:'legs',        title:'Lunge',
    duration:'3 × 20 each leg', difficulty:'beginner',
    description:'Unilateral leg training for balance, functional strength, and glute activation.',
    muscles:[{name:'Quadriceps',type:'Primary',color:'#10b981'},{name:'Glutes',type:'Primary',color:'#10b981'},{name:'Hamstrings',type:'Secondary',color:'#64748b'},{name:'Balance Stabilisers',type:'Stabiliser',color:'#64748b'}],
    steps:[{title:'Start',desc:'Stand tall, feet together. Step forward with one leg — 70–90 cm wide step.'},{title:'Descend',desc:'Lower back knee toward floor. Front knee tracks over toes. Torso upright.'},{title:'Bottom',desc:'Back knee 2–3 cm from floor. Front thigh parallel to ground.'},{title:'Drive Back',desc:'Push front foot into floor, step back to start.'}],
    tips:[{text:'Keep front knee from collapsing inward — push it outward.'},{text:'Longer step = more glutes. Shorter step = more quads.'}]
  },
  { id:10, glb:'exercise10.glb', genre:'legs',        title:'Wall Sit',
    duration:'3 × 90-second holds', difficulty:'beginner',
    description:'Isometric quad endurance hold — brutal for leg strength and mental toughness.',
    muscles:[{name:'Quadriceps',type:'Primary',color:'#10b981'},{name:'Glutes',type:'Secondary',color:'#64748b'},{name:'Calves',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Position',desc:'Back flat against wall. Slide down until thighs parallel to floor. 90° at knees.'},{title:'Hold',desc:'Feet flat, arms at sides. Breathe steadily. Do not slide further.'},{title:'Build',desc:'Start with 30 s and add 10 s each session toward 2 minutes.'}],
    tips:[{text:'As much mental as physical — controlled breathing keeps you there longer.'}]
  },
  { id:11, glb:'exercise11.glb', genre:'legs',        title:'Calf Raise',
    duration:'3 × 30 reps', difficulty:'beginner',
    description:'Isolated calf strengthener essential for running performance and ankle stability.',
    muscles:[{name:'Gastrocnemius',type:'Primary',color:'#10b981'},{name:'Soleus',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Stand',desc:'Stand on edge of a step, heels off. Hold wall for balance.'},{title:'Rise',desc:'Push through balls of feet, rise as high as possible. 1-second hold at top.'},{title:'Lower',desc:'Slowly lower heels below step level. Feel the stretch at the bottom.'}],
    tips:[{text:'Slow, full range of motion beats fast, partial reps every time.'}]
  },

  // ── SHOULDERS ──
  { id:12, glb:'exercise12.glb', genre:'shoulders',   title:'Pike Push-Up',
    duration:'3 × 12 reps', difficulty:'intermediate',
    description:'Bodyweight shoulder press. Hips high in inverted-V to load anterior deltoids.',
    muscles:[{name:'Front Deltoids',type:'Primary',color:'#f59e0b'},{name:'Triceps',type:'Secondary',color:'#64748b'},{name:'Upper Traps',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Setup',desc:'Start in downward dog — hips high, inverted V. Hands shoulder-width.'},{title:'Lower',desc:'Bend elbows, lower head toward floor between hands.'},{title:'Press',desc:'Push back to inverted V. Full arm extension.'}],
    tips:[{text:'The more vertical your torso, the more shoulder stimulus.'}]
  },
  { id:13, glb:'exercise13.glb', genre:'shoulders',   title:'Handstand Push-Up',
    duration:'3 × 8 reps', difficulty:'advanced',
    description:'Elite upper body pressing strength — full shoulder and tricep activation.',
    muscles:[{name:'Deltoids (All Heads)',type:'Primary',color:'#f59e0b'},{name:'Triceps',type:'Primary',color:'#f59e0b'},{name:'Upper Traps',type:'Secondary',color:'#64748b'},{name:'Core',type:'Stabiliser',color:'#64748b'}],
    steps:[{title:'Kick Up',desc:'Kick up to wall handstand. Body straight, core tight. Hands shoulder-width.'},{title:'Lower',desc:'Bend elbows slowly. Head descends toward floor. Elbows stay forward.'},{title:'Press',desc:'Drive through palms. Extend arms fully to lockout at top.'}],
    tips:[{text:'Build up through wall-supported pike push-ups first.'},{text:'Use an ab mat under your head for safety when learning.'}]
  },
  { id:14, glb:'exercise14.glb', genre:'shoulders',   title:'Band Pull-Apart',
    duration:'3 × 20 reps', difficulty:'beginner',
    description:'Resistance band exercise for rear deltoids and scapular retractors.',
    muscles:[{name:'Rear Deltoids',type:'Primary',color:'#f59e0b'},{name:'Rhomboids',type:'Secondary',color:'#64748b'},{name:'Middle Traps',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Hold',desc:'Hold band at shoulder height, hands shoulder-width. Arms extended in front.'},{title:'Pull Apart',desc:'Pull band apart by driving elbows back. Squeeze shoulder blades at full spread.'},{title:'Return',desc:'Slowly return to start with control.'}],
    tips:[{text:'Essential prehab — do this before every pressing session.'}]
  },

  // ── ARMS ──
  { id:15, glb:'exercise15.glb', genre:'arms',        title:'Tricep Dip (Bench)',
    duration:'3 × 20 reps', difficulty:'beginner',
    description:'Bench dips isolate the triceps using bodyweight leverage.',
    muscles:[{name:'Triceps (Long Head)',type:'Primary',color:'#ec4899'},{name:'Front Deltoids',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Position',desc:'Hands on bench behind you, fingers forward. Legs extended in front.'},{title:'Lower',desc:'Bend elbows to 90°. Back stays close to bench.'},{title:'Press Up',desc:'Extend arms to full lockout. Squeeze triceps at top.'}],
    tips:[{text:'Keep back close to bench. The further feet are out, the harder it is.'}]
  },
  { id:16, glb:'exercise16.glb', genre:'arms',        title:'Hammer Curl',
    duration:'3 × 15 reps', difficulty:'beginner',
    description:'Neutral-grip curl targeting brachialis and brachioradialis alongside biceps.',
    muscles:[{name:'Brachialis',type:'Primary',color:'#ec4899'},{name:'Biceps Brachii',type:'Secondary',color:'#64748b'},{name:'Brachioradialis',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Grip',desc:'Stand on band. Hold handles with neutral grip (thumbs up).'},{title:'Curl',desc:'Curl both hands toward shoulders. Keep elbows fixed at sides.'},{title:'Lower',desc:'Slowly extend arms back to full extension.'}],
    tips:[{text:'Elbows stay pinned to your sides — do not let them drift forward.'}]
  },
  { id:17, glb:'exercise17.glb', genre:'arms',        title:'Tricep Overhead Extension',
    duration:'3 × 15 reps', difficulty:'beginner',
    description:'Overhead extension maximally stretches the long head of the triceps.',
    muscles:[{name:'Triceps (Long Head)',type:'Primary',color:'#ec4899'},{name:'Triceps (Lateral)',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Position',desc:'Hold weight overhead with both hands. Elbows point at ceiling.'},{title:'Lower',desc:'Bend elbows, lower weight behind head. Feel the stretch.'},{title:'Extend',desc:'Press weight back overhead. Fully lock out at top.'}],
    tips:[{text:'Keep elbows pointing straight up — do not let them flare wide.'}]
  },

  // ── CORE ──
  { id:18, glb:'exercise18.glb', genre:'core',        title:'Plank',
    duration:'3 × 2-minute holds', difficulty:'beginner',
    description:'Isometric core endurance — the foundation of military fitness.',
    muscles:[{name:'Transverse Abdominis',type:'Primary',color:'#f97316'},{name:'Rectus Abdominis',type:'Primary',color:'#f97316'},{name:'Shoulders',type:'Secondary',color:'#64748b'},{name:'Glutes',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Position',desc:'Forearms on ground, elbows under shoulders. Toes on floor. Body straight as a board.'},{title:'Engage',desc:'Brace core as if expecting a punch. Squeeze glutes. Neutral neck.'},{title:'Hold',desc:'Maintain position. Breathe steadily. Do not allow hips to sag.'}],
    tips:[{text:'Build from 30 seconds. Add 10 seconds each session.'},{text:'Quality over time — a 30-second perfect plank beats 2 minutes sloppy.'}]
  },
  { id:19, glb:'exercise19.glb', genre:'core',        title:'Sit-Up',
    duration:'3 × 50 reps', difficulty:'beginner',
    description:'Standard military sit-up — tested in all Gurkha PT assessments.',
    muscles:[{name:'Rectus Abdominis',type:'Primary',color:'#f97316'},{name:'Hip Flexors',type:'Secondary',color:'#64748b'},{name:'Obliques',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Start',desc:'Lie on back, knees bent to 90°, feet flat. Fingers laced behind head, elbows wide.'},{title:'Crunch Up',desc:'Use abs to curl torso up. Elbows touch or reach past knees.'},{title:'Lower',desc:'Control descent back to floor. Shoulders lightly touch ground between reps.'}],
    tips:[{text:'Drive elbows forward with core, not by yanking the neck.'},{text:'In the Army test: consistent rhythm — all reps in 2 minutes.'}]
  },
  { id:20, glb:'exercise20.glb', genre:'core',        title:'Leg Raise',
    duration:'3 × 20 reps', difficulty:'intermediate',
    description:'Hanging or lying leg raise for lower abdominal and hip flexor strength.',
    muscles:[{name:'Lower Abs',type:'Primary',color:'#f97316'},{name:'Hip Flexors',type:'Primary',color:'#f97316'},{name:'Obliques',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Start',desc:'Lie flat on back, arms at sides. Legs straight.'},{title:'Raise',desc:'Lift legs to 90°, keeping them straight. Exhale on the way up.'},{title:'Lower',desc:'Lower with control. Do not let feet touch the floor between reps.'}],
    tips:[{text:'Slower descent = more time under tension = more abs activation.'}]
  },
  { id:21, glb:'exercise21.glb', genre:'core',        title:'Mountain Climber',
    duration:'4 × 30 seconds', difficulty:'intermediate',
    description:'Dynamic plank variation training core, hip flexors, and cardiovascular system simultaneously.',
    muscles:[{name:'Core',type:'Primary',color:'#f97316'},{name:'Hip Flexors',type:'Primary',color:'#f97316'},{name:'Shoulders',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Start Position',desc:'High plank — arms straight, hands under shoulders. Body rigid plank.'},{title:'Drive Knee',desc:'Drive one knee toward chest explosively. Keep hips down.'},{title:'Alternate',desc:'Switch legs rapidly. Keep core braced, do not let hips bounce.'}],
    tips:[{text:'Faster pace = more cardio. Slow and controlled = more core strength.'}]
  },

  // ── CARDIO ──
  { id:22, glb:'exercise22.glb', genre:'cardio',      title:'3-Mile Run',
    duration:'Target: sub-21 min', difficulty:'intermediate',
    description:'The British Army BPFA 3-mile run — a key Gurkha selection requirement.',
    muscles:[{name:'Quadriceps',type:'Primary',color:'#14b8a6'},{name:'Hamstrings',type:'Primary',color:'#14b8a6'},{name:'Calves',type:'Secondary',color:'#64748b'},{name:'Core',type:'Stabiliser',color:'#64748b'}],
    steps:[{title:'Warm-Up (0.5 miles)',desc:'Start at conversational pace. Activate legs, settle breathing rhythm.'},{title:'Build Phase (Mile 1)',desc:'Increase to 70% effort. Find your race pace. Maintain upright posture.'},{title:'Steady State (Mile 2)',desc:'Hold pace. Consistent cadence 170–180 steps/min. Relax shoulders.'},{title:'Final Push (Last 0.5)',desc:'Increase effort. Drive with arms. Sprint final 200 m.'}],
    tips:[{text:'Breathing rhythm: in 2 steps, out 2 steps.'},{text:'Land midfoot — reduces impact, improves efficiency.'}]
  },
  { id:23, glb:'exercise23.glb', genre:'cardio',      title:'Jump Rope',
    duration:'5 × 3-minute rounds', difficulty:'beginner',
    description:'Skipping rope — high caloric burn, coordination and footwork development.',
    muscles:[{name:'Calves',type:'Primary',color:'#14b8a6'},{name:'Shoulders',type:'Secondary',color:'#64748b'},{name:'Core',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Grip',desc:'Hold handles lightly. Rope behind you. Hands at hip height.'},{title:'Swing and Jump',desc:'Rotate wrists to swing rope. Jump 2–3 cm off ground, landing on balls of feet.'},{title:'Rhythm',desc:'Maintain consistent rhythm. Stay light and relaxed.'}],
    tips:[{text:'Match music BPM to your skipping rhythm for consistent cadence.'}]
  },

  // ── FULL BODY ──
  { id:24, glb:'exercise24.glb', genre:'full_body',   title:'Burpee',
    duration:'5 × 15 reps', difficulty:'advanced',
    description:'Full-body explosive conditioning drill used in British Army selection. Chest to floor, then explosive jump.',
    muscles:[{name:'Full Body',type:'Primary',color:'#3b82f6'},{name:'Quadriceps',type:'Primary',color:'#3b82f6'},{name:'Chest and Triceps',type:'Secondary',color:'#64748b'},{name:'Shoulders',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Standing Start',desc:'Stand feet shoulder-width, arms at sides, chest up.'},{title:'Squat Drop',desc:'Hinge at hips, squat down and place hands on floor directly under shoulders.'},{title:'Jump Back to Plank',desc:'Jump both feet back simultaneously into high plank. Body straight.'},{title:'Chest-to-Floor Push-Up',desc:'Perform one full push-up — chest to floor, then press back up.'},{title:'Jump Forward and Explode Up',desc:'Jump feet back to hands, then explode upward into a jump, hands overhead.'}],
    tips:[{text:'Full chest-to-floor contact on every push-up — no half reps.'},{text:'Consistent pace beats sprinting then burning out.'}]
  },
  { id:25, glb:'exercise25.glb', genre:'full_body',   title:'Bear Crawl',
    duration:'4 × 20 m lengths', difficulty:'intermediate',
    description:'Quadrupedal movement drill building total body coordination, core and shoulder endurance.',
    muscles:[{name:'Core',type:'Primary',color:'#3b82f6'},{name:'Shoulders',type:'Primary',color:'#3b82f6'},{name:'Quadriceps',type:'Secondary',color:'#64748b'},{name:'Hip Flexors',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Start',desc:'On all fours — hands under shoulders, knees just off ground. Back flat.'},{title:'Crawl',desc:'Move opposite hand and foot together. Keep hips low and level.'},{title:'Pace',desc:'Maintain steady crawl. Hips stay down. Breathe rhythmically.'}],
    tips:[{text:'Stay low — your hips should barely rise above your shoulders.'}]
  },
  { id:26, glb:'exercise26.glb', genre:'full_body',   title:'Man Maker',
    duration:'4 × 8 reps', difficulty:'advanced',
    description:'Dumbbell complex combining push-up, row, and squat-to-press in one continuous movement.',
    muscles:[{name:'Full Body',type:'Primary',color:'#3b82f6'},{name:'Back and Biceps',type:'Primary',color:'#3b82f6'},{name:'Chest and Triceps',type:'Secondary',color:'#64748b'},{name:'Legs and Glutes',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Plank',desc:'Hold dumbbells in plank position. Perform a push-up.'},{title:'Row',desc:'Row right dumbbell to chest. Then row left. Keep hips square.'},{title:'Jump to Squat',desc:'Jump feet forward between dumbbells. Into squat rack position.'},{title:'Press',desc:'Stand and press dumbbells overhead to lockout. Lower and repeat.'}],
    tips:[{text:'Use lighter dumbbells than you think — the full complex is brutal.'}]
  },

  // ── HIIT ──
  { id:27, glb:'exercise27.glb', genre:'hiit',        title:'Box Jump',
    duration:'4 × 10 reps', difficulty:'intermediate',
    description:'Explosive plyometric jump training for power, speed, and athleticism.',
    muscles:[{name:'Quadriceps',type:'Primary',color:'#a3e635'},{name:'Glutes',type:'Primary',color:'#a3e635'},{name:'Calves',type:'Secondary',color:'#64748b'},{name:'Hamstrings',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Approach',desc:'Stand 30 cm from box. Feet hip-width, slight forward lean.'},{title:'Load and Swing',desc:'Dip, bending knees and hips. Drive arms forward and up explosively.'},{title:'Jump',desc:'Explode upward. Pull knees to chest to clear box height.'},{title:'Land',desc:'Land softly in quarter-squat. Toes to heels. Absorb the impact.'},{title:'Step Down',desc:'Always step down one foot at a time. Never jump off.'}],
    tips:[{text:'Always step down — jumping off risks Achilles injury.'},{text:'Focus on landing mechanics — soft, controlled, balanced.'}]
  },
  { id:28, glb:'exercise28.glb', genre:'hiit',        title:'Broad Jump',
    duration:'5 × 5 reps', difficulty:'intermediate',
    description:'Horizontal power jump developing explosive leg drive and jumping mechanics.',
    muscles:[{name:'Glutes',type:'Primary',color:'#a3e635'},{name:'Quadriceps',type:'Primary',color:'#a3e635'},{name:'Hamstrings',type:'Secondary',color:'#64748b'},{name:'Calves',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Stance',desc:'Feet shoulder-width at start line. Quarter squat, arms back.'},{title:'Load',desc:'Swing arms back, hinge hips, load legs explosively.'},{title:'Jump',desc:'Drive arms forward, explode off both feet simultaneously.'},{title:'Land',desc:'Land softly in athletic stance. Stick the landing — no forward stumble.'}],
    tips:[{text:'Measure consistently — broad jump is a great power progress marker.'}]
  },

  // ── FLEXIBILITY ──
  { id:29, glb:'exercise29.glb', genre:'flexibility', title:'Hip Flexor Stretch',
    duration:'3 × 60 s each side', difficulty:'beginner',
    description:'Kneeling lunge stretch targeting the hip flexors — critical for runners and soldiers.',
    muscles:[{name:'Hip Flexors (Iliopsoas)',type:'Primary',color:'#a855f7'},{name:'Quadriceps (Rectus Femoris)',type:'Secondary',color:'#64748b'}],
    steps:[{title:'Kneeling Lunge',desc:'Drop to kneeling lunge. Front knee at 90°. Back knee on soft surface.'},{title:'Drive Forward',desc:'Drive hips gently forward until stretch felt in front of back hip and quad.'},{title:'Hold',desc:'Hold 60 seconds. Breathe deeply. Deepen every exhale. Switch sides.'}],
    tips:[{text:'Never force the stretch — ease into it over multiple breaths.'},{text:'Best performed after exercise when muscles are warm.'}]
  },
];

// ── STATE ──
let activeCategory = 'all';
let activeDiff     = 'all';
let searchQuery    = '';

// THREE.JS
let scene3, camera3, renderer3, controls3, mixer3, model3;
let animPaused = false, currentSpeed = 1;
const clock3   = new THREE.Clock();
let loopRunning = false;
let defCamPos   = new THREE.Vector3();
let defCamTgt   = new THREE.Vector3();
const thumbMap  = {};

// ── BUILD CATEGORY DROPDOWN ──
function buildCatDropdown() {
    const dd = document.getElementById('catDropdown');
    CATEGORIES.forEach(cat => {
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
    const btn = document.getElementById('catBtn');
    const dd  = document.getElementById('catDropdown');
    btn.classList.toggle('open');
    dd.classList.toggle('open');
};

function selectCategory(id, label) {
    activeCategory = id;
    const btn = document.getElementById('catBtn');
    const dd  = document.getElementById('catDropdown');
    document.getElementById('catBtnLabel').textContent = label;
    btn.classList.toggle('active-filter', id !== 'all');
    btn.classList.remove('open');
    dd.classList.remove('open');
    document.querySelectorAll('.cat-option').forEach(o => o.classList.toggle('selected', o.dataset.id === id));
    render();
}

// Close dropdown on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('.cat-dropdown-wrap')) {
        document.getElementById('catBtn')?.classList.remove('open');
        document.getElementById('catDropdown')?.classList.remove('open');
    }
});

// ── DIFFICULTY ──
window.setDiff = function(diff, btn) {
    activeDiff = diff;
    document.querySelectorAll('.diff-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    render();
};

// ── SEARCH ──
window.handleSearch = function(q) {
    searchQuery = q.toLowerCase();
    render();
};

// ── FILTER ──
function getFiltered() {
    return EXERCISES.filter(e => {
        const catOk  = activeCategory === 'all' || e.genre === activeCategory;
        const diffOk = activeDiff === 'all' || e.difficulty === activeDiff;
        const qOk    = !searchQuery || e.title.toLowerCase().includes(searchQuery) || e.description.toLowerCase().includes(searchQuery);
        return catOk && diffOk && qOk;
    });
}

// ── RENDER ──
const CAT_LABELS = { chest:'Chest', back:'Back', legs:'Legs', shoulders:'Shoulders', arms:'Arms', core:'Core & Abs', cardio:'Cardio & Endurance', full_body:'Full Body', hiit:'HIIT & Power', flexibility:'Flexibility' };
const DIFF_COLORS = { beginner:'#10b981', intermediate:'#f59e0b', advanced:'#ef4444', expert:'#a855f7' };
const DIFF_PIPS   = { beginner:1, intermediate:2, advanced:3, expert:4 };

function render() {
    const filtered = getFiltered();
    const page = document.getElementById('workoutsPage');
    page.innerHTML = '';
    document.getElementById('filterResults').innerHTML = `<strong>${filtered.length}</strong> exercise${filtered.length !== 1 ? 's' : ''}`;

    if (!filtered.length) {
        page.innerHTML = `<div class="empty-state"><h3>No exercises found</h3><p>Try adjusting the filters or search term.</p></div>`;
        return;
    }

    // Group by category, maintain category order
    const order = ['chest','back','legs','shoulders','arms','core','cardio','full_body','hiit','flexibility'];
    const grouped = {};
    filtered.forEach(e => { if (!grouped[e.genre]) grouped[e.genre] = []; grouped[e.genre].push(e); });
    const keys = order.filter(k => grouped[k]);

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
        setTimeout(() => grouped[key].forEach(ex => initThumb(ex)), 60);
    });
}

// ── BUILD CARD ──
function buildCard(ex) {
    const dc = DIFF_COLORS[ex.difficulty] || '#3b82f6';
    const dp = DIFF_PIPS[ex.difficulty] || 1;
    const pips = [1,2,3,4].map(i => `<div class="diff-pip on" style="color:${i<=dp?dc:'transparent'};background:${i<=dp?dc:'rgba(255,255,255,.08)'}"></div>`).join('');

    const card = document.createElement('div');
    card.className = 'ex-card';
    card.innerHTML = `
        <div class="card-thumb" id="thumb-${ex.id}">
            <div class="card-load" id="cload-${ex.id}"><div class="spinner"></div></div>
            <div class="thumb-overlay"></div>
            <span class="diff-badge ${ex.difficulty}">${ex.difficulty}</span>
            <div class="thumb-placeholder" id="cph-${ex.id}">
                <div class="ph-icon-block">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/></svg>
                </div>
                <div class="ph-label">${ex.title}</div>
            </div>
            <div class="thumb-play">
                <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
            </div>
        </div>
        <div class="card-body">
            <div class="card-title">${ex.title}</div>
            <div class="card-sub">${ex.description}</div>
            <div class="card-meta">
                <div class="diff-pips">${pips}</div>
                <span style="font-size:.68rem;color:${dc};font-weight:600;margin-left:3px;text-transform:capitalize">${ex.difficulty}</span>
                <div class="card-duration">${ex.duration}</div>
            </div>
        </div>
        <div class="card-foot">
            <button class="view-btn" onclick="event.stopPropagation();openExercise(${ex.id})">
                View 3D
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><polyline points="9,18 15,12 9,6"/></svg>
            </button>
            <span class="steps-lbl">${ex.steps.length} steps</span>
        </div>`;
    card.addEventListener('click', () => openExercise(ex.id));
    return card;
}

// ── THUMBNAIL THREE.JS ──
function initThumb(ex) {
    const wrap   = document.getElementById('thumb-' + ex.id);
    const loadEl = document.getElementById('cload-' + ex.id);
    const phEl   = document.getElementById('cph-' + ex.id);
    if (!wrap) return;

    const w = wrap.offsetWidth || 260, h = wrap.offsetHeight || 170;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setSize(w, h);
    renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
    renderer.setClearColor(0x0f172a, 1);
    renderer.domElement.style.cssText = 'position:absolute;inset:0;width:100%!important;height:100%!important;';
    wrap.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, w / h, 0.01, 100);
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    const dl = new THREE.DirectionalLight(0xfff0e8, 1.2); dl.position.set(3,8,5); scene.add(dl);
    const fl = new THREE.DirectionalLight(0x3b82f6, 0.2); fl.position.set(-5,2,-3); scene.add(fl);

    new GLTFLoader().load('../../models/' + ex.glb,
        gltf => {
            const m = gltf.scene;
            const box = new THREE.Box3().setFromObject(m);
            const sz  = box.getSize(new THREE.Vector3());
            const ctr = box.getCenter(new THREE.Vector3());
            m.position.set(-ctr.x, -box.min.y, -ctr.z);
            const sc = sz.y > 0.01 ? 1.8 / sz.y : 1;
            m.scale.setScalar(sc);
            const sh = sz.y * sc;
            camera.position.set(0, sh * 0.55, sh * 1.85);
            camera.lookAt(0, sh * 0.5, 0);
            scene.add(m);
            if (loadEl) loadEl.style.display = 'none';
            if (phEl)   phEl.style.display   = 'none';
            let mx = null;
            if (gltf.animations.length) {
                mx = new THREE.AnimationMixer(m);
                gltf.animations.forEach(clip => mx.clipAction(clip).play());
            }
            const clk = new THREE.Clock();
            thumbMap[ex.id] = { renderer, scene, camera, mixer: mx, model: m, clock: clk };
            runThumb(ex.id);
        },
        undefined,
        () => { if (loadEl) loadEl.style.display='none'; if (phEl) phEl.style.display='flex'; }
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

// ── MODAL THREE.JS ──
function initModal3D() {
    const wrap = document.getElementById('modal-canvas-wrap');
    wrap.innerHTML = '';
    const w = wrap.offsetWidth || 660, h = wrap.offsetHeight || 480;

    renderer3 = new THREE.WebGLRenderer({ antialias: true });
    renderer3.setSize(w, h);
    renderer3.setPixelRatio(Math.min(devicePixelRatio, 2));
    renderer3.shadowMap.enabled = true;
    renderer3.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer3.setClearColor(0x0f172a, 1);
    renderer3.toneMapping = THREE.ACESFilmicToneMapping;
    renderer3.toneMappingExposure = 1.2;
    renderer3.domElement.style.display = 'block';
    wrap.appendChild(renderer3.domElement);

    scene3  = new THREE.Scene();
    camera3 = new THREE.PerspectiveCamera(48, w / h, 0.01, 200);

    scene3.add(new THREE.AmbientLight(0xffffff, 0.5));
    const key = new THREE.DirectionalLight(0xfff5f0, 1.5); key.position.set(4,10,6); key.castShadow=true; key.shadow.mapSize.set(2048,2048); scene3.add(key);
    const fill = new THREE.DirectionalLight(0x3b82f6, 0.3); fill.position.set(-6,3,-4); scene3.add(fill);
    const rim  = new THREE.DirectionalLight(0x6366f1, 0.18); rim.position.set(0,5,-8); scene3.add(rim);

    const floor = new THREE.Mesh(new THREE.CircleGeometry(5,64), new THREE.MeshStandardMaterial({color:0x0a1020,roughness:0.95}));
    floor.rotation.x = -Math.PI/2; floor.receiveShadow = true; scene3.add(floor);
    scene3.add(new THREE.GridHelper(10, 20, 0x1a2035, 0x141925));

    controls3 = new OrbitControls(camera3, renderer3.domElement);
    controls3.enableDamping = true; controls3.dampingFactor = 0.07;
    controls3.minDistance = 0.5; controls3.maxDistance = 20;
    controls3.maxPolarAngle = Math.PI * 0.85;

    new ResizeObserver(() => {
        const w2 = wrap.offsetWidth, h2 = wrap.offsetHeight;
        if (w2>0 && h2>0 && renderer3) { camera3.aspect=w2/h2; camera3.updateProjectionMatrix(); renderer3.setSize(w2,h2); }
    }).observe(wrap);

    if (!loopRunning) { loopRunning=true; runLoop(); }
}

function runLoop() {
    requestAnimationFrame(runLoop);
    const d = clock3.getDelta();
    if (mixer3 && !animPaused) mixer3.update(d);
    if (controls3) controls3.update();
    if (renderer3 && scene3 && camera3) renderer3.render(scene3, camera3);
}

function loadModal(glb) {
    const loadEl = document.getElementById('viewerLoading');
    const loadTx = document.getElementById('viewerLoadText');
    if (loadEl) loadEl.style.display = 'flex';
    if (loadTx) loadTx.textContent   = 'Loading model...';

    if (model3) {
        scene3.remove(model3);
        model3.traverse(c => { if(c.isMesh){ c.geometry?.dispose(); (Array.isArray(c.material)?c.material:[c.material]).forEach(m=>m?.dispose()); }});
        model3 = null;
    }
    if (mixer3) { mixer3.stopAllAction(); mixer3 = null; }
    clock3.getDelta();

    new GLTFLoader().load('../../models/' + glb,
        gltf => {
            model3 = gltf.scene;
            model3.traverse(c => { if(c.isMesh){ c.castShadow=true; c.receiveShadow=true; }});
            scene3.add(model3);

            const box = new THREE.Box3().setFromObject(model3);
            const sz  = box.getSize(new THREE.Vector3());
            const ctr = box.getCenter(new THREE.Vector3());
            model3.position.set(-ctr.x, -box.min.y, -ctr.z);
            const sf  = sz.y > 0.01 ? 1.75 / sz.y : 1;
            model3.scale.setScalar(sf);
            const sh  = sz.y * sf;

            const fovR = THREE.MathUtils.degToRad(camera3.fov);
            const dist = (sh * 1.25) / (2 * Math.tan(fovR / 2));
            camera3.position.set(0.5, sh * 0.52, dist);
            controls3.target.set(0, sh * 0.48, 0);
            controls3.update();
            defCamPos.set(0.5, sh * 0.52, dist);
            defCamTgt.set(0, sh * 0.48, 0);

            if (gltf.animations.length) {
                mixer3 = new THREE.AnimationMixer(model3);
                const act = mixer3.clipAction(gltf.animations[0]);
                act.timeScale = currentSpeed; act.reset().play();
                animPaused = false; updatePlayBtn();
            }
            if (loadEl) loadEl.style.display = 'none';
        },
        p => { if (p.total>0 && loadTx) loadTx.textContent=`Loading... ${Math.round(p.loaded/p.total*100)}%`; },
        err => { console.warn('GLB missing:', glb, err); if (loadTx) loadTx.textContent=`Model unavailable`; setTimeout(()=>{ if(loadEl) loadEl.style.display='none'; },2000); }
    );
}

// ── MODAL OPEN / CLOSE ──
window.openExercise = function(id) {
    const ex = EXERCISES.find(e => e.id === id); if (!ex) return;
    const catLabel = CAT_LABELS[ex.genre] || ex.genre;
    const dc = DIFF_COLORS[ex.difficulty] || '#3b82f6';

    document.getElementById('modalCat').textContent = catLabel.toUpperCase();
    document.getElementById('modalTitle').textContent = ex.title;
    document.getElementById('modalDuration').textContent = ex.duration;
    document.getElementById('modalDiff').textContent = ex.difficulty.charAt(0).toUpperCase() + ex.difficulty.slice(1);
    document.getElementById('modalDiff').style.color = dc;

    document.getElementById('stepsList').innerHTML = ex.steps.map((s,i) => `
        <li class="step-item">
            <div class="step-num">${i+1}</div>
            <div class="step-content"><h4>${s.title}</h4><p>${s.desc}</p></div>
        </li>`).join('');

    document.getElementById('musclesList').innerHTML = ex.muscles.map(m => `
        <div class="muscle-chip">
            <div class="m-dot" style="background:${m.color}"></div>
            <div><div class="m-name">${m.name}</div><div class="m-type">${m.type}</div></div>
        </div>`).join('');

    document.getElementById('tipsList').innerHTML = ex.tips.map((t,i) => `
        <li class="tip-item">
            <span class="tip-lbl">${String(i+1).padStart(2,'0')}</span>
            <span class="tip-text">${t.text}</span>
        </li>`).join('');

    document.querySelectorAll('.m-tab').forEach((t,i)  => t.classList.toggle('active', i===0));
    document.querySelectorAll('.tab-pane').forEach((p,i) => p.classList.toggle('active', i===0));

    document.getElementById('exerciseModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => setTimeout(() => {
        if (!renderer3) initModal3D();
        loadModal(ex.glb);
    }, 60));
};

window.closeModal = function() {
    document.getElementById('exerciseModal').classList.remove('open');
    document.body.style.overflow = '';
};
document.getElementById('exerciseModal').addEventListener('click', e => { if (e.target===e.currentTarget) closeModal(); });

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
    if (s < 0.7) document.getElementById('btnSlow')?.classList.add('active');
    else if (s > 1.2) document.getElementById('btnFast')?.classList.add('active');
};
window.resetCamera = function() {
    if (!camera3 || !controls3) return;
    camera3.position.copy(defCamPos); controls3.target.copy(defCamTgt); controls3.update();
};
window.switchTab = function(name, btn) {
    document.querySelectorAll('.m-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn?.classList.add('active');
    document.getElementById('tab-'+name)?.classList.add('active');
};

document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });

// ── AVATAR (mirrors dashboard) ──
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

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

// ── INIT ──
buildCatDropdown();
render();
renderSbAv();

</script>
</body>
</html>