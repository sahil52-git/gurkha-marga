<?php
// frontend/admin/admin_subscriptions.php
session_start();
define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;
require_once __DIR__ . '/admin_layout.php';
require_once dirname(dirname(__DIR__)) . '/backend/subscription_helper.php';

// ── Handle POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Cancel subscription
    if (isset($_POST['cancel_sub_id'])) {
        execute(
            "UPDATE subscriptions SET status='cancelled', updated_at=NOW() WHERE id=?",
            [(int)$_POST['cancel_sub_id']]
        );
        header('Location: admin_subscriptions.php?msg=cancelled'); exit();
    }

    // Manual assign consultant/dietician
    if (isset($_POST['assign_user_id'])) {
        $uid  = (int)$_POST['assign_user_id'];
        $cid  = $_POST['consultant_id'] ? (int)$_POST['consultant_id'] : null;
        $did  = $_POST['dietician_id']  ? (int)$_POST['dietician_id']  : null;
        execute(
            "INSERT INTO user_assignments (user_id, consultant_id, dietician_id, assigned_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               consultant_id=VALUES(consultant_id),
               dietician_id=VALUES(dietician_id),
               assigned_at=NOW()",
            [$uid, $cid, $did]
        );
        // Ensure chat room exists
        $existing = fetchOne("SELECT id FROM chat_rooms WHERE user_id=?", [$uid]);
        if (!$existing) {
            execute("INSERT INTO chat_rooms (user_id, created_at) VALUES (?, NOW())", [$uid]);
        }
        header('Location: admin_subscriptions.php?msg=assigned'); exit();
    }
}

// ── Filters ────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterPlan   = $_GET['plan']   ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1'];
$params = [];
if ($filterStatus) { $where[] = 's.status=?';   $params[] = $filterStatus; }
if ($filterPlan)   { $where[] = 'p.slug=?';      $params[] = $filterPlan; }
if ($search)       {
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$whereStr = implode(' AND ', $where);

$total = (int)(fetchOne(
    "SELECT COUNT(*) AS c
     FROM subscriptions s
     JOIN users u ON u.id=s.user_id
     JOIN subscription_plans p ON p.id=s.plan_id
     WHERE {$whereStr}",
    $params
)['c'] ?? 0);

$subs = fetchAll(
    "SELECT s.*,
            u.full_name, u.email, u.target_force,
            p.name AS plan_name, p.slug AS plan_slug, p.price AS plan_price,
            ua.consultant_id, ua.dietician_id,
            c.full_name AS consultant_name,
            d.full_name AS dietician_name,
            cr.id AS room_id
     FROM subscriptions s
     JOIN users u ON u.id=s.user_id
     JOIN subscription_plans p ON p.id=s.plan_id
     LEFT JOIN user_assignments ua ON ua.user_id=s.user_id
     LEFT JOIN admin_staff c ON c.id=ua.consultant_id
     LEFT JOIN admin_staff d ON d.id=ua.dietician_id
     LEFT JOIN chat_rooms cr ON cr.user_id=s.user_id
     WHERE {$whereStr}
     ORDER BY s.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$pages   = max(1, (int)ceil($total / $perPage));
$revenue = getRevenueSummary();
$plans   = fetchAll("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY price");

// Staff lists for assignment dropdown
$consultants = fetchAll("SELECT id, full_name, speciality FROM admin_staff WHERE role='consultant' AND is_active=1 ORDER BY full_name");
$dieticians  = fetchAll("SELECT id, full_name, speciality FROM admin_staff WHERE role='dietician'  AND is_active=1 ORDER BY full_name");

$forceLabels = ['british'=>'🇬🇧 British','nepal'=>'🇳🇵 Nepal','indian'=>'🇮🇳 Indian','singapore'=>'🇸🇬 Singapore','french'=>'🇫🇷 French'];
?>

<style>
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:16px;padding:1.75rem;width:420px;max-width:95vw}
.modal-title{font-family:var(--font-display);font-size:1.05rem;font-weight:700;margin-bottom:1.25rem}
.modal-field{margin-bottom:1rem}
.modal-field label{display:block;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-dim);margin-bottom:.4rem}
.modal-field select,.modal-field input{width:100%;background:var(--bg-surface);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.55rem .85rem;font-size:.875rem;outline:none}
.modal-actions{display:flex;gap:.75rem;margin-top:1.5rem;justify-content:flex-end}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Subscriptions</h1>
    <p class="page-sub">Revenue tracking, assignments & subscription management</p>
  </div>
