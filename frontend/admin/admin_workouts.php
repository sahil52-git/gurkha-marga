<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_workouts.php — Full Exercise Management (Admin + Superadmin)
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

define('THUMB_DIR', dirname(dirname(dirname(__FILE__))) . '/frontend/uploads/workout_thumbs/');
define('THUMB_URL', '/gurkha-marga/frontend/uploads/workout_thumbs/');

function ensureThumbDir() {
    if (!is_dir(THUMB_DIR)) mkdir(THUMB_DIR, 0755, true);
}

function sanitizeSteps(array $titles, array $descs): array {
    $steps = [];
    foreach ($titles as $i => $t) {
        $t = trim($t); $d = trim($descs[$i] ?? '');
        if ($t || $d) $steps[] = ['title' => $t, 'desc' => $d];
    }
    return $steps;
}
function sanitizeMuscles(array $names, array $types, array $colors): array {
    $muscles = [];
    foreach ($names as $i => $n) {
        $n = trim($n);
        if ($n) $muscles[] = ['name' => $n, 'type' => trim($types[$i] ?? 'Primary'), 'color' => trim($colors[$i] ?? '#3b82f6')];
    }
    return $muscles;
}
function sanitizeTips(array $texts): array {
    $tips = [];
    foreach ($texts as $t) {
        $t = trim($t);
        if ($t) $tips[] = ['icon' => '', 'text' => $t];
    }
    return $tips;
}

function handleThumbUpload(string $fieldName): string|null|false {
    if (empty($_FILES[$fieldName]['name'])) return null;
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) return false;
    if ($file['size'] > 5 * 1024 * 1024) return false;
    $ext = match($mime) {
        'image/jpeg','image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg',
    };
    $filename = 'thumb_' . uniqid('', true) . '.' . $ext;
    ensureThumbDir();
    if (!move_uploaded_file($file['tmp_name'], THUMB_DIR . $filename)) return false;
    return $filename;
}

function columnExists(string $col): bool {
    try { $cols = fetchAll("SHOW COLUMNS FROM workouts LIKE ?", [$col]); return !empty($cols); }
    catch (Exception $e) { return false; }
}

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

if (!isset($_SESSION['workout_token'])) {
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
}

