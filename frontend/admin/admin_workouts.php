<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_workouts.php — Full Exercise Management (Admin + Superadmin)
//  Features: GLB assignment, step editor, muscle groups, tips, categories
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// Only admin and superadmin can access this page
if (!in_array($adminRole, ['admin', 'superadmin'])) {
    setFlash('error', 'Access denied. Admin or Superadmin required.');
    redirectTo('admin_dashboard.php');
}

// Ensure full workouts table exists with all fields
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
        steps_json       LONGTEXT DEFAULT NULL COMMENT 'JSON array of {title,desc}',
        muscles_json     LONGTEXT DEFAULT NULL COMMENT 'JSON array of {name,type,color}',
        tips_json        LONGTEXT DEFAULT NULL COMMENT 'JSON array of {icon,text}',
        is_published     TINYINT(1) DEFAULT 1,
        created_by_admin INT DEFAULT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", []);

    // Add missing columns if upgrading from old schema
    $cols = fetchAll("SHOW COLUMNS FROM workouts", []);
    $colNames = array_column($cols, 'Field');
    $migrations = [
        'glb_file'       => "ALTER TABLE workouts ADD COLUMN glb_file VARCHAR(100) DEFAULT '' AFTER category",
        'duration_label' => "ALTER TABLE workouts ADD COLUMN duration_label VARCHAR(60) DEFAULT '' AFTER duration_minutes",
        'difficulty'     => "ALTER TABLE workouts ADD COLUMN difficulty ENUM('beginner','intermediate','advanced','expert') DEFAULT 'intermediate' AFTER duration_label",
        'steps_json'     => "ALTER TABLE workouts ADD COLUMN steps_json LONGTEXT DEFAULT NULL AFTER description",
        'muscles_json'   => "ALTER TABLE workouts ADD COLUMN muscles_json LONGTEXT DEFAULT NULL AFTER steps_json",
        'tips_json'      => "ALTER TABLE workouts ADD COLUMN tips_json LONGTEXT DEFAULT NULL AFTER muscles_json",
        'created_by_admin' => "ALTER TABLE workouts ADD COLUMN created_by_admin INT DEFAULT NULL AFTER is_published",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $colNames)) {
            try { query($sql, []); } catch (Exception $e) {}
        }
    }
} catch (Exception $e) {}

// ── Helpers ─────────────────────────────────────────────────────────────────
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

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ── CSRF token ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
}

// ── GLB file scanner ─────────────────────────────────────────────────────────
$modelsDir = dirname(dirname(dirname(__FILE__))) . '/models/';
$glbFiles = [];
if (is_dir($modelsDir)) {
    $files = glob($modelsDir . '*.glb');
    if ($files) {
        foreach ($files as $f) $glbFiles[] = basename($f);
    }
}
// Fallback: numbered list
if (empty($glbFiles)) {
    for ($i = 1; $i <= 29; $i++) $glbFiles[] = "exercise{$i}.glb";
}
sort($glbFiles);

// ── Actions ──────────────────────────────────────────────────────────────────
if ($action === 'delete' && $editId) {
    try {
        query("DELETE FROM workouts WHERE id=?", [$editId]);
        setFlash('success', 'Exercise deleted.');
    } catch (Exception $e) { setFlash('error', 'Delete failed.'); }
    redirectTo('admin_workouts.php');
}

if ($action === 'toggle' && $editId) {
    try {
        query("UPDATE workouts SET is_published=NOT is_published WHERE id=?", [$editId]);
        setFlash('success', 'Visibility updated.');
    } catch (Exception $e) { setFlash('error', 'Toggle failed.'); }
    redirectTo('admin_workouts.php');
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $pub = isset($_POST['is_published']) ? 1 : 0;
    $steps   = sanitizeSteps($_POST['step_title'] ?? [], $_POST['step_desc'] ?? []);
    $muscles = sanitizeMuscles($_POST['muscle_name'] ?? [], $_POST['muscle_type'] ?? [], $_POST['muscle_color'] ?? []);
    $tips    = sanitizeTips($_POST['tip_icon'] ?? [], $_POST['tip_text'] ?? []);
    try {
        query("UPDATE workouts SET
            title=?, category=?, glb_file=?, duration_minutes=?, duration_label=?,
            difficulty=?, description=?, steps_json=?, muscles_json=?, tips_json=?,
            user_id=?, is_published=?, updated_at=NOW()
            WHERE id=?",
            [
                trim($_POST['title']), $_POST['category'],
                trim($_POST['glb_file'] ?? ''), (int)$_POST['duration_minutes'],
                trim($_POST['duration_label'] ?? ''), $_POST['difficulty'],
                trim($_POST['description'] ?? ''),
                json_encode($steps, JSON_UNESCAPED_UNICODE),
                json_encode($muscles, JSON_UNESCAPED_UNICODE),
                json_encode($tips, JSON_UNESCAPED_UNICODE),
                (int)($_POST['user_id'] ?? 0), $pub, $id
            ]);
        setFlash('success', 'Exercise updated successfully.');
    } catch (Exception $e) {
        setFlash('error', 'Update failed: ' . $e->getMessage());
    }
    redirectTo('admin_workouts.php');
}

