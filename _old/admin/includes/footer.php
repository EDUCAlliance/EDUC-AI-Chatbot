        </div>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Admin Panel JavaScript -->
    <script>
        // Theme toggle
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const themeIcon = themeToggle.querySelector('i');
            
            // Load saved theme
            const savedTheme = localStorage.getItem('admin-theme') || 'light';
            htmlElement.setAttribute('data-bs-theme', savedTheme);
            updateThemeIcon(savedTheme);
            
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'light' ? 'dark' : 'light';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('admin-theme', newTheme);
                updateThemeIcon(newTheme);
            });
            
            function updateThemeIcon(theme) {
                themeIcon.className = theme === 'light' ? 'bi bi-moon' : 'bi bi-sun';
            }
            
            // Sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('adminSidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebarToggle.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('show');
                } else {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                }
            });
            
            // Close sidebar on outside click (mobile)
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && 
                    !sidebar.contains(e.target) && 
                    !sidebarToggle.contains(e.target) &&
                    sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                }
            });
            
            // Auto-refresh functionality
            if (window.autoRefreshInterval) {
                setInterval(function() {
                    if (typeof refreshData === 'function') {
                        refreshData();
                    }
                }, window.autoRefreshInterval * 1000);
            }
            
            // Global error handler
            window.addEventListener('error', function(e) {
                console.error('Global error:', e.error);
                showNotification('An error occurred. Please check the console for details.', 'error');
            });
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize popovers
            var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        });
        
        // Utility functions
        function showNotification(message, type = 'info', duration = 5000) {
            const alertClass = type === 'error' ? 'danger' : type;
            const alertHtml = `
                <div class="alert alert-${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999; max-width: 400px;">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', alertHtml);
            
            // Auto-remove after duration
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                if (alerts.length > 0) {
                    alerts[alerts.length - 1].remove();
                }
            }, duration);
        }
        
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
        
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }
        
        function timeAgo(timestamp) {
            const now = new Date();
            const past = new Date(timestamp);
            const diffInSeconds = Math.floor((now - past) / 1000);
            
            const intervals = [
                { label: 'year', seconds: 31536000 },
                { label: 'month', seconds: 2592000 },
                { label: 'day', seconds: 86400 },
                { label: 'hour', seconds: 3600 },
                { label: 'minute', seconds: 60 },
                { label: 'second', seconds: 1 }
            ];
            
            for (const interval of intervals) {
                const count = Math.floor(diffInSeconds / interval.seconds);
                if (count >= 1) {
                    return `${count} ${interval.label}${count > 1 ? 's' : ''} ago`;
                }
            }
            
            return 'just now';
        }
        
        // AJAX helper
        function ajaxRequest(url, options = {}) {
            const defaults = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };
            
            const config = { ...defaults, ...options };
            
            return fetch(url, config)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('AJAX Error:', error);
                    showNotification('Request failed: ' + error.message, 'error');
                    throw error;
                });
        }
        
        // Chart color schemes
        const chartColors = {
            primary: '#667eea',
            secondary: '#f093fb',
            success: '#4facfe',
            warning: '#43e97b',
            danger: '#f5576c',
            info: '#17a2b8',
            light: '#f8f9fa',
            dark: '#343a40'
        };
        
        // Loading state management
        function showLoading(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }
            if (element) {
                element.classList.add('loading-skeleton');
                element.style.pointerEvents = 'none';
            }
        }
        
        function hideLoading(element) {
            if (typeof element === 'string') {
                element = document.querySelector(element);
            }
            if (element) {
                element.classList.remove('loading-skeleton');
                element.style.pointerEvents = '';
            }
        }
        
        // Confirm dialog
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }
        
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                showNotification('Copied to clipboard!', 'success', 2000);
            }, function(err) {
                console.error('Could not copy text: ', err);
                showNotification('Failed to copy to clipboard', 'error');
            });
        }
        
        // Session timeout warning
        let sessionWarningShown = false;
        const sessionTimeout = 3600000; // 1 hour in milliseconds
        
        setTimeout(function() {
            if (!sessionWarningShown) {
                sessionWarningShown = true;
                showNotification(
                    'Your session will expire in 10 minutes. Please save your work.',
                    'warning',
                    10000
                );
            }
        }, sessionTimeout - 600000); // 10 minutes before expiry
    </script>
    
    <?php if (isset($additionalScripts) && is_array($additionalScripts)): ?>
        <?php foreach ($additionalScripts as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($inlineScript)): ?>
        <script>
            <?= $inlineScript ?>
        </script>
    <?php endif; ?>
</body>
</html> 