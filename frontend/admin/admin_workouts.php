<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_workouts.php — Full Exercise Management (Admin + Superadmin)
//  Features: GLB assignment, step editor, muscle groups, tips, categories,
//            thumbnail image upload
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

// ── Upload directory ─────────────────────────────────────────────────────────
define('THUMB_DIR', dirname(dirname(dirname(__FILE__))) . '/frontend/uploads/workout_thumbs/');
define('THUMB_URL', '/gurkha-marga/frontend/uploads/workout_thumbs/');

function ensureThumbDir() {
    if (!is_dir(THUMB_DIR)) {
        mkdir(THUMB_DIR, 0755, true);
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
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
        if ($n) $muscles[] = ['name' => $n, 'type' => trim($types[$i] ?? 'Primary'), 'color' => trim($colors[$i] ?? '#e63b2e')];
    }
    return $muscles;
}
function sanitizeTips(array $icons, array $texts): array {
    $tips = [];
    foreach ($icons as $i => $ic) {
        $t = trim($texts[$i] ?? '');
        if ($t) $tips[] = ['icon' => trim($ic) ?: '💡', 'text' => $t];
    }
    return $tips;
}

/**
 * Handle thumbnail upload. Returns filename on success, null if no file, false on error.
 */
function handleThumbUpload(string $fieldName): string|null|false {
    if (empty($_FILES[$fieldName]['name'])) return null;
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) return false;

    if ($file['size'] > 5 * 1024 * 1024) return false; // 5 MB max

    $ext      = match($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };
    $filename = 'thumb_' . uniqid('', true) . '.' . $ext;
    ensureThumbDir();
    if (!move_uploaded_file($file['tmp_name'], THUMB_DIR . $filename)) return false;
    return $filename;
}

/**
 * Check if a column exists in the workouts table.
 * Uses a fresh DB call so it works even before the migration block runs.
 */
function columnExists(string $col): bool {
    try {
        $cols = fetchAll("SHOW COLUMNS FROM workouts LIKE ?", [$col]);
        return !empty($cols);
    } catch (Exception $e) {
        return false;
    }
}

// ── ALL REDIRECTING ACTIONS BEFORE layout ────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

if (!isset($_SESSION['workout_token'])) {
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
}

// DELETE
if ($action === 'delete' && $editId) {
    try {
        // also delete thumb file
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
    $tips    = sanitizeTips($_POST['tip_icon'] ?? [], $_POST['tip_text'] ?? []);

    // Handle thumbnail upload
    $thumbSql    = '';
    $thumbParams = [];
    $uploadResult = handleThumbUpload('thumb_image');
    if ($uploadResult === false) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid image file. Use JPG, PNG, WebP, or GIF under 5MB.'];
        header('Location: admin_workouts.php?action=edit&id=' . $id); exit();
    }
    if ($uploadResult !== null) {
        // Delete old thumb
        try {
            $old = fetchOne("SELECT thumb_image FROM workouts WHERE id=?", [$id]);
            if (!empty($old['thumb_image']) && file_exists(THUMB_DIR . $old['thumb_image'])) {
                @unlink(THUMB_DIR . $old['thumb_image']);
            }
        } catch (Exception $e) {}
        $thumbSql    = ', thumb_image=?';
        $thumbParams = [$uploadResult];
    }

    // Safely append updated_at only if the column exists
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

        query("UPDATE workouts SET
            title=?, category=?, glb_file=?, duration_minutes=?, duration_label=?,
            difficulty=?, description=?, steps_json=?, muscles_json=?, tips_json=?,
            user_id=?, is_published=?" . $thumbSql . $updatedAtSql . "
            WHERE id=?", $params);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Exercise updated successfully.'];
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

    // Handle thumbnail upload
    $thumbFilename = null;
    $uploadResult  = handleThumbUpload('thumb_image');
    if ($uploadResult === false) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid image. Use JPG, PNG, WebP, or GIF under 5MB.'];
        $_SESSION['workout_token'] = bin2hex(random_bytes(16));
        header('Location: admin_workouts.php?action=create'); exit();
    }
    if ($uploadResult !== null) $thumbFilename = $uploadResult;

    $steps   = sanitizeSteps($_POST['step_title'] ?? [], $_POST['step_desc'] ?? []);
    $muscles = sanitizeMuscles($_POST['muscle_name'] ?? [], $_POST['muscle_type'] ?? [], $_POST['muscle_color'] ?? []);
    $tips    = sanitizeTips($_POST['tip_icon'] ?? [], $_POST['tip_text'] ?? []);
    $adminId = $_SESSION['admin_id'] ?? 0;

    try {
        query("INSERT INTO workouts
            (user_id, title, category, glb_file, duration_minutes, duration_label, difficulty, description,
             steps_json, muscles_json, tips_json, thumb_image, is_published, created_by_admin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())",
            [
                (int)($_POST['user_id'] ?? 0), $title, $_POST['category'],
                trim($_POST['glb_file'] ?? ''), (int)$_POST['duration_minutes'],
                trim($_POST['duration_label'] ?? ''), $_POST['difficulty'],
                trim($_POST['description'] ?? ''),
                json_encode($steps, JSON_UNESCAPED_UNICODE),
                json_encode($muscles, JSON_UNESCAPED_UNICODE),
                json_encode($tips, JSON_UNESCAPED_UNICODE),
                $thumbFilename,
                $adminId
            ]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Exercise \"{$title}\" created and published."];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Create failed: ' . $e->getMessage()];
    }
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
    header('Location: admin_workouts.php'); exit();
}