if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dedup guard
    $tok = $_POST['workout_token'] ?? '';
    if (!$tok || $tok !== ($_SESSION['workout_token'] ?? '')) {
        setFlash('error', 'Duplicate submission blocked.');
        redirectTo('admin_workouts.php');
    }
    unset($_SESSION['workout_token']);

    $title = trim($_POST['title'] ?? '');
    if (!$title) {
        setFlash('error', 'Title is required.');
        $_SESSION['workout_token'] = bin2hex(random_bytes(16));
        redirectTo('admin_workouts.php?action=create');
    }

    $steps   = sanitizeSteps($_POST['step_title'] ?? [], $_POST['step_desc'] ?? []);
    $muscles = sanitizeMuscles($_POST['muscle_name'] ?? [], $_POST['muscle_type'] ?? [], $_POST['muscle_color'] ?? []);
    $tips    = sanitizeTips($_POST['tip_icon'] ?? [], $_POST['tip_text'] ?? []);

    try {
        query("INSERT INTO workouts
            (user_id, title, category, glb_file, duration_minutes, duration_label, difficulty, description,
             steps_json, muscles_json, tips_json, is_published, created_by_admin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())",
            [
                (int)($_POST['user_id'] ?? 0), $title, $_POST['category'],
                trim($_POST['glb_file'] ?? ''), (int)$_POST['duration_minutes'],
                trim($_POST['duration_label'] ?? ''), $_POST['difficulty'],
                trim($_POST['description'] ?? ''),
                json_encode($steps, JSON_UNESCAPED_UNICODE),
                json_encode($muscles, JSON_UNESCAPED_UNICODE),
                json_encode($tips, JSON_UNESCAPED_UNICODE),
                $adminId
            ]);
        setFlash('success', "Exercise \"{$title}\" created and published.");
    } catch (Exception $e) {
        setFlash('error', 'Create failed: ' . $e->getMessage());
    }
    $_SESSION['workout_token'] = bin2hex(random_bytes(16));
    redirectTo('admin_workouts.php');
}

