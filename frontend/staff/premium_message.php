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

    $validUser = null;
    foreach ($assignedUsers as $u) {
        if ((int)$u['id'] === $uid) { $validUser = $u; break; }
    }
    if (!$validUser) { echo json_encode(['error' => 'Not assigned']); exit(); }

    if ($action === 'poll') {
        $afterId = (int)($_POST['after_id'] ?? 0);
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

    if ($action === 'send_text') {
        $body = trim($_POST['body'] ?? '');
        if (!$body) { echo json_encode(['error' => 'Empty']); exit(); }

        $subId = 0;
        try {
            $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY end_date DESC LIMIT 1", [$uid]);
            if (!$sub) $sub = fetchOne("SELECT id FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
            $subId = (int)($sub['id'] ?? 0);
        } catch(Exception $e) {}

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

$staffInitial = av($staffName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages — Gurkha Marga Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
    --gold:       #f59e0b;
    --gold-dim:   rgba(245,158,11,.12);
    --gold-border:rgba(245,158,11,.22);
    --accent:     #3b82f6;
    --accent-rgb: 59,130,246;
    --purple:     #8b5cf6;
    --green:      #10b981;
    --red:        #ef4444;
    --bg:         #0f172a;
    --bg2:        #1e293b;
    --surface:    rgba(30,41,59,.7);
    --sidebar-bg: rgba(10,17,32,.97);
    --border:     rgba(255,255,255,.07);
    --border-hi:  rgba(255,255,255,.12);
    --text:       #f8fafc;
    --text-2:     #cbd5e1;
    --text-3:     #64748b;
    --bubble-out: linear-gradient(135deg,#1d4ed8,#3b82f6);
    --bubble-in:  rgba(30,41,59,.9);
    --font:       'Poppins',sans-serif;
    --mono:       'Courier New',monospace;
    --nav-w:      260px;
    --thread-w:   280px;
}
html,body{height:100%;overflow:hidden;background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#0f172a 100%);color:var(--text);font-family:var(--font)}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(59,130,246,.04),transparent);pointer-events:none;z-index:0}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:2px}

.root{display:flex;height:100vh;overflow:hidden;position:relative;z-index:1}

/* ══ SIDEBAR — matches premium_client.php exactly ══ */
.sidebar{
    width:var(--nav-w);flex-shrink:0;
    background:var(--sidebar-bg);
    backdrop-filter:blur(20px);
    border-right:1px solid var(--border);
    display:flex;flex-direction:column;
    overflow-y:auto;
}
.sb-header{padding:1.3rem 1.1rem .9rem;border-bottom:1px solid var(--border)}
.sb-logo{display:flex;align-items:center;gap:.75rem;margin-bottom:.85rem}
/* ── LOGO: image, same as premium_client.php ── */
.sb-logo-mark{
    width:36px;height:36px;border-radius:8px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    overflow:hidden;background:transparent;
}
.sb-logo-mark img{width:36px;height:36px;object-fit:contain}
.sb-brand-wrap{}
.sb-brand{
    font-size:.95rem;font-weight:800;letter-spacing:-.02em;color:var(--gold);
}
.sb-brand-sub{font-size:.55rem;color:var(--text-3);letter-spacing:.14em;text-transform:uppercase;margin-top:1px}
/* ── User card ── */
.sb-user-card{
    background:rgba(255,255,255,.03);
    border:1px solid var(--border);
    border-radius:10px;padding:.65rem .8rem;
    display:flex;align-items:center;gap:.65rem;
}
.sb-av{
    width:30px;height:30px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),var(--purple));
    display:flex;align-items:center;justify-content:center;
    font-size:.75rem;font-weight:700;color:#fff;
}
.sb-uname{font-size:.78rem;font-weight:600}
.sb-urole{font-size:.62rem;color:var(--accent);margin-top:1px;text-transform:capitalize}
.sb-pulse{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 5px var(--green);margin-left:auto;animation:pulse 2.5s infinite;flex-shrink:0}

.sb-nav{flex:1;padding:.25rem 0}
.nav-sec{
    font-size:.56rem;font-weight:700;letter-spacing:.14em;
    text-transform:uppercase;color:rgba(100,116,139,.45);
    padding:.7rem 1.1rem .25rem;
}
.nav-item{
    padding:.48rem 1.1rem;display:flex;align-items:center;gap:.65rem;
    color:var(--text-3);text-decoration:none;
    transition:all .14s;border-left:2px solid transparent;font-size:.8rem;font-weight:500;
}
.nav-item:hover{background:rgba(255,255,255,.03);color:var(--text-2)}
.nav-item.active{background:rgba(255,255,255,.05);color:var(--text);border-left-color:var(--accent)}
.nav-item.danger{color:#f87171}
.nav-item.danger:hover{background:rgba(239,68,68,.06);border-left-color:var(--red)}
.nav-item svg{width:14px;height:14px;flex-shrink:0;opacity:.65}
.nav-item.active svg{opacity:1}
.nav-badge{
    margin-left:auto;background:var(--red);color:#fff;
    font-size:.57rem;font-weight:700;
    padding:.08rem .38rem;border-radius:99px;min-width:16px;text-align:center;
}

/* ══ THREAD PANEL ══ */
.thread-panel{
    width:var(--thread-w);flex-shrink:0;
    background:rgba(15,23,42,.95);
    border-right:1px solid var(--border);
    display:flex;flex-direction:column;overflow:hidden;
}
.tp-head{padding:.9rem 1rem .65rem;border-bottom:1px solid var(--border)}
.tp-title{font-size:.88rem;font-weight:700;display:flex;align-items:center;justify-content:space-between}
.tp-title a{font-size:.7rem;color:var(--accent);text-decoration:none;font-weight:500;opacity:.8}
.tp-title a:hover{opacity:1}
.tp-sub{font-size:.7rem;color:var(--text-3);margin-top:2px}
.tp-search{margin:.5rem .8rem;position:relative}
.tp-search input{
    width:100%;background:rgba(255,255,255,.04);
    border:1px solid var(--border);border-radius:18px;
    color:var(--text);font-family:var(--font);font-size:.78rem;
    padding:.36rem .9rem .36rem 2rem;outline:none;transition:all .18s;
}
.tp-search input:focus{border-color:rgba(var(--accent-rgb),.3)}
.tp-search svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);width:12px;height:12px;color:var(--text-3)}
.tp-list{flex:1;overflow-y:auto}

.thread-item{
    display:flex;align-items:center;gap:.65rem;
    padding:.72rem .9rem;cursor:pointer;
    text-decoration:none;color:inherit;
    border-bottom:1px solid rgba(255,255,255,.03);
    position:relative;transition:background .12s;
}
.thread-item:hover{background:rgba(255,255,255,.03)}
.thread-item.active{background:rgba(59,130,246,.07)}
.thread-item.active::after{
    content:'';position:absolute;right:0;top:20%;bottom:20%;
    width:2.5px;background:var(--accent);border-radius:2px 0 0 2px;
}
.ti-av{
    width:42px;height:42px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),var(--purple));
    display:flex;align-items:center;justify-content:center;
    font-size:.95rem;font-weight:700;color:#fff;position:relative;
}
.ti-online{
    position:absolute;bottom:1px;right:1px;
    width:10px;height:10px;border-radius:50%;
    background:var(--green);border:2px solid var(--bg);
}
.ti-info{flex:1;min-width:0}
.ti-name{font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ti-preview{font-size:.7rem;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ti-preview.unread{color:var(--text-2);font-weight:500}
.ti-meta{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0}
.ti-time{font-size:.62rem;color:var(--text-3);font-family:var(--mono)}
.ti-badge{
    background:var(--accent);color:#fff;
    font-size:.6rem;font-weight:700;
    border-radius:99px;padding:.06rem .38rem;
    min-width:17px;text-align:center;
}
.tp-empty{
    display:flex;flex-direction:column;align-items:center;
    justify-content:center;height:100%;gap:.5rem;
    padding:2rem;text-align:center;color:var(--text-3);
}

/* ══ CHAT AREA ══ */
.chat-area{flex:1;display:flex;flex-direction:column;min-width:0}

.ch-hdr{
    height:60px;padding:0 1.25rem;
    display:flex;align-items:center;gap:.85rem;
    background:rgba(10,17,32,.9);backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border);flex-shrink:0;
}
.ch-av{
    width:36px;height:36px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),var(--purple));
    display:flex;align-items:center;justify-content:center;
    font-size:.82rem;font-weight:700;color:#fff;position:relative;
}
.ch-av-dot{position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;background:var(--green);border:2px solid var(--bg)}
.ch-name{font-size:.88rem;font-weight:700}
.ch-status{font-size:.68rem;color:var(--green);display:flex;align-items:center;gap:3px;margin-top:1px}
.ch-status::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}
.ch-hdr-right{margin-left:auto;display:flex;align-items:center;gap:.4rem}
.ch-btn{
    width:32px;height:32px;border-radius:50%;
    background:rgba(255,255,255,.04);border:1px solid var(--border);
    color:var(--text-2);display:flex;align-items:center;justify-content:center;
    cursor:pointer;transition:all .14s;text-decoration:none;font-size:.85rem;
}
.ch-btn:hover{background:rgba(255,255,255,.08);color:var(--text)}

