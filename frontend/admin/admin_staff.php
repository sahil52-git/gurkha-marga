<?php
// frontend/admin/admin_staff.php
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// ── Handle actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_staff'])) {
        $name        = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $role        = $_POST['role'] ?? 'consultant';
        $speciality  = trim($_POST['speciality'] ?? '');
        $password    = $_POST['password'] ?? '';

        if ($name && $email && $password && in_array($role, ['consultant','dietician','admin'])) {
            $existing = fetchOne("SELECT id FROM admin_staff WHERE email=?", [$email]);
            if ($existing) {
                $err = 'Email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                execute(
                    "INSERT INTO admin_staff (full_name, email, password_hash, role, speciality, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, 1, NOW())",
                    [$name, $email, $hash, $role, $speciality]
                );
                header('Location: admin_staff.php?msg=created'); exit();
            }
        } else {
            $err = 'Please fill in all required fields.';
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

$staff      = fetchAll("SELECT * FROM admin_staff ORDER BY role, full_name");
$action     = $_GET['action'] ?? '';
$showCreate = ($action === 'create');
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Staff Management</h1>
    <p class="page-sub">Manage consultants, dieticians and admins</p>
  </div>
  <a href="?action=create" class="btn btn-primary">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Staff
  </a>
</div>

<?php if (isset($_GET['msg'])): ?>
<div style="background:rgba(13,184,124,.1);border:1px solid rgba(13,184,124,.25);border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1.25rem;color:#4ddba8;font-size:.88rem">
  <?= ['created'=>'✅ Staff account created.','updated'=>'✅ Status updated.','deleted'=>'✅ Staff removed.'][$_GET['msg']] ?? '✅ Done.' ?>
</div>
<?php endif; ?>

<?php if ($showCreate): ?>
<!-- Create Form -->
<div class="card mb" style="max-width:520px">
  <div style="font-family:var(--font-display);font-size:1rem;font-weight:600;margin-bottom:1.25rem">New Staff Account</div>
  <?php if (!empty($err)): ?>
  <div style="background:rgba(204,51,51,.1);border:1px solid rgba(204,51,51,.25);border-radius:8px;padding:.75rem 1rem;color:#fca5a5;font-size:.85rem;margin-bottom:1rem">
    ⚠️ <?= htmlspecialchars($err) ?>
  </div>
  <?php endif; ?>
  <form method="POST" style="display:flex;flex-direction:column;gap:1rem">
    <input type="hidden" name="create_staff" value="1">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div>
        <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);display:block;margin-bottom:.35rem">Full Name *</label>
        <input name="full_name" required style="width:100%;background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none">
      </div>
      <div>
        <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);display:block;margin-bottom:.35rem">Email *</label>
        <input name="email" type="email" required style="width:100%;background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div>
        <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);display:block;margin-bottom:.35rem">Role *</label>
        <select name="role" style="width:100%;background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none">
          <option value="consultant">🏋️ Fitness Consultant</option>
          <option value="dietician">🥗 Registered Dietician</option>
          <option value="admin">🛡️ Admin</option>
        </select>
      </div>
      <div>
        <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);display:block;margin-bottom:.35rem">Speciality</label>
        <input name="speciality" placeholder="e.g. British Army Fitness" style="width:100%;background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none">
      </div>
    </div>
    <div>
      <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);display:block;margin-bottom:.35rem">Password *</label>
      <input name="password" type="password" required style="width:100%;background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none">
    </div>
    <div style="display:flex;gap:.75rem;margin-top:.25rem">
      <button type="submit" class="btn btn-primary" style="padding:.6rem 1.5rem">Create Account</button>
      <a href="admin_staff.php" class="btn" style="padding:.6rem 1.25rem;background:var(--bg-elevated);border:1px solid var(--border-col);color:var(--text-2)">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Staff Table -->
