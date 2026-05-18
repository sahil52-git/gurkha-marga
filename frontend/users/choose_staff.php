<?php
// frontend/users/choose_staff.php
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

// ── Bootstrap / repair tables ────────────────────────────────────────────────
// 1. Ensure admin_staff has the columns we need
foreach ([
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS accepting_clients TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS bio TEXT DEFAULT NULL",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS speciality VARCHAR(255) DEFAULT NULL",
] as $ddl) { try { query($ddl, []); } catch(Exception $e) {} }

// 2. Drop the bad FK that points consultant_id → consultants(id)
//    We do this safely: check information_schema first.
try {
    $fkCheck = fetchOne(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'user_consultants'
           AND COLUMN_NAME  = 'consultant_id'
           AND REFERENCED_TABLE_NAME IS NOT NULL
         LIMIT 1",
        []
    );
    if ($fkCheck) {
        $fkName = $fkCheck['CONSTRAINT_NAME'];
        query("ALTER TABLE user_consultants DROP FOREIGN KEY `$fkName`", []);
    }
} catch(Exception $e) {}

// 3. Drop any FK on dietitian_id too, just in case
try {
    $fkCheck2 = fetchOne(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'user_consultants'
           AND COLUMN_NAME  = 'dietitian_id'
           AND REFERENCED_TABLE_NAME IS NOT NULL
         LIMIT 1",
        []
    );
    if ($fkCheck2) {
        $fkName2 = $fkCheck2['CONSTRAINT_NAME'];
        query("ALTER TABLE user_consultants DROP FOREIGN KEY `$fkName2`", []);
    }
} catch(Exception $e) {}

// 4. Create / patch user_consultants — no FKs, plain INT columns
foreach ([
    "CREATE TABLE IF NOT EXISTS user_consultants (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL UNIQUE,
        consultant_id  INT DEFAULT NULL,
        dietitian_id   INT DEFAULT NULL,
        is_active      TINYINT(1) NOT NULL DEFAULT 1,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS consultant_id INT DEFAULT NULL",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS dietitian_id  INT DEFAULT NULL",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS is_active     TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE user_consultants ADD COLUMN IF NOT EXISTS updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    // 5. Reviews table
    "CREATE TABLE IF NOT EXISTS staff_reviews (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        staff_id   INT NOT NULL,
        user_id    INT NOT NULL,
        rating     TINYINT NOT NULL DEFAULT 5,
        review     TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_review (staff_id, user_id),
        INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // 6. chat_messages table (in case chat.php hasn't created it yet)
    "CREATE TABLE IF NOT EXISTS chat_messages (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        subscription_id INT NOT NULL,
        sender_type     ENUM('user','consultant') NOT NULL DEFAULT 'user',
        sender_id       INT NOT NULL,
        consultant_id   INT NOT NULL,
        message_type    ENUM('text','image','file','voice') NOT NULL DEFAULT 'text',
        body            TEXT DEFAULT NULL,
        file_path       VARCHAR(500) DEFAULT NULL,
        file_name       VARCHAR(255) DEFAULT NULL,
        file_size       INT DEFAULT 0,
        duration_secs   INT DEFAULT 0,
        is_read         TINYINT(1) NOT NULL DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $ddl) { try { query($ddl, []); } catch(Exception $e) {} }

// ── POST handler ─────────────────────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = trim($_POST['form_action'] ?? '');
    $staffId   = (int)($_POST['staff_id']  ?? 0);
    $staffRole = trim($_POST['staff_role'] ?? '');

    if (in_array($action, ['add','remove']) && $staffId && in_array($staffRole, ['consultant','dietitian'])) {
        $col   = $staffRole === 'dietitian' ? 'dietitian_id' : 'consultant_id';
        $staff = fetchOne("SELECT * FROM admin_staff WHERE id=? AND is_active=1 AND role=?", [$staffId, $staffRole]);
        if ($staff) {
            if ($action === 'remove') {
                execute("UPDATE user_consultants SET $col=NULL, updated_at=NOW() WHERE user_id=?", [$userId]);
                $flash = ['type'=>'info', 'msg'=>htmlspecialchars($staff['full_name']).' removed from your team.'];
            } else {
                if (!(int)($staff['accepting_clients'] ?? 1)) {
                    $flash = ['type'=>'warn', 'msg'=>htmlspecialchars($staff['full_name']).' is not accepting new clients.'];
                } else {
                    $row = fetchOne("SELECT id FROM user_consultants WHERE user_id=?", [$userId]);
                    if ($row) {
                        execute("UPDATE user_consultants SET $col=?, is_active=1, updated_at=NOW() WHERE user_id=?", [$staffId, $userId]);
                    } else {
                        execute("INSERT INTO user_consultants (user_id, $col, is_active) VALUES (?,?,1)", [$userId, $staffId]);
                    }
                    $flash = ['type'=>'success', 'msg'=>htmlspecialchars($staff['full_name']).' added to your team! You can now chat with them.'];
                }
            }
        }
    }

    if ($action === 'review' && $staffId) {
        $rating = min(5, max(1, (int)($_POST['rating'] ?? 5)));
        $review = trim($_POST['review'] ?? '');
        $myRow  = fetchOne("SELECT * FROM user_consultants WHERE user_id=?", [$userId]);
        $canRev = $myRow && ((int)($myRow['consultant_id']??0)===$staffId||(int)($myRow['dietitian_id']??0)===$staffId);
        if ($canRev && $review) {
            execute("INSERT INTO staff_reviews (staff_id,user_id,rating,review) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE rating=VALUES(rating),review=VALUES(review),updated_at=NOW()",
                    [$staffId, $userId, $rating, $review]);
            $flash = ['type'=>'success', 'msg'=>'Review saved.'];
        } elseif (!$canRev) {
            $flash = ['type'=>'warn', 'msg'=>'Select this expert first before leaving a review.'];
        }
    }

    header('Location: choose_staff.php?ft='.urlencode($flash['type']??'').'&fm='.urlencode($flash['msg']??''));
    exit();
}
if (!empty($_GET['ft'])) {
    $flash = ['type'=>$_GET['ft'], 'msg'=>htmlspecialchars(urldecode($_GET['fm']??''))];
}

