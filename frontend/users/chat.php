<?php
// frontend/users/chat.php
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}
$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$sub = getActiveSubscription($userId);
if (!$sub) { header('Location: subscription_fixed.php?status=upgrade_required'); exit(); }

$daysLeft    = daysRemaining($userId);
$consultants = getUserConsultants($userId);
$firstName   = explode(' ', trim($user['full_name']))[0];
$initials    = strtoupper(substr($user['full_name'], 0, 1));
$expKey      = strtolower($user['experience_level'] ?? 'beginner');

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$activeConsId = (int)($_GET['consultant_id'] ?? ($consultants[0]['id'] ?? 0));
$activeCons   = null;
foreach ($consultants as $c) { if ($c['id'] === $activeConsId) { $activeCons = $c; break; } }
if (!$activeCons && !empty($consultants)) { $activeCons = $consultants[0]; $activeConsId = $activeCons['id']; }

$messages = [];
if ($activeCons) {
    $messages = fetchAll(
        "SELECT m.*,
                CASE WHEN m.sender_type='user' THEN ? ELSE c.full_name END AS sender_name
         FROM chat_messages m
         JOIN consultants c ON c.id = m.consultant_id
         WHERE m.subscription_id=? AND m.consultant_id=?
         ORDER BY m.created_at ASC",
        [$user['full_name'], $sub['id'], $activeConsId]
    );
    execute(
        "UPDATE chat_messages SET is_read=1
         WHERE subscription_id=? AND consultant_id=? AND sender_type='consultant' AND is_read=0",
        [$sub['id'], $activeConsId]
    );
}

$unreadCounts = [];
foreach ($consultants as $c) {
    $row = fetchOne(
        "SELECT COUNT(*) as cnt FROM chat_messages
         WHERE subscription_id=? AND consultant_id=? AND sender_type='consultant' AND is_read=0",
        [$sub['id'], $c['id']]
    );
    $unreadCounts[$c['id']] = (int)($row['cnt'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!$sub) { echo json_encode(['error'=>'No active subscription']); exit(); }

    $action   = $_POST['ajax_action'];
    $consId   = (int)($_POST['consultant_id'] ?? 0);
    $msgType  = $_POST['message_type'] ?? 'text';
    $body     = trim($_POST['body'] ?? '');
    $filePath = $fileName = null;
    $fileSize = $duration = 0;

    $isAssigned = false;
    foreach ($consultants as $c) { if ($c['id'] === $consId) { $isAssigned = true; break; } }
    if (!$isAssigned) { echo json_encode(['error'=>'Not authorised']); exit(); }

    if ($action === 'send_file' && isset($_FILES['file'])) {
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','xlsx','mp3','m4a','ogg','wav','mp4','mov'];
        if (!in_array($ext, $allowed)) { echo json_encode(['error'=>'File type not allowed']); exit(); }
        if ($f['size'] > 20 * 1024 * 1024) { echo json_encode(['error'=>'Max file size 20MB']); exit(); }
        $uploadDir = BASE_PATH . '/uploads/chat/' . $userId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = time() . '_' . preg_replace('/[^a-z0-9._-]/', '', strtolower($f['name']));
        move_uploaded_file($f['tmp_name'], $uploadDir . $safeName);
        $filePath = '/uploads/chat/' . $userId . '/' . $safeName;
        $fileName = $f['name'];
        $fileSize = $f['size'];
        $imgExts  = ['jpg','jpeg','png','gif'];
        $msgType  = in_array($ext, $imgExts) ? 'image' : (in_array($ext, ['mp3','m4a','ogg','wav']) ? 'voice' : 'file');
    }

    if ($action === 'send_voice' && isset($_FILES['audio'])) {
        $f = $_FILES['audio'];
        $uploadDir = BASE_PATH . '/uploads/chat/' . $userId . '/voices/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = 'voice_' . time() . '.ogg';
        move_uploaded_file($f['tmp_name'], $uploadDir . $safeName);
        $filePath = '/uploads/chat/' . $userId . '/voices/' . $safeName;
        $msgType  = 'voice';
        $duration = (int)($_POST['duration'] ?? 0);
    }

    if (!$body && !$filePath) { echo json_encode(['error'=>'Empty message']); exit(); }

    $msgId = insert(
        "INSERT INTO chat_messages
         (subscription_id, sender_type, sender_id, consultant_id, message_type, body, file_path, file_name, file_size, duration_secs)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$sub['id'], 'user', $userId, $consId, $msgType, $body ?: null, $filePath, $fileName, $fileSize, $duration]
    );

    $msg = fetchOne("SELECT * FROM chat_messages WHERE id=?", [$msgId]);
    echo json_encode(['ok'=>true, 'message'=>$msg, 'sender_name'=>$user['full_name']]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:     #3b82f6;
    --gold:       #fbbf24;
    --success:    #10b981;
    --error:      #ef4444;
    --text-primary:   #f8fafc;
    --text-secondary: #cbd5e1;
    --text-muted:     #64748b;
    --sidebar-bg: rgba(15, 23, 42, 0.97);
    --card-bg:    rgba(30, 41, 59, 0.8);
    --hover-bg:   rgba(59, 130, 246, 0.08);
    --input-bg:   rgba(15, 23, 42, 0.5);
    --border:     rgba(255, 255, 255, 0.07);
    --border-hi:  rgba(255, 255, 255, 0.12);
}

html, body { height: 100%; overflow: hidden; }
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--text-primary);
}