.msgs{flex:1;overflow-y:auto;padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:.2rem}

.date-sep{display:flex;align-items:center;gap:.6rem;margin:.85rem 0 .5rem}
.date-sep::before,.date-sep::after{content:'';flex:1;height:1px;background:var(--border)}
.date-sep span{font-size:.62rem;color:var(--text-3);white-space:nowrap;font-family:var(--mono)}

.msg-row{display:flex;align-items:flex-end;gap:.5rem;max-width:68%;position:relative}
.msg-row.out{align-self:flex-end;flex-direction:row-reverse}
.msg-row.in{align-self:flex-start}

.del-btn{
    display:none;position:absolute;top:50%;transform:translateY(-50%);right:-32px;
    width:22px;height:22px;border-radius:50%;
    background:rgba(15,23,42,.9);border:1px solid rgba(255,255,255,.1);
    color:var(--text-3);font-size:.55rem;cursor:pointer;
    align-items:center;justify-content:center;z-index:5;transition:all .14s;
}
.msg-row.out:hover .del-btn{display:flex}
.del-btn:hover{background:rgba(239,68,68,.2);color:#fca5a5}

.msg-av{
    width:24px;height:24px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),var(--purple));
    display:flex;align-items:center;justify-content:center;
    font-size:.56rem;font-weight:700;color:#fff;
    align-self:flex-end;margin-bottom:2px;
}
.msg-av.hidden{visibility:hidden}

