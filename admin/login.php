<?php
/**
 * EDUC AI TalkBot Enhanced - Admin Login
 */

session_start();

require_once '../vendor/autoload.php';
require_once '../auto-include.php';

use EDUC\Core\Environment;
use EDUC\Utils\Logger;
use EDUC\Utils\Security;

Environment::load();
Logger::initialize();
Security::initializeErrorHandlers();

$error = '';

// Check if already logged in
if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
    header('Location: index.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Simple authentication - in production, use proper user management
    $adminUsername = Environment::get('ADMIN_USERNAME', 'admin');
    $adminPassword = Environment::get('ADMIN_PASSWORD', 'admin123');
    
    if ($username === $adminUsername && $password === $adminPassword) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['login_time'] = time();
        
        Logger::info('Admin login successful', ['username' => $username]);
        
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
        Logger::warning('Admin login failed', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
}

$csrfToken = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDUC AI TalkBot - Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header i {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-robot"></i>
            <h2 class="mb-0">EDUC AI TalkBot</h2>
            <p class="text-muted mb-0">Admin Panel</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="mb-3">
                <label for="username" class="form-label">
                    <i class="bi bi-person"></i> Username
                </label>
                <input type="text" class="form-control" id="username" name="username" required 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username">
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">
                    <i class="bi bi-lock"></i> Password
                </label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
            </div>
            
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </div>
        </form>
        
        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="bi bi-shield-check"></i>
                Secure Admin Access
            </small>
        </div>
        
        <div class="text-center mt-3">
            <small class="text-muted">
                Default credentials: admin / admin123<br>
                <strong>Change these in production!</strong>
            </small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 