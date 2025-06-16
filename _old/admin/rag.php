<?php
/**
 * EDUC AI TalkBot - RAG Management Page
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication and setup
checkSetup();
requireAuth();

// Initialize components
$db = \EDUC\Database\Database::getInstance();
$ragStats = getRAGStats();

// Initialize LLM client for document processing
$llmClient = null;
$llmClientError = null;

try {
    $apiKey = \EDUC\Core\Environment::get('AI_API_KEY');
    $apiEndpoint = \EDUC\Core\Environment::get('AI_API_ENDPOINT');
    
    if (!empty($apiKey) && !empty($apiEndpoint)) {
        $llmClient = new \EDUC\API\LLMClient(
            $apiKey,
            $apiEndpoint,
            \EDUC\Core\Environment::get('EMBEDDING_API_ENDPOINT'),
            \EDUC\Core\Environment::get('MODELS_API_ENDPOINT')
        );
    } else {
        $missingVars = [];
        if (empty($apiKey)) $missingVars[] = 'AI_API_KEY';
        if (empty($apiEndpoint)) $missingVars[] = 'AI_API_ENDPOINT';
        $llmClientError = 'Missing environment variables: ' . implode(', ', $missingVars);
    }
} catch (Exception $e) {
    $llmClientError = $e->getMessage();
    \EDUC\Utils\Logger::error('Failed to initialize LLM client in RAG management', ['error' => $e->getMessage()]);
}

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
            case 'upload_files':
                $result = handleFileUpload($_FILES);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'process_documents':
                $result = processDocuments($db, $llmClient);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'clear_rag_data':
                $result = clearRAGData($db);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'delete_document':
                $result = deleteDocument($db, $_POST['document_id'] ?? 0);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
        // Refresh stats after any changes
        $ragStats = getRAGStats();
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('RAG management error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

/**
 * Handle file upload
 */
function handleFileUpload(array $files): array {
    if (!isset($files['documents']) || empty($files['documents']['name'][0])) {
        return ['message' => 'No files selected', 'type' => 'error'];
    }
    
    $uploadDir = \EDUC\Core\Environment::getUploadsPath();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = explode(',', \EDUC\Core\Environment::get('ALLOWED_FILE_TYPES', 'txt,md,pdf,docx,html,csv,json'));
    $maxFileSize = (int)\EDUC\Core\Environment::get('MAX_FILE_SIZE', 10485760); // 10MB default
    
    $uploadedFiles = [];
    $errors = [];
    
    $fileCount = count($files['documents']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['documents']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file " . ($i + 1);
            continue;
        }
        
        $originalName = $files['documents']['name'][$i];
        $tmpName = $files['documents']['tmp_name'][$i];
        $size = $files['documents']['size'][$i];
        $type = $files['documents']['type'][$i];
        
        // Validate file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes)) {
            $errors[] = "Invalid file type for {$originalName}";
            continue;
        }
        
        // Validate file size
        if ($size > $maxFileSize) {
            $errors[] = "File {$originalName} is too large";
            continue;
        }
        
        // Generate unique filename
        $filename = uniqid() . '_' . $originalName;
        $destination = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($tmpName, $destination)) {
            $uploadedFiles[] = [
                'filename' => $filename,
                'original_name' => $originalName,
                'size' => $size,
                'type' => $type,
                'path' => $destination
            ];
        } else {
            $errors[] = "Failed to move {$originalName}";
        }
    }
    
    // Save file records to database
    $db = \EDUC\Database\Database::getInstance();
    foreach ($uploadedFiles as $file) {
        $sql = "INSERT INTO {$db->getTablePrefix()}documents 
                (filename, original_filename, file_path, file_size, mime_type, status) 
                VALUES (?, ?, ?, ?, ?, 'uploaded')";
        $db->execute($sql, [
            $file['filename'],
            $file['original_name'],
            $file['path'],
            $file['size'],
            $file['type']
        ]);
    }
    
    $uploadCount = count($uploadedFiles);
    $errorCount = count($errors);
    
    if ($uploadCount > 0 && $errorCount === 0) {
        return ['message' => "Successfully uploaded {$uploadCount} files", 'type' => 'success'];
    } elseif ($uploadCount > 0 && $errorCount > 0) {
        return ['message' => "Uploaded {$uploadCount} files with {$errorCount} errors", 'type' => 'warning'];
    } else {
        return ['message' => 'Upload failed: ' . implode(', ', $errors), 'type' => 'error'];
    }
}