/* ── THREE-COLUMN ROOT ── */
.root { display: flex; height: 100vh; overflow: hidden; }

/* ── LEFT SIDEBAR NAV ── */
.sidebar {
    width: 260px; flex-shrink: 0;
    background: var(--sidebar-bg); backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    padding: 1.75rem 0; overflow-y: auto; z-index: 100;
    display: flex; flex-direction: column;
    transition: transform .3s ease;
}
.sidebar-header { padding: 0 1.25rem 1.25rem; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.logo { display: flex; align-items: center; gap: 10px; margin-bottom: 1.1rem; }
.logo img { width: 34px; height: 34px; object-fit: contain; }
.brand-name {
    font-size: 1.25rem; font-weight: 800;
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.user-card {
    background: rgba(59, 130, 246, 0.07); border: 1px solid rgba(59, 130, 246, 0.18);
    border-radius: 12px; padding: .85rem; display: flex; align-items: center; gap: 10px;
}
.sidebar-av {
    width: 38px; height: 38px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
    border: 1.5px solid rgba(59, 130, 246, 0.35);
    display: flex; align-items: center; justify-content: center;
}
.user-details h3 { font-size: .85rem; font-weight: 600; }
.user-details p  { font-size: .7rem; color: var(--gold); margin-top: 1px; }
.nav-menu { padding: 1.25rem 0; flex: 1; }
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
.nav-item.logout:hover { background: rgba(239, 68, 68, 0.08); border-left-color: #ef4444; }

/* Sub chip in nav */
.sub-chip { margin-left: auto; font-size: .65rem; padding: .15rem .45rem; border-radius: 99px; background: rgba(251,191,36,.12); color: var(--gold); font-weight: 600; border: 1px solid rgba(251,191,36,.2); white-space: nowrap; }

/* ── CONSULTANT LIST COLUMN ── */
.cons-col {
    width: 280px; flex-shrink: 0;
    background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(12px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
}
.cons-col-header {
    padding: 1.1rem 1.1rem .9rem; border-bottom: 1px solid var(--border); flex-shrink: 0;
    background: var(--sidebar-bg);
}
.cons-col-title { font-size: .9rem; font-weight: 700; display: flex; align-items: center; gap: 7px; }
.cons-col-sub   { font-size: .72rem; color: var(--text-muted); margin-top: 3px; }
.cons-list { flex: 1; overflow-y: auto; }
.cons-item {
    display: flex; align-items: center; gap: 10px;
    padding: .85rem 1.1rem; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,.04);
    transition: background .15s; text-decoration: none; border-left: 2.5px solid transparent;
}
.cons-item:hover { background: var(--hover-bg); }
.cons-item.active { background: rgba(59,130,246,.1); border-left-color: var(--accent); }
.cons-avatar {
    width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0; position: relative;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .95rem;
    background: linear-gradient(135deg, #7c3aed, #3b82f6);
}
.cons-online {
    position: absolute; bottom: 1px; right: 1px;
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--success); border: 2px solid #0f172a;
}
.cons-info { flex: 1; min-width: 0; }
.cons-name { font-size: .85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); }
.cons-role-chip { font-size: .65rem; padding: .12rem .4rem; border-radius: 5px; font-weight: 600; display: inline-block; margin-top: 2px; }
.role-consultant { background: rgba(59,130,246,.15); color: #93c5fd; }
.role-dietician  { background: rgba(16,185,129,.15); color: #6ee7b7; }
.cons-unread { background: var(--accent); color: #fff; font-size: .65rem; font-weight: 700; border-radius: 99px; padding: .15rem .45rem; min-width: 18px; text-align: center; flex-shrink: 0; }
.no-cons { padding: 2rem 1.1rem; text-align: center; color: var(--text-muted); font-size: .82rem; line-height: 1.7; }
.no-cons a { color: var(--accent); text-decoration: none; }

/* ── CHAT MAIN ── */
.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; background: rgba(7,12,22,.4); }

/* Chat header */
.chat-header {
    padding: .9rem 1.5rem; background: var(--sidebar-bg); backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.chat-header-av {
    width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #7c3aed, #3b82f6);
    display: flex; align-items: center; justify-content: center; font-weight: 700;
}
.chat-header-name { font-size: .95rem; font-weight: 700; }
.chat-header-meta { font-size: .72rem; color: var(--text-secondary); margin-top: 2px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.online-dot { display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; color: var(--success); }
.days-badge { font-size: .7rem; padding: .18rem .5rem; border-radius: 6px; background: rgba(251,191,36,.1); color: var(--gold); border: 1px solid rgba(251,191,36,.2); margin-left: auto; white-space: nowrap; }

/* Messages */
.messages-wrap { flex: 1; overflow-y: auto; padding: 1.1rem 1.5rem; display: flex; flex-direction: column; gap: .6rem; }
.msg { display: flex; align-items: flex-end; gap: 8px; max-width: 70%; }
.msg.from-user { align-self: flex-end; flex-direction: row-reverse; }
.msg.from-cons { align-self: flex-start; }
.msg-av {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #7c3aed, #3b82f6);
    display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700;
}
.msg-bubble {
    padding: .6rem .95rem; border-radius: 14px;
    font-size: .875rem; line-height: 1.55; max-width: 100%; word-break: break-word;
}
.msg.from-user .msg-bubble {
    background: linear-gradient(135deg, var(--accent), #1d4ed8);
    color: #fff; border-bottom-right-radius: 4px;
}
.msg.from-cons .msg-bubble {
    background: var(--card-bg); border: 1px solid var(--border);
    color: var(--text-primary); border-bottom-left-radius: 4px;
}
.msg-wrap { display: flex; flex-direction: column; }
.msg-time { font-size: .63rem; color: var(--text-muted); margin-top: 3px; padding: 0 .25rem; }
.msg.from-user .msg-time { text-align: right; }

/* Media messages */
.msg-image { max-width: 220px; border-radius: 10px; cursor: pointer; display: block; }
.msg-file {
    display: flex; align-items: center; gap: 8px; padding: .45rem .7rem;
    background: rgba(255,255,255,.06); border-radius: 8px; text-decoration: none;
    color: var(--text-primary); font-size: .8rem;
}
.voice-msg { display: flex; align-items: center; gap: 8px; min-width: 160px; }
.voice-play {
    width: 30px; height: 30px; border-radius: 50%; background: var(--accent);
    border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #fff;
}
.voice-waveform { flex: 1; height: 22px; display: flex; align-items: center; gap: 2px; }
.voice-bar { width: 2px; border-radius: 1px; background: rgba(255,255,255,.35); }
.voice-dur { font-size: .7rem; color: var(--text-muted); white-space: nowrap; }

/* Date divider */
.date-divider {
    display: flex; align-items: center; gap: .65rem;
    font-size: .7rem; color: var(--text-muted); margin: .4rem 0;
}
.date-divider::before, .date-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Input area */
.input-area { padding: .85rem 1.25rem; background: var(--sidebar-bg); border-top: 1px solid var(--border); flex-shrink: 0; }
.recording-bar {
    display: none; align-items: center; gap: .65rem; padding: .45rem .75rem;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25);
    border-radius: 8px; margin-bottom: .5rem; font-size: .8rem; color: #fca5a5;
}
.recording-bar.active { display: flex; }
.rec-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--error); animation: blink 1s infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.rec-timer { font-family: monospace; font-weight: 700; }

.input-row {
    display: flex; align-items: flex-end; gap: .4rem;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: .35rem .45rem .35rem .75rem;
    transition: border-color .2s;
}
.input-row:focus-within { border-color: rgba(59,130,246,.4); }
.msg-input {
    flex: 1; background: transparent; border: none; outline: none;
    color: var(--text-primary); font-size: .88rem; line-height: 1.4;
    resize: none; max-height: 120px; padding: .35rem 0;
    font-family: 'Poppins', sans-serif;
}
.msg-input::placeholder { color: var(--text-muted); }
.input-actions { display: flex; gap: .25rem; align-items: flex-end; padding-bottom: .1rem; }
.action-btn {
    width: 34px; height: 34px; border-radius: 8px; border: none; cursor: pointer;
    background: transparent; color: var(--text-muted); display: flex;
    align-items: center; justify-content: center; transition: all .2s; font-size: 1rem;
}
.action-btn:hover { background: rgba(255,255,255,.06); color: var(--text-primary); }
.send-btn { background: var(--accent); color: #fff; }
.send-btn:hover { background: #1d4ed8; }

/* Typing dots */
.typing-indicator { display: none; align-items: center; gap: 6px; padding: .45rem 0; font-size: .78rem; color: var(--text-muted); }
.typing-dots span { width: 5px; height: 5px; border-radius: 50%; background: var(--text-muted); display: inline-block; animation: bounce .9s infinite; }
.typing-dots span:nth-child(2) { animation-delay: .15s; }
.typing-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-5px)} }

/* Empty state */
.no-chat-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; color: var(--text-muted); }
.no-chat-icon  { font-size: 3.5rem; }
.no-chat-title { font-size: 1.05rem; font-weight: 700; color: var(--text-secondary); }
.no-chat-desc  { font-size: .82rem; text-align: center; max-width: 280px; line-height: 1.6; }

/* Scrollbar */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }

/* Mobile */
.mobile-menu-btn {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}

@media (max-width: 1024px) { .sidebar { display: none; } }
@media (max-width: 768px)  {
    .cons-col { width: 220px; }
    .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
}
@media (max-width: 560px)  { .cons-col { display: none; } }
</style>
</head>
<body>
<div class="root">

<!-- ── LEFT SIDEBAR ── -->
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
                <p>⭐ Premium Member</p>
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
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
            Progress
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions & Quiz
        </a>
        <a href="chat.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            Chat with Expert
        </a>
        <div class="nav-section-title" style="margin-top:.75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="subscription_fixed.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
            <span class="sub-chip"><?= $daysLeft ?>d</span>
        </a>
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- ── CONSULTANT LIST ── -->
<div class="cons-col">
    <div class="cons-col-header">
        <div class="cons-col-title">💬 Your Experts</div>
        <div class="cons-col-sub"><?= count($consultants) ?> assigned · Premium active</div>
    </div>
    <div class="cons-list">
        <?php if (empty($consultants)): ?>
        <div class="no-cons">
            No consultant assigned yet.<br>Admin will assign one shortly.<br><br>
            <a href="questions.php">Browse Q&amp;A →</a>
        </div>
        <?php else: ?>
        <?php foreach ($consultants as $c): $unread = $unreadCounts[$c['id']] ?? 0; ?>
        <a href="chat.php?consultant_id=<?= $c['id'] ?>"
           class="cons-item <?= $c['id'] === $activeConsId ? 'active' : '' ?>">
            <div class="cons-avatar">
                <?= strtoupper(substr($c['full_name'], 0, 1)) ?>
                <div class="cons-online"></div>
            </div>
            <div class="cons-info">
                <div class="cons-name"><?= htmlspecialchars($c['full_name']) ?></div>
                <span class="cons-role-chip role-<?= $c['role'] ?>"><?= ucfirst($c['role']) ?></span>
            </div>
            <?php if ($unread > 0): ?>
            <div class="cons-unread"><?= $unread ?></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── CHAT MAIN ── -->
<div class="chat-main">
    <?php if ($activeCons): ?>

    <div class="chat-header">
        <div class="chat-header-av"><?= strtoupper(substr($activeCons['full_name'], 0, 1)) ?></div>
        <div style="flex:1">
            <div class="chat-header-name"><?= htmlspecialchars($activeCons['full_name']) ?></div>
            <div class="chat-header-meta">
                <span class="online-dot">
                    <svg width="6" height="6" viewBox="0 0 6 6" fill="currentColor"><circle cx="3" cy="3" r="3"/></svg>
                    Online
                </span>
                &nbsp;·&nbsp; <?= ucfirst($activeCons['role']) ?>
                <?php if (!empty($activeCons['speciality'])): ?>&nbsp;·&nbsp; <?= htmlspecialchars($activeCons['speciality']) ?><?php endif; ?>
            </div>
        </div>
        <span class="days-badge">⭐ <?= $daysLeft ?>d left</span>
    </div>

    <div class="messages-wrap" id="messages">
        <?php if (empty($messages)): ?>
        <div style="text-align:center;color:var(--text-muted);margin:auto;font-size:.85rem;line-height:1.7">
            👋 Start your conversation with <strong style="color:var(--text-secondary)"><?= htmlspecialchars($activeCons['full_name']) ?></strong>
        </div>
        <?php else: ?>
        <?php
        $lastDate = '';
        foreach ($messages as $msg):
            $msgDate = date('d M Y', strtotime($msg['created_at']));
            $isUser  = ($msg['sender_type'] === 'user');
        ?>
            <?php if ($msgDate !== $lastDate): $lastDate = $msgDate; ?>
            <div class="date-divider"><?= $msgDate === date('d M Y') ? 'Today' : $msgDate ?></div>
            <?php endif; ?>
            <div class="msg <?= $isUser ? 'from-user' : 'from-cons' ?>">
                <?php if (!$isUser): ?>
                <div class="msg-av"><?= strtoupper(substr($activeCons['full_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <div class="msg-wrap">
                    <div class="msg-bubble">
                        <?php if ($msg['message_type'] === 'image' && $msg['file_path']): ?>
                            <img src="<?= htmlspecialchars($msg['file_path']) ?>" class="msg-image" onclick="window.open(this.src)" alt="Image">
                        <?php elseif ($msg['message_type'] === 'voice' && $msg['file_path']): ?>
                            <div class="voice-msg">
                                <button class="voice-play" onclick="playVoice('<?= htmlspecialchars($msg['file_path']) ?>', this)">▶</button>
                                <div class="voice-waveform">
                                    <?php for ($i = 0; $i < 20; $i++): $h = rand(6, 22); ?>
                                    <div class="voice-bar" style="height:<?= $h ?>px"></div>
                                    <?php endfor; ?>
                                </div>
                                <span class="voice-dur"><?= $msg['duration_secs'] ? gmdate('i:s', $msg['duration_secs']) : '0:00' ?></span>
                            </div>
                        <?php elseif ($msg['message_type'] === 'file' && $msg['file_path']): ?>
                            <a href="<?= htmlspecialchars($msg['file_path']) ?>" class="msg-file" target="_blank">
                                <span>📎</span>
                                <span><?= htmlspecialchars($msg['file_name'] ?? 'File') ?></span>
                            </a>
                        <?php else: ?>
                            <?= nl2br(htmlspecialchars($msg['body'] ?? '')) ?>
                        <?php endif; ?>
                    </div>
                    <div class="msg-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <div class="typing-indicator" id="typingIndicator">
            <div class="msg-av" style="width:24px;height:24px;font-size:.65rem"><?= strtoupper(substr($activeCons['full_name'], 0, 1)) ?></div>
            <div class="typing-dots"><span></span><span></span><span></span></div>
            <span><?= htmlspecialchars($activeCons['full_name']) ?> is typing…</span>
        </div>
    </div>

    <!-- Input area -->
    <div class="input-area">
        <div class="recording-bar" id="recordingBar">
            <div class="rec-dot"></div>
            <span>Recording</span>
            <span class="rec-timer" id="recTimer">0:00</span>
            <span style="margin-left:auto;cursor:pointer" onclick="cancelRecording()">✕ Cancel</span>
            <button onclick="stopRecording()" style="background:var(--error);color:#fff;border:none;border-radius:6px;padding:.25rem .65rem;cursor:pointer;font-size:.8rem;font-weight:600;font-family:'Poppins',sans-serif">Send</button>
        </div>
        <div class="input-row">
            <textarea class="msg-input" id="msgInput"
                placeholder="Message <?= htmlspecialchars($activeCons['full_name']) ?>…"
                rows="1" onkeydown="handleKeyDown(event)" oninput="autoResize(this)"></textarea>
            <div class="input-actions">
                <button class="action-btn" title="Attach file" onclick="document.getElementById('fileInput').click()">📎</button>
                <input type="file" id="fileInput" style="display:none" accept="image/*,.pdf,.doc,.docx,.xlsx" onchange="sendFile(this)">
                <button class="action-btn" title="Camera" onclick="document.getElementById('cameraInput').click()">📷</button>
                <input type="file" id="cameraInput" style="display:none" accept="image/*" capture="environment" onchange="sendFile(this)">
                <button class="action-btn" id="voiceBtn" title="Voice message" onclick="toggleRecording()">🎤</button>
                <button class="action-btn send-btn" onclick="sendTextMessage()" title="Send">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="no-chat-state">
        <div class="no-chat-icon">💬</div>
        <div class="no-chat-title">Select a consultant</div>
        <div class="no-chat-desc">
            <?php if (empty($consultants)): ?>
            No consultant has been assigned yet. Admin will assign one shortly after payment verification.
            <?php else: ?>
            Choose an expert from the left panel to start your conversation.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /chat-main -->
</div><!-- /root -->

<button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
const CONSULTANT_ID   = <?= $activeConsId ?>;
const SUBSCRIPTION_ID = <?= $sub['id'] ?? 0 ?>;
const USER_INITIAL    = <?= json_encode($initials) ?>;

// ── Avatar (mirrors dashboard.php) ───────────────────────────────────────
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;

const AV_OPTIONS = {
    bg: [
        { id:'grad1', grad:['#1e3a5f','#2563eb'] }, { id:'grad2', grad:['#14532d','#16a34a'] },
        { id:'grad3', grad:['#7f1d1d','#dc2626'] }, { id:'grad4', grad:['#312e81','#7c3aed'] },
        { id:'grad5', grad:['#78350f','#d97706'] }, { id:'grad6', grad:['#134e4a','#0d9488'] },
        { id:'grad7', grad:['#0f172a','#1e293b'] }, { id:'grad8', grad:['#4c0519','#e11d48'] },
    ],
    skin: [
        { id:'s1', color:'#FDDBB4' }, { id:'s2', color:'#F5C89A' }, { id:'s3', color:'#D4956A' },
        { id:'s4', color:'#C47C45' }, { id:'s5', color:'#A05C2C' }, { id:'s6', color:'#7D3F17' },
        { id:'s7', color:'#5C2B0D' }, { id:'s8', color:'#3B1A08' },
    ],
    hairColor: [
        { id:'black', color:'#1a1a1a' }, { id:'brown', color:'#5C3A1E' }, { id:'auburn', color:'#922B21' },
        { id:'blonde', color:'#D4A843' }, { id:'gray', color:'#9CA3AF' }, { id:'white', color:'#F5F5F5' },
        { id:'red', color:'#B91C1C' }, { id:'blue', color:'#1D4ED8' }, { id:'purple', color:'#7C3AED' }, { id:'green', color:'#15803D' },
    ],
    eyes: [
        { id:'brown', color:'#6B3F1A' }, { id:'hazel', color:'#8B6914' }, { id:'green', color:'#15803D' },
        { id:'blue', color:'#1D4ED8' }, { id:'gray', color:'#6B7280' }, { id:'black', color:'#111827' },
        { id:'amber', color:'#D97706' }, { id:'violet', color:'#7C3AED' },
    ],
};

function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size / 2, cy = size / 2;
    const bg   = AV_OPTIONS.bg.find(o => o.id === state.bg)              || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o => o.id === state.skin)          || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o => o.id === state.hairColor)|| AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o => o.id === state.eyes)          || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0, bg.grad[0]); grad.addColorStop(1, bg.grad[1]);
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(cx,cy,cx,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]); ctx.fill();
    const shirt = ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle = shirt;
    ctx.beginPath(); ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle = hCol.color==='#F5F5F5'?'#C0A080':hCol.color;
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
    ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
    ctx.fillRect(cx-44,cy-40,88,20);
    ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
    if(acc.includes('glasses')){
        ctx.strokeStyle='#64748b'; ctx.lineWidth=2.5; ctx.fillStyle='rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx-32,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-5); ctx.lineTo(cx+8,cy-5); ctx.stroke();
    }
}

