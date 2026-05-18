<?php
// frontend/staff/premium_message.php  —  Staff-side chat
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['staff_logged_in'])) { header('Location: ../auth/login.php'); exit(); }

$staffId   = (int)$_SESSION['staff_id'];
$staffName = $_SESSION['staff_name']  ?? '';
$staffRole = $_SESSION['staff_role']  ?? 'consultant';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
foreach ([
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS deleted_by_staff TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS recipient_user_id INT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_premium TINYINT(1) NOT NULL DEFAULT 0",
] as $ddl) { try { query($ddl, []); } catch(Exception $e) {} }

// ── Back-fill recipient_user_id for old user messages that are missing it ─────
// For user-sent messages: recipient_user_id should equal sender_id
// We do this once silently; old data had recipient_user_id=0
try {
    execute(
        "UPDATE chat_messages SET recipient_user_id = sender_id
         WHERE sender_type = 'user' AND recipient_user_id = 0",
        []
    );
} catch(Exception $e) {}

// ── Load users assigned to this staff member ──────────────────────────────────
$roleColumn = ($staffRole === 'dietitian') ? 'uc.dietitian_id' : 'uc.consultant_id';

$assignedUsers = fetchAll(
    "SELECT u.* FROM users u
     INNER JOIN user_consultants uc ON uc.user_id = u.id
     WHERE $roleColumn = ? AND uc.is_active = 1
     ORDER BY u.full_name ASC",
    [$staffId]
) ?: [];

// ── Active user from URL ───────────────────────────────────────────────────────
$activeUid  = (int)($_GET['uid'] ?? ($assignedUsers[0]['id'] ?? 0));
$activeUser = null;
foreach ($assignedUsers as $u) {
    if ((int)$u['id'] === $activeUid) { $activeUser = $u; break; }
}
if (!$activeUser && !empty($assignedUsers)) {
    $activeUser = $assignedUsers[0];
    $activeUid  = (int)$activeUser['id'];
}

// ── Load messages ─────────────────────────────────────────────────────────────
// KEY: always scope by recipient_user_id = $activeUid
// This ensures messages are tied to the specific user, not just the consultant
$messages = [];
if ($activeUser) {
    $messages = fetchAll(
        "SELECT * FROM chat_messages
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND deleted_by_staff = 0
         ORDER BY created_at ASC",
        [$staffId, $activeUid]
    ) ?: [];

    // Mark user messages as read
    execute(
        "UPDATE chat_messages SET is_read = 1
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND sender_type = 'user'
           AND is_read = 0
           AND deleted_by_staff = 0",
        [$staffId, $activeUid]
    );
}

// ── Unread counts per user ────────────────────────────────────────────────────
$unreadCounts = [];
foreach ($assignedUsers as $u) {
    $r = fetchOne(
        "SELECT COUNT(*) AS cnt FROM chat_messages
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND sender_type = 'user'
           AND is_read = 0
           AND deleted_by_staff = 0",
        [$staffId, $u['id']]
    );
    $unreadCounts[$u['id']] = (int)($r['cnt'] ?? 0);
}

