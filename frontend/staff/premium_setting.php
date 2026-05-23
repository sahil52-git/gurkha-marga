<?php
// frontend/staff/premium_setting.php  —  Staff account settings + avatar
session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['staff_logged_in'])) { header('Location: ../auth/login.php'); exit(); }

$staffId    = (int)$_SESSION['staff_id'];
$staffName  = $_SESSION['staff_name']  ?? '';
$staffRole  = $_SESSION['staff_role']  ?? 'consultant';
$staffEmail = $_SESSION['staff_email'] ?? '';

// ── Bootstrap: add avatar columns to admin_staff if missing ───────────────
foreach ([
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS avatar_type ENUM('initial','photo','ai') NOT NULL DEFAULT 'initial'",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS avatar_config TEXT DEFAULT NULL",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS phone VARCHAR(40) DEFAULT NULL",
    "ALTER TABLE admin_staff ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL",
] as $ddl) { try { query($ddl, []); } catch(Exception $e) {} }

// ── Upload directory ───────────────────────────────────────────────────────
$uploadDir = BASE_PATH . '/frontend/uploads/staff_avatars/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ── Unread count ───────────────────────────────────────────────────────────
$totalUnread = 0;
try {
    $r = fetchOne(
        "SELECT COUNT(*) AS cnt FROM chat_messages
         WHERE consultant_id = ? AND sender_type = 'user' AND is_read = 0",
        [$staffId]
    );
    $totalUnread = (int)($r['cnt'] ?? 0);
} catch(Exception $e) {}

// ── Load own staff record ──────────────────────────────────────────────────
$selfRecord = null;
try { $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id = ?", [$staffId]); } catch(Exception $e) {}

$flash = ['type' => '', 'msg' => ''];

// ════════════════════════════════════════════════════════════════════════════
//  POST HANDLERS
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Upload profile photo ──────────────────────────────────────────────
    if ($_POST['action'] === 'upload_photo') {
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['profile_photo'];
            $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($file['size'] > 5 * 1024 * 1024) {
                $flash = ['type'=>'error','msg'=>'Image must be under 5MB.'];
            } elseif (!in_array($mime, $allowed)) {
                $flash = ['type'=>'error','msg'=>'Only JPG, PNG, WEBP or GIF allowed.'];
            } else {
                // Delete old
                if (!empty($selfRecord['profile_photo'])) {
                    $old = $uploadDir . $selfRecord['profile_photo'];
                    if (file_exists($old)) unlink($old);
                }
                $ext  = match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',default=>'jpg'};
                $fname = 'staff_' . $staffId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
                    query("UPDATE admin_staff SET profile_photo=?, avatar_type='photo', avatar_config=NULL WHERE id=?", [$fname, $staffId]);
                    $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]);
                    $flash = ['type'=>'success','msg'=>'Profile photo updated!'];
                } else {
                    $flash = ['type'=>'error','msg'=>'Upload failed. Check server permissions.'];
                }
            }
        } else {
            $flash = ['type'=>'error','msg'=>'No file selected or upload error.'];
        }
    }

    // ── Save AI avatar ────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'save_ai_avatar') {
        $config = json_decode($_POST['avatar_config'] ?? '{}', true);
        if ($config && is_array($config)) {
            // Delete old photo file if exists
            if (!empty($selfRecord['profile_photo'])) {
                $old = $uploadDir . $selfRecord['profile_photo'];
                if (file_exists($old)) unlink($old);
            }
            query("UPDATE admin_staff SET avatar_config=?, avatar_type='ai', profile_photo=NULL WHERE id=?",
                [json_encode($config), $staffId]);
            $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]);
            $flash = ['type'=>'success','msg'=>'AI Avatar saved!'];
        } else {
            $flash = ['type'=>'error','msg'=>'Invalid avatar configuration.'];
        }
    }

    // ── Remove photo ──────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'remove_photo') {
        if (!empty($selfRecord['profile_photo'])) {
            $old = $uploadDir . $selfRecord['profile_photo'];
            if (file_exists($old)) unlink($old);
        }
        query("UPDATE admin_staff SET profile_photo=NULL, avatar_type='initial', avatar_config=NULL WHERE id=?", [$staffId]);
        $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]);
        $flash = ['type'=>'success','msg'=>'Profile photo removed.'];
    }

    // ── Update profile ────────────────────────────────────────────────────
    elseif ($_POST['action'] === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $bio      = trim($_POST['bio']       ?? '');
        if (!$fullName) {
            $flash = ['type'=>'error','msg'=>'Full name is required.'];
        } else {
            try {
                query("UPDATE admin_staff SET full_name=?, phone=?, notes=? WHERE id=?",
                    [$fullName, $phone, $bio, $staffId]);
                $_SESSION['staff_name'] = $fullName;
                $staffName = $fullName;
                $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]);
                $flash = ['type'=>'success','msg'=>'Profile updated successfully.'];
            } catch(Exception $e) {
                $flash = ['type'=>'error','msg'=>'Could not update profile. Please try again.'];
            }
        }
    }

    // ── Change password ───────────────────────────────────────────────────
    elseif ($_POST['action'] === 'change_password') {
        $cur     = $_POST['current_password']  ?? '';
        $new     = $_POST['new_password']      ?? '';
        $confirm = $_POST['confirm_password']  ?? '';
        if (!$selfRecord) {
            $flash = ['type'=>'error','msg'=>'Staff record not found.'];
        } elseif (!password_verify($cur, $selfRecord['password'] ?? '')) {
            $flash = ['type'=>'error','msg'=>'Current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $flash = ['type'=>'error','msg'=>'New password must be at least 8 characters.'];
        } elseif ($new !== $confirm) {
            $flash = ['type'=>'error','msg'=>'Passwords do not match.'];
        } else {
            try {
                query("UPDATE admin_staff SET password=? WHERE id=?",
                    [password_hash($new, PASSWORD_DEFAULT), $staffId]);
                $flash = ['type'=>'success','msg'=>'Password changed successfully.'];
            } catch(Exception $e) {
                $flash = ['type'=>'error','msg'=>'Could not change password.'];
            }
        }
    }
}

// ── Re-fetch after mutations ───────────────────────────────────────────────
if (!$selfRecord) {
    try { $selfRecord = fetchOne("SELECT * FROM admin_staff WHERE id=?", [$staffId]); } catch(Exception $e) {}
}

// ── Avatar helpers ─────────────────────────────────────────────────────────
$avatarType   = $selfRecord['avatar_type']   ?? 'initial';
$profilePhoto = $selfRecord['profile_photo'] ?? null;
$avatarConfig = !empty($selfRecord['avatar_config']) ? json_decode($selfRecord['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/staff_avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';
$initials     = strtoupper(substr(trim($staffName), 0, 1));
$joinedDate   = $selfRecord ? date('M Y', strtotime($selfRecord['created_at'] ?? 'now')) : '—';

// ── Accent by role ─────────────────────────────────────────────────────────
$accentMap = [
    'dietitian'  => ['#06b6d4','6,182,212'],
    'consultant' => ['#8b5cf6','139,92,246'],
    'admin'      => ['#3b82f6','59,130,246'],
    'superadmin' => ['#3b82f6','59,130,246'],
];
[$accentHex, $accentRgb] = $accentMap[$staffRole] ?? $accentMap['consultant'];

function av(string $n): string { return strtoupper(substr(trim($n), 0, 1)); }

// ── Determine which tab to open after POST ─────────────────────────────────
$activeTab = 'profile';
if ($flash['msg'] && isset($_POST['action'])) {
    $activeTab = match($_POST['action']) {
        'change_password' => 'security',
        'update_profile'  => 'profile',
        default           => 'profile',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings — Gurkha Marga Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --accent:     <?= $accentHex ?>;
    --accent-rgb: <?= $accentRgb ?>;
    --gold:       #f59e0b;
    --green:      #10b981;
    --red:        #ef4444;
    --purple:     #8b5cf6;
    --bg:         #0f172a;
    --surface:    rgba(30,41,59,.55);
    --border:     rgba(255,255,255,.06);
    --border-hi:  rgba(255,255,255,.11);
    --text:       #f8fafc;
    --text-2:     #cbd5e1;
    --text-3:     #64748b;
    --font:       'Poppins',sans-serif;
    --mono:       'Courier New',monospace;
    --r:          10px;
    --sidebar:    252px;
}
html,body { min-height:100vh; background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#0f172a 100%); color:var(--text); font-family:var(--font); }
body::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(var(--accent-rgb),.05),transparent); pointer-events:none; z-index:0; }
::-webkit-scrollbar { width:3px; } ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.08); border-radius:2px; }

