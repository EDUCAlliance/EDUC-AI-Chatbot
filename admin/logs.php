<?php
/**
 * EDUC AI TalkBot - System Logs
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication
checkSetup();
requireAuth();

// Handle form submissions
$message = '';
$messageType = '';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'clear_logs':
            $message = 'Log clearing functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'download_logs':
            $message = 'Log download functionality will be implemented';
            $messageType = 'info';
            break;
    }
}

// Get log data
$logLevels = ['ERROR', 'WARNING', 'INFO', 'DEBUG'];
$selectedLevel = $_GET['level'] ?? 'all';
$selectedLines = $_GET['lines'] ?? '100';

// Mock log entries for display
$logEntries = [
    [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'INFO',
        'message' => 'Application started successfully',
        'context' => ['module' => 'system']
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
        'level' => 'DEBUG',
        'message' => 'Database connection established',
        'context' => ['host' => 'localhost', 'database' => 'educ_ai']
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
        'level' => 'WARNING',
        'message' => 'API rate limit approaching',
        'context' => ['remaining' => '95', 'limit' => '1000']
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
        'level' => 'ERROR',
        'message' => 'Failed to process document',
        'context' => ['file' => 'example.pdf', 'error' => 'Invalid format']
    ]
];

// Filter logs by level if specified
if ($selectedLevel !== 'all') {
    $logEntries = array_filter($logEntries, function($entry) use ($selectedLevel) {
        return $entry['level'] === $selectedLevel;
    });
}

// Page configuration
$pageTitle = 'System Logs';
$pageIcon = 'bi bi-journal-text';

// Include header
include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
        <i class="bi bi-info-circle"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-12">
        <!-- Log Controls -->
        <div class="stats-card mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-journal-text"></i> System Logs
                </h5>
                <div>
                    <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="toggleAutoRefresh()">
                        <i class="bi bi-play-circle" id="autoRefreshIcon"></i> <span id="autoRefreshText">Auto Refresh</span>
                    </button>
                </div>
            </div>
            
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label for="level" class="form-label">Log Level</label>
                    <select class="form-select" name="level" id="level" onchange="this.form.submit()">
                        <option value="all" <?= $selectedLevel === 'all' ? 'selected' : '' ?>>All Levels</option>
                        <?php foreach ($logLevels as $level): ?>
                            <option value="<?= $level ?>" <?= $selectedLevel === $level ? 'selected' : '' ?>>
                                <?= $level ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="lines" class="form-label">Number of Lines</label>
                    <select class="form-select" name="lines" id="lines" onchange="this.form.submit()">
                        <option value="50" <?= $selectedLines === '50' ? 'selected' : '' ?>>50 lines</option>
                        <option value="100" <?= $selectedLines === '100' ? 'selected' : '' ?>>100 lines</option>
                        <option value="500" <?= $selectedLines === '500' ? 'selected' : '' ?>>500 lines</option>
                        <option value="1000" <?= $selectedLines === '1000' ? 'selected' : '' ?>>1000 lines</option>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <div class="btn-group">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="download_logs">
                            <button type="submit" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-download"></i> Download Logs
                            </button>
                        </form>
                        
                        <form method="POST" class="d-inline ms-2">
                            <input type="hidden" name="action" value="clear_logs">
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear all logs?')">
                                <i class="bi bi-trash"></i> Clear Logs
                            </button>
                        </form>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Log Display -->
        <div class="stats-card">
            <div class="log-container" style="max-height: 600px; overflow-y: auto;">
                <?php if (empty($logEntries)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-journal" style="font-size: 3rem; color: #e5e7eb;"></i>
                        <h6 class="mt-3 text-muted">No logs found</h6>
                        <p class="text-muted">No log entries match the current filter criteria</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($logEntries as $entry): ?>
                        <div class="log-entry log-level-<?= $entry['level'] ?>">
                            <div class="d-flex align-items-start">
                                <div class="log-timestamp">
                                    <small class="text-muted"><?= $entry['timestamp'] ?></small>
                                </div>
                                <div class="log-level ms-3">
                                    <?php
                                    $levelClass = match($entry['level']) {
                                        'ERROR' => 'bg-danger',
                                        'WARNING' => 'bg-warning',
                                        'INFO' => 'bg-info',
                                        'DEBUG' => 'bg-secondary',
                                        default => 'bg-light'
                                    };
                                    ?>
                                    <span class="badge <?= $levelClass ?>"><?= $entry['level'] ?></span>
                                </div>
                                <div class="log-message ms-3 flex-grow-1">
                                    <div class="fw-medium"><?= htmlspecialchars($entry['message']) ?></div>
                                    <?php if (!empty($entry['context'])): ?>
                                        <div class="log-context mt-1">
                                            <small class="text-muted">
                                                <?php foreach ($entry['context'] as $key => $value): ?>
                                                    <span class="me-3"><strong><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars($value) ?></span>
                                                <?php endforeach; ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Log Statistics in Sidebar -->
<div class="row mt-4">
    <div class="col-lg-3">
        <div class="stats-card">
            <h6><i class="bi bi-exclamation-triangle"></i> Errors</h6>
            <h3 class="text-danger">1</h3>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="stats-card">
            <h6><i class="bi bi-exclamation-circle"></i> Warnings</h6>
            <h3 class="text-warning">1</h3>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="stats-card">
            <h6><i class="bi bi-info-circle"></i> Info</h6>
            <h3 class="text-info">1</h3>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="stats-card">
            <h6><i class="bi bi-bug"></i> Debug</h6>
            <h3 class="text-secondary">1</h3>
        </div>
    </div>
</div>

<style>
.log-entry {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 0.85em;
    background: #f8f9fa;
    padding: 0.75rem;
    margin: 0.25rem 0;
    border-radius: 4px;
    border-left: 3px solid #dee2e6;
    transition: all 0.2s ease;
}

.log-entry:hover {
    background: #e9ecef;
}

.log-level-ERROR {
    background: #f8d7da;
    border-left-color: #dc3545;
}

.log-level-WARNING {
    background: #fff3cd;
    border-left-color: #ffc107;
}

.log-level-INFO {
    background: #d1ecf1;
    border-left-color: #17a2b8;
}

.log-level-DEBUG {
    background: #d4edda;
    border-left-color: #28a745;
}

.log-container {
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

.log-timestamp {
    min-width: 150px;
}

.log-level {
    min-width: 80px;
}

.log-message {
    word-break: break-word;
}

.log-context {
    background: rgba(0,0,0,0.05);
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-family: monospace;
}
</style>

<script>
let autoRefreshInterval = null;

function toggleAutoRefresh() {
    const icon = document.getElementById('autoRefreshIcon');
    const text = document.getElementById('autoRefreshText');
    
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        icon.className = 'bi bi-play-circle';
        text.textContent = 'Auto Refresh';
    } else {
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, 30000); // Refresh every 30 seconds
        
        icon.className = 'bi bi-pause-circle';
        text.textContent = 'Stop Auto Refresh';
    }
}

// Auto-scroll to bottom for new logs
function scrollToBottom() {
    const container = document.querySelector('.log-container');
    container.scrollTop = container.scrollHeight;
}

// Highlight search terms (if implemented)
function highlightSearchTerms(searchTerm) {
    const entries = document.querySelectorAll('.log-message');
    entries.forEach(entry => {
        const text = entry.textContent;
        if (searchTerm && text.toLowerCase().includes(searchTerm.toLowerCase())) {
            const regex = new RegExp(`(${searchTerm})`, 'gi');
            entry.innerHTML = text.replace(regex, '<mark>$1</mark>');
        }
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
            case 'r':
                e.preventDefault();
                location.reload();
                break;
            case 'f':
                e.preventDefault();
                // Focus search if implemented
                break;
        }
    }
});

// Real-time log level counts (would be implemented with WebSocket or polling)
function updateLogCounts() {
    // This would fetch real-time log statistics
    // and update the counters in the sidebar
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?> 