<div class="card" style="padding:0;overflow:hidden">
  <table style="width:100%;border-collapse:collapse;font-size:.83rem">
    <thead>
      <tr style="border-bottom:1px solid var(--border-col)">
        <?php foreach(['Staff Member','Role','Speciality','Clients','Status','Login URL','Actions'] as $h): ?>
        <th style="text-align:left;padding:.8rem 1rem;color:var(--text-dim);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;font-weight:600"><?= $h ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($staff)): ?>
      <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-dim)">No staff accounts yet.</td></tr>
      <?php else: ?>
      <?php foreach ($staff as $s):
        $roleColors = [
          'superadmin' => ['rgba(245,200,66,.15)','#f5c842'],
          'admin'      => ['rgba(124,58,237,.15)','#a78bfa'],
          'consultant' => ['rgba(59,111,245,.15)','#7aa8ff'],
          'dietician'  => ['rgba(13,184,124,.15)','#4ddba8'],
        ];
        [$rBg,$rFg] = $roleColors[$s['role']] ?? ['rgba(139,143,168,.15)','#8b8fa8'];
        $roleLabels = ['superadmin'=>'Superadmin','admin'=>'Admin','consultant'=>'🏋️ Consultant','dietician'=>'🥗 Dietician'];

        // Count assigned active clients
        $clientCount = 0;
        if ($s['role'] === 'consultant') {
            $row = fetchOne("SELECT COUNT(*) AS c FROM user_assignments ua JOIN subscriptions s2 ON s2.user_id=ua.user_id AND s2.status='active' AND s2.expires_at>NOW() WHERE ua.consultant_id=?", [$s['id']]);
            $clientCount = (int)($row['c'] ?? 0);
        } elseif ($s['role'] === 'dietician') {
            $row = fetchOne("SELECT COUNT(*) AS c FROM user_assignments ua JOIN subscriptions s2 ON s2.user_id=ua.user_id AND s2.status='active' AND s2.expires_at>NOW() WHERE ua.dietician_id=?", [$s['id']]);
            $clientCount = (int)($row['c'] ?? 0);
        }
      ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
        <td style="padding:.75rem 1rem">
          <div style="font-weight:600;color:var(--text-1)"><?= htmlspecialchars($s['full_name']) ?></div>
          <div style="color:var(--text-dim);font-size:.75rem"><?= htmlspecialchars($s['email']) ?></div>
        </td>
        <td style="padding:.75rem 1rem">
          <span style="background:<?= $rBg ?>;color:<?= $rFg ?>;padding:.25rem .65rem;border-radius:6px;font-size:.75rem;font-weight:700">
            <?= $roleLabels[$s['role']] ?? $s['role'] ?>
          </span>
        </td>
        <td style="padding:.75rem 1rem;color:var(--text-dim);font-size:.8rem"><?= htmlspecialchars($s['speciality'] ?? '—') ?></td>
        <td style="padding:.75rem 1rem;font-family:var(--mono);font-size:.85rem;color:<?= $clientCount>0?'var(--text-1)':'var(--text-dim)' ?>">
          <?= in_array($s['role'],['consultant','dietician']) ? $clientCount . ' active' : '—' ?>
        </td>
        <td style="padding:.75rem 1rem">
          <span style="background:<?= $s['is_active']?'rgba(13,184,124,.15)':'rgba(139,143,168,.1)' ?>;color:<?= $s['is_active']?'#4ddba8':'#8b8fa8' ?>;padding:.25rem .65rem;border-radius:6px;font-size:.72rem;font-weight:700">
            <?= $s['is_active'] ? 'Active' : 'Disabled' ?>
          </span>
        </td>
        <td style="padding:.75rem 1rem">
          <?php if (in_array($s['role'],['consultant','dietician'])): ?>
          <code style="font-size:.72rem;background:var(--bg-elevated);padding:.2rem .5rem;border-radius:5px;color:var(--text-dim)">/consultant/login.php</code>
          <?php else: ?>
          <code style="font-size:.72rem;color:var(--text-dim)">/admin/login.php</code>
          <?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem">
          <div style="display:flex;gap:.4rem">
            <?php if ($s['role'] !== 'superadmin'): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="toggle_staff_id" value="<?= $s['id'] ?>">
              <button type="submit" style="font-size:.72rem;padding:.25rem .65rem;border-radius:6px;background:rgba(245,200,66,.12);color:#f5c842;border:none;cursor:pointer;font-weight:600">
                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
              </button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this staff account?')" style="display:inline">
              <input type="hidden" name="delete_staff_id" value="<?= $s['id'] ?>">
              <button type="submit" style="font-size:.72rem;padding:.25rem .65rem;border-radius:6px;background:rgba(204,51,51,.15);color:#e05555;border:none;cursor:pointer;font-weight:600">Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php layoutFooter(); ?>