// ── Last message per user ─────────────────────────────────────────────────────
$lastMsgs = [];
foreach ($assignedUsers as $u) {
    $lm = fetchOne(
        "SELECT body, message_type, created_at, sender_type FROM chat_messages
         WHERE consultant_id = ?
           AND recipient_user_id = ?
           AND deleted_by_staff = 0
         ORDER BY created_at DESC LIMIT 1",
        [$staffId, $u['id']]
    );
    $lastMsgs[$u['id']] = $lm;
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $uid    = (int)($_POST['user_id'] ?? 0);

    // Verify this user is in our assigned list
    $validUser = null;
    foreach ($assignedUsers as $u) {
        if ((int)$u['id'] === $uid) { $validUser = $u; break; }
    }
    if (!$validUser) { echo json_encode(['error' => 'Not assigned']); exit(); }

    // ── Poll for new user messages ─────────────────────────────────────────────
    if ($action === 'poll') {
        $afterId = (int)($_POST['after_id'] ?? 0);

        // KEY FIX: scope by recipient_user_id to only get messages from this specific user
        $newMsgs = fetchAll(
            "SELECT * FROM chat_messages
             WHERE consultant_id = ?
               AND recipient_user_id = ?
               AND sender_type = 'user'
               AND id > ?
               AND deleted_by_staff = 0
             ORDER BY created_at ASC",
            [$staffId, $uid, $afterId]
        ) ?: [];

        if (!empty($newMsgs)) {
            execute(
                "UPDATE chat_messages SET is_read = 1
                 WHERE consultant_id = ?
                   AND recipient_user_id = ?
                   AND sender_type = 'user'
                   AND is_read = 0",
                [$staffId, $uid]
            );
        }

        // Recalculate unread for all users
        $unread = [];
        foreach ($assignedUsers as $u) {
            $r = fetchOne(
                "SELECT COUNT(*) AS cnt FROM chat_messages
                 WHERE consultant_id = ?
                   AND recipient_user_id = ?
                   AND sender_type = 'user'
                   AND is_read = 0
                   AND deleted_by_staff = 0",
                [$staffId, $u['id']]
            );
            $unread[$u['id']] = (int)($r['cnt'] ?? 0);
        }
        echo json_encode(['messages' => $newMsgs, 'unread' => $unread]);
        exit();
    }

    // ── Delete staff's own message ─────────────────────────────────────────────
    if ($action === 'delete_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        $msg = fetchOne(
            "SELECT * FROM chat_messages
             WHERE id = ? AND sender_id = ? AND sender_type = 'consultant' AND recipient_user_id = ?",
            [$msgId, $staffId, $uid]
        );
        if ($msg) {
            execute("UPDATE chat_messages SET deleted_by_staff = 1 WHERE id = ?", [$msgId]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Not authorised']);
        }
        exit();
    }

    // ── Send text ──────────────────────────────────────────────────────────────
    if ($action === 'send_text') {
        $body = trim($_POST['body'] ?? '');
        if (!$body) { echo json_encode(['error' => 'Empty']); exit(); }

        // Get subscription_id (optional, for compatibility)
        $subId = 0;
        try {
            $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY end_date DESC LIMIT 1", [$uid]);
            if (!$sub) $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
            $subId = (int)($sub['id'] ?? 0);
        } catch(Exception $e) {}

        // KEY: always store recipient_user_id = $uid (the user this message is FOR)
        $msgId = insert(
            "INSERT INTO chat_messages
             (subscription_id, sender_type, sender_id, recipient_user_id, consultant_id, message_type, body)
             VALUES (?, 'consultant', ?, ?, ?, 'text', ?)",
            [$subId, $staffId, $uid, $staffId, $body]
        );
        $msg = fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit();
    }

    // ── Send file ──────────────────────────────────────────────────────────────
    if ($action === 'send_file' && isset($_FILES['file'])) {
        $f   = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xlsx','xls','txt','zip','mp3','m4a','ogg','wav'];
        if (!in_array($ext, $allowed)) { echo json_encode(['error' => 'File type not allowed']); exit(); }
        if ($f['size'] > 20 * 1024 * 1024) { echo json_encode(['error' => 'Max 20MB']); exit(); }

        $uploadDir = BASE_PATH . '/uploads/chat/staff/' . $staffId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = time() . '_' . preg_replace('/[^a-z0-9._-]/', '', strtolower(basename($f['name'])));
        move_uploaded_file($f['tmp_name'], $uploadDir . $safeName);

        $filePath = '/gurkha-marga/uploads/chat/staff/' . $staffId . '/' . $safeName;
        $msgType  = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'image' : 'file';

        $subId = 0;
        try {
            $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY end_date DESC LIMIT 1", [$uid]);
            if (!$sub) $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
            $subId = (int)($sub['id'] ?? 0);
        } catch(Exception $e) {}

        // KEY: always store recipient_user_id = $uid
        $msgId = insert(
            "INSERT INTO chat_messages
             (subscription_id, sender_type, sender_id, recipient_user_id, consultant_id, message_type, file_path, file_name, file_size)
             VALUES (?, 'consultant', ?, ?, ?, ?, ?, ?, ?)",
            [$subId, $staffId, $uid, $staffId, $msgType, $filePath, $f['name'], $f['size']]
        );
        $msg = fetchOne("SELECT * FROM chat_messages WHERE id = ?", [$msgId]);
        echo json_encode(['ok' => true, 'message' => $msg]);
        exit();
    }

    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$accentMap = ['dietitian'=>['#06b6d4','6,182,212'],'consultant'=>['#8b5cf6','139,92,246'],'admin'=>['#3b82f6','59,130,246'],'superadmin'=>['#3b82f6','59,130,246']];
$ra        = $accentMap[$staffRole] ?? $accentMap['consultant'];
$accentHex = $ra[0]; $accentRgb = $ra[1];
$totalUnread = array_sum($unreadCounts);

function fmtBytes(int $b): string { if($b<1024)return $b.'B'; if($b<1048576)return round($b/1024,1).'KB'; return round($b/1048576,1).'MB'; }
function timeAgo(string $dt): string {
    $d=time()-strtotime($dt);
    if($d<60)return 'now';if($d<3600)return floor($d/60).'m';if($d<86400)return floor($d/3600).'h';
    return date('M j',strtotime($dt));
}
function av(string $n): string { return strtoupper(substr(trim($n),0,1)); }
function getFileEmoji(string $name): string {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    return match(true){in_array($ext,['jpg','jpeg','png','gif','webp'])=>'🖼️',$ext==='pdf'=>'📄',in_array($ext,['doc','docx'])=>'📝',in_array($ext,['xlsx','xls'])=>'📊',default=>'📎'};
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages — Staff Portal</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
    --accent:     <?= $accentHex ?>;
    --accent-rgb: <?= $accentRgb ?>;
    --bg:         #020617;
    --surface:    rgba(15,23,42,.85);
    --panel:      #0f172a;
    --border:     rgba(255,255,255,.08);
    --border-hi:  rgba(255,255,255,.14);
    --text:       #f1f5f9;
    --text-2:     #94a3b8;
    --text-3:     #475569;
    --gold:       #f59e0b;
    --green:      #22c55e;
    --red:        #ef4444;
    --bubble-out: linear-gradient(135deg,var(--accent),rgba(var(--accent-rgb),.6));
    --bubble-in:  #1e293b;
    --font:       'DM Sans',sans-serif;
    --mono:       'DM Mono',monospace;
    --r:          20px;
    --r-sm:       12px;
    --sidebar:    300px;
}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);font-family:var(--font)}
body{background-image:radial-gradient(ellipse 70% 50% at 50% -10%,rgba(<?= $accentRgb ?>,.06),transparent)}
::-webkit-scrollbar{width:0}

