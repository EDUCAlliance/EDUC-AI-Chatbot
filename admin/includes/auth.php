<?php
/**
 * Admin Authentication Helper
 */

if (!defined('ADMIN_AUTH_LOADED')) {
    define('ADMIN_AUTH_LOADED', true);
    
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    require_once dirname(__DIR__, 2) . '/educ-bootstrap.php';
    
    // Initialize components
    \EDUC\Core\Environment::load();
    \EDUC\Utils\Logger::initialize();
    \EDUC\Utils\Security::initializeErrorHandlers();
    
    /**
     * Check if admin is authenticated
     */
    function isAuthenticated(): bool {
        return isset($_SESSION['admin_authenticated']) && 
               $_SESSION['admin_authenticated'] === true &&
               isset($_SESSION['admin_user']);
    }
    
    /**
     * Require authentication - redirect to login if not authenticated
     */
    function requireAuth(): void {
        if (!isAuthenticated()) {
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * Get current admin user
     */
    function getCurrentUser(): ?array {
        return $_SESSION['admin_user'] ?? null;
    }
    
    /**
     * Set admin session
     */
    function setAdminSession(array $user): void {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_time'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
    }
    
    /**
     * Clear admin session
     */
    function clearAdminSession(): void {
        $_SESSION['admin_authenticated'] = false;
        unset($_SESSION['admin_user']);
        unset($_SESSION['login_time']);
        session_destroy();
    }
    
    /**
     * Check if setup is required
     */
    function requiresSetup(): bool {
        try {
            $db = \EDUC\Database\Database::getInstance();
            return !$db->hasAdminUsers();
        } catch (Exception $e) {
            \EDUC\Utils\Logger::error('Failed to check admin users', ['error' => $e->getMessage()]);
            return true;
        }
    }
    
    /**
     * Redirect to setup if required
     */
    function checkSetup(): void {
        if (requiresSetup() && basename($_SERVER['PHP_SELF']) !== 'setup.php') {
            header('Location: setup.php');
            exit;
        }
    }
} 