// ── Query ────────────────────────────────────────────────────────────────────
$search    = $_GET['search'] ?? '';
$catFilter = $_GET['category'] ?? '';
$wPage     = max(1, (int)($_GET['p'] ?? 1));
$perPage   = 20;
$offset    = ($wPage - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = '(w.title LIKE ? OR w.description LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($catFilter) { $where[] = 'w.category=?'; $params[] = $catFilter; }
$whereSQL = implode(' AND ', $where);

$total        = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts w LEFT JOIN users u ON w.user_id=u.id WHERE $whereSQL", $params)['c'] ?? 0);
$workoutsList = fetchAll("SELECT w.*, u.full_name as user_name FROM workouts w LEFT JOIN users u ON w.user_id=u.id WHERE $whereSQL ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset", $params);
$wPages       = (int)ceil($total / $perPage);
$workoutUsers = fetchAll("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name");
$editWorkout  = ($action === 'edit' && $editId) ? fetchOne("SELECT * FROM workouts WHERE id=?", [$editId]) : null;

// Stats
$wStats = [];
try {
    $wStats['total']     = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts")['c'] ?? 0);
    $wStats['published'] = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE is_published=1")['c'] ?? 0);
    $wStats['global']    = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE user_id=0")['c'] ?? 0);
    $wStats['has_steps'] = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts WHERE steps_json IS NOT NULL AND steps_json != '[]'")['c'] ?? 0);
} catch (Exception $e) {}

$categories = ['military'=>'Military PT','strength'=>'Strength','cardio'=>'Cardio','endurance'=>'Endurance','hiit'=>'HIIT','flexibility'=>'Flexibility','general'=>'General'];
$catColors  = ['military'=>'badge-green','strength'=>'badge-blue','cardio'=>'badge-red','endurance'=>'badge-amber','hiit'=>'badge-red','flexibility'=>'badge-muted','general'=>'badge-muted'];
$difficulties = ['beginner'=>'Beginner','intermediate'=>'Intermediate','advanced'=>'Advanced','expert'=>'Expert'];

// Decode JSON fields for edit
$editSteps = $editMuscles = $editTips = [];
if ($editWorkout) {
    $editSteps   = json_decode($editWorkout['steps_json']   ?? '[]', true) ?: [];
    $editMuscles = json_decode($editWorkout['muscles_json'] ?? '[]', true) ?: [];
    $editTips    = json_decode($editWorkout['tips_json']    ?? '[]', true) ?: [];
}

// Default step/muscle/tip rows for new exercise
$defaultSteps   = [['title'=>'','desc'=>''],['title'=>'','desc'=>''],['title'=>'','desc'=>'']];
$defaultMuscles = [['name'=>'','type'=>'Primary','color'=>'#e63b2e'],['name'=>'','type'=>'Secondary','color'=>'#3b6ff5']];
$defaultTips    = [['icon'=>'💡','text'=>''],['icon'=>'⚡','text'=>'']];
?>

<style>
/* ── Exercise form styles ── */
.form-section { margin-bottom: 1.4rem; }
.form-section-title {
  font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  color:var(--text-dim); padding-bottom:.6rem; border-bottom:1px solid var(--border);
  margin-bottom:.9rem; display:flex; align-items:center; gap:.5rem;
}
.form-section-title span { font-size:.9rem; }
.dynamic-row {
  display:grid; gap:.65rem; margin-bottom:.6rem; align-items:start;
}
.dynamic-row.steps-row { grid-template-columns: 1fr 2fr auto; }
.dynamic-row.muscle-row { grid-template-columns: 2fr 1fr 80px auto; }
.dynamic-row.tip-row { grid-template-columns: 60px 1fr auto; }
.row-remove {
  width:28px; height:28px; background:var(--red-dim); border:1px solid var(--red-border);
  border-radius:5px; cursor:pointer; color:#e05555; font-size:.9rem;
  display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px;
  transition:all .12s;
}
.row-remove:hover { background:rgba(204,51,51,.25); }
.add-row-btn {
  display:inline-flex; align-items:center; gap:5px;
  font-size:.75rem; font-weight:600; color:var(--accent); background:none; border:none;
  cursor:pointer; padding:.3rem 0; transition:opacity .15s;
}
.add-row-btn:hover { opacity:.75; }
.color-swatch { width:100%; height:36px; border-radius:5px; border:1px solid var(--border); cursor:pointer; }
.preview-badge {
  font-size:.7rem; font-family:var(--mono); background:var(--elevated); border:1px solid var(--border);
  padding:.2rem .5rem; border-radius:4px; color:var(--text-sub);
}
.steps-count { font-family:var(--mono); font-size:.72rem; color:var(--text-dim); }
.glb-select-wrap { position:relative; }
.glb-preview { margin-top:.4rem; font-size:.72rem; color:var(--text-dim); }
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Exercise Library</h1>
    <p class="page-sub">Create 3D exercises with steps, muscles & tips — visible on user workout dashboard</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modal-createWorkout')">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Exercise
  </button>
</div>

<!-- Stats -->
<div class="stats-grid mb" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total Exercises</div>
    <div class="stat-val"><?= $wStats['total'] ?? 0 ?></div>
    <div class="stat-sub">In library</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Published</div>
    <div class="stat-val"><?= $wStats['published'] ?? 0 ?></div>
    <div class="stat-sub">Visible to users</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Global Exercises</div>
    <div class="stat-val"><?= $wStats['global'] ?? 0 ?></div>
    <div class="stat-sub">All-user access</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">With Steps</div>
    <div class="stat-val"><?= $wStats['has_steps'] ?? 0 ?></div>
    <div class="stat-sub">Full 3D instructions</div>
  </div>
</div>

<!-- Info -->
<div style="background:var(--accent-dim);border:1px solid var(--accent-border);border-radius:var(--r);padding:.8rem 1rem;margin-bottom:1.1rem;font-size:.8rem;color:#7fa3f7;display:flex;align-items:center;gap:.65rem">
  <span>ℹ</span>
  <span>GLB files should be placed in your <code style="font-family:var(--mono);background:rgba(59,111,245,.15);padding:.1em .3em;border-radius:3px">models/</code> folder. Published exercises appear on the user workout dashboard with 3D viewer, step-by-step instructions, and muscle maps.</span>
</div>

<!-- Filters -->
<div class="filter-bar">
  <form method="GET" style="display:contents">
    <input type="text" name="search" placeholder="Search exercises…"
           value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="category" onchange="this.form.submit()" style="max-width:150px">
      <option value="">All categories</option>
      <?php foreach ($categories as $k => $v): ?>
      <option value="<?= $k ?>" <?= $catFilter===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    <?php if ($search || $catFilter): ?>
    <a href="admin_workouts.php" class="btn btn-ghost btn-sm">✕ Clear</a>
    <?php endif; ?>
    <span style="margin-left:auto;font-family:var(--mono);font-size:.72rem;color:var(--text-dim)"><?= $total ?> exercises</span>
  </form>
</div>

<!-- Table -->
<div class="card mb">
  <div class="tbl-wrap">
  <table class="data-table">
    <thead>
    <tr>
      <th>ID</th>
      <th>Exercise</th>
      <th>GLB File</th>
      <th>Category</th>
      <th>Difficulty</th>
      <th>Steps</th>
      <th>Assigned To</th>
      <th>Added</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($workoutsList)): ?>
    <tr><td colspan="10" class="empty-row">No exercises yet. Add your first one!</td></tr>
    <?php else: foreach ($workoutsList as $w):
      $steps   = json_decode($w['steps_json'] ?? '[]', true) ?: [];
      $stepsCount = count($steps);
    ?>
    <tr>
      <td class="mono" style="color:var(--text-dim)"><?= $w['id'] ?></td>
      <td>
        <div style="font-weight:600"><?= htmlspecialchars($w['title']) ?></div>
        <?php if ($w['description']): ?>
        <div class="dim" style="font-size:.72rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(substr($w['description'], 0, 60)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($w['glb_file']): ?>
        <span class="mono" style="font-size:.72rem;color:var(--green)"><?= htmlspecialchars($w['glb_file']) ?></span>
        <?php else: ?>
        <span style="color:var(--text-dim);font-size:.75rem">not set</span>
        <?php endif; ?>
      </td>
      <td><span class="badge <?= $catColors[$w['category']] ?? 'badge-muted' ?>"><?= $categories[$w['category']] ?? ucfirst($w['category']) ?></span></td>
      <td><span class="badge badge-muted"><?= ucfirst($w['difficulty'] ?? '—') ?></span></td>
      <td>
        <span class="mono" style="font-size:.75rem;color:<?= $stepsCount>0?'var(--green)':'var(--text-dim)' ?>"><?= $stepsCount ?> step<?= $stepsCount!==1?'s':'' ?></span>
      </td>
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
                  onclick="confirmDelete('admin_workouts.php?action=delete&id=<?= $w['id'] ?>',
                  'Delete \'<?= addslashes($w['title']) ?>\'?')">Delete</button>
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
    <a href="?p=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $catFilter ?>"
       class="pg-btn <?= $i===$wPage?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ CREATE EXERCISE MODAL ═══ -->