.root{display:flex;height:100vh;overflow:hidden}

/* ── NAV RAIL ── */
.nav-rail{width:64px;flex-shrink:0;background:rgba(2,6,23,.97);border-right:1px solid var(--border);display:flex;flex-direction:column;align-items:center;padding:.75rem 0;gap:.2rem;z-index:10}
.nr-brand{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--gold),#d97706);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.75rem;color:#000;font-family:var(--mono);margin-bottom:.5rem;text-decoration:none}
.nr-sep{width:28px;height:1px;background:var(--border);margin:.3rem 0}
.nr-item{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--text-3);text-decoration:none;transition:all .15s;position:relative;font-size:1rem}
.nr-item:hover{background:rgba(255,255,255,.05);color:var(--text-2)}
.nr-item.active{background:rgba(var(--accent-rgb),.12);color:var(--accent)}
.nr-item .nr-dot{position:absolute;top:5px;right:5px;width:7px;height:7px;border-radius:50%;background:var(--red);border:1.5px solid var(--bg)}
.nr-bottom{margin-top:auto;display:flex;flex-direction:column;align-items:center;gap:.2rem}
.nr-av{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:#fff;text-decoration:none}

/* ── THREAD SIDEBAR ── */
.thread-sb{width:var(--sidebar);flex-shrink:0;background:rgba(2,6,23,.95);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.tsb-head{padding:1rem 1.1rem .7rem;border-bottom:1px solid var(--border)}
.tsb-title{font-size:1rem;font-weight:700;letter-spacing:-.02em;display:flex;align-items:center;justify-content:space-between}
.tsb-title a{font-size:.72rem;color:var(--accent);text-decoration:none;font-weight:500}
.tsb-sub{font-size:.72rem;color:var(--text-3);margin-top:2px}
.tsb-search{margin:.55rem .9rem;position:relative}
.tsb-search input{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:18px;color:var(--text);font-family:var(--font);font-size:.8rem;padding:.38rem .9rem .38rem 2rem;outline:none;transition:all .2s}
.tsb-search input:focus{border-color:rgba(var(--accent-rgb),.35)}
.tsb-search svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);width:12px;height:12px;color:var(--text-3)}
.tsb-list{flex:1;overflow-y:auto}
.ts-item{display:flex;align-items:center;gap:.75rem;padding:.78rem .9rem;cursor:pointer;transition:background .12s;text-decoration:none;color:inherit;border-bottom:1px solid rgba(255,255,255,.03);position:relative}
.ts-item:hover{background:rgba(255,255,255,.03)}
.ts-item.active{background:rgba(var(--accent-rgb),.07)}
.ts-item.active::after{content:'';position:absolute;right:0;top:20%;bottom:20%;width:2.5px;background:var(--accent);border-radius:2px 0 0 2px}
.ts-av{width:46px;height:46px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#fff;position:relative}
.ts-online{position:absolute;bottom:1px;right:1px;width:11px;height:11px;border-radius:50%;background:var(--green);border:2px solid var(--bg)}
.ts-info{flex:1;min-width:0}
.ts-name{font-size:.875rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ts-preview{font-size:.74rem;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ts-preview.unread{color:var(--text-2);font-weight:500}
.ts-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.ts-time{font-size:.65rem;color:var(--text-3);font-family:var(--mono)}
.ts-badge{background:var(--accent);color:#fff;font-size:.62rem;font-weight:700;border-radius:99px;padding:.08rem .4rem;min-width:17px;text-align:center}
.ts-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:.65rem;padding:2rem;text-align:center;color:var(--text-3)}
.ts-empty-ico{font-size:2rem;opacity:.2}
.ts-empty-title{font-size:.86rem;font-weight:600;color:var(--text-2)}
.ts-empty-text{font-size:.74rem;line-height:1.6}

/* ── CHAT AREA ── */
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0;background:var(--bg)}

/* Header */
.ch-hdr{height:62px;padding:0 1.1rem;display:flex;align-items:center;gap:.85rem;background:rgba(2,6,23,.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);flex-shrink:0}
.ch-av{width:36px;height:36px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:#fff;position:relative}
.ch-av-online{position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;background:var(--green);border:2px solid var(--bg)}
.ch-name{font-size:.9rem;font-weight:700}
.ch-status{font-size:.7rem;color:var(--green);display:flex;align-items:center;gap:3px;margin-top:1px}
.ch-status::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}
.ch-hdr-actions{margin-left:auto;display:flex;gap:.35rem}
.ch-btn{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .14s;font-size:.9rem;text-decoration:none}
.ch-btn:hover{background:rgba(255,255,255,.09);color:var(--text)}

/* Messages */
.msgs{flex:1;overflow-y:auto;padding:1.1rem 1.25rem;display:flex;flex-direction:column;gap:.18rem}

/* Date sep */
.date-sep{display:flex;align-items:center;gap:.65rem;margin:.75rem 0 .4rem}
.date-sep::before,.date-sep::after{content:'';flex:1;height:1px;background:var(--border)}
.date-sep span{font-size:.64rem;color:var(--text-3);white-space:nowrap;font-family:var(--mono)}

/* Row */
.msg-row{display:flex;align-items:flex-end;gap:.55rem;max-width:70%;position:relative}
.msg-row.out{align-self:flex-end;flex-direction:row-reverse}
.msg-row.in{align-self:flex-start}

/* Delete btn */
.del-btn{display:none;position:absolute;top:50%;transform:translateY(-50%);right:-34px;width:24px;height:24px;border-radius:50%;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.1);color:var(--text-3);font-size:.6rem;cursor:pointer;align-items:center;justify-content:center;z-index:5}
.msg-row.out:hover .del-btn{display:flex}
.del-btn:hover{background:rgba(239,68,68,.25);color:#fca5a5}

/* Avatar */
.msg-av{width:24px;height:24px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--accent),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:700;color:#fff;align-self:flex-end;margin-bottom:2px}
.msg-av.hidden{visibility:hidden}

/* Body */
.m-body{display:flex;flex-direction:column;min-width:0}
.bubble{padding:.58rem .95rem;border-radius:var(--r);font-size:.875rem;line-height:1.55;word-break:break-word;animation:pop .18s cubic-bezier(.34,1.56,.64,1)}
.out .bubble{background:var(--bubble-out);color:#fff;border-bottom-right-radius:4px}
.in  .bubble{background:var(--bubble-in);color:var(--text);border-bottom-left-radius:4px;border:1px solid var(--border)}
.m-time{font-size:.59rem;color:var(--text-3);margin-top:2px;font-family:var(--mono);display:flex;align-items:center;gap:3px}
.out .m-time{justify-content:flex-end}
.tick{color:var(--accent)}
.img-bubble{padding:3px}
.img-bubble img{max-width:230px;max-height:210px;border-radius:12px;display:block;cursor:zoom-in;object-fit:cover}
.file-bubble{display:flex;align-items:center;gap:.7rem;padding:.58rem .85rem;min-width:170px;text-decoration:none;color:inherit}
.file-icon{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.fn{font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:155px}
.fs{font-size:.65rem;opacity:.6;margin-top:2px}

/* Typing */
.typing-row{display:none;align-self:flex-start;align-items:flex-end;gap:.55rem}
.typing-row.show{display:flex;animation:pop .2s ease}
.t-dots{display:flex;gap:3px;align-items:center;padding:.5rem .8rem}
.t-dots span{width:6px;height:6px;border-radius:50%;background:var(--text-3);animation:tdot 1.1s infinite}
.t-dots span:nth-child(2){animation-delay:.22s}.t-dots span:nth-child(3){animation-delay:.44s}

/* Input */
.input-wrap{padding:.6rem 1rem;background:rgba(2,6,23,.9);backdrop-filter:blur(20px);border-top:1px solid var(--border);flex-shrink:0}
.fp-bar{display:none;flex-wrap:wrap;gap:.35rem;padding:.4rem 0;margin-bottom:.35rem}
.fp-bar.show{display:flex}
.fp-chip{display:flex;align-items:center;gap:.35rem;background:rgba(var(--accent-rgb),.1);border:1px solid rgba(var(--accent-rgb),.2);border-radius:18px;padding:.2rem .55rem;font-size:.72rem;color:var(--accent)}
.fp-chip .x{cursor:pointer;opacity:.6;margin-left:1px}
.fp-chip .x:hover{opacity:1}
.fp-thumb{width:20px;height:20px;border-radius:4px;object-fit:cover}
.inp-row{display:flex;align-items:flex-end;gap:.35rem;background:rgba(255,255,255,.05);border:1.5px solid var(--border);border-radius:22px;padding:.28rem .28rem .28rem .85rem;transition:border-color .2s}
.inp-row:focus-within{border-color:rgba(var(--accent-rgb),.3)}
.msg-ta{flex:1;background:transparent;border:none;outline:none;color:var(--text);font-size:.88rem;line-height:1.45;resize:none;max-height:115px;padding:.28rem 0;font-family:var(--font)}
.msg-ta::placeholder{color:var(--text-3)}
.ia{display:flex;align-items:flex-end;gap:.05rem;padding-bottom:.05rem}
.ia-btn{width:34px;height:34px;border-radius:50%;border:none;background:transparent;color:var(--text-3);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;font-size:.95rem;flex-shrink:0}
.ia-btn:hover{background:rgba(255,255,255,.06);color:var(--text)}
.ia-btn.send{background:var(--accent);color:#fff}
.ia-btn.send:hover{background:rgba(var(--accent-rgb),.8);transform:scale(1.05)}

/* Empty */
.empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.65rem;color:var(--text-3);text-align:center;padding:2rem}
.empty-ico{font-size:2.5rem;opacity:.15;margin-bottom:.25rem}
.empty-title{font-size:1rem;font-weight:600;color:var(--text-2)}
.empty-text{font-size:.8rem;line-height:1.7;max-width:250px}
.empty-link{padding:.46rem 1.1rem;border-radius:18px;background:rgba(var(--accent-rgb),.12);border:1px solid rgba(var(--accent-rgb),.25);color:var(--accent);font-size:.8rem;font-weight:600;text-decoration:none;margin-top:.25rem}

/* Lightbox */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:9999;align-items:center;justify-content:center}
.lightbox.show{display:flex;animation:fadeIn .15s ease}
.lb-img{max-width:90vw;max-height:90vh;object-fit:contain;border-radius:6px}
.lb-close{position:fixed;top:1rem;right:1.25rem;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.1);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center}

/* Delete confirm */
.del-confirm{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(8px);z-index:8000;align-items:flex-end;justify-content:center;padding-bottom:1.5rem}
.del-confirm.show{display:flex;animation:fadeIn .15s ease}
.del-sheet{background:#1e293b;border:1px solid var(--border-hi);border-radius:18px;width:100%;max-width:340px;overflow:hidden}
.ds-btn{width:100%;padding:.95rem;background:none;border:none;font-family:var(--font);font-size:.88rem;cursor:pointer;text-align:center;transition:background .15s;color:var(--text-2)}
.ds-btn:hover{background:rgba(255,255,255,.05)}
.ds-btn.danger{color:#ff453a;font-weight:600}
.ds-btn.cancel{color:var(--text-3)}
.ds-sep{height:1px;background:var(--border)}

@keyframes pop{from{opacity:0;transform:scale(.88) translateY(4px)}to{opacity:1;transform:none}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes tdot{0%,80%,100%{transform:scale(.7);opacity:.4}40%{transform:scale(1.1);opacity:1}}
@media(max-width:900px){.thread-sb{display:none}.nav-rail{width:52px}}
@media(max-width:600px){.nav-rail{display:none}}
</style>
</head>
<body>
<div class="root">

<!-- ── NAV RAIL ── -->
<nav class="nav-rail">
    <a href="staff_dashboard.php" class="nr-brand">GM</a>
    <div class="nr-sep"></div>
    <a href="staff_dashboard.php" class="nr-item" title="Overview">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
    </a>
    <a href="premium_client.php" class="nr-item" title="Clients">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    </a>
    <a href="premium_message.php" class="nr-item active" title="Messages">
        <?php if ($totalUnread): ?><span class="nr-dot"></span><?php endif; ?>
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
    </a>
    <div class="nr-bottom">
        <div class="nr-sep"></div>
        <a href="staff_dashboard.php?page=settings" class="nr-item" title="Settings">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </a>
        <a href="#" class="nr-av" title="<?= htmlspecialchars($staffName) ?>"><?= av($staffName) ?></a>
    </div>
</nav>

<!-- ── THREAD SIDEBAR ── -->
<div class="thread-sb">
    <div class="tsb-head">
        <div class="tsb-title">
            Clients
            <a href="premium_client.php">All →</a>
        </div>
        <div class="tsb-sub"><?= count($assignedUsers) ?> assigned · <?= $totalUnread ?> unread</div>
    </div>
    <div class="tsb-search">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" placeholder="Search clients…" oninput="filterThreads(this.value)">
    </div>
    <div class="tsb-list" id="threadList">
        <?php if (empty($assignedUsers)): ?>
        <div class="ts-empty">
            <div class="ts-empty-ico">💬</div>
            <div class="ts-empty-title">No clients yet</div>
            <div class="ts-empty-text">Clients who select you as their expert will appear here.</div>
        </div>
        <?php else:
            foreach ($assignedUsers as $u):
                $isActive = (int)$u['id'] === $activeUid;
                $unread   = $unreadCounts[$u['id']] ?? 0;
                $lm       = $lastMsgs[$u['id']] ?? null;
                $preview  = '';
                if ($lm) {
                    $isMe    = $lm['sender_type'] === 'consultant';
                    $txt     = match($lm['message_type'] ?? 'text') {
                        'image'=>'📷 Photo','file'=>'📎 File','voice'=>'🎤 Voice',
                        default=>mb_substr($lm['body']??'',0,36).(mb_strlen($lm['body']??'')>36?'…':'')
                    };
                    $preview = ($isMe ? 'You: ' : '') . $txt;
                }
        ?>
        <a href="premium_message.php?uid=<?= $u['id'] ?>"
           class="ts-item <?= $isActive?'active':'' ?>"
           data-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>">
            <div class="ts-av">
                <?= av($u['full_name']) ?>
                <div class="ts-online"></div>
            </div>
            <div class="ts-info">
                <div class="ts-name"><?= htmlspecialchars($u['full_name']) ?></div>
                <div class="ts-preview <?= $unread?'unread':'' ?>">
                    <?= $preview ?: '<span style="color:var(--text-3);font-size:.7rem">No messages yet</span>' ?>
                </div>
            </div>
            <div class="ts-meta">
                <?php if ($lm): ?><div class="ts-time"><?= timeAgo($lm['created_at']) ?></div><?php endif; ?>
                <div id="badge-<?= $u['id'] ?>" style="display:<?= $unread?'':'none' ?>">
                    <?php if ($unread): ?><div class="ts-badge"><?= $unread ?></div><?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ── CHAT AREA ── -->
<div class="chat-area">
<?php if ($activeUser): ?>

    <div class="ch-hdr">
        <div class="ch-av">
            <?= av($activeUser['full_name']) ?>
            <div class="ch-av-online"></div>
        </div>
        <div>
            <div class="ch-name"><?= htmlspecialchars($activeUser['full_name']) ?></div>
            <div class="ch-status">Active · <?= htmlspecialchars($activeUser['email']) ?></div>
        </div>
        <div class="ch-hdr-actions">
            <a href="staff_dashboard.php?page=profile&uid=<?= $activeUid ?>" class="ch-btn" title="View profile">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </a>
        </div>
    </div>

    <div class="msgs" id="msgs">
        <?php if (empty($messages)): ?>
        <div id="emptyHint" style="margin:auto;text-align:center;color:var(--text-3);padding:2rem">
            <div style="font-size:2rem;margin-bottom:.65rem;opacity:.18">💬</div>
            <div style="font-size:.9rem;font-weight:600;color:var(--text-2);margin-bottom:.3rem">Start the conversation</div>
            <div style="font-size:.79rem;line-height:1.65">Send <?= htmlspecialchars($activeUser['full_name']) ?> a message<br>to get started.</div>
        </div>
        <?php else:
            $lastDate = '';
            foreach ($messages as $i => $msg):
                $isOut   = ($msg['sender_type'] === 'consultant');
                $msgDate = date('d M Y', strtotime($msg['created_at']));
                $timeStr = date('g:i A', strtotime($msg['created_at']));
                $nextMsg = $messages[$i+1] ?? null;
                $isLast  = !$nextMsg || $nextMsg['sender_type'] !== $msg['sender_type'];
        ?>
            <?php if ($msgDate !== $lastDate): $lastDate = $msgDate; ?>
            <div class="date-sep"><span><?= $msgDate===date('d M Y')?'Today':($msgDate===date('d M Y',strtotime('-1 day'))?'Yesterday':$msgDate) ?></span></div>
            <?php endif; ?>
            <div class="msg-row <?= $isOut?'out':'in' ?>" data-id="<?= (int)$msg['id'] ?>">
                <?php if (!$isOut): ?><div class="msg-av <?= $isLast?'':'hidden' ?>"><?= av($activeUser['full_name']) ?></div><?php endif; ?>
                <div class="m-body">
                    <?php if ($msg['message_type']==='image' && $msg['file_path']): ?>
                    <div class="bubble img-bubble"><img src="<?= htmlspecialchars($msg['file_path']) ?>" alt="Photo" onclick="openLB(this.src)" loading="lazy"></div>
                    <?php elseif ($msg['message_type']==='file' && $msg['file_path']): ?>
                    <a href="<?= htmlspecialchars($msg['file_path']) ?>" target="_blank" class="bubble file-bubble">
                        <div class="file-icon"><?= getFileEmoji($msg['file_name']??'') ?></div>
                        <div><div class="fn"><?= htmlspecialchars($msg['file_name']??'File') ?></div><div class="fs"><?= fmtBytes((int)($msg['file_size']??0)) ?></div></div>
                    </a>
                    <?php else: ?>
                    <div class="bubble"><?= nl2br(htmlspecialchars($msg['body']??'')) ?></div>
                    <?php endif; ?>
                    <?php if ($isLast): ?>
                    <div class="m-time"><?= $timeStr ?><?= $isOut?' <span class="tick">✓✓</span>':'' ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($isOut): ?><button class="del-btn" onclick="showDel(<?= (int)$msg['id'] ?>)">✕</button><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>

        <div class="typing-row" id="typingRow">
            <div class="msg-av"><?= av($activeUser['full_name']) ?></div>
            <div class="m-body"><div class="bubble"><div class="t-dots"><span></span><span></span><span></span></div></div></div>
        </div>
    </div>

    <div class="input-wrap">
        <div class="fp-bar" id="fpBar"></div>
        <div class="inp-row">
            <textarea class="msg-ta" id="msgInput" rows="1"
                placeholder="Message <?= htmlspecialchars($activeUser['full_name']) ?>…"
                onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
            <div class="ia">
                <button class="ia-btn" title="Attach" onclick="document.getElementById('fileIn').click()">
                    <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </button>
                <input type="file" id="fileIn" style="display:none" multiple accept="image/*,.pdf,.doc,.docx,.xlsx,.txt,.zip" onchange="queueFiles(this)">
                <button class="ia-btn send" onclick="sendMsg()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="empty-state">
        <div class="empty-ico">💬</div>
        <div class="empty-title"><?= empty($assignedUsers)?'No clients assigned':'Select a conversation' ?></div>
        <div class="empty-text"><?= empty($assignedUsers)?'Premium clients who select you as their expert will appear here.':'Choose a client from the left panel.' ?></div>
        <?php if (!empty($assignedUsers)): ?><a href="premium_message.php?uid=<?= $assignedUsers[0]['id'] ?>" class="empty-link">Open first conversation</a><?php endif; ?>
    </div>
<?php endif; ?>
</div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLB()">
    <button class="lb-close" onclick="closeLB()">✕</button>
    <img class="lb-img" id="lbImg" src="" alt="">
</div>

<!-- Delete confirm -->
<div class="del-confirm" id="delConfirm">
    <div class="del-sheet">
        <button class="ds-btn danger" onclick="doDelete()">Unsend Message</button>
        <div class="ds-sep"></div>
        <button class="ds-btn cancel" onclick="closeDel()">Cancel</button>
    </div>
</div>

<script>
const STAFF_ID   = <?= $staffId ?>;
const ACTIVE_UID = <?= $activeUid ?>;
const USER_INIT  = <?= json_encode($activeUser ? av($activeUser['full_name']) : '') ?>;

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function fmtTime(iso){return new Date(iso).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function fmtBytes(b){if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB'}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,115)+'px'}
function scrollBottom(){const m=document.getElementById('msgs');if(m)m.scrollTo({top:m.scrollHeight,behavior:'smooth'})}
function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg()}}
function filterThreads(q){document.querySelectorAll('.ts-item').forEach(el=>{el.style.display=el.dataset.name?.includes(q.toLowerCase())?'':'none'})}
function openLB(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('show')}
function closeLB(){document.getElementById('lightbox').classList.remove('show')}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLB();closeDel()}})

let pendingDel=null;
function showDel(id){pendingDel=id;document.getElementById('delConfirm').classList.add('show')}
function closeDel(){document.getElementById('delConfirm').classList.remove('show');pendingDel=null}
document.getElementById('delConfirm')?.addEventListener('click',function(e){if(e.target===this)closeDel()})
async function doDelete(){
    if(!pendingDel)return;const id=pendingDel;closeDel();
    const row=document.querySelector(`.msg-row[data-id="${id}"]`);
    const fd=new FormData();fd.append('ajax_action','delete_message');fd.append('message_id',id);fd.append('user_id',ACTIVE_UID);
    try{
        const r=await fetch('premium_message.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok&&row){row.style.transition='opacity .25s,transform .25s';row.style.opacity='0';row.style.transform='scale(.93)';setTimeout(()=>row.remove(),260)}
    }catch(e){}
}

let fileQueue=[];
function queueFiles(input){for(const f of input.files)fileQueue.push(f);renderFP();input.value=''}
function renderFP(){
    const bar=document.getElementById('fpBar');
    if(!fileQueue.length){bar.classList.remove('show');bar.innerHTML='';return}
    bar.classList.add('show');
    bar.innerHTML=fileQueue.map((f,i)=>{
        const isImg=f.type.startsWith('image/');
        return`<div class="fp-chip">${isImg?`<img class="fp-thumb" src="${URL.createObjectURL(f)}" alt="">`:''}<span>${esc(f.name.length>18?f.name.slice(0,15)+'…':f.name)}</span><span class="x" onclick="removeQ(${i})">×</span></div>`;
    }).join('');
}
function removeQ(i){fileQueue.splice(i,1);renderFP()}

function appendBubble(msg,pending=false){
    const isOut=msg.sender_type==='consultant';
    const time=fmtTime(msg.created_at||new Date().toISOString());
    let inner='';
    if(msg.message_type==='image'&&(msg._blob||msg.file_path)){
        inner=`<div class="bubble img-bubble"><img src="${msg._blob||esc(msg.file_path)}" alt="Photo" onclick="openLB(this.src)" loading="lazy"></div>`;
    }else if(msg.message_type==='file'){
        inner=`<a href="${msg.file_path?esc(msg.file_path):'#'}" target="_blank" class="bubble file-bubble"><div class="file-icon">📎</div><div><div class="fn">${esc(msg.file_name||'File')}</div><div class="fs">${fmtBytes(msg.file_size||0)}</div></div></a>`;
    }else{
        inner=`<div class="bubble">${esc(msg.body||'').replace(/\n/g,'<br>')}</div>`;
    }
    document.getElementById('emptyHint')?.remove();
    const row=document.createElement('div');
    row.className=`msg-row ${isOut?'out':'in'}`;
    if(msg.id)row.dataset.id=msg.id;
    if(pending)row.style.opacity='.55';
    const avPart=!isOut?`<div class="msg-av">${USER_INIT}</div>`:'';
    const delPart=isOut?`<button class="del-btn" onclick="showDel(${msg.id||0})">✕</button>`:'';
    row.innerHTML=`${avPart}<div class="m-body">${inner}<div class="m-time">${time}${isOut?' <span class="tick">✓✓</span>':''}</div></div>${delPart}`;
    const tr=document.getElementById('typingRow');
    const msgs=document.getElementById('msgs');
    if(tr)msgs.insertBefore(row,tr);else msgs.appendChild(row);
    scrollBottom();return row;
}

async function sendMsg(){
    const inp=document.getElementById('msgInput');const text=inp.value.trim();
    // Send files
    for(const f of [...fileQueue]){
        const isImg=f.type.startsWith('image/');
        const row=appendBubble({sender_type:'consultant',message_type:isImg?'image':'file',_blob:isImg?URL.createObjectURL(f):null,file_name:f.name,file_size:f.size,created_at:new Date().toISOString()},true);
        const fd=new FormData();fd.append('ajax_action','send_file');fd.append('user_id',ACTIVE_UID);fd.append('file',f);
        try{
            const r=await fetch('premium_message.php',{method:'POST',body:fd});
            const d=await r.json();
            if(d.ok){row.style.opacity='1';if(d.message?.id){row.dataset.id=d.message.id;lastId=Math.max(lastId,+d.message.id)}}
            else{row.style.opacity='.3';console.error('File send failed:',d.error);}
        }catch(e){row.style.opacity='.3'}
    }
    fileQueue=[];renderFP();
    if(!text)return;
    inp.value='';inp.style.height='auto';
    const row=appendBubble({sender_type:'consultant',message_type:'text',body:text,created_at:new Date().toISOString()},true);
    const fd=new FormData();fd.append('ajax_action','send_text');fd.append('user_id',ACTIVE_UID);fd.append('body',text);
    try{
        const r=await fetch('premium_message.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok&&d.message?.id){
            row.style.opacity='1';row.dataset.id=d.message.id;
            const db=row.querySelector('.del-btn');if(db)db.onclick=()=>showDel(d.message.id);
            lastId=Math.max(lastId,+d.message.id);
        }else{
            row.style.opacity='.3';
            console.error('Send failed:',d.error);
        }
    }catch(e){row.style.opacity='.3';console.error(e)}
}

// Poll for new user messages
let lastId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;

async function poll(){
    if(!ACTIVE_UID)return;
    try{
        const fd=new FormData();
        fd.append('ajax_action','poll');
        fd.append('user_id',ACTIVE_UID);
        fd.append('after_id',lastId);
        const r=await fetch('premium_message.php',{method:'POST',body:fd});
        if(!r.ok)return;
        const d=await r.json();
        if(d.messages?.length){
            d.messages.forEach(m=>{
                if(!document.querySelector(`.msg-row[data-id="${m.id}"]`)){
                    appendBubble(m);
                    lastId=Math.max(lastId,+m.id||0);
                }
            });
        }
        if(d.unread){   
            Object.entries(d.unread).forEach(([uid,cnt])=>{
                const wrapper=document.getElementById('badge-'+uid);
                if(wrapper){
                    wrapper.style.display=cnt>0?'':'none';
                    wrapper.innerHTML=cnt>0?`<div class="ts-badge">${cnt}</div>`:'';
                }
            });
        }
    }catch(e){}
}
setInterval(poll,4000);
document.addEventListener('DOMContentLoaded',()=>scrollBottom());
</script>
</body>
</html>