.m-body{display:flex;flex-direction:column;min-width:0}
.bubble{padding:.55rem .9rem;font-size:.845rem;line-height:1.55;word-break:break-word;animation:pop .2s cubic-bezier(.34,1.56,.64,1)}
.out .bubble{background:var(--bubble-out);color:#fff;border-radius:16px 16px 4px 16px}
.in  .bubble{background:var(--bubble-in);color:var(--text);border:1px solid var(--border);border-radius:16px 16px 16px 4px}
.m-time{font-size:.58rem;color:var(--text-3);margin-top:3px;font-family:var(--mono);display:flex;align-items:center;gap:3px}
.out .m-time{justify-content:flex-end}
.tick{color:#93c5fd}

.img-bubble{padding:3px}
.img-bubble img{max-width:220px;max-height:200px;border-radius:10px;display:block;cursor:zoom-in;object-fit:cover}
.file-bubble{display:flex;align-items:center;gap:.65rem;padding:.55rem .8rem;min-width:165px;text-decoration:none;color:inherit}
.file-icon{width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.fn{font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:145px}
.fs{font-size:.63rem;opacity:.6;margin-top:2px}

.typing-row{display:none;align-self:flex-start;align-items:flex-end;gap:.5rem}
.typing-row.show{display:flex;animation:pop .2s ease}
.t-dots{display:flex;gap:3px;align-items:center;padding:.45rem .75rem}
.t-dots span{width:5px;height:5px;border-radius:50%;background:var(--text-3);animation:tdot 1.1s infinite}
.t-dots span:nth-child(2){animation-delay:.22s}.t-dots span:nth-child(3){animation-delay:.44s}

.input-area{padding:.65rem 1.25rem;background:rgba(10,17,32,.9);backdrop-filter:blur(20px);border-top:1px solid var(--border);flex-shrink:0}
.fp-bar{display:none;flex-wrap:wrap;gap:.3rem;padding:.35rem 0;margin-bottom:.3rem}
.fp-bar.show{display:flex}
.fp-chip{display:flex;align-items:center;gap:.3rem;background:rgba(var(--accent-rgb),.1);border:1px solid rgba(var(--accent-rgb),.2);border-radius:18px;padding:.18rem .5rem;font-size:.7rem;color:var(--accent)}
.fp-chip .x{cursor:pointer;opacity:.6;margin-left:1px}
.fp-chip .x:hover{opacity:1}
.fp-thumb{width:18px;height:18px;border-radius:3px;object-fit:cover}
.inp-row{display:flex;align-items:flex-end;gap:.3rem;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:22px;padding:.25rem .25rem .25rem .8rem;transition:border-color .18s}
.inp-row:focus-within{border-color:rgba(var(--accent-rgb),.28)}
.msg-ta{flex:1;background:transparent;border:none;outline:none;color:var(--text);font-size:.85rem;line-height:1.45;resize:none;max-height:110px;padding:.28rem 0;font-family:var(--font)}
.msg-ta::placeholder{color:var(--text-3)}
.ia{display:flex;align-items:flex-end;padding-bottom:.04rem;gap:.05rem}
.ia-btn{width:33px;height:33px;border-radius:50%;border:none;background:transparent;color:var(--text-3);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;font-size:.9rem;flex-shrink:0}
.ia-btn:hover{background:rgba(255,255,255,.06);color:var(--text)}
.ia-btn.send{background:linear-gradient(135deg,var(--accent),var(--purple));color:#fff}
.ia-btn.send:hover{transform:scale(1.06);box-shadow:0 4px 12px rgba(var(--accent-rgb),.35)}

.empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;color:var(--text-3);text-align:center;padding:2rem}
.empty-ico{font-size:2.2rem;opacity:.14;margin-bottom:.2rem}
.empty-title{font-size:.92rem;font-weight:700;color:var(--text-2)}
.empty-text{font-size:.77rem;line-height:1.65;max-width:240px}

.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:9999;align-items:center;justify-content:center}
.lightbox.show{display:flex;animation:fadeIn .15s ease}
.lb-img{max-width:90vw;max-height:90vh;object-fit:contain;border-radius:6px}
.lb-close{position:fixed;top:1rem;right:1.25rem;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);border:none;color:#fff;font-size:.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center}

/* ══ DELETE CONFIRM SHEET ══ */
.del-confirm{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(8px);z-index:8000;align-items:flex-end;justify-content:center;padding-bottom:1.5rem}
.del-confirm.show{display:flex;animation:fadeIn .15s ease}
.del-sheet{background:var(--bg2);border:1px solid var(--border-hi);border-radius:18px;width:100%;max-width:320px;overflow:hidden}
.ds-btn{width:100%;padding:.9rem;background:none;border:none;font-family:var(--font);font-size:.85rem;cursor:pointer;text-align:center;transition:background .15s;color:var(--text-2)}
.ds-btn:hover{background:rgba(255,255,255,.04)}
.ds-btn.danger{color:#ff453a;font-weight:600}
.ds-btn.cancel{color:var(--text-3)}
.ds-sep{height:1px;background:var(--border)}

/* ══ LOGOUT MODAL — matches premium_client.php exactly ══ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9000;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:#1e293b;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:1.75rem 1.5rem 1.25rem;width:100%;max-width:320px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.modal-title{font-size:.95rem;font-weight:700;margin-bottom:.4rem}
.modal-body{font-size:.8rem;color:var(--text-2);line-height:1.6;margin-bottom:1.25rem}
.modal-actions{display:flex;gap:.6rem;justify-content:flex-end}
.mbtn{padding:.48rem 1.1rem;border-radius:8px;font-size:.8rem;font-weight:600;font-family:var(--font);cursor:pointer;border:1px solid;transition:all .15s}
.mbtn-cancel{background:transparent;border-color:rgba(255,255,255,.12);color:var(--text-2)}
.mbtn-cancel:hover{background:rgba(255,255,255,.06);color:var(--text)}
.mbtn-confirm{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#fca5a5}
.mbtn-confirm:hover{background:rgba(239,68,68,.22)}

@keyframes pop{from{opacity:0;transform:scale(.88) translateY(4px)}to{opacity:1;transform:none}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes tdot{0%,80%,100%{transform:scale(.7);opacity:.4}40%{transform:scale(1.1);opacity:1}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.45)}50%{box-shadow:0 0 0 5px rgba(16,185,129,0)}}

@media(max-width:1024px){.thread-panel{display:none}}
@media(max-width:768px){.sidebar{display:none}}
</style>
</head>
<body>
<div class="root">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
    <div class="sb-header">
        <div class="sb-logo">
            <!-- Logo image — same as premium_client.php -->
            <div class="sb-logo-mark">
                <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="GM">
            </div>
            <div class="sb-brand-wrap">
                <div class="sb-brand">Gurkha Marga</div>
                <div class="sb-brand-sub">Staff Portal</div>
            </div>
        </div>
        <div class="sb-user-card">
            <div class="sb-av"><?= $staffInitial ?></div>
            <div>
                <div class="sb-uname"><?= htmlspecialchars($staffName) ?></div>
                <div class="sb-urole"><?= htmlspecialchars($staffRole) ?></div>
            </div>
            <div class="sb-pulse"></div>
        </div>
    </div>

    <nav class="sb-nav">
        <div class="nav-sec">Main</div>
        <a href="premium_client.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            My Clients
        </a>

        <div class="nav-sec">Communicate</div>
        <a href="premium_message.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            Messages
            <?php if ($totalUnread > 0): ?><span class="nav-badge"><?= $totalUnread ?></span><?php endif; ?>
        </a>

        <div class="nav-sec">Account</div>
        <a href="premium_setting.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="#" class="nav-item danger" onclick="showLogoutModal();return false;">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- ══ THREAD PANEL ══ -->
<div class="thread-panel">
    <div class="tp-head">
        <div class="tp-title">
            Clients
            <a href="premium_client.php">All</a>
        </div>
        <div class="tp-sub"><?= count($assignedUsers) ?> assigned · <?= $totalUnread ?> unread</div>
    </div>
    <div class="tp-search">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" placeholder="Search clients…" oninput="filterThreads(this.value)">
    </div>
    <div class="tp-list" id="threadList">
        <?php if (empty($assignedUsers)): ?>
        <div class="tp-empty">
            <div style="font-size:.82rem;font-weight:600;color:var(--text-2)">No clients yet</div>
            <div style="font-size:.72rem;margin-top:.25rem;line-height:1.6">Clients who select you as their expert will appear here.</div>
        </div>
        <?php else:
            foreach ($assignedUsers as $u):
                $isActive = (int)$u['id'] === $activeUid;
                $unread   = $unreadCounts[$u['id']] ?? 0;
                $lm       = $lastMsgs[$u['id']] ?? null;
                $preview  = '';
                if ($lm) {
                    $isMe = $lm['sender_type'] === 'consultant';
                    $txt  = match($lm['message_type'] ?? 'text') {
                        'image'=>'Photo','file'=>'File','voice'=>'Voice',
                        default=>mb_substr($lm['body']??'',0,34).(mb_strlen($lm['body']??'')>34?'…':'')
                    };
                    $preview = ($isMe ? 'You: ' : '') . $txt;
                }
        ?>
        <a href="premium_message.php?uid=<?= $u['id'] ?>"
           class="thread-item <?= $isActive?'active':'' ?>"
           data-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>">
            <div class="ti-av">
                <?= av($u['full_name']) ?>
                <div class="ti-online"></div>
            </div>
            <div class="ti-info">
                <div class="ti-name"><?= htmlspecialchars($u['full_name']) ?></div>
                <div class="ti-preview <?= $unread?'unread':'' ?>">
                    <?= $preview ?: '<span style="opacity:.45">No messages yet</span>' ?>
                </div>
            </div>
            <div class="ti-meta">
                <?php if ($lm): ?><div class="ti-time"><?= timeAgo($lm['created_at']) ?></div><?php endif; ?>
                <div id="badge-<?= $u['id'] ?>" style="display:<?= $unread?'':'none' ?>">
                    <?php if ($unread): ?><div class="ti-badge"><?= $unread ?></div><?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ══ CHAT AREA ══ -->
<div class="chat-area">
<?php if ($activeUser): ?>

    <div class="ch-hdr">
        <div class="ch-av">
            <?= av($activeUser['full_name']) ?>
            <div class="ch-av-dot"></div>
        </div>
        <div>
            <div class="ch-name"><?= htmlspecialchars($activeUser['full_name']) ?></div>
            <div class="ch-status">Active · <?= htmlspecialchars($activeUser['email']) ?></div>
        </div>
        <div class="ch-hdr-right">
            <a href="premium_client.php" class="ch-btn" title="Back to clients">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </a>
        </div>
    </div>

    <div class="msgs" id="msgs">
        <?php if (empty($messages)): ?>
        <div id="emptyHint" style="margin:auto;text-align:center;color:var(--text-3);padding:2rem">
            <div style="font-size:1.8rem;margin-bottom:.65rem;opacity:.15">&#9679;</div>
            <div style="font-size:.88rem;font-weight:700;color:var(--text-2);margin-bottom:.3rem">Start the conversation</div>
            <div style="font-size:.77rem;line-height:1.65">Send <?= htmlspecialchars($activeUser['full_name']) ?> a message to get started.</div>
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
                    <div class="m-time"><?= $timeStr ?><?= $isOut?' <span class="tick">&#10003;&#10003;</span>':'' ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($isOut): ?><button class="del-btn" onclick="showDel(<?= (int)$msg['id'] ?>)">&#x2715;</button><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>

        <div class="typing-row" id="typingRow">
            <div class="msg-av"><?= av($activeUser['full_name']) ?></div>
            <div class="m-body"><div class="bubble"><div class="t-dots"><span></span><span></span><span></span></div></div></div>
        </div>
    </div>

    <div class="input-area">
        <div class="fp-bar" id="fpBar"></div>
        <div class="inp-row">
            <textarea class="msg-ta" id="msgInput" rows="1"
                placeholder="Message <?= htmlspecialchars($activeUser['full_name']) ?>…"
                onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
            <div class="ia">
                <button class="ia-btn" title="Attach" onclick="document.getElementById('fileIn').click()">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </button>
                <input type="file" id="fileIn" style="display:none" multiple accept="image/*,.pdf,.doc,.docx,.xlsx,.txt,.zip" onchange="queueFiles(this)">
                <button class="ia-btn send" onclick="sendMsg()">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="empty-state">
        <div class="empty-ico">&#9679;</div>
        <div class="empty-title"><?= empty($assignedUsers)?'No clients assigned':'Select a conversation' ?></div>
        <div class="empty-text"><?= empty($assignedUsers)?'Premium clients who select you as their expert will appear here.':'Choose a client from the left panel.' ?></div>
    </div>
<?php endif; ?>
</div>

</div><!-- /.root -->

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLB()">
    <button class="lb-close" onclick="closeLB()">&#x2715;</button>
    <img class="lb-img" id="lbImg" src="" alt="">
</div>

<!-- Delete confirm sheet -->
<div class="del-confirm" id="delConfirm">
    <div class="del-sheet">
        <button class="ds-btn danger" onclick="doDelete()">Unsend Message</button>
        <div class="ds-sep"></div>
        <button class="ds-btn cancel" onclick="closeDel()">Cancel</button>
    </div>
</div>

<!-- Logout modal — same as premium_client.php -->
<div class="modal-overlay" id="logoutModal" onclick="if(event.target===this)hideLogoutModal()">
    <div class="modal-box">
        <div class="modal-title">Sign out</div>
        <div class="modal-body">Are you sure you want to log out of the staff portal?</div>
        <div class="modal-actions">
            <button class="mbtn mbtn-cancel" onclick="hideLogoutModal()">Cancel</button>
            <a href="../auth/logout.php" class="mbtn mbtn-confirm" style="text-decoration:none">Log out</a>
        </div>
    </div>
</div>

<script>
const STAFF_ID   = <?= $staffId ?>;
const ACTIVE_UID = <?= $activeUid ?>;
const USER_INIT  = <?= json_encode($activeUser ? av($activeUser['full_name']) : '') ?>;

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function fmtTime(iso){return new Date(iso).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function fmtBytes(b){if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB'}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,110)+'px'}
function scrollBottom(){const m=document.getElementById('msgs');if(m)m.scrollTo({top:m.scrollHeight,behavior:'smooth'})}
function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg()}}
function filterThreads(q){document.querySelectorAll('.thread-item').forEach(el=>{el.style.display=el.dataset.name?.includes(q.toLowerCase())?'':'none'})}
function openLB(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('show')}
function closeLB(){document.getElementById('lightbox').classList.remove('show')}

document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLB();closeDel();hideLogoutModal()}})

/* Logout modal */
function showLogoutModal(){document.getElementById('logoutModal').classList.add('show')}
function hideLogoutModal(){document.getElementById('logoutModal').classList.remove('show')}

/* Delete sheet */
let pendingDel = null;
function showDel(id){pendingDel=id;document.getElementById('delConfirm').classList.add('show')}
function closeDel(){document.getElementById('delConfirm').classList.remove('show');pendingDel=null}
document.getElementById('delConfirm')?.addEventListener('click',function(e){if(e.target===this)closeDel()})

async function doDelete(){
    if(!pendingDel)return;
    const id=pendingDel;closeDel();
    const row=document.querySelector(`.msg-row[data-id="${id}"]`);
    const fd=new FormData();
    fd.append('ajax_action','delete_message');fd.append('message_id',id);fd.append('user_id',ACTIVE_UID);
    try{
        const r=await fetch('premium_message.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.ok&&row){
            row.style.transition='opacity .25s,transform .2s';
            row.style.opacity='0';row.style.transform='scale(.93)';
            setTimeout(()=>row.remove(),260);
        }
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
        inner=`<a href="${msg.file_path?esc(msg.file_path):'#'}" target="_blank" class="bubble file-bubble"><div class="file-icon">&#128206;</div><div><div class="fn">${esc(msg.file_name||'File')}</div><div class="fs">${fmtBytes(msg.file_size||0)}</div></div></a>`;
    }else{
        inner=`<div class="bubble">${esc(msg.body||'').replace(/\n/g,'<br>')}</div>`;
    }
    document.getElementById('emptyHint')?.remove();
    const row=document.createElement('div');
    row.className=`msg-row ${isOut?'out':'in'}`;
    if(msg.id)row.dataset.id=msg.id;
    if(pending)row.style.opacity='.5';
    const avPart=!isOut?`<div class="msg-av">${USER_INIT}</div>`:'';
    const delPart=isOut?`<button class="del-btn" onclick="showDel(${msg.id||0})">&#x2715;</button>`:'';
    row.innerHTML=`${avPart}<div class="m-body">${inner}<div class="m-time">${time}${isOut?' <span class="tick">&#10003;&#10003;</span>':''}</div></div>${delPart}`;
    const tr=document.getElementById('typingRow');
    const msgs=document.getElementById('msgs');
    if(tr)msgs.insertBefore(row,tr);else msgs.appendChild(row);
    scrollBottom();
    return row;
}

async function sendMsg(){
    const inp=document.getElementById('msgInput');
    const text=inp.value.trim();
    for(const f of [...fileQueue]){
        const isImg=f.type.startsWith('image/');
        const row=appendBubble({sender_type:'consultant',message_type:isImg?'image':'file',_blob:isImg?URL.createObjectURL(f):null,file_name:f.name,file_size:f.size,created_at:new Date().toISOString()},true);
        const fd=new FormData();fd.append('ajax_action','send_file');fd.append('user_id',ACTIVE_UID);fd.append('file',f);
        try{
            const r=await fetch('premium_message.php',{method:'POST',body:fd});
            const d=await r.json();
            if(d.ok){row.style.opacity='1';if(d.message?.id){row.dataset.id=d.message.id;lastId=Math.max(lastId,+d.message.id)}}
            else row.style.opacity='.3';
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
        }else row.style.opacity='.3';
    }catch(e){row.style.opacity='.3'}
}

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
                    wrapper.innerHTML=cnt>0?`<div class="ti-badge">${cnt}</div>`:'';
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