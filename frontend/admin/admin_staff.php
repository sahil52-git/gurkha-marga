<?php
// frontend/admin/admin_staff.php
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

// ── ALL POST HANDLING MUST COME BEFORE admin_layout.php ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_staff'])) {
        $name     = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $role     = $_POST['role']           ?? 'consultant';
        $phone    = trim($_POST['phone']     ?? '');
        $password = $_POST['password']       ?? '';

        if ($name && $email && $password && in_array($role, ['consultant', 'dietitian'])) {
            $existing = fetchOne("SELECT id FROM admin_staff WHERE email=?", [$email]);
            if ($existing) {
                $_SESSION['staff_form_err'] = 'Email already exists.';
                header('Location: admin_staff.php?action=create'); exit();
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                execute(
                    "INSERT INTO admin_staff (full_name, email, password, role, phone, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, NOW())",
                    [$name, $email, $hash, $role, $phone]
                );
                header('Location: admin_staff.php?msg=created'); exit();
            }
        } else {
            $_SESSION['staff_form_err'] = 'Please fill in all required fields.';
            header('Location: admin_staff.php?action=create'); exit();
        }
    }

    if (isset($_POST['toggle_staff_id'])) {
        $sid = (int)$_POST['toggle_staff_id'];
        execute("UPDATE admin_staff SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?", [$sid]);
        header('Location: admin_staff.php?msg=updated'); exit();
    }

    if (isset($_POST['delete_staff_id'])) {
        execute("DELETE FROM admin_staff WHERE id=? AND role != 'superadmin'", [(int)$_POST['delete_staff_id']]);
        header('Location: admin_staff.php?msg=deleted'); exit();
    }
}

// ── NOW it's safe to load the layout (which outputs HTML) ──
require_once __DIR__ . '/admin_layout.php';

// Pull the error from session (set above before redirect)
$err = $_SESSION['staff_form_err'] ?? null;
unset($_SESSION['staff_form_err']);

$staff      = fetchAll("SELECT * FROM admin_staff ORDER BY role, full_name");
$action     = $_GET['action'] ?? '';
$showCreate = ($action === 'create');
?>

<style>
.staff-form-wrap {
    background: var(--elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    max-width: 580px;
}
.staff-form-wrap .form-title {
    font-size: .95rem;
    font-weight: 700;
    margin-bottom: 1.25rem;
    color: var(--text);
}
.sf-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.sf-field {
    display: flex;
    flex-direction: column;
    gap: .38rem;
    margin-bottom: .75rem;
}
.sf-field label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-dim);
}
.sf-field input,
.sf-field select {
    width: 100%;
    box-sizing: border-box;
    background: var(--bg);
    border: 1px solid var(--border2, #3a3d4a);
    border-radius: 8px;
    color: var(--text);
    padding: .6rem .85rem;
    font-size: .85rem;
    font-family: inherit;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    appearance: none;
    -webkit-appearance: none;
}
.sf-field input::placeholder {
    color: var(--text-dim);
    opacity: 1;
}
.sf-field input:focus,
.sf-field select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.sf-field select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    background-size: 14px;
    padding-right: 2.2rem;
}
.sf-hint {
    font-size: .68rem;
    color: var(--text-dim);
    margin-top: 1px;
}
.sf-actions {
    display: flex;
    gap: .75rem;
    margin-top: 1.25rem;
    align-items: center;
}
.sf-err {
    background: rgba(224,85,85,.1);
    border: 1px solid rgba(224,85,85,.25);
    border-radius: 8px;
    padding: .7rem 1rem;
    color: #fca5a5;
    font-size: .83rem;
    margin-bottom: 1rem;
}

