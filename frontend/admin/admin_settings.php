<?php
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';

function safeStaffRecord(string $email): ?array {
    try {
        $rec = fetchOne("SELECT * FROM admin_staff WHERE email = ?", [$email]);
        return (is_array($rec) && !empty($rec)) ? $rec : null;
    } catch (Exception $e) { return null; }
}

$staffRecord = safeStaffRecord($adminEmail);
$isDbAccount = ($staffRecord !== null);
$activeTab   = $_GET['tab'] ?? 'account';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'update_profile' && $isDbAccount) {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone']     ?? '');
        $notes = trim($_POST['notes']     ?? '');
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
                setFlash('error', 'Profile update failed: ' . $e->getMessage());
            }
        }
        redirectTo('admin_settings.php?tab=account');
    }

    if ($act === 'change_password' && $isDbAccount) {
        $cur = $_POST['current_password']  ?? '';
        $new = $_POST['new_password']      ?? '';
        $con = $_POST['confirm_password']  ?? '';
        if (!password_verify($cur, $staffRecord['password'] ?? '')) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $con) {
            setFlash('error', 'Passwords do not match.');
        } else {
            try {
                query("UPDATE admin_staff SET password=? WHERE id=?",
                    [password_hash($new, PASSWORD_DEFAULT), $staffRecord['id']]);
                setFlash('success', 'Password changed successfully.');
            } catch (Exception $e) {
                setFlash('error', 'Password change failed: ' . $e->getMessage());
            }
        }
        redirectTo('admin_settings.php?tab=security');
    }
}

// Refresh after update
$staffRecord = safeStaffRecord($adminEmail);
$isDbAccount = ($staffRecord !== null);

function sr(?array $rec, string $key, string $fallback = ''): string {
    if (!$rec || !isset($rec[$key]) || $rec[$key] === null || $rec[$key] === '') return $fallback;
    return (string)$rec[$key];
}

$displayName  = sr($staffRecord, 'full_name', $adminName ?? 'Admin');
$displayPhone = sr($staffRecord, 'phone', '');
$displayNotes = sr($staffRecord, 'notes', '');
$isActive     = $isDbAccount ? (bool)($staffRecord['is_active'] ?? false) : true;

$memberSince = 'N/A';
if ($isDbAccount && !empty($staffRecord['created_at'])) {
    $ts = strtotime($staffRecord['created_at']);
    if ($ts && $ts > mktime(0,0,0,1,2,1970))
        $memberSince = date('M j, Y', $ts);
    else
        $memberSince = 'Not recorded';
}

// Days active
$daysActive = 'N/A';
if ($isDbAccount && !empty($staffRecord['created_at'])) {
    $ts = strtotime($staffRecord['created_at']);
    if ($ts && $ts > mktime(0,0,0,1,2,1970))
        $daysActive = max(0, (int)floor((time() - $ts) / 86400)) . ' days';
}

