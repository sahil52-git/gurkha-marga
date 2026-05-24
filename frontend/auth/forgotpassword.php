<?php
// frontend/auth/forgotpassword.php
session_start();

require_once __DIR__ . '/../../backend/database.php';
require_once __DIR__ . '/../../backend/config/EmailConfig.php';

if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/gurkha-marga');

$alertMsg  = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alertMsg  = 'Please enter a valid email address.';
        $alertType = 'error';
    } else {
        try {
            $user = fetchOne(
                "SELECT id, full_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1",
                [$email]
            );

            if ($user) {
                $token  = bin2hex(random_bytes(32));
                $expiry = null;

                execute("DELETE FROM password_resets WHERE email = ?", [$email]);
               execute(
    "INSERT INTO password_resets (email, token, expires_at, created_at) 
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())",
    [$email, $token]
);

                $resetLink = SITE_URL . '/frontend/auth/resetpassword.php?token=' . urlencode($token) . '&email=' . urlencode($email);
                $name    = $user['full_name'];
                $subject = 'Password Reset Request – Gurkha Marga';
                $html    = buildResetHtml($name, $resetLink);
                $plain   = "Hi {$name},\n\nReset your password (valid 5 minutes only):\n{$resetLink}\n\nGurkha Marga";
                $sent    = sendMail($email, $subject, $html, $plain);

                if ($sent) {
                    $alertMsg  = 'A reset link has been sent to <strong>' . htmlspecialchars($email) . '</strong>. It expires in <strong>5 minutes</strong> — check your inbox now.';
                    $alertType = 'success';
                } else {
                    $alertMsg  = 'Email could not be sent. Check the PHP error log.';
                    $alertType = 'error';
                }
            } else {
                $alertMsg  = 'If an account with that email exists, a reset link has been sent.';
                $alertType = 'success';
            }
        } catch (\Exception $e) {
            error_log('forgotpassword.php error: ' . $e->getMessage());
            $alertMsg  = 'A server error occurred. Please try again.';
            $alertType = 'error';
        }
    }
}

function buildResetHtml(string $name, string $link): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $year     = date('Y');
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 10px;">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
<tr><td style="background:linear-gradient(135deg,#1a1a2e,#0f3460);padding:36px 40px;text-align:center;">
  <h1 style="color:#e94560;margin:0;font-size:26px;letter-spacing:2px;">GURKHA MARGA</h1>
  <p style="color:rgba(255,255,255,.6);margin:6px 0 0;font-size:13px;">Your Path Forward</p>
</td></tr>
<tr><td style="padding:40px;">
  <div style="text-align:center;margin-bottom:20px;"><div style="font-size:52px;">🔐</div>
    <h2 style="color:#1a1a2e;margin:12px 0 0;font-size:22px;">Password Reset Request</h2></div>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;text-align:center;margin-bottom:24px;">
    <p style="color:#856404;font-size:14px;margin:0;font-weight:600;">⏱️ This link expires in <strong>5 minutes</strong> — click it now.</p>
  </div>
  <p style="color:#4a5568;font-size:15px;line-height:1.7;">Hi <strong>{$safeName}</strong>,</p>
  <p style="color:#4a5568;font-size:15px;line-height:1.7;margin-bottom:28px;">Reset your Gurkha Marga password by clicking the button below. Valid for <strong>5 minutes only</strong>.</p>
  <div style="text-align:center;margin:32px 0;">
    <a href="{$safeLink}" style="display:inline-block;background:linear-gradient(135deg,#e94560,#c73652);color:#fff;text-decoration:none;padding:16px 36px;border-radius:10px;font-size:16px;font-weight:600;">Reset My Password →</a>
  </div>
  <p style="color:#718096;font-size:13px;">Or paste in your browser:</p>
  <p style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:12px;color:#4a5568;word-break:break-all;">{$safeLink}</p>
  <div style="background:#fff8f0;border-left:4px solid #e94560;border-radius:4px;padding:14px;margin-top:20px;">
    <p style="color:#744210;font-size:13px;margin:0;">⚠️ If you didn't request this, ignore this email.</p>
  </div>
</td></tr>
<tr><td style="background:#f7fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
  <p style="color:#a0aec0;font-size:12px;margin:0;">© {$year} Gurkha Marga · Do not reply</p>