/**
 * Process documents for RAG
 */
function processDocuments(\EDUC\Database\Database $db, ?\EDUC\API\LLMClient $llmClient): array {
    if (!$llmClient) {
        return ['message' => 'LLM client not available', 'type' => 'error'];
    }
    
    try {
        $embeddingRepository = new \EDUC\Database\EmbeddingRepository($db);
        $processor = new \EDUC\RAG\DocumentProcessor($llmClient, $db, $embeddingRepository);
        $results = $processor->processAllDocuments();
        
        $processed = 0;
        $embeddings = 0;
        $errors = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $processed++;
                $embeddings += $result['embeddings_count'] ?? 0;
            } else {
                $errors++;
            }
        }
        
        if ($processed > 0) {
            return [
                'message' => "Processed {$processed} documents, generated {$embeddings} embeddings" . 
                           ($errors > 0 ? " ({$errors} errors)" : ""),
                'type' => $errors > 0 ? 'warning' : 'success'
            ];
        } else {
            return ['message' => 'No documents to process or all failed', 'type' => 'warning'];
        }
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Document processing failed', ['error' => $e->getMessage()]);
        return ['message' => 'Document processing failed: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Clear RAG data
 */
function clearRAGData(\EDUC\Database\Database $db): array {
    try {
        $prefix = $db->getTablePrefix();
        
        // Clear embeddings and documents
        $db->execute("DELETE FROM {$prefix}embeddings");
        $db->execute("DELETE FROM {$prefix}documents");
        
        \EDUC\Utils\Logger::info('RAG data cleared');
        
        return ['message' => 'RAG data cleared successfully', 'type' => 'success'];
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Failed to clear RAG data', ['error' => $e->getMessage()]);
        return ['message' => 'Failed to clear RAG data: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Delete a specific document
 */
function deleteDocument(\EDUC\Database\Database $db, int $documentId): array {
    if ($documentId <= 0) {
        return ['message' => 'Invalid document ID', 'type' => 'error'];
    }
    
    try {
        // Delete embeddings first
        $prefix = $db->getTablePrefix();
        $db->execute("DELETE FROM {$prefix}embeddings WHERE document_id = ?", [$documentId]);
        
        // Then delete the document record
        $result = $db->execute("DELETE FROM {$prefix}documents WHERE id = ?", [$documentId]);
        
        if ($result) {
            return ['message' => 'Document deleted successfully', 'type' => 'success'];
        } else {
            return ['message' => 'Failed to delete document', 'type' => 'error'];
        }
    } catch (Exception $e) {
        return ['message' => 'Error deleting document: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Get all documents
 */
function getAllDocuments(): array {
    try {
        $db = \EDUC\Database\Database::getInstance();
        $prefix = $db->getTablePrefix();
        
        $sql = "SELECT id, filename, original_filename, file_path, file_size, mime_type, status, created_at 
                FROM {$prefix}documents 
                ORDER BY created_at DESC";
        
        return $db->query($sql);
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Failed to get documents', ['error' => $e->getMessage()]);
        return [];
    }
}

$documents = getAllDocuments();
$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

// Page configuration
$pageTitle = 'RAG Management';
$pageIcon = 'bi bi-database';

// Include header
include __DIR__ . '/includes/header.php';
?>

<!-- RAG Management Content -->
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Statistics Row -->
<div class="row mb-4 fade-in-up">
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card">
            <div class="stats-icon primary">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['total_documents']) ?></h3>
            <p class="stats-label">Total Documents</p>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card success">
            <div class="stats-icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['processed_documents']) ?></h3>
            <p class="stats-label">Processed</p>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card warning">
            <div class="stats-icon warning">
                <i class="bi bi-clock"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['pending_documents']) ?></h3>
            <p class="stats-label">Pending</p>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card secondary">
            <div class="stats-icon secondary">
                <i class="bi bi-layers"></i>
            </div>
            <h3 class="stats-value"><?= number_format($ragStats['total_embeddings']) ?></h3>
            <p class="stats-label">Embeddings</p>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="row">
    <div class="col-lg-8">
        <!-- File Upload Section -->
        <div class="stats-card mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-cloud-upload"></i> Upload Documents
                </h5>
            </div>
            
            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_files">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                
                <div class="file-upload-area mb-3">
                    <i class="bi bi-cloud-upload" style="font-size: 3rem; color: #6c757d;"></i>
                    <h6 class="mt-2">Upload Documents for RAG</h6>
                    <p class="text-muted mb-3">Select multiple files to upload</p>
                    <input type="file" class="form-control" name="documents[]" multiple 
                           accept=".txt,.md,.pdf,.docx,.html,.csv,.json" required>
                    <div class="form-text mt-2">
                        Supported formats: TXT, MD, PDF, DOCX, HTML, CSV, JSON<br>
                        Maximum size: <?= formatBytes((int)\EDUC\Core\Environment::get('MAX_FILE_SIZE', 10485760)) ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Upload Files
                </button>
            </form>
        </div>
        
        <!-- Documents List -->
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="bi bi-list-ul"></i> Documents (<?= count($documents) ?>)
                </h5>
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            
            <?php if (empty($documents)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #e5e7eb;"></i>
                    <p class="text-muted mt-2">No documents uploaded yet</p>
                    <p class="text-muted">Upload some documents to get started with RAG</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-2"></i>
                                        <?= htmlspecialchars($doc['original_filename']) ?>
                                    </td>
                                    <td><?= formatBytes($doc['file_size']) ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= strtoupper(pathinfo($doc['original_filename'], PATHINFO_EXTENSION)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match($doc['status']) {
                                            'processed' => 'success',
                                            'pending', 'uploaded' => 'warning',
                                            'failed' => 'danger',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?= $statusClass ?>">
                                            <?= ucfirst($doc['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= formatDateTime($doc['created_at']) ?></td>
                                    <td>
                                        <form method="post" action="" class="d-inline" 
                                              onsubmit="return confirm('Are you sure you want to delete this document?')">
                                            <input type="hidden" name="action" value="delete_document">
                                            <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Actions Card -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-lightning"></i> Actions
            </h5>
            
            <?php if ($llmClientError): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>API Configuration Required</strong><br>
                    <?= htmlspecialchars($llmClientError) ?>
                </div>
            <?php endif; ?>
            
            <div class="d-grid gap-2">
                <form method="post" action="">
                    <input type="hidden" name="action" value="process_documents">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <button type="submit" class="btn btn-success w-100" 
                            <?= ($ragStats['pending_documents'] == 0 || $llmClientError) ? 'disabled' : '' ?>>
                        <i class="bi bi-gear"></i> Process Documents
                    </button>
                </form>
                
                <form method="post" action="" 
                      onsubmit="return confirm('Are you sure you want to clear all RAG data? This cannot be undone.')">
                    <input type="hidden" name="action" value="clear_rag_data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-trash"></i> Clear All Data
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Configuration Card -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-gear"></i> Configuration
            </h5>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Chunk Size:</span>
                    <span class="small"><?= \EDUC\Core\Environment::get('RAG_CHUNK_SIZE', '1000') ?></span>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Chunk Overlap:</span>
                    <span class="small"><?= \EDUC\Core\Environment::get('RAG_CHUNK_OVERLAP', '200') ?></span>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Top K Results:</span>
                    <span class="small"><?= \EDUC\Core\Environment::get('RAG_TOP_K', '5') ?></span>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Embedding Model:</span>
                    <span class="small"><?= \EDUC\Core\Environment::get('EMBEDDING_MODEL', 'e5-mistral-7b-instruct') ?></span>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span class="small text-muted">Max File Size:</span>
                    <span class="small"><?= formatBytes((int)\EDUC\Core\Environment::get('MAX_FILE_SIZE', 10485760)) ?></span>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <small class="text-muted">
                    Configure these settings via environment variables
                </small>
            </div>
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
// Include footer
include __DIR__ . '/includes/footer.php';
?> 