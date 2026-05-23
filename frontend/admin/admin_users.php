<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_users.php — User Management (view, enable/disable, delete)
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ── Actions (before layout so redirects work cleanly) ─────────────────────
if ($action === 'delete' && $editId) {
    try {
        query("DELETE FROM users WHERE id=?", [$editId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User deleted successfully.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Delete failed.'];
    }
    header('Location: admin_users.php'); exit();
}

if ($action === 'toggle' && $editId) {
    try {
        query("UPDATE users SET is_active=NOT is_active WHERE id=?", [$editId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User status updated.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Status update failed.'];
    }
    header('Location: admin_users.php'); exit();
}

// ── Load layout (provides sidebar, CSS vars, helpers, $adminRole) ──────────
require_once __DIR__ . '/admin_layout.php';

// ── Query ──────────────────────────────────────────────────────────────────
$search  = $_GET['search'] ?? '';
$uFilter = $_GET['filter'] ?? '';
$uPage   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset  = ($uPage - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($uFilter === 'active')       { $where[] = 'is_active=1'; }
elseif ($uFilter === 'inactive') { $where[] = 'is_active=0'; }
elseif (in_array($uFilter, ['british','nepal','indian','singapore','french'])) {
    $where[] = 'target_force=?'; $params[] = $uFilter;
}
$whereSQL   = implode(' AND ', $where);
$usersTotal = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE $whereSQL", $params)['c'] ?? 0);
$usersList  = fetchAll("SELECT * FROM users WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
$usersPages = (int)ceil($usersTotal / $perPage);

$forceNames = ['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police','french'=>'French Legion'];
$forceFlags = ['british'=>'🇬🇧','nepal'=>'🇳🇵','indian'=>'🇮🇳','singapore'=>'🇸🇬','french'=>'🇫🇷'];

// Quick stats
$activeCount   = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=1")['c'] ?? 0);
$inactiveCount = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE is_active=0")['c'] ?? 0);
$todayCount    = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE DATE(created_at)=CURDATE()")['c'] ?? 0);
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-sub"><?= number_format($usersTotal) ?> total registered accounts</p>
    </div>
</div>

<!-- Flash -->
<?php if (!empty($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="flash <?= $f['type'] ?>">
    <?= $f['type'] === 'success' ? '✓' : '✕' ?> <?= htmlspecialchars($f['msg']) ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="stats-grid mb" style="grid-template-columns: repeat(4,1fr)">
    <div class="stat-card">
        <div class="stat-label">Total Users</div>
        <div class="stat-val" style="color:#93c5fd"><?= number_format($usersTotal) ?></div>
        <div class="stat-sub">All registered accounts</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active</div>
        <div class="stat-val" style="color:#6ee7b7"><?= number_format($activeCount) ?></div>
        <div class="stat-sub">Enabled accounts</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Inactive</div>
        <div class="stat-val" style="color:#fca5a5"><?= number_format($inactiveCount) ?></div>
        <div class="stat-sub">Disabled accounts</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Joined Today</div>
        <div class="stat-val" style="color:var(--gold)"><?= number_format($todayCount) ?></div>
        <div class="stat-sub">New registrations today</div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="GET" style="display:contents">
        <input type="text" name="search" placeholder="Search name or email…"
               value="<?= htmlspecialchars($search) ?>" style="max-width:240px; min-width:160px;">
        <select name="filter" onchange="this.form.submit()" style="max-width:180px;">
            <option value="">All Users</option>
            <option value="active"    <?= $uFilter==='active'   ?'selected':'' ?>>Active</option>
            <option value="inactive"  <?= $uFilter==='inactive' ?'selected':'' ?>>Inactive</option>
            <optgroup label="Target Force">
                <option value="british"   <?= $uFilter==='british'   ?'selected':'' ?>>🇬🇧 British Army</option>
                <option value="nepal"     <?= $uFilter==='nepal'     ?'selected':'' ?>>🇳🇵 Nepal Army</option>
                <option value="indian"    <?= $uFilter==='indian'    ?'selected':'' ?>>🇮🇳 Indian Army</option>
                <option value="singapore" <?= $uFilter==='singapore' ?'selected':'' ?>>🇸🇬 Singapore Police</option>
                <option value="french"    <?= $uFilter==='french'    ?'selected':'' ?>>🇫🇷 French Legion</option>
            </optgroup>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">
            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            Search
        </button>
        <?php if ($search || $uFilter): ?>
        <a href="admin_users.php" class="btn btn-ghost btn-sm" style="border-color:rgba(239,68,68,.3);color:#fca5a5">✕ Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:.72rem;color:var(--text-3);font-family:var(--mono)">
            <?= number_format($usersTotal) ?> result<?= $usersTotal !== 1 ? 's' : '' ?>
        </span>
    </form>
</div>

<!-- Table -->
<div class="card mb">
    <div class="tbl-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Age / Gender</th>
                    <th>BMI</th>
                    <th>Force</th>
                    <th>Level</th>
                    <th>Phone</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($usersList)): ?>
            <tr>
                <td colspan="10" class="empty-row">
                    <div style="font-size:2rem;margin-bottom:.5rem;opacity:.4">👤</div>
                    No users found matching your criteria.
                </td>
            </tr>
            <?php else: foreach ($usersList as $u):
                $bmi = ($u['height'] > 0 && $u['weight'] > 0)
                    ? round($u['weight'] / (($u['height'] / 100) ** 2), 1) : null;
                $bmiClass = '';
                if ($bmi) {
                    if ($bmi < 18.5)     $bmiClass = 'badge-blue';
                    elseif ($bmi < 25.0) $bmiClass = 'badge-green';
                    elseif ($bmi < 30.0) $bmiClass = 'badge-muted';
                    else                 $bmiClass = 'badge-red';
                }
            ?>
            <tr>
                <td class="mono" style="color:var(--text-3);font-size:.75rem"><?= $u['id'] ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:.75rem">
                        <div style="
                            width:36px;height:36px;border-radius:50%;flex-shrink:0;
                            background:linear-gradient(135deg,#3b82f6,#8b5cf6);
                            display:flex;align-items:center;justify-content:center;
                            font-size:.8rem;font-weight:800;color:#fff;
                            border:1.5px solid rgba(59,130,246,.3)">
                            <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:500;font-size:.84rem"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div style="font-size:.72rem;color:var(--text-3)"><?= htmlspecialchars($u['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td class="mono">
                    <?= $u['age'] ? $u['age'].'y' : '—' ?>
                    <span style="color:var(--text-3);font-size:.72rem"> / <?= $u['gender'] ? ucfirst($u['gender']) : '—' ?></span>
                </td>
                <td>
                    <?php if ($bmi): ?>
                    <span class="badge <?= $bmiClass ?>"><?= $bmi ?></span>
                    <?php else: ?><span style="color:var(--text-3)">—</span><?php endif; ?>
                </td>
                <td style="color:var(--text-2);white-space:nowrap">
                    <?= ($forceFlags[$u['target_force']] ?? '') ?>
                    <?= $forceNames[$u['target_force']] ?? '—' ?>
                </td>
                <td><span class="badge badge-muted"><?= ucfirst($u['experience_level'] ?? '—') ?></span></td>
                <td class="mono" style="color:var(--text-3);font-size:.75rem"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                <td class="mono" style="color:var(--text-3);font-size:.75rem;white-space:nowrap"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                <td>
                    <span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>">
                        <?= $u['is_active'] ? '● Active' : '○ Inactive' ?>
                    </span>
                </td>
                <td>
                    <div class="tbl-actions">
                        <button class="act-btn <?= $u['is_active'] ? 'toggle' : 'edit' ?>"
                            onclick="openUserToggle(
                                'admin_users.php?action=toggle&id=<?= $u['id'] ?>',
                                '<?= addslashes($u['full_name']) ?>',
                                <?= $u['is_active'] ? 'true' : 'false' ?>
                            )">
                            <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                        </button>
                        <button class="act-btn del"
                            onclick="confirmDelete(
                                'admin_users.php?action=delete&id=<?= $u['id'] ?>',
                                'Delete &quot;<?= addslashes($u['full_name']) ?>&quot;?'
                            )">
                            Delete
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($usersPages > 1): ?>
    <div class="pagination">
        <?php if ($uPage > 1): ?>
        <a href="?p=<?= $uPage-1 ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>" class="pg-btn">‹</a>
        <?php endif; ?>
        <?php for ($i = max(1, $uPage-3); $i <= min($usersPages, $uPage+3); $i++): ?>
        <a href="?p=<?= $i ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>"
           class="pg-btn <?= $i === $uPage ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($uPage < $usersPages): ?>
        <a href="?p=<?= $uPage+1 ?>&search=<?= urlencode($search) ?>&filter=<?= $uFilter ?>" class="pg-btn">›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ TOGGLE CONFIRM MODAL ═══ -->
<div class="modal-overlay" id="modal-userToggle">
    <div class="modal" style="max-width:400px">
        <div class="modal-head">
            <div class="modal-title" id="ut-title">Disable Account?</div>
            <button class="modal-close" onclick="closeModal('modal-userToggle')">✕</button>
        </div>
        <div style="padding:1.5rem;text-align:center">
            <div style="font-size:2.5rem;margin-bottom:.75rem" id="ut-icon">⏸</div>
            <p style="font-size:.84rem;color:var(--text-2);line-height:1.7">
                This will <strong id="ut-action">disable</strong> the account for
                <strong id="ut-name" style="color:var(--text)"></strong>.
                They will <span id="ut-effect">not be able to log in</span> until changed.
            </p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('modal-userToggle')">Cancel</button>
            <a href="#" class="btn" id="ut-confirm"
               style="background:rgba(251,191,36,.15);color:var(--gold);border:1px solid rgba(251,191,36,.3)">
               Confirm
            </a>
        </div>
    </div>
</div>

<script>
function openUserToggle(url, name, isActive) {
    document.getElementById('ut-name').textContent    = name;
    document.getElementById('ut-confirm').href        = url;
    document.getElementById('ut-icon').textContent    = isActive ? '⏸' : '▶';
    document.getElementById('ut-title').textContent   = isActive ? 'Disable Account?' : 'Enable Account?';
    document.getElementById('ut-action').textContent  = isActive ? 'disable' : 'enable';
    document.getElementById('ut-effect').textContent  = isActive ? 'not be able to log in' : 'be able to log in again';
    openModal('modal-userToggle');
}
</script>

<?php layoutFooter(); ?>