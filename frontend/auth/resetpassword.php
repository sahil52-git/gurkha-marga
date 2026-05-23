<?php
session_start();

// ── PHPMailer autoload ────────────────────────────────────────
$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die('PHPMailer not found. Run: composer require phpmailer/phpmailer');
}
require_once $vendorAutoload;

// ── Constants (guard against redefinition if included elsewhere) ──
if (!defined('DB_HOST'))  define('DB_HOST',  'localhost');
if (!defined('DB_NAME'))  define('DB_NAME',  'gurkhamarga');
if (!defined('DB_USER'))  define('DB_USER',  'root');
if (!defined('DB_PASS'))  define('DB_PASS',  '');
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/gurkha-marga');

// ── DB connection ─────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── Read token + email from URL ───────────────────────────────
$token     = trim($_GET['token'] ?? '');
$email     = trim($_GET['email'] ?? '');
$tokenOk   = false;
$alertMsg  = '';
$alertType = '';

if ($token && $email) {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT * FROM password_resets
             WHERE email = :email
               AND token = :token
               AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':email' => $email, ':token' => $token]);
        $row     = $stmt->fetch();
        $tokenOk = (bool) $row;
    } catch (\PDOException $e) {
        error_log('DB Error (resetpassword check): ' . $e->getMessage());
        $alertMsg  = 'A database error occurred: ' . htmlspecialchars($e->getMessage());
        $alertType = 'error';
    }
}