function renderSidebarAvatar(){
    const c = document.getElementById('sbAvContainer');
    if(!c) return;
    c.innerHTML='';
    if(SAVED_AVATAR_TYPE==='photo'&&SAVED_PHOTO_URL){
        const img=document.createElement('img');
        img.src=SAVED_PHOTO_URL;
        img.style.cssText='width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror=()=>renderInitialSb(c);
        c.appendChild(img);
    } else if(SAVED_AVATAR_TYPE==='ai'&&SAVED_AVATAR_CONFIG){
        const canvas=document.createElement('canvas');
        canvas.style.cssText='width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(canvas);
        drawAvatarOnCanvas(canvas,SAVED_AVATAR_CONFIG,38);
    } else {
        renderInitialSb(c);
    }
}
function renderInitialSb(c){
    const d=document.createElement('div');
    d.style.cssText='width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent=USER_INITIAL;
    c.appendChild(d);
}

// ── Chat logic ────────────────────────────────────────────────────────────
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}
function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendTextMessage(); }
}
function scrollToBottom() {
    const m = document.getElementById('messages');
    if (m) m.scrollTop = m.scrollHeight;
}
scrollToBottom();

async function sendTextMessage() {
    const input = document.getElementById('msgInput');
    const body  = input.value.trim();
    if (!body) return;
    input.value = ''; input.style.height = 'auto';
    appendMessage({ message_type:'text', body, sender_type:'user', created_at: new Date().toISOString() });
    const fd = new FormData();
    fd.append('ajax_action','send_text'); fd.append('consultant_id', CONSULTANT_ID);
    fd.append('message_type','text'); fd.append('body', body);
    try { await (await fetch('chat.php', { method:'POST', body:fd })).json(); } catch(e) {}
}

