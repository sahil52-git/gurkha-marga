<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_users.php — User Management
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ── Actions ────────────────────────────────────────────────────────────────
if ($action === 'delete' && $editId) {
    try {
        query("DELETE FROM users WHERE id=?", [$editId]);
        setFlash('success', 'User deleted successfully.');
    } catch (Exception $e) {
        setFlash('error', 'Delete failed.');
    }
    redirectTo('admin_users.php');
}

if ($action === 'toggle' && $editId) {
    try {
        query("UPDATE users SET is_active=NOT is_active WHERE id=?", [$editId]);
        setFlash('success', 'User status updated.');
    } catch (Exception $e) {
        setFlash('error', 'Status update failed.');
    }
    redirectTo('admin_users.php');
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)$_POST['id'];
    $active = isset($_POST['is_active']) ? 1 : 0;
    try {
        query("UPDATE users SET full_name=?,email=?,age=?,gender=?,height=?,weight=?,target_force=?,experience_level=?,phone=?,is_active=? WHERE id=?",
            [trim($_POST['full_name']), trim($_POST['email']), (int)$_POST['age'], $_POST['gender'],
             (float)$_POST['height'], (float)$_POST['weight'], $_POST['target_force'],
             $_POST['experience_level'], trim($_POST['phone'] ?? ''), $active, $id]);
        if (!empty($_POST['new_password'])) {
            query("UPDATE users SET password=? WHERE id=?", [password_hash($_POST['new_password'], PASSWORD_DEFAULT), $id]);
        }
        setFlash('success', 'User updated successfully.');
    } catch (Exception $e) {
        setFlash('error', 'Update failed: ' . $e->getMessage());
    }
    redirectTo('admin_users.php');
}

if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!$name || !$email || !$pass) {
        setFlash('error', 'Name, email, and password are required.');
    } elseif (strlen($pass) < 8) {
        setFlash('error', 'Password must be at least 8 characters.');
    } elseif (fetchOne("SELECT id FROM users WHERE email=?", [$email])) {
        setFlash('error', 'Email already registered.');
    } else {
        try {
            query("INSERT INTO users (full_name,email,password,age,gender,height,weight,target_force,experience_level,phone,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())",
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT),
                 (int)$_POST['age'], $_POST['gender'], (float)$_POST['height'],
                 (float)$_POST['weight'], $_POST['target_force'], $_POST['experience_level'],
                 trim($_POST['phone'] ?? '')]);
            setFlash('success', "User \"{$name}\" created successfully.");
        } catch (Exception $e) {
            setFlash('error', 'Create failed: ' . $e->getMessage());
        }
    }
    redirectTo('admin_users.php');
}

// ── Query ──────────────────────────────────────────────────────────────────
$search   = $_GET['search'] ?? '';
$uFilter  = $_GET['filter'] ?? '';
$uPage    = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 15;
$offset   = ($uPage - 1) * $perPage;

$where  = ['1=1']; $params = [];
if ($search) {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($uFilter === 'active')   { $where[] = 'is_active=1'; }
elseif ($uFilter === 'inactive') { $where[] = 'is_active=0'; }
elseif (in_array($uFilter, ['british','nepal','indian','singapore','french'])) {
    $where[] = 'target_force=?'; $params[] = $uFilter;
}
$whereSQL = implode(' AND ', $where);
$usersTotal = (int)(fetchOne("SELECT COUNT(*) as c FROM users WHERE $whereSQL", $params)['c'] ?? 0);
$usersList  = fetchAll("SELECT * FROM users WHERE $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
$usersPages = (int)ceil($usersTotal / $perPage);
$editUser   = ($action === 'edit' && $editId) ? fetchOne("SELECT * FROM users WHERE id=?", [$editId]) : null;

$forceNames = ['british'=>'British','nepal'=>'Nepal','indian'=>'Indian','singapore'=>'Singapore','french'=>'French'];
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Users</h1>
        <p class="page-sub"><?= number_format($usersTotal) ?> total users</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-createUser')">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add User
    </button>
</div>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="GET" style="display:contents">
        <input type="text" name="search" placeholder="Search name or email…"
               value="<?= htmlspecialchars($search) ?>" style="max-width:240px">
        <select name="filter" onchange="this.form.submit()" style="max-width:175px">
            <option value="">All Users</option>
            <option value="active"    <?= $uFilter==='active'?'selected':'' ?>>Active</option>
            <option value="inactive"  <?= $uFilter==='inactive'?'selected':'' ?>>Inactive</option>
            <optgroup label="Target Force">
            <option value="british"   <?= $uFilter==='british'?'selected':'' ?>>British Army</option>
            <option value="nepal"     <?= $uFilter==='nepal'?'selected':'' ?>>Nepal Army</option>
            <option value="indian"    <?= $uFilter==='indian'?'selected':'' ?>>Indian Army</option>
            <option value="singapore" <?= $uFilter==='singapore'?'selected':'' ?>>Singapore Police</option>
            <option value="french"    <?= $uFilter==='french'?'selected':'' ?>>French Legion</option>
            </optgroup>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">Search</button>
        <?php if ($search || $uFilter): ?>
        <a href="admin_users.php" class="btn btn-ghost btn-sm">✕ Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-family:var(--mono);font-size:.72rem;color:var(--text-dim)"><?= $usersTotal ?> results</span>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Age</th>
            <th>BMI</th>
            <th>Force</th>
            <th>Level</th>
            <th>Joined</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($usersList)): ?>
        <tr><td colspan="10" class="empty-row">No users found matching your criteria.</td></tr>
        <?php else: foreach ($usersList as $u):
            $bmi = ($u['height'] > 0 && $u['weight'] > 0) ? round($u['weight'] / (($u['height'] / 100) ** 2), 1) : null;
        ?>
        <tr>
            <td class="mono" style="color:var(--text-dim)"><?= $u['id'] ?></td>
            <td>
                <div class="td-name">
                    <div class="tbl-av"><?= strtoupper(substr($u['full_name'], 0, 1)) ?></div>
                    <span><?= htmlspecialchars($u['full_name']) ?></span>
                </div>
            </td>
            <td class="mono dim"><?= htmlspecialchars($u['email']) ?></td>
            <td class="mono"><?= $u['age'] ?: '—' ?></td>
            <td class="mono"><?= $bmi ?? '—' ?></td>
            <td class="dim"><?= $forceNames[$u['target_force']] ?? '—' ?></td>
            <td><span class="badge badge-muted"><?= ucfirst($u['experience_level'] ?? '—') ?></span></td>
            <td class="mono dim"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td><span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td>
                <div class="tbl-actions">
                    <a href="admin_users.php?action=edit&id=<?= $u['id'] ?>" class="act-btn edit">Edit</a>
                    <a href="admin_users.php?action=toggle&id=<?= $u['id'] ?>"
                       class="act-btn toggle"
                       onclick="return confirm('Toggle account status for <?= addslashes($u['full_name']) ?>?')">
                       <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                    </a>
                    <button class="act-btn del"
                            onclick="confirmDelete('admin_users.php?action=delete&id=<?= $u['id'] ?>',
                            'Permanently delete <?= addslashes($u['full_name']) ?>? This cannot be undone.')">
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

