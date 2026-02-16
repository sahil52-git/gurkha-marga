<?php
session_start();

    require_once '../../backend/database.php';
    require_once 'jwt.php';

// Redirect to dashboard if already logged in
$token = JWT::getTokenFromCookie();
if ($token && JWT::getUserFromToken($token)) {
    header('Location: ../users/dashboard.php');
    exit();
}

// Handle login form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        // Regular login
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        if (empty($email) || empty($password)) {
            $error_message = 'Please fill in all fields';
        } else {
            try {
                // Query the database
                $sql = "SELECT * FROM users WHERE email = ? AND is_active = 1";
                $stmt = query($sql, [$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // Password is correct
                    
                    // Create JWT token
                    $token = JWT::createUserToken($user, $remember);
                    
                    // Log successful login
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    
                    $logSql = "INSERT INTO login_logs (user_id, ip_address, user_agent, status) VALUES (?, ?, ?, 'success')";
                    query($logSql, [$user['id'], $ip_address, $user_agent]);
                    
                    // Update last login
                    $updateSql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                    query($updateSql, [$user['id']]);
                    
                    // Redirect to dashboard
                    header('Location: ../users/dashboard.php');
                    exit();
                } else {
                    // Log failed login attempt if user exists
                    if ($user) {
                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                        
                        $logSql = "INSERT INTO login_logs (user_id, ip_address, user_agent, status, failure_reason) VALUES (?, ?, ?, 'failed', 'Invalid password')";
                        query($logSql, [$user['id'], $ip_address, $user_agent]);
                    }
                    
                    $error_message = 'Invalid email or password';
                }
            } catch (Exception $e) {
                error_log('Login Error: ' . $e->getMessage());
                $error_message = 'An error occurred during login. Please try again.';
            }
        }
    } elseif (isset($_POST['verify_2fa'])) {
        // 2FA verification (implement if needed)
        $code = $_POST['code'];
        
        if (empty($code)) {
            $error_message = 'Please enter verification code';
        } else {
            // TODO: Implement 2FA verification
            $error_message = 'Invalid verification code';
        }
    }
}

// Check if we're on 2FA step
$show_2fa = isset($_GET['step']) && $_GET['step'] === '2fa';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gurkha Marga</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0f172a;
            --secondary: #1e293b;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --gold: #fbbf24;
            --success: #10b981;
            --error: #ef4444;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-hero: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gradient-hero);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Animated Background */
        .background-shapes {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 0;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.3;
            animation: float 20s infinite ease-in-out;
        }

        .shape-1 {
            width: 400px;
            height: 400px;
            background: var(--accent);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 300px;
            height: 300px;
            background: #8b5cf6;
            top: 50%;
            right: -150px;
            animation-delay: -5s;
        }

        .shape-3 {
            width: 500px;
            height: 500px;
            background: #ec4899;
            bottom: -250px;
            left: 30%;
            animation-delay: -10s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }

        /* Login Container */
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 450px;
        }

        .login-card {
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 3rem;
            box-shadow: var(--shadow-lg);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1rem;
        }

        .logo img {
            width: 50px;
            height: 50px;
            object-fit: contain;
        }

        .brand-name {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, #f59e0b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .form-label .required {
            color: var(--error);
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(15, 23, 42, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(15, 23, 42, 0.7);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-input::placeholder {
            color: var(--text-secondary);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 1.2rem;
            color: var(--text-secondary);
            user-select: none;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--text-primary);
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }

        .custom-checkbox {
            position: relative;
            display: inline-block;
            width: 20px;
            height: 20px;
        }

        .custom-checkbox input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background: rgba(15, 23, 42, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .custom-checkbox input:checked ~ .checkmark {
            background: var(--accent);
            border-color: var(--accent);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 5px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .custom-checkbox input:checked ~ .checkmark:after {
            display: block;
        }

        .checkbox-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            cursor: pointer;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* Links */
        .form-links {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .form-link {
            color: var(--accent);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .form-link:hover {
            color: var(--gold);
        }

        .text-center {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .text-center a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .text-center a:hover {
            color: var(--gold);
        }

        /* Back to Home Link */
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-link a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link a:hover {
            color: var(--text-primary);
        }

        /* Loading Spinner */
        .spinner {
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 2rem 1.5rem;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="background-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="login-card">
            <!-- Logo -->
            <div class="logo-container">
                <div class="logo">
                    <img src="../image/gurkhalogo.png" alt="Gurkha Marga Logo">
                    <span class="brand-name">Gurkha Marga</span>
                </div>
            </div>

            <!-- Regular Login Form -->
            <div class="login-header">
                <h1>Welcome Back!</h1>
                <p>Login to continue your training journey</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <span>✓</span>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="email">
                        Email Address <span class="required">*</span>
                    </label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input" 
                        placeholder="Enter your email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">
                        Password <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="Enter your password"
                            required
                        >
                        <span class="password-toggle" onclick="togglePassword()">👁️</span>
                    </div>
                </div>

                <div class="form-links">
                    <div class="checkbox-group">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="remember" id="remember">
                            <span class="checkmark"></span>
                        </label>
                        <label class="checkbox-label" for="remember">Remember me</label>
                    </div>
                    <a href="forgot-password.php" class="form-link">Forgot Password?</a>
                </div>

                <button type="submit" name="login" class="btn btn-primary">
                    <span>Login</span>
                    <span>→</span>
                </button>
            </form>

            <div class="text-center">
                Don't have an account? <a href="register.php">Sign up</a>
            </div>

            <div class="back-link">
                <a href="../landingpage/index.php">
                    <span>←</span>
                    <span>Back to Home</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Password toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggle = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggle.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                toggle.textContent = '👁️';
            }
        }

        // Form validation
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                const email = document.getElementById('email').value;
                const password = document.getElementById('password').value;
                
                if (!email || !password) {
                    e.preventDefault();
                    alert('Please fill in all required fields');
                    return false;
                }
                
                // Show loading state
                const submitBtn = loginForm.querySelector('button[type="submit"]');
                const originalContent = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<div class="spinner"></div><span>Logging in...</span>';
            });
        }
    </script>
</body>
</html>