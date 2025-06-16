// Enhanced Logs Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const refreshBtn = document.getElementById('refreshBtn');
    const levelFilter = document.getElementById('levelFilter');
    const searchFilter = document.getElementById('searchFilter');
    const clearFilters = document.getElementById('clearFilters');
    const expandAllBtn = document.getElementById('expandAllBtn');
    const collapseAllBtn = document.getElementById('collapseAllBtn');
    const visibleCount = document.getElementById('visibleCount');
    const logsTable = document.getElementById('logsTable');
    
    let filteredEntries = [];
    let allEntries = [];
    let autoRefreshInterval;

    // Initialize
    init();

    function init() {
        getAllEntries();
        setupEventListeners();
        setupAutoRefresh();
        updateVisibleCount();
    }

    function getAllEntries() {
        if (logsTable) {
            allEntries = Array.from(logsTable.querySelectorAll('.log-entry'));
            filteredEntries = [...allEntries];
        }
    }

    function setupEventListeners() {
        // Filter events
        if (levelFilter) {
            levelFilter.addEventListener('change', applyFilters);
        }
        
        if (searchFilter) {
            searchFilter.addEventListener('input', debounce(applyFilters, 300));
        }

        if (clearFilters) {
            clearFilters.addEventListener('click', function() {
                levelFilter.value = '';
                searchFilter.value = '';
                applyFilters();
                searchFilter.focus();
            });
        }

        // Expand/Collapse buttons
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function() {
                toggleAllContexts(true);
            });
        }

        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function() {
                toggleAllContexts(false);
            });
        }

        // Refresh button
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                this.classList.add('is-loading');
                window.location.reload();
            });
        }

        // Context toggle buttons
        document.querySelectorAll('.context-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const contextPanel = document.getElementById(targetId);
                const icon = this.querySelector('svg polyline');
                const text = this.querySelector('span:last-child');
                
                if (contextPanel) {
                    const isHidden = contextPanel.classList.contains('is-hidden');
                    
                    if (isHidden) {
                        contextPanel.classList.remove('is-hidden');
                        text.textContent = 'Hide Context';
                        icon.setAttribute('points', '18,15 12,9 6,15');
                        this.classList.add('is-active');
                    } else {
                        contextPanel.classList.add('is-hidden');
                        text.textContent = 'Show Context';
                        icon.setAttribute('points', '6,9 12,15 18,9');
                        this.classList.remove('is-active');
                    }
                }
            });
        });

        // Copy to clipboard functionality
        document.querySelectorAll('.copy-log').forEach(button => {
            button.addEventListener('click', function() {
                const logText = this.getAttribute('data-log');
                const icon = this.querySelector('svg');
                
                navigator.clipboard.writeText(logText).then(() => {
                    // Change icon to checkmark
                    icon.innerHTML = '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>';
                    icon.setAttribute('stroke', 'currentColor');
                    this.classList.add('has-text-success');
                    
                    // Show tooltip
                    showTooltip(this, 'Copied!');
                    
                    // Reset after 2 seconds
                    setTimeout(() => {
                        icon.innerHTML = '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>';
                        this.classList.remove('has-text-success');
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy: ', err);
                    showTooltip(this, 'Failed to copy', 'error');
                });
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                if (searchFilter) {
                    searchFilter.focus();
                    searchFilter.select();
                }
            }
            
            // Escape to clear search
            if (e.key === 'Escape' && searchFilter && searchFilter.value) {
                searchFilter.value = '';
                applyFilters();
            }
        });
    }

    function applyFilters() {
        const levelValue = levelFilter ? levelFilter.value.toLowerCase() : '';
        const searchValue = searchFilter ? searchFilter.value.toLowerCase() : '';
        
        filteredEntries = allEntries.filter(entry => {
            const level = entry.getAttribute('data-level').toLowerCase();
            const message = entry.getAttribute('data-message').toLowerCase();
            
            const levelMatch = !levelValue || level === levelValue;
            const searchMatch = !searchValue || message.includes(searchValue);
            
            return levelMatch && searchMatch;
        });

        // Show/hide entries
        allEntries.forEach(entry => {
            if (filteredEntries.includes(entry)) {
                entry.style.display = '';
            } else {
                entry.style.display = 'none';
            }
        });

        updateVisibleCount();
        highlightSearchTerms(searchValue);
    }

    function highlightSearchTerms(searchTerm) {
        if (!searchTerm) {
            // Remove existing highlights
            document.querySelectorAll('.search-highlight').forEach(el => {
                const parent = el.parentNode;
                parent.replaceChild(document.createTextNode(el.textContent), el);
                parent.normalize();
            });
            return;
        }

        // Add highlights to visible entries
        filteredEntries.forEach(entry => {
            const messageDiv = entry.querySelector('.log-message');
            if (messageDiv) {
                highlightText(messageDiv, searchTerm);
            }
        });
    }

    function highlightText(element, term) {
        const text = element.textContent;
        const regex = new RegExp(`(${escapeRegExp(term)})`, 'gi');
        const highlightedText = text.replace(regex, '<mark class="search-highlight has-background-warning">$1</mark>');
        element.innerHTML = highlightedText;
    }

    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function updateVisibleCount() {
        if (visibleCount) {
            const count = filteredEntries.length;
            const total = allEntries.length;
            
            if (count === total) {
                visibleCount.textContent = `${count} entries`;
                visibleCount.className = 'tag is-light';
            } else {
                visibleCount.textContent = `${count} of ${total} entries`;
                visibleCount.className = 'tag is-warning';
            }
        }
    }

    function toggleAllContexts(expand) {
        document.querySelectorAll('.context-toggle').forEach(button => {
            const targetId = button.getAttribute('data-target');
            const contextPanel = document.getElementById(targetId);
            const icon = button.querySelector('svg polyline');
            const text = button.querySelector('span:last-child');
            
            if (contextPanel) {
                if (expand) {
                    contextPanel.classList.remove('is-hidden');
                    text.textContent = 'Hide Context';
                    icon.setAttribute('points', '18,15 12,9 6,15');
                    button.classList.add('is-active');
                } else {
                    contextPanel.classList.add('is-hidden');
                    text.textContent = 'Show Context';
                    icon.setAttribute('points', '6,9 12,15 18,9');
                    button.classList.remove('is-active');
                }
            }
        });

        // Update button states
        if (expand) {
            expandAllBtn.classList.add('is-active');
            collapseAllBtn.classList.remove('is-active');
        } else {
            expandAllBtn.classList.remove('is-active');
            collapseAllBtn.classList.add('is-active');
        }
    }

    function setupAutoRefresh() {
        // Auto-refresh every 30 seconds
        autoRefreshInterval = setInterval(() => {
            // Only refresh if no filters are active and user hasn't interacted recently
            const hasFilters = (levelFilter && levelFilter.value) || (searchFilter && searchFilter.value);
            const lastInteraction = window.lastUserInteraction || 0;
            const timeSinceInteraction = Date.now() - lastInteraction;
            
            if (!hasFilters && timeSinceInteraction > 10000) { // 10 seconds
                window.location.reload();
            }
        }, 30000);

        // Track user interactions to prevent unwanted refreshes
        ['click', 'keydown', 'scroll'].forEach(event => {
            document.addEventListener(event, () => {
                window.lastUserInteraction = Date.now();
            });
        });
    }

    function showTooltip(element, message, type = 'success') {
        // Create tooltip
        const tooltip = document.createElement('div');
        tooltip.className = `notification is-${type} is-small tooltip-notification`;
        tooltip.textContent = message;
        tooltip.style.cssText = `
            position: fixed;
            z-index: 9999;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.75rem;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        `;
        
        document.body.appendChild(tooltip);
        
        // Position tooltip
        const rect = element.getBoundingClientRect();
        tooltip.style.left = `${rect.left + rect.width / 2 - tooltip.offsetWidth / 2}px`;
        tooltip.style.top = `${rect.top - tooltip.offsetHeight - 5}px`;
        
        // Show tooltip
        requestAnimationFrame(() => {
            tooltip.style.opacity = '1';
        });
        
        // Remove tooltip after delay
        setTimeout(() => {
            tooltip.style.opacity = '0';
            setTimeout(() => {
                if (tooltip.parentNode) {
                    document.body.removeChild(tooltip);
                }
            }, 200);
        }, 2000);
    }

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    });

    // Expose some functions globally for debugging
    window.LogsManager = {
        applyFilters,
        toggleAllContexts,
        updateVisibleCount,
        getAllEntries
    };
}); 