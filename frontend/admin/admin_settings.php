<?php
// ════════════════════════════════════════════════════════════════════════════
//  admin_settings.php — Account Settings
// ════════════════════════════════════════════════════════════════════════════
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

// Fetch the logged-in staff record (null = hardcoded account)
$staffRecord = null;
try { $staffRecord = fetchOne("SELECT * FROM admin_staff WHERE email=?", [$adminEmail]); } catch (Exception $e) {}

$isDbAccount = ($staffRecord !== null);
$activeTab   = $_GET['tab'] ?? 'profile';

// ── POST Handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'update_profile' && $isDbAccount) {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$name) {
            setFlash('error', 'Name cannot be empty.');
        } else {
            try {
                query("UPDATE admin_staff SET full_name=?,phone=?,notes=? WHERE id=?",
                    [$name, $phone, $notes, $staffRecord['id']]);
                $_SESSION['admin_name'] = $name;
                $adminName = $name;
                setFlash('success', 'Profile updated successfully.');
            } catch (Exception $e) {
                setFlash('error', 'Profile update failed.');
            }
        }
        redirectTo('admin_settings.php?tab=profile');
    }

    if ($act === 'change_password' && $isDbAccount) {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $con = $_POST['confirm_password'] ?? '';

        if (!password_verify($cur, $staffRecord['password'])) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $con) {
            setFlash('error', 'New passwords do not match.');
        } else {
            try {
                query("UPDATE admin_staff SET password=? WHERE id=?",
                    [password_hash($new, PASSWORD_DEFAULT), $staffRecord['id']]);
                setFlash('success', 'Password changed successfully. Please use your new password on next login.');
            } catch (Exception $e) {
                setFlash('error', 'Password change failed.');
            }
        }
        redirectTo('admin_settings.php?tab=security');
    }
}

// Refresh record after updates
if ($isDbAccount) {
    try { $staffRecord = fetchOne("SELECT * FROM admin_staff WHERE email=?", [$adminEmail]); } catch (Exception $e) {}
}

// System stats for overview tab
$sysStats = [];
try {
    $sysStats['users']    = (int)(fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0);
    $sysStats['staff']    = (int)(fetchOne("SELECT COUNT(*) as c FROM admin_staff WHERE is_active=1")['c'] ?? 0);
    $sysStats['workouts'] = 0;
    try { $sysStats['workouts'] = (int)(fetchOne("SELECT COUNT(*) as c FROM workouts")['c'] ?? 0); } catch(Exception $e){}
} catch (Exception $e) {}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">Manage your account profile and preferences</p>
    </div>
</div>

<!-- Account card at top -->
<div class="card card-pad mb" style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
    <div style="width:52px;height:52px;border-radius:8px;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;color:#fff;font-family:var(--mono);flex-shrink:0">
        <?= strtoupper(substr($adminName, 0, 1)) ?>
    </div>
    <div style="flex:1;min-width:200px">
        <div style="font-weight:700;font-size:1rem"><?= htmlspecialchars($adminName) ?></div>
        <div style="font-size:.78rem;color:var(--text-sub);margin-top:2px"><?= htmlspecialchars($adminEmail) ?></div>
        <div style="display:flex;gap:.5rem;margin-top:.45rem;flex-wrap:wrap;align-items:center">
            <span class="badge badge-blue"><?= ucfirst($adminRole) ?></span>
            <?php if ($isDbAccount): ?>
            <span class="badge badge-green">Database Account</span>
            <?php else: ?>
            <span class="badge badge-gold">Hardcoded Account</span>
            <?php endif; ?>
            <span class="badge badge-muted" style="font-family:var(--mono)">Session active</span>
        </div>
    </div>
    <div class="grid-3" style="gap:.65rem;min-width:320px">
        <div style="background:var(--elevated);border:1px solid var(--border);border-radius:6px;padding:.7rem .9rem;text-align:center">
            <div style="font-family:var(--mono);font-size:1.2rem;font-weight:600"><?= $sysStats['users'] ?></div>
            <div style="font-size:.65rem;color:var(--text-dim);margin-top:2px;text-transform:uppercase;letter-spacing:.08em">Users</div>
        </div>
        <div style="background:var(--elevated);border:1px solid var(--border);border-radius:6px;padding:.7rem .9rem;text-align:center">
            <div style="font-family:var(--mono);font-size:1.2rem;font-weight:600"><?= $sysStats['staff'] ?></div>
            <div style="font-size:.65rem;color:var(--text-dim);margin-top:2px;text-transform:uppercase;letter-spacing:.08em">Staff</div>
        </div>
        <div style="background:var(--elevated);border:1px solid var(--border);border-radius:6px;padding:.7rem .9rem;text-align:center">
            <div style="font-family:var(--mono);font-size:1.2rem;font-weight:600"><?= $sysStats['workouts'] ?></div>
            <div style="font-size:.65rem;color:var(--text-dim);margin-top:2px;text-transform:uppercase;letter-spacing:.08em">Workouts</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a class="tab <?= $activeTab==='profile'?'active':'' ?>" href="admin_settings.php?tab=profile">Profile</a>
    <a class="tab <?= $activeTab==='security'?'active':'' ?>" href="admin_settings.php?tab=security">Security</a>
    <a class="tab <?= $activeTab==='system'?'active':'' ?>" href="admin_settings.php?tab=system">System Info</a>