<div class="modal-overlay <?= $action==='create'?'open':'' ?>" id="modal-createWorkout">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <div class="modal-title">Add New Exercise</div>
      <button class="modal-close" onclick="closeModal('modal-createWorkout')">✕</button>
    </div>
    <form method="POST" action="admin_workouts.php?action=store" id="createForm">
      <input type="hidden" name="workout_token" value="<?= htmlspecialchars($_SESSION['workout_token'] ?? '') ?>">

      <!-- ── Basic Info ── -->
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
            <div class="glb-select-wrap">
              <select name="glb_file">
                <option value="">— No 3D Model —</option>
                <?php foreach ($glbFiles as $f): ?><option value="<?= $f ?>"><?= $f ?></option><?php endforeach; ?>
              </select>
            </div>
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

      <!-- ── Steps ── -->
      <div class="form-section">
        <div class="form-section-title">
          <span>📍</span> Step-by-Step Instructions
          <span class="steps-count" id="createStepsCount">(<?= count($defaultSteps) ?> steps)</span>
        </div>
        <div id="createStepsList">
          <?php foreach ($defaultSteps as $i => $s): ?>
          <div class="dynamic-row steps-row" data-row="step">
            <input type="text" name="step_title[]" placeholder="Step title" value="<?= htmlspecialchars($s['title']) ?>">
            <textarea name="step_desc[]" placeholder="Step description…" rows="2"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this, 'createStepsCount')">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('createStepsList','createStepsCount')">+ Add Step</button>
      </div>

      <!-- ── Muscles ── -->
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

      <!-- ── Tips ── -->
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

      <div style="background:var(--green-dim);border:1px solid var(--green-border);border-radius:5px;padding:.6rem .8rem;font-size:.76rem;color:#3dc96a">
        ✓ This exercise will be <strong>published and visible</strong> on the user workout dashboard with the 3D viewer.
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modal-createWorkout')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="createBtn">Save Exercise</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ EDIT EXERCISE MODAL ═══ -->
<?php if ($action === 'edit' && $editWorkout): ?>
<div class="modal-overlay open" id="modal-editWorkout">
  <div class="modal" style="max-width:760px">
    <div class="modal-head">
      <div class="modal-title">Edit — <?= htmlspecialchars($editWorkout['title']) ?></div>
      <a href="admin_workouts.php" class="modal-close">✕</a>
    </div>
    <form method="POST" action="admin_workouts.php?action=update">
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

      <div class="form-section">
        <div class="form-section-title">
          <span>📍</span> Steps
          <span class="steps-count" id="editStepsCount">(<?= count($editSteps) ?> steps)</span>
        </div>
        <div id="editStepsList">
          <?php foreach ($editSteps as $s): ?>
          <div class="dynamic-row steps-row">
            <input type="text" name="step_title[]" value="<?= htmlspecialchars($s['title']) ?>" placeholder="Step title">
            <textarea name="step_desc[]" rows="2" placeholder="Description…"><?= htmlspecialchars($s['desc']) ?></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'editStepsCount')">✕</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($editSteps)): ?>
          <div class="dynamic-row steps-row">
            <input type="text" name="step_title[]" placeholder="Step title">
            <textarea name="step_desc[]" rows="2" placeholder="Description…"></textarea>
            <button type="button" class="row-remove" onclick="removeRow(this,'editStepsCount')">✕</button>
          </div>
          <?php endif; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addStep('editStepsList','editStepsCount')">+ Add Step</button>
      </div>

      <div class="form-section">
        <div class="form-section-title"><span>💪</span> Muscles</div>
        <div id="editMusclesList">
          <?php foreach ($editMuscles as $m): ?>
          <div class="dynamic-row muscle-row">
            <input type="text" name="muscle_name[]" value="<?= htmlspecialchars($m['name']) ?>" placeholder="Muscle name">
            <select name="muscle_type[]">
              <?php foreach (['Primary','Secondary','Stabiliser'] as $t): ?><option <?= ($m['type']??'')===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="<?= htmlspecialchars($m['color']??'#e63b2e') ?>">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($editMuscles)): ?>
          <div class="dynamic-row muscle-row">
            <input type="text" name="muscle_name[]" placeholder="Muscle name">
            <select name="muscle_type[]"><option>Primary</option><option>Secondary</option><option>Stabiliser</option></select>
            <input type="color" name="muscle_color[]" class="color-swatch" value="#e63b2e">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endif; ?>
        </div>
        <button type="button" class="add-row-btn" onclick="addMuscle('editMusclesList')">+ Add Muscle</button>
      </div>

      <div class="form-section">
        <div class="form-section-title"><span>💡</span> Tips</div>
        <div id="editTipsList">
          <?php foreach ($editTips as $t): ?>
          <div class="dynamic-row tip-row">
            <input type="text" name="tip_icon[]" value="<?= htmlspecialchars($t['icon']??'💡') ?>" placeholder="💡" style="text-align:center;font-size:1.1rem">
            <input type="text" name="tip_text[]" value="<?= htmlspecialchars($t['text']) ?>" placeholder="Tip text…">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endforeach; ?>
          <?php if (empty($editTips)): ?>
          <div class="dynamic-row tip-row">
            <input type="text" name="tip_icon[]" value="💡" style="text-align:center;font-size:1.1rem">
            <input type="text" name="tip_text[]" placeholder="Tip text…">
            <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
          </div>
          <?php endif; ?>
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
// ── Dynamic row management ──────────────────────────────────────────────────
function removeRow(btn, counterId) {
  const row = btn.closest('[data-row]') || btn.closest('.dynamic-row');
  if (row) { row.remove(); if (counterId) updateCount(counterId); }
}

function updateCount(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const container = document.getElementById(id.replace('Count','List'));
  if (!container) return;
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
    <button type="button" class="row-remove" onclick="removeRow(this,'${counterId}')">✕</button>
  `;
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
    <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
  `;
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
    <button type="button" class="row-remove" onclick="removeRow(this)">✕</button>
  `;
  list.appendChild(row);
  row.querySelector('input[name="tip_text[]"]').focus();
}

// Dedup guard on create form
document.getElementById('createForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('createBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
});
</script>

<?php layoutFooter(); ?>