/* ── LAYOUT ── */
.layout { display:flex; min-height:100vh; position:relative; z-index:1; }

/* ── SIDEBAR ── */
.sb { width:var(--sidebar); flex-shrink:0; background:rgba(10,17,32,.97); border-right:1px solid var(--border); display:flex; flex-direction:column; position:sticky; top:0; height:100vh; overflow-y:auto; }
.sb-logo { padding:1.3rem 1.1rem .9rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:.75rem; }
.sb-mark { width:36px; height:36px; border-radius:8px; flex-shrink:0; display:flex; align-items:center; justify-content:center; overflow:hidden; background:transparent; }
.sb-mark img { width:36px; height:36px; object-fit:contain; }
.sb-brand { font-size:.95rem; font-weight:800; color:var(--gold); letter-spacing:-.02em; }
.sb-sub { font-size:.55rem; color:var(--text-3); letter-spacing:.14em; text-transform:uppercase; margin-top:1px; }
.sb-user { margin:.75rem .8rem; background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:var(--r); padding:.65rem .8rem; display:flex; align-items:center; gap:.65rem; }
.sb-av-wrap { width:36px; height:36px; border-radius:50%; flex-shrink:0; overflow:hidden; border:2px solid rgba(var(--accent-rgb),.3); }
.sb-av-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.sb-av-wrap canvas { width:100%; height:100%; display:block; }
.sb-av-init { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--purple)); display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; }
.sb-uname { font-size:.78rem; font-weight:600; }
.sb-urole { font-size:.62rem; color:var(--accent); text-transform:capitalize; }
.sb-pulse { width:6px; height:6px; border-radius:50%; background:var(--green); box-shadow:0 0 5px var(--green); margin-left:auto; animation:pulse 2.5s infinite; flex-shrink:0; }
.sb-nav { flex:1; padding:.25rem 0; }
.nav-sec { font-size:.56rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:rgba(71,85,105,.55); padding:.7rem 1.1rem .25rem; }
.nav-a { display:flex; align-items:center; gap:.65rem; padding:.48rem 1.1rem; color:var(--text-3); text-decoration:none; font-size:.8rem; font-weight:500; border-left:2px solid transparent; transition:all .14s; }
.nav-a:hover { color:var(--text-2); background:rgba(255,255,255,.03); }
.nav-a.active { color:var(--text); background:rgba(255,255,255,.05); border-left-color:var(--accent); }
.nav-a.danger { color:#f87171; } .nav-a.danger:hover { background:rgba(239,68,68,.06); }
.nav-ico { width:14px; text-align:center; flex-shrink:0; opacity:.65; }
.nav-a.active .nav-ico { opacity:1; }
.nav-badge { margin-left:auto; background:var(--red); color:#fff; font-size:.57rem; font-weight:700; padding:.08rem .38rem; border-radius:99px; min-width:16px; text-align:center; }

/* ── MAIN ── */
.main { flex:1; min-width:0; }
.topbar { position:sticky; top:0; z-index:50; background:rgba(10,17,32,.88); backdrop-filter:blur(24px); border-bottom:1px solid var(--border); padding:.75rem 1.75rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; }
.breadcrumb { font-size:.7rem; color:var(--text-3); display:flex; align-items:center; gap:.4rem; }
.breadcrumb a { color:var(--text-2); text-decoration:none; }
.breadcrumb a:hover { color:var(--text); }
.top-chip { font-size:.67rem; padding:.18rem .6rem; border-radius:20px; font-weight:600; }
.top-chip.gold { background:rgba(245,158,11,.09); border:1px solid rgba(245,158,11,.18); color:var(--gold); }

/* ── PAGE ── */
.page { padding:1.6rem 1.75rem 3rem; max-width:960px; }
.pg-hdr { margin-bottom:1.6rem; }
.pg-title { font-size:1.3rem; font-weight:800; letter-spacing:-.03em; }
.pg-sub { font-size:.78rem; color:var(--text-3); margin-top:.3rem; }

/* ── PROFILE HERO ── */
.profile-hero {
    background:var(--surface); border:1px solid var(--border); border-radius:14px;
    padding:1.5rem 1.6rem; display:flex; align-items:center; gap:1.5rem;
    margin-bottom:1.4rem; position:relative; overflow:hidden;
}
.profile-hero::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--accent),rgba(var(--accent-rgb),.2)); }

