<?php
if (!defined('ADMIN_AUTH_LOADED')) {
    require_once __DIR__ . '/auth.php';
}

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Admin Panel' ?> - EDUC AI TalkBot</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Chart.js for statistics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    
    <style>
        :root {
            --sidebar-width: 280px;
            --header-height: 60px;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafb;
            margin: 0;
            overflow-x: hidden;
        }
        
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        
        .admin-sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.1);
        }
        
        .sidebar-brand {
            color: white;
            font-size: 1.25rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .sidebar-brand:hover {
            color: #e2e8f0;
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin: 0.25rem 1rem;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        
        .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.1);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .nav-link i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background: #f8fafb;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        .top-header {
            background: white;
            padding: 1rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-left: auto;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #64748b;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .content-wrapper {
            padding: 2rem;
        }
        
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        .stats-card.success::before {
            background: var(--success-gradient);
        }
        
        .stats-card.warning::before {
            background: var(--warning-gradient);
        }
        
        .stats-card.secondary::before {
            background: var(--secondary-gradient);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stats-icon.primary {
            background: var(--primary-gradient);
        }
        
        .stats-icon.success {
            background: var(--success-gradient);
        }
        
        .stats-icon.warning {
            background: var(--warning-gradient);
        }
        
        .stats-icon.secondary {
            background: var(--secondary-gradient);
        }
        
        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .stats-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
            margin: 0;
        }
        
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.25rem;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .sidebar-toggle:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        .theme-toggle {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1.1rem;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .theme-toggle:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
            
            .content-wrapper {
                padding: 1rem;
            }
            
            .top-header {
                padding: 1rem;
            }
            
            .stats-card {
                margin-bottom: 1rem;
            }
        }
        
        /* Dark mode support */
        [data-bs-theme="dark"] {
            --bs-body-bg: #0f172a;
            --bs-body-color: #e2e8f0;
        }
        
        [data-bs-theme="dark"] .main-content {
            background: #0f172a;
        }
        
        [data-bs-theme="dark"] .top-header {
            background: #1e293b;
            border-color: #334155;
        }
        
        [data-bs-theme="dark"] .stats-card {
            background: #1e293b;
            border-color: #334155;
        }
        
        /* Custom animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease;
        }
        
        /* Loading states */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <i class="bi bi-robot"></i>
                <span>EDUC AI Admin</span>
            </a>
        </div>
        
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <i class="bi bi-house"></i>
                    <span>Dashboard</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="settings.php" class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">
                    <i class="bi bi-gear"></i>
                    <span>Settings</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="rag.php" class="nav-link <?= $currentPage === 'rag' ? 'active' : '' ?>">
                    <i class="bi bi-database"></i>
                    <span>RAG Management</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="models.php" class="nav-link <?= $currentPage === 'models' ? 'active' : '' ?>">
                    <i class="bi bi-cpu"></i>
                    <span>AI Models</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="logs.php" class="nav-link <?= $currentPage === 'logs' ? 'active' : '' ?>">
                    <i class="bi bi-journal-text"></i>
                    <span>Logs</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="system.php" class="nav-link <?= $currentPage === 'system' ? 'active' : '' ?>">
                    <i class="bi bi-info-circle"></i>
                    <span>System Info</span>
                </a>
            </div>
            
            <div class="nav-item">
                <a href="users.php" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i>
                    <span>Admin Users</span>
                </a>
            </div>
            
            <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem;">
            
            <div class="nav-item">
                <a href="logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Top Header -->
        <header class="top-header">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            
            <h1 class="page-title">
                <?php if (isset($pageIcon)): ?>
                    <i class="<?= $pageIcon ?>"></i>
                <?php endif; ?>
                <?= $pageTitle ?? 'Dashboard' ?>
            </h1>
            
            <div class="header-actions">
                <button class="theme-toggle" id="themeToggle" title="Toggle theme">
                    <i class="bi bi-moon"></i>
                </button>
                
                <div class="user-menu">
                    <div class="user-avatar">
                        <?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 0.875rem;">
                            <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Admin') ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #94a3b8;">
                            <?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Content Wrapper -->
        <div class="content-wrapper"> 