// ── Data ─────────────────────────────────────────────────────────────────────
$allStaff = fetchAll(
    "SELECT s.*,
        COALESCE((SELECT ROUND(AVG(r.rating),1) FROM staff_reviews r WHERE r.staff_id=s.id),0) AS avg_rating,
        COALESCE((SELECT COUNT(*) FROM staff_reviews r WHERE r.staff_id=s.id),0) AS review_count,
        (SELECT COUNT(*) FROM user_consultants uc2
         WHERE (s.role='consultant' AND uc2.consultant_id=s.id)
            OR (s.role='dietitian'  AND uc2.dietitian_id=s.id)) AS client_count
     FROM admin_staff s
     WHERE s.is_active=1 AND s.role IN('consultant','dietitian')
     ORDER BY s.role ASC, avg_rating DESC, s.full_name ASC"
) ?: [];

$myRow    = fetchOne("SELECT * FROM user_consultants WHERE user_id=?", [$userId]);
$myConsId = (int)($myRow['consultant_id'] ?? 0);
$myDietId = (int)($myRow['dietitian_id']  ?? 0);

$myReviews = [];
foreach (fetchAll("SELECT * FROM staff_reviews WHERE user_id=?", [$userId]) ?: [] as $r) {
    $myReviews[(int)$r['staff_id']] = $r;
}

$staffReviews = [];
foreach ($allStaff as $s) {
    $staffReviews[(int)$s['id']] = fetchAll(
        "SELECT r.*, u.full_name AS uname FROM staff_reviews r
         JOIN users u ON u.id=r.user_id
         WHERE r.staff_id=? ORDER BY r.created_at DESC LIMIT 5",
        [(int)$s['id']]
    ) ?: [];
}

$consultants = array_values(array_filter($allStaff, fn($s)=>$s['role']==='consultant'));
$dietitians  = array_values(array_filter($allStaff, fn($s)=>$s['role']==='dietitian'));

$myConsultant = $myConsId ? fetchOne("SELECT * FROM admin_staff WHERE id=?", [$myConsId]) : null;
$myDietitian  = $myDietId ? fetchOne("SELECT * FROM admin_staff WHERE id=?", [$myDietId])  : null;

$daysLeft = daysRemaining($userId);
$initials = strtoupper(substr($user['full_name'], 0, 1));
$expKey   = strtolower($user['experience_level'] ?? 'beginner');
$forceKey = strtolower($user['target_force'] ?? 'british');
$forceMap = ['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police Force','french'=>'French Foreign Legion'];
$forceName = $forceMap[$forceKey] ?? ucfirst($forceKey);

$avatarType       = $user['avatar_type']   ?? 'initial';
$profilePhoto     = $user['profile_photo'] ?? null;
$avatarConfig     = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl         = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

function starHtml(float $r): string {
    $s = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($r >= $i)        $s .= '<span class="s-on">★</span>';
        elseif ($r >= $i-.5) $s .= '<span class="s-half">★</span>';
        else                  $s .= '<span class="s-off">☆</span>';
    }
    return $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expert Team — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --accent:  #3b82f6;
    --gold:    #fbbf24;
    --green:   #10b981;
    --red:     #ef4444;
    --t1:      #f8fafc;
    --t2:      #cbd5e1;
    --t3:      #64748b;
    --sidebar: rgba(15,23,42,.97);
    --card:    rgba(30,41,59,.8);
    --hover:   rgba(59,130,246,.08);
    --border:  rgba(255,255,255,.07);
    --bhi:     rgba(255,255,255,.12);
}
body { font-family:'Poppins',sans-serif; background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%); color:var(--t1); min-height:100vh; }
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}