</div>

<?php if ($activeTab === 'profile'): ?>
<!-- Profile Tab -->
<div class="grid-2">
    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">Edit Profile</div>
        <?php if ($isDbAccount): ?>
        <form method="POST" action="admin_settings.php?tab=profile">
            <input type="hidden" name="action" value="update_profile">
            <div class="field" style="margin-bottom:.9rem">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($staffRecord['full_name'] ?? $adminName) ?>" required>
            </div>
            <div class="field" style="margin-bottom:.9rem">
                <label>Email Address (read-only)</label>
                <input type="email" value="<?= htmlspecialchars($adminEmail) ?>" disabled>
                <span style="font-size:.68rem;color:var(--text-dim);margin-top:3px">Email cannot be changed here. Contact a Superadmin.</span>
            </div>
            <div class="field" style="margin-bottom:.9rem">
                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($staffRecord['phone'] ?? '') ?>" placeholder="+977 98XXXXXXXX">
            </div>
            <div class="field" style="margin-bottom:1.1rem">
                <label>Notes / Bio</label>
                <textarea name="notes" rows="3" placeholder="Your role description, access notes…"><?= htmlspecialchars($staffRecord['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
        <?php else: ?>
        <div style="background:var(--gold-dim);border:1px solid var(--gold-border);border-radius:6px;padding:1rem;font-size:.82rem;color:var(--gold);line-height:1.7">
            <div style="font-weight:700;margin-bottom:.4rem">Hardcoded Admin Account</div>
            Profile editing is not available for hardcoded admin accounts. To update your credentials, edit the <code style="font-family:var(--mono);background:rgba(201,151,58,.15);padding:.1em .3em;border-radius:3px">HARDCODED_ADMINS</code> array in <code style="font-family:var(--mono);background:rgba(201,151,58,.15);padding:.1em .3em;border-radius:3px">auth/unified_login.php</code>.
        </div>
        <?php endif; ?>
    </div>

    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">Account Details</div>
        <div style="display:flex;flex-direction:column;gap:.75rem">
            <?php
            $details = [
                ['Name',        htmlspecialchars($staffRecord['full_name'] ?? $adminName)],
                ['Email',       htmlspecialchars($adminEmail)],
                ['Role',        ucfirst($adminRole)],
                ['Account Type',$isDbAccount ? 'Database (editable)' : 'Hardcoded'],
                ['Status',      $isDbAccount ? ($staffRecord['is_active'] ? 'Active' : 'Inactive') : 'Active (hardcoded)'],
                ['Phone',       $isDbAccount ? (htmlspecialchars($staffRecord['phone'] ?? '') ?: '—') : '—'],
                ['Member Since',$isDbAccount ? date('d M Y', strtotime($staffRecord['created_at'])) : 'N/A'],
            ];
            foreach ($details as [$label, $val]):
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border)">
                <span style="font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim)"><?= $label ?></span>
                <span style="font-size:.82rem;color:var(--text)"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'security'): ?>
