class DocumentUploader {
    constructor() {
        this.initializeElements();
        this.attachEventListeners();
        this.currentXHR = null;
        this.currentEventSource = null;
        this.processingDocId = null;
        this.initializeModalState();
    }

    initializeElements() {
        // File input elements
        this.documentInput = document.getElementById('documentInput');
        this.fileName = document.getElementById('fileName');
        this.uploadBtn = document.getElementById('uploadBtn');
        this.cancelBtn = document.getElementById('cancelBtn');
        this.clearBtn = document.getElementById('clearBtn');
        
        // Progress elements
        this.uploadProgress = document.getElementById('uploadProgress');
        this.uploadProgressBar = document.getElementById('uploadProgressBar');
        this.uploadPercent = document.getElementById('uploadPercent');
        this.uploadStatus = document.getElementById('uploadStatus');
        
        this.embeddingProgress = document.getElementById('embeddingProgress');
        this.embeddingProgressBar = document.getElementById('embeddingProgressBar');
        this.embeddingPercent = document.getElementById('embeddingPercent');
        this.embeddingStatus = document.getElementById('embeddingStatus');
        
        // Message elements
        this.successMessage = document.getElementById('successMessage');
        this.successDetails = document.getElementById('successDetails');
        this.errorMessage = document.getElementById('errorMessage');
        this.errorDetails = document.getElementById('errorDetails');
        
        // Table elements
        this.documentsTable = document.getElementById('documentsTable');
        this.noDocsRow = document.getElementById('noDocsRow');
        
        // Modal elements
        this.deleteModal = document.getElementById('deleteModal');
        this.deleteFileName = document.getElementById('deleteFileName');
        this.confirmDelete = document.getElementById('confirmDelete');
        this.closeDeleteModal = document.getElementById('closeDeleteModal');
        this.cancelDelete = document.getElementById('cancelDelete');
        
        // Drag & Drop
        this.dragDropZone = document.getElementById('dragDropZone');
        this.fileSelector = document.getElementById('fileSelector');
    }

    initializeModalState() {
        // Ensure modal is hidden on page load
        if (this.deleteModal) {
            this.deleteModal.classList.remove('is-active');
        }
    }

