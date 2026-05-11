<?php
// frontend/users/profile.php
session_start();

define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

// ── Auth guard ──────────────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$success = $error = '';

// ── Force & experience maps ─────────────────────────────────────────────────
$forceMap = [
    'british'   => ['🇬🇧', 'British Army'],
    'nepal'     => ['🇳🇵', 'Nepal Army'],
    'indian'    => ['🇮🇳', 'Indian Army'],
    'singapore' => ['🇸🇬', 'Singapore Police Force'],
    'french'    => ['🇫🇷', 'French Foreign Legion'],
];

// ── Upload directory setup ──────────────────────────────────────────────────
$uploadDir = BASE_PATH . '/frontend/uploads/avatars/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Handle POST actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── UPLOAD profile photo ────────────────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['profile_photo'];
            $maxSize  = 5 * 1024 * 1024; // 5MB
            $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($file['size'] > $maxSize) {
                $error = 'Image must be under 5MB.';
            } elseif (!in_array($mimeType, $allowed)) {
                $error = 'Only JPG, PNG, WEBP, or GIF files are allowed.';
            } else {
                // Delete old photo if exists
                $oldUser = fetchOne("SELECT profile_photo FROM users WHERE id = ?", [$userId]);
                if (!empty($oldUser['profile_photo']) && strpos($oldUser['profile_photo'], 'avatar_') === 0) {
                    $oldPath = $uploadDir . $oldUser['profile_photo'];
                    if (file_exists($oldPath)) unlink($oldPath);
                }

                $ext      = match($mimeType) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg' };
                $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    query("UPDATE users SET profile_photo = ?, avatar_type = 'photo', updated_at = NOW() WHERE id = ?",
                        [$filename, $userId]);
                    $success = 'Profile photo updated!';
                } else {
                    $error = 'Failed to upload. Check server permissions.';
                }
            }
        } else {
            $error = 'No file selected or upload error.';
        }
    }

    // ── SAVE AI avatar config ───────────────────────────────────────────────
    elseif (isset($_POST['action']) && $_POST['action'] === 'save_ai_avatar') {
        $avatarConfig = json_decode($_POST['avatar_config'] ?? '{}', true);
        if ($avatarConfig && is_array($avatarConfig)) {
            $configJson = json_encode($avatarConfig);
            query("UPDATE users SET avatar_config = ?, avatar_type = 'ai', profile_photo = NULL, updated_at = NOW() WHERE id = ?",
                [$configJson, $userId]);
            $success = 'AI Avatar saved!';
        } else {
            $error = 'Invalid avatar configuration.';
        }
    }

    // ── REMOVE photo (revert to initial) ───────────────────────────────────
    elseif (isset($_POST['action']) && $_POST['action'] === 'remove_photo') {
        $oldUser = fetchOne("SELECT profile_photo FROM users WHERE id = ?", [$userId]);
        if (!empty($oldUser['profile_photo'])) {
            $oldPath = $uploadDir . $oldUser['profile_photo'];
            if (file_exists($oldPath)) unlink($oldPath);
        }
        query("UPDATE users SET profile_photo = NULL, avatar_type = 'initial', avatar_config = NULL, updated_at = NOW() WHERE id = ?", [$userId]);
        $success = 'Profile photo removed.';
    }

    // ── UPDATE profile ──────────────────────────────────────────────────────
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $full_name        = trim($_POST['full_name']        ?? '');
        $age              = (int)($_POST['age']             ?? 0);
        $gender           = trim($_POST['gender']           ?? '');
        $height           = (float)($_POST['height']        ?? 0);
        $weight           = (float)($_POST['weight']        ?? 0);
        $target_force     = trim($_POST['target_force']     ?? '');
        $experience_level = trim($_POST['experience_level'] ?? '');

        $allowed_genders    = ['male', 'female', 'other'];
        $allowed_forces     = ['british', 'nepal', 'indian', 'singapore', 'french'];
        $allowed_experience = ['beginner', 'intermediate', 'advanced', 'expert'];

        if (empty($full_name)) {
            $error = 'Full name is required.';
        } elseif ($age < 13 || $age > 120) {
            $error = 'Age must be between 13 and 120.';
        } elseif (!in_array($gender, $allowed_genders, true)) {
            $error = 'Please select a valid gender.';
        } elseif ($height < 100 || $height > 250) {
            $error = 'Height must be between 100 and 250 cm.';
        } elseif ($weight < 30 || $weight > 300) {
            $error = 'Weight must be between 30 and 300 kg.';
        } elseif (!in_array($target_force, $allowed_forces, true)) {
            $error = 'Please select a valid target force.';
        } elseif (!in_array($experience_level, $allowed_experience, true)) {
            $error = 'Please select a valid experience level.';
        } else {
            try {
                query(
                    "UPDATE users SET full_name=?, age=?, gender=?, height=?, weight=?,
                     target_force=?, experience_level=?, updated_at=NOW() WHERE id=?",
                    [$full_name, $age, $gender, $height, $weight, $target_force, $experience_level, $userId]
                );
                $_SESSION['user_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
                $success = 'Profile updated successfully!';
            } catch (Exception $e) {
                $error = 'Failed to update profile. Please try again.';
            }
        }
    }

    // ── CHANGE password ─────────────────────────────────────────────────────
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $user = fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!password_verify($current, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            query("UPDATE users SET password=?, updated_at=NOW() WHERE id=?",
                [password_hash($new, PASSWORD_DEFAULT), $userId]);
            $success = 'Password changed successfully!';
        }
    }

    // ── DELETE account ──────────────────────────────────────────────────────
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
        $confirm_delete = trim($_POST['confirm_delete'] ?? '');
        $user = fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!password_verify($confirm_delete, $user['password'])) {
            $error = 'Incorrect password. Account not deleted.';
        } else {
            try {
                query("DELETE FROM login_logs WHERE user_id = ?", [$userId]);
                query("DELETE FROM users WHERE id = ?", [$userId]);
                session_destroy();
                header('Location: ../auth/register.php');
                exit();
            } catch (Exception $e) {
                $error = 'Failed to delete account. Please try again.';
            }
        }
    }
}

// ── Fetch fresh user data ───────────────────────────────────────────────────
$user = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

// ── Avatar helpers ──────────────────────────────────────────────────────────
$avatarType   = $user['avatar_type'] ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;

// ── Computed values ─────────────────────────────────────────────────────────
$bmi = $bmiCategory = $bmiClass = null;
if (!empty($user['height']) && !empty($user['weight'])) {
    $h   = $user['height'] / 100;
    $bmi = round($user['weight'] / ($h * $h), 1);
    [$bmiCategory, $bmiClass] = match(true) {
        $bmi < 18.5 => ['Underweight', 'underweight'],
        $bmi < 25   => ['Healthy',     'normal'],
        $bmi < 30   => ['Overweight',  'overweight'],
        default     => ['Obese',       'obese'],
    };
}

$forceKey    = $user['target_force'] ?? '';
$forceFlag   = $forceMap[$forceKey][0] ?? '🎖️';
$forceName   = $forceMap[$forceKey][1] ?? ucfirst($forceKey);
$memberSince = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A';
$lastLogin   = !empty($user['last_login'])  ? date('M j, Y · g:i A', strtotime($user['last_login'])) : 'N/A';
$firstName   = explode(' ', $user['full_name'])[0];
$initials    = strtoupper(substr($user['full_name'], 0, 1));

$expProgress = match($user['experience_level'] ?? '') {
    'beginner' => 25, 'intermediate' => 50, 'advanced' => 75, 'expert' => 100, default => 0,
};

