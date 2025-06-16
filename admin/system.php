<?php
/**
 * EDUC AI TalkBot - System Information Page
 */

session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

checkSetup();
requireAuth();

$pageTitle = 'System Information';
$pageIcon = 'bi bi-info-circle';

// Helper function for formatting bytes
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Get system information
function getSystemInfo() {
    $info = [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'session_save_path' => session_save_path(),
        'timezone' => date_default_timezone_get(),
        'current_time' => date('Y-m-d H:i:s T')
    ];
    
    // Get disk usage
    $info['disk_free'] = disk_free_space(__DIR__);
    $info['disk_total'] = disk_total_space(__DIR__);
    $info['disk_used'] = $info['disk_total'] - $info['disk_free'];
    
    return $info;
}

// Get database info
function getDatabaseInfo() {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $connection = $db->getConnection();
        
        return [
            'status' => 'Connected',
            'version' => $connection->query('SELECT version()')->fetchColumn(),
            'database' => $_ENV['CLOUDRON_POSTGRESQL_DATABASE'] ?? 'educ_ai_talkbot',
            'host' => $_ENV['CLOUDRON_POSTGRESQL_HOST'] ?? 'localhost',
            'port' => $_ENV['CLOUDRON_POSTGRESQL_PORT'] ?? '5432'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'Error: ' . $e->getMessage(),
            'version' => 'Unknown',
            'database' => 'Unknown',
            'host' => 'Unknown',
            'port' => 'Unknown'
        ];
    }
}

// Get application info
function getApplicationInfo() {
    $composerFile = __DIR__ . '/../composer.json';
    $version = 'Unknown';
    
    if (file_exists($composerFile)) {
        $composer = json_decode(file_get_contents($composerFile), true);
        $version = $composer['version'] ?? '2.0.0';
    }
    
    return [
        'name' => 'EDUC AI TalkBot Enhanced',
        'version' => $version,
        'environment' => $_ENV['CLOUDRON_APP_DOMAIN'] ? 'Cloudron' : 'Development',
        'debug_mode' => \EDUC\Core\Environment::get('DEBUG_MODE', false) ? 'Enabled' : 'Disabled',
        'log_level' => \EDUC\Core\Environment::get('LOG_LEVEL', 'INFO'),
        'timezone' => \EDUC\Core\Environment::get('TIMEZONE', 'Europe/Berlin')
    ];
}

// Get PHP extensions
function getPHPExtensions() {
    $required = ['pdo', 'pdo_pgsql', 'curl', 'json', 'mbstring', 'openssl'];
    $extensions = [];
    
    foreach ($required as $ext) {
        $extensions[$ext] = extension_loaded($ext);
    }
    
    return $extensions;
}

$systemInfo = getSystemInfo();
$dbInfo = getDatabaseInfo();
$appInfo = getApplicationInfo();
$phpExtensions = getPHPExtensions();

include __DIR__ . '/includes/header.php';
?>

<!-- System Overview Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon primary">
                <i class="bi bi-server"></i>
            </div>
            <h3 class="stats-value"><?= $appInfo['version'] ?></h3>
            <p class="stats-label">Application Version</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon success">
                <i class="bi bi-database"></i>
            </div>
            <h3 class="stats-value"><?= $dbInfo['status'] === 'Connected' ? 'OK' : 'ERROR' ?></h3>
            <p class="stats-label">Database Status</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon warning">
                <i class="bi bi-memory"></i>
            </div>
            <h3 class="stats-value"><?= round($systemInfo['disk_used'] / 1024 / 1024 / 1024, 1) ?>GB</h3>
            <p class="stats-label">Disk Used</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card secondary">
            <div class="stats-icon secondary">
                <i class="bi bi-clock"></i>
            </div>
            <h3 class="stats-value"><?= date('H:i') ?></h3>
            <p class="stats-label">Server Time</p>
        </div>
    </div>
</div>