/* ── Table ── */
.staff-table-wrap {
    background: var(--elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.staff-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .83rem;
}
.staff-table thead th {
    text-align: left;
    padding: .85rem 1rem;
    color: var(--text-dim);
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
    background: var(--bg);
}
.staff-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .1s;
}
.staff-table tbody tr:last-child { border-bottom: none; }
.staff-table tbody tr:hover { background: rgba(255,255,255,.02); }
.staff-table td { padding: .8rem 1rem; vertical-align: middle; }

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .25rem .65rem;
    border-radius: 6px;
    font-size: .72rem;
    font-weight: 700;
}
.status-dot {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .25rem .65rem;
    border-radius: 6px;
    font-size: .72rem;
    font-weight: 700;
}
.status-dot::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
}
.status-active            { background: rgba(13,184,124,.12); color: #4ddba8; }
.status-active::before    { background: #4ddba8; }
.status-disabled          { background: rgba(139,143,168,.1);  color: #8b8fa8; }
.status-disabled::before  { background: #8b8fa8; }

.act-btn {
    font-size: .72rem;
    padding: .27rem .7rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: opacity .15s;
    text-decoration: none;
    display: inline-block;
}
.act-btn:hover  { opacity: .8; }
.act-toggle { background: rgba(245,200,66,.12); color: #f5c842; }
.act-delete { background: rgba(224,85,85,.15);  color: #e05555; }
</style>

<!-- ── Page Header ── -->
<div class="page-header">
    <div>
        <h1 class="page-title">Staff Management</h1>
        <p class="page-sub">Manage consultants and dietitians</p>
    </div>
    <a href="?action=create" class="btn btn-primary">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Staff
    </a>
</div>

<!-- ── Flash message ── -->
<?php if (isset($_GET['msg'])): ?>
<div style="background:rgba(13,184,124,.1);border:1px solid rgba(13,184,124,.25);border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1.25rem;color:#4ddba8;font-size:.88rem">
    <?= [
        'created' => '✅ Staff account created.',
        'updated' => '✅ Status updated.',
        'deleted' => '✅ Staff removed.',
    ][$_GET['msg']] ?? '✅ Done.' ?>
</div>
<?php endif; ?>

<!-- ── Create Staff Form ── -->
<?php if ($showCreate): ?>
<div class="staff-form-wrap">
    <div class="form-title">New Staff Account</div>

    <?php if (!empty($err)): ?>
    <div class="sf-err">⚠️ <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="create_staff" value="1">

        <div class="sf-grid-2">
            <div class="sf-field">
                <label>Full Name <span style="color:#e05555">*</span></label>
                <input type="text" name="full_name"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       placeholder="Enter full name" required>
            </div>
            <div class="sf-field">
                <label>Email Address <span style="color:#e05555">*</span></label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Enter your email" required>
            </div>
        </div>

        <div class="sf-grid-2">
            <div class="sf-field">
                <label>Role <span style="color:#e05555">*</span></label>
                <select name="role">
                    <option value="consultant"
                        <?= (($_POST['role'] ?? 'consultant') === 'consultant') ? 'selected' : '' ?>>
                         Consultant
                    </option>
                    <option value="dietitian"
                        <?= (($_POST['role'] ?? '') === 'dietitian') ? 'selected' : '' ?>>
                         Dietitian
                    </option>
                </select>
            </div>
            <div class="sf-field">
                <label>Phone Number</label>
                <input type="text" name="phone"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       placeholder="+977 98XXXXXXXX">
            </div>
        </div>

        <div class="sf-field">
            <label>Password <span style="color:#e05555">*</span></label>
            <input type="password" name="password"
                   placeholder="Min. 8 characters" minlength="8" required>
        </div>

        <div class="sf-actions">
            <button type="submit" class="btn btn-primary">
                <svg viewBox="0 0 24 24" style="width:14px;height:14px">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create Account
            </button>
            <a href="admin_staff.php" class="btn"
               style="background:var(--bg);border:1px solid var(--border);color:var(--text-sub)">
                Cancel
            </a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Staff Table ── -->
<div class="staff-table-wrap">
    <table class="staff-table">
        <thead>
            <tr>
                <?php foreach (['Staff Member', 'Role', 'Phone', 'Clients', 'Status', 'Login URL', 'Actions'] as $h): ?>
                <th><?= $h ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($staff)): ?>
            <tr>
                <td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-dim)">
                    No staff accounts yet.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($staff as $s):
                // Role colours — keys match DB enum exactly
                $roleColors = [
                    'superadmin' => ['rgba(245,200,66,.15)',  '#f5c842'],
                    'admin'      => ['rgba(124,58,237,.15)',  '#a78bfa'],
                    'consultant' => ['rgba(59,111,245,.15)',  '#7aa8ff'],
                    'dietitian'  => ['rgba(13,184,124,.15)',  '#4ddba8'],
                ];
                [$rBg, $rFg] = $roleColors[$s['role']] ?? ['rgba(139,143,168,.15)', '#8b8fa8'];

                // Role labels — keys match DB enum exactly
                $roleLabels = [
                    'superadmin' => ' Superadmin',
                    'admin'      => ' Admin',
                    'consultant' => ' Consultant',
                    'dietitian'  => ' Dietitian',
                ];

                // Active client count — wrapped in try/catch in case tables don't exist yet
                $clientCount = 0;
                if ($s['role'] === 'consultant') {
                    try {
                        $row = fetchOne(
                            "SELECT COUNT(*) AS c FROM user_consultants
                             WHERE consultant_id = ? AND is_active = 1",
                            [$s['id']]
                        );
                        $clientCount = (int)($row['c'] ?? 0);
                    } catch (Exception $e) { $clientCount = 0; }
                } elseif ($s['role'] === 'dietitian') {
                    try {
                        $row = fetchOne(
                            "SELECT COUNT(*) AS c FROM user_consultants
                             WHERE dietitian_id = ? AND is_active = 1",
                            [$s['id']]
                        );
                        $clientCount = (int)($row['c'] ?? 0);
                    } catch (Exception $e) { $clientCount = 0; }
                }
            ?>
            <tr>
                <!-- Staff Member -->
                <td>
                    <div style="display:flex;align-items:center;gap:.75rem">
                        <div style="width:34px;height:34px;border-radius:8px;
                                    background:<?= $rBg ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    color:<?= $rFg ?>;font-weight:700;font-size:.85rem;flex-shrink:0">
                            <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:var(--text)">
                                <?= htmlspecialchars($s['full_name']) ?>
                            </div>
                            <div style="color:var(--text-dim);font-size:.73rem">
                                <?= htmlspecialchars($s['email']) ?>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- Role -->
                <td>
                    <span class="role-badge" style="background:<?= $rBg ?>;color:<?= $rFg ?>">
                        <?= $roleLabels[$s['role']] ?? ucfirst($s['role']) ?>
                    </span>
                </td>

                <!-- Phone -->
                <td style="color:var(--text-sub);font-size:.8rem;font-family:var(--mono)">
                    <?= htmlspecialchars($s['phone'] ?? '') ?: '<span style="color:var(--text-dim)">—</span>' ?>
                </td>

                <!-- Clients -->
                <td style="font-family:var(--mono);font-size:.83rem">
                    <?php if (in_array($s['role'], ['consultant', 'dietitian'])): ?>
                        <span style="color:<?= $clientCount > 0 ? 'var(--text)' : 'var(--text-dim)' ?>">
                            <?= $clientCount ?> active
                        </span>
                    <?php else: ?>
                        <span style="color:var(--text-dim)">—</span>
                    <?php endif; ?>
                </td>

                <!-- Status -->
                <td>
                    <span class="status-dot <?= $s['is_active'] ? 'status-active' : 'status-disabled' ?>">
                        <?= $s['is_active'] ? 'Active' : 'Disabled' ?>
                    </span>
                </td>

                <!-- Login URL -->
                <td>
                    <?php if (in_array($s['role'], ['consultant', 'dietitian'])): ?>
                    <code style="font-size:.71rem;background:var(--bg);padding:.2rem .5rem;border-radius:5px;color:var(--text-dim)">
                        /consultant/login.php
                    </code>
                    <?php else: ?>
                    <code style="font-size:.71rem;background:var(--bg);padding:.2rem .5rem;border-radius:5px;color:var(--text-dim)">
                        /admin/login.php
                    </code>
                    <?php endif; ?>
                </td>

                <!-- Actions -->
                <td>
                    <?php if ($s['role'] !== 'superadmin'): ?>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="toggle_staff_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="act-btn act-toggle">
                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <form method="POST"
                              onsubmit="return confirm('Delete this staff account? This cannot be undone.')"
                              style="display:inline">
                            <input type="hidden" name="delete_staff_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="act-btn act-delete">Delete</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-dim);font-size:.73rem">Protected</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php layoutFooter(); ?>