<!-- Security Tab -->
<div class="grid-2">
    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">Change Password</div>
        <?php if ($isDbAccount): ?>
        <form method="POST" action="admin_settings.php?tab=security">
            <input type="hidden" name="action" value="change_password">
            <div class="field" style="margin-bottom:.9rem">
                <label>Current Password</label>
                <input type="password" name="current_password" required placeholder="Enter current password">
            </div>
            <div class="field" style="margin-bottom:.9rem">
                <label>New Password (min 8 characters)</label>
                <input type="password" name="new_password" required minlength="8" placeholder="New password" id="newPw">
            </div>
            <div class="field" style="margin-bottom:.5rem">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required placeholder="Repeat new password" id="confirmPw">
            </div>
            <div id="pwMatch" style="font-size:.73rem;margin-bottom:1rem;min-height:18px"></div>
            <button type="submit" class="btn btn-danger">Change Password</button>
        </form>
        <script>
        const np = document.getElementById('newPw');
        const cp = document.getElementById('confirmPw');
        const pm = document.getElementById('pwMatch');
        function checkMatch() {
            if (!cp.value) { pm.textContent = ''; return; }
            if (np.value === cp.value) {
                pm.style.color = '#3dc96a'; pm.textContent = '✓ Passwords match';
            } else {
                pm.style.color = '#e05555'; pm.textContent = '✗ Passwords do not match';
            }
        }
        np.addEventListener('input', checkMatch);
        cp.addEventListener('input', checkMatch);
        </script>
        <?php else: ?>
        <div style="background:var(--gold-dim);border:1px solid var(--gold-border);border-radius:6px;padding:1rem;font-size:.82rem;color:var(--gold);line-height:1.7">
            <div style="font-weight:700;margin-bottom:.4rem">Cannot Change Password Here</div>
            Edit the <code style="font-family:var(--mono);background:rgba(201,151,58,.15);padding:.1em .3em;border-radius:3px">HARDCODED_ADMINS</code> array in <code style="font-family:var(--mono);background:rgba(201,151,58,.15);padding:.1em .3em;border-radius:3px">auth/unified_login.php</code>.
        </div>
        <?php endif; ?>
    </div>

    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">Password Requirements</div>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:.6rem">
            <?php
            $reqs = [
                ['Minimum 8 characters', true],
                ['Mix of letters and numbers recommended', true],
                ['Do not reuse old passwords', true],
                ['Avoid sharing your password', true],
            ];
            foreach ($reqs as [$req, $met]):
            ?>
            <li style="display:flex;align-items:center;gap:.6rem;font-size:.8rem;color:var(--text-sub)">
                <span style="color:var(--green);font-size:.9rem">✓</span>
                <?= $req ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <hr class="divider">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--red);margin-bottom:.75rem">Danger Zone</div>
        <p style="font-size:.8rem;color:var(--text-sub);line-height:1.65;margin-bottom:1rem">Logging out ends your session immediately. Any unsaved work will be lost.</p>
        <a href="admin_logout.php" class="btn btn-danger" onclick="return confirm('Log out of your session now?')">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout Now
        </a>
    </div>
</div>

<?php elseif ($activeTab === 'system'): ?>
<!-- System Info Tab -->
<div class="grid-2">
    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">System Information</div>
        <?php
        $sysInfo = [
            ['PHP Version',      PHP_VERSION],
            ['Server Software',  $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'],
            ['Server Time',      date('d M Y, H:i:s T')],
            ['Panel Version',    'v2.0'],
            ['Session Driver',   'PHP Native'],
            ['DB Connection',    'MySQL via PDO'],
        ];
        ?>
        <div style="display:flex;flex-direction:column;gap:.6rem">
            <?php foreach ($sysInfo as [$k, $v]): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border)">
                <span style="font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim)"><?= $k ?></span>
                <span class="mono" style="font-size:.78rem;color:var(--text)"><?= htmlspecialchars($v) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card card-pad">
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:1rem">Quick Links</div>
        <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php
            $links = [
                ['admin_dashboard.php', 'Dashboard', 'View analytics overview'],
                ['admin_users.php',     'Users',     'Manage user accounts'],
                ['admin_staff.php',     'Staff',     'Manage staff accounts'],
                ['admin_workouts.php',  'Workouts',  'Manage workout content'],
            ];
            foreach ($links as [$url, $label, $desc]):
            ?>
            <a href="<?= $url ?>" style="display:flex;align-items:center;justify-content:space-between;padding:.7rem .9rem;background:var(--elevated);border:1px solid var(--border);border-radius:6px;text-decoration:none;color:var(--text);transition:border-color .12s"
               onmouseover="this.style.borderColor='var(--border2)'"
               onmouseout="this.style.borderColor='var(--border)'">
                <div>
                    <div style="font-weight:600;font-size:.83rem"><?= $label ?></div>
                    <div style="font-size:.73rem;color:var(--text-sub);margin-top:2px"><?= $desc ?></div>
                </div>
                <span style="color:var(--text-dim);font-size:.9rem">›</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php layoutFooter(); ?>