<?php
/**
 * EDUC AI TalkBot - System Information
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication
checkSetup();
requireAuth();

// Page configuration
$pageTitle = 'System Information';
$pageIcon = 'bi bi-info-circle';

// Include header
include __DIR__ . '/includes/header.php';
?>

<div class="row">
    <div class="col-lg-6">
        <!-- PHP Information -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-server"></i> PHP Information
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td width="40%">PHP Version</td>
                    <td><?= PHP_VERSION ?></td>
                </tr>
                <tr>
                    <td>SAPI</td>
                    <td><?= php_sapi_name() ?></td>
                </tr>
                <tr>
                    <td>Memory Limit</td>
                    <td><?= ini_get('memory_limit') ?></td>
                </tr>
                <tr>
                    <td>Max Execution Time</td>
                    <td><?= ini_get('max_execution_time') ?> seconds</td>
                </tr>
                <tr>
                    <td>Upload Max Filesize</td>
                    <td><?= ini_get('upload_max_filesize') ?></td>
                </tr>
                <tr>
                    <td>Post Max Size</td>
                    <td><?= ini_get('post_max_size') ?></td>
                </tr>
                <tr>
                    <td>Display Errors</td>
                    <td><?= ini_get('display_errors') ? 'On' : 'Off' ?></td>
                </tr>
                <tr>
                    <td>Error Reporting</td>
                    <td><?= error_reporting() ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Database Information -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-database"></i> Database Information
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td width="40%">Database Type</td>
                    <td>PostgreSQL</td>
                </tr>
                <tr>
                    <td>Host</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_POSTGRESQL_HOST') ?: 'Not configured') ?></td>
                </tr>
                <tr>
                    <td>Port</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_POSTGRESQL_PORT') ?: '5432') ?></td>
                </tr>
                <tr>
                    <td>Database</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_POSTGRESQL_DATABASE') ?: 'Not configured') ?></td>
                </tr>
                <tr>
                    <td>Username</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_POSTGRESQL_USERNAME') ?: 'Not configured') ?></td>
                </tr>
                <tr>
                    <td>Password</td>
                    <td>
                        <?php if (getenv('CLOUDRON_POSTGRESQL_PASSWORD')): ?>
                            <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Not set</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Connection Status</td>
                    <td>
                        <span class="badge bg-warning">Testing...</span>
                    </td>
                </tr>
                <tr>
                    <td>pgvector Extension</td>
                    <td>
                        <span class="badge bg-info">Check pgvector</span>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- API Configuration -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-cloud"></i> API Configuration
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td width="40%">AI API Key</td>
                    <td>
                        <?php if (getenv('AI_API_KEY')): ?>
                            <span class="badge bg-success">Configured (<?= strlen(getenv('AI_API_KEY')) ?> chars)</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Not set</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Chat API Endpoint</td>
                    <td><code class="small"><?= htmlspecialchars(getenv('AI_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/chat/completions') ?></code></td>
                </tr>
                <tr>
                    <td>Embeddings Endpoint</td>
                    <td><code class="small"><?= htmlspecialchars(getenv('EMBEDDING_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/embeddings') ?></code></td>
                </tr>
                <tr>
                    <td>Models Endpoint</td>
                    <td><code class="small"><?= htmlspecialchars(getenv('MODELS_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/models') ?></code></td>
                </tr>
                <tr>
                    <td>Documents Endpoint</td>
                    <td><code class="small"><?= htmlspecialchars(getenv('DOCUMENTS_API_ENDPOINT') ?: 'https://chat-ai.academiccloud.de/v1/documents') ?></code></td>
                </tr>
                <tr>
                    <td>API Status</td>
                    <td>
                        <span class="badge bg-warning" id="apiStatus">Checking...</span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="col-lg-6">
        <!-- Cloudron Environment -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-cloud-fill"></i> Cloudron Environment
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td width="40%">Cloudron Mode</td>
                    <td>
                        <?php if (getenv('CLOUDRON_ENVIRONMENT')): ?>
                            <span class="badge bg-success">Yes</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Environment</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_ENVIRONMENT') ?: 'Not set') ?></td>
                </tr>
                <tr>
                    <td>App Domain</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_APP_DOMAIN') ?: 'Not set') ?></td>
                </tr>
                <tr>
                    <td>App Origin</td>
                    <td><?= htmlspecialchars(getenv('CLOUDRON_APP_ORIGIN') ?: 'Not set') ?></td>
                </tr>
                <tr>
                    <td>App Name</td>
                    <td><?= htmlspecialchars(getenv('APP_NAME') ?: 'EDUC AI TalkBot Enhanced') ?></td>
                </tr>
                <tr>
                    <td>App ID</td>
                    <td><?= htmlspecialchars(getenv('APP_ID') ?: 'Not set') ?></td>
                </tr>
                <tr>
                    <td>App Directory</td>
                    <td><code class="small"><?= htmlspecialchars(getenv('APP_DIRECTORY') ?: getcwd()) ?></code></td>
                </tr>
            </table>
        </div>
        
        <!-- PHP Extensions -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-puzzle"></i> PHP Extensions
            </h5>
            
            <?php
            $requiredExtensions = [
                'pdo' => 'PDO',
                'pdo_pgsql' => 'PDO PostgreSQL',
                'pgsql' => 'PostgreSQL',
                'curl' => 'cURL',
                'json' => 'JSON',
                'mbstring' => 'Multibyte String',
                'fileinfo' => 'File Info',
                'gd' => 'GD Graphics',
                'zip' => 'ZIP',
                'openssl' => 'OpenSSL'
            ];
            ?>
            
            <div class="row">
                <?php foreach ($requiredExtensions as $ext => $name): ?>
                    <div class="col-md-6 mb-2">
                        <div class="d-flex align-items-center">
                            <?php if (extension_loaded($ext)): ?>
                                <i class="bi bi-check-circle text-success me-2"></i>
                            <?php else: ?>
                                <i class="bi bi-x-circle text-danger me-2"></i>
                            <?php endif; ?>
                            <span class="small"><?= $name ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <hr>
            
            <h6>PDO Drivers</h6>
            <div class="small">
                <?php
                try {
                    $drivers = PDO::getAvailableDrivers();
                    echo 'Available: ' . implode(', ', $drivers);
                } catch (Exception $e) {
                    echo 'Error getting PDO drivers';
                }
                ?>
            </div>
        </div>
        
        <!-- File System -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-folder"></i> File System
            </h5>
            
            <?php
            $paths = [
                'Current Directory' => getcwd(),
                'Script Directory' => __DIR__,
                'Uploads Directory' => __DIR__ . '/../uploads',
                'Cache Directory' => __DIR__ . '/../cache',
                'Logs Directory' => __DIR__ . '/../logs',
                'Vendor Directory' => __DIR__ . '/../vendor',
                'Bootstrap File' => __DIR__ . '/../educ-bootstrap.php'
            ];
            ?>
            
            <table class="table table-sm">
                <?php foreach ($paths as $label => $path): ?>
                    <tr>
                        <td width="40%"><?= $label ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if (file_exists($path)): ?>
                                    <i class="bi bi-check-circle text-success me-2"></i>
                                    <span class="small text-muted"><?= htmlspecialchars($path) ?></span>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-danger me-2"></i>
                                    <span class="small text-muted"><?= htmlspecialchars($path) ?> (Not found)</span>
                                <?php endif; ?>
                            </div>
                            <?php if (file_exists($path)): ?>
                                <div class="small text-muted mt-1">
                                    <?php if (is_readable($path)): ?>
                                        <span class="badge badge-sm bg-success">Readable</span>
                                    <?php endif; ?>
                                    <?php if (is_writable($path)): ?>
                                        <span class="badge badge-sm bg-info">Writable</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<!-- System Actions -->
<div class="row mt-4">
    <div class="col-lg-12">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-tools"></i> System Actions
            </h5>
            
            <div class="row">
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100 mb-2" onclick="testDatabaseConnection()">
                        <i class="bi bi-database"></i> Test Database
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-info w-100 mb-2" onclick="testAPIConnection()">
                        <i class="bi bi-cloud"></i> Test API
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-warning w-100 mb-2" onclick="clearCache()">
                        <i class="bi bi-arrow-clockwise"></i> Clear Cache
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-success w-100 mb-2" onclick="downloadDiagnostics()">
                        <i class="bi bi-download"></i> Download Diagnostics
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Diagnostic Information -->
<div class="row mt-4">
    <div class="col-lg-12">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-bug"></i> Diagnostic Information
            </h5>
            
            <div class="small">
                <strong>Current Time:</strong> <?= date('Y-m-d H:i:s T') ?><br>
                <strong>Server Software:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?><br>
                <strong>Document Root:</strong> <?= $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown' ?><br>
                <strong>HTTP Host:</strong> <?= $_SERVER['HTTP_HOST'] ?? 'Unknown' ?><br>
                <strong>Request URI:</strong> <?= $_SERVER['REQUEST_URI'] ?? 'Unknown' ?><br>
                <strong>User Agent:</strong> <?= $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ?>
            </div>
            
            <hr>
            
            <div class="mt-3">
                <button class="btn btn-outline-info" onclick="window.open('?phpinfo=1', '_blank')">
                    <i class="bi bi-info-circle"></i> View PHP Info
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Test database connection
function testDatabaseConnection() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Testing...';
    btn.disabled = true;
    
    // Simulate test (replace with actual AJAX call)
    setTimeout(() => {
        alert('Database connection test completed. Check the System Information section for details.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 2000);
}

// Test API connection
function testAPIConnection() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Testing...';
    btn.disabled = true;
    
    // Check API status
    fetch('api/status.php')
        .then(response => response.json())
        .then(data => {
            const statusElement = document.getElementById('apiStatus');
            if (data.status === 'success') {
                statusElement.textContent = 'Connected';
                statusElement.className = 'badge bg-success';
                alert('✅ API connection successful!');
            } else {
                statusElement.textContent = 'Error';
                statusElement.className = 'badge bg-danger';
                alert('❌ API connection failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            const statusElement = document.getElementById('apiStatus');
            statusElement.textContent = 'Error';
            statusElement.className = 'badge bg-danger';
            alert('❌ API test failed: ' + error.message);
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
}

// Clear cache
function clearCache() {
    if (confirm('Clear application cache?')) {
        alert('Cache clearing functionality will be implemented');
    }
}

// Download diagnostics
function downloadDiagnostics() {
    // Generate diagnostics report
    const diagnostics = {
        timestamp: new Date().toISOString(),
        php_version: '<?= PHP_VERSION ?>',
        cloudron_mode: <?= getenv('CLOUDRON_ENVIRONMENT') ? 'true' : 'false' ?>,
        api_configured: <?= getenv('AI_API_KEY') ? 'true' : 'false' ?>,
        database_configured: <?= getenv('CLOUDRON_POSTGRESQL_HOST') ? 'true' : 'false' ?>
    };
    
    const blob = new Blob([JSON.stringify(diagnostics, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'educ-ai-diagnostics-' + new Date().toISOString().slice(0, 10) + '.json';
    a.click();
    URL.revokeObjectURL(url);
}

// Add spinning animation
const style = document.createElement('style');
style.textContent = `
    .spin {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);

// Check API status on page load
document.addEventListener('DOMContentLoaded', function() {
    const statusElement = document.getElementById('apiStatus');
    
    fetch('api/status.php')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                statusElement.textContent = 'Connected';
                statusElement.className = 'badge bg-success';
            } else {
                statusElement.textContent = 'Error';
                statusElement.className = 'badge bg-danger';
            }
        })
        .catch(error => {
            statusElement.textContent = 'Unknown';
            statusElement.className = 'badge bg-warning';
        });
});
</script>

<?php
include __DIR__ . '/includes/footer.php';
?> 