<!-- Create User Modal -->
<div class="modal-overlay" id="modal-createUser">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title">Add New User</div>
            <button class="modal-close" onclick="closeModal('modal-createUser')">✕</button>
        </div>
        <form method="POST" action="admin_users.php?action=store">
            <div class="form-grid">
                <div class="field full"><label>Full Name *</label><input type="text" name="full_name" required placeholder="Full name"></div>
                <div class="field"><label>Email *</label><input type="email" name="email" required placeholder="email@example.com"></div>
                <div class="field"><label>Password *</label><input type="password" name="password" required placeholder="Min 8 characters" minlength="8"></div>
                <div class="field"><label>Age</label><input type="number" name="age" min="15" max="60" placeholder="25"></div>
                <div class="field"><label>Gender</label>
                    <select name="gender">
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="field"><label>Height (cm)</label><input type="number" name="height" step="0.1" placeholder="175"></div>
                <div class="field"><label>Weight (kg)</label><input type="number" name="weight" step="0.1" placeholder="70"></div>
                <div class="field"><label>Target Force</label>
                    <select name="target_force">
                        <option value="">Select force</option>
                        <option value="british">British Army</option>
                        <option value="nepal">Nepal Army</option>
                        <option value="indian">Indian Army</option>
                        <option value="singapore">Singapore Police</option>
                        <option value="french">French Foreign Legion</option>
                    </select>
                </div>
                <div class="field full"><label>Experience Level</label>
                    <select name="experience_level">
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                        <option value="expert">Expert</option>
                    </select>
                </div>
                <div class="field full"><label>Phone</label><input type="text" name="phone" placeholder="+977 98XXXXXXXX"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-createUser')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<?php if ($action === 'edit' && $editUser): ?>
<div class="modal-overlay open" id="modal-editUser">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title">Edit — <?= htmlspecialchars($editUser['full_name']) ?></div>
            <a href="admin_users.php" class="modal-close">✕</a>
        </div>
        <form method="POST" action="admin_users.php?action=update">
            <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <div class="form-grid">
                <div class="field"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($editUser['full_name']) ?>" required></div>
                <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($editUser['email']) ?>" required></div>
                <div class="field full"><label>New Password (leave blank to keep existing)</label><input type="password" name="new_password" placeholder="New password"></div>
                <div class="field"><label>Age</label><input type="number" name="age" value="<?= $editUser['age'] ?>" min="10" max="80"></div>
                <div class="field"><label>Gender</label>
                    <select name="gender">
                        <?php foreach (['male','female','other'] as $g): ?>
                        <option value="<?= $g ?>" <?= $editUser['gender']===$g?'selected':'' ?>><?= ucfirst($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Height (cm)</label><input type="number" name="height" step="0.1" value="<?= $editUser['height'] ?>"></div>
                <div class="field"><label>Weight (kg)</label><input type="number" name="weight" step="0.1" value="<?= $editUser['weight'] ?>"></div>
                <div class="field"><label>Target Force</label>
                    <select name="target_force">
                        <?php foreach (['british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police','french'=>'French Foreign Legion'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $editUser['target_force']===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Experience Level</label>
                    <select name="experience_level">
                        <?php foreach (['beginner','intermediate','advanced','expert'] as $l): ?>
                        <option value="<?= $l ?>" <?= $editUser['experience_level']===$l?'selected':'' ?>><?= ucfirst($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field full"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>"></div>
                <div class="field row-check">
                    <input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                    <label>Account Active</label>
                </div>
            </div>
            <div class="modal-foot">
                <a href="admin_users.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php layoutFooter(); ?>