$stats = ['users'=>0,'staff'=>0,'workouts'=>0];
try {
    $stats['users']    = (int)(fetchOne("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);
    $stats['staff']    = (int)(fetchOne("SELECT COUNT(*) AS c FROM admin_staff WHERE is_active=1")['c'] ?? 0);
    try { $stats['workouts'] = (int)(fetchOne("SELECT COUNT(*) AS c FROM workouts")['c'] ?? 0); } catch(Exception $e){}
} catch (Exception $e){}

// ── System info ────────────────────────────────────────────────────────────
$dbVersion = 'N/A';
try { $r = fetchOne("SELECT VERSION() AS v"); if ($r && isset($r['v'])) $dbVersion = $r['v']; } catch(Exception $e){}
$peakMem  = round(memory_get_peak_usage(true)/1024/1024,2).' MB';
$srvLoad  = function_exists('sys_getloadavg') ? implode(', ', array_map(fn($l)=>round($l,2), sys_getloadavg())) : 'N/A';

// ── Tabs config ────────────────────────────────────────────────────────────
$tabs = [
    'account'  => ['icon'=>'account', 'label'=>'Account Status'],
    'profile'  => ['icon'=>'profile', 'label'=>'Edit Profile'],
    'security' => ['icon'=>'security','label'=>'Security'],
    'system'   => ['icon'=>'system',  'label'=>'System Info'],
    'links'    => ['icon'=>'links',   'label'=>'Quick Links'],
];

// SVG icons per tab
function tabIcon(string $key): string {
    return match($key) {
        'account'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>',
        'profile'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'security' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'system'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
        'links'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>',
        default    => '',
    };
}
?>

<style>
/* ── Settings Layout ───────────────────────────────────────────────── */
.settings-wrap {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media(max-width:860px){
    .settings-wrap { grid-template-columns: 1fr; }
    .settings-sidebar { display:flex; flex-wrap:wrap; gap:.4rem; }
}

/* ── Sidebar ───────────────────────────────────────────────────────── */
.settings-sidebar {
    background: var(--elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    position: sticky;
    top: 1rem;
}
.settings-nav-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .8rem 1.1rem;
    font-size: .83rem;
    font-weight: 500;
    color: var(--text-sub);
    text-decoration: none;
    border-left: 3px solid transparent;
    transition: all .15s;
    cursor: pointer;
}
.settings-nav-item svg {
    width: 16px; height: 16px;
    flex-shrink: 0;
    opacity: .7;
}
.settings-nav-item:hover {
    background: var(--bg);
    color: var(--text);
}
.settings-nav-item.active {
    background: var(--bg);
    color: var(--accent);
    border-left-color: var(--accent);
    font-weight: 600;
}
.settings-nav-item.active svg { opacity: 1; }
.nav-divider {
    height: 1px;
    background: var(--border);
    margin: .3rem 0;
}

/* ── Panel ─────────────────────────────────────────────────────────── */
.settings-panel {
    background: var(--elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
}
.panel-header-left {
    display: flex;
    align-items: center;
    gap: .75rem;
}
.panel-header-icon {
    width: 36px; height: 36px;
    background: var(--accent-dim, rgba(99,102,241,.12));
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: var(--accent);
}
.panel-header-icon svg { width:18px; height:18px; }
.panel-title  { font-size: .95rem; font-weight: 700; }
.panel-sub    { font-size: .73rem; color: var(--text-dim); margin-top:2px; }
.panel-body   { padding: 1.5rem; }

/* ── Detail rows ───────────────────────────────────────────────────── */
.detail-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .85rem 0;
    border-bottom: 1px solid var(--border);
    gap: 1rem;
}
.detail-row:last-child { border-bottom: none; }
.detail-label {
    font-size: .75rem;
    font-weight: 600;
    color: var(--text-dim);
    letter-spacing: .04em;
    flex-shrink: 0;
}
.detail-value {
    font-size: .85rem;
    color: var(--text);
    font-weight: 500;
    text-align: right;
}

/* ── Status pill ───────────────────────────────────────────────────── */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .25rem .75rem;
    border-radius: 99px;
    font-size: .72rem;
    font-weight: 600;
}
.status-pill::before {
    content: '';
    width: 7px; height: 7px;
    border-radius: 50%;
}
.status-active  { background:rgba(61,201,106,.12); color:#3dc96a; }
.status-active::before  { background:#3dc96a; box-shadow:0 0 5px #3dc96a88; }
.status-inactive{ background:rgba(224,85,85,.12); color:#e05555; }
.status-inactive::before{ background:#e05555; }

/* ── Stats row ─────────────────────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: .75rem;
    margin-bottom: 1.5rem;
}
.stat-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .9rem;
    text-align: center;
}
.stat-num  { font-family:var(--mono);font-size:1.3rem;font-weight:700; }
.stat-lbl  { font-size:.62rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:.08em;margin-top:3px; }

/* ── Form fields ───────────────────────────────────────────────────── */
.sfield       { display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem; }
.sfield label { font-size:.72rem;font-weight:700;color:var(--text-dim);letter-spacing:.05em;text-transform:uppercase; }
.sfield input,
.sfield textarea {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: .65rem .85rem;
    color: var(--text);
    font-size: .84rem;
    width: 100%;
    box-sizing: border-box;
    transition: border-color .15s;
    font-family: inherit;
}
.sfield input:focus,
.sfield textarea:focus {
    outline: none;
    border-color: var(--accent);
}
.sfield input:disabled {
    opacity: .5;
    cursor: not-allowed;
}
.sfield .hint { font-size:.68rem;color:var(--text-dim); }

/* ── Buttons ───────────────────────────────────────────────────────── */
.sbtn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .6rem 1.2rem;
    border-radius: 7px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: opacity .15s, transform .1s;
}
.sbtn:hover  { opacity: .88; transform: translateY(-1px); }
.sbtn:active { transform: translateY(0); }
.sbtn-primary { background:var(--accent);color:#fff; }
.sbtn-danger  { background:#e05555;color:#fff; }
.sbtn-outline { background:transparent;border:1px solid var(--border);color:var(--text); }

/* ── Warning box ───────────────────────────────────────────────────── */
.warn-box {
    background: rgba(201,151,58,.08);
    border: 1px solid rgba(201,151,58,.25);
    border-radius: 8px;
    padding: 1rem 1.1rem;
    font-size: .82rem;
    color: var(--gold, #c9973a);
    line-height: 1.7;
}
.warn-box strong { display:block;margin-bottom:.3rem;font-weight:700; }
.warn-box code   { font-family:var(--mono);background:rgba(201,151,58,.15);padding:.1em .35em;border-radius:3px; }

/* ── Password match ────────────────────────────────────────────────── */
#pwMatch { font-size:.73rem;min-height:18px;margin-bottom:.75rem; }

/* ── Quick link item ───────────────────────────────────────────────── */
.qlink {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text);
    margin-bottom: .5rem;
    transition: border-color .12s, background .12s;
}
.qlink:hover { border-color: var(--accent); background: var(--elevated); }
.qlink-arrow { color: var(--text-dim); font-size:1rem; }

/* ── Profile card at top ───────────────────────────────────────────── */
.profile-card {
    background: var(--elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.avatar-lg {
    width: 56px; height: 56px;
    border-radius: 10px;
    background: var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 700;
    color: #fff; font-family: var(--mono);
    flex-shrink: 0;
}
.profile-meta { flex: 1; min-width: 160px; }
.profile-name { font-weight: 700; font-size: 1rem; }
.profile-email{ font-size: .77rem; color: var(--text-sub); margin-top: 2px; }
.badge-row    { display:flex; gap:.45rem; flex-wrap:wrap; margin-top:.5rem; }

/* ── Req list ──────────────────────────────────────────────────────── */
.req-list { list-style:none; display:flex; flex-direction:column; gap:.55rem; margin:0; padding:0; }
.req-list li { display:flex; align-items:center; gap:.6rem; font-size:.8rem; color:var(--text-sub); }
.req-check { color:var(--green,#3dc96a); font-size:.9rem; }
</style>

<!-- ════════════════════ PAGE HEADER ════════════════════ -->
<div class="page-header" style="margin-bottom:1.25rem">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">Manage your account, security and preferences</p>
    </div>
</div>

<!-- ════════════════════ PROFILE STRIP ════════════════════ -->
<div class="profile-card">
    <div class="avatar-lg"><?= strtoupper(substr($displayName,0,1)) ?></div>
    <div class="profile-meta">
        <div class="profile-name"><?= htmlspecialchars($displayName) ?></div>
        <div class="profile-email"><?= htmlspecialchars($adminEmail) ?></div>
        <div class="badge-row">
            <span class="badge badge-blue"><?= ucfirst($adminRole) ?></span>
            <?php if ($isDbAccount): ?>
                <span class="badge badge-green">Database Account</span>
            <?php else: ?>
                <span class="badge badge-gold">Hardcoded Account</span>
            <?php endif; ?>
            <span class="<?= $isActive ? 'status-pill status-active' : 'status-pill status-inactive' ?>">
                <?= $isActive ? 'Active' : 'Inactive' ?>
            </span>
        </div>
    </div>
    <div class="stats-grid" style="margin-bottom:0;min-width:280px">
        <?php foreach([['Users',$stats['users']],['Staff',$stats['staff']],['Workouts',$stats['workouts']]] as [$l,$n]): ?>
        <div class="stat-box">
            <div class="stat-num"><?= $n ?></div>
            <div class="stat-lbl"><?= $l ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ════════════════════ MAIN LAYOUT ════════════════════ -->
<div class="settings-wrap">

    <!-- ── Sidebar ── -->
    <nav class="settings-sidebar">
        <?php foreach ($tabs as $key => $tab): ?>
            <?php if ($key === 'system'): ?><div class="nav-divider"></div><?php endif; ?>
            <a href="admin_settings.php?tab=<?= $key ?>"
               class="settings-nav-item <?= $activeTab===$key?'active':'' ?>">
                <?= tabIcon($key) ?>
                <?= $tab['label'] ?>
            </a>
        <?php endforeach; ?>
        <div class="nav-divider"></div>
        <a href="admin_logout.php"
           onclick="return confirm('Log out now?')"
           class="settings-nav-item"
           style="color:#e05555">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:16px;height:16px;opacity:1">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Logout
        </a>
    </nav>

    <!-- ── Panel ── -->
    <div class="settings-panel">

        <!-- Panel header -->
        <div class="panel-header">
            <div class="panel-header-left">
                <div class="panel-header-icon"><?= tabIcon($activeTab) ?></div>
                <div>
                    <div class="panel-title"><?= $tabs[$activeTab]['label'] ?? 'Settings' ?></div>
                    <div class="panel-sub">
                        <?= match($activeTab) {
                            'account'  => 'Your current account standing and details',
                            'profile'  => 'Update your name, phone and bio',
                            'security' => 'Change your password and manage session',
                            'system'   => 'Server and application information',
                            'links'    => 'Navigate to other admin sections',
                            default    => '',
                        } ?>
                    </div>
                </div>
            </div>
            <?php if ($isDbAccount && $isActive): ?>
            <span class="status-pill status-active">Active</span>
            <?php elseif ($isDbAccount): ?>
            <span class="status-pill status-inactive">Inactive</span>
            <?php else: ?>
            <span class="status-pill status-active">Active</span>
            <?php endif; ?>
        </div>

        <div class="panel-body">

        <?php if ($activeTab === 'account'): ?>
        <!-- ══ ACCOUNT STATUS ══ -->
        <?php
        $rows = [
            ['Full Name',    htmlspecialchars($displayName)],
            ['Email',        htmlspecialchars($adminEmail)],
            ['Account Type', $isDbAccount ? 'Database (editable)' : 'Hardcoded'],
            ['Role',         ucfirst($adminRole)],
            ['Member Since', $memberSince],
            ['Days Active',  $daysActive],
            ['Phone',        $displayPhone ? htmlspecialchars($displayPhone) : '—'],
            ['Status',       $isDbAccount
                ? ($isActive
                    ? '<span class="status-pill status-active">Active</span>'
                    : '<span class="status-pill status-inactive">Inactive</span>')
                : '<span class="status-pill status-active">Active</span>'],
        ];
        foreach ($rows as [$label, $val]):
        ?>
        <div class="detail-row">
            <span class="detail-label"><?= $label ?></span>
            <span class="detail-value"><?= $val ?></span>
        </div>
        <?php endforeach; ?>

        <?php elseif ($activeTab === 'profile'): ?>
        <!-- ══ EDIT PROFILE ══ -->
        <?php if ($isDbAccount): ?>
        <form method="POST" action="admin_settings.php?tab=profile">
            <input type="hidden" name="action" value="update_profile">
            <div class="sfield">
                <label>Full Name</label>
                <input type="text" name="full_name"
                       value="<?= htmlspecialchars($displayName) ?>" required>
            </div>
            <div class="sfield">
                <label>Email Address</label>
                <input type="email" value="<?= htmlspecialchars($adminEmail) ?>" disabled>
                <span class="hint">Email cannot be changed here. Contact a Superadmin.</span>
            </div>
            <div class="sfield">
                <label>Phone Number</label>
                <input type="text" name="phone"
                       value="<?= htmlspecialchars($displayPhone) ?>"
                       placeholder="+977 98XXXXXXXX">
            </div>
            <div class="sfield">
                <label>Notes / Bio</label>
                <textarea name="notes" rows="3"
                          placeholder="Role description, access notes…"><?= htmlspecialchars($displayNotes) ?></textarea>
            </div>
            <button type="submit" class="sbtn sbtn-primary">Save Changes</button>
        </form>
        <?php else: ?>
        <div class="warn-box">
            <strong>Hardcoded Admin Account</strong>
            Profile editing is not available for hardcoded accounts. Edit the
            <code>HARDCODED_ADMINS</code> array in
            <code>auth/unified_login.php</code>.
        </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'security'): ?>
        <!-- ══ SECURITY ══ -->
        <?php if ($isDbAccount): ?>
        <form method="POST" action="admin_settings.php?tab=security">
            <input type="hidden" name="action" value="change_password">
            <div class="sfield">
                <label>Current Password</label>
                <input type="password" name="current_password"
                       required placeholder="Enter current password">
            </div>
            <div class="sfield">
                <label>New Password</label>
                <input type="password" name="new_password" id="newPw"
                       required minlength="8" placeholder="Min. 8 characters">
            </div>
            <div class="sfield">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" id="confirmPw"
                       required placeholder="Repeat new password">
            </div>
            <div id="pwMatch"></div>
            <button type="submit" class="sbtn sbtn-danger">Change Password</button>
        </form>
        <script>
        (() => {
            const np = document.getElementById('newPw');
            const cp = document.getElementById('confirmPw');
            const pm = document.getElementById('pwMatch');
            function check() {
                if (!cp.value) { pm.textContent=''; return; }
                const ok = np.value === cp.value;
                pm.style.color = ok ? '#3dc96a' : '#e05555';
                pm.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
            }
            np.addEventListener('input', check);
            cp.addEventListener('input', check);
        })();
        </script>
        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);margin-bottom:.75rem">Password Requirements</div>
        <ul class="req-list" style="margin-bottom:1.5rem">
            <?php foreach(['Minimum 8 characters','Mix of letters and numbers recommended','Do not reuse old passwords','Avoid sharing your password'] as $r): ?>
            <li><span class="req-check">✓</span> <?= htmlspecialchars($r) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="warn-box" style="margin-bottom:1.5rem">
            <strong>Cannot Change Password Here</strong>
            Edit the <code>HARDCODED_ADMINS</code> array in <code>auth/unified_login.php</code>.
        </div>
        <?php endif; ?>

        <hr style="border:none;border-top:1px solid var(--border);margin:0 0 1.25rem">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#e05555;margin-bottom:.6rem">Danger Zone</div>
        <p style="font-size:.8rem;color:var(--text-sub);line-height:1.6;margin-bottom:1rem">Logging out ends your session immediately. Any unsaved work will be lost.</p>
        <a href="admin_logout.php" class="sbtn sbtn-danger"
           onclick="return confirm('Log out of your session now?')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Logout Now
        </a>

        <?php elseif ($activeTab === 'system'): ?>
        <!-- ══ SYSTEM INFO ══ -->
        <?php
        $sysRows = [
            ['PHP Version',     PHP_VERSION],
            ['MySQL Version',   $dbVersion],
            ['Server Software', $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'],
            ['Server Time',     date('d M Y, H:i:s T')],
            ['Peak Memory',     $peakMem],
            ['Server Load',     $srvLoad],
            ['Panel Version',   'v2.0'],
            ['Session Driver',  'PHP Native'],
            ['DB Connection',   'MySQL via PDO'],
        ];
        foreach ($sysRows as [$k,$v]):
        ?>
        <div class="detail-row">
            <span class="detail-label"><?= $k ?></span>
            <span class="detail-value" style="font-family:var(--mono);font-size:.78rem">
                <?= htmlspecialchars($v) ?>
            </span>
        </div>
        <?php endforeach; ?>

        <?php elseif ($activeTab === 'links'): ?>
        <!-- ══ QUICK LINKS ══ -->
        <?php foreach([
            ['admin_dashboard.php','Dashboard',  'View analytics overview'],
            ['admin_users.php',    'Users',      'Manage user accounts'],
            ['admin_staff.php',    'Staff',      'Manage staff accounts'],
            ['admin_workouts.php', 'Workouts',   'Manage workout content'],
        ] as [$url,$lbl,$desc]): ?>
        <a href="<?= $url ?>" class="qlink">
            <div>
                <div style="font-weight:600;font-size:.85rem"><?= $lbl ?></div>
                <div style="font-size:.73rem;color:var(--text-sub);margin-top:2px"><?= $desc ?></div>
            </div>
            <span class="qlink-arrow">›</span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>

        </div><!-- /panel-body -->
    </div><!-- /settings-panel -->
</div><!-- /settings-wrap -->

<?php layoutFooter(); ?>