// DELETE
if ($action === 'delete' && $editId) {
    try {
        $row = fetchOne("SELECT thumb_image FROM workouts WHERE id=?", [$editId]);
        if (!empty($row['thumb_image']) && file_exists(THUMB_DIR . $row['thumb_image'])) {
            @unlink(THUMB_DIR . $row['thumb_image']);
        }
        query("DELETE FROM workouts WHERE id=?", [$editId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Exercise deleted.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Delete failed.'];
    }
    header('Location: admin_workouts.php'); exit();
}

// TOGGLE
if ($action === 'toggle' && $editId) {
    try {
        query("UPDATE workouts SET is_published=NOT is_published WHERE id=?", [$editId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Visibility updated.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Toggle failed.'];
    }
    header('Location: admin_workouts.php'); exit();
}

// UPDATE
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)$_POST['id'];
    $pub = isset($_POST['is_published']) ? 1 : 0;
    $steps   = sanitizeSteps($_POST['step_title'] ?? [], $_POST['step_desc'] ?? []);
    $muscles = sanitizeMuscles($_POST['muscle_name'] ?? [], $_POST['muscle_type'] ?? [], $_POST['muscle_color'] ?? []);
    $tips    = sanitizeTips($_POST['tip_text'] ?? []);

    $thumbSql = ''; $thumbParams = [];
    $uploadResult = handleThumbUpload('thumb_image');
    if ($uploadResult === false) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid image file.'];
        header('Location: admin_workouts.php?action=edit&id=' . $id); exit();
    }
    if ($uploadResult !== null) {
        try {
            $old = fetchOne("SELECT thumb_image FROM workouts WHERE id=?", [$id]);
            if (!empty($old['thumb_image']) && file_exists(THUMB_DIR . $old['thumb_image'])) {
                @unlink(THUMB_DIR . $old['thumb_image']);
            }
        } catch (Exception $e) {}
        $thumbSql = ', thumb_image=?'; $thumbParams = [$uploadResult];
    }
    $updatedAtSql = columnExists('updated_at') ? ', updated_at=NOW()' : '';

    try {
        $params = [
            trim($_POST['title']), $_POST['category'],
            trim($_POST['glb_file'] ?? ''), (int)$_POST['duration_minutes'],
            trim($_POST['duration_label'] ?? ''), $_POST['difficulty'],
            trim($_POST['description'] ?? ''),
            json_encode($steps, JSON_UNESCAPED_UNICODE),
            json_encode($muscles, JSON_UNESCAPED_UNICODE),
            json_encode($tips, JSON_UNESCAPED_UNICODE),
            (int)($_POST['user_id'] ?? 0), $pub,
        ];
        if ($thumbParams) $params = array_merge($params, $thumbParams);
        $params[] = $id;
        query("UPDATE workouts SET title=?, category=?, glb_file=?, duration_minutes=?, duration_label=?,
            difficulty=?, description=?, steps_json=?, muscles_json=?, tips_json=?,
            user_id=?, is_published=?" . $thumbSql . $updatedAtSql . " WHERE id=?", $params);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Exercise updated.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Update failed: ' . $e->getMessage()];
    }
    header('Location: admin_workouts.php'); exit();
}

// STORE (create)
if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['workout_token'] ?? '';
    if (!$tok || $tok !== ($_SESSION['workout_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Duplicate submission blocked.'];
        header('Location: admin_workouts.php'); exit();
    }
    unset($_SESSION['workout_token']);

    $title = trim($_POST['title'] ?? '');
    if (!$title) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Title is required.'];
        $_SESSION['workout_token'] = bin2hex(random_bytes(16));
        header('Location: admin_workouts.php?action=create'); exit();
    }

    $thumbFilename = null;
    $uploadResult  = handleThumbUpload('thumb_image');
    if ($uploadResult === false) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid image.'];
        $_SESSION['workout_token'] = bin2hex(random_bytes(16));
        header('Location: admin_workouts.php?action=create'); exit();
    }
    if ($uploadResult !== null) $thumbFilename = $uploadResult;

    $steps   = sanitizeSteps($_POST['step_title'] ?? [], $_POST['step_desc'] ?? []);
    $muscles = sanitizeMuscles($_POST['muscle_name'] ?? [], $_POST['muscle_type'] ?? [], $_POST['muscle_color'] ?? []);
    $tips    = sanitizeTips($_POST['tip_text'] ?? []);
    $adminId = $_SESSION['admin_id'] ?? 0;

    // ── FIX: Use exactly what was submitted, allow empty glb ──
    $glbFile = trim($_POST['glb_file'] ?? '');

    try {
        query("INSERT INTO workouts
            (user_id, title, category, glb_file, duration_minutes, duration_label, difficulty, description,
             steps_json, muscles_json, tips_json, thumb_image, is_published, created_by_admin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())",
            [
                (int)($_POST['user_id'] ?? 0), $title, $_POST['category'],
                $glbFile, (int)$_POST['duration_minutes'],
                trim($_POST['duration_label'] ?? ''), $_POST['difficulty'],
                trim($_POST['description'] ?? ''),
                json_encode($steps, JSON_UNESCAPED_UNICODE),
                json_encode($muscles, JSON_UNESCAPED_UNICODE),
                json_encode($tips, JSON_UNESCAPED_UNICODE),
                $thumbFilename, $adminId
            ]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Exercise \"{$title}\" created."];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Create failed: ' . $e->getMessage()];
    }
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
    header('Location: admin_workouts.php'); exit();
}

require_once __DIR__ . '/admin_layout.php';

if (!in_array($adminRole, ['admin', 'superadmin'])) {
    setFlash('error', 'Access denied.'); redirectTo('admin_dashboard.php');
}

