<?php

namespace EDUC\RAG;

use Exception;
use EDUC\API\LLMClient;
use EDUC\Database\Database;
use EDUC\Database\EmbeddingRepository;
use EDUC\Utils\Logger;

/**
 * Document processor for handling file uploads and creating embeddings
 */
class DocumentProcessor {
    private LLMClient $llmClient;
    private Database $db;
    private EmbeddingRepository $embeddingRepository;
    private int $chunkSize;
    private int $chunkOverlap;
    private int $maxFileSize;
    private array $allowedMimeTypes;
    
    public function __construct(
        LLMClient $llmClient,
        Database $db,
        EmbeddingRepository $embeddingRepository,
        int $chunkSize = 1000,
        int $chunkOverlap = 200,
        int $maxFileSize = 10485760 // 10MB
    ) {
        $this->llmClient = $llmClient;
        $this->db = $db;
        $this->embeddingRepository = $embeddingRepository;
        $this->chunkSize = $chunkSize;
        $this->chunkOverlap = $chunkOverlap;
        $this->maxFileSize = $maxFileSize;
        $this->allowedMimeTypes = [
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/json',
            'text/html'
        ];
    }
    
    /**
     * Process uploaded file
     */
    public function processUploadedFile(array $file, array $metadata = []): array {
        try {
            // Validate file upload
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            
            // Create document record
            $documentId = $this->createDocumentRecord($file, $metadata);
            
            // Extract text content
            $content = $this->extractContent($file);
            if (empty($content)) {
                $this->updateDocumentStatus($documentId, 'failed', 'No content extracted');
                return ['success' => false, 'error' => 'No content could be extracted from file'];
            }
            
            // Process content and create embeddings
            $result = $this->processContent($documentId, $content, $metadata);
            
            if ($result['success']) {
                $this->updateDocumentStatus($documentId, 'processed');
                Logger::info('Document processed successfully', [
                    'document_id' => $documentId,
                    'filename' => $file['name'],
                    'embeddings_count' => $result['embeddings_count']
                ]);
            } else {
                $this->updateDocumentStatus($documentId, 'failed', $result['error']);
            }
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error('Document processing failed', [
                'error' => $e->getMessage(),
                'filename' => $file['name'] ?? 'unknown'
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process all pending documents
     */
    public function processAllDocuments(): array {
        $sql = "SELECT * FROM {$this->db->getTablePrefix()}documents 
                WHERE status = 'pending' 
                ORDER BY created_at ASC";
        
        $documents = $this->db->query($sql);
        $results = [];
        
        foreach ($documents as $document) {
            try {
                if (!file_exists($document['file_path'])) {
                    $this->updateDocumentStatus($document['id'], 'failed', 'File not found');
                    $results[] = ['document_id' => $document['id'], 'success' => false, 'error' => 'File not found'];
                    continue;
                }
                
                // Read file content
                $content = file_get_contents($document['file_path']);
                if ($content === false) {
                    $this->updateDocumentStatus($document['id'], 'failed', 'Could not read file');
                    $results[] = ['document_id' => $document['id'], 'success' => false, 'error' => 'Could not read file'];
                    continue;
                }
                
                // Process content
                $metadata = json_decode($document['metadata'], true) ?: [];
                $result = $this->processContent($document['id'], $content, $metadata);
                
                if ($result['success']) {
                    $this->updateDocumentStatus($document['id'], 'processed');
                } else {
                    $this->updateDocumentStatus($document['id'], 'failed', $result['error']);
                }
                
                $results[] = array_merge($result, ['document_id' => $document['id']]);
                
            } catch (Exception $e) {
                $this->updateDocumentStatus($document['id'], 'failed', $e->getMessage());
                $results[] = ['document_id' => $document['id'], 'success' => false, 'error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile(array $file): array {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'File upload error'];
        }
        
        if ($file['size'] > $this->maxFileSize) {
            return ['valid' => false, 'error' => 'File too large (max ' . round($this->maxFileSize / 1024 / 1024, 1) . 'MB)'];
        }
        
        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            return ['valid' => false, 'error' => 'File type not supported: ' . $mimeType];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Create document record in database
     */
    private function createDocumentRecord(array $file, array $metadata): int {
        $filename = uniqid() . '_' . basename($file['name']);
        $uploadDir = $this->getUploadDirectory();
        $filePath = $uploadDir . '/' . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // Insert document record
        $sql = "INSERT INTO {$this->db->getTablePrefix()}documents 
                (filename, original_filename, file_path, file_size, mime_type, metadata) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $this->db->execute($sql, [
            $filename,
            $file['name'],
            $filePath,
            $file['size'],
            $file['type'],
            json_encode($metadata)
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update document status
     */
    private function updateDocumentStatus(int $documentId, string $status, ?string $error = null): void {
        $sql = "UPDATE {$this->db->getTablePrefix()}documents 
                SET status = ?, processed_at = CURRENT_TIMESTAMP";
        
        $params = [$status];
        
        if ($error) {
            $sql .= ", metadata = jsonb_set(COALESCE(metadata, '{}'), '{error}', ?, true)";
            $params[] = json_encode($error);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $documentId;
        
        $this->db->execute($sql, $params);
    }
    
    /**
     * Extract content from file
     */
    private function extractContent(array $file): string {
        $mimeType = mime_content_type($file['tmp_name']);
        
        switch ($mimeType) {
            case 'text/plain':
            case 'text/markdown':
            case 'text/csv':
            case 'application/json':
            case 'text/html':
                return file_get_contents($file['tmp_name']);
                
            case 'application/pdf':
                return $this->extractPdfContent($file['tmp_name']);
                
            case 'application/msword':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return $this->extractWordContent($file['tmp_name']);
                
            default:
                throw new Exception('Unsupported file type for content extraction: ' . $mimeType);
        }
    }
    
    /**
     * Extract content from PDF (basic implementation)
     */
    private function extractPdfContent(string $filePath): string {
        // This is a basic implementation - in production you might want to use a proper PDF library
        $command = "pdftotext " . escapeshellarg($filePath) . " -";
        $output = shell_exec($command);
        
        if ($output === null) {
            throw new Exception('Could not extract text from PDF');
        }
        
        return trim($output);
    }
    
    /**
     * Extract content from Word document (basic implementation)
     */
    private function extractWordContent(string $filePath): string {
        // This is a basic implementation - in production you might want to use a proper library
        if (strpos(mime_content_type($filePath), 'openxmlformats') !== false) {
            // DOCX file
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                $content = $zip->getFromName('word/document.xml');
                $zip->close();
                
                if ($content) {
                    // Simple XML parsing to extract text
                    $content = strip_tags($content);
                    return trim($content);
                }
            }
        }
        
        throw new Exception('Could not extract text from Word document');
    }
    
    /**
     * Process content and create embeddings
     */
    private function processContent(int $documentId, string $content, array $metadata = []): array {
        try {
            // Clean and prepare content
            $content = $this->cleanContent($content);
            
            // Split into chunks
            $chunks = $this->splitIntoChunks($content);
            
            if (empty($chunks)) {
                return ['success' => false, 'error' => 'No content chunks created'];
            }
            
            // Create embeddings for each chunk
            $embeddingIds = [];
            $batchSize = 5; // Process in batches to avoid API limits
            
            for ($i = 0; $i < count($chunks); $i += $batchSize) {
                $batch = array_slice($chunks, $i, $batchSize);
                
                foreach ($batch as $index => $chunk) {
                    $chunkIndex = $i + $index;
                    
                    // Generate embedding
                    $embedding = $this->llmClient->generateEmbedding($chunk);
                    
                    if ($embedding) {
                        // Store embedding
                        $embeddingId = $this->embeddingRepository->storeEmbedding(
                            $documentId,
                            $chunk,
                            $embedding,
                            $chunkIndex,
                            $metadata
                        );
                        
                        $embeddingIds[] = $embeddingId;
                    }
                }
                
                // Rate limiting - wait between batches
                if ($i + $batchSize < count($chunks)) {
                    usleep(100000); // 100ms delay
                }
            }
            
            return [
                'success' => true,
                'embeddings_count' => count($embeddingIds),
                'chunks_count' => count($chunks),
                'embedding_ids' => $embeddingIds
            ];
            
        } catch (Exception $e) {
            Logger::error('Content processing failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Clean content for processing
     */
    private function cleanContent(string $content): string {
        // Remove excessive whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Remove control characters
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        
        // Trim
        $content = trim($content);
        
        return $content;
    }
    
    /**
     * Split content into chunks
     */
    private function splitIntoChunks(string $content): array {
        $chunks = [];
        $contentLength = strlen($content);
        
        if ($contentLength <= $this->chunkSize) {
            return [$content];
        }
        
        $position = 0;
        
        while ($position < $contentLength) {
            $chunkEnd = min($position + $this->chunkSize, $contentLength);
            
            // Try to find a good breaking point (sentence, paragraph, or word boundary)
            if ($chunkEnd < $contentLength) {
                $breakPoint = $this->findBreakPoint($content, $position, $chunkEnd);
                if ($breakPoint > $position) {
                    $chunkEnd = $breakPoint;
                }
            }
            
            $chunk = substr($content, $position, $chunkEnd - $position);
            $chunk = trim($chunk);
            
            if (!empty($chunk)) {
                $chunks[] = $chunk;
            }
            
            // Move position with overlap
            $position = max($chunkEnd - $this->chunkOverlap, $position + 1);
        }
        
        return $chunks;
    }
    
    /**
     * Find good breaking point for chunks
     */
    private function findBreakPoint(string $content, int $start, int $end): int {
        // Look for sentence endings first
        for ($i = $end - 1; $i > $start + ($this->chunkSize / 2); $i--) {
            if (in_array($content[$i], ['.', '!', '?']) && 
                isset($content[$i + 1]) && 
                ctype_space($content[$i + 1])) {
                return $i + 1;
            }
        }
        
        // Look for paragraph breaks
        for ($i = $end - 1; $i > $start + ($this->chunkSize / 2); $i--) {
            if ($content[$i] === "\n" && 
                isset($content[$i + 1]) && 
                $content[$i + 1] === "\n") {
                return $i + 2;
            }
        }
        
        // Look for word boundaries
        for ($i = $end - 1; $i > $start + ($this->chunkSize / 2); $i--) {
            if (ctype_space($content[$i])) {
                return $i + 1;
            }
        }
        
        return $end;
    }
    
    /**
     * Get upload directory
     */
    private function getUploadDirectory(): string {
        $uploadDir = __DIR__ . '/../../uploads';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        return $uploadDir;
    }
    
    /**
     * Delete document and its embeddings
     */
    public function deleteDocument(int $documentId): bool {
        try {
            $this->db->beginTransaction();
            
            // Get document info
            $sql = "SELECT file_path FROM {$this->db->getTablePrefix()}documents WHERE id = ?";
            $document = $this->db->query($sql, [$documentId]);
            
            if (empty($document)) {
                $this->db->rollBack();
                return false;
            }
            
            // Delete embeddings
            $this->embeddingRepository->deleteDocumentEmbeddings($documentId);
            
            // Delete document record
            $sql = "DELETE FROM {$this->db->getTablePrefix()}documents WHERE id = ?";
            $this->db->execute($sql, [$documentId]);
            
            // Delete physical file
            $filePath = $document[0]['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            $this->db->commit();
            
            Logger::info('Document deleted', ['document_id' => $documentId]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            Logger::error('Failed to delete document', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get document statistics
     */
    public function getDocumentStats(): array {
        $sql = "SELECT 
                    COUNT(*) as total_documents,
                    COUNT(CASE WHEN status = 'processed' THEN 1 END) as processed_documents,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_documents,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_documents,
                    COALESCE(SUM(file_size), 0) as total_size
                FROM {$this->db->getTablePrefix()}documents";
        
        $result = $this->db->query($sql);
        return $result[0] ?? [];
    }
    
    /**
     * Get all documents
     */
    public function getAllDocuments(): array {
        $sql = "SELECT id, original_filename, file_size, mime_type, status, 
                       processed_at, created_at, metadata
                FROM {$this->db->getTablePrefix()}documents 
                ORDER BY created_at DESC";
        
        return $this->db->query($sql);
    }
}
?> 