async function sendFile(input) {
    if (!input.files[0]) return;
    const file = input.files[0];
    const isImage = file.type.startsWith('image/');
    if (isImage) {
        appendMessage({ message_type:'image', file_path: URL.createObjectURL(file), sender_type:'user', created_at: new Date().toISOString() });
    } else {
        appendMessage({ message_type:'file', file_name: file.name, sender_type:'user', created_at: new Date().toISOString() });
    }
    const fd = new FormData();
    fd.append('ajax_action','send_file'); fd.append('consultant_id', CONSULTANT_ID);
    fd.append('message_type', isImage ? 'image' : 'file'); fd.append('file', file);
    try { await (await fetch('chat.php', { method:'POST', body:fd })).json(); } catch(e) {}
    input.value = '';
}

let mediaRec, audioChunks = [], recInterval, recSecs = 0, recStream;
async function toggleRecording() {
    if (mediaRec && mediaRec.state === 'recording') stopRecording();
    else startRecording();
}
async function startRecording() {
    try {
        recStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks = []; recSecs = 0;
        mediaRec = new MediaRecorder(recStream);
        mediaRec.ondataavailable = e => audioChunks.push(e.data);
        mediaRec.start();
        document.getElementById('recordingBar').classList.add('active');
        document.getElementById('voiceBtn').style.color = 'var(--error)';
        recInterval = setInterval(() => {
            recSecs++;
            const m = Math.floor(recSecs/60), s = recSecs%60;
            document.getElementById('recTimer').textContent = m+':'+String(s).padStart(2,'0');
        }, 1000);
    } catch(e) { alert('Microphone access denied.'); }
}
async function stopRecording() {
    if (!mediaRec) return;
    clearInterval(recInterval);
    mediaRec.stop(); recStream.getTracks().forEach(t => t.stop());
    document.getElementById('recordingBar').classList.remove('active');
    document.getElementById('voiceBtn').style.color = '';
    mediaRec.onstop = async () => {
        const blob = new Blob(audioChunks, { type:'audio/ogg; codecs=opus' });
        appendMessage({ message_type:'voice', sender_type:'user', created_at: new Date().toISOString(), duration_secs: recSecs });
        const fd = new FormData();
        fd.append('ajax_action','send_voice'); fd.append('consultant_id', CONSULTANT_ID);
        fd.append('audio', blob, 'voice.ogg'); fd.append('duration', recSecs);
        try { await fetch('chat.php', { method:'POST', body:fd }); } catch(e) {}
    };
}
function cancelRecording() {
    if (mediaRec && mediaRec.state === 'recording') {
        clearInterval(recInterval); mediaRec.stop(); recStream.getTracks().forEach(t => t.stop()); audioChunks=[];
    }
    document.getElementById('recordingBar').classList.remove('active');
    document.getElementById('voiceBtn').style.color = '';
}

