<?php
// ════════════════════════════════════════════════════════════════════════════
//  staff_dashboard.php  —  Gurkha Marga  |  Dietitian & Consultant Portal
//  Self-contained: auth + routing + rendering in one file.
//  Matches admin_dashboard.php aesthetic exactly.
// ════════════════════════════════════════════════════════════════════════════
session_start();

define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

// ── Bootstrap tables ─────────────────────────────────────────────────────────
$bootstrapSQL = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_premium    TINYINT(1)  NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS premium_since DATETIME    DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS staff_notes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL, staff_id INT NOT NULL,
        note_type   ENUM('general','dietary','medical','workout','followup') NOT NULL DEFAULT 'general',
        content     TEXT NOT NULL, is_private TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id), INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS meal_plans (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, staff_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        goal ENUM('weight_loss','muscle_gain','maintenance','endurance','custom') DEFAULT 'maintenance',
        calories INT DEFAULT 0, protein_g INT DEFAULT 0, carbs_g INT DEFAULT 0, fat_g INT DEFAULT 0,
        plan_text TEXT, week_number INT DEFAULT 1, is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS consultations (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, staff_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        type ENUM('initial','followup','assessment','emergency') DEFAULT 'initial',
        status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
        scheduled_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL,
        summary TEXT, recommendations TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id), INDEX idx_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS staff_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_staff INT DEFAULT NULL, from_user INT DEFAULT NULL,
        to_staff INT DEFAULT NULL, to_user INT DEFAULT NULL,
        subject VARCHAR(200), body TEXT NOT NULL, is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_to_staff (to_staff), INDEX idx_to_user (to_user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($bootstrapSQL as $sql) { try { query($sql, []); } catch(Exception $e) {} }

// ── Auth ──────────────────────────────────────────────────────────────────────
$isLoggedIn = !empty($_SESSION['staff_logged_in']);
$staffId    = (int)($_SESSION['staff_id']    ?? 0);
$staffName  = $_SESSION['staff_name']  ?? '';
$staffRole  = $_SESSION['staff_role']  ?? 'dietitian'; // default prevents undefined var
$staffEmail = $_SESSION['staff_email'] ?? '';

$page   = $_GET['page']   ?? 'home';
$action = $_GET['action'] ?? 'list';
$uid    = (int)($_GET['uid']  ?? 0);
$rid    = (int)($_GET['rid']  ?? 0);
$flash  = ['type' => '', 'msg' => ''];

if ($page === 'logout') { session_unset(); session_destroy(); header('Location: ../auth/login.php'); exit(); }
if (!$isLoggedIn) { header('Location: ../auth/login.php'); exit(); }

$isDietitian  = in_array($staffRole, ['dietitian', 'superadmin', 'admin']);
$isConsultant = in_array($staffRole, ['consultant', 'superadmin', 'admin']);

// ── Accent by role ────────────────────────────────────────────────────────────
$accentMap = [
    'dietitian'  => ['hex' => '#06b6d4', 'rgb' => '6,182,212',   'icon' => '🥗', 'label' => 'Dietitian',  'desc' => 'Nutrition & Diet Plans'],
    'consultant' => ['hex' => '#8b5cf6', 'rgb' => '139,92,246',  'icon' => '💬', 'label' => 'Consultant', 'desc' => 'General Advisory'],
    'admin'      => ['hex' => '#3b82f6', 'rgb' => '59,130,246',  'icon' => '🛡️', 'label' => 'Admin',      'desc' => 'Full Access'],
    'superadmin' => ['hex' => '#3b82f6', 'rgb' => '59,130,246',  'icon' => '🛡️', 'label' => 'Super Admin','desc' => 'Full Access'],
];
$ra = $accentMap[$staffRole] ?? $accentMap['consultant'];
$accentHex = $ra['hex'];
$accentRgb = $ra['rgb'];

// ════════════════════════════════════════════════════════════════════════════
//  ACTION PROCESSING
// ════════════════════════════════════════════════════════════════════════════
if ($page === 'notes') {
    if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $content = trim($_POST['content'] ?? '');
        if ($content) {
            query("INSERT INTO staff_notes (user_id,staff_id,note_type,content,is_private) VALUES (?,?,?,?,?)",
                [(int)$_POST['user_id'], $staffId, $_POST['note_type']??'general', $content, isset($_POST['is_private'])?1:0]);
            $flash = ['type'=>'success','msg'=>'Note saved successfully.'];
        }
        header("Location: ?page=notes&uid={$_POST['user_id']}"); exit();
    }
    if ($action === 'delete' && $rid) {
        query("DELETE FROM staff_notes WHERE id=? AND staff_id=?", [$rid, $staffId]);
        $flash = ['type'=>'success','msg'=>'Note deleted.']; header("Location: ?page=notes&uid=$uid"); exit();
    }
}
if ($page === 'meals') {
    if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        query("INSERT INTO meal_plans (user_id,staff_id,title,goal,calories,protein_g,carbs_g,fat_g,plan_text,week_number) VALUES (?,?,?,?,?,?,?,?,?,?)",
            [(int)$_POST['user_id'],$staffId,trim($_POST['title']),$_POST['goal'],(int)$_POST['calories'],(int)$_POST['protein_g'],(int)$_POST['carbs_g'],(int)$_POST['fat_g'],trim($_POST['plan_text']??''),(int)($_POST['week_number']??1)]);
        $flash = ['type'=>'success','msg'=>'Meal plan created.']; header("Location: ?page=meals&uid={$_POST['user_id']}"); exit();
    }
    if ($action === 'delete' && $rid) { query("DELETE FROM meal_plans WHERE id=? AND staff_id=?", [$rid,$staffId]); header("Location: ?page=meals&uid=$uid"); exit(); }
    if ($action === 'toggle' && $rid) { query("UPDATE meal_plans SET is_active = NOT is_active WHERE id=?", [$rid]); header("Location: ?page=meals&uid=$uid"); exit(); }
}
if ($page === 'consult') {
    if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        query("INSERT INTO consultations (user_id,staff_id,title,type,status,scheduled_at,summary,recommendations) VALUES (?,?,?,?,?,?,?,?)",
            [(int)$_POST['user_id'],$staffId,trim($_POST['title']),$_POST['type']??'initial',$_POST['status']??'pending',
             !empty($_POST['scheduled_at'])?$_POST['scheduled_at']:null,trim($_POST['summary']??''),trim($_POST['recommendations']??'')]);
        $flash = ['type'=>'success','msg'=>'Consultation logged.']; header("Location: ?page=consult&uid={$_POST['user_id']}"); exit();
    }
    if ($action === 'complete' && $rid) { query("UPDATE consultations SET status='completed', completed_at=NOW() WHERE id=?", [$rid]); header("Location: ?page=consult&uid=$uid"); exit(); }
    if ($action === 'delete' && $rid) { query("DELETE FROM consultations WHERE id=? AND staff_id=?", [$rid,$staffId]); header("Location: ?page=consult&uid=$uid"); exit(); }
}
if ($page === 'messages') {
    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = trim($_POST['body'] ?? ''); $toUser = (int)($_POST['to_user'] ?? 0);
        if ($body && $toUser) {
            query("INSERT INTO staff_messages (from_staff,to_user,subject,body) VALUES (?,?,?,?)",
                [$staffId, $toUser, trim($_POST['subject']??''), $body]);
            $flash = ['type'=>'success','msg'=>'Message sent.'];
        }
        header("Location: ?page=messages&uid=$toUser"); exit();
    }
    if ($action === 'read' && $rid) { query("UPDATE staff_messages SET is_read=1 WHERE id=? AND to_staff=?", [$rid,$staffId]); header("Location: ?page=messages"); exit(); }
}
if ($page === 'settings') {
    $selfRecord = null;
    try { $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]); } catch(Exception $e){}
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
        if ($_POST['action']==='update_profile' && $selfRecord) {
            query("UPDATE admin_staff SET full_name=?,phone=?,notes=? WHERE id=?",[trim($_POST['full_name']),trim($_POST['phone']??''),trim($_POST['notes']??''),$staffId]);
            $_SESSION['staff_name'] = trim($_POST['full_name']); $staffName = trim($_POST['full_name']);
            $flash=['type'=>'success','msg'=>'Profile updated.']; header("Location: ?page=settings"); exit();
        }
        if ($_POST['action']==='change_password' && $selfRecord) {
            $cur=$_POST['current_password']??''; $new=$_POST['new_password']??'';
            if (!password_verify($cur,$selfRecord['password'])) $flash=['type'=>'error','msg'=>'Current password incorrect.'];
            elseif (strlen($new)<8) $flash=['type'=>'error','msg'=>'New password must be 8+ chars.'];
            elseif ($new!==($_POST['confirm_password']??'')) $flash=['type'=>'error','msg'=>'Passwords do not match.'];
            else { query("UPDATE admin_staff SET password=? WHERE id=?",[password_hash($new,PASSWORD_DEFAULT),$staffId]); $flash=['type'=>'success','msg'=>'Password changed.']; header("Location: ?page=settings"); exit(); }
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  DATA FETCHING
// ════════════════════════════════════════════════════════════════════════════
$homeStats = []; $recentPremium = [];
if ($page === 'home') {
    try {
        $homeStats['premium']       = fetchOne("SELECT COUNT(*) as c FROM users WHERE is_premium=1")['c'] ?? 0;
        $homeStats['my_notes']      = fetchOne("SELECT COUNT(*) as c FROM staff_notes WHERE staff_id=?",[$staffId])['c'] ?? 0;
        $homeStats['my_consults']   = fetchOne("SELECT COUNT(*) as c FROM consultations WHERE staff_id=? AND status='completed'",[$staffId])['c'] ?? 0;
        $homeStats['pending']       = fetchOne("SELECT COUNT(*) as c FROM consultations WHERE staff_id=? AND status='pending'",[$staffId])['c'] ?? 0;
        $homeStats['unread_msgs']   = fetchOne("SELECT COUNT(*) as c FROM staff_messages WHERE to_staff=? AND is_read=0",[$staffId])['c'] ?? 0;
        $homeStats['my_meal_plans'] = fetchOne("SELECT COUNT(*) as c FROM meal_plans WHERE staff_id=?",[$staffId])['c'] ?? 0;
        $recentPremium = fetchAll("SELECT id,full_name,email,age,weight,height,target_force,experience_level,premium_since FROM users WHERE is_premium=1 ORDER BY premium_since DESC LIMIT 8");
    } catch(Exception $e){}
}

$premiumUsers = []; $searchQ = trim($_GET['q'] ?? '');
if ($page === 'clients') {
    $where = ['is_premium=1']; $params = [];
    if ($searchQ) { $where[] = '(full_name LIKE ? OR email LIKE ?)'; $params[] = "%$searchQ%"; $params[] = "%$searchQ%"; }
    $premiumUsers = fetchAll("SELECT * FROM users WHERE " . implode(' AND ',$where) . " ORDER BY premium_since DESC", $params);
}

$selectedUser = null;
if ($uid && in_array($page, ['profile','notes','meals','consult','messages'])) {
    $selectedUser = fetchOne("SELECT * FROM users WHERE id=? AND is_premium=1", [$uid]);
    if (!$selectedUser && $page !== 'messages') { $flash = ['type'=>'error','msg'=>'User not found or not a premium member.']; $page='clients'; }
}

$userNotes = [];
if ($page === 'notes' && $selectedUser)
    $userNotes = fetchAll("SELECT n.*,s.full_name as staff_name FROM staff_notes n LEFT JOIN admin_staff s ON n.staff_id=s.id WHERE n.user_id=? ORDER BY n.created_at DESC", [$uid]);

$userMeals = [];
if ($page === 'meals' && $selectedUser)
    $userMeals = fetchAll("SELECT m.*,s.full_name as staff_name FROM meal_plans m LEFT JOIN admin_staff s ON m.staff_id=s.id WHERE m.user_id=? ORDER BY m.created_at DESC", [$uid]);

$userWorkouts = [];
if ($page === 'profile' && $selectedUser) {
    try { $userWorkouts = fetchAll("SELECT * FROM workouts WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [$uid]); } catch(Exception $e){}
}

$userConsults = [];
if ($page === 'consult' && $selectedUser)
    $userConsults = fetchAll("SELECT c.*,s.full_name as staff_name FROM consultations c LEFT JOIN admin_staff s ON c.staff_id=s.id WHERE c.user_id=? ORDER BY c.created_at DESC", [$uid]);

$inbox = []; $sentItems = []; $msgUser = null;
if ($page === 'messages') {
    $inbox     = fetchAll("SELECT m.*,u.full_name as from_name FROM staff_messages m LEFT JOIN users u ON m.from_user=u.id WHERE m.to_staff=? ORDER BY m.created_at DESC LIMIT 40", [$staffId]);
    $sentItems = fetchAll("SELECT m.*,u.full_name as to_name FROM staff_messages m LEFT JOIN users u ON m.to_user=u.id WHERE m.from_staff=? ORDER BY m.created_at DESC LIMIT 40", [$staffId]);
    if ($uid) $msgUser = fetchOne("SELECT id,full_name,email FROM users WHERE id=? AND is_premium=1", [$uid]);
    $premiumUsers = fetchAll("SELECT id,full_name,email FROM users WHERE is_premium=1 ORDER BY full_name");
}

$unreadCount = 0;
if ($isLoggedIn) { try { $unreadCount = fetchOne("SELECT COUNT(*) as c FROM staff_messages WHERE to_staff=? AND is_read=0",[$staffId])['c']??0; } catch(Exception $e){} }

// ── Helpers ───────────────────────────────────────────────────────────────────
$forceFlags   = ['british'=>'🇬🇧','nepal'=>'🇳🇵','indian'=>'🇮🇳','singapore'=>'🇸🇬','french'=>'🇫🇷'];
$avatarLetter = fn($name) => strtoupper(substr(trim($name),0,1));
$bmiCalc      = function($w,$h){ if(!$w||!$h) return null; $hm=$h/100; return round($w/($hm*$hm),1); };
$bmiCategory  = function($bmi){ if(!$bmi) return ['—','muted']; if($bmi<18.5) return ['Underweight','blue']; if($bmi<25) return ['Healthy','green']; if($bmi<30) return ['Overweight','gold']; return ['Obese','red']; };

$pageTitles = ['home'=>'Overview','clients'=>'Premium Clients','profile'=>'Client Profile','notes'=>'Notes & Remarks','meals'=>'Meal Plans','consult'=>'Consultations','messages'=>'Messages','settings'=>'Settings'];
$selfRecord  = isset($selfRecord) ? $selfRecord : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageTitles[$page]??'Portal' ?> — Gurkha Marga Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════
   RESET & TOKENS
═══════════════════════════════════════════════════════ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --accent:     <?= $accentHex ?>;
    --accent-rgb: <?= $accentRgb ?>;
    --gold:       #f59e0b;
    --green:      #10b981;
    --red:        #ef4444;
    --purple:     #8b5cf6;

    --bg:         #0f172a;
    --surface:    rgba(30,41,59,.6);
    --surface-s:  #1e293b;
    --elevated:   rgba(30,41,59,.9);

    --border:     rgba(255,255,255,.07);
    --border-hi:  rgba(255,255,255,.12);

    --text:       #f8fafc;
    --text-2:     #cbd5e1;
    --text-3:     #64748b;

    --sidebar-w:  260px;
    --r:          10px;
    --mono:       'Courier New',monospace;
    --font:       'Poppins',sans-serif;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#0f172a 100%);color:var(--text);min-height:100vh}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}

/* ═══════════════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════════════ */
.sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;background:rgba(15,23,42,.97);backdrop-filter:blur(20px);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow-y:auto;transition:transform .3s ease}

.sb-logo{padding:1.4rem 1.25rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.sb-logo-ico{width:36px;height:36px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.sb-brand{font-size:1.05rem;font-weight:800;color:var(--gold)}
.sb-brand-sub{font-size:.57rem;font-weight:600;letter-spacing:.13em;text-transform:uppercase;color:var(--text-3);margin-top:1px}

.sb-user{margin:.85rem .9rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r);padding:.75rem .9rem;display:flex;align-items:center;gap:.7rem}
.sb-av{width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.88rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),var(--purple));border:1.5px solid rgba(255,255,255,.15)}
.sb-uname{font-size:.82rem;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}
.sb-urole{font-size:.67rem;color:var(--accent);margin-top:2px;text-transform:capitalize}
.online-dot{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);margin-left:auto;flex-shrink:0;animation:pulse 2s infinite}

