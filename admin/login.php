<?php
session_start();

require_once(__DIR__ . '/includes/env_loader.php');
loadAdminEnv(); // Use the function from env_loader.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_password = $_POST['password'] ?? '';
    
    // Get the expected password
    $admin_password = getenv('ADMIN_PASSWORD');
    if (empty($admin_password)) {
        $admin_password = getenv('BOT_TOKEN');
    }
    
    // Simple password check (consider using password_hash/verify for production)
    if ($submitted_password === $admin_password && !empty($admin_password)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        header('Location: index.php?error=' . urlencode('Invalid password.'));
        exit;
    }
} else {
    // Redirect if not POST
    header('Location: index.php');
    exit;
} 