const CONS_INITIAL = '<?= $activeCons ? strtoupper(substr($activeCons['full_name'], 0, 1)) : 'C' ?>';
function appendMessage(msg) {
    const isUser = msg.sender_type === 'user';
    const time   = new Date(msg.created_at).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
    let bubbleContent = '';
    if (msg.message_type === 'image' && msg.file_path) {
        bubbleContent = `<img src="${escHtml(msg.file_path)}" class="msg-image" onclick="window.open(this.src)" alt="Image">`;
    } else if (msg.message_type === 'voice') {
        const dur  = msg.duration_secs ? Math.floor(msg.duration_secs/60)+':'+String(msg.duration_secs%60).padStart(2,'0') : '0:00';
        const bars = Array.from({length:20}, ()=>`<div class="voice-bar" style="height:${6+Math.random()*16}px"></div>`).join('');
        bubbleContent = `<div class="voice-msg"><button class="voice-play" onclick="playVoice('${msg.file_path||''}',this)">▶</button><div class="voice-waveform">${bars}</div><span class="voice-dur">${dur}</span></div>`;
    } else if (msg.message_type === 'file') {
        bubbleContent = `<a href="${escHtml(msg.file_path||'#')}" class="msg-file" target="_blank"><span>📎</span><span>${escHtml(msg.file_name||'File')}</span></a>`;
    } else {
        bubbleContent = escHtml(msg.body||'').replace(/\n/g,'<br>');
    }
    const el = document.createElement('div');
    el.className = 'msg ' + (isUser ? 'from-user' : 'from-cons');
    el.innerHTML = `
        ${!isUser ? `<div class="msg-av">${CONS_INITIAL}</div>` : ''}
        <div class="msg-wrap">
            <div class="msg-bubble">${bubbleContent}</div>
            <div class="msg-time">${time}</div>
        </div>`;
    const msgs = document.getElementById('messages');
    msgs.appendChild(el);
    scrollToBottom();
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let activeAudio = null;
function playVoice(src, btn) {
    if (!src) return;
    if (activeAudio) { activeAudio.pause(); activeAudio = null; }
    activeAudio = new Audio(src);
    activeAudio.play();
    btn.textContent = '⏸';
    activeAudio.onended = () => { btn.textContent = '▶'; activeAudio = null; };
}

let lastMsgId = <?= end($messages) ? end($messages)['id'] : 0 ?>;
setInterval(async () => {
    try {
        const r = await fetch(`chat_poll.php?consultant_id=${CONSULTANT_ID}&after=${lastMsgId}`);
        const data = await r.json();
        if (data.messages && data.messages.length) {
            data.messages.forEach(m => { appendMessage(m); lastMsgId = Math.max(lastMsgId, m.id); });
        }
    } catch(e) {}
}, 4000);

document.addEventListener('DOMContentLoaded', renderSidebarAvatar);
</script>
</body>
</html>