if (!$tokenOk && empty($alertMsg) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $alertMsg  = 'This password reset link is invalid or has expired. Please request a new one.';
    $alertType = 'error';
}

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $alertMsg  = 'Password must be at least 8 characters.';
        $alertType = 'error';
    } elseif ($password !== $password2) {
        $alertMsg  = 'Passwords do not match.';
        $alertType = 'error';
    } else {
        try {
            $db   = getDB();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Update user password
            $db->prepare("UPDATE users SET password = :pw WHERE email = :email")
               ->execute([':pw' => $hash, ':email' => $email]);

            // Delete used token
            $db->prepare("DELETE FROM password_resets WHERE email = :email")
               ->execute([':email' => $email]);

            $alertMsg  = 'Your password has been reset successfully. You can now log in.';
            $alertType = 'success';
            $tokenOk   = false; // hide the form
        } catch (\PDOException $e) {
            error_log('DB Error (resetpassword update): ' . $e->getMessage());
            $alertMsg  = 'A database error occurred: ' . htmlspecialchars($e->getMessage());
            $alertType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password – Gurkha Marga</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            background:linear-gradient(135deg,#1a1a2e 0%,#16213e 55%,#0f3460 100%);
            min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:20px;
        }

        .card {
            background:rgba(255,255,255,0.05);
            backdrop-filter:blur(20px);
            -webkit-backdrop-filter:blur(20px);
            border:1px solid rgba(255,255,255,0.1);
            border-radius:20px; padding:50px 40px;
            width:100%; max-width:450px;
            box-shadow:0 25px 50px rgba(0,0,0,0.5);
        }

        .brand { text-align:center; margin-bottom:6px; }
        .brand img { height:56px; object-fit:contain; }
        .brand-name {
            color:#e94560; font-size:20px; font-weight:700;
            letter-spacing:2px; text-align:center; margin-bottom:16px;
        }

        .icon-circle {
            width:72px; height:72px;
            background:rgba(233,69,96,0.12); border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 18px;
        }
        .icon-circle i { font-size:30px; color:#e94560; }

        h1 { color:#fff; font-size:22px; font-weight:600; text-align:center; margin-bottom:6px; }
        .subtitle {
            color:rgba(255,255,255,0.5); font-size:14px;
            text-align:center; line-height:1.6; margin-bottom:28px;
        }

        .alert {
            padding:14px 16px; border-radius:10px;
            margin-bottom:22px; font-size:14px; line-height:1.55;
            display:flex; gap:10px; align-items:flex-start;
        }
        .alert i { flex-shrink:0; margin-top:2px; }
        .alert-success { background:rgba(16,185,129,0.13); border:1px solid rgba(16,185,129,0.35); color:#6ee7b7; }
        .alert-error   { background:rgba(239,68,68,0.13);  border:1px solid rgba(239,68,68,0.35);  color:#fca5a5; }

        .form-group { margin-bottom:20px; }
        label {
            display:block; color:rgba(255,255,255,0.7);
            font-size:13px; font-weight:500; margin-bottom:8px;
        }

        .input-wrap { position:relative; }
        .input-wrap .fi {
            position:absolute; left:14px; top:50%;
            transform:translateY(-50%);
            color:rgba(255,255,255,0.3); font-size:14px; pointer-events:none;
        }

        input[type="password"] {
            width:100%; padding:13px 14px 13px 42px;
            background:rgba(255,255,255,0.07);
            border:1px solid rgba(255,255,255,0.14);
            border-radius:10px; color:#fff; font-size:15px; outline:none;
            transition:border-color .25s, box-shadow .25s, background .25s;
        }
        input[type="password"]::placeholder { color:rgba(255,255,255,0.28); }
        input[type="password"]:focus {
            border-color:#e94560;
            background:rgba(233,69,96,0.07);
            box-shadow:0 0 0 3px rgba(233,69,96,0.18);
        }

        .strength-bar {
            height:4px; border-radius:2px;
            background:rgba(255,255,255,0.1); margin-top:8px; overflow:hidden;
        }
        .strength-fill { height:100%; width:0; border-radius:2px; transition:width .3s, background .3s; }
        .strength-label { font-size:11px; color:rgba(255,255,255,0.4); margin-top:4px; }

        .btn {
            width:100%; padding:14px; border:none; border-radius:10px;
            font-size:15px; font-weight:600; cursor:pointer;
            display:flex; align-items:center; justify-content:center; gap:8px;
            transition:all .25s; text-decoration:none;
        }
        .btn-primary { background:linear-gradient(135deg,#e94560,#c73652); color:#fff; }
        .btn-primary:hover:not(:disabled) {
            background:linear-gradient(135deg,#d63a53,#b02e45);
            transform:translateY(-1px);
            box-shadow:0 8px 20px rgba(233,69,96,0.38);
        }
        .btn-ghost {
            background:rgba(255,255,255,0.07); color:rgba(255,255,255,0.75);
            border:1px solid rgba(255,255,255,0.12); margin-top:12px;
        }
        .btn-ghost:hover { background:rgba(255,255,255,0.12); color:#fff; }

        .text-center { text-align:center; }
        .link { color:#e94560; text-decoration:none; font-weight:500; transition:color .2s; }
        .link:hover { color:#ff6b81; }
        .muted { color:rgba(255,255,255,0.45); font-size:14px; margin-top:20px; }
    </style>
</head>
<body>
<div class="card">

    <div class="brand">
        <img src="../image/gurkhalogo.png" alt="Gurkha Marga" onerror="this.style.display='none'">
    </div>
    <div class="brand-name">GURKHA MARGA</div>

    <div class="icon-circle"><i class="fas fa-key"></i></div>
    <h1>Set New Password</h1>
    <p class="subtitle">Choose a strong password of at least 8 characters.</p>

    <?php if (!empty($alertMsg)): ?>
    <div class="alert alert-<?= $alertType ?>">
        <i class="fas fa-<?= $alertType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= $alertMsg ?></span>
    </div>
    <?php endif; ?>

    <?php if ($tokenOk): ?>
    <form method="POST"
          action="?token=<?= urlencode($token) ?>&email=<?= urlencode($email) ?>"
          id="rpForm" novalidate>

        <div class="form-group">
            <label for="password">New Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock fi"></i>
                <input type="password" id="password" name="password"
                       placeholder="Min. 8 characters" required autocomplete="new-password">
            </div>
            <div class="strength-bar"><div class="strength-fill" id="sBar"></div></div>
            <div class="strength-label" id="sLabel"></div>
        </div>

        <div class="form-group">
            <label for="password2">Confirm Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock fi"></i>
                <input type="password" id="password2" name="password2"
                       placeholder="Repeat password" required autocomplete="new-password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-shield-halved"></i> Reset Password
        </button>
    </form>

    <?php elseif ($alertType === 'success'): ?>
        <a href="login.php" class="btn btn-primary" style="margin-top:8px;">
            <i class="fas fa-right-to-bracket"></i> Go to Login
        </a>

    <?php else: ?>
        <a href="forgotpassword.php" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Request New Link
        </a>
    <?php endif; ?>

    <p class="text-center muted">
        <a href="login.php" class="link">Back to Login</a>
    </p>
</div>

<script>
document.getElementById('password')?.addEventListener('input', function () {
    const v   = this.value;
    const bar = document.getElementById('sBar');
    const lbl = document.getElementById('sLabel');
    let score = 0;
    if (v.length >= 8)           score++;
    if (/[A-Z]/.test(v))        score++;
    if (/[0-9]/.test(v))        score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const map = [
        ['0%',   '',        ''],
        ['25%',  '#ef4444', 'Weak'],
        ['50%',  '#f97316', 'Fair'],
        ['75%',  '#eab308', 'Good'],
        ['100%', '#22c55e', 'Strong'],
    ];
    bar.style.width      = map[score][0];
    bar.style.background = map[score][1];
    lbl.textContent      = map[score][2];
    lbl.style.color      = map[score][1];
});
</script>
</body>
</html>