/* ── MAIN AVATAR DISPLAY (clickable) ── */
.hero-av-wrap {
    width:80px; height:80px; border-radius:50%; flex-shrink:0; cursor:pointer;
    border:3px solid rgba(var(--accent-rgb),.45); box-shadow:0 0 0 4px rgba(var(--accent-rgb),.12);
    position:relative; overflow:hidden; transition:transform .18s, box-shadow .18s;
}
.hero-av-wrap:hover { transform:scale(1.05); box-shadow:0 0 0 7px rgba(var(--accent-rgb),.22); }
.hero-av-wrap img { width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; }
.hero-av-wrap canvas { width:100% !important; height:100% !important; display:block; border-radius:50%; }
.hero-av-init { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:800; background:linear-gradient(135deg,var(--accent),var(--purple)); color:#fff; }
/* Camera overlay */
.av-cam-overlay {
    position:absolute; inset:0; border-radius:50%; background:rgba(0,0,0,.55);
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px;
    opacity:0; transition:opacity .18s; pointer-events:none;
}
.hero-av-wrap:hover .av-cam-overlay { opacity:1; }
.av-cam-overlay svg { width:20px; height:20px; color:#fff; }
.av-cam-overlay span { font-size:.55rem; color:rgba(255,255,255,.85); font-weight:700; letter-spacing:.06em; }

.hero-info { flex:1; }
.hero-name { font-size:1.1rem; font-weight:700; }
.hero-email { font-size:.74rem; color:var(--text-3); margin-top:2px; }
.hero-chips { display:flex; gap:.4rem; margin-top:.6rem; flex-wrap:wrap; }
.h-chip { font-size:.6rem; font-weight:600; padding:.12rem .48rem; border-radius:4px; }
.chip-role { background:rgba(var(--accent-rgb),.09); border:1px solid rgba(var(--accent-rgb),.2); color:var(--accent); text-transform:capitalize; }
.chip-since { background:rgba(255,255,255,.04); border:1px solid var(--border); color:var(--text-3); }
.hero-right { text-align:right; flex-shrink:0; }
.hero-stat-val { font-family:var(--mono); font-size:.78rem; font-weight:600; color:var(--text-2); }
.hero-stat-lbl { font-size:.6rem; color:var(--text-3); margin-top:1px; text-transform:uppercase; letter-spacing:.07em; }

/* ── SETTING TABS ── */
.setting-tabs { display:flex; gap:.2rem; border-bottom:1px solid var(--border); margin-bottom:1.4rem; }
.stab { padding:.55rem 1rem; font-size:.8rem; font-weight:500; color:var(--text-3); cursor:pointer; border-bottom:2px solid transparent; transition:all .14s; background:none; border-top:none; border-left:none; border-right:none; font-family:var(--font); }
.stab:hover { color:var(--text-2); }
.stab.active { color:var(--accent); border-bottom-color:var(--accent); }

/* ── SECTION PANELS ── */
.s-panel { display:none; }
.s-panel.active { display:block; animation:fadeUp .25s ease; }

/* ── CARD ── */
.card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:1.4rem 1.5rem; margin-bottom:1rem; overflow:hidden; position:relative; }
.card::before { content:''; position:absolute; top:0; left:0; right:0; height:1.5px; background:rgba(var(--accent-rgb),.25); }
.card.danger-card::before { background:rgba(239,68,68,.35); }
.card-title { font-size:.88rem; font-weight:700; margin-bottom:.25rem; }
.card-desc { font-size:.76rem; color:var(--text-3); margin-bottom:1.25rem; line-height:1.6; }

/* ── FORM ── */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.9rem; }
.form-grid.one { grid-template-columns:1fr; }
.field { display:flex; flex-direction:column; gap:.3rem; }
.field.full { grid-column:1/-1; }
label, .lbl { font-size:.62rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--text-3); }
input, select, textarea {
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    border-radius:8px; color:var(--text); font-family:var(--font); font-size:.83rem;
    padding:.58rem .85rem; transition:border-color .18s,box-shadow .18s; width:100%;
}
input:focus, select:focus, textarea:focus {
    outline:none; border-color:rgba(var(--accent-rgb),.38);
    box-shadow:0 0 0 3px rgba(var(--accent-rgb),.07);
}
input::placeholder, textarea::placeholder { color:var(--text-3); }
input:disabled { opacity:.38; cursor:not-allowed; }
textarea { resize:vertical; min-height:80px; }