.sb-nav{flex:1;padding:.35rem 0 1rem}
.nav-sec{font-size:.6rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:rgba(100,116,139,.5);padding:.85rem 1.25rem .3rem}
.nav-item{display:flex;align-items:center;gap:.7rem;padding:.55rem 1.25rem;color:var(--text-2);text-decoration:none;font-size:.84rem;font-weight:500;border-left:2.5px solid transparent;transition:all .15s}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.04)}
.nav-item.active{color:var(--text);background:rgba(255,255,255,.06);border-left-color:var(--accent)}
.nav-item .ico{width:16px;text-align:center;flex-shrink:0}
.nav-item.danger{color:#f87171}
.nav-item.danger:hover{background:rgba(239,68,68,.08)}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:20px;min-width:18px;text-align:center}

.sb-footer{padding:.85rem .9rem;border-top:1px solid var(--border)}
.role-chip{display:flex;align-items:center;gap:.65rem;background:rgba(255,255,255,.04);border:1px solid var(--border-hi);border-radius:9px;padding:.65rem .85rem}
.role-chip-ico{font-size:1.1rem}
.role-chip-name{font-size:.8rem;font-weight:700;color:var(--accent);text-transform:capitalize}
.role-chip-desc{font-size:.67rem;color:var(--text-3);margin-top:1px}

/* ═══════════════════════════════════════════════════════
   LAYOUT
═══════════════════════════════════════════════════════ */
.layout{margin-left:var(--sidebar-w);min-height:100vh}

