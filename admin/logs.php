<?php
/**
 * EDUC AI TalkBot - Logs Page
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication and setup
checkSetup();
requireAuth();

// Initialize components
$db = \EDUC\Database\Database::getInstance();

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !\EDUC\Utils\Security::validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'clear_logs':
                $logType = $_POST['log_type'] ?? 'all';
                clearLogs($logType);
                $message = 'Logs cleared successfully!';
                $messageType = 'success';
                break;
                
            case 'download_logs':
                $logType = $_POST['log_type'] ?? 'application';
                downloadLogs($logType);
                exit;
                
            default:
                throw new Exception('Unknown action');
        }
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Logs page error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

/**
 * Get application logs
 */
function getApplicationLogs($limit = 100) {
    $logFile = __DIR__ . '/../logs/app.log';
    if (!file_exists($logFile)) return [];
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    
    $lines = array_slice($lines, -$limit);
    $logs = [];
    
    foreach (array_reverse($lines) as $line) {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+): (.+)$/', $line, $matches)) {
            $logs[] = [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => $matches[3]
            ];
        }
    }
    return $logs;
}

/**
 * Get system logs (if accessible)
 */
function getSystemLogs(int $limit = 50): array {
    try {
        $logs = [];
        
        // Try to get Apache/Nginx error logs (if accessible)
        $possibleLogFiles = [
            '/var/log/nginx/error.log',
            '/var/log/apache2/error.log',
            '/usr/local/var/log/nginx/error.log',
            '/opt/homebrew/var/log/nginx/error.log'
        ];
        
        foreach ($possibleLogFiles as $logFile) {
            if (file_exists($logFile) && is_readable($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) {
                    $lines = array_slice($lines, -$limit);
                    foreach (array_reverse($lines) as $line) {
                        $logs[] = [
                            'timestamp' => '',
                            'level' => 'SYSTEM',
                            'message' => $line,
                            'source' => basename($logFile)
                        ];
                    }
                }
                break; // Only read first accessible log file
            }
        }
        
        return $logs;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get PHP error logs
 */
function getPHPErrorLogs(int $limit = 50): array {
    try {
        $errorLog = ini_get('error_log');
        if (!$errorLog || !file_exists($errorLog) || !is_readable($errorLog)) {
            return [];
        }
        
        $lines = file($errorLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }
        
        $lines = array_slice($lines, -$limit);
        $logs = [];
        
        foreach (array_reverse($lines) as $line) {
            $logs[] = [
                'timestamp' => '',
                'level' => 'PHP_ERROR',
                'message' => $line,
                'source' => 'PHP'
            ];
        }
        
        return $logs;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Clear logs
 */
function clearLogs(string $logType): void {
    switch ($logType) {
        case 'application':
            $logFile = __DIR__ . '/../logs/app.log';
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
            }
            break;
            
        case 'all':
            $logDir = __DIR__ . '/../logs';
            if (is_dir($logDir)) {
                $files = glob($logDir . '/*.log');
                foreach ($files as $file) {
                    file_put_contents($file, '');
                }
            }
            break;
    }
}

/**
 * Download logs
 */
function downloadLogs(string $logType): void {
    $logs = [];
    $filename = 'logs_' . date('Y-m-d_H-i-s') . '.txt';
    
    switch ($logType) {
        case 'application':
            $logs = getApplicationLogs(1000);
            $filename = 'application_' . $filename;
            break;
            
        case 'system':
            $logs = getSystemLogs(1000);
            $filename = 'system_' . $filename;
            break;
            
        case 'php':
            $logs = getPHPErrorLogs(1000);
            $filename = 'php_' . $filename;
            break;
    }
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    foreach ($logs as $log) {
        echo ($log['raw'] ?? $log['message']) . "\n";
    }
}

// Get logs data
$applicationLogs = getApplicationLogs();
$systemLogs = getSystemLogs();
$phpLogs = getPHPErrorLogs();

// Get log statistics
$logStats = [
    'total' => count($applicationLogs),
    'errors' => count(array_filter($applicationLogs, fn($log) => $log['level'] === 'ERROR')),
    'warnings' => count(array_filter($applicationLogs, fn($log) => $log['level'] === 'WARNING')),
    'info' => count(array_filter($applicationLogs, fn($log) => $log['level'] === 'INFO'))
];

// Count log levels
$levelCounts = [
    'ERROR' => 0,
    'WARNING' => 0,
    'INFO' => 0,
    'DEBUG' => 0
];

foreach ($applicationLogs as $log) {
    $level = strtoupper($log['level']);
    if (isset($levelCounts[$level])) {
        $levelCounts[$level]++;
    }
}

$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

// Page configuration
$pageTitle = 'System Logs';
$pageIcon = 'bi bi-journal-text';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Logs Content -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon primary">
                <i class="bi bi-file-text"></i>
            </div>
            <h3 class="stats-value"><?= $logStats['total'] ?></h3>
            <p class="stats-label">Total Log Entries</p>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card secondary">
            <div class="stats-icon secondary">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <h3 class="stats-value"><?= $logStats['errors'] ?></h3>
            <p class="stats-label">Error Entries</p>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon warning">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <h3 class="stats-value"><?= $logStats['warnings'] ?></h3>
            <p class="stats-label">Warning Entries</p>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon success">
                <i class="bi bi-info-circle"></i>
            </div>
            <h3 class="stats-value"><?= $logStats['info'] ?></h3>
            <p class="stats-label">Info Entries</p>
        </div>
    </div>
</div>

<!-- Log Management -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-gear"></i> Log Management
                </h5>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#downloadModal">
                        <i class="bi bi-download"></i> Download Logs
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#clearModal">
                        <i class="bi bi-trash"></i> Clear Logs
                    </button>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-journal-code text-primary mb-2" style="font-size: 2rem;"></i>
                        <h6>Application Logs</h6>
                        <p class="text-muted small mb-2"><?= $logStats['total'] ?> entries</p>
                        <small class="text-muted">Bot interactions, errors, and system events</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-server text-warning mb-2" style="font-size: 2rem;"></i>
                        <h6>System Logs</h6>
                        <p class="text-muted small mb-2"><?= count($systemLogs) ?> entries</p>
                        <small class="text-muted">Web server and system-level events</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="bi bi-bug text-danger mb-2" style="font-size: 2rem;"></i>
                        <h6>PHP Error Logs</h6>
                        <p class="text-muted small mb-2"><?= count($phpLogs) ?> entries</p>
                        <small class="text-muted">PHP errors, warnings, and notices</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Viewer Tabs -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <ul class="nav nav-tabs mb-4" id="logTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="app-logs-tab" data-bs-toggle="tab" data-bs-target="#app-logs" type="button" role="tab">
                        <i class="bi bi-journal-code"></i> Application Logs
                        <span class="badge bg-primary ms-2"><?= $logStats['total'] ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="system-logs-tab" data-bs-toggle="tab" data-bs-target="#system-logs" type="button" role="tab">
                        <i class="bi bi-server"></i> System Logs
                        <span class="badge bg-warning ms-2"><?= count($systemLogs) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="php-logs-tab" data-bs-toggle="tab" data-bs-target="#php-logs" type="button" role="tab">
                        <i class="bi bi-bug"></i> PHP Errors
                        <span class="badge bg-danger ms-2"><?= count($phpLogs) ?></span>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="logTabsContent">
                <!-- Application Logs -->
                <div class="tab-pane fade show active" id="app-logs" role="tabpanel">
                    <?php if (empty($applicationLogs)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-journal" style="font-size: 3rem; color: #e5e7eb;"></i>
                            <p class="text-muted mt-2">No application logs found</p>
                        </div>
                    <?php else: ?>
                        <div class="log-viewer" style="max-height: 600px; overflow-y: auto;">
                            <?php foreach ($applicationLogs as $log): ?>
                                <div class="log-entry p-2 border-bottom small">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <span class="badge bg-<?= match(strtoupper($log['level'])) {
                                                'ERROR' => 'danger',
                                                'WARNING' => 'warning',
                                                'INFO' => 'info',
                                                'DEBUG' => 'secondary',
                                                default => 'light'
                                            } ?> me-2"><?= htmlspecialchars($log['level']) ?></span>
                                            <span class="font-monospace"><?= htmlspecialchars($log['message']) ?></span>
                                        </div>
                                        <small class="text-muted ms-2"><?= htmlspecialchars($log['timestamp']) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- System Logs -->
                <div class="tab-pane fade" id="system-logs" role="tabpanel">
                    <?php if (empty($systemLogs)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-server" style="font-size: 3rem; color: #e5e7eb;"></i>
                            <p class="text-muted mt-2">No system logs accessible</p>
                            <small class="text-muted">System logs may require elevated permissions</small>
                        </div>
                    <?php else: ?>
                        <div class="log-viewer" style="max-height: 600px; overflow-y: auto;">
                            <?php foreach ($systemLogs as $log): ?>
                                <div class="log-entry p-2 border-bottom small">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <span class="badge bg-warning me-2"><?= htmlspecialchars($log['source'] ?? 'SYSTEM') ?></span>
                                            <span class="font-monospace"><?= htmlspecialchars($log['message']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- PHP Error Logs -->
                <div class="tab-pane fade" id="php-logs" role="tabpanel">
                    <?php if (empty($phpLogs)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-bug" style="font-size: 3rem; color: #e5e7eb;"></i>
                            <p class="text-muted mt-2">No PHP error logs found</p>
                        </div>
                    <?php else: ?>
                        <div class="log-viewer" style="max-height: 600px; overflow-y: auto;">
                            <?php foreach ($phpLogs as $log): ?>
                                <div class="log-entry p-2 border-bottom small">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <span class="badge bg-danger me-2">PHP</span>
                                            <span class="font-monospace"><?= htmlspecialchars($log['message']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Download Modal -->
<div class="modal fade" id="downloadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Download Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="download_logs">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="mb-3">
                        <label for="download_log_type" class="form-label">Select log type to download:</label>
                        <select class="form-select" id="download_log_type" name="log_type" required>
                            <option value="application">Application Logs</option>
                            <option value="system">System Logs</option>
                            <option value="php">PHP Error Logs</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        Logs will be downloaded as a text file with the last 1000 entries.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-download"></i> Download
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Clear Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="clear_logs">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="mb-3">
                        <label for="clear_log_type" class="form-label">Select log type to clear:</label>
                        <select class="form-select" id="clear_log_type" name="log_type" required>
                            <option value="application">Application Logs Only</option>
                            <option value="all">All Application Logs</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Warning:</strong> This action cannot be undone. Consider downloading logs first.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i> Clear Logs
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Include footer
include __DIR__ . '/includes/footer.php';
?> 