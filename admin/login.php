<?php
/**
 * EDUC AI TalkBot - Enhanced Admin Login
 */

session_start();

require_once __DIR__ . '/includes/auth.php';

// Redirect to setup if no admin users exist
checkSetup();

// Check if already logged in
if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);
        
        if (empty($username) || empty($password)) {
            throw new Exception('Username and password are required');
        }
        
        // Authenticate user
        $db = \EDUC\Database\Database::getInstance();
        $user = $db->authenticateAdmin($username, $password);
        
        if (!$user) {
            throw new Exception('Invalid username or password');
        }
        
        // Set session
        setAdminSession($user);
        
        // Handle remember me
        if ($rememberMe) {
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true); // 30 days
            // In a production system, you'd store this token in the database
        }
        
        \EDUC\Utils\Logger::info('Admin login successful', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        // Redirect to originally requested page or dashboard
        $redirectTo = $_SESSION['login_redirect'] ?? 'index.php';
        unset($_SESSION['login_redirect']);
        
        header('Location: ' . $redirectTo);
        exit;
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::warning('Admin login failed', [
            'username' => $username ?? 'unknown',
            'error' => $e->getMessage(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        $error = $e->getMessage();
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check for setup completion message
if (isset($_GET['setup']) && $_GET['setup'] === 'complete') {
    $success = 'Setup completed successfully! Please log in to continue.';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EDUC AI TalkBot Admin</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 3rem;
            width: 100%;
            max-width: 420px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .login-logo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .login-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .login-subtitle {
            color: #64748b;
            font-size: 1rem;
            margin-bottom: 0;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-control {
            padding: 0.875rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15);
            transform: translateY(-1px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 1rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 10px;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
        }
        
        .btn-primary:disabled {
            transform: none;
            background: #9ca3af;
            box-shadow: none;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-check-input {
            margin: 0;
        }
        
        .form-check-label {
            color: #6b7280;
            font-size: 0.875rem;
            cursor: pointer;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #dc2626;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #16a34a;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .footer-text {
            color: #6b7280;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .theme-toggle {
            background: none;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .theme-toggle:hover {
            background: #f9fafb;
            border-color: #667eea;
            color: #667eea;
        }
        
        .security-info {
            background: #f8fafc;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
        }
        
        .security-info i {
            color: #10b981;
            margin-right: 0.5rem;
        }
        
        /* Loading state */
        .btn-loading {
            position: relative;
            color: transparent;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Dark mode support */
        [data-bs-theme="dark"] .login-container {
            background: rgba(30, 41, 59, 0.95);
        }
        
        [data-bs-theme="dark"] .login-title {
            color: #f1f5f9;
        }
        
        [data-bs-theme="dark"] .form-control {
            background: #374151;
            border-color: #4b5563;
            color: #f1f5f9;
        }
        
        [data-bs-theme="dark"] .security-info {
            background: #374151;
        }
        
        /* Mobile responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
                margin: 1rem;
            }
            
            .login-logo {
                width: 60px;
                height: 60px;
            }
            
            .login-logo i {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <i class="bi bi-robot"></i>
            </div>
            <h1 class="login-title">Admin Panel</h1>
            <p class="login-subtitle">EDUC AI TalkBot Administration</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>
        
        <form method="post" action="" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <div class="form-group">
                <label for="username" class="form-label">
                    <i class="bi bi-person"></i>
                    Username
                </label>
                <input type="text" class="form-control" id="username" name="username" 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                       required autocomplete="username" placeholder="Enter your username"
                       autofocus>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">
                    <i class="bi bi-lock"></i>
                    Password
                </label>
                <input type="password" class="form-control" id="password" name="password" 
                       required autocomplete="current-password" placeholder="Enter your password">
            </div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                <label class="form-check-label" for="remember_me">
                    Remember me for 30 days
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" id="loginBtn">
                <i class="bi bi-box-arrow-in-right"></i>
                Sign In
            </button>
        </form>
        
        <div class="security-info">
            <i class="bi bi-shield-check"></i>
            Secure encrypted connection with database authentication
        </div>
        
        <div class="login-footer">
            <p class="footer-text">Powered by EDUC AI TalkBot</p>
            <button class="theme-toggle" id="themeToggle">
                <i class="bi bi-moon"></i>
                <span id="themeText">Dark Mode</span>
            </button>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const themeToggle = document.getElementById('themeToggle');
            const themeText = document.getElementById('themeText');
            const themeIcon = themeToggle.querySelector('i');
            const htmlElement = document.documentElement;
            
            // Load saved theme
            const savedTheme = localStorage.getItem('admin-theme') || 'light';
            htmlElement.setAttribute('data-bs-theme', savedTheme);
            updateThemeButton(savedTheme);
            
            // Theme toggle
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'light' ? 'dark' : 'light';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('admin-theme', newTheme);
                updateThemeButton(newTheme);
            });
            
            function updateThemeButton(theme) {
                if (theme === 'light') {
                    themeIcon.className = 'bi bi-moon';
                    themeText.textContent = 'Dark Mode';
                } else {
                    themeIcon.className = 'bi bi-sun';
                    themeText.textContent = 'Light Mode';
                }
            }
            
            // Form submission
            loginForm.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value;
                
                if (!username || !password) {
                    e.preventDefault();
                    showAlert('Please enter both username and password', 'danger');
                    return;
                }
                
                // Show loading state
                loginBtn.classList.add('btn-loading');
                loginBtn.disabled = true;
                
                // Let the form submit naturally
            });
            
            // Auto-focus on username if empty, password if username has value
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            
            if (usernameInput.value.trim()) {
                passwordInput.focus();
            }
            
            // Enter key handling
            document.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
                    loginForm.submit();
                }
            });
            
            function showAlert(message, type) {
                const alertClass = type === 'danger' ? 'alert-danger' : 'alert-success';
                const icon = type === 'danger' ? 'bi-exclamation-triangle' : 'bi-check-circle';
                
                const alertHtml = `
                    <div class="alert ${alertClass}">
                        <i class="bi ${icon}"></i>
                        <span>${message}</span>
                    </div>
                `;
                
                const existingAlert = document.querySelector('.alert');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                loginForm.insertAdjacentHTML('beforebegin', alertHtml);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    const alert = document.querySelector('.alert');
                    if (alert) alert.remove();
                }, 5000);
            }
            
            // Password visibility toggle (optional enhancement)
            const passwordToggle = document.createElement('button');
            passwordToggle.type = 'button';
            passwordToggle.innerHTML = '<i class="bi bi-eye"></i>';
            passwordToggle.style.cssText = `
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: #6b7280;
                cursor: pointer;
                z-index: 10;
            `;
            
            const passwordGroup = passwordInput.parentElement;
            passwordGroup.style.position = 'relative';
            passwordGroup.appendChild(passwordToggle);
            
            passwordToggle.addEventListener('click', function() {
                const isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                this.innerHTML = isPassword ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            });
        });
    </script>
</body>
</html> 