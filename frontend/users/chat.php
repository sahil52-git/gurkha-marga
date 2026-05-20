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
$user   = fetchOne("SELECT * FROM users WHERE id=? LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$sub = getActiveSubscription($userId);
if (!$sub) { header('Location: subscription_fixed.php?status=upgrade_required'); exit(); }

// ── Bootstrap tables 
foreach ([
    "CREATE TABLE IF NOT EXISTS user_consultants (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
        consultant_id INT DEFAULT NULL, dietitian_id INT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS consultant_id INT DEFAULT NULL",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS dietitian_id  INT DEFAULT NULL",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1",
    "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subscription_id INT NOT NULL DEFAULT 0,
        sender_type ENUM('user','consultant') NOT NULL DEFAULT 'user',
        sender_id INT NOT NULL,
        recipient_user_id INT NOT NULL DEFAULT 0,
        consultant_id INT NOT NULL,
        message_type ENUM('text','image','file','voice') NOT NULL DEFAULT 'text',
        body TEXT DEFAULT NULL,
        file_path VARCHAR(500) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_size INT DEFAULT 0,
        duration_secs INT DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        deleted_by_user TINYINT(1) NOT NULL DEFAULT 0,
        deleted_by_staff TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_conv (recipient_user_id, consultant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS deleted_by_user TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS deleted_by_staff TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS subscription_id INT NOT NULL DEFAULT 0",
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS recipient_user_id INT NOT NULL DEFAULT 0",
] as $ddl) { try { query($ddl, []); } catch(Exception $e) {} }

// ── Load user's chosen team ────────────────────────────────────────────────────
$myRow     = fetchOne("SELECT * FROM user_consultants WHERE user_id=? LIMIT 1", [$userId]);
$teamStaff = [];
if ($myRow) {
    if (!empty($myRow['consultant_id'])) {
        $c = fetchOne("SELECT * FROM admin_staff WHERE id=? AND is_active=1", [(int)$myRow['consultant_id']]);
        if ($c) $teamStaff[] = $c;
    }
    if (!empty($myRow['dietitian_id'])) {
        $d = fetchOne("SELECT * FROM admin_staff WHERE id=? AND is_active=1", [(int)$myRow['dietitian_id']]);
        if ($d) $teamStaff[] = $d;
    }
}

// ── Active staff from URL ──────────────────────────────────────────────────────
$activeStaffId = (int)($_GET['staff_id'] ?? $_GET['consultant_id'] ?? ($teamStaff[0]['id'] ?? 0));
$activeStaff   = null;
foreach ($teamStaff as $s) {
    if ((int)$s['id'] === $activeStaffId) { $activeStaff = $s; break; }
}
if (!$activeStaff && !empty($teamStaff)) {
    $activeStaff   = $teamStaff[0];
    $activeStaffId = (int)$activeStaff['id'];
}

// ── Load messages ─────────────────────────────────────────────────────────────
// KEY: recipient_user_id = $userId ties every message to this specific user
// This prevents messages from bleeding across different users
$messages = [];
if ($activeStaff) {
    $messages = fetchAll(
        "SELECT * FROM chat_messages
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND deleted_by_user = 0
         ORDER BY created_at ASC",
        [$activeStaffId, $userId]
    ) ?: [];

    // Mark staff messages as read
    execute(
        "UPDATE chat_messages SET is_read = 1
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND sender_type = 'consultant'
           AND is_read = 0
           AND deleted_by_user = 0",
        [$activeStaffId, $userId]
    );
}

// ── Unread counts per staff member ────────────────────────────────────────────
$unreadCounts = [];
foreach ($teamStaff as $s) {
    $row = fetchOne(
        "SELECT COUNT(*) AS cnt FROM chat_messages
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND sender_type = 'consultant'
           AND is_read = 0
           AND deleted_by_user = 0",
        [(int)$s['id'], $userId]
    );
    $unreadCounts[$s['id']] = (int)($row['cnt'] ?? 0);
}

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $consId = (int)($_POST['consultant_id'] ?? 0);

    // Verify this staff member is in user's team
    $isAssigned = false;
    foreach ($teamStaff as $s) {
        if ((int)$s['id'] === $consId) { $isAssigned = true; break; }
    }

    // ── Poll for new staff messages ────────────────────────────────────────────
    if ($action === 'poll') {
        if (!$isAssigned) { echo json_encode(['messages' => []]); exit(); }
        $afterId = (int)($_POST['after_id'] ?? 0);

        // KEY FIX: filter by recipient_user_id so we only get messages FOR this user
        $newMsgs = fetchAll(
            "SELECT * FROM chat_messages
             WHERE consultant_id = ?
               AND recipient_user_id = ?
               AND id > ?
               AND deleted_by_user = 0
               AND sender_type = 'consultant'
             ORDER BY created_at ASC",
            [$consId, $userId, $afterId]
        ) ?: [];

        if (!empty($newMsgs)) {
            execute(
                "UPDATE chat_messages SET is_read = 1
                 WHERE consultant_id = ?
                   AND recipient_user_id = ?
                   AND sender_type = 'consultant'
                   AND is_read = 0",
                [$consId, $userId]
            );
        }
        echo json_encode(['messages' => $newMsgs]);
        exit();
    }

    // ── Delete single message ──────────────────────────────────────────────────
    if ($action === 'delete_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $msg   = fetchOne(
            "SELECT * FROM chat_messages
             WHERE id = ? AND sender_id = ? AND sender_type = 'user' AND recipient_user_id = ?",
            [$msgId, $userId, $userId]
        );
        if ($msg) {
            execute("UPDATE chat_messages SET deleted_by_user = 1 WHERE id = ?", [$msgId]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Not authorised']);
        }
        exit();
    }

    // ── Clear entire conversation ──────────────────────────────────────────────
    if ($action === 'clear_chat') {
        if (!$isAssigned) { echo json_encode(['error' => 'Not authorised']); exit(); }
        execute(
            "UPDATE chat_messages SET deleted_by_user = 1
             WHERE consultant_id = ? AND recipient_user_id = ?",
            [$consId, $userId]
        );
        echo json_encode(['ok' => true]);
        exit();
    }

    if (!$isAssigned) { echo json_encode(['error' => 'Not authorised']); exit(); }

    // ── Send text ──────────────────────────────────────────────────────────────
    if ($action === 'send_text') {
        $body = trim($_POST['body'] ?? '');
        if (!$body) { echo json_encode(['error' => 'Empty message']); exit(); }

        // KEY: always store recipient_user_id = $userId so messages are scoped to this user
        $msgId = insert(
            "INSERT INTO chat_messages
             (subscription_id, sender_type, sender_id, recipient_user_id, consultant_id, message_type, body, is_read)
             VALUES (?, 'user', ?, ?, ?, 'text', ?, 0)",
            [(int)($sub['id'] ?? 0), $userId, $userId, $consId, $body]
        );

        if (!$msgId) { echo json_encode(['error' => 'DB insert failed']); exit(); }

        $msg = fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit();
    }

    // ── Send file / image ──────────────────────────────────────────────────────
    if ($action === 'send_file' && isset($_FILES['file'])) {
        $f   = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xlsx','xls','txt','zip','mp3','m4a','ogg','wav'];
        if (!in_array($ext, $allowed)) { echo json_encode(['error' => 'File type not allowed']); exit(); }
        if ($f['size'] > 20 * 1024 * 1024) { echo json_encode(['error' => 'Max 20MB']); exit(); }

        $uploadDir = BASE_PATH . '/uploads/chat/' . $userId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = time() . '_' . preg_replace('/[^a-z0-9._-]/', '', strtolower(basename($f['name'])));
        move_uploaded_file($f['tmp_name'], $uploadDir . $safeName);

        $filePath = '/gurkha-marga/uploads/chat/' . $userId . '/' . $safeName;
        $msgType  = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'image' : 'file';

        $msgId = insert(
            "INSERT INTO chat_messages
             (subscription_id, sender_type, sender_id, recipient_user_id, consultant_id, message_type, file_path, file_name, file_size, is_read)
             VALUES (?, 'user', ?, ?, ?, ?, ?, ?, ?, 0)",
            [(int)($sub['id'] ?? 0), $userId, $userId, $consId, $msgType, $filePath, $f['name'], $f['size']]
        );

        if (!$msgId) { echo json_encode(['error' => 'DB insert failed']); exit(); }

        $msg = fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit();
    }

    // ── Send voice ─────────────────────────────────────────────────────────────
    if ($action === 'send_voice' && isset($_FILES['audio'])) {
        $f         = $_FILES['audio'];
        $uploadDir = BASE_PATH . '/uploads/chat/' . $userId . '/voices/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName  = 'voice_' . time() . '.ogg';
        move_uploaded_file($f['tmp_name'], $uploadDir . $safeName);

        $filePath = '/gurkha-marga/uploads/chat/' . $userId . '/voices/' . $safeName;
        $duration = (int)($_POST['duration'] ?? 0);

        $msgId = insert(
            "INSERT INTO chat_messages
             (subscription_id, sender_type, sender_id, recipient_user_id, consultant_id, message_type, file_path, duration_secs, is_read)
             VALUES (?, 'user', ?, ?, ?, 'voice', ?, ?, 0)",
            [(int)($sub['id'] ?? 0), $userId, $userId, $consId, $filePath, $duration]
        );

        if (!$msgId) { echo json_encode(['error' => 'DB insert failed']); exit(); }

        $msg = fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit();
    }

    echo json_encode(['error' => 'Unknown action or missing file']);
    exit();
}

// ── View helpers ───────────────────────────────────────────────────────────────
$daysLeft         = daysRemaining($userId);
$initials         = strtoupper(substr($user['full_name'], 0, 1));
$avatarType       = $user['avatar_type']   ?? 'initial';
$profilePhoto     = $user['profile_photo'] ?? null;
$avatarConfig     = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl         = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';
$expKey           = strtolower($user['experience_level'] ?? 'beginner');
$forceKey         = strtolower($user['target_force'] ?? 'british');
$forceMap         = ['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police Force','french'=>'French Foreign Legion'];
$forceName        = $forceMap[$forceKey] ?? ucfirst($forceKey);

function fmtBytes(int $b): string {
    if ($b < 1024) return $b.'B';
    if ($b < 1048576) return round($b/1024,1).'KB';
    return round($b/1048576,1).'MB';
}
function getFileEmoji(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match(true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp']) => '🖼️',
        $ext === 'pdf'  => '📄',
        in_array($ext, ['doc','docx']) => '📝',
        in_array($ext, ['xlsx','xls']) => '📊',
        in_array($ext, ['zip','rar'])  => '🗜️',
        in_array($ext, ['mp3','m4a','ogg','wav']) => '🎵',
        default => '📎',
    };
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
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --accent:#3b82f6;--gold:#fbbf24;--green:#10b981;--red:#ef4444;
    --t1:#f8fafc;--t2:#cbd5e1;--t3:#64748b;
    --sidebar:rgba(15,23,42,.97);--hover:rgba(59,130,246,.08);
    --border:rgba(255,255,255,.07);--bhi:rgba(255,255,255,.13);
    --inp:rgba(15,23,42,.6);
    --bubble-u:linear-gradient(135deg,#2563eb,#1d4ed8);
    --bubble-s:rgba(30,41,59,.9);
}
html,body{height:100%;overflow:hidden}
body{font-family:'Poppins',sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%);color:var(--t1)}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
.root{display:flex;height:100vh;overflow:hidden}

/* ── SIDEBAR ── */
.sidebar{width:240px;flex-shrink:0;background:var(--sidebar);backdrop-filter:blur(20px);border-right:1px solid var(--border);overflow-y:auto;display:flex;flex-direction:column;z-index:100}
.sb-head{padding:1.25rem;border-bottom:1px solid var(--border)}
.logo{display:flex;align-items:center;gap:9px;margin-bottom:1rem}
.logo img{width:30px;height:30px;object-fit:contain}
.brand{font-size:1.15rem;font-weight:800;background:linear-gradient(135deg,var(--gold),#f59e0b);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.user-card{background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18);border-radius:10px;padding:.7rem;display:flex;align-items:center;gap:9px}
.sb-av{width:34px;height:34px;border-radius:50%;flex-shrink:0;border:1.5px solid rgba(59,130,246,.35);display:flex;align-items:center;justify-content:center;overflow:hidden}
.user-card h3{font-size:.8rem;font-weight:600}
.user-card p{font-size:.65rem;color:var(--gold);margin-top:1px}
.nav-menu{padding:1rem 0;flex:1}
.nav-sec{font-size:.6rem;font-weight:600;letter-spacing:.1em;color:rgba(203,213,225,.3);text-transform:uppercase;padding:.35rem 1.1rem;margin-bottom:.15rem}
.nav-item{padding:.55rem 1.1rem;display:flex;align-items:center;gap:10px;color:var(--t2);text-decoration:none;transition:all .18s;border-left:2px solid transparent;font-size:.82rem}
.nav-item svg{width:15px;height:15px;flex-shrink:0}
.nav-item:hover,.nav-item.active{background:var(--hover);color:var(--t1);border-left-color:var(--accent)}
.nav-item.logout{color:#f87171}
.nav-item.logout:hover{background:rgba(239,68,68,.08);border-left-color:var(--red)}
.sub-chip{margin-left:auto;font-size:.6rem;padding:.1rem .4rem;border-radius:99px;background:rgba(251,191,36,.12);color:var(--gold);font-weight:600;border:1px solid rgba(251,191,36,.2);white-space:nowrap}

/* ── STAFF COLUMN ── */
.staff-col{width:270px;flex-shrink:0;background:rgba(10,16,30,.7);backdrop-filter:blur(14px);border-right:1px solid var(--border);display:flex;flex-direction:column}
.sc-head{padding:1rem 1rem .8rem;border-bottom:1px solid var(--border);background:var(--sidebar)}
.sc-title{font-size:.88rem;font-weight:700;display:flex;align-items:center;gap:7px;margin-bottom:.25rem}
.sc-sub{font-size:.7rem;color:var(--t3)}
.sc-search{margin:.75rem 1rem .6rem;position:relative}
.sc-search input{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:20px;color:var(--t1);font-family:'Poppins',sans-serif;font-size:.78rem;padding:.38rem .8rem .38rem 2rem;outline:none;transition:border-color .18s}
.sc-search input:focus{border-color:rgba(59,130,246,.4)}
.sc-search svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);width:13px;height:13px;color:var(--t3)}
.staff-list{flex:1;overflow-y:auto}
.staff-item{display:flex;align-items:center;gap:11px;padding:.8rem 1rem;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.03);transition:background .15s;text-decoration:none;position:relative}
.staff-item:hover{background:rgba(255,255,255,.04)}
.staff-item.active{background:rgba(59,130,246,.1)}
.staff-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);border-radius:0 2px 2px 0}
.s-av{width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.95rem;position:relative}
.s-av.cons{background:linear-gradient(135deg,#0891b2,#06b6d4)}
.s-av.diet{background:linear-gradient(135deg,#d97706,#f59e0b);color:#0a0f1e}
.s-online{position:absolute;bottom:1px;right:1px;width:11px;height:11px;border-radius:50%;background:var(--green);border:2px solid #0f172a}
.s-info{flex:1;min-width:0}
.s-name{font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--t1)}
.s-preview{font-size:.7rem;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.s-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.s-time{font-size:.63rem;color:var(--t3)}
.s-badge{background:var(--green);color:#fff;font-size:.62rem;font-weight:700;border-radius:99px;padding:.1rem .38rem;min-width:18px;text-align:center}
.s-role-chip{font-size:.6rem;padding:.1rem .35rem;border-radius:4px;font-weight:600}
.s-role-chip.cons{background:rgba(6,182,212,.15);color:#22d3ee}
.s-role-chip.diet{background:rgba(245,158,11,.15);color:var(--gold)}
.no-team{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;padding:2rem 1.25rem;text-align:center;gap:.75rem}
.no-team-icon{font-size:2.5rem}
.no-team-title{font-size:.88rem;font-weight:700;color:var(--t2)}
.no-team-desc{font-size:.74rem;color:var(--t3);line-height:1.65}
.no-team-btn{padding:.5rem 1.1rem;border-radius:9px;background:linear-gradient(135deg,var(--accent),#1d4ed8);color:#fff;font-size:.8rem;font-weight:600;text-decoration:none}

/* ── CHAT AREA ── */
.chat-wrap{flex:1;display:flex;flex-direction:column;min-width:0;background:rgba(7,12,22,.5)}

/* HEADER */
.chat-hdr{padding:.85rem 1.25rem;background:var(--sidebar);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.ch-av{width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem}
.ch-av.cons{background:linear-gradient(135deg,#0891b2,#06b6d4)}
.ch-av.diet{background:linear-gradient(135deg,#d97706,#f59e0b);color:#0a0f1e}
.ch-name{font-size:.92rem;font-weight:700}
.ch-meta{font-size:.7rem;color:var(--t3);margin-top:2px;display:flex;align-items:center;gap:5px}
.online-dot{display:inline-flex;align-items:center;gap:3px;color:var(--green);font-size:.7rem}
.ch-actions{margin-left:auto;display:flex;align-items:center;gap:.5rem}
.ch-btn{font-size:.72rem;font-weight:600;padding:.3rem .7rem;border-radius:7px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);color:#93c5fd;text-decoration:none;white-space:nowrap;transition:all .15s;cursor:pointer;font-family:'Poppins',sans-serif}
.ch-btn:hover{background:rgba(59,130,246,.22)}
.ch-btn.danger{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25);color:#fca5a5}
.ch-btn.danger:hover{background:rgba(239,68,68,.2)}
.days-badge{font-size:.68rem;padding:.18rem .5rem;border-radius:6px;background:rgba(251,191,36,.1);color:var(--gold);border:1px solid rgba(251,191,36,.2);white-space:nowrap}

/* MESSAGES */
.msgs{flex:1;overflow-y:auto;padding:1rem 1.25rem;display:flex;flex-direction:column;gap:.5rem}
.date-sep{display:flex;align-items:center;gap:.5rem;margin:.6rem 0}
.date-sep::before,.date-sep::after{content:'';flex:1;height:1px;background:var(--border)}
.date-sep span{font-size:.65rem;color:var(--t3);padding:.15rem .55rem;background:rgba(255,255,255,.04);border-radius:99px;white-space:nowrap}
.msg-row{display:flex;align-items:flex-end;gap:8px;max-width:68%;position:relative}
.msg-row.out{align-self:flex-end;flex-direction:row-reverse}
.msg-row.in{align-self:flex-start}
.del-btn{display:none;position:absolute;top:-9px;background:rgba(239,68,68,.9);border:none;color:#fff;border-radius:50%;width:20px;height:20px;font-size:.6rem;cursor:pointer;align-items:center;justify-content:center;z-index:10;line-height:1}
.msg-row.out .del-btn{right:-9px}
.msg-row:hover .del-btn{display:flex}
.del-btn:hover{background:#ef4444!important}
.m-av{width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700}
.m-av.cons{background:linear-gradient(135deg,#0891b2,#06b6d4)}
.m-av.diet{background:linear-gradient(135deg,#d97706,#f59e0b);color:#0a0f1e}
.m-body{display:flex;flex-direction:column}
.bubble{padding:.55rem .9rem;border-radius:16px;font-size:.858rem;line-height:1.58;word-break:break-word;max-width:100%}
.out .bubble{background:var(--bubble-u);color:#fff;border-bottom-right-radius:4px}
.in  .bubble{background:var(--bubble-s);border:1px solid var(--border);color:var(--t1);border-bottom-left-radius:4px}
.m-time{font-size:.6rem;color:var(--t3);margin-top:3px;padding:0 .2rem}
.out .m-time{text-align:right}
.m-tick{color:var(--accent);font-size:.65rem}
.img-bubble{padding:.3rem}
.img-bubble img{max-width:220px;max-height:200px;border-radius:10px;display:block;cursor:pointer;object-fit:cover}
.file-bubble{display:flex;align-items:center;gap:10px;padding:.55rem .85rem;min-width:160px}
.file-icon{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.file-info{min-width:0}
.file-name{font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
.file-size{font-size:.65rem;color:var(--t3);margin-top:2px}
.out .file-size{color:rgba(255,255,255,.55)}
.voice-bubble{display:flex;align-items:center;gap:8px;min-width:180px;padding:.45rem .7rem}
.v-play{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.15);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:inherit;font-size:.85rem;flex-shrink:0;transition:background .15s}
.v-play:hover{background:rgba(255,255,255,.25)}
.v-wave{flex:1;height:24px;display:flex;align-items:center;gap:2px}
.v-bar{width:2px;border-radius:1px;background:rgba(255,255,255,.3);flex-shrink:0}
.out .v-bar{background:rgba(255,255,255,.5)}
.v-dur{font-size:.68rem;white-space:nowrap;opacity:.75;flex-shrink:0}
.typing{display:none;align-self:flex-start}
.typing.show{display:flex}
.typing .bubble{padding:.5rem .75rem}
.t-dots{display:flex;gap:3px;align-items:center}
.t-dots span{width:6px;height:6px;border-radius:50%;background:var(--t3);animation:tdot .9s infinite}
.t-dots span:nth-child(2){animation-delay:.18s}.t-dots span:nth-child(3){animation-delay:.36s}
@keyframes tdot{0%,80%,100%{transform:scale(.8);opacity:.5}40%{transform:scale(1.1);opacity:1}}

/* ── INPUT ── */
.input-wrap{padding:.75rem 1.1rem;background:var(--sidebar);border-top:1px solid var(--border);flex-shrink:0}
.file-preview-bar{display:none;flex-wrap:wrap;gap:.4rem;padding:.5rem .65rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;margin-bottom:.5rem;max-height:90px;overflow-y:auto}
.file-preview-bar.show{display:flex}
.fp-item{display:flex;align-items:center;gap:.4rem;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:7px;padding:.25rem .55rem;font-size:.73rem;color:#93c5fd}
.fp-item span.x{cursor:pointer;font-size:.85rem;line-height:1;opacity:.7}
.fp-item span.x:hover{opacity:1}
.fp-thumb{width:28px;height:28px;border-radius:5px;object-fit:cover}
.rec-bar{display:none;align-items:center;gap:.6rem;padding:.4rem .7rem;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;margin-bottom:.5rem;font-size:.8rem;color:#fca5a5}
.rec-bar.show{display:flex}
.rec-dot{width:7px;height:7px;border-radius:50%;background:var(--red);animation:blink 1s infinite;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.rec-timer{font-family:monospace;font-weight:700;font-size:.82rem}
.rec-cancel{margin-left:auto;cursor:pointer;opacity:.7;font-size:.78rem}
.rec-cancel:hover{opacity:1}
.rec-send{padding:.2rem .6rem;background:var(--red);color:#fff;border:none;border-radius:5px;font-family:'Poppins',sans-serif;font-size:.75rem;font-weight:600;cursor:pointer}
.input-row{display:flex;align-items:flex-end;gap:.35rem;background:var(--inp);border:1px solid var(--border);border-radius:22px;padding:.3rem .4rem .3rem .9rem;transition:border-color .18s}
.input-row:focus-within{border-color:rgba(59,130,246,.38)}
.msg-ta{flex:1;background:transparent;border:none;outline:none;color:var(--t1);font-size:.88rem;line-height:1.45;resize:none;max-height:110px;padding:.3rem 0;font-family:'Poppins',sans-serif}
.msg-ta::placeholder{color:var(--t3)}
.ia{display:flex;gap:.2rem;align-items:flex-end;padding-bottom:.1rem}
.ia-btn{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;background:transparent;color:var(--t3);display:flex;align-items:center;justify-content:center;transition:all .18s;font-size:1.05rem;flex-shrink:0}
.ia-btn:hover{background:rgba(255,255,255,.06);color:var(--t1)}
.ia-btn.send{background:var(--accent);color:#fff}
.ia-btn.send:hover{background:#1d4ed8}
.ia-btn.record.active{color:var(--red)}

/* ── LIGHTBOX ── */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center}
.lightbox.show{display:flex}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;object-fit:contain}
.lb-close{position:fixed;top:1.25rem;right:1.5rem;background:rgba(255,255,255,.12);border:none;color:#fff;width:38px;height:38px;border-radius:50%;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:10000}

/* ── CONFIRM MODAL ── */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:8000;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.confirm-overlay.show{display:flex;animation:fadeIn .18s ease}
.confirm-box{background:#1e293b;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:1.75rem;max-width:360px;width:90%;text-align:center;animation:slideUp .2s ease}
.confirm-box h3{font-size:.95rem;font-weight:700;margin-bottom:.5rem}
.confirm-box p{font-size:.8rem;color:var(--t3);margin-bottom:1.25rem;line-height:1.6}
.confirm-btns{display:flex;gap:.65rem;justify-content:center}
.cb{padding:.52rem 1.1rem;border-radius:9px;border:none;font-family:'Poppins',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .18s}
.cb-cancel{background:rgba(255,255,255,.07);color:var(--t2);border:1px solid var(--border)}
.cb-cancel:hover{background:rgba(255,255,255,.12)}
.cb-confirm{background:linear-gradient(135deg,var(--red),#b91c1c);color:#fff}
.cb-confirm:hover{opacity:.9}

/* ── EMPTY ── */
.empty-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;color:var(--t3);padding:2rem;text-align:center}
.empty-chat .ei{font-size:3rem;margin-bottom:.25rem}
.empty-chat h3{font-size:1rem;font-weight:700;color:var(--t2)}
.empty-chat p{font-size:.8rem;line-height:1.65;max-width:270px}
.empty-chat a{padding:.5rem 1.1rem;border-radius:9px;background:linear-gradient(135deg,var(--accent),#1d4ed8);color:#fff;font-size:.8rem;font-weight:600;text-decoration:none;margin-top:.25rem}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
@media(max-width:1024px){.sidebar{display:none}}
@media(max-width:768px){.staff-col{width:220px}}
@media(max-width:540px){.staff-col{display:none}}
</style>
</head>
<body>
<div class="root">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sb-head">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="" onerror="this.style.display='none'">
            <span class="brand">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="sb-av" id="sbAvContainer"></div>
            <div>
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= ucfirst($expKey) ?> · <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-sec">Main</div>
        <a href="dashboard.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
        <a href="workouts.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>Workouts</a>
        <a href="progress.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>Progress</a>
        <a href="nutrition.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>Nutrition</a>
        <a href="questions.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Questions</a>
        <a href="chat.php" class="nav-item active"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>Chat</a>
        <div class="nav-sec" style="margin-top:.65rem">Account</div>
        <a href="choose_staff.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>My Expert Team</a>
        <a href="profile.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Profile</a>
        <a href="subscription_fixed.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>Subscription<span class="sub-chip"><?= $daysLeft ?>d</span></a>
        <a href="../auth/logout.php" class="nav-item logout"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
    </nav>
</aside>

<!-- STAFF COLUMN -->
<div class="staff-col">
    <div class="sc-head">
        <div class="sc-title">💬 Your Experts</div>
        <div class="sc-sub"><?= count($teamStaff) ?> in team · Premium active</div>
    </div>
    <div class="sc-search">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" placeholder="Search experts…" oninput="filterStaff(this.value)">
    </div>
    <div class="staff-list" id="staffList">
        <?php if (empty($teamStaff)): ?>
        <div class="no-team">
            <div class="no-team-icon">👥</div>
            <div class="no-team-title">No experts yet</div>
            <div class="no-team-desc">Choose a consultant and dietitian to start chatting.</div>
            <a href="choose_staff.php" class="no-team-btn">Browse Experts</a>
        </div>
        <?php else:
            foreach ($teamStaff as $s):
                $unread    = $unreadCounts[$s['id']] ?? 0;
                $isActive  = (int)$s['id'] === $activeStaffId;
                $roleClass = $s['role'] === 'dietitian' ? 'diet' : 'cons';
                // Last message scoped to this user
                $lastMsg = fetchOne(
                    "SELECT body, message_type, created_at, sender_type FROM chat_messages
                     WHERE consultant_id = ? AND recipient_user_id = ? AND deleted_by_user = 0
                     ORDER BY created_at DESC LIMIT 1",
                    [(int)$s['id'], $userId]
                );
                $preview = '';
                if ($lastMsg) {
                    $isMe = $lastMsg['sender_type'] === 'user';
                    $txt  = match($lastMsg['message_type']) {
                        'image' => '📷 Photo', 'file' => '📎 File', 'voice' => '🎤 Voice',
                        default => mb_substr($lastMsg['body'] ?? '', 0, 35) . (mb_strlen($lastMsg['body'] ?? '') > 35 ? '…' : ''),
                    };
                    $preview = ($isMe ? 'You: ' : '') . $txt;
                }
        ?>
        <a href="chat.php?staff_id=<?= $s['id'] ?>"
           class="staff-item <?= $isActive ? 'active' : '' ?>"
           data-name="<?= htmlspecialchars(strtolower($s['full_name'])) ?>">
            <div class="s-av <?= $roleClass ?>">
                <?= strtoupper(substr($s['full_name'],0,1)) ?>
                <div class="s-online"></div>
            </div>
            <div class="s-info">
                <div class="s-name"><?= htmlspecialchars($s['full_name']) ?></div>
                <div class="s-preview"><?= $preview ?: '<span class="s-role-chip '.$roleClass.'">'.ucfirst($s['role']).'</span>' ?></div>
            </div>
            <div class="s-meta">
                <?php if ($lastMsg): ?><div class="s-time"><?= date('g:i A', strtotime($lastMsg['created_at'])) ?></div><?php endif; ?>
                <?php if ($unread > 0): ?><div class="s-badge"><?= $unread ?></div><?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        <div style="padding:.8rem 1rem;border-top:1px solid var(--border)">
            <a href="choose_staff.php" style="font-size:.74rem;color:var(--t3);text-decoration:none;display:flex;align-items:center;gap:6px">
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Manage / Change Experts
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CHAT AREA -->
<div class="chat-wrap">
<?php if ($activeStaff): ?>
<?php $roleClass = $activeStaff['role'] === 'dietitian' ? 'diet' : 'cons'; ?>

    <div class="chat-hdr">
        <div class="ch-av <?= $roleClass ?>"><?= strtoupper(substr($activeStaff['full_name'],0,1)) ?></div>
        <div style="flex:1;min-width:0">
            <div class="ch-name"><?= htmlspecialchars($activeStaff['full_name']) ?></div>
            <div class="ch-meta">
                <span class="online-dot"><svg width="6" height="6" viewBox="0 0 6 6"><circle cx="3" cy="3" r="3" fill="currentColor"/></svg>Online</span>
                &nbsp;·&nbsp;<?= ucfirst($activeStaff['role']) ?>
                <?php if (!empty($activeStaff['speciality'])): ?>&nbsp;·&nbsp;<?= htmlspecialchars($activeStaff['speciality']) ?><?php endif; ?>
            </div>
        </div>
        <div class="ch-actions">
            <a href="choose_staff.php" class="ch-btn">👥 Team</a>
            <button class="ch-btn danger" onclick="confirmClear()">🗑 Clear</button>
            <span class="days-badge">⭐ <?= $daysLeft ?>d</span>
        </div>
    </div>

    <div class="msgs" id="msgs">
        <?php if (empty($messages)): ?>
        <div id="emptyHint" style="margin:auto;text-align:center;color:var(--t3);font-size:.83rem;line-height:1.7">
            <div style="font-size:2rem;margin-bottom:.5rem">👋</div>
            Say hello to <strong style="color:var(--t2)"><?= htmlspecialchars($activeStaff['full_name']) ?></strong><br>
            Your messages are private and secure.
        </div>
        <?php else:
            $lastDate = '';
            foreach ($messages as $msg):
                $isOut   = ($msg['sender_type'] === 'user');
                $msgDate = date('d M Y', strtotime($msg['created_at']));
                $timeStr = date('g:i A', strtotime($msg['created_at']));
        ?>
            <?php if ($msgDate !== $lastDate): $lastDate = $msgDate; ?>
            <div class="date-sep"><span><?= $msgDate === date('d M Y') ? 'Today' : ($msgDate === date('d M Y', strtotime('-1 day')) ? 'Yesterday' : $msgDate) ?></span></div>
            <?php endif; ?>
            <div class="msg-row <?= $isOut ? 'out' : 'in' ?>" data-id="<?= (int)$msg['id'] ?>">
                <?php if (!$isOut): ?><div class="m-av <?= $roleClass ?>"><?= strtoupper(substr($activeStaff['full_name'],0,1)) ?></div><?php endif; ?>
                <div class="m-body">
                    <?php if ($msg['message_type'] === 'image' && $msg['file_path']): ?>
                    <div class="bubble img-bubble"><img src="<?= htmlspecialchars($msg['file_path']) ?>" alt="Image" onclick="openLightbox(this.src)" loading="lazy"></div>
                    <?php elseif ($msg['message_type'] === 'file' && $msg['file_path']): ?>
                    <a href="<?= htmlspecialchars($msg['file_path']) ?>" target="_blank" rel="noopener" class="bubble file-bubble" style="text-decoration:none;color:inherit">
                        <div class="file-icon"><?= getFileEmoji($msg['file_name'] ?? '') ?></div>
                        <div class="file-info"><div class="file-name"><?= htmlspecialchars($msg['file_name'] ?? 'File') ?></div><div class="file-size"><?= fmtBytes((int)($msg['file_size'] ?? 0)) ?> · Tap to open</div></div>
                    </a>
                    <?php elseif ($msg['message_type'] === 'voice' && $msg['file_path']): ?>
                    <div class="bubble voice-bubble">
                        <button class="v-play" onclick="playVoice('<?= htmlspecialchars($msg['file_path']) ?>',this)">▶</button>
                        <div class="v-wave"><?php for($i=0;$i<22;$i++): $h=rand(5,22); ?><div class="v-bar" style="height:<?=$h?>px"></div><?php endfor; ?></div>
                        <span class="v-dur"><?= $msg['duration_secs'] ? gmdate('i:s',(int)$msg['duration_secs']) : '0:00' ?></span>
                    </div>
                    <?php else: ?>
                    <div class="bubble"><?= nl2br(htmlspecialchars($msg['body'] ?? '')) ?></div>
                    <?php endif; ?>
                    <div class="m-time"><?= $timeStr ?><?= $isOut ? ' <span class="m-tick">✓✓</span>' : '' ?></div>
                </div>
                <?php if ($isOut): ?><button class="del-btn" onclick="deleteMsg(<?= (int)$msg['id'] ?>,this)" title="Delete">✕</button><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>

        <div class="msg-row in typing" id="typingRow">
            <div class="m-av <?= $roleClass ?>"><?= strtoupper(substr($activeStaff['full_name'],0,1)) ?></div>
            <div class="m-body"><div class="bubble"><div class="t-dots"><span></span><span></span><span></span></div></div></div>
        </div>
    </div>

    <div class="input-wrap">
        <div class="file-preview-bar" id="filePreviewBar"></div>
        <div class="rec-bar" id="recBar">
            <div class="rec-dot"></div>
            <span>Recording voice…</span>
            <span class="rec-timer" id="recTimer">0:00</span>
            <span class="rec-cancel" onclick="cancelRecording()">✕ Cancel</span>
            <button class="rec-send" onclick="stopAndSendRecording()">Send</button>
        </div>
        <div class="input-row">
            <textarea class="msg-ta" id="msgInput" rows="1"
                placeholder="Message <?= htmlspecialchars($activeStaff['full_name']) ?>…"
                onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
            <div class="ia">
                <button class="ia-btn" title="Attach" onclick="document.getElementById('fileIn').click()">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </button>
                <input type="file" id="fileIn" style="display:none" multiple accept="image/*,.pdf,.doc,.docx,.xlsx,.xls,.txt,.zip,.mp3,.m4a,.ogg,.wav" onchange="queueFiles(this)">
                <button class="ia-btn" title="Camera" onclick="document.getElementById('camIn').click()">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </button>
                <input type="file" id="camIn" style="display:none" accept="image/*" capture="environment" onchange="queueFiles(this)">
                <button class="ia-btn" id="voiceBtn" title="Voice" onclick="toggleRecording()">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                </button>
                <button class="ia-btn send" onclick="handleSend()" title="Send">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="empty-chat">
        <div class="ei">💬</div>
        <h3><?= empty($teamStaff) ? 'No expert team yet' : 'Select a conversation' ?></h3>
        <p><?= empty($teamStaff) ? 'Pick a consultant and dietitian to start chatting.' : 'Choose an expert from the left panel.' ?></p>
        <?php if (empty($teamStaff)): ?><a href="choose_staff.php">Browse Experts →</a><?php endif; ?>
    </div>
<?php endif; ?>
</div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lb-close" onclick="closeLightbox()">✕</button>
    <img id="lbImg" src="" alt="">
</div>

<!-- Confirm Clear -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <h3>Clear Conversation?</h3>
        <p>This hides all messages in this chat from your view. Your expert's copy remains intact.</p>
        <div class="confirm-btns">
            <button class="cb cb-cancel" onclick="closeConfirm()">Cancel</button>
            <button class="cb cb-confirm" onclick="doClearChat()">Clear Chat</button>
        </div>
    </div>
</div>

<script>
const ACTIVE_STAFF_ID     = <?= (int)$activeStaffId ?>;
const USER_INIT           = <?= json_encode($initials) ?>;
const STAFF_INIT          = <?= json_encode($activeStaff ? strtoupper(substr($activeStaff['full_name'],0,1)) : 'S') ?>;
const STAFF_ROLE          = <?= json_encode($activeStaff['role'] ?? 'consultant') ?>;
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;

// ── Avatar ────────────────────────────────────────────────────────────────
const AV_OPTIONS = {
    bg:[{id:'grad1',grad:['#1e3a5f','#2563eb']},{id:'grad2',grad:['#14532d','#16a34a']},{id:'grad3',grad:['#7f1d1d','#dc2626']},{id:'grad4',grad:['#312e81','#7c3aed']},{id:'grad5',grad:['#78350f','#d97706']},{id:'grad6',grad:['#134e4a','#0d9488']},{id:'grad7',grad:['#0f172a','#1e293b']},{id:'grad8',grad:['#4c0519','#e11d48']}],
    skin:[{id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},{id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},{id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'}],
    hairColor:[{id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},{id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},{id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},{id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'}],
    eyes:[{id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},{id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},{id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'}],
};
function drawAv(cv,s,sz){
    const ctx=cv.getContext('2d');cv.width=sz;cv.height=sz;const cx=sz/2,cy=sz/2;
    const bg=AV_OPTIONS.bg.find(o=>o.id===s.bg)||AV_OPTIONS.bg[0];
    const skin=AV_OPTIONS.skin.find(o=>o.id===s.skin)||AV_OPTIONS.skin[1];
    const hCol=AV_OPTIONS.hairColor.find(o=>o.id===s.hairColor)||AV_OPTIONS.hairColor[1];
    const eyeC=AV_OPTIONS.eyes.find(o=>o.id===s.eyes)||AV_OPTIONS.eyes[0];
    const g=ctx.createLinearGradient(0,0,sz,sz);g.addColorStop(0,bg.grad[0]);g.addColorStop(1,bg.grad[1]);
    ctx.fillStyle=g;ctx.beginPath();ctx.arc(cx,cy,cx,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=skin.color;ctx.beginPath();ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#fff';ctx.beginPath();ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=eyeC.color;ctx.beginPath();ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#000';ctx.beginPath();ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=hCol.color;ctx.beginPath();ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0);ctx.fill();ctx.fillRect(cx-44,cy-40,88,20);
}
function renderSbAv(){
    const c=document.getElementById('sbAvContainer');if(!c)return;c.innerHTML='';
    if(SAVED_AVATAR_TYPE==='photo'&&SAVED_PHOTO_URL){
        const img=document.createElement('img');img.src=SAVED_PHOTO_URL;img.style.cssText='width:34px;height:34px;object-fit:cover;border-radius:50%;display:block';img.onerror=()=>initAv(c);c.appendChild(img);
    }else if(SAVED_AVATAR_TYPE==='ai'&&SAVED_AVATAR_CONFIG){
        const cv=document.createElement('canvas');cv.style.cssText='width:34px;height:34px;border-radius:50%;display:block';c.appendChild(cv);drawAv(cv,SAVED_AVATAR_CONFIG,34);
    }else initAv(c);
}
function initAv(c){const d=document.createElement('div');d.style.cssText='width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';d.textContent=USER_INIT;c.appendChild(d)}

// ── Helpers ───────────────────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function fmtTime(iso){return new Date(iso).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function fmtBytes(b){if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB'}
function fileEmoji(n){const e=(n||'').split('.').pop().toLowerCase();return['jpg','jpeg','png','gif','webp'].includes(e)?'🖼️':e==='pdf'?'📄':['doc','docx'].includes(e)?'📝':['xlsx','xls'].includes(e)?'📊':['zip','rar'].includes(e)?'🗜️':['mp3','m4a','ogg','wav'].includes(e)?'🎵':'📎'}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,110)+'px'}
function scrollBottom(){const m=document.getElementById('msgs');if(m)m.scrollTop=m.scrollHeight}
function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();handleSend()}}
function filterStaff(q){document.querySelectorAll('.staff-item').forEach(el=>{el.style.display=el.dataset.name.includes(q.toLowerCase())?'':'none'})}

// ── Lightbox ──────────────────────────────────────────────────────────────
function openLightbox(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('show')}
function closeLightbox(){document.getElementById('lightbox').classList.remove('show')}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLightbox()})

// ── Confirm clear ─────────────────────────────────────────────────────────
function confirmClear(){document.getElementById('confirmOverlay').classList.add('show')}
function closeConfirm(){document.getElementById('confirmOverlay').classList.remove('show')}
async function doClearChat(){
    closeConfirm();
    const fd=new FormData();fd.append('ajax_action','clear_chat');fd.append('consultant_id',ACTIVE_STAFF_ID);
    try{
        const r=await fetch('chat.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok){
            const msgs=document.getElementById('msgs');
            const roleC=STAFF_ROLE==='dietitian'?'diet':'cons';
            msgs.innerHTML=`<div id="emptyHint" style="margin:auto;text-align:center;color:var(--t3);font-size:.83rem;line-height:1.7"><div style="font-size:2rem;margin-bottom:.5rem">💬</div>Chat cleared. Say hello again!</div><div class="msg-row in typing" id="typingRow"><div class="m-av ${roleC}">${STAFF_INIT}</div><div class="m-body"><div class="bubble"><div class="t-dots"><span></span><span></span><span></span></div></div></div></div>`;
            lastMsgId=0;
        }
    }catch(e){console.error('Clear chat error:',e)}
}

// ── Delete single message ─────────────────────────────────────────────────
async function deleteMsg(msgId,btn){
    const row=btn.closest('.msg-row');
    const fd=new FormData();fd.append('ajax_action','delete_message');fd.append('message_id',msgId);fd.append('consultant_id',ACTIVE_STAFF_ID);
    try{
        const r=await fetch('chat.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok){row.style.transition='opacity .25s,transform .25s';row.style.opacity='0';row.style.transform='scale(.95)';setTimeout(()=>row.remove(),260)}
    }catch(e){}
}

// ── File queue ────────────────────────────────────────────────────────────
let fileQueue=[];
function queueFiles(input){for(const f of input.files)fileQueue.push(f);renderFilePreview();input.value=''}
function renderFilePreview(){
    const bar=document.getElementById('filePreviewBar');
    if(!fileQueue.length){bar.classList.remove('show');bar.innerHTML='';return}
    bar.classList.add('show');
    bar.innerHTML=fileQueue.map((f,i)=>{
        const isImg=f.type.startsWith('image/');const prev=isImg?`<img class="fp-thumb" src="${URL.createObjectURL(f)}" alt="">`:fileEmoji(f.name);
        return`<div class="fp-item">${prev}<span>${esc(f.name.length>18?f.name.slice(0,15)+'…':f.name)}</span><span class="x" onclick="removeQueued(${i})">✕</span></div>`;
    }).join('');
}
function removeQueued(i){fileQueue.splice(i,1);renderFilePreview()}

// ── Append bubble ─────────────────────────────────────────────────────────
function appendBubble(msg,isOptimistic=false){
    const isOut=msg.sender_type==='user';
    const roleC=STAFF_ROLE==='dietitian'?'diet':'cons';
    const timeStr=fmtTime(msg.created_at||new Date().toISOString());
    let inner='';
    if(msg.message_type==='image'&&(msg._blobUrl||msg.file_path)){
        inner=`<div class="bubble img-bubble"><img src="${msg._blobUrl||esc(msg.file_path)}" alt="Image" onclick="openLightbox(this.src)" loading="lazy"></div>`;
    }else if(msg.message_type==='file'){
        inner=`<a href="${msg.file_path?esc(msg.file_path):'#'}" target="_blank" rel="noopener" class="bubble file-bubble" style="text-decoration:none;color:inherit"><div class="file-icon">${fileEmoji(msg.file_name||msg._filename||'')}</div><div class="file-info"><div class="file-name">${esc(msg.file_name||msg._filename||'File')}</div><div class="file-size">${fmtBytes(msg.file_size||msg._filesize||0)} · Tap to open</div></div></a>`;
    }else if(msg.message_type==='voice'){
        const dur=msg.duration_secs?Math.floor(msg.duration_secs/60)+':'+String(msg.duration_secs%60).padStart(2,'0'):'0:00';
        const bars=Array.from({length:22},()=>`<div class="v-bar" style="height:${5+Math.random()*17}px"></div>`).join('');
        inner=`<div class="bubble voice-bubble"><button class="v-play" onclick="playVoice('${msg.file_path?esc(msg.file_path):''}',this)">▶</button><div class="v-wave">${bars}</div><span class="v-dur">${dur}</span></div>`;
    }else{
        inner=`<div class="bubble">${esc(msg.body||'').replace(/\n/g,'<br>')}</div>`;
    }
    document.getElementById('emptyHint')?.remove();

    const row=document.createElement('div');
    row.className='msg-row '+(isOut?'out':'in');
    if(msg.id)row.dataset.id=msg.id;
    if(isOptimistic)row.style.opacity='.6';
    const delPart=isOut?`<button class="del-btn" onclick="deleteMsg(${msg.id||0},this)" title="Delete">✕</button>`:'';
    row.innerHTML=`${!isOut?`<div class="m-av ${roleC}">${STAFF_INIT}</div>`:''}<div class="m-body">${inner}<div class="m-time">${timeStr}${isOut?' <span class="m-tick">✓✓</span>':''}</div></div>${delPart}`;
    const tr=document.getElementById('typingRow');
    const msgs=document.getElementById('msgs');
    if(tr)msgs.insertBefore(row,tr);else msgs.appendChild(row);
    scrollBottom();
    return row;
}

// ── Send ──────────────────────────────────────────────────────────────────
let isSending=false;
async function handleSend(){
    if(isSending)return;

    // Send queued files first
    for(const f of [...fileQueue]){
        const isImg=f.type.startsWith('image/');
        const blobUrl=isImg?URL.createObjectURL(f):null;
        const optMsg={sender_type:'user',message_type:isImg?'image':'file',_blobUrl:blobUrl,_filename:f.name,_filesize:f.size,file_name:f.name,file_size:f.size,created_at:new Date().toISOString()};
        const row=appendBubble(optMsg,true);
        const fd=new FormData();
        fd.append('ajax_action','send_file');
        fd.append('consultant_id',ACTIVE_STAFF_ID);
        fd.append('file',f);
        try{
            const r=await fetch('chat.php',{method:'POST',body:fd});
            const d=await r.json();
            console.log(d);
            if(d.ok&&d.message?.id){
                row.style.opacity='1';
                row.dataset.id=d.message.id;
                const db=row.querySelector('.del-btn');
                if(db)db.onclick=()=>deleteMsg(d.message.id,db);
                lastMsgId=Math.max(lastMsgId,+d.message.id);
            }else{row.style.opacity='.4';console.error('File send failed:',d.error);}
        }catch(e){row.style.opacity='.4';console.error(e);}
    }
    fileQueue=[];renderFilePreview();

    const input=document.getElementById('msgInput');
    const text=input.value.trim();
    if(!text)return;

    isSending=true;
    input.value='';input.style.height='auto';

    const row=appendBubble({sender_type:'user',message_type:'text',body:text,created_at:new Date().toISOString()},true);

    const fd=new FormData();
    fd.append('ajax_action','send_text');
    fd.append('consultant_id',ACTIVE_STAFF_ID);
    fd.append('body',text);

    try{
        const r=await fetch('chat.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok&&d.message?.id){
            row.style.opacity='1';
            row.dataset.id=d.message.id;
            const db=row.querySelector('.del-btn');
            if(db)db.onclick=()=>deleteMsg(d.message.id,db);
            lastMsgId=Math.max(lastMsgId,+d.message.id);
        }else{
            row.style.opacity='.4';
            console.error('Send failed:',d.error||'unknown');
        }
    }catch(e){row.style.opacity='.4';console.error(e);}
    finally{isSending=false;}
}

// ── Voice recording ───────────────────────────────────────────────────────
let mediaRec,audioChunks=[],recInterval,recSecs=0,recStream,isRecording=false;
async function toggleRecording(){if(isRecording)stopAndSendRecording();else startRecording()}
async function startRecording(){
    try{
        recStream=await navigator.mediaDevices.getUserMedia({audio:true});
        audioChunks=[];recSecs=0;isRecording=true;
        mediaRec=new MediaRecorder(recStream,{mimeType:'audio/webm'});
        mediaRec.ondataavailable=e=>audioChunks.push(e.data);
        mediaRec.start();
        document.getElementById('recBar').classList.add('show');
        document.getElementById('voiceBtn').classList.add('active');
        recInterval=setInterval(()=>{recSecs++;const m=Math.floor(recSecs/60),s=recSecs%60;document.getElementById('recTimer').textContent=m+':'+String(s).padStart(2,'0')},1000);
    }catch(e){alert('Microphone access denied.')}
}
async function stopAndSendRecording(){
    if(!mediaRec||!isRecording)return;
    clearInterval(recInterval);isRecording=false;
    document.getElementById('recBar').classList.remove('show');
    document.getElementById('voiceBtn').classList.remove('active');
    recStream.getTracks().forEach(t=>t.stop());
    mediaRec.stop();
    mediaRec.onstop=async()=>{
        const blob=new Blob(audioChunks,{type:'audio/webm'});
        const dur=recSecs;
        const row=appendBubble({sender_type:'user',message_type:'voice',duration_secs:dur,created_at:new Date().toISOString()},true);
        const fd=new FormData();
        fd.append('ajax_action','send_voice');
        fd.append('consultant_id',ACTIVE_STAFF_ID);
        fd.append('audio',blob,'voice.webm');
        fd.append('duration',dur);
        try{
            const r=await fetch('chat.php',{method:'POST',body:fd});
            const d=await r.json();
            if(d.ok){row.style.opacity='1';lastMsgId=Math.max(lastMsgId,+(d.message?.id||0));}
            else{row.style.opacity='.4';console.error('Voice send failed:',d.error);}
        }catch(e){row.style.opacity='.4';}
    };
}
function cancelRecording(){
    if(!isRecording)return;
    clearInterval(recInterval);isRecording=false;
    if(mediaRec&&mediaRec.state!=='inactive')mediaRec.stop();
    if(recStream)recStream.getTracks().forEach(t=>t.stop());
    audioChunks=[];
    document.getElementById('recBar').classList.remove('show');
    document.getElementById('voiceBtn').classList.remove('active');
}

// ── Voice playback ────────────────────────────────────────────────────────
let activeAudio=null;
function playVoice(src,btn){
    if(!src)return;
    if(activeAudio){activeAudio.pause();activeAudio=null;document.querySelectorAll('.v-play').forEach(b=>b.textContent='▶')}
    activeAudio=new Audio(src);btn.textContent='⏸';
    activeAudio.play().catch(()=>btn.textContent='▶');
    activeAudio.onended=()=>{btn.textContent='▶';activeAudio=null};
}

// ── Poll for new staff messages ───────────────────────────────────────────
let lastMsgId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;

async function pollMessages(){
    if(!ACTIVE_STAFF_ID)return;
    try{
        const fd=new FormData();
        fd.append('ajax_action','poll');
        fd.append('consultant_id',ACTIVE_STAFF_ID);
        fd.append('after_id',lastMsgId);
        const r=await fetch('chat.php',{method:'POST',body:fd});
        if(!r.ok)return;
        const data=await r.json();
        if(data.messages?.length){
            data.messages.forEach(m=>{
                if(!document.querySelector(`.msg-row[data-id="${m.id}"]`)){
                    appendBubble(m);
                    lastMsgId=Math.max(lastMsgId,parseInt(m.id)||0);
                }
            });
        }
    }catch(e){}
}
setInterval(pollMessages,4000);
document.addEventListener('DOMContentLoaded',()=>{renderSbAv();scrollBottom()});
</script>
</body>
</html>