.sidebar {
    position:fixed; left:0; top:0; width:260px; height:100vh;
    background:var(--sidebar); backdrop-filter:blur(20px);
    border-right:1px solid var(--border); padding:1.75rem 0;
    overflow-y:auto; z-index:1000; transition:transform .3s ease;
}
.sb-head { padding:0 1.25rem 1.25rem; border-bottom:1px solid var(--border); }
.logo { display:flex; align-items:center; gap:10px; margin-bottom:1.1rem; }
.logo img { width:34px; height:34px; object-fit:contain; }
.brand { font-size:1.25rem; font-weight:800; background:linear-gradient(135deg,var(--gold),#f59e0b); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.user-card { background:rgba(59,130,246,.07); border:1px solid rgba(59,130,246,.18); border-radius:12px; padding:.85rem; display:flex; align-items:center; gap:10px; }
.sb-av { width:38px; height:38px; border-radius:50%; overflow:hidden; flex-shrink:0; border:1.5px solid rgba(59,130,246,.35); display:flex; align-items:center; justify-content:center; }
.user-card h3 { font-size:.85rem; font-weight:600; }
.user-card p  { font-size:.7rem; color:var(--gold); margin-top:1px; }
.nav-menu { padding:1.25rem 0; }
.nav-sec { font-size:.65rem; font-weight:600; letter-spacing:.1em; color:rgba(203,213,225,.35); text-transform:uppercase; padding:.4rem 1.25rem; margin-bottom:.2rem; }
.nav-item { padding:.6rem 1.25rem; display:flex; align-items:center; gap:11px; color:var(--t2); text-decoration:none; transition:all .2s; border-left:2.5px solid transparent; font-size:.85rem; }
.nav-item svg { width:16px; height:16px; flex-shrink:0; }
.nav-item:hover,.nav-item.active { background:var(--hover); color:var(--t1); border-left-color:var(--accent); }
.nav-item.logout { color:#f87171; }
.nav-item.logout:hover { background:rgba(239,68,68,.08); border-left-color:var(--red); }
.sub-chip { margin-left:auto; font-size:.65rem; padding:.15rem .45rem; border-radius:99px; background:rgba(251,191,36,.12); color:var(--gold); font-weight:600; border:1px solid rgba(251,191,36,.2); white-space:nowrap; }

.main { margin-left:260px; padding:1.75rem; min-height:100vh; }

.topbar { background:var(--card); backdrop-filter:blur(20px); border-radius:14px; padding:1.25rem 1.75rem; margin-bottom:1.75rem; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border); flex-wrap:wrap; gap:.75rem; }
.topbar h1 { font-size:1.3rem; font-weight:700; }
.topbar p  { font-size:.75rem; color:var(--t3); margin-top:.2rem; }
.btn { display:inline-flex; align-items:center; gap:.45rem; padding:.55rem 1.1rem; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; font-size:.82rem; text-decoration:none; transition:all .2s; white-space:nowrap; }
.btn-primary { background:linear-gradient(135deg,var(--accent),#8b5cf6); color:#fff; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(59,130,246,.35); }
.btn-outline { background:transparent; border:1px solid var(--bhi); color:var(--t2); }
.btn-outline:hover { background:rgba(255,255,255,.06); color:var(--t1); }
.tb-right { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }

.flash { display:flex; align-items:center; gap:.6rem; padding:.8rem 1.1rem; border-radius:10px; font-size:.82rem; margin-bottom:1.5rem; animation:fadeIn .3s ease; }
.flash-success { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.2); color:#6ee7b7; }
.flash-info    { background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.2); color:#93c5fd; }
.flash-warn    { background:rgba(251,191,36,.1); border:1px solid rgba(251,191,36,.2); color:var(--gold); }
.flash a { color:inherit; font-weight:700; margin-left:.4rem; text-decoration:none; }

.team-strip { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:.9rem 1.5rem; margin-bottom:1.75rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.team-lbl { font-size:.65rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); flex-shrink:0; }
.team-slots { display:flex; gap:.6rem; flex:1; flex-wrap:wrap; }
.t-slot { display:flex; align-items:center; gap:.55rem; padding:.32rem .85rem .32rem .38rem; border-radius:99px; font-size:.78rem; border:1px dashed rgba(255,255,255,.1); color:var(--t3); background:rgba(255,255,255,.03); }
.t-slot.filled { border-style:solid; border-color:var(--bhi); color:var(--t1); }
.t-av { width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.6rem; font-weight:800; }
.t-av.c { background:linear-gradient(135deg,#0891b2,#06b6d4); color:#fff; }
.t-av.d { background:linear-gradient(135deg,#d97706,#f59e0b); color:#0a0f1e; }
.t-av.e { background:rgba(255,255,255,.08); color:var(--t3); }
.t-sub { font-size:.6rem; color:var(--t3); }

.sec-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
.sec-title { font-size:.65rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:.6rem; }
.sec-title::after { content:''; width:40px; height:1px; background:var(--border); display:block; }
.sec-badge { font-size:.68rem; color:var(--t3); background:rgba(255,255,255,.05); border:1px solid var(--border); padding:.15rem .55rem; border-radius:5px; }

.staff-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:.85rem; margin-bottom:2rem; }

.sc { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:border-color .2s, transform .18s; animation:fadeIn .35s ease both; position:relative; }
.sc:hover { border-color:var(--bhi); transform:translateY(-2px); }
.sc.mine { border-color:rgba(16,185,129,.28); background:rgba(16,185,129,.04); }
.sc.noaccept { opacity:.55; pointer-events:none; }
.sc::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,#0891b2 0%,transparent 100%); }
.sc.diet-card::before { background:linear-gradient(90deg,#d97706 0%,transparent 100%); }
.sc.mine::before { background:linear-gradient(90deg,var(--green) 0%,transparent 100%); }

.sc-top { display:flex; align-items:center; gap:.85rem; padding:1.1rem 1.1rem .75rem; }
.sc-av { width:46px; height:46px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.15rem; font-weight:800; color:#fff; }
.sc-av.c { background:linear-gradient(135deg,#0891b2,#06b6d4); }
.sc-av.d { background:linear-gradient(135deg,#d97706,#f59e0b); color:#0a0f1e; }
.sc-name { font-size:.9rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sc-id { font-size:.65rem; color:var(--t3); margin-top:2px; font-family:monospace; }
.sc-role { font-size:.6rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin-top:3px; }
.sc-role.c { color:#22d3ee; }
.sc-role.d { color:var(--gold); }
.mine-tick { margin-left:auto; font-size:.65rem; font-weight:700; padding:.15rem .5rem; border-radius:4px; background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.2); color:#6ee7b7; flex-shrink:0; }

.sc-stats { display:flex; border-top:1px solid var(--border); border-bottom:1px solid var(--border); }
.sc-stat { flex:1; padding:.5rem; text-align:center; border-right:1px solid var(--border); }
.sc-stat:last-child { border-right:none; }
.sc-stat-v { font-size:.9rem; font-weight:700; line-height:1; }
.sc-stat-l { font-size:.58rem; color:var(--t3); margin-top:2px; text-transform:uppercase; letter-spacing:.05em; }

.sc-stars { display:flex; align-items:center; gap:.3rem; padding:.6rem 1.1rem; }
.s-on   { color:var(--gold); font-size:.85rem; }
.s-half { color:var(--gold); font-size:.85rem; opacity:.55; }
.s-off  { color:rgba(255,255,255,.12); font-size:.85rem; }
.rating-n { font-size:.75rem; font-weight:700; color:var(--t2); }
.rating-c { font-size:.65rem; color:var(--t3); }
.online-dot { margin-left:auto; width:7px; height:7px; border-radius:50%; background:var(--green); flex-shrink:0; }

.sc-actions { display:flex; gap:.45rem; padding:.75rem 1.1rem; }
.act-primary { flex:1; padding:.5rem .65rem; border-radius:7px; font-family:'Poppins',sans-serif; font-size:.78rem; font-weight:600; border:none; cursor:pointer; transition:all .18s; text-align:center; display:flex; align-items:center; justify-content:center; gap:.35rem; }
.act-blue   { background:rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.25); }
.act-blue:hover { background:rgba(59,130,246,.25); }
.act-green  { background:rgba(16,185,129,.12); color:#6ee7b7; border:1px solid rgba(16,185,129,.22); cursor:default; }
.act-muted  { background:rgba(255,255,255,.04); color:var(--t3); border:1px solid var(--border); cursor:not-allowed; }
.act-icon { width:34px; height:34px; border-radius:7px; flex-shrink:0; border:1px solid var(--border); background:rgba(255,255,255,.04); color:var(--t3); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.82rem; transition:all .18s; font-family:'Poppins',sans-serif; text-decoration:none; }
.act-icon:hover { border-color:var(--bhi); color:var(--t1); }
.act-icon.rm { background:rgba(239,68,68,.08); border-color:rgba(239,68,68,.2); color:#fca5a5; }
.act-icon.rm:hover { background:rgba(239,68,68,.16); }
.act-icon.chat-link { background:rgba(59,130,246,.08); border-color:rgba(59,130,246,.2); color:#93c5fd; }
.act-icon.chat-link:hover { background:rgba(59,130,246,.2); }

.rev-panel { display:none; border-top:1px solid var(--border); padding:.9rem 1.1rem; background:rgba(0,0,0,.18); }
.rev-panel.open { display:block; animation:fadeIn .2s ease; }
.rev-item { padding:.55rem 0; border-bottom:1px solid rgba(255,255,255,.04); }
.rev-item:last-child { border-bottom:none; }
.rev-meta { display:flex; align-items:center; gap:.45rem; margin-bottom:.2rem; flex-wrap:wrap; }
.rev-author { font-size:.75rem; font-weight:600; }
.rev-date { font-size:.63rem; color:var(--t3); margin-left:auto; }
.rev-text { font-size:.77rem; color:var(--t3); line-height:1.6; }
.no-rev { font-size:.75rem; color:var(--t3); padding:.25rem 0; }

.rev-form { margin-top:.8rem; padding-top:.8rem; border-top:1px solid var(--border); }
.rf-lbl { font-size:.63rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--t3); margin-bottom:.4rem; }
.star-pick { display:flex; gap:.2rem; margin-bottom:.55rem; }
.star-pick span { font-size:1.25rem; cursor:pointer; color:rgba(255,255,255,.12); transition:color .12s; }
.star-pick span.on { color:var(--gold); }
.rf-ta { width:100%; background:rgba(255,255,255,.05); border:1px solid var(--border); border-radius:8px; color:var(--t1); font-family:'Poppins',sans-serif; font-size:.8rem; padding:.55rem .8rem; resize:none; outline:none; transition:border-color .2s; min-height:65px; }
.rf-ta:focus { border-color:rgba(59,130,246,.4); }
.rf-sub { margin-top:.45rem; padding:.42rem 1rem; background:linear-gradient(135deg,var(--accent),#1d4ed8); color:#fff; border:none; border-radius:7px; font-family:'Poppins',sans-serif; font-size:.78rem; font-weight:600; cursor:pointer; transition:opacity .18s; }
.rf-sub:hover { opacity:.85; }
.rev-locked { font-size:.72rem; color:var(--t3); font-style:italic; padding:.2rem 0; }

.div-row { display:flex; align-items:center; gap:.65rem; margin:1rem 0 1.75rem; }
.div-row::before,.div-row::after { content:''; flex:1; height:1px; background:var(--border); }
.div-lbl { font-size:.62rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); white-space:nowrap; }

.empty-box { text-align:center; padding:2.5rem 1rem; color:var(--t3); }
.empty-box span { font-size:2rem; display:block; margin-bottom:.5rem; opacity:.3; }
.empty-box p { font-size:.82rem; color:var(--t2); }

.mobile-btn { display:none; position:fixed; bottom:1.25rem; right:1.25rem; width:50px; height:50px; border-radius:50%; background:linear-gradient(135deg,var(--accent),#8b5cf6); border:none; color:#fff; font-size:1.2rem; cursor:pointer; box-shadow:0 4px 15px rgba(59,130,246,.4); z-index:999; }

@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

@media(max-width:1024px) { .sidebar{display:none} }
@media(max-width:768px)  { .main{margin-left:0;padding:1rem} .mobile-btn{display:flex;align-items:center;justify-content:center} }
@media(max-width:560px)  { .staff-grid{grid-template-columns:1fr} }
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sb-head">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Logo" onerror="this.style.display='none'">
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
        <a href="nutrition.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
            Nutrition
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
        </a>
        <a href="chat.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            Chat
        </a>
        <div class="nav-sec" style="margin-top:.75rem">Account</div>
        <a href="choose_staff.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            My Expert Team
        </a>
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

<main class="main">

    <div class="topbar">
        <div>
            <h1>Expert Team</h1>
            <p>Pick your consultant and dietitian · Rate after consultation</p>
        </div>
        <div class="tb-right">
            <span style="font-size:.75rem;color:var(--gold);padding:.35rem .7rem;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;font-weight:600;">⭐ <?= $daysLeft ?>d left</span>
            <?php if ($myConsultant || $myDietitian): ?>
            <a href="chat.php" class="btn btn-primary">💬 Chat with Team</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-outline">← Dashboard</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
        <?= $flash['type']==='success'?'✓':($flash['type']==='warn'?'⚠':'ℹ') ?>
        <?= $flash['msg'] ?>
        <?php if ($flash['type']==='success' && (str_contains($flash['msg'],'added') || str_contains($flash['msg'],'team'))): ?>
        <a href="chat.php">Open Chat →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="team-strip">
        <span class="team-lbl">Your Team</span>
        <div class="team-slots">
            <div class="t-slot <?= $myConsultant?'filled':'' ?>">
                <?php if ($myConsultant): ?>
                <div class="t-av c"><?= strtoupper(substr($myConsultant['full_name'],0,1)) ?></div>
                <div>
                    <div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($myConsultant['full_name']) ?></div>
                    <div class="t-sub">Consultant · #<?= $myConsultant['id'] ?></div>
                </div>
                <?php else: ?>
                <div class="t-av e">?</div><span>No consultant</span>
                <?php endif; ?>
            </div>
            <div class="t-slot <?= $myDietitian?'filled':'' ?>">
                <?php if ($myDietitian): ?>
                <div class="t-av d"><?= strtoupper(substr($myDietitian['full_name'],0,1)) ?></div>
                <div>
                    <div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($myDietitian['full_name']) ?></div>
                    <div class="t-sub">Dietitian · #<?= $myDietitian['id'] ?></div>
                </div>
                <?php else: ?>
                <div class="t-av e">?</div><span>No dietitian</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($myConsultant || $myDietitian): ?>
        <a href="chat.php" class="btn btn-primary" style="margin-left:auto;flex-shrink:0">💬 Open Chat</a>
        <?php endif; ?>
    </div>

    <!-- CONSULTANTS -->
    <div class="sec-row">
        <div class="sec-title">Fitness Consultants</div>
        <span class="sec-badge"><?= count($consultants) ?> available</span>
    </div>

    <?php if (empty($consultants)): ?>
    <div class="empty-box"><span>🏋️</span><p>No consultants listed yet.</p></div>
    <?php else: ?>
    <div class="staff-grid">
    <?php foreach ($consultants as $i => $s):
        $isMine    = ($s['id'] == $myConsId);
        $accepting = (int)($s['accepting_clients'] ?? 1);
        $avg       = (float)$s['avg_rating'];
        $revCnt    = (int)$s['review_count'];
        $clients   = (int)$s['client_count'];
        $myRev     = $myReviews[(int)$s['id']] ?? null;
        $reviews   = $staffReviews[(int)$s['id']] ?? [];
    ?>
    <div class="sc <?= $isMine?'mine':'' ?> <?= !$accepting?'noaccept':'' ?>" style="animation-delay:<?= $i*.05 ?>s">
        <div class="sc-top">
            <div class="sc-av c"><?= strtoupper(substr($s['full_name'],0,1)) ?></div>
            <div style="flex:1;min-width:0">
                <div class="sc-name"><?= htmlspecialchars($s['full_name']) ?></div>
                <div class="sc-id">#<?= $s['id'] ?></div>
                <div class="sc-role c">Consultant</div>
            </div>
            <?php if ($isMine): ?><span class="mine-tick">✓ Yours</span><?php endif; ?>
        </div>
        <div class="sc-stats">
            <div class="sc-stat"><div class="sc-stat-v"><?= $avg > 0 ? $avg : '—' ?></div><div class="sc-stat-l">Rating</div></div>
            <div class="sc-stat"><div class="sc-stat-v"><?= $revCnt ?></div><div class="sc-stat-l">Reviews</div></div>
            <div class="sc-stat"><div class="sc-stat-v"><?= $clients ?></div><div class="sc-stat-l">Clients</div></div>
        </div>
        <div class="sc-stars">
            <?= starHtml($avg) ?>
            <span class="rating-n"><?= $avg > 0 ? $avg : '0.0' ?></span>
            <span class="rating-c">(<?= $revCnt ?>)</span>
            <?php if ($accepting): ?><span class="online-dot"></span><?php endif; ?>
        </div>
        <div class="sc-actions">
            <?php if ($isMine): ?>
                <span class="act-primary act-green">✓ Selected</span>
                <a href="chat.php?staff_id=<?= $s['id'] ?>" class="act-icon chat-link" title="Chat">💬</a>
                <form method="POST" style="display:contents">
                    <input type="hidden" name="form_action" value="remove">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="staff_role"  value="consultant">
                    <button type="submit" class="act-icon rm" title="Remove">✕</button>
                </form>
            <?php elseif (!$accepting): ?>
                <span class="act-primary act-muted">Unavailable</span>
            <?php else: ?>
                <form method="POST" style="display:contents">
                    <input type="hidden" name="form_action" value="add">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="staff_role"  value="consultant">
                    <button type="submit" class="act-primary act-blue">+ Select</button>
                </form>
            <?php endif; ?>
            <button class="act-icon" onclick="toggleRev(<?= $s['id'] ?>)" title="Reviews">☆ <?= $revCnt ?></button>
        </div>
        <div class="rev-panel" id="rp-<?= $s['id'] ?>">
            <?php if (empty($reviews)): ?><div class="no-rev">No reviews yet.</div>
            <?php else: foreach ($reviews as $rv): ?>
            <div class="rev-item">
                <div class="rev-meta"><span class="rev-author"><?= htmlspecialchars($rv['uname']) ?></span><?= starHtml((float)$rv['rating']) ?><span class="rev-date"><?= date('M j, Y', strtotime($rv['created_at'])) ?></span></div>
                <div class="rev-text"><?= htmlspecialchars($rv['review']) ?></div>
            </div>
            <?php endforeach; endif; ?>
            <?php if ($isMine): ?>
            <div class="rev-form">
                <div class="rf-lbl"><?= $myRev ? 'Edit review' : 'Write a review' ?></div>
                <form method="POST">
                    <input type="hidden" name="form_action" value="review">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="rating" id="rt-<?= $s['id'] ?>" value="<?= $myRev?(int)$myRev['rating']:5 ?>">
                    <div class="star-pick" id="sp-<?= $s['id'] ?>"><?php for($j=1;$j<=5;$j++): ?><span class="<?= $myRev&&(int)$myRev['rating']>=$j?'on':'' ?>" onclick="setRat(<?= $s['id'] ?>,<?= $j ?>)">★</span><?php endfor; ?></div>
                    <textarea name="review" class="rf-ta" rows="2" placeholder="Your experience…"><?= $myRev?htmlspecialchars($myRev['review']):'' ?></textarea>
                    <button type="submit" class="rf-sub"><?= $myRev?'Update':'Submit' ?></button>
                </form>
            </div>
            <?php else: ?><div class="rev-locked">Select this expert to leave a review.</div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="div-row"><span class="div-lbl">Dietitians &amp; Nutritionists</span></div>

    <!-- DIETITIANS -->
    <div class="sec-row">
        <div class="sec-title">Registered Dietitians</div>
        <span class="sec-badge"><?= count($dietitians) ?> available</span>
    </div>

    <?php if (empty($dietitians)): ?>
    <div class="empty-box"><span>🥗</span><p>No dietitians listed yet.</p></div>
    <?php else: ?>
    <div class="staff-grid">
    <?php foreach ($dietitians as $i => $s):
        $isMine    = ($s['id'] == $myDietId);
        $accepting = (int)($s['accepting_clients'] ?? 1);
        $avg       = (float)$s['avg_rating'];
        $revCnt    = (int)$s['review_count'];
        $clients   = (int)$s['client_count'];
        $myRev     = $myReviews[(int)$s['id']] ?? null;
        $reviews   = $staffReviews[(int)$s['id']] ?? [];
    ?>
    <div class="sc diet-card <?= $isMine?'mine':'' ?> <?= !$accepting?'noaccept':'' ?>" style="animation-delay:<?= $i*.05 ?>s">
        <div class="sc-top">
            <div class="sc-av d"><?= strtoupper(substr($s['full_name'],0,1)) ?></div>
            <div style="flex:1;min-width:0">
                <div class="sc-name"><?= htmlspecialchars($s['full_name']) ?></div>
                <div class="sc-id">#<?= $s['id'] ?></div>
                <div class="sc-role d">Dietitian</div>
            </div>
            <?php if ($isMine): ?><span class="mine-tick">✓ Yours</span><?php endif; ?>
        </div>
        <div class="sc-stats">
            <div class="sc-stat"><div class="sc-stat-v"><?= $avg > 0 ? $avg : '—' ?></div><div class="sc-stat-l">Rating</div></div>
            <div class="sc-stat"><div class="sc-stat-v"><?= $revCnt ?></div><div class="sc-stat-l">Reviews</div></div>
            <div class="sc-stat"><div class="sc-stat-v"><?= $clients ?></div><div class="sc-stat-l">Clients</div></div>
        </div>
        <div class="sc-stars">
            <?= starHtml($avg) ?>
            <span class="rating-n"><?= $avg > 0 ? $avg : '0.0' ?></span>
            <span class="rating-c">(<?= $revCnt ?>)</span>
            <?php if ($accepting): ?><span class="online-dot"></span><?php endif; ?>
        </div>
        <div class="sc-actions">
            <?php if ($isMine): ?>
                <span class="act-primary act-green">✓ Selected</span>
                <a href="chat.php?staff_id=<?= $s['id'] ?>" class="act-icon chat-link" title="Chat">💬</a>
                <form method="POST" style="display:contents">
                    <input type="hidden" name="form_action" value="remove">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="staff_role"  value="dietitian">
                    <button type="submit" class="act-icon rm" title="Remove">✕</button>
                </form>
            <?php elseif (!$accepting): ?>
                <span class="act-primary act-muted">Unavailable</span>
            <?php else: ?>
                <form method="POST" style="display:contents">
                    <input type="hidden" name="form_action" value="add">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="staff_role"  value="dietitian">
                    <button type="submit" class="act-primary act-blue">+ Select</button>
                </form>
            <?php endif; ?>
            <button class="act-icon" onclick="toggleRev(<?= $s['id'] ?>)" title="Reviews">☆ <?= $revCnt ?></button>
        </div>
        <div class="rev-panel" id="rp-<?= $s['id'] ?>">
            <?php if (empty($reviews)): ?><div class="no-rev">No reviews yet.</div>
            <?php else: foreach ($reviews as $rv): ?>
            <div class="rev-item">
                <div class="rev-meta"><span class="rev-author"><?= htmlspecialchars($rv['uname']) ?></span><?= starHtml((float)$rv['rating']) ?><span class="rev-date"><?= date('M j, Y', strtotime($rv['created_at'])) ?></span></div>
                <div class="rev-text"><?= htmlspecialchars($rv['review']) ?></div>
            </div>
            <?php endforeach; endif; ?>
            <?php if ($isMine): ?>
            <div class="rev-form">
                <div class="rf-lbl"><?= $myRev ? 'Edit review' : 'Write a review' ?></div>
                <form method="POST">
                    <input type="hidden" name="form_action" value="review">
                    <input type="hidden" name="staff_id"   value="<?= $s['id'] ?>">
                    <input type="hidden" name="rating" id="rt-<?= $s['id'] ?>" value="<?= $myRev?(int)$myRev['rating']:5 ?>">
                    <div class="star-pick" id="sp-<?= $s['id'] ?>"><?php for($j=1;$j<=5;$j++): ?><span class="<?= $myRev&&(int)$myRev['rating']>=$j?'on':'' ?>" onclick="setRat(<?= $s['id'] ?>,<?= $j ?>)">★</span><?php endfor; ?></div>
                    <textarea name="review" class="rf-ta" rows="2" placeholder="Your experience…"><?= $myRev?htmlspecialchars($myRev['review']):'' ?></textarea>
                    <button type="submit" class="rf-sub"><?= $myRev?'Update':'Submit' ?></button>
                </form>
            </div>
            <?php else: ?><div class="rev-locked">Select this expert to leave a review.</div><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<button class="mobile-btn" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

const AV_OPTIONS = {
    bg:[{id:'grad1',grad:['#1e3a5f','#2563eb']},{id:'grad2',grad:['#14532d','#16a34a']},{id:'grad3',grad:['#7f1d1d','#dc2626']},{id:'grad4',grad:['#312e81','#7c3aed']},{id:'grad5',grad:['#78350f','#d97706']},{id:'grad6',grad:['#134e4a','#0d9488']},{id:'grad7',grad:['#0f172a','#1e293b']},{id:'grad8',grad:['#4c0519','#e11d48']}],
    skin:[{id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},{id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},{id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'}],
    hairColor:[{id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},{id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},{id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},{id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'}],
    eyes:[{id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},{id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},{id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'}],
};
function drawAvatarOnCanvas(canvas,state,size){
    const ctx=canvas.getContext('2d');canvas.width=size;canvas.height=size;
    const cx=size/2,cy=size/2;
    const bg=AV_OPTIONS.bg.find(o=>o.id===state.bg)||AV_OPTIONS.bg[0];
    const skin=AV_OPTIONS.skin.find(o=>o.id===state.skin)||AV_OPTIONS.skin[1];
    const hCol=AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor)||AV_OPTIONS.hairColor[1];
    const eyeC=AV_OPTIONS.eyes.find(o=>o.id===state.eyes)||AV_OPTIONS.eyes[0];
    const grad=ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0,bg.grad[0]);grad.addColorStop(1,bg.grad[1]);
    ctx.fillStyle=grad;ctx.beginPath();ctx.arc(cx,cy,cx,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=skin.color;ctx.beginPath();ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]);ctx.fill();
    const shirt=ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f');shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle=shirt;ctx.beginPath();ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath();ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2);ctx.fill();
    ctx.strokeStyle=hCol.color;ctx.lineWidth=3.5;ctx.lineCap='round';
    ctx.beginPath();ctx.moveTo(cx-28,cy-16);ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16);ctx.stroke();
    ctx.beginPath();ctx.moveTo(cx+7,cy-16);ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16);ctx.stroke();
    ctx.fillStyle='#fff';
    ctx.beginPath();ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=eyeC.color;
    ctx.beginPath();ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='#000';
    ctx.beginPath();ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2);ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.6)';
    ctx.beginPath();ctx.arc(cx-13,cy-7,2.2,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(cx+19,cy-7,2.2,0,Math.PI*2);ctx.fill();
    ctx.strokeStyle='rgba(0,0,0,0.18)';ctx.lineWidth=2;ctx.lineCap='round';
    ctx.beginPath();ctx.moveTo(cx-3,cy+2);ctx.lineTo(cx,cy+12);ctx.lineTo(cx+3,cy+2);ctx.stroke();
    ctx.beginPath();ctx.moveTo(cx-9,cy+14);ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14);ctx.stroke();
    ctx.strokeStyle='rgba(0,0,0,0.3)';ctx.lineWidth=2.5;
    ctx.beginPath();ctx.moveTo(cx-14,cy+24);ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24);ctx.stroke();
    ctx.fillStyle=hCol.color;
    ctx.beginPath();ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0);ctx.fill();
    ctx.fillRect(cx-44,cy-40,88,20);
    ctx.beginPath();ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2);ctx.fill();
    ctx.beginPath();ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true);ctx.fill();
}
function renderSidebarAvatar(){
    const c=document.getElementById('sbAvContainer');if(!c)return;c.innerHTML='';
    if(SAVED_AVATAR_TYPE==='photo'&&SAVED_PHOTO_URL){
        const img=document.createElement('img');img.src=SAVED_PHOTO_URL;
        img.style.cssText='width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror=()=>renderInitial(c);c.appendChild(img);
    } else if(SAVED_AVATAR_TYPE==='ai'&&SAVED_AVATAR_CONFIG){
        const cv=document.createElement('canvas');
        cv.style.cssText='width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(cv);drawAvatarOnCanvas(cv,SAVED_AVATAR_CONFIG,38);
    } else { renderInitial(c); }
}
function renderInitial(c){
    const d=document.createElement('div');
    d.style.cssText='width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent=USER_INITIAL;c.appendChild(d);
}
function toggleRev(id){const p=document.getElementById('rp-'+id);if(p)p.classList.toggle('open');}
function setRat(staffId,val){
    document.getElementById('rt-'+staffId).value=val;
    document.getElementById('sp-'+staffId).querySelectorAll('span').forEach((s,i)=>s.classList.toggle('on',i<val));
}
document.addEventListener('DOMContentLoaded',renderSidebarAvatar);
</script>
</body>
</html>