// Encode avatar config for JS
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile – Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --accent:#3b82f6; --gold:#fbbf24; --success:#10b981;
    --error:#ef4444; --warning:#f59e0b; --danger:#dc2626;
    --text-primary:#f8fafc; --text-secondary:#cbd5e1;
    --sidebar-bg:rgba(15,23,42,.97); --card-bg:rgba(30,41,59,.8);
    --hover-bg:rgba(59,130,246,.1); --input-bg:rgba(15,23,42,.6);
    --border:rgba(255,255,255,.08);
}
body { font-family:'Poppins',sans-serif; background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#334155 100%); color:var(--text-primary); min-height:100vh; }

/* ── SIDEBAR ── */
.sidebar { position:fixed; left:0; top:0; width:280px; height:100vh; background:var(--sidebar-bg); backdrop-filter:blur(20px); border-right:1px solid var(--border); padding:2rem 0; overflow-y:auto; z-index:1000; transition:transform .3s ease; }
.sidebar-header { padding:0 1.5rem 1.5rem; border-bottom:1px solid var(--border); }
.logo { display:flex; align-items:center; gap:10px; margin-bottom:1.25rem; }
.brand-name { font-size:1.4rem; font-weight:800; background:linear-gradient(135deg,var(--gold),#f59e0b); background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.user-card { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:14px; padding:1rem; display:flex; align-items:center; gap:12px; }
.sidebar-av { width:46px; height:46px; border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid rgba(59,130,246,.4); display:flex; align-items:center; justify-content:center; }
.sidebar-av img { width:100%; height:100%; object-fit:cover; }
.user-details h3 { font-size:.95rem; font-weight:600; }
.user-details p  { font-size:.75rem; color:var(--gold); margin-top:2px; }
.nav-menu { padding:1.5rem 0; }
.nav-section-title { font-size:.7rem; font-weight:600; letter-spacing:.1em; color:rgba(203,213,225,.4); text-transform:uppercase; padding:.5rem 1.5rem; margin-bottom:.25rem; }
.nav-item { padding:.7rem 1.5rem; display:flex; align-items:center; gap:12px; color:var(--text-secondary); text-decoration:none; transition:all .25s; border-left:3px solid transparent; font-size:.9rem; }
.nav-item:hover, .nav-item.active { background:var(--hover-bg); color:var(--text-primary); border-left-color:var(--accent); }
.nav-item svg { width:18px; height:18px; flex-shrink:0; }
.nav-item.logout { color:#f87171; }
.nav-item.logout:hover { background:rgba(239,68,68,.1); border-left-color:#ef4444; }

/* ── MAIN ── */
.main-content { margin-left:280px; padding:2rem; min-height:100vh; }
.topbar { background:var(--card-bg); backdrop-filter:blur(20px); border-radius:16px; padding:1.5rem 2rem; margin-bottom:2rem; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border); }
.topbar h1 { font-size:1.6rem; font-weight:700; }
.topbar p  { color:var(--text-secondary); font-size:.9rem; margin-top:4px; }
.breadcrumb { display:flex; align-items:center; gap:8px; font-size:.85rem; color:var(--text-secondary); }
.breadcrumb a { color:var(--accent); text-decoration:none; }

.alert { padding:1rem 1.25rem; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px; font-size:.9rem; font-weight:500; animation:slideDown .3s ease; }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)} }
.alert-success { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.3); color:#6ee7b7; }
.alert-error   { background:rgba(239,68,68,.12);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }

/* ── PROFILE HEADER ── */
.profile-header {
    background:linear-gradient(135deg,rgba(59,130,246,.12),rgba(139,92,246,.08));
    border:1px solid rgba(59,130,246,.2); border-radius:20px;
    padding:2rem; margin-bottom:2rem;
    display:grid; grid-template-columns:auto 1fr auto; gap:2rem; align-items:center;
}
.profile-avatar-section { position:relative; }

/* ── AVATAR DISPLAY ── */
.avatar-display {
    width:100px; height:100px; border-radius:50%; overflow:hidden;
    border:3px solid rgba(59,130,246,.5);
    box-shadow:0 0 0 4px rgba(59,130,246,.15);
    position:relative; cursor:pointer;
    transition:transform .2s, box-shadow .2s;
    flex-shrink: 0;
}
.avatar-display:hover { transform:scale(1.04); box-shadow:0 0 0 6px rgba(59,130,246,.25); }
.avatar-display img   { width:100%; height:100%; object-fit:cover; display:block; }
.avatar-display canvas { width:100% !important; height:100% !important; display:block; }
.avatar-display .initial-av {
    width:100%; height:100%; display:flex; align-items:center; justify-content:center;
    font-size:2.4rem; font-weight:800;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;
}

/* Camera overlay on hover */
.avatar-cam-overlay {
    position:absolute; inset:0; border-radius:50%;
    background:rgba(0,0,0,.55); display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:4px;
    opacity:0; transition:opacity .2s; pointer-events:none;
}
.avatar-display:hover .avatar-cam-overlay { opacity:1; }
.avatar-cam-overlay svg { width:22px; height:22px; color:#fff; }
.avatar-cam-overlay span { font-size:.6rem; color:rgba(255,255,255,.85); font-weight:600; letter-spacing:.04em; }

.avatar-badge { position:absolute; bottom:2px; right:2px; width:26px; height:26px; border-radius:50%; background:var(--success); border:2px solid #0f172a; display:flex; align-items:center; justify-content:center; font-size:.75rem; }

.profile-meta h2 { font-size:1.6rem; font-weight:700; margin-bottom:.25rem; }
.profile-meta p  { color:var(--text-secondary); font-size:.9rem; }
.profile-tags { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.75rem; }
.tag { padding:.3rem .8rem; border-radius:20px; font-size:.78rem; font-weight:500; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); }
.tag.gold  { background:rgba(251,191,36,.15);  border-color:rgba(251,191,36,.3);  color:var(--gold); }
.tag.blue  { background:rgba(59,130,246,.15);  border-color:rgba(59,130,246,.3);  color:#93c5fd; }
.tag.green { background:rgba(16,185,129,.15);  border-color:rgba(16,185,129,.3);  color:#6ee7b7; }
.profile-quick-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
.qs { text-align:center; }
.qs-val { font-size:1.4rem; font-weight:700; }
.qs-lbl { font-size:.72rem; color:var(--text-secondary); margin-top:2px; }

/* ── TABS ── */
.tabs { display:flex; gap:.5rem; margin-bottom:2rem; background:var(--card-bg); padding:.5rem; border-radius:14px; border:1px solid var(--border); }
.tab { flex:1; padding:.75rem; border:none; border-radius:10px; font-size:.88rem; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; background:transparent; color:var(--text-secondary); transition:all .25s; display:flex; align-items:center; justify-content:center; gap:8px; }
.tab:hover  { background:rgba(255,255,255,.05); color:var(--text-primary); }
.tab.active { background:var(--accent); color:#fff; box-shadow:0 4px 15px rgba(59,130,246,.3); }
.tab-content { display:none; }
.tab-content.active { display:block; animation:fadeIn .3s ease; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)} }

/* ── CARDS ── */
.card { background:var(--card-bg); border-radius:16px; padding:1.75rem; border:1px solid var(--border); margin-bottom:1.5rem; }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:1px solid rgba(255,255,255,.06); }
.card-title  { font-size:1.05rem; font-weight:600; display:flex; align-items:center; gap:8px; }
.card-subtitle { font-size:.82rem; color:var(--text-secondary); margin-top:3px; }

/* ── AVATAR PICKER MODAL ── */
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
@keyframes slideUp { from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)} }
.av-modal-head {
    padding:1.4rem 1.75rem; border-bottom:1px solid rgba(255,255,255,.07);
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.av-modal-head h3 { font-size:1.15rem; font-weight:700; }
.av-modal-head p  { font-size:.8rem; color:var(--text-secondary); margin-top:2px; }
.av-close { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:8px; color:var(--text-secondary); width:34px; height:34px; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.av-close:hover { background:rgba(239,68,68,.2); color:#fca5a5; }

/* Method tabs inside modal */
.av-method-tabs { display:flex; gap:0; border-bottom:1px solid rgba(255,255,255,.07); flex-shrink:0; }
.av-method-tab {
    flex:1; padding:.85rem 1rem; font-size:.85rem; font-weight:600; cursor:pointer;
    border:none; background:transparent; color:var(--text-secondary);
    font-family:'Poppins',sans-serif; transition:all .2s;
    border-bottom:2px solid transparent; display:flex; align-items:center; justify-content:center; gap:7px;
}
.av-method-tab:hover { color:var(--text-primary); background:rgba(255,255,255,.03); }
.av-method-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.av-method-pane { display:none; flex:1; overflow-y:auto; }
.av-method-pane.active { display:flex; flex-direction:column; }

/* Upload pane */
.upload-zone {
    margin:2rem; border:2px dashed rgba(59,130,246,.3); border-radius:16px;
    padding:3rem 2rem; text-align:center; cursor:pointer;
    transition:all .22s; background:rgba(59,130,246,.04);
    position:relative;
}
.upload-zone:hover, .upload-zone.drag-over { border-color:var(--accent); background:rgba(59,130,246,.1); }
.upload-zone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; }
.upload-zone-icon { font-size:2.8rem; margin-bottom:.75rem; }
.upload-zone h4 { font-size:1rem; font-weight:600; margin-bottom:.4rem; }
.upload-zone p  { font-size:.8rem; color:var(--text-secondary); }
.upload-preview-wrap { margin:0 2rem 2rem; position:relative; display:none; }
.upload-preview-wrap.show { display:block; }
.upload-preview-img { width:120px; height:120px; border-radius:50%; object-fit:cover; border:3px solid var(--accent); margin:0 auto; display:block; }
.upload-preview-name { text-align:center; font-size:.82rem; color:var(--text-secondary); margin-top:.75rem; }
.upload-actions { display:flex; gap:.75rem; padding:0 2rem 2rem; }

/* AI Avatar builder */
.av-builder { padding:1.5rem 1.75rem; display:flex; gap:1.5rem; flex:1; }
.av-preview-col { flex-shrink:0; width:160px; display:flex; flex-direction:column; align-items:center; gap:1rem; }
.av-preview-canvas { width:140px; height:140px; border-radius:50%; border:3px solid rgba(59,130,246,.4); overflow:hidden; background:#1e2a40; box-shadow:0 8px 30px rgba(0,0,0,.4); }
.av-preview-canvas canvas { width:100% !important; height:100% !important; display:block; }
.av-randomize { background:rgba(139,92,246,.15); border:1px solid rgba(139,92,246,.3); color:#c4b5fd; border-radius:8px; padding:.5rem 1rem; font-size:.75rem; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; transition:all .15s; width:100%; }
.av-randomize:hover { background:rgba(139,92,246,.25); }

.av-options-col { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:1.1rem; }
.av-option-group label { font-size:.78rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:.55rem; text-transform:uppercase; letter-spacing:.06em; }
.av-swatch-row { display:flex; flex-wrap:wrap; gap:.45rem; }
.av-swatch {
    width:32px; height:32px; border-radius:8px; cursor:pointer;
    border:2px solid transparent; transition:all .15s; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:.95rem;
}
.av-swatch:hover { transform:scale(1.12); }
.av-swatch.sel   { border-color:#fff; box-shadow:0 0 0 2px rgba(255,255,255,.3); transform:scale(1.1); }
.av-swatch.icon-swatch { background:rgba(255,255,255,.07); border-color:rgba(255,255,255,.1); font-size:1.1rem; }
.av-swatch.icon-swatch.sel { border-color:var(--accent); background:rgba(59,130,246,.15); }
.av-swatch.shape-swatch { font-size:.6rem; font-weight:700; color:#fff; letter-spacing:.03em; }

/* Accessory toggle chips */
.av-chip-row { display:flex; flex-wrap:wrap; gap:.4rem; }
.av-chip {
    padding:.3rem .75rem; border-radius:20px; font-size:.75rem; font-weight:600;
    border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.05);
    color:var(--text-secondary); cursor:pointer; transition:all .15s;
}
.av-chip:hover { border-color:rgba(255,255,255,.22); color:var(--text-primary); }
.av-chip.sel { background:rgba(59,130,246,.2); border-color:var(--accent); color:#93c5fd; }

.av-save-row { padding:1.25rem 1.75rem; border-top:1px solid rgba(255,255,255,.07); display:flex; gap:.75rem; flex-shrink:0; }

/* ── FORM STYLES (same as before) ── */
.form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
.form-grid.three { grid-template-columns:1fr 1fr 1fr; }
.span-2 { grid-column:span 2; }
.form-group { display:flex; flex-direction:column; gap:.5rem; }
.form-label { font-size:.85rem; font-weight:500; color:var(--text-secondary); display:flex; align-items:center; gap:6px; }
.form-label .required { color:var(--error); }
.form-input, .form-select { padding:12px 16px; border-radius:10px; background:var(--input-bg); border:1.5px solid rgba(255,255,255,.1); color:var(--text-primary); font-size:.95rem; font-family:'Poppins',sans-serif; transition:all .25s; width:100%; }
.form-input:focus, .form-select:focus { outline:none; border-color:var(--accent); background:rgba(15,23,42,.8); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.form-input::placeholder { color:rgba(203,213,225,.4); }
.form-select option { background:#1e293b; }
.input-wrap { position:relative; }
.input-unit { position:absolute; right:14px; top:50%; transform:translateY(-50%); color:var(--text-secondary); font-size:.85rem; pointer-events:none; }
.with-unit { padding-right:48px; }

.force-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:.6rem; }
.force-radio { display:none; }
.force-label { display:flex; flex-direction:column; align-items:center; gap:6px; padding:.75rem .5rem; background:var(--input-bg); border:1.5px solid rgba(255,255,255,.1); border-radius:10px; cursor:pointer; transition:all .25s; text-align:center; font-size:.75rem; color:var(--text-secondary); }
.force-flag { font-size:1.6rem; line-height:1; }
.force-radio:checked + .force-label { border-color:var(--accent); background:rgba(59,130,246,.15); color:var(--text-primary); box-shadow:0 0 0 3px rgba(59,130,246,.12); }

.btn { padding:.75rem 1.5rem; border:none; border-radius:10px; font-weight:600; cursor:pointer; transition:all .25s; font-family:'Poppins',sans-serif; display:inline-flex; align-items:center; gap:8px; font-size:.9rem; text-decoration:none; }
.btn-primary { background:linear-gradient(135deg,var(--accent),#8b5cf6); color:#fff; }
.btn-primary:hover { transform:translateY(-2px); box-shadow:0 4px 15px rgba(59,130,246,.4); }
.btn-success { background:linear-gradient(135deg,var(--success),#059669); color:#fff; }
.btn-success:hover { transform:translateY(-2px); box-shadow:0 4px 15px rgba(16,185,129,.4); }
.btn-danger  { background:linear-gradient(135deg,var(--danger),#991b1b); color:#fff; }
.btn-danger:hover  { transform:translateY(-2px); box-shadow:0 4px 15px rgba(220,38,38,.4); }
.btn-outline { background:transparent; border:1.5px solid rgba(255,255,255,.15); color:var(--text-secondary); }
.btn-outline:hover { background:rgba(255,255,255,.08); color:var(--text-primary); }
.btn-purple { background:linear-gradient(135deg,#8b5cf6,#a855f7); color:#fff; }
.btn-purple:hover { transform:translateY(-2px); box-shadow:0 4px 15px rgba(139,92,246,.4); }
.btn-sm { padding:.5rem 1rem; font-size:.82rem; }
.btn-full { width:100%; justify-content:center; }

.info-row { display:flex; justify-content:space-between; align-items:center; padding:.85rem 0; border-bottom:1px solid rgba(255,255,255,.05); font-size:.9rem; }
.info-row:last-child { border-bottom:none; padding-bottom:0; }
.info-label { color:var(--text-secondary); font-size:.85rem; }
.info-value { font-weight:500; }
.bmi-row { display:flex; align-items:center; gap:1.5rem; padding:1rem 0; }
.bmi-number { font-size:2.5rem; font-weight:800; line-height:1; }
.bmi-badge { padding:.3rem .9rem; border-radius:20px; font-size:.82rem; font-weight:600; }
.bmi-badge.normal      { background:rgba(16,185,129,.2); color:#6ee7b7; }
.bmi-badge.underweight { background:rgba(59,130,246,.2);  color:#93c5fd; }
.bmi-badge.overweight  { background:rgba(251,191,36,.2);  color:#fcd34d; }
.bmi-badge.obese       { background:rgba(239,68,68,.2);   color:#fca5a5; }
.bmi-scale { height:8px; border-radius:4px; position:relative; margin-top:.75rem; background:linear-gradient(90deg,#3b82f6 0%,#10b981 35%,#f59e0b 65%,#ef4444 100%); }
.bmi-marker { position:absolute; top:-4px; width:16px; height:16px; background:#fff; border-radius:50%; transform:translateX(-50%); box-shadow:0 2px 8px rgba(0,0,0,.4); }
.prog-bar  { height:6px; background:rgba(255,255,255,.08); border-radius:3px; overflow:hidden; margin-top:.4rem; }
.prog-fill { height:100%; border-radius:3px; transition:width .8s ease; }
.prog-blue  { background:linear-gradient(90deg,var(--accent),#60a5fa); }
.prog-gold  { background:linear-gradient(90deg,var(--gold),#fcd34d); }
.prog-green { background:linear-gradient(90deg,var(--success),#34d399); }
.strength-bar { display:flex; gap:4px; margin-top:.5rem; }
.strength-seg { height:4px; flex:1; border-radius:2px; background:rgba(255,255,255,.1); transition:background .3s; }
.strength-text { font-size:.75rem; color:var(--text-secondary); margin-top:.3rem; }
.danger-zone { border-color:rgba(220,38,38,.3); background:rgba(220,38,38,.05); }
.danger-zone .card-title { color:#fca5a5; }
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); backdrop-filter:blur(4px); z-index:9999; display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal { background:#1e293b; border:1px solid rgba(220,38,38,.3); border-radius:20px; padding:2rem; max-width:440px; width:90%; animation:fadeIn .3s ease; }
.modal h3 { font-size:1.2rem; color:#fca5a5; margin-bottom:.75rem; }
.modal p  { color:var(--text-secondary); font-size:.9rem; margin-bottom:1.25rem; line-height:1.6; }
.modal-actions { display:flex; gap:1rem; }
.mobile-menu-btn { display:none; position:fixed; bottom:1.5rem; right:1.5rem; width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,var(--accent),#8b5cf6); border:none; color:#fff; font-size:1.4rem; cursor:pointer; box-shadow:0 4px 15px rgba(59,130,246,.4); z-index:999; }

/* Scrollbar */
.av-options-col::-webkit-scrollbar { width:4px; }
.av-options-col::-webkit-scrollbar-track { background:transparent; }
.av-options-col::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:2px; }
.av-method-pane::-webkit-scrollbar { width:4px; }
.av-method-pane::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:2px; }

@media (max-width:1200px) { .profile-quick-stats{display:none} .profile-header{grid-template-columns:auto 1fr} }
@media (max-width:1024px) { .form-grid{grid-template-columns:1fr} .form-grid.three{grid-template-columns:1fr 1fr} .force-grid{grid-template-columns:repeat(3,1fr)} .span-2{grid-column:span 1} .av-builder{flex-direction:column} .av-preview-col{width:100%;flex-direction:row;gap:2rem;align-items:flex-start} }
@media (max-width:768px) { .sidebar{transform:translateX(-100%)} .sidebar.active{transform:translateX(0)} .main-content{margin-left:0} .profile-header{grid-template-columns:1fr;text-align:center} .avatar-display{margin:0 auto} .profile-tags{justify-content:center} .tabs{flex-wrap:wrap} .mobile-menu-btn{display:flex;align-items:center;justify-content:center} .force-grid{grid-template-columns:repeat(3,1fr)} .modal-actions{flex-direction:column} }
@media (max-width:480px) { .main-content{padding:1rem} .force-grid{grid-template-columns:repeat(2,1fr)} .av-modal{max-height:95vh} }
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Logo" style="width:40px;height:40px;object-fit:contain"
                 onerror="this.style.display='none'">
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="sidebar-av" id="sidebarAvContainer">
                <!-- Filled by JS -->
            </div>
            <div class="user-details">
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= ucfirst($user['experience_level'] ?? '') ?> · <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
        <a href="workouts.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>Workouts</a>
        <a href="progress.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Progress</a>
        <a href="nutrition.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>Nutrition</a>
        <a href="questions.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Questions</a>
        <a href="documents.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Documents</a>
        <a href="motivation.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>Motivation</a>
        <div class="nav-section-title" style="margin-top:1rem">Account</div>
        <a href="profile.php" class="nav-item active"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Profile</a>
        <a href="settings.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Settings</a>
        <a href="../auth/logout.php" class="nav-item logout"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
    </nav>
</aside>

<!-- ── Main Content ── -->
<main class="main-content">
    <div class="topbar">
        <div>
            <div class="breadcrumb"><a href="dashboard.php">Dashboard</a><span>/</span><span>Profile</span></div>
            <h1 style="margin-top:.25rem">My Profile</h1>
            <p>Manage your personal information and account settings</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><span>✓</span> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><span>⚠️</span> <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- ── Profile Header ── -->
    <div class="profile-header">
        <div class="profile-avatar-section">
            <div class="avatar-display" onclick="openAvatarModal()" title="Change profile picture">
                <div id="mainAvatarInner">
                    <!-- Rendered by JS -->
                </div>
                <div class="avatar-cam-overlay">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
                    <span>CHANGE</span>
                </div>
                <div class="avatar-badge">✓</div>
            </div>
        </div>
        <div class="profile-meta">
            <h2><?= htmlspecialchars($user['full_name']) ?></h2>
            <p><?= htmlspecialchars($user['email']) ?> &nbsp;·&nbsp; Member since <?= $memberSince ?></p>
            <div class="profile-tags">
                <span class="tag gold"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
                <span class="tag blue">⚔️ <?= ucfirst($user['experience_level'] ?? '') ?></span>
                <span class="tag green"><?= $user['gender']==='male'?'♂':($user['gender']==='female'?'♀':'⚧') ?> <?= ucfirst($user['gender'] ?? '') ?></span>
                <span class="tag">📅 Age <?= (int)$user['age'] ?></span>
            </div>
        </div>
        <div class="profile-quick-stats">
            <div class="qs"><div class="qs-val"><?= $user['height'] ?></div><div class="qs-lbl">Height (cm)</div></div>
            <div class="qs"><div class="qs-val"><?= $user['weight'] ?></div><div class="qs-lbl">Weight (kg)</div></div>
            <div class="qs"><div class="qs-val"><?= $bmi ?? '—' ?></div><div class="qs-lbl">BMI</div></div>
        </div>
    </div>

    <!-- ── Tabs ── -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('overview',this)">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Overview
        </button>
        <button class="tab" onclick="switchTab('edit',this)">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>Edit Profile
        </button>
        <button class="tab" onclick="switchTab('security',this)">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>Security
        </button>
        <button class="tab" onclick="switchTab('danger',this)" style="color:#f87171">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>Danger Zone
        </button>
    </div>

    <!-- ══ TAB 1: OVERVIEW ══ -->
    <div id="tab-overview" class="tab-content active">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
            <div class="card">
                <div class="card-header">
                    <div><div class="card-title">👤 Personal Information</div><div class="card-subtitle">Your registered details</div></div>
                    <button class="btn btn-outline btn-sm" onclick="switchTab('edit',document.querySelectorAll('.tab')[1])">Edit</button>
                </div>
                <div class="info-row"><span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars($user['full_name']) ?></span></div>
                <div class="info-row"><span class="info-label">Email</span><span class="info-value" style="font-size:.85rem"><?= htmlspecialchars($user['email']) ?></span></div>
                <div class="info-row"><span class="info-label">Age</span><span class="info-value"><?= (int)$user['age'] ?> years old</span></div>
                <div class="info-row"><span class="info-label">Gender</span><span class="info-value"><?= ucfirst($user['gender'] ?? 'N/A') ?></span></div>
                <div class="info-row"><span class="info-label">Member Since</span><span class="info-value"><?= $memberSince ?></span></div>
                <div class="info-row"><span class="info-label">Last Login</span><span class="info-value" style="font-size:.82rem"><?= $lastLogin ?></span></div>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">📊 Physical Stats</div><div class="card-subtitle">Body measurements & BMI</div></div></div>
                <div style="margin-bottom:1.1rem">
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.3rem"><span style="color:var(--text-secondary)">Height</span><span style="font-weight:600"><?= $user['height'] ?> cm</span></div>
                    <div class="prog-bar"><?php $hPct=min(100,max(0,(($user['height']-100)/150)*100)); ?><div class="prog-fill prog-blue" style="width:<?= $hPct ?>%"></div></div>
                </div>
                <div style="margin-bottom:1.1rem">
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.3rem"><span style="color:var(--text-secondary)">Weight</span><span style="font-weight:600"><?= $user['weight'] ?> kg</span></div>
                    <div class="prog-bar"><?php $wPct=min(100,max(0,(($user['weight']-30)/270)*100)); ?><div class="prog-fill prog-green" style="width:<?= $wPct ?>%"></div></div>
                </div>
                <?php if ($bmi): ?>
                <div class="bmi-row">
                    <div><div class="bmi-number"><?= $bmi ?></div><div class="bmi-badge <?= $bmiClass ?>" style="margin-top:.4rem"><?= $bmiCategory ?></div></div>
                    <div style="flex:1">
                        <div class="bmi-scale"><?php $mp=min(100,max(0,(($bmi-10)/30)*100)); ?><div class="bmi-marker" style="left:<?= $mp ?>%"></div></div>
                        <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--text-secondary);margin-top:.4rem"><span>Thin</span><span>Normal</span><span>Over</span><span>Obese</span></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">🎖️ Target Force</div><div class="card-subtitle">Your recruitment goal</div></div></div>
                <div style="text-align:center;padding:1.5rem 0">
                    <div style="font-size:4rem;margin-bottom:.75rem"><?= $forceFlag ?></div>
                    <div style="font-size:1.2rem;font-weight:700"><?= htmlspecialchars($forceName) ?></div>
                    <div style="font-size:.82rem;color:var(--text-secondary);margin-top:.4rem">Target Military Force</div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">⚔️ Experience Level</div><div class="card-subtitle">Current training level</div></div></div>
                <div style="padding:.5rem 0">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem"><span style="font-size:.9rem;color:var(--text-secondary)">Level</span><span style="font-weight:600;font-size:1rem"><?= ucfirst($user['experience_level'] ?? 'N/A') ?></span></div>
                    <div class="prog-bar" style="height:10px"><div class="prog-fill prog-gold" style="width:<?= $expProgress ?>%"></div></div>
                    <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-secondary);margin-top:.5rem"><span>Beginner</span><span>Intermediate</span><span>Advanced</span><span>Expert</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: EDIT ══ -->
    <div id="tab-edit" class="tab-content">
        <form method="POST" action="profile.php">
            <input type="hidden" name="action" value="update_profile">
            <div class="card">
                <div class="card-header"><div><div class="card-title">✏️ Edit Personal Information</div><div class="card-subtitle">Update your personal details</div></div></div>
                <div class="form-grid">
                    <div class="form-group span-2"><label class="form-label">Full Name <span class="required">*</span></label><input type="text" name="full_name" class="form-input" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
                    <div class="form-group"><label class="form-label">Age <span class="required">*</span></label><input type="number" name="age" class="form-input" value="<?= (int)$user['age'] ?>" min="13" max="120" required></div>
                    <div class="form-group"><label class="form-label">Gender <span class="required">*</span></label><select name="gender" class="form-select" required><option value="male" <?= $user['gender']==='male'?'selected':'' ?>>Male</option><option value="female" <?= $user['gender']==='female'?'selected':'' ?>>Female</option><option value="other" <?= $user['gender']==='other'?'selected':'' ?>>Other</option></select></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">📏 Physical Measurements</div></div></div>
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Height *</label><div class="input-wrap"><input type="number" name="height" class="form-input with-unit" value="<?= $user['height'] ?>" step="0.1" min="100" max="250" required><span class="input-unit">cm</span></div></div>
                    <div class="form-group"><label class="form-label">Weight *</label><div class="input-wrap"><input type="number" name="weight" class="form-input with-unit" value="<?= $user['weight'] ?>" step="0.1" min="30" max="300" required><span class="input-unit">kg</span></div></div>
                </div>
                <div id="bmi-preview" style="margin-top:1.25rem;padding:1rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;display:flex;align-items:center;gap:1rem">
                    <div><div style="font-size:.8rem;color:var(--text-secondary)">Live BMI</div><div id="bmi-val" style="font-size:1.8rem;font-weight:700"><?= $bmi ?? '—' ?></div></div>
                    <div id="bmi-cat" style="padding:.3rem .9rem;border-radius:20px;font-size:.82rem;font-weight:600;background:rgba(16,185,129,.2);color:#6ee7b7"><?= $bmiCategory ?? '' ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">🎖️ Target Force</div></div></div>
                <div class="force-grid">
                    <?php foreach(['british'=>['🇬🇧','British Army'],'nepal'=>['🇳🇵','Nepal Army'],'indian'=>['🇮🇳','Indian Army'],'singapore'=>['🇸🇬','Singapore Police'],'french'=>['🇫🇷','French Foreign Legion']] as $val=>[$flag,$label]): ?>
                    <input type="radio" class="force-radio" id="force_<?= $val ?>" name="target_force" value="<?= $val ?>" <?= ($user['target_force']??'')===$val?'checked':'' ?> required>
                    <label class="force-label" for="force_<?= $val ?>"><span class="force-flag"><?= $flag ?></span><?= $label ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">⚔️ Experience Level</div></div></div>
                <div class="form-grid" style="grid-template-columns:repeat(4,1fr)">
                    <?php foreach(['beginner'=>['🌱','Beginner','Just starting'],'intermediate'=>['💪','Intermediate','Some experience'],'advanced'=>['🔥','Advanced','Highly trained'],'expert'=>['⚡','Expert','Elite fitness']] as $val=>[$icon,$label,$desc]): ?>
                    <div><input type="radio" class="force-radio" id="exp_<?= $val ?>" name="experience_level" value="<?= $val ?>" <?= ($user['experience_level']??'')===$val?'checked':'' ?> required><label class="force-label" for="exp_<?= $val ?>" style="height:100%"><span style="font-size:1.5rem"><?= $icon ?></span><span style="font-weight:600"><?= $label ?></span><span style="font-size:.7rem;color:var(--text-secondary)"><?= $desc ?></span></label></div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;gap:1rem">
                    <button type="button" class="btn btn-outline" onclick="switchTab('overview',document.querySelectorAll('.tab')[0])">Cancel</button>
                    <button type="submit" class="btn btn-success">✓ Save Changes</button>
                </div>
            </div>
        </form>
    </div>

    <!-- ══ TAB 3: SECURITY ══ -->
    <div id="tab-security" class="tab-content">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
            <div class="card">
                <div class="card-header"><div><div class="card-title">🔑 Change Password</div><div class="card-subtitle">Use a strong unique password</div></div></div>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group" style="margin-bottom:1.25rem"><label class="form-label">Current Password *</label><input type="password" name="current_password" class="form-input" placeholder="Current password" required autocomplete="current-password"></div>
                    <div class="form-group" style="margin-bottom:.5rem"><label class="form-label">New Password *</label><input type="password" name="new_password" id="new_pw" class="form-input" placeholder="Min 8 characters" required minlength="8" autocomplete="new-password" oninput="checkStrength(this.value)">
                        <div class="strength-bar"><div class="strength-seg" id="s1"></div><div class="strength-seg" id="s2"></div><div class="strength-seg" id="s3"></div><div class="strength-seg" id="s4"></div></div>
                        <div class="strength-text" id="strength-text">Enter a password</div>
                    </div>
                    <div class="form-group" style="margin-bottom:1.5rem"><label class="form-label">Confirm New Password *</label><input type="password" name="confirm_password" class="form-input" placeholder="Repeat new password" required autocomplete="new-password"></div>
                    <button type="submit" class="btn btn-primary btn-full">🔒 Update Password</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><div><div class="card-title">🛡️ Security Overview</div></div></div>
                <div class="info-row"><span class="info-label">Account Status</span><span class="info-value" style="color:var(--success)">✓ Active</span></div>
                <div class="info-row"><span class="info-label">Email</span><span class="info-value" style="font-size:.82rem"><?= htmlspecialchars($user['email']) ?></span></div>
                <div class="info-row"><span class="info-label">Last Login</span><span class="info-value" style="font-size:.82rem"><?= $lastLogin ?></span></div>
                <div class="info-row"><span class="info-label">Member Since</span><span class="info-value"><?= $memberSince ?></span></div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 4: DANGER ══ -->
    <div id="tab-danger" class="tab-content">
        <div class="card danger-zone">
            <div class="card-header"><div><div class="card-title">⚠️ Delete Account</div><div class="card-subtitle" style="color:#fca5a5">Permanent — cannot be undone</div></div></div>
            <p style="color:var(--text-secondary);font-size:.9rem;line-height:1.7;margin-bottom:1.5rem">Deleting your account removes all data permanently including your profile, workout history, and login records.</p>
            <button class="btn btn-danger" onclick="document.getElementById('deleteModal').classList.add('open')">🗑 Delete My Account</button>
        </div>
    </div>
</main>

<!-- ══════════════════════════════════════════════════════
     AVATAR PICKER MODAL
════════════════════════════════════════════════════════ -->
<div class="av-modal-overlay" id="avatarModal">
    <div class="av-modal">
        <div class="av-modal-head">
            <div>
                <h3>Profile Picture</h3>
                <p>Upload a photo or create your AI avatar</p>
            </div>
            <button class="av-close" onclick="closeAvatarModal()">✕</button>
        </div>

        <!-- Method tabs -->
        <div class="av-method-tabs">
            <button class="av-method-tab active" onclick="switchAvTab('upload',this)">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Upload Photo
            </button>
            <button class="av-method-tab" onclick="switchAvTab('ai',this)">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                AI Avatar Builder
            </button>
            <?php if ($photoUrl || $avatarType === 'ai'): ?>
            <button class="av-method-tab" onclick="switchAvTab('remove',this)" style="color:#f87171">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Remove
            </button>
            <?php endif; ?>
        </div>

        <!-- UPLOAD PANE -->
        <div class="av-method-pane active" id="avpane-upload">
            <form method="POST" action="profile.php" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload_photo">
                <div class="upload-zone" id="uploadZone">
                    <input type="file" name="profile_photo" id="photoFileInput" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewUpload(this)">
                    <div class="upload-zone-icon">📸</div>
                    <h4>Drop photo here or click to browse</h4>
                    <p>JPG, PNG, WEBP or GIF · Max 5MB · Square crop recommended</p>
                </div>
                <div class="upload-preview-wrap" id="uploadPreviewWrap">
                    <img id="uploadPreviewImg" class="upload-preview-img" src="" alt="Preview">
                    <div class="upload-preview-name" id="uploadPreviewName"></div>
                </div>
                <div class="upload-actions">
                    <button type="button" class="btn btn-outline" onclick="clearUpload()">Clear</button>
                    <button type="submit" class="btn btn-primary" id="uploadSubmitBtn" disabled>
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Upload & Save
                    </button>
                </div>
            </form>
        </div>

        <!-- AI AVATAR PANE -->
        <div class="av-method-pane" id="avpane-ai">
            <form method="POST" action="profile.php" id="aiAvatarForm">
                <input type="hidden" name="action" value="save_ai_avatar">
                <input type="hidden" name="avatar_config" id="avatarConfigInput">

                <div class="av-builder">
                    <!-- Preview column -->
                    <div class="av-preview-col">
                        <div class="av-preview-canvas">
                            <canvas id="avatarCanvas" width="200" height="200"></canvas>
                        </div>
                        <button type="button" class="av-randomize" onclick="randomizeAvatar()">🎲 Randomize</button>
                    </div>

                    <!-- Options column -->
                    <div class="av-options-col">

                        <!-- Background -->
                        <div class="av-option-group">
                            <label>Background</label>
                            <div class="av-swatch-row" id="opt-bg">
                                <!-- filled by JS -->
                            </div>
                        </div>

                        <!-- Skin Tone -->
                        <div class="av-option-group">
                            <label>Skin Tone</label>
                            <div class="av-swatch-row" id="opt-skin">
                            </div>
                        </div>

                        <!-- Hair Style -->
                        <div class="av-option-group">
                            <label>Hair Style</label>
                            <div class="av-swatch-row" id="opt-hair-style">
                            </div>
                        </div>

                        <!-- Hair Color -->
                        <div class="av-option-group">
                            <label>Hair Color</label>
                            <div class="av-swatch-row" id="opt-hair-color">
                            </div>
                        </div>

                        <!-- Eyes -->
                        <div class="av-option-group">
                            <label>Eye Color</label>
                            <div class="av-swatch-row" id="opt-eyes">
                            </div>
                        </div>

                        <!-- Accessories -->
                        <div class="av-option-group">
                            <label>Accessories</label>
                            <div class="av-chip-row" id="opt-accessories">
                            </div>
                        </div>

                        <!-- Military Badge -->
                        <div class="av-option-group">
                            <label>Military Badge</label>
                            <div class="av-swatch-row" id="opt-badge">
                            </div>
                        </div>

                    </div>
                </div>

                <div class="av-save-row">
                    <button type="button" class="btn btn-outline" onclick="closeAvatarModal()">Cancel</button>
                    <button type="button" class="btn btn-purple" onclick="saveAiAvatar()">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Avatar
                    </button>
                </div>
            </form>
        </div>

        <!-- REMOVE PANE -->
        <div class="av-method-pane" id="avpane-remove">
            <div style="padding:2.5rem;text-align:center">
                <div style="font-size:3.5rem;margin-bottom:1rem">🗑️</div>
                <h4 style="font-size:1.1rem;margin-bottom:.6rem">Remove Profile Picture</h4>
                <p style="color:var(--text-secondary);font-size:.88rem;margin-bottom:2rem;max-width:340px;margin-left:auto;margin-right:auto">Your profile will show your initial letter instead. You can upload a new photo or create an AI avatar at any time.</p>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="action" value="remove_photo">
                    <button type="submit" class="btn btn-danger">Remove Picture</button>
                </form>
            </div>
        </div>

    </div>
</div>

<!-- Delete modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <h3>⚠️ Confirm Account Deletion</h3>
        <p>Permanently delete your account for <strong style="color:var(--text-primary)"><?= htmlspecialchars($user['full_name']) ?></strong>. Enter your password to confirm.</p>
        <form method="POST" action="profile.php">
            <input type="hidden" name="action" value="delete_account">
            <div class="form-group" style="margin-bottom:1.25rem"><label class="form-label">Password</label><input type="password" name="confirm_delete" class="form-input" placeholder="Enter your password" required autocomplete="current-password"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline btn-full" onclick="document.getElementById('deleteModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-danger btn-full">Delete Forever</button>
            </div>
        </form>
    </div>
</div>

<button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>

<script>
// ════════════════════════════════════════════════════════════════
// AVATAR SYSTEM
// ════════════════════════════════════════════════════════════════

// Current saved state from PHP
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;
const USER_GENDER         = <?= json_encode($user['gender'] ?? 'male') ?>;

// ── Avatar option definitions ─────────────────────────────────────────────
const AV_OPTIONS = {
    bg: [
        { id:'grad1', label:'Ocean',    grad:['#1e3a5f','#2563eb'] },
        { id:'grad2', label:'Forest',   grad:['#14532d','#16a34a'] },
        { id:'grad3', label:'Sunset',   grad:['#7f1d1d','#dc2626'] },
        { id:'grad4', label:'Dusk',     grad:['#312e81','#7c3aed'] },
        { id:'grad5', label:'Gold',     grad:['#78350f','#d97706'] },
        { id:'grad6', label:'Teal',     grad:['#134e4a','#0d9488'] },
        { id:'grad7', label:'Night',    grad:['#0f172a','#1e293b'] },
        { id:'grad8', label:'Rose',     grad:['#4c0519','#e11d48'] },
    ],
    skin: [
        { id:'s1', color:'#FDDBB4' },
        { id:'s2', color:'#F5C89A' },
        { id:'s3', color:'#D4956A' },
        { id:'s4', color:'#C47C45' },
        { id:'s5', color:'#A05C2C' },
        { id:'s6', color:'#7D3F17' },
        { id:'s7', color:'#5C2B0D' },
        { id:'s8', color:'#3B1A08' },
    ],
    hairStyle: [
        { id:'none',   label:'None',    icon:'🧑' },
        { id:'short',  label:'Short',   icon:'💆' },
        { id:'medium', label:'Medium',  icon:'🧑‍🦰' },
        { id:'long',   label:'Long',    icon:'👩‍🦳' },
        { id:'curly',  label:'Curly',   icon:'🧑‍🦱' },
        { id:'bald',   label:'Bald',    icon:'👨‍🦲' },
        { id:'bun',    label:'Bun',     icon:'💁' },
        { id:'mohawk', label:'Mohawk',  icon:'🤘' },
    ],
    hairColor: [
        { id:'black',  color:'#1a1a1a' },
        { id:'brown',  color:'#5C3A1E' },
        { id:'auburn', color:'#922B21' },
        { id:'blonde', color:'#D4A843' },
        { id:'gray',   color:'#9CA3AF' },
        { id:'white',  color:'#F5F5F5' },
        { id:'red',    color:'#B91C1C' },
        { id:'blue',   color:'#1D4ED8' },
        { id:'purple', color:'#7C3AED' },
        { id:'green',  color:'#15803D' },
    ],
    eyes: [
        { id:'brown',  color:'#6B3F1A' },
        { id:'hazel',  color:'#8B6914' },
        { id:'green',  color:'#15803D' },
        { id:'blue',   color:'#1D4ED8' },
        { id:'gray',   color:'#6B7280' },
        { id:'black',  color:'#111827' },
        { id:'amber',  color:'#D97706' },
        { id:'violet', color:'#7C3AED' },
    ],
    accessories: [
        { id:'glasses',  label:'👓 Glasses' },
        { id:'sunglasses', label:'🕶 Shades' },
        { id:'beard',    label:'🧔 Beard' },
        { id:'moustache',label:'👨 Moustache' },
        { id:'earring',  label:'💎 Earring' },
        { id:'headband', label:'🩺 Headband' },
    ],
    badge: [
        { id:'none',      label:'None',   icon:'❌' },
        { id:'gurkha',    label:'Gurkha', icon:'🎖' },
        { id:'star',      label:'Star',   icon:'⭐' },
        { id:'military',  label:'Army',   icon:'🪖' },
        { id:'crown',     label:'Crown',  icon:'👑' },
        { id:'lightning', label:'Power',  icon:'⚡' },
    ],
};

// Current avatar config state
let avatarState = SAVED_AVATAR_CONFIG ? { ...SAVED_AVATAR_CONFIG } : {
    bg:         'grad1',
    skin:       's2',
    hairStyle:  'short',
    hairColor:  'brown',
    eyes:       'brown',
    accessories:[],
    badge:      'none',
};

// ── Build option UI ────────────────────────────────────────────────────────
function buildSwatches(containerId, options, stateKey, isMulti = false) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '';

    options.forEach(opt => {
        const sw = document.createElement('div');
        sw.className = 'av-swatch';

        if (stateKey === 'bg') {
            // Gradient swatch
            sw.style.background = `linear-gradient(135deg, ${opt.grad[0]}, ${opt.grad[1]})`;
            sw.title = opt.label;
            sw.classList.toggle('sel', avatarState.bg === opt.id);
            sw.onclick = () => { avatarState.bg = opt.id; refreshSwatches(containerId, options, stateKey); drawAvatar(); };
        } else if (opt.color) {
            // Color swatch
            sw.style.background = opt.color;
            sw.title = opt.id;
            sw.style.border = opt.color === '#F5F5F5' ? '2px solid #9CA3AF' : '2px solid transparent';
            const isSel = isMulti ? avatarState[stateKey]?.includes(opt.id) : avatarState[stateKey] === opt.id;
            sw.classList.toggle('sel', isSel);
            sw.onclick = () => {
                if (isMulti) {
                    if (!avatarState[stateKey]) avatarState[stateKey] = [];
                    const idx = avatarState[stateKey].indexOf(opt.id);
                    if (idx > -1) avatarState[stateKey].splice(idx, 1);
                    else avatarState[stateKey].push(opt.id);
                } else {
                    avatarState[stateKey] = opt.id;
                }
                refreshSwatches(containerId, options, stateKey, isMulti);
                drawAvatar();
            };
        } else if (opt.icon) {
            // Icon swatch
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
        chip.className = 'av-chip';
        chip.textContent = opt.label;
        chip.dataset.id = opt.id;
        const isSel = avatarState[stateKey]?.includes(opt.id);
        chip.classList.toggle('sel', isSel);
        chip.onclick = () => {
            if (!avatarState[stateKey]) avatarState[stateKey] = [];
            const idx = avatarState[stateKey].indexOf(opt.id);
            if (idx > -1) avatarState[stateKey].splice(idx, 1);
            else avatarState[stateKey].push(opt.id);
            buildChips(containerId, options, stateKey);
            drawAvatar();
        };
        el.appendChild(chip);
    });
}

function refreshSwatches(containerId, options, stateKey, isMulti = false) {
    document.querySelectorAll('#' + containerId + ' .av-swatch').forEach((sw, i) => {
        const opt = options[i];
        if (!opt) return;
        const isSel = isMulti
            ? avatarState[stateKey]?.includes(opt.id)
            : avatarState[stateKey] === opt.id;
        sw.classList.toggle('sel', isSel);
    });
}

function buildAllOptions() {
    buildSwatches('opt-bg',         AV_OPTIONS.bg,        'bg');
    buildSwatches('opt-skin',       AV_OPTIONS.skin,      'skin');
    buildSwatches('opt-hair-style', AV_OPTIONS.hairStyle, 'hairStyle');
    buildSwatches('opt-hair-color', AV_OPTIONS.hairColor, 'hairColor');
    buildSwatches('opt-eyes',       AV_OPTIONS.eyes,      'eyes');
    buildChips('opt-accessories',   AV_OPTIONS.accessories,'accessories');
    buildSwatches('opt-badge',      AV_OPTIONS.badge,     'badge');
}

// ── Canvas avatar renderer ─────────────────────────────────────────────────
function drawAvatarOnCanvas(canvas, state, size = 200) {
    const ctx = canvas.getContext('2d');
    const w = size, h = size;
    canvas.width = w; canvas.height = h;

    const bg    = AV_OPTIONS.bg.find(o => o.id === state.bg) || AV_OPTIONS.bg[0];
    const skin  = AV_OPTIONS.skin.find(o => o.id === state.skin) || AV_OPTIONS.skin[1];
    const hCol  = AV_OPTIONS.hairColor.find(o => o.id === state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC  = AV_OPTIONS.eyes.find(o => o.id === state.eyes) || AV_OPTIONS.eyes[0];
    const acc   = state.accessories || [];
    const badge = state.badge || 'none';
    const hStyle= state.hairStyle || 'short';

    const cx = w / 2, cy = h / 2;

    // ── Background ──
    const grad = ctx.createLinearGradient(0, 0, w, h);
    grad.addColorStop(0, bg.grad[0]);
    grad.addColorStop(1, bg.grad[1]);
    ctx.fillStyle = grad;
    ctx.beginPath(); ctx.arc(cx, cy, cx, 0, Math.PI * 2); ctx.fill();

    // ── Neck ──
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.roundRect(cx - 14, cy + 32, 28, 22, [4,4,0,0]); ctx.fill();

    // ── Shoulders/Shirt ──
    const shirtGrad = ctx.createLinearGradient(cx - 55, cy + 50, cx + 55, cy + h);
    shirtGrad.addColorStop(0, '#1e3a5f'); shirtGrad.addColorStop(1, '#0f172a');
    ctx.fillStyle = shirtGrad;
    ctx.beginPath(); ctx.ellipse(cx, cy + 62, 58, 28, 0, 0, Math.PI * 2); ctx.fill();

    // ── Head shape ──
    ctx.fillStyle = skin.color;
    ctx.beginPath();
    ctx.ellipse(cx, cy + 2, 44, 52, 0, 0, Math.PI * 2);
    ctx.fill();

    // Face shadow
    const faceShadow = ctx.createRadialGradient(cx, cy + 8, 10, cx, cy + 8, 44);
    faceShadow.addColorStop(0, 'transparent');
    faceShadow.addColorStop(1, 'rgba(0,0,0,0.08)');
    ctx.fillStyle = faceShadow;
    ctx.beginPath(); ctx.ellipse(cx, cy + 2, 44, 52, 0, 0, Math.PI * 2); ctx.fill();

    // ── Ears ──
    ctx.fillStyle = skin.color;
    ctx.beginPath(); ctx.ellipse(cx - 44, cy + 5, 8, 11, 0, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx + 44, cy + 5, 8, 11, 0, 0, Math.PI * 2); ctx.fill();
    // Ear inner
    ctx.fillStyle = 'rgba(0,0,0,0.1)';
    ctx.beginPath(); ctx.ellipse(cx - 44, cy + 5, 4, 6, 0, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx + 44, cy + 5, 4, 6, 0, 0, Math.PI * 2); ctx.fill();

    // ── Eyebrows ──
    ctx.strokeStyle = hCol.color === '#F5F5F5' ? '#C0A080' : (hCol.color === '#1a1a1a' ? '#2d2d2d' : hCol.color);
    ctx.lineWidth = 3.5; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx - 28, cy - 16); ctx.quadraticCurveTo(cx - 16, cy - 20, cx - 7, cy - 16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx + 7, cy - 16); ctx.quadraticCurveTo(cx + 16, cy - 20, cx + 28, cy - 16); ctx.stroke();

    // ── Eyes ──
    // Whites
    ctx.fillStyle = '#FFFFFF';
    ctx.beginPath(); ctx.ellipse(cx - 16, cy - 4, 11, 9, 0, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx + 16, cy - 4, 11, 9, 0, 0, Math.PI * 2); ctx.fill();
    // Iris
    ctx.fillStyle = eyeC.color;
    ctx.beginPath(); ctx.arc(cx - 16, cy - 4, 6.5, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx + 16, cy - 4, 6.5, 0, Math.PI * 2); ctx.fill();
    // Pupil
    ctx.fillStyle = '#000';
    ctx.beginPath(); ctx.arc(cx - 16, cy - 4, 3.5, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx + 16, cy - 4, 3.5, 0, Math.PI * 2); ctx.fill();
    // Catchlight
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.beginPath(); ctx.arc(cx - 13, cy - 7, 2.2, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx + 19, cy - 7, 2.2, 0, Math.PI * 2); ctx.fill();
    // Eye outline
    ctx.strokeStyle = 'rgba(0,0,0,0.25)'; ctx.lineWidth = 1.2;
    ctx.beginPath(); ctx.ellipse(cx - 16, cy - 4, 11, 9, 0, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(cx + 16, cy - 4, 11, 9, 0, 0, Math.PI * 2); ctx.stroke();

    // ── Nose ──
    ctx.strokeStyle = 'rgba(0,0,0,0.18)'; ctx.lineWidth = 2; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx - 3, cy + 2); ctx.lineTo(cx, cy + 12); ctx.lineTo(cx + 3, cy + 2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx - 9, cy + 14); ctx.quadraticCurveTo(cx, cy + 17, cx + 9, cy + 14); ctx.stroke();

    // ── Mouth ──
    ctx.strokeStyle = 'rgba(0,0,0,0.3)'; ctx.lineWidth = 2.5;
    ctx.beginPath(); ctx.moveTo(cx - 14, cy + 24); ctx.quadraticCurveTo(cx, cy + 30, cx + 14, cy + 24); ctx.stroke();
    // Lips hint
    ctx.fillStyle = 'rgba(200,120,100,0.35)';
    ctx.beginPath(); ctx.ellipse(cx, cy + 25, 12, 4, 0, 0, Math.PI); ctx.fill();

    // ── Cheeks blush ──
    const blush = ctx.createRadialGradient(cx - 28, cy + 14, 0, cx - 28, cy + 14, 12);
    blush.addColorStop(0, 'rgba(255,150,150,0.25)'); blush.addColorStop(1, 'transparent');
    ctx.fillStyle = blush; ctx.beginPath(); ctx.arc(cx - 28, cy + 14, 12, 0, Math.PI * 2); ctx.fill();
    const blush2 = ctx.createRadialGradient(cx + 28, cy + 14, 0, cx + 28, cy + 14, 12);
    blush2.addColorStop(0, 'rgba(255,150,150,0.25)'); blush2.addColorStop(1, 'transparent');
    ctx.fillStyle = blush2; ctx.beginPath(); ctx.arc(cx + 28, cy + 14, 12, 0, Math.PI * 2); ctx.fill();

    // ── Hair ──
    ctx.fillStyle = hCol.color;
    switch (hStyle) {
        case 'short':
            ctx.beginPath(); ctx.ellipse(cx, cy - 38, 44, 22, 0, Math.PI, 0); ctx.fill();
            ctx.fillRect(cx - 44, cy - 40, 88, 20);
            ctx.beginPath(); ctx.ellipse(cx - 42, cy - 6, 8, 22, -0.15, -Math.PI/2, Math.PI/2); ctx.fill();
            ctx.beginPath(); ctx.ellipse(cx + 42, cy - 6, 8, 22, 0.15, -Math.PI/2, Math.PI/2, true); ctx.fill();
            break;
        case 'medium':
            ctx.beginPath(); ctx.ellipse(cx, cy - 40, 46, 24, 0, Math.PI, 0); ctx.fill();
            ctx.fillRect(cx - 46, cy - 44, 92, 22);
            ctx.beginPath(); ctx.ellipse(cx - 44, cy + 8, 9, 32, -0.2, -Math.PI/2, Math.PI/2); ctx.fill();
            ctx.beginPath(); ctx.ellipse(cx + 44, cy + 8, 9, 32, 0.2, -Math.PI/2, Math.PI/2, true); ctx.fill();
            ctx.fillRect(cx - 44, cy - 2, 8, 38);
            ctx.fillRect(cx + 36, cy - 2, 8, 38);
            break;
        case 'long':
            ctx.beginPath(); ctx.ellipse(cx, cy - 40, 46, 24, 0, Math.PI, 0); ctx.fill();
            ctx.fillRect(cx - 46, cy - 44, 92, 22);
            ctx.beginPath(); ctx.roundRect(cx - 50, cy - 10, 12, 75, 6); ctx.fill();
            ctx.beginPath(); ctx.roundRect(cx + 38, cy - 10, 12, 75, 6); ctx.fill();
            break;
        case 'curly':
            for (let a = 0; a < Math.PI * 2; a += 0.35) {
                const rx = cx + Math.cos(a) * 42;
                const ry = (cy - 30) + Math.sin(a) * 24;
                if (ry < cy - 10) { ctx.beginPath(); ctx.arc(rx, ry, 9, 0, Math.PI * 2); ctx.fill(); }
            }
            ctx.beginPath(); ctx.ellipse(cx, cy - 42, 40, 18, 0, Math.PI, 0); ctx.fill();
            break;
        case 'bald':
            // Just scalp shine
            const shine = ctx.createRadialGradient(cx - 8, cy - 35, 0, cx, cy - 30, 30);
            shine.addColorStop(0, 'rgba(255,255,255,0.15)'); shine.addColorStop(1, 'transparent');
            ctx.fillStyle = shine; ctx.beginPath(); ctx.ellipse(cx, cy - 30, 42, 30, 0, 0, Math.PI * 2); ctx.fill();
            ctx.fillStyle = hCol.color;
            break;
        case 'bun':
            ctx.beginPath(); ctx.ellipse(cx, cy - 40, 44, 20, 0, Math.PI, 0); ctx.fill();
            ctx.fillRect(cx - 44, cy - 43, 88, 18);
            ctx.beginPath(); ctx.arc(cx, cy - 56, 14, 0, Math.PI * 2); ctx.fill();
            ctx.strokeStyle = hCol.color; ctx.lineWidth = 5;
            ctx.beginPath(); ctx.arc(cx, cy - 56, 14, 0, Math.PI * 2); ctx.stroke();
            ctx.fillStyle = hCol.color;
            break;
        case 'mohawk':
            ctx.beginPath(); ctx.moveTo(cx - 10, cy - 40); ctx.lineTo(cx, cy - 80); ctx.lineTo(cx + 10, cy - 40); ctx.closePath(); ctx.fill();
            ctx.beginPath(); ctx.roundRect(cx - 10, cy - 50, 20, 14, 2); ctx.fill();
            break;
        default: // none
            break;
    }

    // Reset fillStyle for accessories
    ctx.fillStyle = skin.color;

    // ── Beard ──
    if (acc.includes('beard')) {
        const beardGrad = ctx.createLinearGradient(cx - 25, cy + 18, cx + 25, cy + 55);
        beardGrad.addColorStop(0, hCol.color); beardGrad.addColorStop(1, hCol.color + 'CC');
        ctx.fillStyle = beardGrad;
        ctx.beginPath(); ctx.ellipse(cx, cy + 36, 30, 18, 0, 0, Math.PI); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx - 30, cy + 18, 60, 20, 4); ctx.fill();
        ctx.fillStyle = skin.color;
    }

    // ── Moustache ──
    if (acc.includes('moustache')) {
        ctx.fillStyle = hCol.color;
        ctx.beginPath(); ctx.ellipse(cx - 9, cy + 18, 8, 4, -0.2, 0, Math.PI * 2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx + 9, cy + 18, 8, 4, 0.2, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = skin.color;
    }

    // ── Glasses ──
    if (acc.includes('glasses')) {
        ctx.strokeStyle = '#64748b'; ctx.lineWidth = 2.5; ctx.fillStyle = 'rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx - 32, cy - 14, 24, 18, 5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx + 8, cy - 14, 24, 18, 5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx - 8, cy - 5); ctx.lineTo(cx + 8, cy - 5); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx - 42, cy - 5); ctx.lineTo(cx - 46, cy - 10); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx + 42, cy - 5); ctx.lineTo(cx + 46, cy - 10); ctx.stroke();
    }

    // ── Sunglasses ──
    if (acc.includes('sunglasses')) {
        ctx.fillStyle = '#111827'; ctx.strokeStyle = '#374151'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.roundRect(cx - 34, cy - 15, 26, 16, 5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx + 8, cy - 15, 26, 16, 5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx - 8, cy - 7); ctx.lineTo(cx + 8, cy - 7); ctx.strokeStyle = '#374151'; ctx.stroke();
        // Lenses shine
        ctx.fillStyle = 'rgba(255,255,255,0.08)';
        ctx.beginPath(); ctx.ellipse(cx - 25, cy - 9, 5, 3, -0.5, 0, Math.PI * 2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx + 17, cy - 9, 5, 3, -0.5, 0, Math.PI * 2); ctx.fill();
    }

    // ── Earring ──
    if (acc.includes('earring')) {
        ctx.fillStyle = '#FFD700'; ctx.strokeStyle = '#B8860B'; ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(cx + 44, cy + 14, 5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
        const gemGrad = ctx.createRadialGradient(cx + 43, cy + 13, 0, cx + 44, cy + 14, 5);
        gemGrad.addColorStop(0, '#fff'); gemGrad.addColorStop(1, '#FFD700');
        ctx.fillStyle = gemGrad; ctx.beginPath(); ctx.arc(cx + 44, cy + 14, 3, 0, Math.PI * 2); ctx.fill();
    }

    // ── Headband ──
    if (acc.includes('headband')) {
        ctx.strokeStyle = '#DC2626'; ctx.lineWidth = 8; ctx.lineCap = 'round';
        ctx.beginPath(); ctx.ellipse(cx, cy - 24, 44, 22, 0, 0.15, Math.PI - 0.15); ctx.stroke();
    }

    // ── Military Badge (bottom-right overlay) ──
    const badgeMap = { gurkha:'🎖', star:'⭐', military:'🪖', crown:'👑', lightning:'⚡' };
    if (badge !== 'none' && badgeMap[badge]) {
        ctx.font = '22px serif';
        ctx.textAlign = 'right'; ctx.textBaseline = 'bottom';
        ctx.shadowColor = 'rgba(0,0,0,0.5)'; ctx.shadowBlur = 4;
        ctx.fillText(badgeMap[badge], cx + 38, cy + h/2 - 6);
        ctx.shadowBlur = 0;
    }

    // ── Outer glow ring ──
    const ring = ctx.createLinearGradient(0, 0, w, h);
    ring.addColorStop(0, bg.grad[0] + '88'); ring.addColorStop(1, bg.grad[1] + '88');
    ctx.strokeStyle = ring; ctx.lineWidth = 4;
    ctx.beginPath(); ctx.arc(cx, cy, cx - 2, 0, Math.PI * 2); ctx.stroke();
}

function drawAvatar() {
    const canvas = document.getElementById('avatarCanvas');
    if (canvas) drawAvatarOnCanvas(canvas, avatarState, 200);
}

// ── Render the saved avatar in all display slots ───────────────────────────
function renderSavedAvatar() {
    [
        { containerId: 'mainAvatarInner', size: 100 },
        { containerId: 'sidebarAvContainer', size: 46 }
    ].forEach(({ containerId, size }) => {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';

        if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
            const img = document.createElement('img');
            img.src = SAVED_PHOTO_URL;
            img.style.cssText = `width:${size}px;height:${size}px;object-fit:cover;border-radius:50%;display:block`;
            img.onerror = () => renderInitial(container, size);
            container.appendChild(img);
        } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CONFIG) {
            const canvas = document.createElement('canvas');
            canvas.width = size; canvas.height = size;
            canvas.style.cssText = `width:${size}px;height:${size}px;border-radius:50%;display:block`;
            container.appendChild(canvas);
            drawAvatarOnCanvas(canvas, SAVED_AVATAR_CONFIG, size);
        } else {
            renderInitial(container, size);
        }
    });
}

function renderInitial(container, size) {
    container.innerHTML = '';
    const div = document.createElement('div');
    div.className = 'initial-av';
    div.style.cssText = `width:${size}px;height:${size}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${size*0.4}px;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff`;
    div.textContent = USER_INITIAL;
    container.appendChild(div);
}

// ── Randomize ─────────────────────────────────────────────────────────────
function randomizeAvatar() {
    const pick = arr => arr[Math.floor(Math.random() * arr.length)].id;
    const numAcc = Math.floor(Math.random() * 3);
    const accPool = [...AV_OPTIONS.accessories];
    const chosenAcc = [];
    for (let i = 0; i < numAcc; i++) {
        if (!accPool.length) break;
        const idx = Math.floor(Math.random() * accPool.length);
        chosenAcc.push(accPool.splice(idx, 1)[0].id);
    }
    avatarState = {
        bg:          pick(AV_OPTIONS.bg),
        skin:        pick(AV_OPTIONS.skin),
        hairStyle:   pick(AV_OPTIONS.hairStyle),
        hairColor:   pick(AV_OPTIONS.hairColor),
        eyes:        pick(AV_OPTIONS.eyes),
        accessories: chosenAcc,
        badge:       pick(AV_OPTIONS.badge),
    };
    buildAllOptions();
    drawAvatar();
}

// ── Save AI avatar ─────────────────────────────────────────────────────────
function saveAiAvatar() {
    document.getElementById('avatarConfigInput').value = JSON.stringify(avatarState);
    document.getElementById('aiAvatarForm').submit();
}

// ── Upload helpers ─────────────────────────────────────────────────────────
function previewUpload(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('uploadPreviewImg').src = e.target.result;
        document.getElementById('uploadPreviewName').textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
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

// ── Modal open/close ───────────────────────────────────────────────────────
function openAvatarModal() {
    document.getElementById('avatarModal').classList.add('open');
    buildAllOptions();
    drawAvatar();
}

function closeAvatarModal() {
    document.getElementById('avatarModal').classList.remove('open');
}

function switchAvTab(name, btn) {
    document.querySelectorAll('.av-method-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.av-method-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('avpane-' + name)?.classList.add('active');
    if (name === 'ai') drawAvatar();
}

// ── Drag-and-drop on upload zone ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderSavedAvatar();

    const zone = document.getElementById('uploadZone');
    if (zone) {
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
        zone.addEventListener('drop', e => {
            e.preventDefault(); zone.classList.remove('drag-over');
            const file = e.dataTransfer?.files[0];
            if (file) {
                const input = document.getElementById('photoFileInput');
                const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
                previewUpload(input);
            }
        });
    }

    // Close modal on backdrop click
    document.getElementById('avatarModal').addEventListener('click', function(e) {
        if (e.target === this) closeAvatarModal();
    });
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });

    // Progress bars animate
    document.querySelectorAll('.prog-fill').forEach(bar => {
        const w = bar.style.width; bar.style.width = '0';
        setTimeout(() => { bar.style.width = w; }, 200);
    });
});

// ════════════════════════════════════════════════════════════════
// PAGE UTILS
// ════════════════════════════════════════════════════════════════
function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (btn) btn.classList.add('active');
}

// Live BMI
const hInput = document.querySelector('input[name="height"]');
const wInput = document.querySelector('input[name="weight"]');
function updateBMI() {
    const h = parseFloat(hInput?.value) / 100, w = parseFloat(wInput?.value);
    if (!h || !w) return;
    const bmi = (w / (h * h)).toFixed(1);
    const bmiEl = document.getElementById('bmi-val'), catEl = document.getElementById('bmi-cat');
    if (!bmiEl || !catEl) return;
    bmiEl.textContent = bmi;
    let cat, col;
    if (bmi < 18.5)    { cat = 'Underweight'; col = 'rgba(59,130,246,.2); color:#93c5fd'; }
    else if (bmi < 25) { cat = 'Healthy';     col = 'rgba(16,185,129,.2); color:#6ee7b7'; }
    else if (bmi < 30) { cat = 'Overweight';  col = 'rgba(251,191,36,.2); color:#fcd34d'; }
    else               { cat = 'Obese';        col = 'rgba(239,68,68,.2); color:#fca5a5'; }
    catEl.textContent = cat;
    catEl.style.cssText = `padding:.3rem .9rem;border-radius:20px;font-size:.82rem;font-weight:600;background:${col}`;
}
if (hInput) hInput.addEventListener('input', updateBMI);
if (wInput) wInput.addEventListener('input', updateBMI);

// Password strength
function checkStrength(pw) {
    const segs = ['s1','s2','s3','s4'].map(id => document.getElementById(id));
    const txt  = document.getElementById('strength-text');
    let score = 0;
    if (pw.length >= 8)             score++;
    if (/[A-Z]/.test(pw))           score++;
    if (/[0-9]/.test(pw))           score++;
    if (/[^A-Za-z0-9]/.test(pw))   score++;
    const colors = ['#ef4444','#f59e0b','#3b82f6','#10b981'];
    const labels = ['Weak','Fair','Good','Strong'];
    segs.forEach((s, i) => { if(s) s.style.background = i < score ? colors[score - 1] : 'rgba(255,255,255,.1)'; });
    if (txt) { txt.textContent = pw.length === 0 ? 'Enter a password' : (labels[score-1] || 'Weak'); txt.style.color = score > 0 ? colors[score-1] : 'var(--text-secondary)'; }
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar'), btn = document.querySelector('.mobile-menu-btn');
    if (window.innerWidth <= 768 && sb && btn && !sb.contains(e.target) && !btn.contains(e.target)) sb.classList.remove('active');
});

<?php if ($error || $success): ?>
<?php
$activeTab = 'overview';
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') $activeTab = 'edit';
    if ($_POST['action'] === 'change_password') $activeTab = 'security';
    if ($_POST['action'] === 'delete_account')  $activeTab = 'danger';
}
?>
window.addEventListener('load', () => {
    const tabs = document.querySelectorAll('.tab'), tabNames = ['overview','edit','security','danger'];
    const idx = tabNames.indexOf('<?= $activeTab ?>');
    if (idx >= 0) switchTab('<?= $activeTab ?>', tabs[idx]);
});
<?php endif; ?>
</script>

<!-- SQL migration note (run once) -->
<?php
/*
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS avatar_type ENUM('initial','photo','ai') NOT NULL DEFAULT 'initial',
    ADD COLUMN IF NOT EXISTS avatar_config TEXT DEFAULT NULL;
*/
?>
</body>
</html>