</td></tr>
</table></td></tr></table></body></html>
HTML;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password – Gurkha Marga</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 55%,#0f3460 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .card{background:rgba(255,255,255,0.05);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:50px 40px;width:100%;max-width:450px;box-shadow:0 25px 50px rgba(0,0,0,0.5);}
        .brand{text-align:center;margin-bottom:6px;}.brand img{height:56px;object-fit:contain;}
        .brand-name{color:#e94560;font-size:20px;font-weight:700;letter-spacing:2px;text-align:center;margin-bottom:16px;}
        .icon-circle{width:72px;height:72px;background:rgba(233,69,96,0.12);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;}
        .icon-circle i{font-size:30px;color:#e94560;}
        h1{color:#fff;font-size:22px;font-weight:600;text-align:center;margin-bottom:6px;}
        .subtitle{color:rgba(255,255,255,0.5);font-size:14px;text-align:center;line-height:1.6;margin-bottom:16px;}
        .expiry-note{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.25);border-radius:8px;padding:8px 14px;margin-bottom:20px;font-size:13px;color:#fbbf24;font-weight:500;}
        .alert{padding:14px 16px;border-radius:10px;margin-bottom:22px;font-size:14px;line-height:1.55;display:flex;gap:10px;align-items:flex-start;}
        .alert i{flex-shrink:0;margin-top:2px;}
        .alert-success{background:rgba(16,185,129,0.13);border:1px solid rgba(16,185,129,0.35);color:#6ee7b7;}
        .alert-error{background:rgba(239,68,68,0.13);border:1px solid rgba(239,68,68,0.35);color:#fca5a5;}
        .form-group{margin-bottom:20px;}
        label{display:block;color:rgba(255,255,255,0.7);font-size:13px;font-weight:500;margin-bottom:8px;}
        .input-wrap{position:relative;}
        .input-wrap .fi{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:14px;pointer-events:none;}
        input[type="email"]{width:100%;padding:13px 14px 13px 42px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.14);border-radius:10px;color:#fff;font-size:15px;outline:none;transition:border-color .25s,box-shadow .25s;}
        input[type="email"]::placeholder{color:rgba(255,255,255,0.28);}
        input[type="email"]:focus{border-color:#e94560;background:rgba(233,69,96,0.07);box-shadow:0 0 0 3px rgba(233,69,96,0.18);}
        .btn{width:100%;padding:14px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .25s;text-decoration:none;font-family:inherit;}
        .btn-primary{background:linear-gradient(135deg,#e94560,#c73652);color:#fff;}
        .btn-primary:hover:not(:disabled){background:linear-gradient(135deg,#d63a53,#b02e45);transform:translateY(-1px);box-shadow:0 8px 20px rgba(233,69,96,0.38);}
        .btn-primary:disabled{opacity:.55;cursor:not-allowed;}
        .btn-ghost{background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.75);border:1px solid rgba(255,255,255,0.12);margin-top:12px;}
        .btn-ghost:hover{background:rgba(255,255,255,0.12);color:#fff;}
        .spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite;}
        @keyframes spin{to{transform:rotate(360deg);}}
        .divider{position:relative;text-align:center;margin:24px 0;}
        .divider::before{content:'';position:absolute;left:0;top:50%;width:100%;height:1px;background:rgba(255,255,255,0.09);}
        .divider span{position:relative;padding:0 12px;color:rgba(255,255,255,0.35);font-size:12px;}
        .text-center{text-align:center;}.link{color:#e94560;text-decoration:none;font-weight:500;}.link:hover{color:#ff6b81;}
        .muted{color:rgba(255,255,255,0.45);font-size:14px;}
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <img src="../image/gurkhalogo.png" alt="Gurkha Marga" onerror="this.style.display='none'">
    </div>
    <div class="brand-name">GURKHA MARGA</div>
    <div class="icon-circle"><i class="fas fa-lock-open"></i></div>
    <h1>Forgot Password?</h1>
    <p class="subtitle">Enter your registered email and we'll send you a secure reset link.</p>
    <div class="expiry-note"><i class="fas fa-clock"></i> Reset link expires in <strong>&nbsp;5 minutes</strong></div>

    <?php if (!empty($alertMsg)): ?>
    <div class="alert alert-<?= $alertType ?>">
        <i class="fas fa-<?= $alertType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= $alertMsg ?></span>
    </div>
    <?php endif; ?>

    <?php if ($alertType !== 'success'): ?>
    <form method="POST" action="" id="fpForm" novalidate>
        <div class="form-group">
            <label for="email">Email Address</label>
            <div class="input-wrap">
                <i class="fas fa-envelope fi"></i>
                <input type="email" id="email" name="email" placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">
            </div>
        </div>
        <button type="submit" class="btn btn-primary" id="submitBtn">
            <span class="spinner" id="spin"></span>
            <i class="fas fa-paper-plane" id="sendIco"></i>
            <span id="btnTxt">Send Reset Link</span>
        </button>
    </form>
    <?php else: ?>
        <a href="login.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Login</a>
    <?php endif; ?>

    <div class="divider"><span>or</span></div>
    <p class="text-center muted">Remember your password? <a href="login.php" class="link">Log in</a></p>
    <p class="text-center muted" style="margin-top:10px;">New here? <a href="register.php" class="link">Create account</a></p>
</div>
<script>
document.getElementById('fpForm')?.addEventListener('submit', function(e) {
    const email = document.getElementById('email').value.trim();
    if (!email) { e.preventDefault(); return; }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('spin').style.display = 'block';
    document.getElementById('sendIco').style.display = 'none';
    document.getElementById('btnTxt').textContent = 'Sending…';
});
</script>
</body>
</html>