// ── NOW safe to load layout ───────────────────────────────────────────────────
require_once __DIR__ . '/admin_layout.php';

if (!in_array($adminRole, ['admin', 'superadmin'])) {
    setFlash('error', 'Access denied.');
    redirectTo('admin_dashboard.php');
}

// ── Ensure workouts table + columns ──────────────────────────────────────────
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

    $cols     = fetchAll("SHOW COLUMNS FROM workouts", []);
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
        if (!in_array($col, $colNames)) {
            try { query($sql, []); } catch (Exception $e) {}
        }
    }
} catch (Exception $e) {}

if (!isset($_SESSION['workout_token'])) {
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
}
ensureThumbDir();

// ── GLB scanner ───────────────────────────────────────────────────────────────
$modelsDir = dirname(dirname(dirname(__FILE__))) . '/models/';
$glbFiles  = [];
if (is_dir($modelsDir)) {
    $files = glob($modelsDir . '*.glb');
    if ($files) foreach ($files as $f) $glbFiles[] = basename($f);
}
if (empty($glbFiles)) {
    for ($i = 1; $i <= 8; $i++) $glbFiles[] = "exercise{$i}.glb";
}
sort($glbFiles);

// ── Query workouts
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

$defaultSteps   = [['title'=>'','desc'=>''],['title'=>'','desc'=>''],['title'=>'','desc'=>'']];
$defaultMuscles = [['name'=>'','type'=>'Primary','color'=>'#e63b2e'],['name'=>'','type'=>'Secondary','color'=>'#3b6ff5']];
$defaultTips    = [['icon'=>'💡','text'=>''],['icon'=>'⚡','text'=>'']];
?>

<style>
.form-section { margin-bottom: 1.4rem; }
.form-section-title {
  font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  color:var(--text-3); padding-bottom:.6rem; border-bottom:1px solid var(--border);
  margin-bottom:.9rem; display:flex; align-items:center; gap:.5rem;
}
.form-section-title span { font-size:.9rem; }
.dynamic-row {
  display:grid; gap:.65rem; margin-bottom:.6rem; align-items:start;
}
.dynamic-row.steps-row  { grid-template-columns: 1fr 2fr auto; }
.dynamic-row.muscle-row { grid-template-columns: 2fr 1fr 80px auto; }
.dynamic-row.tip-row    { grid-template-columns: 60px 1fr auto; }
.row-remove {
  width:28px; height:28px; background:var(--red-dim); border:1px solid var(--red-hi);
  border-radius:5px; cursor:pointer; color:#fca5a5; font-size:.9rem;
  display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px;
  transition:all .12s;
}
.row-remove:hover { background:rgba(239,68,68,.25); }
.add-row-btn {
  display:inline-flex; align-items:center; gap:5px;
  font-size:.75rem; font-weight:600; color:var(--accent); background:none; border:none;
  cursor:pointer; padding:.3rem 0; transition:opacity .15s;
}
.add-row-btn:hover { opacity:.75; }
.color-swatch { width:100%; height:36px; border-radius:5px; border:1px solid var(--border); cursor:pointer; }
.steps-count { font-family:var(--mono); font-size:.72rem; color:var(--text-3); }

