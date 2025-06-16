<?php
/**
 * EDUC AI TalkBot - First Time Setup
 */

session_start();

require_once __DIR__ . '/includes/auth.php';

// Redirect if setup is not required
if (!requiresSetup()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Handle setup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        
        // Validation
        if (empty($username)) {
            throw new Exception('Username is required');
        }
        
        if (strlen($username) < 3) {
            throw new Exception('Username must be at least 3 characters long');
        }
        
        if (empty($password)) {
            throw new Exception('Password is required');
        }
        
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long');
        }
        
        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        
        // Create admin user
        $db = \EDUC\Database\Database::getInstance();
        $userId = $db->createAdminUser($username, $password, $email, $fullName);
        
        \EDUC\Utils\Logger::info('First admin user created during setup', [
            'user_id' => $userId,
            'username' => $username
        ]);
        
        $success = 'Admin account created successfully! You can now log in.';
        
        // Auto-login the user
        $user = $db->getAdminUser($userId);
        if ($user) {
            setAdminSession($user);
            header('Location: index.php?setup=complete');
            exit;
        }
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Setup failed', ['error' => $e->getMessage()]);
        $error = $e->getMessage();
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - EDUC AI TalkBot Admin</title>
    
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
        
        .setup-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 3rem;
            width: 100%;
            max-width: 500px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .setup-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .setup-logo {
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
        
        .setup-logo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .setup-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .setup-subtitle {
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
        
        .input-group {
            position: relative;
        }
        
        .input-group-text {
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-right: none;
            color: #6b7280;
            padding: 0.875rem 1rem;
            border-radius: 10px 0 0 10px;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        
        .input-group:focus-within .input-group-text {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
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
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #dc2626;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #16a34a;
        }
        
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        
        .strength-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 0.25rem;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak .strength-fill {
            width: 25%;
            background: #ef4444;
        }
        
        .strength-fair .strength-fill {
            width: 50%;
            background: #f59e0b;
        }
        
        .strength-good .strength-fill {
            width: 75%;
            background: #10b981;
        }
        
        .strength-strong .strength-fill {
            width: 100%;
            background: #059669;
        }
        
        .feature-list {
            background: #f8fafc;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .feature-list h6 {
            color: #374151;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        .feature-item:last-child {
            margin-bottom: 0;
        }
        
        .feature-item i {
            color: #10b981;
            font-size: 1rem;
        }
        
        /* Dark mode support */
        [data-bs-theme="dark"] .setup-container {
            background: rgba(30, 41, 59, 0.95);
        }
        
        [data-bs-theme="dark"] .setup-title {
            color: #f1f5f9;
        }
        
        [data-bs-theme="dark"] .form-control {
            background: #374151;
            border-color: #4b5563;
            color: #f1f5f9;
        }
        
        [data-bs-theme="dark"] .feature-list {
            background: #374151;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <div class="setup-logo">
                <i class="bi bi-robot"></i>
            </div>
            <h1 class="setup-title">Welcome to EDUC AI TalkBot</h1>
            <p class="setup-subtitle">Let's set up your admin account to get started</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <form method="post" action="" id="setupForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <div class="form-group">
                <label for="username" class="form-label">
                    <i class="bi bi-person"></i> Username
                </label>
                <input type="text" class="form-control" id="username" name="username" 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                       required autocomplete="username" placeholder="Choose a username">
                <small class="text-muted">At least 3 characters, letters and numbers only</small>
            </div>
            
            <div class="form-group">
                <label for="full_name" class="form-label">
                    <i class="bi bi-card-text"></i> Full Name (Optional)
                </label>
                <input type="text" class="form-control" id="full_name" name="full_name" 
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" 
                       autocomplete="name" placeholder="Your full name">
            </div>
            
            <div class="form-group">
                <label for="email" class="form-label">
                    <i class="bi bi-envelope"></i> Email (Optional)
                </label>
                <input type="email" class="form-control" id="email" name="email" 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                       autocomplete="email" placeholder="admin@example.com">
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">
                    <i class="bi bi-lock"></i> Password
                </label>
                <input type="password" class="form-control" id="password" name="password" 
                       required autocomplete="new-password" placeholder="Create a strong password">
                <div class="password-strength" id="passwordStrength" style="display: none;">
                    <span id="strengthText">Password strength: </span>
                    <div class="strength-bar">
                        <div class="strength-fill"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password" class="form-label">
                    <i class="bi bi-lock-fill"></i> Confirm Password
                </label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                       required autocomplete="new-password" placeholder="Confirm your password">
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle"></i>
                Create Admin Account
            </button>
        </form>
        
        <div class="feature-list">
            <h6><i class="bi bi-star"></i> What you'll get:</h6>
            <div class="feature-item">
                <i class="bi bi-check"></i>
                <span>Full administrative access to the AI chatbot</span>
            </div>
            <div class="feature-item">
                <i class="bi bi-check"></i>
                <span>RAG document management and processing</span>
            </div>
            <div class="feature-item">
                <i class="bi bi-check"></i>
                <span>AI model configuration and monitoring</span>
            </div>
            <div class="feature-item">
                <i class="bi bi-check"></i>
                <span>System logs and performance analytics</span>
            </div>
            <div class="feature-item">
                <i class="bi bi-check"></i>
                <span>User management and security controls</span>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const strengthIndicator = document.getElementById('passwordStrength');
            const strengthText = document.getElementById('strengthText');
            const strengthBar = strengthIndicator.querySelector('.strength-bar');
            const form = document.getElementById('setupForm');
            
            // Password strength checker
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                
                if (password.length === 0) {
                    strengthIndicator.style.display = 'none';
                    return;
                }
                
                strengthIndicator.style.display = 'block';
                
                const strength = calculatePasswordStrength(password);
                const strengthLevels = ['weak', 'fair', 'good', 'strong'];
                const strengthTexts = ['Weak', 'Fair', 'Good', 'Strong'];
                
                // Remove all strength classes
                strengthLevels.forEach(level => {
                    strengthBar.classList.remove('strength-' + level);
                });
                
                // Add current strength class
                strengthBar.classList.add('strength-' + strengthLevels[strength]);
                strengthText.textContent = 'Password strength: ' + strengthTexts[strength];
            });
            
            // Password confirmation validation
            function validatePasswordMatch() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword && password !== confirmPassword) {
                    confirmPasswordInput.setCustomValidity('Passwords do not match');
                } else {
                    confirmPasswordInput.setCustomValidity('');
                }
            }
            
            passwordInput.addEventListener('input', validatePasswordMatch);
            confirmPasswordInput.addEventListener('input', validatePasswordMatch);
            
            // Form validation
            form.addEventListener('submit', function(e) {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters long');
                    return;
                }
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match');
                    return;
                }
                
                // Show loading state
                const submitButton = form.querySelector('button[type="submit"]');
                submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating Account...';
                submitButton.disabled = true;
            });
            
            function calculatePasswordStrength(password) {
                let score = 0;
                
                // Length
                if (password.length >= 8) score++;
                if (password.length >= 12) score++;
                
                // Character types
                if (/[a-z]/.test(password)) score++;
                if (/[A-Z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;
                
                // Return strength level (0-3)
                if (score <= 2) return 0; // weak
                if (score <= 4) return 1; // fair
                if (score <= 5) return 2; // good
                return 3; // strong
            }
        });
    </script>
</body>
</html> 