try {
    query("CREATE TABLE IF NOT EXISTS workouts (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL DEFAULT 0,
        title            VARCHAR(200) NOT NULL,
        category         VARCHAR(80) DEFAULT 'general',
        glb_file         VARCHAR(100) DEFAULT '',
        duration_minutes INT DEFAULT 30,
        duration_label   VARCHAR(60) DEFAULT '',
        difficulty       ENUM('beginner','intermediate','advanced','expert') DEFAULT 'intermediate',
        description      TEXT,
        steps_json       LONGTEXT DEFAULT NULL,
        muscles_json     LONGTEXT DEFAULT NULL,
        tips_json        LONGTEXT DEFAULT NULL,
        thumb_image      VARCHAR(200) DEFAULT NULL,
        is_published     TINYINT(1) DEFAULT 1,
        created_by_admin INT DEFAULT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", []);

    $cols = fetchAll("SHOW COLUMNS FROM workouts", []);
    $colNames = array_column($cols, 'Field');
    $migrations = [
        'glb_file'         => "ALTER TABLE workouts ADD COLUMN glb_file VARCHAR(100) DEFAULT '' AFTER category",
        'duration_label'   => "ALTER TABLE workouts ADD COLUMN duration_label VARCHAR(60) DEFAULT '' AFTER duration_minutes",
        'difficulty'       => "ALTER TABLE workouts ADD COLUMN difficulty ENUM('beginner','intermediate','advanced','expert') DEFAULT 'intermediate' AFTER duration_label",
        'steps_json'       => "ALTER TABLE workouts ADD COLUMN steps_json LONGTEXT DEFAULT NULL AFTER description",
        'muscles_json'     => "ALTER TABLE workouts ADD COLUMN muscles_json LONGTEXT DEFAULT NULL AFTER steps_json",
        'tips_json'        => "ALTER TABLE workouts ADD COLUMN tips_json LONGTEXT DEFAULT NULL AFTER muscles_json",
        'thumb_image'      => "ALTER TABLE workouts ADD COLUMN thumb_image VARCHAR(200) DEFAULT NULL AFTER tips_json",
        'created_by_admin' => "ALTER TABLE workouts ADD COLUMN created_by_admin INT DEFAULT NULL AFTER is_published",
        'updated_at'       => "ALTER TABLE workouts ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $colNames)) { try { query($sql, []); } catch (Exception $e) {} }
    }
} catch (Exception $e) {}

if (!isset($_SESSION['workout_token'])) {
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
}
ensureThumbDir();

// ── GLB scanner — FIXED: only use real files, no fallbacks ───────────────────
$modelsDir = dirname(dirname(dirname(__FILE__))) . '/models/';
$glbFiles  = [];
if (is_dir($modelsDir)) {
    $files = glob($modelsDir . '*.glb');
    if ($files) {
        foreach ($files as $f) $glbFiles[] = basename($f);
        sort($glbFiles);
    }
}
// No fallback to exercise1.glb..exercise8.glb — that was causing the wrong GLB selection bug

$search    = $_GET['search']   ?? '';
$catFilter = $_GET['category'] ?? '';
$wPage     = max(1, (int)($_GET['p'] ?? 1));
$perPage   = 20;
$offset    = ($wPage - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($search) {
    $where[]  = '(w.title LIKE ? OR w.description LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($catFilter) { $where[] = 'w.category=?'; $params[] = $catFilter; }
$whereSQL = implode(' AND ', $where);

$total        = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts w LEFT JOIN users u ON w.user_id=u.id WHERE $whereSQL", $params)['c'] ?? 0);
$workoutsList = fetchAll("SELECT w.*, u.full_name as user_name FROM workouts w LEFT JOIN users u ON w.user_id=u.id WHERE $whereSQL ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset", $params);
$wPages       = (int)ceil($total / $perPage);
$workoutUsers = fetchAll("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name");
$editWorkout  = ($action === 'edit' && $editId) ? fetchOne("SELECT * FROM workouts WHERE id=?", [$editId]) : null;

$wStats = [];
try {
    $wStats['total']     = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts")['c'] ?? 0);
    $wStats['published'] = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE is_published=1")['c'] ?? 0);
    $wStats['global']    = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE user_id=0")['c'] ?? 0);
    $wStats['has_steps'] = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE steps_json IS NOT NULL AND steps_json != '[]'")['c'] ?? 0);
} catch (Exception $e) {}

$categories   = ['military'=>'Military PT','strength'=>'Strength','cardio'=>'Cardio','endurance'=>'Endurance','hiit'=>'HIIT','flexibility'=>'Flexibility','general'=>'General','chest'=>'Chest','back'=>'Back','legs'=>'Legs','shoulders'=>'Shoulders','arms'=>'Arms','core'=>'Core & Abs','full_body'=>'Full Body'];
$catColors    = ['military'=>'badge-green','strength'=>'badge-blue','cardio'=>'badge-red','endurance'=>'badge-amber','hiit'=>'badge-red','flexibility'=>'badge-muted','general'=>'badge-muted','chest'=>'badge-blue','back'=>'badge-green','legs'=>'badge-green','shoulders'=>'badge-amber','arms'=>'badge-amber','core'=>'badge-red','full_body'=>'badge-blue'];
$difficulties = ['beginner'=>'Beginner','intermediate'=>'Intermediate','advanced'=>'Advanced','expert'=>'Expert'];

$editSteps = $editMuscles = $editTips = [];
if ($editWorkout) {
    $editSteps   = json_decode($editWorkout['steps_json']   ?? '[]', true) ?: [];
    $editMuscles = json_decode($editWorkout['muscles_json'] ?? '[]', true) ?: [];
    $editTips    = json_decode($editWorkout['tips_json']    ?? '[]', true) ?: [];
}

$defaultSteps   = [['title'=>'','desc'=>'']];
$defaultMuscles = [['name'=>'','type'=>'Primary','color'=>'#3b82f6']];
$defaultTips    = [['text'=>'']];
?>

<style>
/* ── Form sections ── */
.form-section { margin-bottom: 1.5rem; }
.form-section-title {
  font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  color:var(--text-3); padding-bottom:.55rem; border-bottom:1px solid var(--border);
  margin-bottom:.9rem; display:flex; align-items:center; gap:.4rem;
}
.section-icon {
  width:16px; height:16px; flex-shrink:0; color:var(--text-3);
}
.dynamic-row { display:grid; gap:.6rem; margin-bottom:.55rem; align-items:start; }
.dynamic-row.steps-row  { grid-template-columns: 1fr 2fr auto; }
.dynamic-row.muscle-row { grid-template-columns: 2fr 1fr 64px auto; }
.dynamic-row.tip-row    { grid-template-columns: 1fr auto; }
.row-remove {
  width:28px; height:28px; background:var(--red-dim); border:1px solid var(--red-hi);
  border-radius:5px; cursor:pointer; color:#fca5a5; font-size:.75rem;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
  transition:background .12s; line-height:1;
}
.row-remove:hover { background:rgba(239,68,68,.25); }
.add-row-btn {
  display:inline-flex; align-items:center; gap:4px;
  font-size:.75rem; font-weight:600; color:var(--accent); background:none; border:none;
  cursor:pointer; padding:.25rem 0; transition:opacity .15s;
}
.add-row-btn:hover { opacity:.7; }
.color-swatch { width:100%; height:34px; border-radius:5px; border:1px solid var(--border); cursor:pointer; }

/* ── Thumb upload ── */
.thumb-upload-wrap {
  border:2px dashed var(--border-hi); border-radius:10px; overflow:hidden;
  cursor:pointer; transition:border-color .15s; background:rgba(15,23,42,.4); position:relative;
}
.thumb-upload-wrap:hover { border-color:var(--accent); }
.thumb-upload-wrap input[type=file] {
  position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; z-index:2;
}
.thumb-upload-inner {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:.4rem; padding:1rem; min-height:80px; pointer-events:none;
}
.thumb-upload-inner svg { width:20px; height:20px; color:var(--text-3); }
.thumb-upload-inner span { font-size:.7rem; color:var(--text-3); }
.thumb-upload-inner strong { font-size:.74rem; color:var(--text-2); }
.thumb-preview-wrap { position:relative; height:110px; border-radius:9px; overflow:hidden; }
.thumb-preview-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.thumb-preview-label {
  position:absolute; bottom:0; left:0; right:0;
  background:rgba(0,0,0,.55); font-size:.62rem; color:rgba(255,255,255,.7);
  padding:.2rem .5rem; text-align:center;
}
/* Thumb in table */
.tbl-thumb { width:40px; height:30px; border-radius:5px; object-fit:cover; border:1px solid var(--border); display:block; }
.tbl-no-thumb {
  width:40px; height:30px; border-radius:5px;
  background:rgba(255,255,255,.04); border:1px solid var(--border);
  display:flex; align-items:center; justify-content:center;
}
.tbl-no-thumb svg { width:13px; height:13px; color:var(--text-3); }

/* ── Confirm dialog ── */
.confirm-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,.75); backdrop-filter:blur(8px);
  z-index:9999; display:none; align-items:center; justify-content:center; padding:1rem;
}
.confirm-overlay.open { display:flex; animation:cfadeIn .18s ease; }
@keyframes cfadeIn { from { opacity:0; } to { opacity:1; } }
.confirm-box {
  background:#1e293b; border:1px solid rgba(255,255,255,.1); border-radius:14px;
  padding:2rem; max-width:380px; width:100%; text-align:center;
  animation:cslideUp .2s ease;
}
@keyframes cslideUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
.confirm-icon {
  width:44px; height:44px; border-radius:10px; margin:0 auto .9rem;
  display:flex; align-items:center; justify-content:center;
}
.confirm-icon.danger { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25); }
.confirm-icon.warning { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25); }
.confirm-icon svg { width:22px; height:22px; }
.confirm-title { font-size:1.05rem; font-weight:700; margin-bottom:.4rem; }
.confirm-desc  { font-size:.82rem; color:var(--text-2, #94a3b8); margin-bottom:1.5rem; line-height:1.5; }
.confirm-actions { display:flex; gap:.65rem; justify-content:center; }
.confirm-btn {
  padding:.55rem 1.35rem; border-radius:8px; font-size:.83rem; font-weight:600;
  cursor:pointer; font-family:inherit; border:none; display:inline-flex;
  align-items:center; gap:6px; transition:all .15s;
}
.confirm-btn-cancel { background:rgba(255,255,255,.07); color:#94a3b8; border:1px solid rgba(255,255,255,.1); }
.confirm-btn-cancel:hover { background:rgba(255,255,255,.12); color:#fff; }
.confirm-btn-danger { background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }
.confirm-btn-danger:hover { background:rgba(239,68,68,.25); }
.confirm-btn-warning { background:rgba(245,158,11,.12); color:#fcd34d; border:1px solid rgba(245,158,11,.3); }
.confirm-btn-warning:hover { background:rgba(245,158,11,.2); }
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Exercise Library</h1>
    <p class="page-sub">Manage exercises, 3D models, steps, muscles and tips</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modal-createWorkout')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Exercise
  </button>
</div>

<!-- Stats -->
<div class="stats-grid mb" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card"><div class="stat-label">Total</div><div class="stat-val"><?= $wStats['total'] ?? 0 ?></div><div class="stat-sub">In library</div></div>
  <div class="stat-card"><div class="stat-label">Published</div><div class="stat-val"><?= $wStats['published'] ?? 0 ?></div><div class="stat-sub">Visible to users</div></div>
  <div class="stat-card"><div class="stat-label">Global</div><div class="stat-val"><?= $wStats['global'] ?? 0 ?></div><div class="stat-sub">All-user access</div></div>
  <div class="stat-card"><div class="stat-label">With Steps</div><div class="stat-val"><?= $wStats['has_steps'] ?? 0 ?></div><div class="stat-sub">Full instructions</div></div>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" style="display:contents">
    <input type="text" name="search" placeholder="Search exercises..." value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="category" onchange="this.form.submit()" style="max-width:160px">
      <option value="">All categories</option>
      <?php foreach ($categories as $k => $v): ?>
      <option value="<?= $k ?>" <?= $catFilter===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    <?php if ($search || $catFilter): ?><a href="admin_workouts.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    <span style="margin-left:auto;font-family:var(--mono);font-size:.72rem;color:var(--text-3)"><?= $total ?> exercises</span>
  </form>
</div>

<!-- Table -->
<div class="card mb">
  <div class="tbl-wrap">
  <table class="data-table">
    <thead>
    <tr>
      <th>ID</th><th>Thumb</th><th>Exercise</th><th>GLB File</th><th>Category</th>
      <th>Difficulty</th><th>Steps</th><th>Assigned To</th><th>Added</th><th>Status</th><th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($workoutsList)): ?>
    <tr><td colspan="11" class="empty-row">No exercises yet. Add your first one!</td></tr>
    <?php else: foreach ($workoutsList as $w):
      $stepsCount = count(json_decode($w['steps_json'] ?? '[]', true) ?: []);
      $thumbSrc   = !empty($w['thumb_image']) ? THUMB_URL . htmlspecialchars($w['thumb_image']) : null;
    ?>
    <tr>
      <td class="mono" style="color:var(--text-3)"><?= $w['id'] ?></td>
      <td>
        <?php if ($thumbSrc): ?>
        <img class="tbl-thumb" src="<?= $thumbSrc ?>" alt="">
        <?php else: ?>
        <div class="tbl-no-thumb"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg></div>
        <?php endif; ?>
      </td>
      <td>
        <div style="font-weight:600"><?= htmlspecialchars($w['title']) ?></div>
        <?php if ($w['description']): ?>
        <div class="dim" style="font-size:.72rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(substr($w['description'], 0, 55)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($w['glb_file']): ?>
        <span class="mono" style="font-size:.72rem;color:var(--green)"><?= htmlspecialchars($w['glb_file']) ?></span>
        <?php else: ?><span style="color:var(--text-3);font-size:.75rem">none</span><?php endif; ?>
      </td>
      <td><span class="badge <?= $catColors[$w['category']] ?? 'badge-muted' ?>"><?= $categories[$w['category']] ?? ucfirst($w['category']) ?></span></td>
      <td><span class="badge badge-muted"><?= ucfirst($w['difficulty'] ?? '') ?></span></td>
      <td><span class="mono" style="font-size:.75rem;color:<?= $stepsCount>0?'var(--green)':'var(--text-3)' ?>"><?= $stepsCount ?></span></td>
      <td class="dim"><?= $w['user_id'] ? htmlspecialchars($w['user_name'] ?? '(deleted)') : '<span class="badge badge-muted">Global</span>' ?></td>
      <td class="mono dim"><?= date('d M Y', strtotime($w['created_at'])) ?></td>
      <td><span class="badge <?= $w['is_published']?'badge-green':'badge-red' ?>"><?= $w['is_published']?'Live':'Hidden' ?></span></td>
      <td>
        <div class="tbl-actions">
          <a href="admin_workouts.php?action=edit&id=<?= $w['id'] ?>" class="act-btn edit">Edit</a>
          <button class="act-btn toggle"
            onclick="confirmToggle('admin_workouts.php?action=toggle&id=<?= $w['id'] ?>','<?= addslashes($w['title']) ?>',<?= $w['is_published']?'true':'false' ?>)">
            <?= $w['is_published'] ? 'Hide' : 'Publish' ?>
          </button>
          <button class="act-btn del"
            onclick="confirmDelete('admin_workouts.php?action=delete&id=<?= $w['id'] ?>','<?= addslashes($w['title']) ?>')">
            Delete
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($wPages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $wPages; $i++): ?>
    <a href="?p=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $catFilter ?>" class="pg-btn <?= $i===$wPage?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ CONFIRM DIALOG ═══ -->
<div class="confirm-overlay" id="confirmDialog">
  <div class="confirm-box">
    <div class="confirm-icon" id="confirmIcon">
      <svg id="confirmIconSvg" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"></svg>
    </div>
    <div class="confirm-title" id="confirmTitle"></div>
    <div class="confirm-desc"  id="confirmDesc"></div>
    <div class="confirm-actions">
      <button class="confirm-btn confirm-btn-cancel" onclick="closeConfirm()">Cancel</button>
      <a class="confirm-btn" id="confirmActionBtn" href="#">Confirm</a>
    </div>
  </div>
</div>

<!-- ═══ CREATE MODAL ═══ -->
<div class="modal-overlay <?= $action==='create'?'open':'' ?>" id="modal-createWorkout">
  <div class="modal" style="max-width:740px">
    <div class="modal-head">
      <div class="modal-title">Add New Exercise</div>
      <button class="modal-close" onclick="closeModal('modal-createWorkout')">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="POST" action="admin_workouts.php?action=store" id="createForm" enctype="multipart/form-data">
      <input type="hidden" name="workout_token" value="<?= htmlspecialchars($_SESSION['workout_token'] ?? '') ?>">

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          Basic Information
        </div>
        <div class="form-grid">
          <div class="field full"><label>Exercise Title *</label><input type="text" name="title" required placeholder="e.g. Standard Push-Up"></div>
          <div class="field"><label>Category</label>
            <select name="category">
              <?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Difficulty</label>
            <select name="difficulty">
              <?php foreach ($difficulties as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>3D Model (GLB)</label>
            <select name="glb_file">
              <option value="">— No 3D Model —</option>
              <?php foreach ($glbFiles as $f): ?><option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Assign to User</label>
            <select name="user_id">
              <option value="0">Global (all users)</option>
              <?php foreach ($workoutUsers as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="30" min="1"></div>
          <div class="field"><label>Duration Label</label><input type="text" name="duration_label" placeholder="e.g. 3 sets x 20 reps"></div>
          <div class="field full"><label>Description</label><textarea name="description" rows="2" placeholder="Brief overview of the exercise..."></textarea></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          Thumbnail Image <span style="font-weight:400;color:var(--text-3);font-size:.63rem;text-transform:none;letter-spacing:0;margin-left:.35rem">optional</span>
        </div>
        <div class="thumb-upload-wrap" id="createThumbWrap">
          <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'createThumbPreview')">
          <div class="thumb-upload-inner" id="createThumbPreview">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
            <strong>Click to upload thumbnail</strong>
            <span>JPG, PNG, WebP — max 5 MB</span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Steps
          <span id="createStepsCount" style="font-size:.68rem;color:var(--text-3);font-weight:400;margin-left:.25rem">(<?= count($defaultSteps) ?>)</span>
        </div>
        <div id="createStepsList">
          <?php foreach ($defaultSteps as $s): ?>
          <div class="dynamic-row steps-row" data-row="step">
            <input type="text" name="step_title[]" placeholder="Step title" value="<?= htmlspecialchars($s['title']) ?>">
            <textarea name="step_desc[]" placeholder="Description..." rows="2"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'createStepsCount','createStepsList')">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('createStepsList','createStepsCount')">+ Add Step</button>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Muscle Groups
        </div>
        <div id="createMusclesList">
          <?php foreach ($defaultMuscles as $m): ?>
          <div class="dynamic-row muscle-row">
            <input type="text" name="muscle_name[]" placeholder="e.g. Quadriceps" value="<?= htmlspecialchars($m['name']) ?>">
            <select name="muscle_type[]">
              <?php foreach (['Primary','Secondary','Stabiliser'] as $t): ?><option <?= $m['type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="<?= $m['color'] ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addMuscle('createMusclesList')">+ Add Muscle</button>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Pro Tips
        </div>
        <div id="createTipsList">
          <?php foreach ($defaultTips as $t): ?>
          <div class="dynamic-row tip-row">
            <input type="text" name="tip_text[]" placeholder="Enter a tip..." value="<?= htmlspecialchars($t['text'] ?? '') ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addTip('createTipsList')">+ Add Tip</button>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modal-createWorkout')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="createBtn">Save Exercise</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT MODAL ═══ -->
<?php if ($action === 'edit' && $editWorkout): ?>
<?php $editThumbSrc = !empty($editWorkout['thumb_image']) ? THUMB_URL . htmlspecialchars($editWorkout['thumb_image']) : null; ?>
<div class="modal-overlay open" id="modal-editWorkout">
  <div class="modal" style="max-width:740px">
    <div class="modal-head">
      <div class="modal-title">Edit — <?= htmlspecialchars($editWorkout['title']) ?></div>
      <a href="admin_workouts.php" class="modal-close">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </a>
    </div>
    <form method="POST" action="admin_workouts.php?action=update" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?= $editWorkout['id'] ?>">

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          Basic Information
        </div>
        <div class="form-grid">
          <div class="field full"><label>Exercise Title *</label><input type="text" name="title" required value="<?= htmlspecialchars($editWorkout['title']) ?>"></div>
          <div class="field"><label>Category</label>
            <select name="category">
              <?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>" <?= $editWorkout['category']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Difficulty</label>
            <select name="difficulty">
              <?php foreach ($difficulties as $k => $v): ?><option value="<?= $k ?>" <?= ($editWorkout['difficulty']??'')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>3D Model (GLB)</label>
            <select name="glb_file">
              <option value="">— No 3D Model —</option>
              <?php foreach ($glbFiles as $f): ?><option value="<?= htmlspecialchars($f) ?>" <?= ($editWorkout['glb_file']??'')===$f?'selected':'' ?>><?= htmlspecialchars($f) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Assign to User</label>
            <select name="user_id">
              <option value="0">Global</option>
              <?php foreach ($workoutUsers as $u): ?><option value="<?= $u['id'] ?>" <?= $editWorkout['user_id']==$u['id']?'selected':'' ?>><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="<?= $editWorkout['duration_minutes'] ?>" min="1"></div>
          <div class="field"><label>Duration Label</label><input type="text" name="duration_label" value="<?= htmlspecialchars($editWorkout['duration_label'] ?? '') ?>" placeholder="e.g. 3 sets x 20 reps"></div>
          <div class="field full"><label>Description</label><textarea name="description" rows="2"><?= htmlspecialchars($editWorkout['description'] ?? '') ?></textarea></div>
          <div class="field row-check">
            <input type="checkbox" name="is_published" value="1" <?= $editWorkout['is_published']?'checked':'' ?>>
            <label>Published (visible on user dashboard)</label>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          Thumbnail Image <span style="font-weight:400;color:var(--text-3);font-size:.63rem;text-transform:none;letter-spacing:0;margin-left:.35rem">upload new to replace current</span>
        </div>
        <?php if ($editThumbSrc): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;align-items:start">
          <div class="thumb-preview-wrap">
            <img src="<?= $editThumbSrc ?>" alt="Current thumbnail">
            <div class="thumb-preview-label">Current thumbnail</div>
          </div>
          <div class="thumb-upload-wrap">
            <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'editThumbPreview')">
            <div class="thumb-upload-inner" id="editThumbPreview">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
              <strong>Upload new</strong>
              <span>JPG, PNG, WebP — max 5 MB</span>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="thumb-upload-wrap">
          <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'editThumbPreview')">
          <div class="thumb-upload-inner" id="editThumbPreview">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
            <strong>Click to upload thumbnail</strong>
            <span>JPG, PNG, WebP — max 5 MB</span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Steps
          <span id="editStepsCount" style="font-size:.68rem;color:var(--text-3);font-weight:400;margin-left:.25rem">(<?= count($editSteps) ?>)</span>
        </div>
        <div id="editStepsList">
          <?php foreach (!empty($editSteps) ? $editSteps : [['title'=>'','desc'=>'']] as $s): ?>
          <div class="dynamic-row steps-row">
            <input type="text" name="step_title[]" value="<?= htmlspecialchars($s['title']) ?>" placeholder="Step title">
            <textarea name="step_desc[]" rows="2"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'editStepsCount','editStepsList')">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('editStepsList','editStepsCount')">+ Add Step</button>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Muscle Groups
        </div>
        <div id="editMusclesList">
          <?php foreach (!empty($editMuscles) ? $editMuscles : [['name'=>'','type'=>'Primary','color'=>'#3b82f6']] as $m): ?>
          <div class="dynamic-row muscle-row">
            <input type="text" name="muscle_name[]" value="<?= htmlspecialchars($m['name']) ?>" placeholder="e.g. Quadriceps">
            <select name="muscle_type[]">
              <?php foreach (['Primary','Secondary','Stabiliser'] as $t): ?><option <?= ($m['type']??'')===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="<?= htmlspecialchars($m['color']??'#3b82f6') ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addMuscle('editMusclesList')">+ Add Muscle</button>
      </div>

      <div class="form-section">
        <div class="form-section-title">
          <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Pro Tips
        </div>
        <div id="editTipsList">
          <?php foreach (!empty($editTips) ? $editTips : [['text'=>'']] as $t): ?>
          <div class="dynamic-row tip-row">
            <input type="text" name="tip_text[]" value="<?= htmlspecialchars($t['text'] ?? '') ?>" placeholder="Enter a tip...">
            <button type="button" class="row-remove" onclick="removeRow(this)">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addTip('editTipsList')">+ Add Tip</button>
      </div>

      <div class="modal-foot">
        <a href="admin_workouts.php" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// ── Row helpers ───────────────────────────────────────────────────────────────
function removeRow(btn, counterId, listId) {
  const row = btn.closest('.dynamic-row');
  if (row) { row.remove(); if (counterId && listId) updateCount(counterId, listId); }
}
function updateCount(counterId, listId) {
  const el = document.getElementById(counterId);
  const list = document.getElementById(listId);
  if (!el || !list) return;
  const cnt = list.querySelectorAll('.dynamic-row').length;
  el.textContent = '(' + cnt + ')';
}
const xSvg = `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>`;
function addStep(listId, counterId) {
  const list = document.getElementById(listId);
  const row  = document.createElement('div');
  row.className = 'dynamic-row steps-row'; row.setAttribute('data-row','step');
  row.innerHTML = `
    <input type="text" name="step_title[]" placeholder="Step title">
    <textarea name="step_desc[]" rows="2" placeholder="Description..."></textarea>
    <button type="button" class="row-remove" onclick="removeRow(this,'${counterId}','${listId}')">${xSvg}</button>`;
  list.appendChild(row);
  updateCount(counterId, listId);
  row.querySelector('input').focus();
}
function addMuscle(listId) {
  const list = document.getElementById(listId);
  const row  = document.createElement('div');
  row.className = 'dynamic-row muscle-row';
  row.innerHTML = `
    <input type="text" name="muscle_name[]" placeholder="e.g. Quadriceps">
    <select name="muscle_type[]"><option>Primary</option><option>Secondary</option><option>Stabiliser</option></select>
    <input type="color" name="muscle_color[]" class="color-swatch" value="#3b82f6">
    <button type="button" class="row-remove" onclick="removeRow(this)">${xSvg}</button>`;
  list.appendChild(row);
  row.querySelector('input').focus();
}
function addTip(listId) {
  const list = document.getElementById(listId);
  const row  = document.createElement('div');
  row.className = 'dynamic-row tip-row';
  row.innerHTML = `
    <input type="text" name="tip_text[]" placeholder="Enter a tip...">
    <button type="button" class="row-remove" onclick="removeRow(this)">${xSvg}</button>`;
  list.appendChild(row);
  row.querySelector('input').focus();
}

// ── Thumb preview ─────────────────────────────────────────────────────────────
function previewThumb(input, previewId) {
  const preview = document.getElementById(previewId);
  const file    = input.files[0];
  if (!file || !preview) return;
  const reader  = new FileReader();
  reader.onload = e => {
    preview.innerHTML = `
      <div class="thumb-preview-wrap" style="width:100%;height:90px">
        <img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:7px">
        <div class="thumb-preview-label">${file.name}</div>
      </div>`;
  };
  reader.readAsDataURL(file);
}

// ── Confirm dialog ────────────────────────────────────────────────────────────
function showConfirm({ title, desc, actionHref, btnClass, btnText, iconType, iconPath }) {
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmDesc').textContent  = desc;
  const btn = document.getElementById('confirmActionBtn');
  btn.href      = actionHref;
  btn.className = 'confirm-btn ' + btnClass;
  btn.textContent = btnText;
  const icon = document.getElementById('confirmIcon');
  icon.className = 'confirm-icon ' + iconType;
  document.getElementById('confirmIconSvg').innerHTML = iconPath;
  document.getElementById('confirmDialog').classList.add('open');
}
function closeConfirm() {
  document.getElementById('confirmDialog').classList.remove('open');
}
document.getElementById('confirmDialog').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeConfirm();
});

function confirmDelete(href, title) {
  showConfirm({
    title: 'Delete Exercise',
    desc: `Are you sure you want to permanently delete "${title}"? This cannot be undone.`,
    actionHref: href,
    btnClass: 'confirm-btn-danger',
    btnText: 'Delete',
    iconType: 'danger',
    iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>',
  });
}
function confirmToggle(href, title, isPublished) {
  if (isPublished) {
    showConfirm({
      title: 'Hide Exercise',
      desc: `"${title}" will be hidden from users.`,
      actionHref: href,
      btnClass: 'confirm-btn-warning',
      btnText: 'Hide',
      iconType: 'warning',
      iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>',
    });
  } else {
    showConfirm({
      title: 'Publish Exercise',
      desc: `"${title}" will be visible to users.`,
      actionHref: href,
      btnClass: 'confirm-btn-warning',
      btnText: 'Publish',
      iconType: 'warning',
      iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
    });
  }
}

// Dedup guard on create
document.getElementById('createForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('createBtn');
  btn.disabled = true; btn.textContent = 'Saving...';
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeConfirm(); });
</script>

<?php layoutFooter(); ?>