/* ═══════════════════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════════════════ */
.topbar{position:sticky;top:0;z-index:100;background:rgba(15,23,42,.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:.85rem 2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.topbar-toggle{display:none;background:none;border:none;cursor:pointer;color:var(--text-3);font-size:1.2rem}
.breadcrumb{font-size:.72rem;color:var(--text-3);display:flex;align-items:center;gap:.4rem}
.breadcrumb a{color:var(--text-2);text-decoration:none}.breadcrumb a:hover{color:var(--text)}
.topbar-right{display:flex;align-items:center;gap:.75rem}
.topbar-date{font-size:.72rem;color:var(--text-3);font-family:var(--mono)}
.premium-badge{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);color:var(--gold);padding:.22rem .75rem;border-radius:20px;font-size:.7rem;font-weight:600}
.unread-link{display:flex;align-items:center;gap:.35rem;text-decoration:none;font-size:.76rem;color:#fca5a5;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);padding:.22rem .7rem;border-radius:20px}

/* ═══════════════════════════════════════════════════════
   PAGE
═══════════════════════════════════════════════════════ */
.page{padding:1.75rem 2rem 3rem;max-width:1400px}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.75rem;gap:1rem;flex-wrap:wrap}
.page-title{font-size:1.4rem;font-weight:800;letter-spacing:-.02em;display:flex;align-items:center;gap:.6rem}
.page-sub{font-size:.82rem;color:var(--text-3);margin-top:.25rem}
.section-label{font-size:.62rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--text-3);margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem}
.section-label::after{content:'';flex:1;height:1px;background:var(--border)}

/* ═══════════════════════════════════════════════════════
   STAT CARDS
═══════════════════════════════════════════════════════ */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--surface);backdrop-filter:blur(12px);border:1px solid var(--border);border-radius:var(--r);padding:1.1rem 1.2rem;position:relative;overflow:hidden;transition:border-color .2s,transform .2s;animation:fadeUp .4s ease both}
.stat-card:hover{border-color:var(--border-hi);transform:translateY(-2px)}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);border-radius:var(--r) var(--r) 0 0;opacity:.7}
.stat-card.green::after{background:var(--green)}.stat-card.gold::after{background:var(--gold)}.stat-card.red::after{background:var(--red)}.stat-card.purple::after{background:var(--purple)}
.stat-ico{font-size:1.3rem;margin-bottom:.6rem}
.stat-val{font-size:1.75rem;font-weight:700;line-height:1;letter-spacing:-.03em;font-family:var(--mono)}
.stat-lbl{font-size:.65rem;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3);margin-top:.3rem}
.stat-sub{font-size:.7rem;color:var(--text-3);margin-top:.18rem}

/* ═══════════════════════════════════════════════════════
   CARDS
═══════════════════════════════════════════════════════ */
.card{background:var(--surface);backdrop-filter:blur(12px);border:1px solid var(--border);border-radius:var(--r);padding:1.35rem;overflow:hidden;animation:fadeUp .4s ease both}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.15rem;padding-bottom:.85rem;border-bottom:1px solid var(--border)}
.card-title{font-size:.88rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
.card-action{font-size:.78rem;color:var(--accent);text-decoration:none;font-weight:500}
.card-action:hover{text-decoration:underline}

/* ═══════════════════════════════════════════════════════
   GRIDS
═══════════════════════════════════════════════════════ */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
.aside-layout{display:grid;grid-template-columns:280px 1fr;gap:1.25rem;align-items:start}
.span-full{grid-column:1/-1}
.mb{margin-bottom:1.5rem}

/* ═══════════════════════════════════════════════════════
   BADGES
═══════════════════════════════════════════════════════ */
.badge{display:inline-block;padding:.2rem .6rem;border-radius:5px;font-size:.68rem;font-weight:600;white-space:nowrap}
.bg-green {background:rgba(16,185,129,.12); color:#6ee7b7; border:1px solid rgba(16,185,129,.2)}
.bg-red   {background:rgba(239,68,68,.12);  color:#fca5a5; border:1px solid rgba(239,68,68,.2)}
.bg-gold  {background:rgba(245,158,11,.12); color:#fcd34d; border:1px solid rgba(245,158,11,.2)}
.bg-blue  {background:rgba(59,130,246,.12); color:#93c5fd; border:1px solid rgba(59,130,246,.2)}
.bg-accent{background:rgba(var(--accent-rgb),.12);color:var(--accent);border:1px solid rgba(var(--accent-rgb),.25)}
.bg-purple{background:rgba(139,92,246,.12); color:#c4b5fd; border:1px solid rgba(139,92,246,.2)}
.bg-muted {background:rgba(255,255,255,.06);color:var(--text-3);border:1px solid var(--border)}

/* ═══════════════════════════════════════════════════════
   BUTTONS
═══════════════════════════════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.52rem 1.1rem;border-radius:9px;font-family:var(--font);font-size:.82rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .18s;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--accent),rgba(var(--accent-rgb),.7));color:#fff}
.btn-primary:hover{opacity:.9;transform:translateY(-1px);box-shadow:0 4px 14px rgba(var(--accent-rgb),.3)}
.btn-gold{background:linear-gradient(135deg,var(--gold),#d97706);color:#0f172a}
.btn-gold:hover{opacity:.9;transform:translateY(-1px)}
.btn-ghost{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text-2)}
.btn-ghost:hover{background:rgba(255,255,255,.09);color:var(--text)}
.btn-danger{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-green-outline{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#6ee7b7}
.btn-sm{font-size:.73rem;padding:.3rem .7rem;border-radius:7px;background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text-2);font-family:var(--font);font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;text-decoration:none}
.btn-sm:hover{color:var(--text);border-color:var(--border-hi)}
.act{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.04);border:1px solid var(--border);font-size:.78rem;cursor:pointer;transition:all .15s;text-decoration:none;color:inherit}
.act:hover{background:rgba(255,255,255,.09);border-color:var(--border-hi)}
.act.del:hover{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3)}

/* ═══════════════════════════════════════════════════════
   FORMS
═══════════════════════════════════════════════════════ */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.field{display:flex;flex-direction:column;gap:.35rem}
.field.full{grid-column:1/-1}
.field.row{flex-direction:row;align-items:center;gap:.65rem}
label,.lbl{font-size:.67rem;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3)}
.field.row label{text-transform:none;font-size:.875rem;letter-spacing:0;color:var(--text);margin:0}
input,select,textarea{background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:var(--font);font-size:.875rem;padding:.6rem .85rem;transition:border-color .2s,box-shadow .2s;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:rgba(var(--accent-rgb),.4);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.08)}
input::placeholder,textarea::placeholder{color:var(--text-3)}
select option{background:#1e293b}
textarea{resize:vertical;min-height:90px}
input[type=checkbox]{width:auto;accent-color:var(--accent)}

/* ═══════════════════════════════════════════════════════
   FLASH
═══════════════════════════════════════════════════════ */
.flash{padding:.78rem 1rem;border-radius:9px;font-size:.84rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:.6rem;animation:fadeUp .3s ease}
.flash-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);color:#6ee7b7}
.flash-error  {background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.2); color:#fca5a5}

/* ═══════════════════════════════════════════════════════
   MODAL
═══════════════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease}
.modal{background:#1e293b;border:1px solid var(--border-hi);border-radius:14px;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;padding:1.6rem;animation:slideUp .22s ease}
.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.3rem}
.modal-title{font-size:1rem;font-weight:700}
.modal-close{background:none;border:none;color:var(--text-3);font-size:1.1rem;cursor:pointer;padding:.2rem;transition:color .15s}
.modal-close:hover{color:var(--text)}
.modal-foot{display:flex;gap:.65rem;justify-content:flex-end;margin-top:1.3rem;padding-top:.9rem;border-top:1px solid var(--border)}

/* ═══════════════════════════════════════════════════════
   TABS
═══════════════════════════════════════════════════════ */
.tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:1.35rem}
.tab{padding:.6rem 1.1rem;font-size:.82rem;font-weight:500;color:var(--text-3);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;text-decoration:none;white-space:nowrap}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}

/* ═══════════════════════════════════════════════════════
   TABLE
═══════════════════════════════════════════════════════ */
.tbl-wrap{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse}
.data-table th{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);padding:.6rem .85rem;text-align:left;border-bottom:1px solid var(--border)}
.data-table td{padding:.75rem .85rem;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.data-table tbody tr:hover{background:rgba(255,255,255,.02)}
.data-table tbody tr:last-child td{border-bottom:none}
.td-name{display:flex;align-items:center;gap:.75rem}
.tbl-av{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--accent),var(--purple))}

