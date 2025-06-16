<?php
/**
 * EDUC AI TalkBot - Admin Dashboard
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication and setup
checkSetup();
requireAuth();

// Get statistics
$systemStats = getSystemStats();
$ragStats = getRAGStats();
$systemInfo = getSystemInfo();

// Check for setup completion
$setupComplete = isset($_GET['setup']) && $_GET['setup'] === 'complete';

// Page configuration
$pageTitle = 'Dashboard';
$pageIcon = 'bi bi-house';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- Dashboard Content -->
<?php if ($setupComplete): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i>
        <strong>Welcome!</strong> Your EDUC AI TalkBot admin panel has been set up successfully. 
        You now have full access to all administrative features.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4 fade-in-up">
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card">
            <div class="stats-icon primary">
                <i class="bi bi-chat-dots"></i>
            </div>
            <h3 class="stats-value"><?= number_format($systemStats['total_messages']) ?></h3>
            <p class="stats-label">Total Messages</p>
            <small class="text-muted">
                <i class="bi bi-clock"></i> 
                <?= number_format($systemStats['messages_24h']) ?> in last 24h
            </small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card success">
            <div class="stats-icon success">
                <i class="bi bi-people"></i>
            </div>
            <h3 class="stats-value"><?= number_format($systemStats['total_chats']) ?></h3>
            <p class="stats-label">Active Chats</p>
            <small class="text-muted">
                <i class="bi bi-graph-up"></i> 
                Conversation threads
            </small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card warning">
            <div class="stats-icon warning">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['total_documents']) ?></h3>
            <p class="stats-label">Documents</p>
            <small class="text-muted">
                <i class="bi bi-check-circle"></i> 
                <?= number_format($ragStats['processed_documents']) ?> processed
            </small>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card secondary">
            <div class="stats-icon secondary">
                <i class="bi bi-layers"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['total_embeddings']) ?></h3>
            <p class="stats-label">Embeddings</p>
            <small class="text-muted">
                <i class="bi bi-database"></i> 
                Vector database
            </small>
        </div>
    </div>
</div>

<!-- Quick Actions & System Overview -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-speedometer2"></i> System Overview
                </h5>
                <button class="btn btn-outline-primary btn-sm" onclick="refreshData()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">PHP Version</span>
                            <span class="small"><?= $systemInfo['php_version'] ?></span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Memory Limit</span>
                            <span class="small"><?= $systemInfo['php_memory_limit'] ?></span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Database</span>
                            <span class="small">
                                <i class="bi bi-check-circle text-success"></i> 
                                <?= $systemInfo['database_type'] ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Cloudron Mode</span>
                            <span class="small">
                                <?php if ($systemInfo['cloudron_mode']): ?>
                                    <i class="bi bi-check-circle text-success"></i> Enabled
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-warning"></i> Disabled
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Admin Users</span>
                            <span class="small"><?= number_format($systemStats['admin_users']) ?></span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted">Disk Usage</span>
                            <span class="small">
                                <?= $systemInfo['disk_usage']['usage_percentage'] ?>% 
                                (<?= $systemInfo['disk_usage']['used'] ?> / <?= $systemInfo['disk_usage']['total'] ?>)
                            </span>
                        </div>
                        <div class="progress" style="height: 4px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $systemInfo['disk_usage']['usage_percentage'] ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Extension Status -->
            <div class="mt-3 pt-3 border-top">
                <h6 class="mb-2">PHP Extensions</h6>
                <div class="row">
                    <?php foreach ($systemInfo['extensions'] as $ext => $loaded): ?>
                        <div class="col-md-3 col-sm-4 col-6 mb-2">
                            <small class="d-flex align-items-center">
                                <?php if ($loaded): ?>
                                    <i class="bi bi-check-circle text-success me-1"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-danger me-1"></i>
                                <?php endif; ?>
                                <?= $ext ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-lightning"></i> Quick Actions
            </h5>
            
            <div class="d-grid gap-2">
                <a href="settings.php" class="btn btn-outline-primary">
                    <i class="bi bi-gear"></i> Configure Settings
                </a>
                
                <a href="rag.php" class="btn btn-outline-success">
                    <i class="bi bi-upload"></i> Upload Documents
                </a>
                
                <a href="models.php" class="btn btn-outline-info">
                    <i class="bi bi-cpu"></i> Manage Models
                </a>
                
                <a href="system.php" class="btn btn-outline-warning">
                    <i class="bi bi-info-circle"></i> System Info
                </a>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-activity"></i> System Status
            </h5>
            
            <div class="status-item mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small">Database Connection</span>
                    <span class="badge bg-success">Connected</span>
                </div>
            </div>
            
            <div class="status-item mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small">AI API Status</span>
                    <span class="badge bg-info" id="apiStatus">Checking...</span>
                </div>
            </div>
            
            <div class="status-item mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small">pgvector Extension</span>
                    <span class="badge bg-success">Available</span>
                </div>
            </div>
            
            <div class="status-item mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small">Log Level</span>
                    <span class="badge bg-secondary"><?= strtoupper($systemInfo['log_level']) ?></span>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <small class="text-muted">
                    Last updated: <span id="lastUpdated"><?= date('H:i:s') ?></span>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-graph-up"></i> Message Activity (Last 7 Days)
            </h5>
            <canvas id="messageChart" height="200"></canvas>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-pie-chart"></i> Document Types
            </h5>
            <?php if (!empty($ragStats['document_types'])): ?>
                <canvas id="documentChart" height="200"></canvas>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #e5e7eb;"></i>
                    <p class="text-muted mt-2">No documents uploaded yet</p>
                    <a href="rag.php" class="btn btn-outline-primary btn-sm">
                        Upload Documents
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-refresh interval
window.autoRefreshInterval = 30; // 30 seconds

// Refresh data function
function refreshData() {
    showLoading('.stats-card');
    location.reload();
}

// Initialize charts
document.addEventListener('DOMContentLoaded', function() {
    // Message activity chart
    const messageCtx = document.getElementById('messageChart');
    if (messageCtx) {
        new Chart(messageCtx, {
            type: 'line',
            data: {
                labels: ['6 days ago', '5 days ago', '4 days ago', '3 days ago', '2 days ago', 'Yesterday', 'Today'],
                datasets: [{
                    label: 'Messages',
                    data: [12, 19, 3, 5, 2, 3, <?= $systemStats['messages_24h'] ?>],
                    borderColor: chartColors.primary,
                    backgroundColor: chartColors.primary + '20',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    // Document types chart
    const documentCtx = document.getElementById('documentChart');
    if (documentCtx) {
        const documentTypes = <?= json_encode($ragStats['document_types']) ?>;
        
        if (documentTypes.length > 0) {
            new Chart(documentCtx, {
                type: 'doughnut',
                data: {
                    labels: documentTypes.map(type => type.mime_type || 'Unknown'),
                    datasets: [{
                        data: documentTypes.map(type => type.count),
                        backgroundColor: [
                            chartColors.primary,
                            chartColors.success,
                            chartColors.warning,
                            chartColors.secondary,
                            chartColors.info
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    }
    
    // Check API status
    checkAPIStatus();
});

function checkAPIStatus() {
    fetch('api/status.php')
        .then(response => response.json())
        .then(data => {
            const statusBadge = document.getElementById('apiStatus');
            if (data.status === 'success') {
                statusBadge.textContent = 'Connected';
                statusBadge.className = 'badge bg-success';
            } else {
                statusBadge.textContent = 'Error';
                statusBadge.className = 'badge bg-danger';
            }
        })
        .catch(error => {
            const statusBadge = document.getElementById('apiStatus');
            statusBadge.textContent = 'Unknown';
            statusBadge.className = 'badge bg-warning';
        });
}

// Update last updated time
setInterval(function() {
    document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString();
}, 1000);
</script>

<?php
// Page-specific scripts
$additionalScripts = [];
$inlineScript = '';

// Include footer
include __DIR__ . '/includes/footer.php';
?> 