/* ── Thumbnail upload ── */
.thumb-upload-wrap {
  position:relative; border:2px dashed var(--border-hi); border-radius:10px;
  overflow:hidden; cursor:pointer; transition:border-color .15s;
  background:rgba(15,23,42,.4);
}
.thumb-upload-wrap:hover { border-color:var(--accent); }
.thumb-upload-wrap input[type=file] {
  position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
}
.thumb-upload-inner {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:.5rem; padding:1.1rem; min-height:90px; pointer-events:none;
}
.thumb-upload-inner svg { width:22px; height:22px; color:var(--text-3); }
.thumb-upload-inner span { font-size:.72rem; color:var(--text-3); }
.thumb-upload-inner strong { font-size:.75rem; color:var(--text-2); }

.thumb-preview-wrap { position:relative; height:120px; border-radius:10px; overflow:hidden; }
.thumb-preview-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.thumb-preview-label {
  position:absolute; bottom:0; left:0; right:0;
  background:rgba(0,0,0,.55); font-size:.65rem; color:rgba(255,255,255,.7);
  padding:.25rem .5rem; text-align:center;
}
.thumb-preview-remove {
  position:absolute; top:.4rem; right:.4rem;
  width:22px; height:22px; border-radius:4px;
  background:rgba(239,68,68,.8); border:none; cursor:pointer;
  color:#fff; font-size:.75rem; display:flex; align-items:center; justify-content:center;
}
/* Thumb in table */
.tbl-thumb {
  width:42px; height:32px; border-radius:5px; object-fit:cover;
  border:1px solid var(--border); display:block;
}
.tbl-no-thumb {
  width:42px; height:32px; border-radius:5px;
  background:rgba(255,255,255,.04); border:1px solid var(--border);
  display:flex; align-items:center; justify-content:center;
  font-size:.6rem; color:var(--text-3);
}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Exercise Library</h1>
    <p class="page-sub">Create exercises with thumbnails, 3D models, steps, muscles &amp; tips</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modal-createWorkout')">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
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

<!-- Info -->
<div style="background:var(--accent-dim);border:1px solid var(--accent-hi);border-radius:var(--r);padding:.8rem 1rem;margin-bottom:1.1rem;font-size:.8rem;color:#93c5fd;display:flex;align-items:center;gap:.65rem">
  <span>ℹ</span>
  <span>GLB files → <code style="font-family:var(--mono);background:rgba(59,130,246,.15);padding:.1em .3em;border-radius:3px">models/</code> folder. Thumbnail images are shown on the user exercise cards. Published exercises appear on the user workout page.</span>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" style="display:contents">
    <input type="text" name="search" placeholder="Search exercises…" value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="category" onchange="this.form.submit()" style="max-width:160px">
      <option value="">All categories</option>
      <?php foreach ($categories as $k => $v): ?>
      <option value="<?= $k ?>" <?= $catFilter===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    <?php if ($search || $catFilter): ?><a href="admin_workouts.php" class="btn btn-ghost btn-sm">✕ Clear</a><?php endif; ?>
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
        <div class="tbl-no-thumb">—</div>
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
        <?php else: ?><span style="color:var(--text-3);font-size:.75rem">not set</span><?php endif; ?>
      </td>
      <td><span class="badge <?= $catColors[$w['category']] ?? 'badge-muted' ?>"><?= $categories[$w['category']] ?? ucfirst($w['category']) ?></span></td>
      <td><span class="badge badge-muted"><?= ucfirst($w['difficulty'] ?? '—') ?></span></td>
      <td><span class="mono" style="font-size:.75rem;color:<?= $stepsCount>0?'var(--green)':'var(--text-3)' ?>"><?= $stepsCount ?> step<?= $stepsCount!==1?'s':'' ?></span></td>
      <td class="dim"><?= $w['user_id'] ? htmlspecialchars($w['user_name'] ?? '(deleted)') : '<span class="badge badge-muted">Global</span>' ?></td>
      <td class="mono dim"><?= date('d M Y', strtotime($w['created_at'])) ?></td>
      <td><span class="badge <?= $w['is_published']?'badge-green':'badge-red' ?>"><?= $w['is_published']?'Live':'Hidden' ?></span></td>
      <td>
        <div class="tbl-actions">
          <a href="admin_workouts.php?action=edit&id=<?= $w['id'] ?>" class="act-btn edit">Edit</a>
          <a href="admin_workouts.php?action=toggle&id=<?= $w['id'] ?>"
             class="act-btn toggle"
             onclick="return confirm('<?= $w['is_published']?'Hide':'Publish' ?> this exercise?')">
             <?= $w['is_published'] ? 'Hide' : 'Publish' ?>
          </a>
          <button class="act-btn del"
                  onclick="confirmDelete('admin_workouts.php?action=delete&id=<?= $w['id'] ?>','Delete \'<?= addslashes($w['title']) ?>\'?')">Delete</button>
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