/* ═══════════════════════════════════════════════════════
   CLIENT GRID
═══════════════════════════════════════════════════════ */
.client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:1rem}
.client-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:1.2rem;text-decoration:none;color:inherit;display:block;transition:border-color .2s,transform .18s;position:relative;animation:fadeUp .4s ease both}
.client-card:hover{border-color:rgba(var(--accent-rgb),.3);transform:translateY(-2px)}
.cc-top{display:flex;align-items:center;gap:.85rem;margin-bottom:1rem}
.cc-av{width:44px;height:44px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;color:#fff}
.cc-name{font-weight:700;font-size:.9rem;line-height:1.2}
.cc-email{font-size:.72rem;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:175px}
.cc-metrics{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;border-top:1px solid var(--border);padding-top:.85rem}
.cc-metric-val{font-family:var(--mono);font-size:.9rem;font-weight:700}
.cc-metric-lbl{font-size:.63rem;color:var(--text-3);margin-top:1px;text-transform:uppercase;letter-spacing:.05em}
.cc-footer{display:flex;align-items:center;justify-content:space-between;margin-top:.85rem;padding-top:.75rem;border-top:1px solid var(--border)}
.premium-star{position:absolute;top:-.5rem;right:-.5rem;background:var(--gold);color:#0f172a;font-size:.58rem;font-weight:700;padding:.12rem .45rem;border-radius:4px}

/* ═══════════════════════════════════════════════════════
   PROFILE PANEL
═══════════════════════════════════════════════════════ */
.profile-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:1.4rem;position:sticky;top:76px}
.profile-av{width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;font-size:1.45rem;font-weight:700;color:#fff;margin:0 auto .75rem;border:2px solid rgba(255,255,255,.12)}
.profile-name{text-align:center;font-weight:700;font-size:.96rem;margin-bottom:.15rem}
.profile-email{text-align:center;font-size:.73rem;color:var(--text-3);margin-bottom:.9rem}
.stat-mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem}
.stat-mini{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.55rem;text-align:center}
.stat-mini-val{font-family:var(--mono);font-size:.95rem;font-weight:700}
.stat-mini-lbl{font-size:.62rem;color:var(--text-3);margin-top:2px;text-transform:uppercase;letter-spacing:.06em}
.profile-nav{margin-top:1rem;display:flex;flex-direction:column;gap:.2rem}
.profile-nav-link{display:flex;align-items:center;gap:.6rem;padding:.48rem .7rem;border-radius:8px;font-size:.8rem;font-weight:500;text-decoration:none;color:var(--text-3);transition:all .15s}
.profile-nav-link:hover{color:var(--text);background:rgba(255,255,255,.05)}
.profile-nav-link.active{color:var(--accent);background:rgba(var(--accent-rgb),.08)}

/* ═══════════════════════════════════════════════════════
   DATA ROW
═══════════════════════════════════════════════════════ */
.d-row{display:flex;justify-content:space-between;align-items:center;padding:.48rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem}
.d-row:last-child{border-bottom:none}
.d-key{color:var(--text-3)}
.d-val{font-family:var(--mono);font-size:.78rem;font-weight:500;text-align:right}

/* ═══════════════════════════════════════════════════════
   NOTE ITEM
═══════════════════════════════════════════════════════ */
.note-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:1rem;margin-bottom:.75rem;transition:border-color .15s}
.note-item:hover{border-color:var(--border-hi)}
.note-header{display:flex;align-items:center;gap:.6rem;margin-bottom:.65rem;flex-wrap:wrap}
.note-type{font-size:.63rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;padding:.18rem .55rem;border-radius:4px}
.note-type-general  {background:rgba(var(--accent-rgb),.1);color:var(--accent);border:1px solid rgba(var(--accent-rgb),.2)}
.note-type-dietary  {background:rgba(16,185,129,.1);color:#6ee7b7;border:1px solid rgba(16,185,129,.2)}
.note-type-medical  {background:rgba(239,68,68,.1); color:#fca5a5;border:1px solid rgba(239,68,68,.2)}
.note-type-workout  {background:rgba(59,130,246,.1);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
.note-type-followup {background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.note-meta{font-size:.72rem;color:var(--text-3)}
.note-time{font-size:.7rem;color:var(--text-3);font-family:var(--mono);margin-left:auto}
.note-body{font-size:.84rem;line-height:1.7;color:var(--text-2)}
.note-private{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2);font-size:.65rem;font-weight:600;padding:.12rem .45rem;border-radius:4px}

/* ═══════════════════════════════════════════════════════
   MEAL PLAN CARD
═══════════════════════════════════════════════════════ */
.meal-card{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:1.1rem;margin-bottom:.85rem;transition:border-color .15s}
.meal-card:hover{border-color:var(--border-hi)}
.macro-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;margin:.75rem 0}
.macro-box{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.55rem;text-align:center}
.macro-val{font-family:var(--mono);font-size:.95rem;font-weight:700}
.macro-lbl{font-size:.62rem;color:var(--text-3);margin-top:2px;text-transform:uppercase;letter-spacing:.06em}
.plan-text{font-size:.82rem;color:var(--text-3);line-height:1.7;white-space:pre-wrap;padding:.75rem;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid var(--border);margin-top:.65rem}

/* ═══════════════════════════════════════════════════════
   CONSULTATION ITEM
═══════════════════════════════════════════════════════ */
.consult-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:1rem;margin-bottom:.75rem;position:relative;overflow:hidden;transition:border-color .15s}
.consult-item:hover{border-color:var(--border-hi)}
.consult-item::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:var(--r) 0 0 var(--r)}
.consult-item.pending::before   {background:var(--gold)}
.consult-item.in_progress::before{background:var(--accent)}
.consult-item.completed::before {background:var(--green)}
.consult-item.cancelled::before {background:var(--text-3)}
.consult-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.65rem;flex-wrap:wrap}
.consult-title{font-weight:700;font-size:.9rem}
.consult-meta{font-size:.73rem;color:var(--text-3);margin-top:.18rem}
.consult-body{font-size:.82rem;color:var(--text-2);line-height:1.65}

