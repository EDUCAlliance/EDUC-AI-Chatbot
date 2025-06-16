<?php
/**
 * EDUC AI TalkBot - RAG Management
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
        case 'upload_files':
            $message = 'File upload functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'process_documents':
            $message = 'Document processing functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'clear_rag_data':
            $message = 'RAG data clearing functionality will be implemented';
            $messageType = 'info';
            break;
    }
}

// Get RAG statistics
$ragStats = getRAGStats();

// Page configuration
$pageTitle = 'RAG Management';
$pageIcon = 'bi bi-database';

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
    <div class="col-lg-8">
        <!-- File Upload Section -->
        <div class="stats-card mb-4">
            <h5 class="mb-4">
                <i class="bi bi-cloud-upload"></i> Upload Documents for RAG
            </h5>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>RAG (Retrieval-Augmented Generation):</strong> Upload documents to enhance the AI's knowledge base.
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_files">
                
                <div class="file-upload-area mb-4">
                    <i class="bi bi-cloud-upload" style="font-size: 3rem; color: #6c757d;"></i>
                    <h6 class="mt-2">Upload Documents</h6>
                    <p class="text-muted mb-3">Supported: txt, md, pdf, docx, html, csv, json</p>
                    <input type="file" class="form-control" name="documents[]" multiple accept=".txt,.md,.pdf,.docx,.html,.csv,.json">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Upload Documents
                </button>
            </form>
        </div>
        
        <!-- Document List -->
        <div class="stats-card">
            <h5 class="mb-4">
                <i class="bi bi-files"></i> Uploaded Documents
            </h5>
            
            <div class="text-center py-5">
                <i class="bi bi-inbox" style="font-size: 3rem; color: #e5e7eb;"></i>
                <h6 class="mt-3 text-muted">No documents uploaded yet</h6>
                <p class="text-muted">Upload documents above to get started with RAG</p>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- RAG Statistics -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-bar-chart"></i> RAG Statistics
            </h5>
            
            <div class="row text-center">
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-primary mb-1"><?= number_format($ragStats['total_documents']) ?></h4>
                        <small class="text-muted">Total Documents</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-success mb-1"><?= number_format($ragStats['processed_documents']) ?></h4>
                        <small class="text-muted">Processed</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-warning mb-1"><?= number_format($ragStats['pending_documents']) ?></h4>
                        <small class="text-muted">Pending</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-info mb-1"><?= number_format($ragStats['total_embeddings']) ?></h4>
                        <small class="text-muted">Embeddings</small>
                    </div>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="process_documents">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-gear"></i> Process Documents
                    </button>
                </form>
                
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="clear_rag_data">
                    <button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('Clear all RAG data?')">
                        <i class="bi bi-trash"></i> Clear RAG Data
                    </button>
                </form>
            </div>
        </div>
        
        <!-- RAG Configuration -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-gear"></i> Configuration
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td>RAG Enabled:</td>
                    <td>
                        <?php if (getenv('USE_RAG') === 'true'): ?>
                            <span class="badge bg-success">Yes</span>
                        <?php else: ?>
                            <span class="badge bg-warning">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Top K Results:</td>
                    <td><?= htmlspecialchars(getenv('RAG_TOP_K') ?: '5') ?></td>
                </tr>
                <tr>
                    <td>Embedding Model:</td>
                    <td><code><?= htmlspecialchars(getenv('EMBEDDING_MODEL') ?: 'e5-mistral-7b-instruct') ?></code></td>
                </tr>
            </table>
            
            <a href="settings.php" class="btn btn-outline-primary btn-sm w-100 mt-3">
                <i class="bi bi-gear"></i> Configure Settings
            </a>
        </div>
    </div>
</div>

<style>
.file-upload-area {
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    padding: 2rem;
    text-align: center;
    transition: border-color 0.3s;
}

.file-upload-area:hover {
    border-color: #0d6efd;
}
</style>

<?php
include __DIR__ . '/includes/footer.php';
?> 