<!-- ═══ CREATE MODAL ═══ -->
<div class="modal-overlay <?= $action==='create'?'open':'' ?>" id="modal-createWorkout">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <div class="modal-title">Add New Exercise</div>
      <button class="modal-close" onclick="closeModal('modal-createWorkout')">✕</button>
    </div>
    <form method="POST" action="admin_workouts.php?action=store" id="createForm" enctype="multipart/form-data">
      <input type="hidden" name="workout_token" value="<?= htmlspecialchars($_SESSION['workout_token'] ?? '') ?>">

      <!-- Basic Info -->
      <div class="form-section">
        <div class="form-section-title"><span>📋</span> Basic Information</div>
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
          <div class="field"><label>3D Model (GLB File)</label>
            <select name="glb_file">
              <option value="">— No 3D Model —</option>
              <?php foreach ($glbFiles as $f): ?><option value="<?= $f ?>"><?= $f ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Assign to User</label>
            <select name="user_id">
              <option value="0">🌐 Global (all users)</option>
              <?php foreach ($workoutUsers as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="30" min="1"></div>
          <div class="field"><label>Duration Label</label><input type="text" name="duration_label" placeholder="e.g. 3 sets × 20 reps"></div>
          <div class="field full"><label>Description</label><textarea name="description" rows="2" placeholder="Brief overview of the exercise…"></textarea></div>
        </div>
      </div>

      <!-- Thumbnail Upload -->
      <div class="form-section">
        <div class="form-section-title"><span>🖼️</span> Thumbnail Image <span style="font-weight:400;color:var(--text-3);font-size:.65rem;text-transform:none;letter-spacing:0">(optional — shown on exercise cards)</span></div>
        <div class="thumb-upload-wrap" id="createThumbWrap">
          <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'createThumbPreview','createThumbWrap')">
          <div class="thumb-upload-inner" id="createThumbPreview">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <strong>Click to upload thumbnail</strong>
            <span>JPG, PNG, WebP · max 5 MB</span>
          </div>
        </div>
      </div>

      <!-- Steps -->
      <div class="form-section">
        <div class="form-section-title">
          <span>📍</span> Step-by-Step Instructions
          <span class="steps-count" id="createStepsCount">(<?= count($defaultSteps) ?> steps)</span>
        </div>
        <div id="createStepsList">
          <?php foreach ($defaultSteps as $s): ?>
          <div class="dynamic-row steps-row" data-row="step">
            <input type="text" name="step_title[]" placeholder="Step title" value="<?= htmlspecialchars($s['title']) ?>">
            <textarea name="step_desc[]" placeholder="Step description…" rows="2"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'createStepsCount')">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('createStepsList','createStepsCount')">+ Add Step</button>
      </div>

      <!-- Muscles -->
      <div class="form-section">
        <div class="form-section-title"><span>💪</span> Muscle Groups</div>
        <div id="createMusclesList">
          <?php foreach ($defaultMuscles as $m): ?>
          <div class="dynamic-row muscle-row" data-row="muscle">
            <input type="text" name="muscle_name[]" placeholder="Muscle name" value="<?= htmlspecialchars($m['name']) ?>">
            <select name="muscle_type[]">
              <?php foreach (['Primary','Secondary','Stabiliser'] as $t): ?><option <?= $m['type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="<?= $m['color'] ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addMuscle('createMusclesList')">+ Add Muscle</button>
      </div>

      <!-- Tips -->
      <div class="form-section">
        <div class="form-section-title"><span>💡</span> Pro Tips</div>
        <div id="createTipsList">
          <?php foreach ($defaultTips as $t): ?>
          <div class="dynamic-row tip-row" data-row="tip">
            <input type="text" name="tip_icon[]" placeholder="💡" value="<?= htmlspecialchars($t['icon']) ?>" style="text-align:center;font-size:1.1rem">
            <input type="text" name="tip_text[]" placeholder="Tip text…" value="<?= htmlspecialchars($t['text']) ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addTip('createTipsList')">+ Add Tip</button>
      </div>

      <div style="background:var(--green-dim);border:1px solid var(--green-hi);border-radius:5px;padding:.6rem .8rem;font-size:.76rem;color:#6ee7b7">
        ✓ This exercise will be <strong>published</strong> and immediately visible on the user workout dashboard.
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
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <div class="modal-title">Edit — <?= htmlspecialchars($editWorkout['title']) ?></div>
      <a href="admin_workouts.php" class="modal-close">✕</a>
    </div>
    <form method="POST" action="admin_workouts.php?action=update" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?= $editWorkout['id'] ?>">

      <div class="form-section">
        <div class="form-section-title"><span>📋</span> Basic Information</div>
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
          <div class="field"><label>3D Model (GLB File)</label>
            <select name="glb_file">
              <option value="">— No 3D Model —</option>
              <?php foreach ($glbFiles as $f): ?><option value="<?= $f ?>" <?= ($editWorkout['glb_file']??'')===$f?'selected':'' ?>><?= $f ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Assign to User</label>
            <select name="user_id">
              <option value="0">🌐 Global</option>
              <?php foreach ($workoutUsers as $u): ?><option value="<?= $u['id'] ?>" <?= $editWorkout['user_id']==$u['id']?'selected':'' ?>><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="<?= $editWorkout['duration_minutes'] ?>" min="1"></div>
          <div class="field"><label>Duration Label</label><input type="text" name="duration_label" value="<?= htmlspecialchars($editWorkout['duration_label'] ?? '') ?>" placeholder="e.g. 3 sets × 20 reps"></div>
          <div class="field full"><label>Description</label><textarea name="description" rows="2"><?= htmlspecialchars($editWorkout['description'] ?? '') ?></textarea></div>
          <div class="field row-check">
            <input type="checkbox" name="is_published" value="1" <?= $editWorkout['is_published']?'checked':'' ?>>
            <label>Published (visible on user dashboard)</label>
          </div>
        </div>
      </div>

      <!-- Thumbnail Upload (edit) -->
      <div class="form-section">
        <div class="form-section-title"><span>🖼️</span> Thumbnail Image <span style="font-weight:400;color:var(--text-3);font-size:.65rem;text-transform:none;letter-spacing:0">(upload new to replace current)</span></div>
        <?php if ($editThumbSrc): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;align-items:start">
          <div>
            <div class="thumb-preview-wrap">
              <img src="<?= $editThumbSrc ?>" alt="Current thumbnail">
              <div class="thumb-preview-label">Current thumbnail</div>
            </div>
          </div>
          <div class="thumb-upload-wrap" id="editThumbWrap">
            <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'editThumbPreview','editThumbWrap')">
            <div class="thumb-upload-inner" id="editThumbPreview">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              <strong>Upload new thumbnail</strong>
              <span>JPG, PNG, WebP · max 5 MB</span>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="thumb-upload-wrap" id="editThumbWrap">
          <input type="file" name="thumb_image" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewThumb(this,'editThumbPreview','editThumbWrap')">
          <div class="thumb-upload-inner" id="editThumbPreview">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <strong>Click to upload thumbnail</strong>
            <span>JPG, PNG, WebP · max 5 MB</span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Steps -->
      <div class="form-section">
        <div class="form-section-title">
          <span>📍</span> Steps
          <span class="steps-count" id="editStepsCount">(<?= count($editSteps) ?> steps)</span>
        </div>
        <div id="editStepsList">
          <?php foreach (!empty($editSteps) ? $editSteps : [['title'=>'','desc'=>'']] as $s): ?>
          <div class="dynamic-row steps-row">
            <input type="text" name="step_title[]" value="<?= htmlspecialchars($s['title']) ?>" placeholder="Step title">
            <textarea name="step_desc[]" rows="2"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'editStepsCount')">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('editStepsList','editStepsCount')">+ Add Step</button>
      </div>

      <!-- Muscles -->
      <div class="form-section">
        <div class="form-section-title"><span>💪</span> Muscles</div>
        <div id="editMusclesList">
          <?php foreach (!empty($editMuscles) ? $editMuscles : [['name'=>'','type'=>'Primary','color'=>'#e63b2e']] as $m): ?>
          <div class="dynamic-row muscle-row">
            <input type="text" name="muscle_name[]" value="<?= htmlspecialchars($m['name']) ?>" placeholder="Muscle name">
            <select name="muscle_type[]">
              <?php foreach (['Primary','Secondary','Stabiliser'] as $t): ?><option <?= ($m['type']??'')===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="<?= htmlspecialchars($m['color']??'#e63b2e') ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addMuscle('editMusclesList')">+ Add Muscle</button>
      </div>

      <!-- Tips -->
      <div class="form-section">
        <div class="form-section-title"><span>💡</span> Tips</div>
        <div id="editTipsList">
          <?php foreach (!empty($editTips) ? $editTips : [['icon'=>'💡','text'=>'']] as $t): ?>
          <div class="dynamic-row tip-row">
            <input type="text" name="tip_icon[]" value="<?= htmlspecialchars($t['icon']??'💡') ?>" placeholder="💡" style="text-align:center;font-size:1.1rem">
            <input type="text" name="tip_text[]" value="<?= htmlspecialchars($t['text']) ?>" placeholder="Tip text…">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
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
function removeRow(btn, counterId) {
  const row = btn.closest('[data-row]') || btn.closest('.dynamic-row');
  if (row) { row.remove(); if (counterId) updateCount(counterId); }
}
function updateCount(id) {
  const el = document.getElementById(id); if (!el) return;
  const container = document.getElementById(id.replace('Count','List')); if (!container) return;
  const cnt = container.querySelectorAll('.dynamic-row').length;
  el.textContent = `(${cnt} step${cnt!==1?'s':''})`;
}
function addStep(listId, counterId) {
  const list = document.getElementById(listId);
  const row = document.createElement('div');
  row.className = 'dynamic-row steps-row'; row.setAttribute('data-row','step');
  row.innerHTML = `
    <input type="text" name="step_title[]" placeholder="Step title">
    <textarea name="step_desc[]" rows="2" placeholder="Description…"></textarea>
    <button type="button" class="row-remove" onclick="removeRow(this,'${counterId}')">✕</button>`;
  list.appendChild(row);
  if (counterId) updateCount(counterId);
  row.querySelector('input').focus();
}
function addMuscle(listId) {
  const list = document.getElementById(listId);
  const row = document.createElement('div');
  row.className = 'dynamic-row muscle-row';
  row.innerHTML = `
    <input type="text" name="muscle_name[]" placeholder="Muscle name">
    <select name="muscle_type[]"><option>Primary</option><option>Secondary</option><option>Stabiliser</option></select>
    <input type="color" name="muscle_color[]" class="color-swatch" value="#e63b2e">
    <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>`;
  list.appendChild(row);
  row.querySelector('input').focus();
}
function addTip(listId) {
  const list = document.getElementById(listId);
  const row = document.createElement('div');
  row.className = 'dynamic-row tip-row';
  row.innerHTML = `
    <input type="text" name="tip_icon[]" value="💡" placeholder="💡" style="text-align:center;font-size:1.1rem">
    <input type="text" name="tip_text[]" placeholder="Tip text…">
    <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>`;
  list.appendChild(row);
  row.querySelector('input[name="tip_text[]"]').focus();
}

// ── Thumbnail preview ─────────────────────────────────────────────────────────
function previewThumb(input, previewId, wrapId) {
  const preview = document.getElementById(previewId);
  const file    = input.files[0];
  if (!file || !preview) return;

  const reader = new FileReader();
  reader.onload = e => {
    preview.innerHTML = `
      <div class="thumb-preview-wrap" style="width:100%;height:110px">
        <img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:8px">
        <div class="thumb-preview-label">${file.name}</div>
      </div>`;
  };
  reader.readAsDataURL(file);
}

// Dedup guard
document.getElementById('createForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('createBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
});
</script>

<?php layoutFooter(); ?>