/* ── BUTTONS ── */
.btn { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem 1.1rem; border-radius:8px; font-family:var(--font); font-size:.8rem; font-weight:600; border:none; cursor:pointer; text-decoration:none; transition:all .16s; white-space:nowrap; }
.btn-primary { background:linear-gradient(135deg,var(--accent),rgba(var(--accent-rgb),.7)); color:#fff; }
.btn-primary:hover { opacity:.88; transform:translateY(-1px); box-shadow:0 4px 14px rgba(var(--accent-rgb),.28); }
.btn-ghost { background:rgba(255,255,255,.05); border:1px solid var(--border); color:var(--text-2); }
.btn-ghost:hover { background:rgba(255,255,255,.08); color:var(--text); border-color:var(--border-hi); }
.btn-danger { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#fca5a5; }
.btn-danger:hover { background:rgba(239,68,68,.18); }
.btn-purple { background:linear-gradient(135deg,#8b5cf6,#a855f7); color:#fff; }
.btn-purple:hover { opacity:.88; transform:translateY(-1px); box-shadow:0 4px 14px rgba(139,92,246,.3); }
.btn-row { display:flex; gap:.6rem; margin-top:1.1rem; align-items:center; }

/* ── FLASH ── */
.flash { padding:.72rem .9rem; border-radius:8px; font-size:.8rem; margin-bottom:1.2rem; display:flex; align-items:center; gap:.55rem; animation:fadeUp .3s ease; }
.flash-success { background:rgba(16,185,129,.09); border:1px solid rgba(16,185,129,.18); color:#6ee7b7; }
.flash-error   { background:rgba(239,68,68,.09);  border:1px solid rgba(239,68,68,.18);  color:#fca5a5; }

/* ── PW STRENGTH ── */
.pw-strength { height:3px; border-radius:99px; margin-top:.35rem; background:var(--border); overflow:hidden; }
.pw-bar { height:100%; border-radius:99px; transition:width .3s,background .3s; width:0; }

/* ── INFO ROWS ── */
.info-row { display:flex; justify-content:space-between; align-items:center; padding:.52rem 0; border-bottom:1px solid rgba(255,255,255,.04); font-size:.8rem; }
.info-row:last-child { border-bottom:none; }
.info-key { color:var(--text-3); }
.info-val { font-family:var(--mono); font-size:.76rem; font-weight:500; text-align:right; }

/* ── DANGER ITEMS ── */
.danger-item { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.85rem 0; border-bottom:1px solid rgba(255,255,255,.04); }
.danger-item:last-child { border-bottom:none; }
.danger-label { font-size:.82rem; font-weight:600; color:var(--text-2); margin-bottom:.18rem; }
.danger-desc { font-size:.72rem; color:var(--text-3); line-height:1.55; }

/* ════════════════════════════════════════════════════
   AVATAR PICKER MODAL (identical system to user profile.php)
════════════════════════════════════════════════════ */
.av-modal-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.82); backdrop-filter:blur(8px);
    z-index:9000; display:none; align-items:center; justify-content:center; padding:1rem;
}
.av-modal-overlay.open { display:flex; animation:fadeIn .22s ease; }
.av-modal {
    background:#131c2e; border:1px solid rgba(59,130,246,.25); border-radius:20px;
    width:100%; max-width:780px; max-height:90vh; overflow:hidden;
    display:flex; flex-direction:column; animation:slideUp .25s ease;
}
.av-modal-head {
    padding:1.4rem 1.75rem; border-bottom:1px solid rgba(255,255,255,.07);
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.av-modal-head h3 { font-size:1.1rem; font-weight:700; }
.av-modal-head p { font-size:.8rem; color:var(--text-3); margin-top:2px; }
.av-close { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:8px; color:var(--text-3); width:34px; height:34px; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.av-close:hover { background:rgba(239,68,68,.2); color:#fca5a5; }
.av-method-tabs { display:flex; border-bottom:1px solid rgba(255,255,255,.07); flex-shrink:0; }
.av-method-tab { flex:1; padding:.85rem 1rem; font-size:.85rem; font-weight:600; cursor:pointer; border:none; background:transparent; color:var(--text-3); font-family:var(--font); transition:all .2s; border-bottom:2px solid transparent; display:flex; align-items:center; justify-content:center; gap:7px; }
.av-method-tab:hover { color:var(--text-2); background:rgba(255,255,255,.03); }
.av-method-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.av-method-pane { display:none; flex:1; overflow-y:auto; }
.av-method-pane.active { display:flex; flex-direction:column; }
/* Upload pane */
.upload-zone { margin:2rem; border:2px dashed rgba(59,130,246,.3); border-radius:16px; padding:3rem 2rem; text-align:center; cursor:pointer; transition:all .22s; background:rgba(59,130,246,.04); position:relative; }
.upload-zone:hover, .upload-zone.drag-over { border-color:var(--accent); background:rgba(59,130,246,.1); }
.upload-zone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
.upload-zone h4 { font-size:1rem; font-weight:600; margin:.5rem 0 .4rem; }
.upload-zone p { font-size:.8rem; color:var(--text-3); }
.upload-preview-wrap { margin:0 2rem 2rem; display:none; }
.upload-preview-wrap.show { display:block; }
.upload-preview-img { width:120px; height:120px; border-radius:50%; object-fit:cover; border:3px solid var(--accent); margin:0 auto; display:block; }
.upload-preview-name { text-align:center; font-size:.82rem; color:var(--text-3); margin-top:.75rem; }
.upload-actions { display:flex; gap:.75rem; padding:0 2rem 2rem; }
/* AI builder */
.av-builder { padding:1.5rem 1.75rem; display:flex; gap:1.5rem; flex:1; }
.av-preview-col { flex-shrink:0; width:160px; display:flex; flex-direction:column; align-items:center; gap:1rem; }
.av-preview-canvas { width:140px; height:140px; border-radius:50%; border:3px solid rgba(59,130,246,.4); overflow:hidden; background:#1e2a40; box-shadow:0 8px 30px rgba(0,0,0,.4); }
.av-preview-canvas canvas { width:100% !important; height:100% !important; display:block; }
.av-randomize { background:rgba(139,92,246,.15); border:1px solid rgba(139,92,246,.3); color:#c4b5fd; border-radius:8px; padding:.5rem 1rem; font-size:.75rem; font-weight:600; cursor:pointer; font-family:var(--font); transition:all .15s; width:100%; }
.av-randomize:hover { background:rgba(139,92,246,.25); }
.av-options-col { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:1.1rem; }
.av-option-group label { font-size:.78rem; font-weight:600; color:var(--text-3); display:block; margin-bottom:.55rem; text-transform:uppercase; letter-spacing:.06em; }
.av-swatch-row { display:flex; flex-wrap:wrap; gap:.45rem; }
.av-swatch { width:32px; height:32px; border-radius:8px; cursor:pointer; border:2px solid transparent; transition:all .15s; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.av-swatch:hover { transform:scale(1.12); }
.av-swatch.sel { border-color:#fff; box-shadow:0 0 0 2px rgba(255,255,255,.3); transform:scale(1.1); }
.av-swatch.icon-swatch { background:rgba(255,255,255,.07); border-color:rgba(255,255,255,.1); font-size:1.1rem; }
.av-swatch.icon-swatch.sel { border-color:var(--accent); background:rgba(59,130,246,.15); }
.av-chip-row { display:flex; flex-wrap:wrap; gap:.4rem; }
.av-chip { padding:.3rem .75rem; border-radius:20px; font-size:.75rem; font-weight:600; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.05); color:var(--text-3); cursor:pointer; transition:all .15s; }
.av-chip:hover { border-color:rgba(255,255,255,.22); color:var(--text); }
.av-chip.sel { background:rgba(59,130,246,.2); border-color:var(--accent); color:#93c5fd; }
.av-save-row { padding:1.25rem 1.75rem; border-top:1px solid rgba(255,255,255,.07); display:flex; gap:.75rem; flex-shrink:0; }
.av-options-col::-webkit-scrollbar { width:4px; }
.av-options-col::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:2px; }

/* ── LOGOUT MODAL ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#1e293b; border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:1.75rem 1.5rem 1.25rem; width:100%; max-width:320px; box-shadow:0 20px 60px rgba(0,0,0,.5); }
.modal-title { font-size:.95rem; font-weight:700; margin-bottom:.4rem; }
.modal-body { font-size:.8rem; color:var(--text-2); line-height:1.6; margin-bottom:1.25rem; }
.modal-actions { display:flex; gap:.6rem; justify-content:flex-end; }
.mbtn { padding:.48rem 1.1rem; border-radius:8px; font-size:.8rem; font-weight:600; font-family:var(--font); cursor:pointer; border:1px solid; transition:all .15s; }
.mbtn-cancel { background:transparent; border-color:rgba(255,255,255,.12); color:var(--text-2); }
.mbtn-cancel:hover { background:rgba(255,255,255,.06); color:var(--text); }
.mbtn-confirm { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.28); color:#fca5a5; }
.mbtn-confirm:hover { background:rgba(239,68,68,.22); }

@keyframes fadeUp  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:none} }
@keyframes fadeIn  { from{opacity:0}                             to{opacity:1} }
@keyframes slideUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
@keyframes pulse   { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.45)} 50%{box-shadow:0 0 0 5px rgba(16,185,129,0)} }

@media(max-width:900px) { .sb{display:none} .form-grid{grid-template-columns:1fr} .av-builder{flex-direction:column} .av-preview-col{width:100%;flex-direction:row;gap:2rem} }
@media(max-width:600px) { .page{padding:.9rem} }
</style>
</head>
<body>
<div class="layout">

<!-- ══ SIDEBAR ══ -->
<aside class="sb">
    <div class="sb-logo">
        <div class="sb-mark"><img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="GM"></div>
        <div><div class="sb-brand">Gurkha Marga</div><div class="sb-sub">Staff Portal</div></div>
    </div>
    <div class="sb-user">
        <div class="sb-av-wrap" id="sidebarAvWrap">
            <!-- filled by JS -->
        </div>
        <div>
            <div class="sb-uname"><?= htmlspecialchars($staffName) ?></div>
            <div class="sb-urole"><?= htmlspecialchars($staffRole) ?></div>
        </div>
        <div class="sb-pulse"></div>
    </div>
    <nav class="sb-nav">
        <div class="nav-sec">Main</div>
        <a href="premium_client.php" class="nav-a">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            My Clients
        </a>
        <div class="nav-sec">Communicate</div>
        <a href="premium_message.php" class="nav-a">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            Messages
            <?php if ($totalUnread > 0): ?><span class="nav-badge"><?= $totalUnread ?></span><?php endif; ?>
        </a>
        <div class="nav-sec">Account</div>
        <a href="premium_setting.php" class="nav-a active">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <a href="#" class="nav-a danger" onclick="showLogoutModal();return false;">
            <svg class="nav-ico" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">
    <header class="topbar">
        <nav class="breadcrumb">
            <a href="staff_dashboard.php">Staff Portal</a>
            <span>/</span><span>Settings</span>
        </nav>
        <div style="display:flex;align-items:center;gap:.6rem">
            <span class="top-chip gold"><?= htmlspecialchars(ucfirst($staffRole)) ?></span>
            <span style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)"><?= date('D, d M Y') ?></span>
        </div>
    </header>

    <div class="page">
        <div class="pg-hdr">
            <h1 class="pg-title">Account Settings</h1>
            <p class="pg-sub">Manage your profile, avatar, security, and portal preferences</p>
        </div>

        <?php if ($flash['msg']): ?>
        <div class="flash flash-<?= $flash['type'] ?>" id="flashMsg"><?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif; ?>

        <!-- Profile Hero -->
        <div class="profile-hero">
            <div class="hero-av-wrap" onclick="openAvatarModal()" title="Change profile picture">
                <div id="heroAvInner"><!-- filled by JS --></div>
                <div class="av-cam-overlay">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
                    <span>CHANGE</span>
                </div>
            </div>
            <div class="hero-info">
                <div class="hero-name"><?= htmlspecialchars($staffName) ?></div>
                <div class="hero-email"><?= htmlspecialchars($staffEmail) ?></div>
                <div class="hero-chips">
                    <span class="h-chip chip-role"><?= htmlspecialchars($staffRole) ?></span>
                    <span class="h-chip chip-since">Joined <?= $joinedDate ?></span>
                    <span class="h-chip" style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.18);color:#6ee7b7;font-size:.6rem;font-weight:600;padding:.12rem .48rem;border-radius:4px;">Active</span>
                </div>
            </div>
            <div class="hero-right">
                <button class="btn btn-ghost" onclick="openAvatarModal()" style="font-size:.75rem;padding:.38rem .8rem">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
                    Change Photo
                </button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="setting-tabs">
            <button class="stab <?= $activeTab==='profile'?'active':'' ?>" onclick="switchTab('profile',this)">Profile</button>
            <button class="stab <?= $activeTab==='security'?'active':'' ?>" onclick="switchTab('security',this)">Security</button>
            <button class="stab" onclick="switchTab('info',this)">Account Info</button>
            <button class="stab" onclick="switchTab('danger',this)">Danger Zone</button>
        </div>

        <!-- ── PROFILE TAB ── -->
        <div class="s-panel <?= $activeTab==='profile'?'active':'' ?>" id="tab-profile">
            <div class="card">
                <div class="card-title">Update Profile</div>
                <div class="card-desc">Changes to your name are reflected across the portal immediately.</div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid">
                        <div class="field">
                            <label>Full Name</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($selfRecord['full_name'] ?? $staffName) ?>" required placeholder="Your full name">
                        </div>
                        <div class="field">
                            <label>Email Address</label>
                            <input type="email" value="<?= htmlspecialchars($staffEmail) ?>" disabled>
                        </div>
                        <div class="field">
                            <label>Phone Number</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($selfRecord['phone'] ?? '') ?>" placeholder="+977 9800 000000">
                        </div>
                        <div class="field">
                            <label>Role</label>
                            <input type="text" value="<?= htmlspecialchars(ucfirst($staffRole)) ?>" disabled>
                        </div>
                        <div class="field full">
                            <label>Bio / Notes</label>
                            <textarea name="bio" placeholder="A short bio or internal notes…"><?= htmlspecialchars($selfRecord['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── SECURITY TAB ── -->
        <div class="s-panel <?= $activeTab==='security'?'active':'' ?>" id="tab-security">
            <div class="card">
                <div class="card-title">Change Password</div>
                <div class="card-desc">Use a strong password of at least 8 characters.</div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-grid">
                        <div class="field full">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required placeholder="Enter current password">
                        </div>
                        <div class="field">
                            <label>New Password</label>
                            <input type="password" name="new_password" id="newPw" required minlength="8" placeholder="At least 8 characters" oninput="checkStrength(this.value)">
                            <div class="pw-strength"><div class="pw-bar" id="pwBar"></div></div>
                        </div>
                        <div class="field">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required placeholder="Repeat new password">
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-danger">Change Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── INFO TAB ── -->
        <div class="s-panel" id="tab-info">
            <div class="card">
                <div class="card-title">Account Details</div>
                <div class="card-desc">Read-only summary. Contact an admin to change restricted fields.</div>
                <div class="info-row"><span class="info-key">Staff ID</span><span class="info-val">#<?= $staffId ?></span></div>
                <div class="info-row"><span class="info-key">Full Name</span><span class="info-val"><?= htmlspecialchars($selfRecord['full_name'] ?? $staffName) ?></span></div>
                <div class="info-row"><span class="info-key">Email</span><span class="info-val"><?= htmlspecialchars($staffEmail) ?></span></div>
                <div class="info-row"><span class="info-key">Role</span><span class="info-val"><?= htmlspecialchars(ucfirst($staffRole)) ?></span></div>
                <div class="info-row"><span class="info-key">Phone</span><span class="info-val"><?= htmlspecialchars($selfRecord['phone'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-key">Joined</span><span class="info-val"><?= $joinedDate ?></span></div>
                <div class="info-row"><span class="info-key">Avatar Type</span><span class="info-val"><?= ucfirst($avatarType) ?></span></div>
                <div class="info-row"><span class="info-key">Status</span><span class="info-val" style="color:var(--green)">Active</span></div>
            </div>
        </div>

        <!-- ── DANGER TAB ── -->
        <div class="s-panel" id="tab-danger">
            <div class="card danger-card">
                <div class="card-title" style="color:#fca5a5">Danger Zone</div>
                <div class="card-desc">These actions have immediate effect. Proceed with caution.</div>
                <div class="danger-item">
                    <div>
                        <div class="danger-label">Sign out of portal</div>
                        <div class="danger-desc">End your current session. You will be redirected to the login page.</div>
                    </div>
                    <button class="btn btn-danger" onclick="showLogoutModal()">Log out</button>
                </div>
                <div class="danger-item">
                    <div>
                        <div class="danger-label">Session information</div>
                        <div class="danger-desc">Logged in as <strong style="color:var(--text-2)"><?= htmlspecialchars($staffName) ?></strong> · Role: <strong style="color:var(--accent)"><?= htmlspecialchars(ucfirst($staffRole)) ?></strong></div>
                    </div>
                    <span style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)"><?= date('d M Y') ?></span>
                </div>
            </div>
        </div>

    </div><!-- /.page -->
</div><!-- /.main -->
</div><!-- /.layout -->

<!-- ══ AVATAR PICKER MODAL ══ -->
<div class="av-modal-overlay" id="avatarModal">
    <div class="av-modal">
        <div class="av-modal-head">
            <div><h3>Profile Picture</h3><p>Upload a photo or create your AI avatar</p></div>
            <button class="av-close" onclick="closeAvatarModal()">&#x2715;</button>
        </div>
        <div class="av-method-tabs">
            <button class="av-method-tab active" onclick="switchAvTab('upload',this)">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Upload Photo
            </button>
            <button class="av-method-tab" onclick="switchAvTab('ai',this)">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                AI Avatar Builder
            </button>
            <?php if ($photoUrl || $avatarType === 'ai'): ?>
            <button class="av-method-tab" onclick="switchAvTab('remove',this)" style="color:#f87171">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Remove
            </button>
            <?php endif; ?>
        </div>

        <!-- UPLOAD PANE -->
        <div class="av-method-pane active" id="avpane-upload">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload_photo">
                <div class="upload-zone" id="uploadZone">
                    <input type="file" name="profile_photo" id="photoFileInput" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewUpload(this)">
                    <svg width="40" height="40" fill="none" stroke="rgba(59,130,246,.5)" viewBox="0 0 24 24" style="margin:0 auto .75rem;display:block"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
                    <h4>Drop photo here or click to browse</h4>
                    <p>JPG, PNG, WEBP or GIF &middot; Max 5MB</p>
                </div>
                <div class="upload-preview-wrap" id="uploadPreviewWrap">
                    <img id="uploadPreviewImg" class="upload-preview-img" src="" alt="Preview">
                    <div class="upload-preview-name" id="uploadPreviewName"></div>
                </div>
                <div class="upload-actions">
                    <button type="button" class="btn btn-ghost" onclick="clearUpload()">Clear</button>
                    <button type="submit" class="btn btn-primary" id="uploadSubmitBtn" disabled>Upload &amp; Save</button>
                </div>
            </form>
        </div>

        <!-- AI AVATAR PANE -->
        <div class="av-method-pane" id="avpane-ai">
            <form method="POST" id="aiAvatarForm">
                <input type="hidden" name="action" value="save_ai_avatar">
                <input type="hidden" name="avatar_config" id="avatarConfigInput">
                <div class="av-builder">
                    <div class="av-preview-col">
                        <div class="av-preview-canvas">
                            <canvas id="avatarCanvas" width="200" height="200"></canvas>
                        </div>
                        <button type="button" class="av-randomize" onclick="randomizeAvatar()">&#x1F3B2; Randomize</button>
                    </div>
                    <div class="av-options-col">
                        <div class="av-option-group"><label>Background</label><div class="av-swatch-row" id="opt-bg"></div></div>
                        <div class="av-option-group"><label>Skin Tone</label><div class="av-swatch-row" id="opt-skin"></div></div>
                        <div class="av-option-group"><label>Hair Style</label><div class="av-swatch-row" id="opt-hair-style"></div></div>
                        <div class="av-option-group"><label>Hair Color</label><div class="av-swatch-row" id="opt-hair-color"></div></div>
                        <div class="av-option-group"><label>Eye Color</label><div class="av-swatch-row" id="opt-eyes"></div></div>
                        <div class="av-option-group"><label>Accessories</label><div class="av-chip-row" id="opt-accessories"></div></div>
                        <div class="av-option-group"><label>Military Badge</label><div class="av-swatch-row" id="opt-badge"></div></div>
                    </div>
                </div>
                <div class="av-save-row">
                    <button type="button" class="btn btn-ghost" onclick="closeAvatarModal()">Cancel</button>
                    <button type="button" class="btn btn-purple" onclick="saveAiAvatar()">Save Avatar</button>
                </div>
            </form>
        </div>

        <!-- REMOVE PANE -->
        <div class="av-method-pane" id="avpane-remove">
            <div style="padding:2.5rem;text-align:center">
                <svg width="48" height="48" fill="none" stroke="rgba(239,68,68,.5)" viewBox="0 0 24 24" style="margin:0 auto 1rem;display:block"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                <h4 style="font-size:1rem;margin-bottom:.6rem">Remove Profile Picture</h4>
                <p style="color:var(--text-3);font-size:.85rem;margin-bottom:2rem;max-width:300px;margin-left:auto;margin-right:auto;line-height:1.6">Your profile will show your initial letter. You can upload a new photo or create an AI avatar at any time.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="remove_photo">
                    <button type="submit" class="btn btn-danger">Remove Picture</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Logout Modal -->
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
// ════════════════════════════════════════════════════════
// AVATAR CONFIG FROM PHP
// ════════════════════════════════════════════════════════
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

// ── Avatar option definitions (same as user profile.php) ──
const AV_OPTIONS = {
    bg: [
        {id:'grad1',label:'Ocean',grad:['#1e3a5f','#2563eb']},
        {id:'grad2',label:'Forest',grad:['#14532d','#16a34a']},
        {id:'grad3',label:'Sunset',grad:['#7f1d1d','#dc2626']},
        {id:'grad4',label:'Dusk',grad:['#312e81','#7c3aed']},
        {id:'grad5',label:'Gold',grad:['#78350f','#d97706']},
        {id:'grad6',label:'Teal',grad:['#134e4a','#0d9488']},
        {id:'grad7',label:'Night',grad:['#0f172a','#1e293b']},
        {id:'grad8',label:'Rose',grad:['#4c0519','#e11d48']},
    ],
    skin: [
        {id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},
        {id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},
        {id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'},
    ],
    hairStyle: [
        {id:'none',label:'None',icon:'🧑'},{id:'short',label:'Short',icon:'💆'},
        {id:'medium',label:'Medium',icon:'🧑‍🦰'},{id:'long',label:'Long',icon:'👩‍🦳'},
        {id:'curly',label:'Curly',icon:'🧑‍🦱'},{id:'bald',label:'Bald',icon:'👨‍🦲'},
        {id:'bun',label:'Bun',icon:'💁'},{id:'mohawk',label:'Mohawk',icon:'🤘'},
    ],
    hairColor: [
        {id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},
        {id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},
        {id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},
        {id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'},
    ],
    eyes: [
        {id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},
        {id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},
        {id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'},
    ],
    accessories: [
        {id:'glasses',label:'👓 Glasses'},{id:'sunglasses',label:'🕶 Shades'},
        {id:'beard',label:'🧔 Beard'},{id:'moustache',label:'👨 Moustache'},
        {id:'earring',label:'💎 Earring'},{id:'headband',label:'🩺 Headband'},
    ],
    badge: [
        {id:'none',label:'None',icon:'❌'},{id:'gurkha',label:'Gurkha',icon:'🎖'},
        {id:'star',label:'Star',icon:'⭐'},{id:'military',label:'Army',icon:'🪖'},
        {id:'crown',label:'Crown',icon:'👑'},{id:'lightning',label:'Power',icon:'⚡'},
    ],
};

let avatarState = SAVED_AVATAR_CONFIG ? {...SAVED_AVATAR_CONFIG} : {
    bg:'grad1', skin:'s2', hairStyle:'short', hairColor:'brown',
    eyes:'brown', accessories:[], badge:'none',
};

// ── Build option UI ──
function buildSwatches(containerId, options, stateKey) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '';
    options.forEach(opt => {
        const sw = document.createElement('div');
        sw.className = 'av-swatch';
        if (stateKey === 'bg') {
            sw.style.background = `linear-gradient(135deg,${opt.grad[0]},${opt.grad[1]})`;
            sw.title = opt.label;
            sw.classList.toggle('sel', avatarState.bg === opt.id);
            sw.onclick = () => { avatarState.bg = opt.id; refreshSwatches(containerId, options, stateKey); drawAvatar(); };
        } else if (opt.color) {
            sw.style.background = opt.color;
            sw.title = opt.id;
            if (opt.color === '#F5F5F5') sw.style.border = '2px solid #9CA3AF';
            sw.classList.toggle('sel', avatarState[stateKey] === opt.id);
            sw.onclick = () => { avatarState[stateKey] = opt.id; refreshSwatches(containerId, options, stateKey); drawAvatar(); };
        } else if (opt.icon) {
            sw.className = 'av-swatch icon-swatch';
            sw.textContent = opt.icon;
            sw.title = opt.label;
            sw.classList.toggle('sel', avatarState[stateKey] === opt.id);
            sw.onclick = () => { avatarState[stateKey] = opt.id; refreshSwatches(containerId, options, stateKey); drawAvatar(); };
        }
        el.appendChild(sw);
    });
}
function buildChips(containerId, options, stateKey) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '';
    options.forEach(opt => {
        const chip = document.createElement('div');
        chip.className = 'av-chip' + (avatarState[stateKey]?.includes(opt.id) ? ' sel' : '');
        chip.textContent = opt.label;
        chip.onclick = () => {
            if (!avatarState[stateKey]) avatarState[stateKey] = [];
            const idx = avatarState[stateKey].indexOf(opt.id);
            if (idx > -1) avatarState[stateKey].splice(idx, 1); else avatarState[stateKey].push(opt.id);
            buildChips(containerId, options, stateKey);
            drawAvatar();
        };
        el.appendChild(chip);
    });
}
function refreshSwatches(containerId, options, stateKey) {
    document.querySelectorAll('#' + containerId + ' .av-swatch').forEach((sw, i) => {
        sw.classList.toggle('sel', avatarState[stateKey] === options[i]?.id);
    });
}
function buildAllOptions() {
    buildSwatches('opt-bg', AV_OPTIONS.bg, 'bg');
    buildSwatches('opt-skin', AV_OPTIONS.skin, 'skin');
    buildSwatches('opt-hair-style', AV_OPTIONS.hairStyle, 'hairStyle');
    buildSwatches('opt-hair-color', AV_OPTIONS.hairColor, 'hairColor');
    buildSwatches('opt-eyes', AV_OPTIONS.eyes, 'eyes');
    buildChips('opt-accessories', AV_OPTIONS.accessories, 'accessories');
    buildSwatches('opt-badge', AV_OPTIONS.badge, 'badge');
}

// ── Canvas renderer (same as user profile.php) ──
function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size / 2, cy = size / 2;
    const bg    = AV_OPTIONS.bg.find(o => o.id === state.bg) || AV_OPTIONS.bg[0];
    const skin  = AV_OPTIONS.skin.find(o => o.id === state.skin) || AV_OPTIONS.skin[1];
    const hCol  = AV_OPTIONS.hairColor.find(o => o.id === state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC  = AV_OPTIONS.eyes.find(o => o.id === state.eyes) || AV_OPTIONS.eyes[0];
    const acc   = state.accessories || [];
    const badge = state.badge || 'none';
    const hStyle= state.hairStyle || 'short';

    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0, bg.grad[0]); grad.addColorStop(1, bg.grad[1]);
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(cx, cy, cx, 0, Math.PI*2); ctx.fill();

    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14, cy+32, 28, 22, [4,4,0,0]); ctx.fill();

    const shirtGrad = ctx.createLinearGradient(cx-55, cy+50, cx+55, cy+size);
    shirtGrad.addColorStop(0,'#1e3a5f'); shirtGrad.addColorStop(1,'#0f172a');
    ctx.fillStyle = shirtGrad;
    ctx.beginPath(); ctx.ellipse(cx, cy+62, 58, 28, 0, 0, Math.PI*2); ctx.fill();

    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx, cy+2, 44, 52, 0, 0, Math.PI*2); ctx.fill();

    const faceShadow = ctx.createRadialGradient(cx,cy+8,10,cx,cy+8,44);
    faceShadow.addColorStop(0,'transparent'); faceShadow.addColorStop(1,'rgba(0,0,0,0.08)');
    ctx.fillStyle = faceShadow;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();

    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = 'rgba(0,0,0,0.1)';
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,4,6,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,4,6,0,0,Math.PI*2); ctx.fill();

    ctx.strokeStyle = hCol.color==='#F5F5F5'?'#C0A080':(hCol.color==='#1a1a1a'?'#2d2d2d':hCol.color);
    ctx.lineWidth=3.5; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-28,cy-16); ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx+7,cy-16); ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16); ctx.stroke();

    ctx.fillStyle='#FFFFFF';
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
    ctx.strokeStyle='rgba(0,0,0,0.25)'; ctx.lineWidth=1.2;
    ctx.beginPath(); ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2); ctx.stroke();

    ctx.strokeStyle='rgba(0,0,0,0.18)'; ctx.lineWidth=2; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-3,cy+2); ctx.lineTo(cx,cy+12); ctx.lineTo(cx+3,cy+2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx-9,cy+14); ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14); ctx.stroke();

    ctx.strokeStyle='rgba(0,0,0,0.3)'; ctx.lineWidth=2.5;
    ctx.beginPath(); ctx.moveTo(cx-14,cy+24); ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24); ctx.stroke();
    ctx.fillStyle='rgba(200,120,100,0.35)';
    ctx.beginPath(); ctx.ellipse(cx,cy+25,12,4,0,0,Math.PI); ctx.fill();

    [[-28,14],[28,14]].forEach(([ox,oy])=>{
        const b=ctx.createRadialGradient(cx+ox,cy+oy,0,cx+ox,cy+oy,12);
        b.addColorStop(0,'rgba(255,150,150,0.25)'); b.addColorStop(1,'transparent');
        ctx.fillStyle=b; ctx.beginPath(); ctx.arc(cx+ox,cy+oy,12,0,Math.PI*2); ctx.fill();
    });

    ctx.fillStyle = hCol.color;
    switch(hStyle) {
        case 'short':
            ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
            ctx.fillRect(cx-44,cy-40,88,20);
            ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
            ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
            break;
        case 'medium':
            ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
            ctx.fillRect(cx-46,cy-44,92,22);
            ctx.beginPath(); ctx.ellipse(cx-44,cy+8,9,32,-0.2,-Math.PI/2,Math.PI/2); ctx.fill();
            ctx.beginPath(); ctx.ellipse(cx+44,cy+8,9,32,0.2,-Math.PI/2,Math.PI/2,true); ctx.fill();
            ctx.fillRect(cx-44,cy-2,8,38); ctx.fillRect(cx+36,cy-2,8,38);
            break;
        case 'long':
            ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
            ctx.fillRect(cx-46,cy-44,92,22);
            ctx.beginPath(); ctx.roundRect(cx-50,cy-10,12,75,6); ctx.fill();
            ctx.beginPath(); ctx.roundRect(cx+38,cy-10,12,75,6); ctx.fill();
            break;
        case 'curly':
            for(let a=0;a<Math.PI*2;a+=0.35){const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24;if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}}
            ctx.beginPath(); ctx.ellipse(cx,cy-42,40,18,0,Math.PI,0); ctx.fill();
            break;
        case 'bald':
            const shine=ctx.createRadialGradient(cx-8,cy-35,0,cx,cy-30,30);
            shine.addColorStop(0,'rgba(255,255,255,0.15)'); shine.addColorStop(1,'transparent');
            ctx.fillStyle=shine; ctx.beginPath(); ctx.ellipse(cx,cy-30,42,30,0,0,Math.PI*2); ctx.fill();
            ctx.fillStyle=hCol.color; break;
        case 'bun':
            ctx.beginPath(); ctx.ellipse(cx,cy-40,44,20,0,Math.PI,0); ctx.fill();
            ctx.fillRect(cx-44,cy-43,88,18);
            ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.fill();
            ctx.strokeStyle=hCol.color; ctx.lineWidth=5;
            ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.stroke();
            ctx.fillStyle=hCol.color; break;
        case 'mohawk':
            ctx.beginPath(); ctx.moveTo(cx-10,cy-40); ctx.lineTo(cx,cy-80); ctx.lineTo(cx+10,cy-40); ctx.closePath(); ctx.fill();
            ctx.beginPath(); ctx.roundRect(cx-10,cy-50,20,14,2); ctx.fill(); break;
    }

    if(acc.includes('beard')){const bg2=ctx.createLinearGradient(cx-25,cy+18,cx+25,cy+55);bg2.addColorStop(0,hCol.color);bg2.addColorStop(1,hCol.color+'CC');ctx.fillStyle=bg2;ctx.beginPath();ctx.ellipse(cx,cy+36,30,18,0,0,Math.PI);ctx.fill();ctx.beginPath();ctx.roundRect(cx-30,cy+18,60,20,4);ctx.fill();}
    if(acc.includes('moustache')){ctx.fillStyle=hCol.color;ctx.beginPath();ctx.ellipse(cx-9,cy+18,8,4,-0.2,0,Math.PI*2);ctx.fill();ctx.beginPath();ctx.ellipse(cx+9,cy+18,8,4,0.2,0,Math.PI*2);ctx.fill();}
    if(acc.includes('glasses')){ctx.strokeStyle='#64748b';ctx.lineWidth=2.5;ctx.fillStyle='rgba(147,197,253,0.2)';ctx.beginPath();ctx.roundRect(cx-32,cy-14,24,18,5);ctx.fill();ctx.stroke();ctx.beginPath();ctx.roundRect(cx+8,cy-14,24,18,5);ctx.fill();ctx.stroke();ctx.beginPath();ctx.moveTo(cx-8,cy-5);ctx.lineTo(cx+8,cy-5);ctx.stroke();ctx.beginPath();ctx.moveTo(cx-42,cy-5);ctx.lineTo(cx-46,cy-10);ctx.stroke();ctx.beginPath();ctx.moveTo(cx+42,cy-5);ctx.lineTo(cx+46,cy-10);ctx.stroke();}
    if(acc.includes('sunglasses')){ctx.fillStyle='#111827';ctx.strokeStyle='#374151';ctx.lineWidth=2;ctx.beginPath();ctx.roundRect(cx-34,cy-15,26,16,5);ctx.fill();ctx.stroke();ctx.beginPath();ctx.roundRect(cx+8,cy-15,26,16,5);ctx.fill();ctx.stroke();ctx.beginPath();ctx.moveTo(cx-8,cy-7);ctx.lineTo(cx+8,cy-7);ctx.strokeStyle='#374151';ctx.stroke();}
    if(acc.includes('earring')){ctx.fillStyle='#FFD700';ctx.strokeStyle='#B8860B';ctx.lineWidth=1.5;ctx.beginPath();ctx.arc(cx+44,cy+14,5,0,Math.PI*2);ctx.fill();ctx.stroke();}
    if(acc.includes('headband')){ctx.strokeStyle='#DC2626';ctx.lineWidth=8;ctx.lineCap='round';ctx.beginPath();ctx.ellipse(cx,cy-24,44,22,0,0.15,Math.PI-0.15);ctx.stroke();}

    const badgeMap={gurkha:'🎖',star:'⭐',military:'🪖',crown:'👑',lightning:'⚡'};
    if(badge!=='none'&&badgeMap[badge]){ctx.font='22px serif';ctx.textAlign='right';ctx.textBaseline='bottom';ctx.shadowColor='rgba(0,0,0,0.5)';ctx.shadowBlur=4;ctx.fillText(badgeMap[badge],cx+38,cy+size/2-6);ctx.shadowBlur=0;}

    const ring=ctx.createLinearGradient(0,0,size,size);
    ring.addColorStop(0,bg.grad[0]+'88');ring.addColorStop(1,bg.grad[1]+'88');
    ctx.strokeStyle=ring;ctx.lineWidth=4;
    ctx.beginPath();ctx.arc(cx,cy,cx-2,0,Math.PI*2);ctx.stroke();
}

function drawAvatar() {
    const canvas = document.getElementById('avatarCanvas');
    if (canvas) drawAvatarOnCanvas(canvas, avatarState, 200);
}

// ── Render saved avatar in display slots ──
function renderSavedAvatar() {
    const slots = [
        {id:'heroAvInner', size:80},
        {id:'sidebarAvWrap', size:36},
    ];
    slots.forEach(({id, size}) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '';
        if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
            const img = document.createElement('img');
            img.src = SAVED_PHOTO_URL;
            img.style.cssText = `width:${size}px;height:${size}px;object-fit:cover;border-radius:50%;display:block`;
            img.onerror = () => renderInitial(el, size);
            el.appendChild(img);
        } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CONFIG) {
            const canvas = document.createElement('canvas');
            canvas.style.cssText = `display:block;border-radius:50%`;
            el.appendChild(canvas);
            drawAvatarOnCanvas(canvas, SAVED_AVATAR_CONFIG, size);
        } else {
            renderInitial(el, size);
        }
    });
}
function renderInitial(el, size) {
    el.innerHTML = '';
    const div = document.createElement('div');
    div.style.cssText = `width:${size}px;height:${size}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${size*0.38}px;font-weight:800;background:linear-gradient(135deg,var(--accent),var(--purple));color:#fff`;
    div.textContent = USER_INITIAL;
    el.appendChild(div);
}

// ── Randomize ──
function randomizeAvatar() {
    const pick = arr => arr[Math.floor(Math.random()*arr.length)].id;
    const numAcc = Math.floor(Math.random()*3);
    const pool = [...AV_OPTIONS.accessories];
    const chosen = [];
    for(let i=0;i<numAcc;i++){if(!pool.length)break;const idx=Math.floor(Math.random()*pool.length);chosen.push(pool.splice(idx,1)[0].id);}
    avatarState = {bg:pick(AV_OPTIONS.bg),skin:pick(AV_OPTIONS.skin),hairStyle:pick(AV_OPTIONS.hairStyle),hairColor:pick(AV_OPTIONS.hairColor),eyes:pick(AV_OPTIONS.eyes),accessories:chosen,badge:pick(AV_OPTIONS.badge)};
    buildAllOptions(); drawAvatar();
}

function saveAiAvatar() {
    document.getElementById('avatarConfigInput').value = JSON.stringify(avatarState);
    document.getElementById('aiAvatarForm').submit();
}

// ── Upload helpers ──
function previewUpload(input) {
    const file = input.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('uploadPreviewImg').src = e.target.result;
        document.getElementById('uploadPreviewName').textContent = file.name + ' (' + (file.size/1024/1024).toFixed(2) + ' MB)';
        document.getElementById('uploadPreviewWrap').classList.add('show');
        document.getElementById('uploadSubmitBtn').disabled = false;
        document.getElementById('uploadZone').style.display = 'none';
    };
    reader.readAsDataURL(file);
}
function clearUpload() {
    document.getElementById('photoFileInput').value = '';
    document.getElementById('uploadPreviewWrap').classList.remove('show');
    document.getElementById('uploadSubmitBtn').disabled = true;
    document.getElementById('uploadZone').style.display = '';
}

// ── Modal ──
function openAvatarModal() { document.getElementById('avatarModal').classList.add('open'); buildAllOptions(); drawAvatar(); }
function closeAvatarModal() { document.getElementById('avatarModal').classList.remove('open'); }
function switchAvTab(name, btn) {
    document.querySelectorAll('.av-method-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.av-method-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('avpane-'+name)?.classList.add('active');
    if(name==='ai') drawAvatar();
}

// ── Page utils ──
function switchTab(name, btn) {
    document.querySelectorAll('.s-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.stab').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    if(btn) btn.classList.add('active');
}
function showLogoutModal()  { document.getElementById('logoutModal').classList.add('show'); }
function hideLogoutModal()  { document.getElementById('logoutModal').classList.remove('show'); }

// ── Password strength ──
function checkStrength(pw) {
    const bar = document.getElementById('pwBar'); if(!bar) return;
    let s = 0;
    if(pw.length>=8) s++;
    if(/[A-Z]/.test(pw)) s++;
    if(/[0-9]/.test(pw)) s++;
    if(/[^A-Za-z0-9]/.test(pw)) s++;
    const colors = ['#ef4444','#f59e0b','#3b82f6','#10b981'];
    bar.style.width = (s/4*100)+'%';
    bar.style.background = colors[s-1] || '#ef4444';
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    renderSavedAvatar();

    const zone = document.getElementById('uploadZone');
    if(zone){
        zone.addEventListener('dragover', e=>{e.preventDefault();zone.classList.add('drag-over');});
        zone.addEventListener('dragleave', ()=>zone.classList.remove('drag-over'));
        zone.addEventListener('drop', e=>{
            e.preventDefault(); zone.classList.remove('drag-over');
            const file = e.dataTransfer?.files[0];
            if(file){const input=document.getElementById('photoFileInput');const dt=new DataTransfer();dt.items.add(file);input.files=dt.files;previewUpload(input);}
        });
    }

    document.getElementById('avatarModal').addEventListener('click', function(e){if(e.target===this)closeAvatarModal();});
    document.addEventListener('keydown', e=>{if(e.key==='Escape'){closeAvatarModal();hideLogoutModal();}});

    // Auto-dismiss flash
    const flash = document.getElementById('flashMsg');
    if(flash){setTimeout(()=>{flash.style.transition='opacity .4s';flash.style.opacity='0';setTimeout(()=>flash.remove(),400);},4500);}
});
</script>
</body>
</html>