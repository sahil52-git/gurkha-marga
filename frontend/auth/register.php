<?php
// frontend/auth/register.php

session_start();
ob_start();

require_once __DIR__ . '/../../backend/database.php';
require_once __DIR__ . '/../../backend/services/EmailService.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../users/dashboard.php');
    exit();
}

$error_message = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

if ($step === 2 && empty($_SESSION['registration_step1'])) {
    ob_end_clean();
    header('Location: register.php?step=1');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['step1'])) {
        $full_name        = trim($_POST['full_name']        ?? '');
        $email            = trim($_POST['email']            ?? '');
        $password         = $_POST['password']              ?? '';
        $confirm_password = $_POST['confirm_password']      ?? '';

        if (empty($full_name)) {
            $error_message = 'Full name is required.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'A valid email address is required.';
        } elseif (strlen($password) < 8) {
            $error_message = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm_password) {
            $error_message = 'Passwords do not match.';
        } else {
            try {
                $existing = query("SELECT id FROM users WHERE email = ? LIMIT 1", [$email])->fetch();
                if ($existing) {
                    $error_message = 'An account with that email already exists.';
                } else {
                    $_SESSION['registration_step1'] = [
                        'full_name' => htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'),
                        'email'     => $email,
                        'password'  => password_hash($password, PASSWORD_DEFAULT),
                        'timestamp' => time(),
                    ];
                    ob_end_clean();
                    header('Location: register.php?step=2');
                    exit();
                }
            } catch (Exception $e) {
                error_log('Register step1 error: ' . $e->getMessage());
                $error_message = 'A server error occurred. Please try again.';
            }
        }
    }

    elseif (isset($_POST['step2'])) {
        $age              = (int)  ($_POST['age']              ?? 0);
        $gender           = trim(   $_POST['gender']           ?? '');
        $height           = (float)($_POST['height']           ?? 0);
        $weight           = (float)($_POST['weight']           ?? 0);
        $target_force     = trim(   $_POST['target_force']     ?? '');
        $experience_level = trim(   $_POST['experience_level'] ?? '');

        $allowed_genders    = ['male', 'female', 'other'];
        $allowed_forces     = ['british', 'nepal', 'indian', 'singapore', 'french'];
        $allowed_experience = ['beginner', 'intermediate', 'advanced', 'expert'];

        if ($age < 13 || $age > 120) {
            $error_message = 'Age must be between 13 and 120.';
        } elseif (!in_array($gender, $allowed_genders, true)) {
            $error_message = 'Please select a valid gender.';
        } elseif ($height < 100 || $height > 250) {
            $error_message = 'Height must be between 100 and 250 cm.';
        } elseif ($weight < 30 || $weight > 300) {
            $error_message = 'Weight must be between 30 and 300 kg.';
        } elseif (!in_array($target_force, $allowed_forces, true)) {
            $error_message = 'Please select a valid target force.';
        } elseif (!in_array($experience_level, $allowed_experience, true)) {
            $error_message = 'Please select a valid experience level.';
        } elseif (empty($_SESSION['registration_step1'])) {
            $error_message = 'Your session expired. Please start from the beginning.';
            $step = 1;
        } else {
            $step1 = $_SESSION['registration_step1'];

            if (time() - $step1['timestamp'] > 600) {
                unset($_SESSION['registration_step1']);
                $error_message = 'Your session timed out. Please start over.';
                $step = 1;
            } else {
                try {
                    // ── 1. Insert user ────────────────────────────────────────────
                    query(
                        "INSERT INTO users
                            (full_name, email, password, age, gender, height, weight,
                             target_force, experience_level, is_active, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())",
                        [
                            $step1['full_name'],
                            $step1['email'],
                            $step1['password'],
                            $age, $gender, $height, $weight,
                            $target_force, $experience_level,
                        ]
                    );

                    // ── 2. Get the new user's ID ──────────────────────────────────
                    $newUser = query(
                        "SELECT id FROM users WHERE email = ? LIMIT 1",
                        [$step1['email']]
                    )->fetch();
                    $newUserId = $newUser ? (int)$newUser['id'] : null;

                    // ── 3. Create motivation record (email_daily = 1 by default) ─
                    if ($newUserId) {
                        query(
                            "INSERT INTO user_motivation
                                (user_id, streak_days, last_visit, email_daily, total_visits)
                             VALUES (?, 1, CURDATE(), 1, 1)",
                            [$newUserId]
                        );
                    }

                    // ── 4. Resolve force display name ─────────────────────────────
                    $forceNames = [
                        'british'   => 'British Army',
                        'nepal'     => 'Nepal Army',
                        'indian'    => 'Indian Army',
                        'singapore' => 'Singapore Police Force',
                        'french'    => 'French Foreign Legion',
                    ];
                    $forceName = $forceNames[$target_force] ?? 'British Army';

                    // ── 5. Send welcome email ─────────────────────────────────────
                    if (!Email::sendWelcomeEmail($step1['full_name'], $step1['email'])) {
                        error_log("Welcome email failed for " . $step1['email']);
                    }

                    // ── 6. Send Day 1 motivation email ────────────────────────────
                    if (!Email::sendSignupMotivationEmail($step1['full_name'], $step1['email'], $forceName)) {
                        error_log("Day 1 motivation email failed for " . $step1['email']);
                    }

                    unset($_SESSION['registration_step1']);
                    $_SESSION['registration_success'] = 'Account created! Please log in.';

                    ob_end_clean();
                    header('Location: login.php');
                    exit();

                } catch (Exception $e) {
                    error_log('Register step2 error: ' . $e->getMessage());
                    $error_message = 'A server error occurred while creating your account. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – Gurkha Marga</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --accent:         #3b82f6;
            --gold:           #fbbf24;
            --success:        #10b981;
            --error:          #ef4444;
            --text-primary:   #f8fafc;
            --text-secondary: #cbd5e1;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            width: 100%; max-width: 600px;
            background: rgba(30,41,59,.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 8px 32px rgba(0,0,0,.3);
        }

        .brand-wrap { text-align:center; margin-bottom:2rem; }
        .brand {
            font-size:2rem; font-weight:800;
            background: linear-gradient(135deg, var(--gold) 0%, #f59e0b 100%);
            background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }

        .progress {
            display:flex; justify-content:space-between;
            margin-bottom:2rem; position:relative;
        }
        .progress::before {
            content:''; position:absolute; top:20px; left:0; right:0;
            height:2px; background:rgba(255,255,255,.1);
        }
        .progress-fill {
            position:absolute; top:20px; left:0; height:2px;
            background:var(--accent);
            width:<?= $step === 2 ? '100%' : '0%' ?>;
            transition:width .5s;
        }
        .step {
            display:flex; flex-direction:column; align-items:center;
            gap:8px; flex:1; position:relative; z-index:2;
        }
        .step-circle {
            width:40px; height:40px; border-radius:50%;
            background:rgba(255,255,255,.1); border:2px solid rgba(255,255,255,.2);
            display:flex; align-items:center; justify-content:center; font-weight:600;
        }
        .step.active .step-circle   { background:var(--accent);  border-color:var(--accent); }
        .step.complete .step-circle { background:var(--success); border-color:var(--success); }
        .step-label { font-size:.9rem; color:var(--text-secondary); }

        .alert {
            padding:1rem; border-radius:12px; margin-bottom:1.5rem;
            background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#fca5a5;
            display:flex; gap:10px; align-items:center; font-size:.9rem;
        }

        .header { text-align:center; margin-bottom:2rem; }
        .header h1 { font-size:2rem; margin-bottom:.5rem; }
        .header p  { color:var(--text-secondary); }

        .form-group  { margin-bottom:1.5rem; }
        .form-label  { display:block; margin-bottom:.5rem; font-weight:500; }
        .required    { color:var(--error); }

        .form-input,
        .form-select {
            width:100%; padding:14px 16px;
            background:rgba(15,23,42,.5);
            border:2px solid rgba(255,255,255,.1);
            border-radius:12px;
            color:var(--text-primary);
            font-size:1rem; font-family:'Poppins',sans-serif;
            transition: border-color .3s;
        }
        .form-select option { background:#1e293b; }
        .form-input:focus,
        .form-select:focus { outline:none; border-color:var(--accent); }
        .form-input::placeholder { color:var(--text-secondary); }

        .form-row   { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .input-wrap { position:relative; }
        .input-unit {
            position:absolute; right:16px; top:50%; transform:translateY(-50%);
            color:var(--text-secondary); pointer-events:none;
        }
        .with-unit { padding-right:50px; }

        /* Force flags */
        .force-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: .75rem;
        }
        .force-option { display: none; }
        .force-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 1rem .75rem;
            background: rgba(15,23,42,.5);
            border: 2px solid rgba(255,255,255,.1);
            border-radius: 12px;
            cursor: pointer;
            transition: all .3s;
            text-align: center;
            font-size: .85rem;
            color: var(--text-secondary);
        }
        .force-flag { font-size: 1.8rem; line-height: 1; }
        .force-option:checked + .force-label {
            border-color: var(--accent);
            background: rgba(59,130,246,.15);
            color: var(--text-primary);
        }

        .btn {
            width:100%; padding:14px; border:none; border-radius:12px;
            font-size:1rem; font-weight:600; cursor:pointer;
            font-family:'Poppins',sans-serif; transition:transform .2s;
        }
        .btn-primary { background:linear-gradient(135deg,var(--accent) 0%,#8b5cf6 100%); color:#fff; }
        .btn-primary:hover { transform:translateY(-2px); }
        .btn-secondary {
            background:rgba(255,255,255,.05); color:var(--text-primary);
            border:2px solid rgba(255,255,255,.1);
        }
        .btn-group { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }

        .foot { text-align:center; margin-top:1.5rem; color:var(--text-secondary); font-size:.9rem; }
        .foot a { color:var(--accent); text-decoration:none; font-weight:600; }

        @media (max-width:640px) {
            .card { padding:2rem 1.5rem; }
            .form-row, .btn-group { grid-template-columns:1fr; }
            .force-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<div class="card">

    <div class="brand-wrap">
        <div class="brand">Gurkha Marga</div>
    </div>

    <div class="progress" aria-label="Registration progress">
        <div class="progress-fill"></div>
        <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'complete' : '' ?>">
            <div class="step-circle"><?= $step > 1 ? '✓' : '1' ?></div>
            <div class="step-label">Account</div>
        </div>
        <div class="step <?= $step >= 2 ? 'active' : '' ?>">
            <div class="step-circle">2</div>
            <div class="step-label">Profile</div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert" role="alert">
            <span>⚠️</span>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>

        <div class="header">
            <h1>Create Account</h1>
            <p>Start your Gurkha journey</p>
        </div>

        <form method="POST" action="register.php" novalidate>
            <div class="form-group">
                <label class="form-label" for="full_name">Full Name <span class="required">*</span></label>
                <input type="text" id="full_name" name="full_name" class="form-input"
                    placeholder="Enter your full name" autocomplete="name" required
                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email" class="form-input"
                    placeholder="Enter your email" autocomplete="email" required
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password <span class="required">*</span></label>
                <input type="password" id="password" name="password" class="form-input"
                    placeholder="Minimum 8 characters" autocomplete="new-password" required minlength="8">
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password <span class="required">*</span></label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                    placeholder="Re-enter your password" autocomplete="new-password" required>
            </div>

            <button type="submit" name="step1" class="btn btn-primary">Continue →</button>
        </form>

        <p class="foot">Already have an account? <a href="login.php">Login</a></p>

    <?php else: ?>

        <div class="header">
            <h1>Personal Information</h1>
            <p>Complete your soldier profile</p>
        </div>

        <form method="POST" action="register.php?step=2" novalidate>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="age">Age <span class="required">*</span></label>
                    <input type="number" id="age" name="age" class="form-input"
                        placeholder="Your age" min="13" max="120" required
                        value="<?= htmlspecialchars($_POST['age'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="gender">Gender <span class="required">*</span></label>
                    <select id="gender" name="gender" class="form-select" required>
                        <option value="">Select</option>
                        <option value="male"   <?= ($_POST['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other"  <?= ($_POST['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="height">Height <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="number" id="height" name="height" class="form-input with-unit"
                            placeholder="Height" step="0.1" min="100" max="250" required
                            value="<?= htmlspecialchars($_POST['height'] ?? '') ?>">
                        <span class="input-unit">cm</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="weight">Weight <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="number" id="weight" name="weight" class="form-input with-unit"
                            placeholder="Weight" step="0.1" min="30" max="300" required
                            value="<?= htmlspecialchars($_POST['weight'] ?? '') ?>">
                        <span class="input-unit">kg</span>
                    </div>
                </div>
            </div>

            <!-- Target Force - Visual card selector -->
            <div class="form-group">
                <label class="form-label">Target Force <span class="required">*</span></label>
                <div class="force-grid">
                    <?php
                    $forces = [
                        'british'   => ['🇬🇧', 'British Army'],
                        'nepal'     => ['🇳🇵', 'Nepal Army'],
                        'indian'    => ['🇮🇳', 'Indian Army'],
                        'singapore' => ['🇸🇬', 'Singapore Police'],
                        'french'    => ['🇫🇷', 'French Foreign Legion'],
                    ];
                    $selectedForce = $_POST['target_force'] ?? '';
                    foreach ($forces as $val => [$flag, $label]):
                    ?>
                    <input type="radio" class="force-option" id="force_<?= $val ?>"
                           name="target_force" value="<?= $val ?>"
                           <?= $selectedForce === $val ? 'checked' : '' ?> required>
                    <label class="force-label" for="force_<?= $val ?>">
                        <span class="force-flag"><?= $flag ?></span>
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="experience_level">Experience Level <span class="required">*</span></label>
                <select id="experience_level" name="experience_level" class="form-select" required>
                    <option value="">Select your level</option>
                    <option value="beginner"     <?= ($_POST['experience_level'] ?? '') === 'beginner'     ? 'selected' : '' ?>>Beginner</option>
                    <option value="intermediate" <?= ($_POST['experience_level'] ?? '') === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                    <option value="advanced"     <?= ($_POST['experience_level'] ?? '') === 'advanced'     ? 'selected' : '' ?>>Advanced</option>
                    <option value="expert"       <?= ($_POST['experience_level'] ?? '') === 'expert'       ? 'selected' : '' ?>>Expert</option>
                </select>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-secondary"
                        onclick="window.location.href='register.php?step=1'">← Back</button>
                <button type="submit" name="step2" class="btn btn-primary">Complete ✓</button>
            </div>
        </form>

    <?php endif; ?>

</div>
</body>
</html>