    attachEventListeners() {
        // File selection
        this.documentInput.addEventListener('change', (e) => this.handleFileSelect(e));
        
        // Upload actions
        this.uploadBtn.addEventListener('click', () => this.startUpload());
        this.cancelBtn.addEventListener('click', () => this.cancelUpload());
        this.clearBtn.addEventListener('click', () => this.clearSelection());
        
        // Message dismissal
        const dismissSuccess = document.getElementById('dismissSuccess');
        const dismissError = document.getElementById('dismissError');
        
        if (dismissSuccess) {
            dismissSuccess.addEventListener('click', () => this.hideSuccess());
        }
        if (dismissError) {
            dismissError.addEventListener('click', () => this.hideError());
        }
        
        // Delete functionality
        this.documentsTable.addEventListener('click', (e) => this.handleTableClick(e));
        
        // Modal close events
        if (this.closeDeleteModal) {
            this.closeDeleteModal.addEventListener('click', () => this.hideDeleteModal());
        }
        if (this.cancelDelete) {
            this.cancelDelete.addEventListener('click', () => this.hideDeleteModal());
        }
        if (this.confirmDelete) {
            this.confirmDelete.addEventListener('click', () => this.executeDelete());
        }
        
        // Close modal when clicking outside
        if (this.deleteModal) {
            this.deleteModal.addEventListener('click', (e) => {
                if (e.target === this.deleteModal || e.target.classList.contains('modal-background')) {
                    this.hideDeleteModal();
                }
            });
        }
        
        // Escape key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.deleteModal && this.deleteModal.classList.contains('is-active')) {
                this.hideDeleteModal();
            }
        });
        
        // Drag & Drop
        this.setupDragAndDrop();
    }

    setupDragAndDrop() {
        const dropZone = this.fileSelector;
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, this.preventDefaults, false);
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
        });
        
        dropZone.addEventListener('drop', (e) => this.handleDrop(e), false);
    }

    preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length > 0) {
            this.documentInput.files = files;
            this.handleFileSelect({ target: { files } });
        }
    }

    handleFileSelect(e) {
        const file = e.target.files[0];
        
        if (file) {
            this.fileName.textContent = file.name;
            this.uploadBtn.disabled = false;
            this.clearBtn.style.display = 'inline-flex';
            
            // Validate file type
            const allowedTypes = ['.pdf', '.txt', '.doc', '.docx', '.md'];
            const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
            
            if (!allowedTypes.includes(fileExtension)) {
                this.showError('Unsupported file type. Please select a PDF, TXT, DOC, DOCX, or MD file.');
                this.clearSelection();
                return;
            }
            
            // Check file size (10MB limit)
            if (file.size > 10 * 1024 * 1024) {
                this.showError('File size too large. Please select a file smaller than 10MB.');
                this.clearSelection();
                return;
            }
            
            this.hideMessages();
        }
    }

    clearSelection() {
        this.documentInput.value = '';
        this.fileName.textContent = 'No file selected';
        this.uploadBtn.disabled = true;
        this.clearBtn.style.display = 'none';
        this.hideMessages();
        this.hideProgress();
    }

    async startUpload() {
        const file = this.documentInput.files[0];
        if (!file) return;

        this.hideMessages();
        this.showUploadProgress();
        this.setUploadState(true);

        const formData = new FormData();
        formData.append('document', file);

        this.currentXHR = new XMLHttpRequest();

        // Upload progress
        this.currentXHR.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                this.updateUploadProgress(percentComplete, `Uploading ${file.name}... ${this.formatBytes(e.loaded)} / ${this.formatBytes(e.total)}`);
            }
        });

        // Upload complete
        this.currentXHR.addEventListener('load', () => {
            if (this.currentXHR.status === 200) {
                try {
                    const response = JSON.parse(this.currentXHR.responseText);
                    if (response.success) {
                        this.updateUploadProgress(100, 'Upload complete! Starting document processing...');
                        this.processingDocId = response.doc_id;
                        this.startEmbeddingProgress(response.doc_id);
                    } else {
                        this.showError(response.error || 'Upload failed');
                        this.setUploadState(false);
                    }
                } catch (e) {
                    this.showError('Invalid server response');
                    this.setUploadState(false);
                }
            } else {
                this.showError('Upload failed with status: ' + this.currentXHR.status);
                this.setUploadState(false);
            }
        });

        // Upload error
        this.currentXHR.addEventListener('error', () => {
            this.showError('Upload failed due to network error');
            this.setUploadState(false);
        });

        // Start upload
        this.currentXHR.open('POST', this.getUploadUrl());
        this.currentXHR.send(formData);
    }

    startEmbeddingProgress(docId) {
        this.showEmbeddingProgress();
        this.updateEmbeddingProgress(0, 'Initializing document processing...');

        // Start Server-Sent Events for real-time progress
        this.currentEventSource = new EventSource(`${this.getBaseUrl()}/embedding-progress/${docId}`);

        this.currentEventSource.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleEmbeddingUpdate(data);
            } catch (e) {
                console.error('Invalid progress data:', event.data);
            }
        };

        this.currentEventSource.onerror = (error) => {
            console.error('EventSource failed:', error);
            this.currentEventSource?.close();
            this.currentEventSource = null;
            
            // Fallback to polling if SSE fails
            this.startProgressPolling(docId);
        };
    }

    handleEmbeddingUpdate(data) {
        const { progress, status, completed, error, stats } = data;

        if (error) {
            this.showError(error);
            this.setUploadState(false);
            this.currentEventSource?.close();
            return;
        }

        this.updateEmbeddingProgress(progress, status);

        if (completed) {
            this.updateEmbeddingProgress(100, 'Processing complete!');
            setTimeout(() => {
                this.hideProgress();
                this.showSuccess(stats);
                this.addDocumentToTable(data.document);
                this.clearSelection();
                this.setUploadState(false);
            }, 1000);
            
            this.currentEventSource?.close();
            this.currentEventSource = null;
        }
    }

    async startProgressPolling(docId) {
        const pollProgress = async () => {
            try {
                const response = await fetch(`${this.getBaseUrl()}/embedding-status/${docId}`);
                const data = await response.json();
                
                this.handleEmbeddingUpdate(data);
                
                if (!data.completed && !data.error) {
                    setTimeout(pollProgress, 1000);
                }
            } catch (error) {
                console.error('Polling error:', error);
                this.showError('Failed to get processing status');
                this.setUploadState(false);
            }
        };
        
        pollProgress();
    }

    cancelUpload() {
        if (this.currentXHR) {
            this.currentXHR.abort();
            this.currentXHR = null;
        }
        
        if (this.currentEventSource) {
            this.currentEventSource.close();
            this.currentEventSource = null;
        }
        
        if (this.processingDocId) {
            // Cancel server-side processing if possible
            fetch(`${this.getBaseUrl()}/cancel-processing/${this.processingDocId}`, { method: 'POST' })
                .catch(e => console.warn('Failed to cancel processing:', e));
        }
        
        this.hideProgress();
        this.setUploadState(false);
        this.clearSelection();
    }

    showUploadProgress() {
        this.uploadProgress.style.display = 'block';
        this.updateUploadProgress(0, 'Preparing upload...');
    }

    showEmbeddingProgress() {
        this.embeddingProgress.style.display = 'block';
        this.updateEmbeddingProgress(0, 'Preparing document processing...');
    }

    updateUploadProgress(percent, status) {
        this.uploadProgressBar.value = percent;
        this.uploadPercent.textContent = `${percent}%`;
        this.uploadStatus.textContent = status;
    }

    updateEmbeddingProgress(percent, status) {
        this.embeddingProgressBar.value = percent;
        this.embeddingPercent.textContent = `${percent}%`;
        this.embeddingStatus.textContent = status;
    }

    hideProgress() {
        this.uploadProgress.style.display = 'none';
        this.embeddingProgress.style.display = 'none';
    }

    setUploadState(uploading) {
        this.uploadBtn.style.display = uploading ? 'none' : 'inline-flex';
        this.cancelBtn.style.display = uploading ? 'inline-flex' : 'none';
        this.documentInput.disabled = uploading;
    }

    showSuccess(stats) {
        this.successMessage.style.display = 'block';
        this.successDetails.textContent = `Created ${stats?.chunks || 0} text chunks with ${stats?.embeddings || 0} embeddings`;
    }

    showError(message) {
        this.errorMessage.style.display = 'block';
        this.errorDetails.textContent = message;
    }

    hideSuccess() {
        this.successMessage.style.display = 'none';
    }

    hideError() {
        this.errorMessage.style.display = 'none';
    }

    hideMessages() {
        this.hideSuccess();
        this.hideError();
    }

    handleTableClick(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const deleteBtn = e.target.closest('.delete-doc-btn');
        if (deleteBtn) {
            const docId = deleteBtn.dataset.docId;
            const filename = deleteBtn.dataset.filename;
            
            if (docId && filename) {
                this.showDeleteModal(docId, filename);
            }
        }
    }

    showDeleteModal(docId, filename) {
        if (!this.deleteModal || !this.deleteFileName || !this.confirmDelete) {
            console.error('Modal elements not found');
            return;
        }
        
        this.deleteFileName.textContent = filename;
        this.confirmDelete.dataset.docId = docId;
        this.deleteModal.classList.add('is-active');
        
        // Add body class to prevent scrolling
        document.body.classList.add('modal-open');
    }

    hideDeleteModal() {
        if (this.deleteModal) {
            this.deleteModal.classList.remove('is-active');
            document.body.classList.remove('modal-open');
        }
        
        // Clear the stored document ID
        if (this.confirmDelete) {
            delete this.confirmDelete.dataset.docId;
        }
    }

    async executeDelete() {
        if (!this.confirmDelete || !this.confirmDelete.dataset.docId) {
            this.showError('No document selected for deletion');
            return;
        }
        
        const docId = this.confirmDelete.dataset.docId;
        
        // Disable the delete button to prevent double-clicks
        const originalText = this.confirmDelete.textContent;
        this.confirmDelete.disabled = true;
        this.confirmDelete.textContent = 'Deleting...';
        
        try {
            const response = await fetch(`${this.getBaseUrl()}/documents/delete`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ doc_id: parseInt(docId) })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.removeDocumentFromTable(docId);
                this.hideDeleteModal();
                this.showSuccess({ message: 'Document deleted successfully' });
                
                // Auto-hide success message after 3 seconds
                setTimeout(() => this.hideSuccess(), 3000);
            } else {
                throw new Error(data.error || 'Failed to delete document');
            }
        } catch (error) {
            console.error('Delete error:', error);
            this.showError(`Error deleting document: ${error.message}`);
        } finally {
            // Re-enable the delete button
            if (this.confirmDelete) {
                this.confirmDelete.disabled = false;
                this.confirmDelete.textContent = originalText;
            }
        }
    }

    addDocumentToTable(document) {
        // Remove "no documents" row if it exists
        if (this.noDocsRow) {
            this.noDocsRow.remove();
        }

        const row = this.createDocumentRow(document);
        this.documentsTable.insertBefore(row, this.documentsTable.firstChild);
    }

    removeDocumentFromTable(docId) {
        const row = this.documentsTable.querySelector(`tr[data-doc-id="${docId}"]`);
        if (row) {
            row.remove();
            
            // Add "no documents" row if table is empty
            if (this.documentsTable.children.length === 0) {
                const noDocsRow = document.createElement('tr');
                noDocsRow.id = 'noDocsRow';
                noDocsRow.innerHTML = '<td colspan="4" class="has-text-centered has-text-grey">No documents have been uploaded yet.</td>';
                this.documentsTable.appendChild(noDocsRow);
                this.noDocsRow = noDocsRow;
            }
        }
    }

    createDocumentRow(document) {
        const row = document.createElement('tr');
        row.dataset.docId = document.id;
        row.innerHTML = `
            <td>
                <span class="icon-text">
                    <span class="icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                        </svg>
                    </span>
                    <span>${this.escapeHtml(document.filename)}</span>
                </span>
            </td>
            <td>
                <span class="tag is-success is-light">
                    <span class="icon">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20,6 9,17 4,12"/>
                        </svg>
                    </span>
                    <span>Ready</span>
                </span>
            </td>
            <td>${new Date(document.created_at).toLocaleString()}</td>
            <td>
                <button type="button" class="button is-danger is-small delete-doc-btn" data-doc-id="${document.id}" data-filename="${this.escapeHtml(document.filename)}">
                    <span class="icon">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3,6 5,6 21,6"/>
                            <path d="m19,6 v14 a2,2 0 0,1 -2,2 H7 a2,2 0 0,1 -2,-2 V6 m3,0 V4 a2,2 0 0,1 2,-2 h4 a2,2 0 0,1 2,2 v2"/>
                        </svg>
                    </span>
                    <span>Delete</span>
                </button>
            </td>
        `;
        return row;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    getBaseUrl() {
        return window.location.pathname.replace(/\/+$/, '');
    }

    getUploadUrl() {
        return `${this.getBaseUrl()}/documents/upload-async`;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new DocumentUploader();
}); 