/* ═══════════════════════════════════════════════════════
   MESSAGE ITEM
═══════════════════════════════════════════════════════ */
.msg-item{display:flex;gap:.85rem;padding:.9rem;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);margin-bottom:.6rem;transition:border-color .15s}
.msg-item:hover{border-color:var(--border-hi)}
.msg-item.unread{border-color:rgba(var(--accent-rgb),.25);background:rgba(var(--accent-rgb),.04)}
.msg-av{width:36px;height:36px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:#fff}
.msg-from{font-size:.84rem;font-weight:600}
.msg-subject{font-size:.78rem;color:var(--text-3);margin-top:.1rem}
.msg-preview{font-size:.77rem;color:var(--text-3);margin-top:.3rem;display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.msg-time{font-size:.7rem;color:var(--text-3);font-family:var(--mono);white-space:nowrap}

/* ═══════════════════════════════════════════════════════
   EMPTY STATE
═══════════════════════════════════════════════════════ */
.empty-state{text-align:center;padding:3.5rem 1.5rem;color:var(--text-3)}
.empty-icon{font-size:2.2rem;margin-bottom:.75rem;opacity:.5}
.empty-title{font-size:.9rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem}
.empty-text{font-size:.8rem}

/* ═══════════════════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════════════════ */
@keyframes fadeUp  {from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn  {from{opacity:0}to{opacity:1}}
@keyframes slideUp {from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse   {0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}50%{box-shadow:0 0 0 6px rgba(16,185,129,0)}}

/* ═══════════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════════ */
@media(max-width:1200px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1000px){.grid2,.aside-layout{grid-template-columns:1fr}.grid3{grid-template-columns:1fr 1fr}}
@media(max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.layout{margin-left:0}.topbar-toggle{display:block}.page{padding:1rem}}
@media(max-width:640px){.stats-row{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}.client-grid{grid-template-columns:1fr}.grid3{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sb-logo">
        <div class="sb-logo-ico">⚔️</div>
        <div>
            <div class="sb-brand">Gurkha Marga</div>
            <div class="sb-brand-sub">Staff Portal</div>
        </div>
    </div>

    <div class="sb-user">
        <div class="sb-av"><?= $avatarLetter($staffName) ?></div>
        <div style="overflow:hidden;flex:1">
            <div class="sb-uname"><?= htmlspecialchars($staffName) ?></div>
            <div class="sb-urole"><?= htmlspecialchars($staffRole) ?></div>
        </div>
        <div class="online-dot"></div>
    </div>

    <nav class="sb-nav">
        <div class="nav-sec">Main</div>
        <a href="premium_home.php"    class="nav-item <?= $page==='home'?'active':'' ?>"><span class="ico">📊</span> Overview</a>
        <a href="premium_client.php" class="nav-item <?= $page==='clients'?'active':'' ?>"><span class="ico">👥</span> Premium Clients</a>

        <div class="nav-sec">Client Tools</div>
        <a href="premium_notes.php"   class="nav-item <?= $page==='notes'?'active':'' ?>"><span class="ico">📝</span> Notes & Remarks</a>
        <a href="premium_consult.php" class="nav-item <?= $page==='consult'?'active':'' ?>"><span class="ico">🗂</span> Consultations</a>
        <?php if ($isDietitian): ?>
        <a href="premium_meals.php"   class="nav-item <?= $page==='meals'?'active':'' ?>"><span class="ico">🥗</span> Meal Plans</a>
        <?php endif; ?>

        <div class="nav-sec">Communicate</div>
        <a href="premium_messages.php" class="nav-item <?= $page==='messages'?'active':'' ?>">
            <span class="ico">✉️</span> Messages
            <?php if ($unreadCount>0): ?><span class="nav-badge"><?= $unreadCount ?></span><?php endif; ?>
        </a>

        <div class="nav-sec">Account</div>
        <a href="premium_settings.php" class="nav-item <?= $page==='settings'?'active':'' ?>"><span class="ico">⚙️</span> Settings</a>
        <a href="premium_logout.php" class="nav-item danger" onclick="return confirm('Log out?')"><span class="ico">🚪</span> Logout</a>
    </nav>

    <div class="sb-footer">
        <div class="role-chip">
            <div class="role-chip-ico"><?= $ra['icon'] ?></div>
            <div>
                <div class="role-chip-name"><?= $ra['label'] ?></div>
                <div class="role-chip-desc"><?= $ra['desc'] ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- ══ LAYOUT ════════════════════════════════════════════════════════════════ -->
<div class="layout">

    <!-- TOPBAR -->
    <header class="topbar">
        <div style="display:flex;align-items:center;gap:.75rem">
            <button class="topbar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
            <nav class="breadcrumb">
                <a href="?page=home">Staff Portal</a>
                <span>/</span>
                <span><?= htmlspecialchars($pageTitles[$page]??ucfirst($page)) ?></span>
                <?php if (isset($selectedUser) && $selectedUser): ?>
                <span>/</span>
                <span><?= htmlspecialchars($selectedUser['full_name']) ?></span>
                <?php endif; ?>
            </nav>
        </div>
        <div class="topbar-right">
            <span class="topbar-date"><?= date('D, d M Y') ?></span>
            <span class="premium-badge">⭐ Premium Portal</span>
            <?php if ($unreadCount>0): ?>
            <a href="premium_messages.php" class="unread-link">✉️ <?= $unreadCount ?> unread</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">

    <?php if ($flash['msg']): ?>
    <div class="flash flash-<?= $flash['type'] ?>"><?= $flash['type']==='success'?'✓':'⚠' ?> <?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  HOME
// ════════════════════════════════════════════════════════════════════════════
if ($page === 'home'):
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $ra['icon'] ?> <?= $ra['label'] ?> Dashboard</h1>
        <p class="page-sub">
            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:5px;vertical-align:middle;animation:pulse 2s infinite"></span>
            Welcome back, <?= htmlspecialchars($staffName) ?> — <?= date('D, d M Y · H:i') ?>
        </p>
    </div>
    <a href="?page=clients" class="btn btn-primary">View All Clients →</a>
</div>

<div class="section-label">Your Activity</div>
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-ico">⭐</div>
        <div class="stat-val"><?= $homeStats['premium']??0 ?></div>
        <div class="stat-lbl">Premium Clients</div>
        <div class="stat-sub">Your accessible pool</div>
    </div>
    <div class="stat-card green">
        <div class="stat-ico">📝</div>
        <div class="stat-val"><?= $homeStats['my_notes']??0 ?></div>
        <div class="stat-lbl">Notes Written</div>
        <div class="stat-sub">By you total</div>
    </div>
    <?php if ($isDietitian): ?>
    <div class="stat-card gold">
        <div class="stat-ico">🥗</div>
        <div class="stat-val"><?= $homeStats['my_meal_plans']??0 ?></div>
        <div class="stat-lbl">Meal Plans</div>
        <div class="stat-sub">Created by you</div>
    </div>
    <?php endif; ?>
    <div class="stat-card purple">
        <div class="stat-ico">✅</div>
        <div class="stat-val"><?= $homeStats['my_consults']??0 ?></div>
        <div class="stat-lbl">Consultations Done</div>
        <div class="stat-sub">Completed sessions</div>
    </div>
    <div class="stat-card red">
        <div class="stat-ico">⏳</div>
        <div class="stat-val"><?= $homeStats['pending']??0 ?></div>
        <div class="stat-lbl">Pending Sessions</div>
        <div class="stat-sub">Needs attention</div>
    </div>
</div>

<div class="grid2 mb">
    <div class="card">
        <div class="card-head">
            <div class="card-title">⭐ Recent Premium Clients</div>
            <a href="?page=clients" class="card-action">See all →</a>
        </div>
        <?php if (empty($recentPremium)): ?>
        <div class="empty-state" style="padding:2rem"><div class="empty-icon">👥</div><div class="empty-title">No premium clients yet</div><div class="empty-text">Clients will appear once they upgrade.</div></div>
        <?php else: foreach ($recentPremium as $u):
            $bmi = $bmiCalc($u['weight'],$u['height']);
            [$bmiCat,$bmiCol] = $bmiCategory($bmi);
        ?>
        <a href="?page=profile&uid=<?= $u['id'] ?>" style="display:flex;align-items:center;gap:.85rem;padding:.75rem 0;border-bottom:1px solid rgba(255,255,255,.04);text-decoration:none;color:inherit;transition:opacity .15s">
            <div class="tbl-av"><?= $avatarLetter($u['full_name']) ?></div>
            <div style="flex:1;overflow:hidden">
                <div style="font-weight:600;font-size:.84rem"><?= htmlspecialchars($u['full_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-3)"><?= htmlspecialchars($u['email']) ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <span class="badge bg-<?= $bmiCol ?>" style="font-size:.65rem"><?= $bmiCat ?></span>
                <div style="font-size:.68rem;color:var(--text-3);margin-top:3px"><?= $forceFlags[$u['target_force']]??'—' ?></div>
            </div>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-title"><?= $ra['icon'] ?> <?= $ra['label'] ?> Access Guide</div>
        </div>
        <?php if ($isDietitian && $staffRole==='dietitian'): ?>
        <div class="d-row"><span class="d-key">Notes</span><span class="d-val">Dietary, medical, general</span></div>
        <div class="d-row"><span class="d-key">Meal Plans</span><span class="d-val">Calories + full macros</span></div>
        <div class="d-row"><span class="d-key">Consultations</span><span class="d-val">Log & track sessions</span></div>
        <div class="d-row"><span class="d-key">Messaging</span><span class="d-val">Direct to premium users</span></div>
        <div class="d-row"><span class="d-key">Health Data</span><span class="d-val">BMI, body fat, TDEE</span></div>
        <div class="d-row"><span class="d-key">Workouts</span><span class="d-val">View client history</span></div>
        <?php else: ?>
        <div class="d-row"><span class="d-key">Notes</span><span class="d-val">General & follow-up</span></div>
        <div class="d-row"><span class="d-key">Messaging</span><span class="d-val">Direct to premium users</span></div>
        <div class="d-row"><span class="d-key">Profiles</span><span class="d-val">Full health & fitness data</span></div>
        <div class="d-row"><span class="d-key">Workouts</span><span class="d-val">View client history</span></div>
        <?php endif; ?>
        <div style="margin-top:1rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:9px;padding:.85rem;font-size:.8rem;color:#fcd34d;line-height:1.6">
            ⭐ <strong>Premium only:</strong> All features are exclusive to premium plan users.
        </div>
    </div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  CLIENTS
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'clients'):
?>
<div class="page-header">
    <div>
        <h1 class="page-title">⭐ Premium Clients</h1>
        <p class="page-sub"><?= count($premiumUsers) ?> premium <?= count($premiumUsers)===1?'client':'clients' ?></p>
    </div>
    <form method="GET" style="display:flex;gap:.65rem;align-items:center">
        <input type="hidden" name="page" value="clients">
        <input type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($searchQ) ?>" style="max-width:240px">
        <button type="submit" class="btn btn-ghost">🔍</button>
        <?php if ($searchQ): ?><a href="?page=clients" class="btn btn-ghost">✕</a><?php endif; ?>
    </form>
</div>

<?php if (empty($premiumUsers)): ?>
<div class="empty-state"><div class="empty-icon">⭐</div><div class="empty-title">No premium clients<?= $searchQ?' match your search':' yet' ?></div><div class="empty-text">Premium users appear here once they upgrade their plan.</div></div>
<?php else: ?>
<div class="client-grid">
    <?php foreach ($premiumUsers as $u):
        $bmi = $bmiCalc($u['weight'],$u['height']);
        [$bmiCat,$bmiCol] = $bmiCategory($bmi);
    ?>
    <a class="client-card" href="?page=profile&uid=<?= $u['id'] ?>">
        <span class="premium-star">⭐ Premium</span>
        <div class="cc-top">
            <div class="cc-av"><?= $avatarLetter($u['full_name']) ?></div>
            <div style="overflow:hidden">
                <div class="cc-name"><?= htmlspecialchars($u['full_name']) ?></div>
                <div class="cc-email"><?= htmlspecialchars($u['email']) ?></div>
            </div>
        </div>
        <div class="cc-metrics">
            <div><div class="cc-metric-val"><?= $u['weight']?$u['weight'].'kg':'—' ?></div><div class="cc-metric-lbl">Weight</div></div>
            <div><div class="cc-metric-val"><?= $bmi??'—' ?></div><div class="cc-metric-lbl">BMI</div></div>
            <div><div class="cc-metric-val"><?= $u['height']?$u['height'].'cm':'—' ?></div><div class="cc-metric-lbl">Height</div></div>
        </div>
        <div class="cc-footer">
            <span class="badge bg-<?= $bmiCol ?>"><?= $bmiCat ?></span>
            <span style="font-size:.73rem;color:var(--text-3)"><?= $forceFlags[$u['target_force']]??'' ?> <?= ucfirst($u['experience_level']??'') ?></span>
            <span class="badge bg-accent" style="font-size:.65rem">View →</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  PROFILE
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'profile' && $selectedUser):
$u = $selectedUser;
$bmi     = $bmiCalc($u['weight'],$u['height']);
[$bmiCat,$bmiCol] = $bmiCategory($bmi);
$bodyFat = ($bmi && $u['age']) ? round((1.2*$bmi)+(0.23*(int)$u['age'])-(($u['gender']==='female')?5.4:16.2),1) : null;
$tdee    = ($u['weight']&&$u['height']&&$u['age']) ? round(((10*$u['weight'])+(6.25*$u['height'])-(5*(int)$u['age'])+($u['gender']==='female'?-161:5))*1.55) : null;
$noteCount    = fetchOne("SELECT COUNT(*) as c FROM staff_notes WHERE user_id=? AND staff_id=?",[$uid,$staffId])['c']??0;
$consultCount = fetchOne("SELECT COUNT(*) as c FROM consultations WHERE user_id=? AND staff_id=?",[$uid,$staffId])['c']??0;
$mealCount    = $isDietitian ? (fetchOne("SELECT COUNT(*) as c FROM meal_plans WHERE user_id=? AND staff_id=?",[$uid,$staffId])['c']??0) : 0;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">👤 <?= htmlspecialchars($u['full_name']) ?></h1>
        <p class="page-sub">Premium client profile · <a href="?page=clients" style="color:var(--accent);text-decoration:none">← Back to clients</a></p>
    </div>
    <div style="display:flex;gap:.65rem;flex-wrap:wrap">
        <a href="?page=notes&uid=<?= $uid ?>" class="btn btn-ghost">📝 Notes (<?= $noteCount ?>)</a>
        <?php if ($isDietitian): ?><a href="?page=meals&uid=<?= $uid ?>" class="btn btn-ghost">🥗 Plans (<?= $mealCount ?>)</a><?php endif; ?>
        <a href="?page=consult&uid=<?= $uid ?>" class="btn btn-ghost">🗂 Consults (<?= $consultCount ?>)</a>
        <a href="?page=messages&uid=<?= $uid ?>" class="btn btn-primary">✉️ Message</a>
    </div>
</div>

<div class="aside-layout">
    <div>
        <div class="profile-panel">
            <div class="profile-av"><?= $avatarLetter($u['full_name']) ?></div>
            <div class="profile-name"><?= htmlspecialchars($u['full_name']) ?></div>
            <div class="profile-email"><?= htmlspecialchars($u['email']) ?></div>
            <div style="display:flex;justify-content:center;margin-bottom:1rem"><span class="badge bg-gold">⭐ Premium Member</span></div>

            <div class="stat-mini-grid">
                <div class="stat-mini"><div class="stat-mini-val"><?= $bmi??'—' ?></div><div class="stat-mini-lbl">BMI</div></div>
                <div class="stat-mini"><div class="stat-mini-val" style="color:var(--<?= $bmiCol ?>)"><?= $bmiCat ?></div><div class="stat-mini-lbl">Category</div></div>
                <div class="stat-mini"><div class="stat-mini-val"><?= $u['weight']?$u['weight'].'kg':'—' ?></div><div class="stat-mini-lbl">Weight</div></div>
                <div class="stat-mini"><div class="stat-mini-val"><?= $u['height']?$u['height'].'cm':'—' ?></div><div class="stat-mini-lbl">Height</div></div>
            </div>

            <div class="d-row"><span class="d-key">🎂 Age</span><span class="d-val"><?= (int)$u['age'] ?> yrs</span></div>
            <div class="d-row"><span class="d-key">⚥ Gender</span><span class="d-val"><?= ucfirst($u['gender']??'N/A') ?></span></div>
            <div class="d-row"><span class="d-key">🎖️ Target Force</span><span class="d-val"><?= $forceFlags[$u['target_force']]??'' ?> <?= ucfirst($u['target_force']??'—') ?></span></div>
            <div class="d-row"><span class="d-key">⚔️ Level</span><span class="d-val"><?= ucfirst($u['experience_level']??'—') ?></span></div>
            <?php if ($bodyFat): ?><div class="d-row"><span class="d-key">💪 Body Fat</span><span class="d-val"><?= $bodyFat ?>%</span></div><?php endif; ?>
            <?php if ($tdee): ?><div class="d-row"><span class="d-key">🔥 TDEE</span><span class="d-val"><?= number_format($tdee) ?> kcal</span></div><?php endif; ?>
            <div class="d-row"><span class="d-key">📅 Premium Since</span><span class="d-val"><?= $u['premium_since']?date('M Y',strtotime($u['premium_since'])):'N/A' ?></span></div>

            <div class="profile-nav" style="border-top:1px solid var(--border);padding-top:.85rem;margin-top:.85rem">
                <div style="font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);margin-bottom:.4rem">Quick Actions</div>
                <a href="?page=notes&uid=<?= $uid ?>" class="profile-nav-link">📝 Write a Note</a>
                <?php if ($isDietitian): ?><a href="?page=meals&uid=<?= $uid ?>" class="profile-nav-link">🥗 Create Meal Plan</a><?php endif; ?>
                <a href="?page=consult&uid=<?= $uid ?>" class="profile-nav-link">🗂 Log Consultation</a>
                <a href="?page=messages&uid=<?= $uid ?>" class="profile-nav-link">✉️ Send Message</a>
            </div>
        </div>
    </div>

    <div>
        <div class="card mb">
            <div class="card-head">
                <div class="card-title">🏋️ Workout History</div>
                <span class="badge bg-muted"><?= count($userWorkouts) ?> sessions</span>
            </div>
            <?php if (empty($userWorkouts)): ?>
            <div class="empty-state" style="padding:1.5rem"><div class="empty-icon">🏋️</div><div class="empty-title">No workouts logged yet</div></div>
            <?php else: foreach ($userWorkouts as $w): ?>
            <div style="display:flex;align-items:center;gap:1rem;padding:.65rem 0;border-bottom:1px solid rgba(255,255,255,.04)">
                <div style="width:36px;height:36px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0">🏋️</div>
                <div style="flex:1">
                    <div style="font-size:.875rem;font-weight:600"><?= htmlspecialchars($w['title']) ?></div>
                    <div style="font-size:.72rem;color:var(--text-3)"><?= ucfirst($w['category']??'—') ?> · <?= $w['duration_minutes'] ?> min</div>
                </div>
                <div style="font-size:.72rem;color:var(--text-3);font-family:var(--mono)"><?= date('M j',strtotime($w['created_at'])) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if ($isDietitian && $tdee): ?>
        <div class="card">
            <div class="card-head"><div class="card-title">🔥 Nutrition Profile</div></div>
            <?php
            $protein = round($u['weight'] * 2.2);
            $fat     = round($tdee * 0.28 / 9);
            $carbs   = round(($tdee - $protein*4 - $fat*9) / 4);
            ?>
            <div class="macro-grid">
                <div class="macro-box"><div class="macro-val" style="color:var(--accent)"><?= number_format($tdee) ?></div><div class="macro-lbl">TDEE kcal</div></div>
                <div class="macro-box"><div class="macro-val" style="color:#93c5fd"><?= $protein ?>g</div><div class="macro-lbl">Protein</div></div>
                <div class="macro-box"><div class="macro-val" style="color:#6ee7b7"><?= $carbs ?>g</div><div class="macro-lbl">Carbs</div></div>
                <div class="macro-box"><div class="macro-val" style="color:#fcd34d"><?= $fat ?>g</div><div class="macro-lbl">Fat</div></div>
            </div>
            <div class="d-row"><span class="d-key">Bulk target</span><span class="d-val"><?= number_format($tdee+300) ?> kcal</span></div>
            <div class="d-row"><span class="d-key">Cut target</span><span class="d-val"><?= number_format($tdee-400) ?> kcal</span></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  NOTES
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'notes' && $selectedUser):
$u = $selectedUser;
$filterType    = $_GET['filter'] ?? 'all';
$filteredNotes = $filterType === 'all' ? $userNotes : array_filter($userNotes,fn($n)=>$n['note_type']===$filterType);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">📝 Notes — <?= htmlspecialchars($u['full_name']) ?></h1>
        <p class="page-sub"><a href="?page=profile&uid=<?= $uid ?>" style="color:var(--accent);text-decoration:none">← Back to profile</a></p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-note')">+ Add Note</button>
</div>

<div class="aside-layout">
    <div>
        <div class="card mb">
            <div class="card-head"><div class="card-title">Client</div></div>
            <div class="cc-top">
                <div class="cc-av" style="width:40px;height:40px;font-size:.95rem"><?= $avatarLetter($u['full_name']) ?></div>
                <div><div class="cc-name"><?= htmlspecialchars($u['full_name']) ?></div><div class="cc-email"><?= htmlspecialchars($u['email']) ?></div></div>
            </div>
            <div class="d-row"><span class="d-key">My notes</span><span class="d-val"><?= count(array_filter($userNotes,fn($n)=>$n['staff_id']==$staffId)) ?></span></div>
            <div class="d-row"><span class="d-key">All notes</span><span class="d-val"><?= count($userNotes) ?></span></div>
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:.85rem;font-size:.82rem">Filter by type</div>
            <?php foreach(['all','general','dietary','medical','workout','followup'] as $t): ?>
            <a href="?page=notes&uid=<?= $uid ?>&filter=<?= $t ?>" class="btn-sm" style="display:block;margin-bottom:.35rem;width:100%;justify-content:flex-start;<?= $filterType===$t?'border-color:rgba(var(--accent-rgb),.4);color:var(--accent)':'' ?>"><?= ucfirst($t) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <?php if (empty($filteredNotes)): ?>
        <div class="empty-state"><div class="empty-icon">📝</div><div class="empty-title">No notes yet</div><div class="empty-text">Add the first note for this client.</div></div>
        <?php else: foreach($filteredNotes as $note): ?>
        <div class="note-item">
            <div class="note-header">
                <span class="note-type note-type-<?= $note['note_type'] ?>"><?= ucfirst($note['note_type']) ?></span>
                <?php if ($note['is_private']): ?><span class="note-private">🔒 Private</span><?php endif; ?>
                <span class="note-meta">by <?= htmlspecialchars($note['staff_name']??'Staff') ?></span>
                <span class="note-time"><?= date('M j, Y · g:i A',strtotime($note['created_at'])) ?></span>
                <?php if ($note['staff_id']==$staffId): ?>
                <a href="?page=notes&uid=<?= $uid ?>&action=delete&rid=<?= $note['id'] ?>" class="act del" onclick="return confirm('Delete this note?')">🗑️</a>
                <?php endif; ?>
            </div>
            <div class="note-body"><?= nl2br(htmlspecialchars($note['content'])) ?></div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal-overlay" id="modal-note">
    <div class="modal">
        <div class="modal-head"><div class="modal-title">📝 Add Note for <?= htmlspecialchars($u['full_name']) ?></div><button class="modal-close" onclick="closeModal('modal-note')">✕</button></div>
        <form method="POST" action="?page=notes&action=store">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Note Type</label>
                    <select name="note_type">
                        <option value="general">General</option>
                        <?php if ($isDietitian): ?><option value="dietary">Dietary</option><option value="medical">Medical</option><?php endif; ?>
                        <option value="workout">Workout</option>
                        <option value="followup">Follow-up</option>
                    </select>
                </div>
                <div class="field row" style="padding-top:1.4rem">
                    <input type="checkbox" name="is_private" id="isPrivate">
                    <label for="isPrivate">Private (only I can see)</label>
                </div>
                <div class="field full">
                    <label>Note Content *</label>
                    <textarea name="content" required placeholder="Write your observation or recommendation…" style="min-height:130px"></textarea>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('modal-note')">Cancel</button><button type="submit" class="btn btn-primary">Save Note</button></div>
        </form>
    </div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  MEAL PLANS
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'meals' && $selectedUser && $isDietitian):
$u = $selectedUser;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">🥗 Meal Plans — <?= htmlspecialchars($u['full_name']) ?></h1>
        <p class="page-sub"><a href="?page=profile&uid=<?= $uid ?>" style="color:var(--accent);text-decoration:none">← Back to profile</a></p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-meal')">+ Create Meal Plan</button>
</div>

<?php if (empty($userMeals)): ?>
<div class="empty-state"><div class="empty-icon">🥗</div><div class="empty-title">No meal plans yet</div><div class="empty-text">Create the first plan for this client.</div></div>
<?php else: foreach ($userMeals as $m): ?>
<div class="meal-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.5rem">
        <div>
            <div style="font-weight:700;font-size:.9rem"><?= htmlspecialchars($m['title']) ?></div>
            <div style="font-size:.73rem;color:var(--text-3);margin-top:2px">Week <?= $m['week_number'] ?> · <?= ucfirst(str_replace('_',' ',$m['goal'])) ?> · by <?= htmlspecialchars($m['staff_name']??'Staff') ?></div>
        </div>
        <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0">
            <span class="badge <?= $m['is_active']?'bg-green':'bg-muted' ?>"><?= $m['is_active']?'Active':'Inactive' ?></span>
            <?php if ($m['staff_id']==$staffId): ?>
            <a href="?page=meals&uid=<?= $uid ?>&action=toggle&rid=<?= $m['id'] ?>" class="act" title="Toggle">🔄</a>
            <a href="?page=meals&uid=<?= $uid ?>&action=delete&rid=<?= $m['id'] ?>" class="act del" onclick="return confirm('Delete this plan?')">🗑️</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="macro-grid">
        <div class="macro-box"><div class="macro-val" style="color:var(--accent)"><?= number_format($m['calories']) ?></div><div class="macro-lbl">Calories</div></div>
        <div class="macro-box"><div class="macro-val" style="color:#93c5fd"><?= $m['protein_g'] ?>g</div><div class="macro-lbl">Protein</div></div>
        <div class="macro-box"><div class="macro-val" style="color:#6ee7b7"><?= $m['carbs_g'] ?>g</div><div class="macro-lbl">Carbs</div></div>
        <div class="macro-box"><div class="macro-val" style="color:#fcd34d"><?= $m['fat_g'] ?>g</div><div class="macro-lbl">Fat</div></div>
    </div>
    <?php if ($m['plan_text']): ?><div class="plan-text"><?= htmlspecialchars($m['plan_text']) ?></div><?php endif; ?>
    <div style="font-size:.7rem;color:var(--text-3);margin-top:.65rem"><?= date('M j, Y',strtotime($m['created_at'])) ?></div>
</div>
<?php endforeach; endif; ?>

<div class="modal-overlay" id="modal-meal">
    <div class="modal">
        <div class="modal-head"><div class="modal-title">🥗 Create Meal Plan — <?= htmlspecialchars($u['full_name']) ?></div><button class="modal-close" onclick="closeModal('modal-meal')">✕</button></div>
        <form method="POST" action="?page=meals&action=store">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <div class="form-grid">
                <div class="field full"><label>Plan Title *</label><input type="text" name="title" required placeholder="e.g. Week 1 Lean Bulk Plan"></div>
                <div class="field">
                    <label>Goal</label>
                    <select name="goal">
                        <option value="maintenance">Maintenance</option>
                        <option value="weight_loss">Weight Loss</option>
                        <option value="muscle_gain">Muscle Gain</option>
                        <option value="endurance">Endurance</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div class="field"><label>Week Number</label><input type="number" name="week_number" value="1" min="1" max="52"></div>
                <div class="field"><label>Total Calories / day</label><input type="number" name="calories" placeholder="2400"></div>
                <div class="field"><label>Protein (g)</label><input type="number" name="protein_g" placeholder="180"></div>
                <div class="field"><label>Carbs (g)</label><input type="number" name="carbs_g" placeholder="260"></div>
                <div class="field"><label>Fat (g)</label><input type="number" name="fat_g" placeholder="70"></div>
                <div class="field full"><label>Detailed Plan</label><textarea name="plan_text" style="min-height:120px" placeholder="Breakfast: Oats + eggs&#10;Lunch: Rice + chicken&#10;Dinner: Salad + fish"></textarea></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('modal-meal')">Cancel</button><button type="submit" class="btn btn-primary">Create Plan</button></div>
        </form>
    </div>
</div>

<?php elseif ($page === 'meals' && !$isDietitian): ?>
<div class="empty-state"><div class="empty-icon">🔒</div><div class="empty-title">Access Restricted</div><div class="empty-text">Meal plan creation is available to dietitians only.</div></div>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  CONSULTATIONS
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'consult' && $selectedUser):
$u = $selectedUser;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">🗂 Consultations — <?= htmlspecialchars($u['full_name']) ?></h1>
        <p class="page-sub"><a href="?page=profile&uid=<?= $uid ?>" style="color:var(--accent);text-decoration:none">← Back to profile</a></p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-consult')">+ Log Consultation</button>
</div>

<?php if (empty($userConsults)): ?>
<div class="empty-state"><div class="empty-icon">🗂</div><div class="empty-title">No consultations logged</div><div class="empty-text">Log the first session with this client.</div></div>
<?php else: foreach ($userConsults as $c): ?>
<div class="consult-item <?= $c['status'] ?>">
    <div class="consult-head">
        <div>
            <div class="consult-title"><?= htmlspecialchars($c['title']) ?></div>
            <div class="consult-meta">
                <?= ucfirst($c['type']) ?> · by <?= htmlspecialchars($c['staff_name']??'Staff') ?>
                <?php if ($c['scheduled_at']): ?> · <?= date('M j, Y g:i A',strtotime($c['scheduled_at'])) ?><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;flex-shrink:0">
            <?php
            $statusClass = ['pending'=>'bg-gold','in_progress'=>'bg-accent','completed'=>'bg-green','cancelled'=>'bg-muted'];
            ?>
            <span class="badge <?= $statusClass[$c['status']]??'bg-muted' ?>"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></span>
            <?php if ($c['staff_id']==$staffId && $c['status']!=='completed'): ?>
            <a href="?page=consult&uid=<?= $uid ?>&action=complete&rid=<?= $c['id'] ?>" class="btn-sm" style="color:#6ee7b7;border-color:rgba(16,185,129,.3)" onclick="return confirm('Mark as completed?')">✅ Complete</a>
            <?php endif; ?>
            <?php if ($c['staff_id']==$staffId): ?><a href="?page=consult&uid=<?= $uid ?>&action=delete&rid=<?= $c['id'] ?>" class="act del" onclick="return confirm('Delete?')">🗑️</a><?php endif; ?>
        </div>
    </div>
    <?php if ($c['summary']): ?><div class="consult-body"><strong style="color:var(--text)">Summary:</strong> <?= nl2br(htmlspecialchars($c['summary'])) ?></div><?php endif; ?>
    <?php if ($c['recommendations']): ?><div class="consult-body" style="margin-top:.4rem"><strong style="color:var(--text)">Recommendations:</strong> <?= nl2br(htmlspecialchars($c['recommendations'])) ?></div><?php endif; ?>
    <?php if ($c['completed_at']): ?><div style="font-size:.7rem;color:var(--green);margin-top:.45rem">✅ Completed <?= date('M j, Y',strtotime($c['completed_at'])) ?></div><?php endif; ?>
</div>
<?php endforeach; endif; ?>

<div class="modal-overlay" id="modal-consult">
    <div class="modal">
        <div class="modal-head"><div class="modal-title">🗂 Log Consultation — <?= htmlspecialchars($u['full_name']) ?></div><button class="modal-close" onclick="closeModal('modal-consult')">✕</button></div>
        <form method="POST" action="?page=consult&action=store">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <div class="form-grid">
                <div class="field full"><label>Session Title *</label><input type="text" name="title" required placeholder="e.g. Initial Assessment — Week 1"></div>
                <div class="field">
                    <label>Session Type</label>
                    <select name="type"><option value="initial">Initial Assessment</option><option value="followup">Follow-up</option><option value="assessment">Progress Assessment</option><option value="emergency">Emergency</option></select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="status"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select>
                </div>
                <div class="field full"><label>Scheduled Date & Time</label><input type="datetime-local" name="scheduled_at"></div>
                <div class="field full"><label>Session Summary</label><textarea name="summary" placeholder="What was discussed, observations…"></textarea></div>
                <div class="field full"><label>Recommendations</label><textarea name="recommendations" placeholder="Action items, next steps…"></textarea></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('modal-consult')">Cancel</button><button type="submit" class="btn btn-primary">Save Consultation</button></div>
        </form>
    </div>
</div>

<?php

elseif ($page === 'messages'):
$msgTab = $_GET['tab'] ?? 'inbox';
$displayMsgs = $msgTab === 'sent' ? $sentItems : $inbox;
?>
<div class="page-header">
    <div><h1 class="page-title">✉️ Messages</h1><p class="page-sub">Communicate with premium clients</p></div>
    <button class="btn btn-primary" onclick="openModal('modal-compose')">+ New Message</button>
</div>

<div class="tabs">
    <a class="tab <?= $msgTab==='inbox'?'active':'' ?>" href="?page=messages&tab=inbox">
        📥 Inbox <?php if($unreadCount>0): ?><span class="nav-badge" style="margin-left:.4rem"><?= $unreadCount ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $msgTab==='sent'?'active':'' ?>" href="?page=messages&tab=sent">📤 Sent</a>
</div>

<?php if (empty($displayMsgs)): ?>
<div class="empty-state"><div class="empty-icon">✉️</div><div class="empty-title">No messages</div><div class="empty-text"><?= $msgTab==='inbox'?'Your inbox is empty.':'No sent messages yet.' ?></div></div>
<?php else: foreach ($displayMsgs as $msg):
    $fromName = $msgTab==='sent' ? ($msg['to_name']??'User') : ($msg['from_name']??'User');
    $isUnread = !$msg['is_read'] && $msgTab==='inbox';
?>
<div class="msg-item <?= $isUnread?'unread':'' ?>">
    <div class="msg-av"><?= strtoupper(substr($fromName,0,1)) ?></div>
    <div style="flex:1;overflow:hidden">
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="msg-from"><?= $msgTab==='sent'?'To: ':'' ?><?= htmlspecialchars($fromName) ?></span>
            <?php if ($isUnread): ?><span class="badge bg-accent" style="font-size:.63rem">New</span><?php endif; ?>
        </div>
        <div class="msg-subject"><?= htmlspecialchars($msg['subject']??'(no subject)') ?></div>
        <div class="msg-preview"><?= htmlspecialchars($msg['body']) ?></div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;flex-shrink:0">
        <span class="msg-time"><?= date('M j, g:i A',strtotime($msg['created_at'])) ?></span>
        <?php if ($isUnread): ?><a href="?page=messages&tab=inbox&action=read&rid=<?= $msg['id'] ?>" class="btn-sm">Mark read</a><?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<div class="modal-overlay <?= $msgUser?'open':'' ?>" id="modal-compose">
    <div class="modal">
        <div class="modal-head"><div class="modal-title">✉️ New Message</div><button class="modal-close" onclick="closeModal('modal-compose')">✕</button></div>
        <form method="POST" action="?page=messages&action=send">
            <div class="form-grid">
                <div class="field full">
                    <label>Send To (Premium Client) *</label>
                    <select name="to_user" required>
                        <option value="">Select client…</option>
                        <?php foreach ($premiumUsers as $pu): ?>
                        <option value="<?= $pu['id'] ?>" <?= $pu['id']==$uid?'selected':'' ?>><?= htmlspecialchars($pu['full_name']) ?> (<?= htmlspecialchars($pu['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field full"><label>Subject</label><input type="text" name="subject" placeholder="e.g. Your Week 2 Nutrition Plan"></div>
                <div class="field full"><label>Message *</label><textarea name="body" required placeholder="Write your message…" style="min-height:120px"></textarea></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeModal('modal-compose')">Cancel</button><button type="submit" class="btn btn-primary">📤 Send</button></div>
        </form>
    </div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════════════
//  SETTINGS
// ════════════════════════════════════════════════════════════════════════════
elseif ($page === 'settings'):
if (!isset($selfRecord) || !$selfRecord) {
    try { $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?",[$staffId]); } catch(Exception $e){}
}
?>
<div class="page-header"><div><h1 class="page-title">⚙️ Settings</h1><p class="page-sub">Manage your staff account</p></div></div>

<?php if ($flash['msg']): ?>
<div class="flash flash-<?= $flash['type'] ?>"><?= $flash['type']==='success'?'✓':'⚠' ?> <?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<div class="grid2">
    <div class="card">
        <div class="card-head"><div class="card-title">👤 Update Profile</div></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_profile">
            <div class="field" style="margin-bottom:1rem"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($selfRecord['full_name']??$staffName) ?>"></div>
            <div class="field" style="margin-bottom:1rem"><label>Email (read-only)</label><input type="email" value="<?= htmlspecialchars($staffEmail) ?>" disabled style="opacity:.5"></div>
            <div class="field" style="margin-bottom:1rem"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($selfRecord['phone']??'') ?>"></div>
            <div class="field" style="margin-bottom:1.2rem"><label>Bio / Notes</label><textarea name="notes"><?= htmlspecialchars($selfRecord['notes']??'') ?></textarea></div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><div class="card-title">🔐 Change Password</div></div>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="field" style="margin-bottom:1rem"><label>Current Password</label><input type="password" name="current_password" required></div>
            <div class="field" style="margin-bottom:1rem"><label>New Password (min 8 chars)</label><input type="password" name="new_password" required minlength="8"></div>
            <div class="field" style="margin-bottom:1.2rem"><label>Confirm New Password</label><input type="password" name="confirm_password" required></div>
            <button type="submit" class="btn btn-danger">🔒 Change Password</button>
        </form>
    </div>
    <div class="card span-full">
        <div class="card-head"><div class="card-title" style="color:var(--red)">⚠ Danger Zone</div></div>
        <p style="font-size:.875rem;color:var(--text-3);margin-bottom:1rem">Logging out ends your current session immediately.</p>
        <a href="?page=logout" class="btn btn-danger" onclick="return confirm('Log out now?')">🚪 Logout</a>
    </div>
</div>

<?php endif; ?>

    </div><!-- /.page -->
</div><!-- /.layout -->

<script>
// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
    const sb = document.getElementById('sidebar');
    const toggle = document.querySelector('.topbar-toggle');
    if (!sb || window.innerWidth > 900) return;
    if (!sb.contains(e.target) && toggle && !toggle.contains(e.target)) sb.classList.remove('open');
});

// Modal helpers
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// Auto-dismiss flash
document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 4500);
});
</script>
</body>
</html>