<!-- Application Information -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-robot"></i> Application Information
            </h5>
            <table class="table table-sm">
                <tr><td><strong>Application Name:</strong></td><td><?= htmlspecialchars($appInfo['name']) ?></td></tr>
                <tr><td><strong>Version:</strong></td><td><?= htmlspecialchars($appInfo['version']) ?></td></tr>
                <tr><td><strong>Environment:</strong></td><td><?= htmlspecialchars($appInfo['environment']) ?></td></tr>
                <tr><td><strong>Debug Mode:</strong></td><td><?= htmlspecialchars($appInfo['debug_mode']) ?></td></tr>
                <tr><td><strong>Log Level:</strong></td><td><?= htmlspecialchars($appInfo['log_level']) ?></td></tr>
                <tr><td><strong>Timezone:</strong></td><td><?= htmlspecialchars($appInfo['timezone']) ?></td></tr>
            </table>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-database"></i> Database Information
            </h5>
            <table class="table table-sm">
                <tr><td><strong>Status:</strong></td><td>
                    <span class="badge bg-<?= $dbInfo['status'] === 'Connected' ? 'success' : 'danger' ?>">
                        <?= htmlspecialchars($dbInfo['status']) ?>
                    </span>
                </td></tr>
                <tr><td><strong>Version:</strong></td><td><?= htmlspecialchars($dbInfo['version']) ?></td></tr>
                <tr><td><strong>Database:</strong></td><td><?= htmlspecialchars($dbInfo['database']) ?></td></tr>
                <tr><td><strong>Host:</strong></td><td><?= htmlspecialchars($dbInfo['host']) ?></td></tr>
                <tr><td><strong>Port:</strong></td><td><?= htmlspecialchars($dbInfo['port']) ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- Server Information -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-server"></i> Server Information
            </h5>
            <table class="table table-sm">
                <tr><td><strong>PHP Version:</strong></td><td><?= htmlspecialchars($systemInfo['php_version']) ?></td></tr>
                <tr><td><strong>Server Software:</strong></td><td><?= htmlspecialchars($systemInfo['server_software']) ?></td></tr>
                <tr><td><strong>Operating System:</strong></td><td><?= htmlspecialchars($systemInfo['os']) ?></td></tr>
                <tr><td><strong>Memory Limit:</strong></td><td><?= htmlspecialchars($systemInfo['memory_limit']) ?></td></tr>
                <tr><td><strong>Max Execution Time:</strong></td><td><?= htmlspecialchars($systemInfo['max_execution_time']) ?>s</td></tr>
                <tr><td><strong>Upload Max Size:</strong></td><td><?= htmlspecialchars($systemInfo['upload_max_filesize']) ?></td></tr>
            </table>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-hdd"></i> Storage Information
            </h5>
            <table class="table table-sm">
                <tr><td><strong>Total Space:</strong></td><td><?= formatBytes($systemInfo['disk_total']) ?></td></tr>
                <tr><td><strong>Used Space:</strong></td><td><?= formatBytes($systemInfo['disk_used']) ?></td></tr>
                <tr><td><strong>Free Space:</strong></td><td><?= formatBytes($systemInfo['disk_free']) ?></td></tr>
                <tr><td><strong>Usage:</strong></td><td>
                    <?php $usage = ($systemInfo['disk_used'] / $systemInfo['disk_total']) * 100; ?>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar bg-<?= $usage > 80 ? 'danger' : ($usage > 60 ? 'warning' : 'success') ?>" 
                             style="width: <?= $usage ?>%"><?= round($usage, 1) ?>%</div>
                    </div>
                </td></tr>
            </table>
        </div>
    </div>
</div>

<!-- PHP Extensions -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-puzzle"></i> PHP Extensions Status
            </h5>
            <div class="row">
                <?php foreach ($phpExtensions as $ext => $loaded): ?>
                    <div class="col-md-4 mb-2">
                        <span class="badge bg-<?= $loaded ? 'success' : 'danger' ?> me-2">
                            <i class="bi bi-<?= $loaded ? 'check-circle' : 'x-circle' ?>"></i>
                        </span>
                        <?= htmlspecialchars($ext) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?> 