</div>

<!-- Revenue Strip -->
<div class="stats-grid mb">
  <div class="stat-card">
    <div class="stat-label">Total Revenue</div>
    <div class="stat-val">रू <?= number_format($revenue['all'], 0) ?></div>
    <div class="stat-sub">All-time from active subs</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">This Month</div>
    <div class="stat-val">रू <?= number_format($revenue['month'], 0) ?></div>
    <div class="stat-sub">Last 30 days</div>
    <span class="stat-chip up">रू <?= number_format($revenue['today'], 0) ?> today</span>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Subscribers</div>
    <div class="stat-val"><?= $revenue['active'] ?></div>
    <div class="stat-sub">Currently active & unexpired</div>
    <span class="stat-chip neutral">Live</span>
  </div>
  <div class="stat-card">
    <div class="stat-label">Filtered Records</div>
    <div class="stat-val"><?= $total ?></div>
    <div class="stat-sub">Matching current filter</div>
  </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div style="background:rgba(13,184,124,.1);border:1px solid rgba(13,184,124,.25);border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1.25rem;color:#4ddba8;font-size:.88rem">
  <?php if ($_GET['msg'] === 'cancelled'): ?>✅ Subscription cancelled.
  <?php elseif ($_GET['msg'] === 'assigned'): ?>✅ Consultant & dietician assigned successfully.
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb" style="padding:1rem 1.25rem">
  <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email…"
           style="background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.5rem .85rem;font-size:.85rem;width:220px;outline:none">
    <select name="status" style="background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.5rem .85rem;font-size:.85rem;outline:none">
      <option value="">All Status</option>
      <option value="active"    <?= $filterStatus==='active'    ?'selected':'' ?>>Active</option>
      <option value="expired"   <?= $filterStatus==='expired'   ?'selected':'' ?>>Expired</option>
      <option value="pending"   <?= $filterStatus==='pending'   ?'selected':'' ?>>Pending</option>
      <option value="cancelled" <?= $filterStatus==='cancelled' ?'selected':'' ?>>Cancelled</option>
    </select>
    <select name="plan" style="background:var(--bg-elevated);border:1px solid var(--border-col);border-radius:8px;color:var(--text-1);padding:.5rem .85rem;font-size:.85rem;outline:none">
      <option value="">All Plans</option>
      <?php foreach ($plans as $pl): ?>
      <option value="<?= $pl['slug'] ?>" <?= $filterPlan===$pl['slug']?'selected':'' ?>><?= htmlspecialchars($pl['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="padding:.5rem 1rem;font-size:.85rem">Filter</button>
    <a href="admin_subscriptions.php" class="btn" style="padding:.5rem 1rem;font-size:.85rem;background:var(--bg-elevated);border:1px solid var(--border-col);color:var(--text-2)">Reset</a>
  </form>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden">
  <table style="width:100%;border-collapse:collapse;font-size:.82rem">
    <thead>
      <tr style="border-bottom:1px solid var(--border-col)">
        <?php foreach(['User','Plan','Amount','Status','Assignment','Started','Expires','eSewa Ref','Actions'] as $h): ?>
        <th style="text-align:left;padding:.8rem 1rem;color:var(--text-dim);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;font-weight:600"><?= $h ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($subs)): ?>
      <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text-dim)">No subscriptions found.</td></tr>
      <?php else: ?>
      <?php foreach ($subs as $s):
        $statusColors = [
          'active'    => ['rgba(45,164,78,.15)','#2da44e'],
          'expired'   => ['rgba(139,143,168,.15)','#8b8fa8'],
          'pending'   => ['rgba(249,200,66,.15)','#f5c842'],
          'cancelled' => ['rgba(204,51,51,.15)','#cc3333'],
        ];
        [$sBg,$sFg] = $statusColors[$s['status']] ?? ['rgba(255,255,255,.08)','#8b8fa8'];
        $isActive   = ($s['status'] === 'active');
        $daysLeft   = $isActive && $s['expires_at'] ? max(0, (int)ceil((strtotime($s['expires_at']) - time()) / 86400)) : 0;
        $force      = $forceLabels[$s['target_force'] ?? ''] ?? '';
      ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
        <td style="padding:.75rem 1rem">
          <div style="font-weight:600;color:var(--text-1)"><?= htmlspecialchars($s['full_name']) ?></div>
          <div style="color:var(--text-dim);font-size:.75rem"><?= htmlspecialchars($s['email']) ?></div>
          <?php if ($force): ?><div style="font-size:.72rem;color:var(--text-dim);margin-top:1px"><?= $force ?></div><?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem">
          <span style="background:rgba(59,111,245,.12);color:#7aa8ff;padding:.2rem .55rem;border-radius:6px;font-size:.75rem;font-weight:600">
            <?= htmlspecialchars($s['plan_name']) ?>
          </span>
        </td>
        <td style="padding:.75rem 1rem;font-family:var(--mono);color:var(--gold);font-weight:700;font-size:.88rem">
          रू <?= number_format((float)$s['amount'], 0) ?>
        </td>
        <td style="padding:.75rem 1rem">
          <span style="background:<?= $sBg ?>;color:<?= $sFg ?>;padding:.25rem .65rem;border-radius:6px;font-size:.72rem;font-weight:700">
            <?= ucfirst($s['status']) ?>
          </span>
          <?php if ($isActive && $daysLeft): ?>
          <div style="font-size:.7rem;color:var(--text-dim);margin-top:2px"><?= $daysLeft ?>d left</div>
          <?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem;font-size:.78rem">
          <?php if ($s['consultant_name']): ?>
          <div style="color:#6ab4ff">🏋️ <?= htmlspecialchars($s['consultant_name']) ?></div>
          <?php else: ?>
          <div style="color:var(--text-dim)">🏋️ Not assigned</div>
          <?php endif; ?>
          <?php if ($s['dietician_name']): ?>
          <div style="color:#4ddba8;margin-top:2px">🥗 <?= htmlspecialchars($s['dietician_name']) ?></div>
          <?php else: ?>
          <div style="color:var(--text-dim);margin-top:2px">🥗 Not assigned</div>
          <?php endif; ?>
        </td>
        <td style="padding:.75rem 1rem;color:var(--text-dim);font-family:var(--mono);font-size:.78rem">
          <?= $s['starts_at'] ? date('d M Y', strtotime($s['starts_at'])) : '—' ?>
        </td>
        <td style="padding:.75rem 1rem;font-family:var(--mono);font-size:.78rem;color:<?= $isActive ? '#f5c842' : 'var(--text-dim)' ?>">
          <?= $s['expires_at'] ? date('d M Y', strtotime($s['expires_at'])) : '—' ?>
        </td>
        <td style="padding:.75rem 1rem;font-family:var(--mono);font-size:.72rem;color:var(--text-dim);max-width:140px;word-break:break-all">
          <?= $s['esewa_ref_id'] ? htmlspecialchars(substr($s['esewa_ref_id'], 0, 20)) . (strlen($s['esewa_ref_id']) > 20 ? '…' : '') : '—' ?>
        </td>
        <td style="padding:.75rem 1rem">
          <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <!-- Assign button -->
            <button onclick="openAssign(<?= $s['user_id'] ?>,'<?= addslashes(htmlspecialchars($s['full_name'])) ?>',<?= (int)($s['consultant_id']??0) ?>,<?= (int)($s['dietician_id']??0) ?>)"
                    style="font-size:.72rem;padding:.25rem .65rem;border-radius:6px;background:rgba(59,111,245,.15);color:#7aa8ff;border:none;cursor:pointer;font-weight:600">
              Assign
            </button>
            <?php if ($s['room_id']): ?>
            <a href="#" style="font-size:.72rem;padding:.25rem .65rem;border-radius:6px;background:rgba(13,184,124,.12);color:#4ddba8;text-decoration:none;font-weight:600">
              Chat
            </a>
            <?php endif; ?>
            <?php if ($s['status'] === 'active'): ?>
            <form method="POST" onsubmit="return confirm('Cancel this subscription?')" style="display:inline">
              <input type="hidden" name="cancel_sub_id" value="<?= $s['id'] ?>">
              <button type="submit" style="font-size:.72rem;padding:.25rem .65rem;border-radius:6px;background:rgba(204,51,51,.15);color:#e05555;border:none;cursor:pointer;font-weight:600">Cancel</button>
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

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:center">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
  <a href="?page=<?= $i ?>&status=<?= urlencode($filterStatus) ?>&plan=<?= urlencode($filterPlan) ?>&q=<?= urlencode($search) ?>"
     style="padding:.4rem .8rem;border-radius:7px;font-size:.82rem;text-decoration:none;background:<?= $i===$page?'var(--accent)':'var(--bg-elevated)' ?>;color:<?= $i===$page?'#fff':'var(--text-2)' ?>;border:1px solid var(--border-col)">
    <?= $i ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- ── Assign Modal ──────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="assignModal" onclick="closeAssignOnBg(event)">
  <div class="modal-box">
    <div class="modal-title">Assign Consultant & Dietician</div>
    <div style="font-size:.82rem;color:var(--text-dim);margin-bottom:1.25rem">
      Assigning for: <strong id="assignUserName" style="color:var(--text-1)"></strong>
    </div>
    <form method="POST" id="assignForm">
      <input type="hidden" name="assign_user_id" id="assignUserId">
      <div class="modal-field">
        <label>Fitness Consultant 🏋️</label>
        <select name="consultant_id" id="selectConsultant">
          <option value="">— No consultant —</option>
          <?php foreach ($consultants as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?><?= $c['speciality'] ? ' · '.$c['speciality'] : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-field">
        <label>Registered Dietician 🥗</label>
        <select name="dietician_id" id="selectDietician">
          <option value="">— No dietician —</option>
          <?php foreach ($dieticians as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?><?= $d['speciality'] ? ' · '.$d['speciality'] : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeAssign()"
                style="padding:.55rem 1.1rem;border-radius:8px;background:var(--bg-surface);border:1px solid var(--border-col);color:var(--text-2);cursor:pointer;font-size:.85rem">Cancel</button>
        <button type="submit" class="btn btn-primary" style="padding:.55rem 1.25rem;font-size:.85rem">Save Assignment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAssign(userId, userName, currentConsultant, currentDietician) {
  document.getElementById('assignUserId').value      = userId;
  document.getElementById('assignUserName').textContent = userName;
  document.getElementById('selectConsultant').value  = currentConsultant || '';
  document.getElementById('selectDietician').value   = currentDietician  || '';
  document.getElementById('assignModal').classList.add('open');
}
function closeAssign() {
  document.getElementById('assignModal').classList.remove('open');
}
function closeAssignOnBg(e) {
  if (e.target === document.getElementById('assignModal')) closeAssign();
}
</script>

<?php layoutFooter(); ?>