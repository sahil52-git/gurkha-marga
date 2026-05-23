<?php
session_start();

require_once '../../backend/database.php';

define('HARDCODED_ADMINS', [
    ['id' => 1, 'username' => 'admin',      'email' => 'admin@gurkhamarga.com',
     'password' => 'Admin@123', 'name' => 'System Administrator', 'role' => 'superadmin'],
    ['id' => 2, 'username' => 'superadmin', 'email' => 'superadmin@gurkhamarga.com',
     'password' => 'Super@123', 'name' => 'Super Administrator',  'role' => 'superadmin'],
]);

// ── Already logged in? Bounce to the right place ─────────────────────────────
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: ../users/dashboard.php'); exit();
}
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: ../admin/admin_dashboard.php?page=dashboard'); exit();
}
if (!empty($_SESSION['staff_logged_in'])) {
    header('Location: ../staff/premium_client.php'); exit();
}

$error_message   = '';
$success_message = '';
$prefill_email   = '';

if (isset($_SESSION['registration_success'])) {
    $success_message = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $loginInput = trim($_POST['email']    ?? '');
    $password   = $_POST['password']      ?? '';
    $remember   = isset($_POST['remember']);
    $prefill_email = htmlspecialchars($loginInput);

    if (empty($loginInput) || empty($password)) {
        $error_message = 'Please fill in all fields.';

    } else {
        $matched = false;

        foreach (HARDCODED_ADMINS as $cred) {
            if (($loginInput === $cred['email'] || $loginInput === $cred['username'])
                && $password === $cred['password']) {

                $matched = true;
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id']        = $cred['id'];
                $_SESSION['admin_name']      = $cred['name'];
                $_SESSION['admin_email']     = $cred['email'];
                $_SESSION['admin_role']      = $cred['role'];

                header('Location: ../admin/admin_dashboard.php?page=dashboard'); exit();
            }
        }

        if (!$matched) {
            try {
                $staff = fetchOne(
                    "SELECT * FROM admin_staff WHERE email = ? AND is_active = 1 LIMIT 1",
                    [$loginInput]
                );

                if ($staff && password_verify($password, $staff['password'])) {
                    $matched = true;

                    if (in_array($staff['role'], ['superadmin', 'admin'])) {
                        // Admin-role staff → admin panel
                        session_regenerate_id(true);
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id']        = $staff['id'];
                        $_SESSION['admin_name']      = $staff['full_name'];
                        $_SESSION['admin_email']     = $staff['email'];
                        $_SESSION['admin_role']      = $staff['role'];
                        try { query("UPDATE admin_staff SET last_login = NOW() WHERE id = ?", [$staff['id']]); } catch(Exception $e){}
                        header('Location: ../admin/admin_dashboard.php?page=dashboard'); exit();

                    } else {
                        // dietitian / consultant → staff portal
                        session_regenerate_id(true);
                        $_SESSION['staff_logged_in'] = true;
                        $_SESSION['staff_id']        = $staff['id'];
                        $_SESSION['staff_name']      = $staff['full_name'];
                        $_SESSION['staff_email']     = $staff['email'];
                        $_SESSION['staff_role']      = $staff['role'];
                        try { query("UPDATE admin_staff SET last_login = NOW() WHERE id = ?", [$staff['id']]); } catch(Exception $e){}
                        header('Location: ../staff/premium_client.php'); exit();
                    }
                }
            } catch (Exception $e) {
                // admin_staff table may not exist yet — fall through
            }
        }

        if (!$matched) {
            try {
                $user = fetchOne(
                    "SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1",
                    [$loginInput]
                );

                if ($user && password_verify($password, $user['password'])) {
                    $matched = true;
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['logged_in']  = true;

                    if ($remember) {
                        $params = session_get_cookie_params();
                        setcookie(session_name(), session_id(),
                            time() + (60 * 60 * 24 * 30),
                            $params['path'], $params['domain'],
                            $params['secure'], $params['httponly']);
                    }

                    $ip         = $_SERVER['REMOTE_ADDR']     ?? 'Unknown';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    try {
                        query("INSERT INTO login_logs (user_id, ip_address, user_agent, status) VALUES (?, ?, ?, 'success')",
                            [$user['id'], $ip, $user_agent]);
                        query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
                    } catch (Exception $e) {}

                    header('Location: ../users/dashboard.php'); exit();

                } elseif ($user) {
                    $ip         = $_SERVER['REMOTE_ADDR']     ?? 'Unknown';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    try {
                        query("INSERT INTO login_logs (user_id, ip_address, user_agent, status, failure_reason)
                               VALUES (?, ?, ?, 'failed', 'Invalid password')",
                            [$user['id'], $ip, $user_agent]);
                    } catch (Exception $e) {}
                }
            } catch (Exception $e) {
                error_log('Login Error: ' . $e->getMessage());
            }
        }

        if (!$matched) {
            $error_message = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – Gurkha Marga</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary:        #0f172a;
            --accent:         #3b82f6;
            --gold:           #fbbf24;
            --success:        #10b981;
            --error:          #ef4444;
            --text-primary:   #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted:     #64748b;
            --border:         rgba(255,255,255,.08);
            --border-hover:   rgba(255,255,255,.15);
            --surface:        rgba(30,41,59,.75);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0a0f1e 0%, #0f172a 40%, #1a1035 100%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
        }

        /* ── Animated blobs ── */
        .bg-canvas { position: fixed; inset: 0; z-index: 0; overflow: hidden; }
        .blob { position: absolute; border-radius: 50%; filter: blur(72px); opacity: .28; animation: blobDrift 22s infinite ease-in-out; }
        .blob-1 { width: 520px; height: 520px; background: #3b82f6; top: -220px; left: -220px; animation-delay: 0s; }
        .blob-2 { width: 380px; height: 380px; background: #7c3aed; top: 50%; right: -160px; animation-delay: -7s; }
        .blob-3 { width: 460px; height: 460px; background: #0e7490; bottom: -200px; left: 32%; animation-delay: -14s; }
        .blob-4 { width: 260px; height: 260px; background: #fbbf24; top: 20%; left: 55%; opacity: .12; animation-delay: -4s; }
        @keyframes blobDrift {
            0%,100% { transform: translate(0, 0) scale(1); }
            33%      { transform: translate(28px, -45px) scale(1.07); }
            66%      { transform: translate(-18px, 22px) scale(.94); }
        }
        .bg-canvas::after {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.018) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        /* ── Card ── */
        .card-wrap {
            position: relative; z-index: 10;
            width: 100%; max-width: 460px;
        }
        .card-wrap::before {
            content: '';
            position: absolute; inset: -1px;
            border-radius: 26px;
            background: linear-gradient(135deg, rgba(59,130,246,.4) 0%, rgba(124,58,237,.3) 50%, rgba(14,116,144,.4) 100%);
            filter: blur(1px);
            z-index: -1;
            animation: ringPulse 4s ease-in-out infinite;
        }
        @keyframes ringPulse { 0%,100% { opacity: .6; } 50% { opacity: 1; } }

        .card {
            background: var(--surface);
            backdrop-filter: blur(28px) saturate(1.5);
            border: 1px solid var(--border-hover);
            border-radius: 24px;
            padding: 2.75rem 2.5rem;
            animation: cardIn .55s cubic-bezier(.34,1.3,.64,1) both;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(28px) scale(.97); }
            to   { opacity: 1; transform: translateY(0)    scale(1);   }
        }

        /* ── Brand ── */
        .brand-row { display: flex; align-items: center; justify-content: center; gap: 13px; margin-bottom: .35rem; }
        .brand-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, rgba(251,191,36,.2), rgba(251,191,36,.06));
            border: 1px solid rgba(251,191,36,.35);
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.45rem; flex-shrink: 0;
            box-shadow: 0 0 18px rgba(251,191,36,.18);
        }
        .brand-name {
            font-size: 1.65rem; font-weight: 800; letter-spacing: -.02em;
            background: linear-gradient(135deg, var(--gold) 0%, #f59e0b 60%, #fde68a 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .brand-tagline { text-align: center; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 2rem; }

        /* ── Role pills ── */
        .role-strip { display: flex; gap: .5rem; justify-content: center; margin-bottom: 1.75rem; flex-wrap: wrap; }
        .role-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: .28rem .75rem; border-radius: 20px;
            font-size: .72rem; font-weight: 600; letter-spacing: .04em; border: 1px solid;
            opacity: .75; transition: opacity .2s, transform .2s;
        }
        .role-pill:hover { opacity: 1; transform: translateY(-1px); }
        .pill-user  { background: rgba(59,130,246,.12);  border-color: rgba(59,130,246,.3);  color: #93c5fd; }
        .pill-staff { background: rgba(6,182,212,.1);    border-color: rgba(6,182,212,.3);   color: #67e8f9; }
        .pill-admin { background: rgba(251,191,36,.1);   border-color: rgba(251,191,36,.3);  color: var(--gold); }

        /* ── Heading ── */
        .form-heading { text-align: center; margin-bottom: 1.6rem; }
        .form-heading h1 { font-size: 1.55rem; font-weight: 700; letter-spacing: -.02em; margin-bottom: .3rem; }
        .form-heading p  { font-size: .875rem; color: var(--text-secondary); }

        /* ── Alerts ── */
        .alert {
            padding: .9rem 1rem; border-radius: 12px; margin-bottom: 1.4rem;
            font-size: .875rem; display: flex; align-items: flex-start; gap: 9px;
            animation: alertIn .3s ease;
        }
        @keyframes alertIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
        .alert-error   { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.28);  color: #fca5a5; }
        .alert-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.28); color: #6ee7b7; }

        /* ── Fields ── */
        .form-group { margin-bottom: 1.35rem; }
        .form-label { display: flex; align-items: center; gap: 6px; margin-bottom: .5rem; font-weight: 600; font-size: .875rem; }
        .required { color: var(--error); margin-left: 1px; }
        .input-wrap { position: relative; }
        .form-input {
            width: 100%; padding: 13px 16px;
            background: rgba(15,23,42,.55);
            border: 1.5px solid var(--border);
            border-radius: 12px; color: var(--text-primary);
            font-size: .95rem; font-family: 'Poppins', sans-serif;
            transition: border-color .25s, background .25s, box-shadow .25s;
        }
        .form-input:focus { outline: none; border-color: rgba(59,130,246,.6); background: rgba(15,23,42,.75); box-shadow: 0 0 0 4px rgba(59,130,246,.1); }
        .form-input::placeholder { color: var(--text-muted); }
        .form-input.has-right-btn { padding-right: 3rem; }
        .btn-eye {
            position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: var(--text-muted);
            font-size: 1rem; padding: .2rem; transition: color .2s; line-height: 1;
        }
        .btn-eye:hover { color: var(--text-primary); }

        /* ── Remember / forgot ── */
        .extras-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: .5rem; }
        .check-group { display: flex; align-items: center; gap: 9px; }
        .custom-check { position: relative; width: 18px; height: 18px; flex-shrink: 0; }
        .custom-check input { opacity: 0; width: 0; height: 0; position: absolute; }
        .checkmark {
            position: absolute; inset: 0;
            background: rgba(15,23,42,.55); border: 1.5px solid var(--border-hover);
            border-radius: 5px; cursor: pointer; transition: background .2s, border-color .2s;
        }
        .custom-check input:checked ~ .checkmark { background: var(--accent); border-color: var(--accent); }
        .checkmark::after { content: ''; position: absolute; display: none; left: 4px; top: 1px; width: 5px; height: 9px; border: 2px solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg); }
        .custom-check input:checked ~ .checkmark::after { display: block; }
        .check-lbl  { font-size: .855rem; color: var(--text-secondary); cursor: pointer; }
        .forgot-link { font-size: .855rem; color: var(--accent); text-decoration: none; transition: color .2s; }
        .forgot-link:hover { color: var(--gold); }

        /* ── Submit ── */
        .btn-submit {
            width: 100%; padding: 14px 24px; border: none; border-radius: 12px;
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            color: #fff; font-size: 1rem; font-weight: 700; font-family: 'Poppins', sans-serif;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 9px;
            transition: transform .25s, box-shadow .25s, opacity .25s;
            box-shadow: 0 4px 20px rgba(59,130,246,.38);
            position: relative; overflow: hidden; letter-spacing: .01em;
        }
        .btn-submit::before { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,.12) 0%, transparent 60%); opacity: 0; transition: opacity .25s; }
        .btn-submit:hover::before { opacity: 1; }
        .btn-submit:hover   { transform: translateY(-2px); box-shadow: 0 7px 28px rgba(59,130,246,.5); }
        .btn-submit:active  { transform: translateY(0);    box-shadow: 0 3px 12px rgba(59,130,246,.35); }
        .btn-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .spinner { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; flex-shrink: 0; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Divider ── */
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.4rem 0 1.2rem; font-size: .75rem; color: var(--text-muted); letter-spacing: .06em; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        /* ── Footer ── */
        .footer-links { display: flex; flex-direction: column; align-items: center; gap: .6rem; margin-top: 1.25rem; }
        .footer-links p { font-size: .875rem; color: var(--text-secondary); }
        .footer-links a { color: var(--accent); font-weight: 600; text-decoration: none; transition: color .2s; }
        .footer-links a:hover { color: var(--gold); }
        .back-home { display: inline-flex; align-items: center; gap: 7px; color: var(--text-muted); text-decoration: none; font-size: .83rem; transition: color .2s; margin-top: .2rem; }
        .back-home:hover { color: var(--text-primary); }

        /* ── Security note ── */
        .security-note {
            display: flex; align-items: center; gap: 7px;
            margin-top: 1.5rem; padding: .7rem 1rem;
            background: rgba(255,255,255,.03); border: 1px solid var(--border);
            border-radius: 9px; font-size: .74rem; color: var(--text-muted); line-height: 1.5;
        }

        @media (max-width: 500px) { .card { padding: 2rem 1.4rem; } }
    </style>
</head>
<body>

<div class="bg-canvas">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
    <div class="blob blob-4"></div>
</div>

<div class="card-wrap">
    <div class="card">

        <!-- Brand -->
        <div class="brand-row">
            <span class="brand-name">Gurkha Marga</span>
        </div><br><br>
        <!-- Heading -->
        <div class="form-heading">
            <h1>Welcome Back</h1>
        </div>

        <!-- Alerts -->
        <?php if ($error_message): ?>
        <div class="alert alert-error" role="alert">
            <span>⚠️</span>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
        <div class="alert alert-success" role="status">
            <span>✓</span>
            <span><?= htmlspecialchars($success_message) ?></span>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="login.php" id="loginForm" novalidate>

            <div class="form-group">
                <label class="form-label" for="email">
                    Email or Username <span class="required">*</span>
                </label>
                <input
                    type="text"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="Enter your email or username"
                    autocomplete="username"
                    required
                    value="<?= $prefill_email ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="password">
                   Password <span class="required">*</span>
                </label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input has-right-btn"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="btn-eye" id="togglePwBtn" onclick="togglePw()" aria-label="Toggle password">👁️</button>
                </div>
            </div>

            <div class="extras-row">
                <div class="check-group">
                    <label class="custom-check">
                        <input type="checkbox" name="remember" id="rememberMe">
                        <span class="checkmark"></span>
                    </label>
                    <label class="check-lbl" for="rememberMe">Remember me</label>
                </div>
                <a href="forgotpassword.php" class="forgot-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <span id="btnLabel">Sign In</span>
                <span id="btnArrow">→</span>
            </button>

        </form>

        <div class="divider">New here?</div>

        <div class="footer-links">
            <p>Don't have an account? <a href="register.php">Create one</a></p>
            <a href="../../frontend/landingpage/home.php" class="back-home">← Back to Homepage</a>
        </div>


    </div>
</div>

<script>
function togglePw() {
    const input = document.getElementById('password');
    const btn   = document.getElementById('togglePwBtn');
    const hidden = input.type === 'password';
    input.type      = hidden ? 'text' : 'password';
    btn.textContent = hidden ? '🙈' : '👁️';
}

document.getElementById('loginForm').addEventListener('submit', function(e) {
    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    if (!email || !password) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return;
    }
    const btn   = document.getElementById('submitBtn');
    const label = document.getElementById('btnLabel');
    const arrow = document.getElementById('btnArrow');
    btn.disabled    = true;
    label.innerHTML = '<div class="spinner"></div>';
    arrow.textContent = 'Signing in…';
});
</script>

</body>
</html>