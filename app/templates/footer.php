<?php
// templates/footer.php
declare(strict_types=1);

?>
</div> <!-- End of .container-fluid in sidebar.php -->

<!-- Session Expiration Modal -->
<div class="modal fade" id="sessionExpiryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Session About to Expire</h5>
            </div>
            <div class="modal-body">
                <p>Your session will expire in <span id="countdown">5:00</span> minutes.</p>
                <p>Would you like to extend your session?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="logout()">Logout</button>
                <button type="button" class="btn btn-primary" onclick="extendSession()">Extend Session</button>
            </div>
        </div>
    </div>
</div>

</main> <!-- End of main from sidebar.php -->
</div> <!-- End of .row from header.php -->
</div> <!-- End of .container-fluid from header.php -->

<!-- Scripts -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

<!-- Bootstrap 5 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Chart.js - Use a specific version without source map issues -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Page-specific JavaScript -->
<?php if (isset($jsFiles)): ?>
    <?php foreach ($jsFiles as $jsFile): ?>
        <script src="<?php echo asset_url("js/{$jsFile}"); ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Global JavaScript -->
<script>
    // Global variables
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';
    const USER_ID = '<?php echo $_SESSION['user_id'] ?? 0; ?>';
    const COMPANY_CURRENCY = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';

    // Notification system
    function showNotification(message, type = 'info') {
        const types = {
            'success': { icon: 'check-circle', color: 'success' },
            'error': { icon: 'exclamation-circle', color: 'danger' },
            'warning': { icon: 'exclamation-triangle', color: 'warning' },
            'info': { icon: 'info-circle', color: 'info' }
        };

        const config = types[type] || types.info;
        const toastId = 'toast-' + Date.now();

        const toastHtml = `
        <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-${config.color} text-white">
                <i class="fas fa-${config.icon} me-2"></i>
                <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;

        $('#toast-container').append(toastHtml);
        const toast = new bootstrap.Toast(document.getElementById(toastId));
        toast.show();

        document.getElementById(toastId).addEventListener('hidden.bs.toast', function () {
            this.remove();
        });
    }

    // Loading spinner control
    function showLoading() {
        $('#loading-spinner').removeClass('d-none');
    }

    function hideLoading() {
        $('#loading-spinner').addClass('d-none');
    }

    // Session management
    let sessionTimer;
    function startSessionTimer() {
        clearTimeout(sessionTimer);

        const warningTime = 5 * 60 * 1000;
        const expiryTime = 8 * 60 * 60 * 1000;

        sessionTimer = setTimeout(() => {
            const modal = new bootstrap.Modal(document.getElementById('sessionExpiryModal'));
            modal.show();

            let countdown = 300;
            const countdownElement = document.getElementById('countdown');

            const countdownInterval = setInterval(() => {
                const minutes = Math.floor(countdown / 60);
                const seconds = countdown % 60;
                countdownElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;

                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    logout();
                }
                countdown--;
            }, 1000);
        }, expiryTime - warningTime);
    }

    function extendSession() {
        fetch(`${BASE_URL}api/session/extend`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    $('#sessionExpiryModal').modal('hide');
                    startSessionTimer();
                    showNotification('Session extended successfully', 'success');
                }
            });
    }

    function logout() {
        window.location.href = `${BASE_URL}auth/logout.php`;
    }

    // Theme toggle
    $('#toggle-theme').on('click', function () {
        const currentTheme = document.documentElement.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        document.documentElement.setAttribute('data-bs-theme', newTheme);
        $(this).find('i').toggleClass('fa-moon fa-sun');
        localStorage.setItem('theme', newTheme);
        showNotification(`Theme changed to ${newTheme} mode`, 'info');
    });

    // Initialize theme
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-bs-theme', savedTheme);
    $('#toggle-theme i').toggleClass(
        savedTheme === 'dark' ? 'fa-moon' : 'fa-sun',
        savedTheme === 'dark' ? 'fa-sun' : 'fa-moon'
    );

    // AJAX setup
    $.ajaxSetup({
        beforeSend: function (xhr) {
            xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
        },
        error: function (xhr, status, error) {
            hideLoading();

            if (xhr.status === 401) {
                showNotification('Your session has expired. Please login again.', 'error');
                setTimeout(() => logout(), 2000);
            } else if (xhr.status === 403) {
                showNotification('You do not have permission to perform this action.', 'error');
            } else if (xhr.status === 404) {
                showNotification('Requested resource not found.', 'error');
            } else if (xhr.status === 500) {
                showNotification('Server error occurred. Please try again later.', 'error');
            } else {
                showNotification('An error occurred: ' + error, 'error');
            }
        }
    });

    // Initialize DataTables
    $(document).ready(function () {
        $('table.data-table').DataTable({
            pageLength: 25,
            responsive: true,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });

        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });

        startSessionTimer();

        $(document).on('mousemove keydown click scroll', function () {
            startSessionTimer();
        });
    });

    // Display flash messages
    <?php if ($flashMessage = SessionManager::flash('success')): ?>
        showNotification('<?php echo addslashes($flashMessage); ?>', 'success');
    <?php endif; ?>

    <?php if ($flashMessage = SessionManager::flash('error')): ?>
        showNotification('<?php echo addslashes($flashMessage); ?>', 'error');
    <?php endif; ?>

    <?php if ($flashMessage = SessionManager::flash('warning')): ?>
        showNotification('<?php echo addslashes($flashMessage); ?>', 'warning');
    <?php endif; ?>

    <?php if ($flashMessage = SessionManager::flash('info')): ?>
        showNotification('<?php echo addslashes($flashMessage); ?>', 'info');
    <?php endif; ?>

    // Notifications
    let notificationCheckInterval;

    function loadNotifications() {
        fetch('./api/notifications/get.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationBadge(data.unread_count);
                    renderNotificationList(data.notifications);
                }
            })
            .catch(error => console.error('Error loading notifications:', error));
    }

    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    function renderNotificationList(notifications) {
        const list = document.getElementById('notificationList');

        if (!list) return;

        if (!notifications || notifications.length === 0) {
            list.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                <p class="text-muted small mb-0">No new notifications</p>
            </div>
        `;
            return;
        }

        let html = '';
        notifications.forEach(notif => {
            const icon = getNotificationIcon(notif.type);
            const timeAgo = timeAgo(notif.created_at);

            html += `
            <a href="${notif.link || '#'}" class="dropdown-item notification-item ${notif.is_read ? '' : 'unread'}" 
               data-id="${notif.id}" onclick="markAsRead(${notif.id}); return false;">
                <div class="d-flex">
                    <div class="me-3">
                        <i class="${icon.icon} ${icon.color}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <strong class="small">${escapeHtml(notif.title)}</strong>
                            <small class="text-muted ms-2">${timeAgo}</small>
                        </div>
                        <p class="small text-muted mb-0">${escapeHtml(notif.message)}</p>
                    </div>
                </div>
            </a>
        `;
        });

        list.innerHTML = html;
    }

    function getNotificationIcon(type) {
        const icons = {
            'low_stock': { icon: 'fas fa-exclamation-triangle', color: 'text-warning' },
            'overdue_invoice': { icon: 'fas fa-clock', color: 'text-danger' },
            'upcoming_invoice': { icon: 'fas fa-hourglass-half', color: 'text-info' },
            'pending_order': { icon: 'fas fa-truck', color: 'text-warning' },
            'sale_completed': { icon: 'fas fa-shopping-cart', color: 'text-success' },
            'order_received': { icon: 'fas fa-box', color: 'text-success' },
            'new_customer': { icon: 'fas fa-user-plus', color: 'text-primary' },
            'backup_reminder': { icon: 'fas fa-database', color: 'text-info' },
            'system_alert': { icon: 'fas fa-exclamation-circle', color: 'text-warning' }
        };

        return icons[type] || { icon: 'fas fa-bell', color: 'text-secondary' };
    }

    function markAsRead(id) {
        fetch('./api/notifications/mark-read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                }
            })
            .catch(error => console.error('Error marking notification as read:', error));
    }

    function markAllAsRead() {
        fetch('./api/notifications/mark-all-read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                    showNotification('All notifications marked as read', 'success');
                }
            })
            .catch(error => console.error('Error marking all notifications as read:', error));
    }

    function timeAgo(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';
        return date.toLocaleDateString();
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Load notifications
    document.addEventListener('DOMContentLoaded', function () {
        loadNotifications();
        notificationCheckInterval = setInterval(loadNotifications, 60000);
    });

    window.addEventListener('beforeunload', function () {
        if (notificationCheckInterval) {
            clearInterval(notificationCheckInterval);
        }
    });
</script>

<!-- Scroll to top button -->
<button class="scroll-to-top" id="scrollToTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Quick Search Functionality (Always Visible) -->
<script>
$(document).ready(function() {
    const searchInput = $('#quickSearchInput');
    const searchBtn = $('#quickSearchBtn');
    const resultsContainer = $('#quickSearchResults');
    let searchTimeout;
    
    // Function to perform search
    function performSearch() {
        const term = searchInput.val().trim();
        
        if (term.length < 2) {
            resultsContainer.hide();
            return;
        }
        
        // Show loading state
        resultsContainer.html('<div class="text-center p-4"><div class="spinner-border spinner-border-sm text-primary"></div><p class="text-muted small mt-2">Searching...</p></div>');
        resultsContainer.show();
        
        // Perform AJAX search
        $.ajax({
            url: `${BASE_URL}api/search/search.php`,
            method: 'GET',
            data: { term: term },
            dataType: 'json',
            success: function(data) {
                renderSearchResults(data, term);
            },
            error: function(xhr, status, error) {
                console.error('Search error:', error);
                resultsContainer.html('<div class="text-center p-4 text-danger"><i class="fas fa-exclamation-circle"></i><p class="small mt-2">Search failed. Please try again.</p></div>');
            }
        });
    }
    
    // Render search results
    function renderSearchResults(data, term) {
        if (!data || (!data.products && !data.customers && !data.invoices && !data.products?.length && !data.customers?.length && !data.invoices?.length)) {
            resultsContainer.html(`
                <div class="no-results">
                    <i class="fas fa-search fa-2x text-muted mb-2"></i>
                    <p>No results found for "${escapeHtml(term)}"</p>
                    <small class="text-muted">Try searching with different keywords</small>
                </div>
            `);
            return;
        }
        
        let html = '';
        
        // Products section
        if (data.products && data.products.length > 0) {
            html += `
                <div class="search-section">
                    <div class="search-section-title">
                        <i class="fas fa-box me-1"></i> Products (${data.products.length})
                    </div>
            `;
            data.products.forEach(product => {
                html += `
                    <a href="?page=products/view&id=${product.id}" class="search-item">
                        <div class="search-item-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="search-item-info">
                            <div class="search-item-title">${escapeHtml(product.product_name)}</div>
                            <div class="search-item-subtitle">SKU: ${escapeHtml(product.sku || 'N/A')}</div>
                        </div>
                        <div class="search-item-badge badge bg-info">Stock: ${product.total_stock || 0}</div>
                    </a>
                `;
            });
            html += `</div>`;
        }
        
        // Customers section
        if (data.customers && data.customers.length > 0) {
            html += `
                <div class="search-section">
                    <div class="search-section-title">
                        <i class="fas fa-users me-1"></i> Customers (${data.customers.length})
                    </div>
            `;
            data.customers.forEach(customer => {
                html += `
                    <a href="?page=sales/customers&id=${customer.id}" class="search-item">
                        <div class="search-item-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="search-item-info">
                            <div class="search-item-title">${escapeHtml(customer.full_name)}</div>
                            <div class="search-item-subtitle">${escapeHtml(customer.phone || customer.email || 'No contact')}</div>
                        </div>
                        <div class="search-item-badge badge bg-success">${escapeHtml(customer.customer_code)}</div>
                    </a>
                `;
            });
            html += `</div>`;
        }
        
        // Invoices section
        if (data.invoices && data.invoices.length > 0) {
            html += `
                <div class="search-section">
                    <div class="search-section-title">
                        <i class="fas fa-file-invoice me-1"></i> Invoices (${data.invoices.length})
                    </div>
            `;
            data.invoices.forEach(invoice => {
                const statusClass = invoice.status === 'paid' ? 'bg-success' : (invoice.status === 'overdue' ? 'bg-danger' : 'bg-warning');
                html += `
                    <a href="?page=sales/view-invoice&id=${invoice.id}" class="search-item">
                        <div class="search-item-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="search-item-info">
                            <div class="search-item-title">${escapeHtml(invoice.invoice_number)}</div>
                            <div class="search-item-subtitle">${escapeHtml(invoice.customer_name)} - ${parseFloat(invoice.total_amount).toLocaleString()} ${COMPANY_CURRENCY}</div>
                        </div>
                        <div class="search-item-badge badge ${statusClass}">${escapeHtml(invoice.status)}</div>
                    </a>
                `;
            });
            html += `</div>`;
        }
        
        if (html === '') {
            html = `
                <div class="no-results">
                    <i class="fas fa-search fa-2x text-muted mb-2"></i>
                    <p>No results found for "${escapeHtml(term)}"</p>
                </div>
            `;
        }
        
        resultsContainer.html(html);
    }
    
    // Input event with debounce
    searchInput.on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    });
    
    // Search button click
    if (searchBtn.length) {
        searchBtn.on('click', performSearch);
    }
    
    // Enter key press
    searchInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            performSearch();
        }
    });
    
    // Close results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.quick-search-wrapper').length) {
            resultsContainer.hide();
        }
    });
    
    // Prevent hiding when clicking inside results
    resultsContainer.on('click', function(e) {
        e.stopPropagation();
    });
    
    // Keyboard shortcut (Ctrl+K) to focus search
    $(document).on('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
    });
});
</script>

<!-- Sidebar and Content Height Script -->
<script>
    (function () {
        'use strict';

        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const collapseBtn = document.getElementById('sidebarCollapseBtn');
            
            // Function to check if sidebar is collapsed
            function isSidebarCollapsed() {
                return sidebar && sidebar.classList.contains('sidebar-collapsed');
            }
            
            // Function to save state to cookie (DESKTOP ONLY)
            function saveSidebarState(collapsed) {
                // Only save state on desktop
                if (window.innerWidth > 768) {
                    document.cookie = `sidebar_collapsed=${collapsed}; path=/; max-age=${365 * 24 * 60 * 60}`;
                }
            }
            
            // Function to collapse sidebar (DESKTOP ONLY)
            function collapseSidebar() {
                if (!sidebar || window.innerWidth <= 768) return;
                sidebar.classList.add('sidebar-collapsed');
                saveSidebarState('true');
                // Update tooltip attributes for better UX
                document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                    const text = link.querySelector('.nav-text');
                    if (text && text.textContent.trim()) {
                        link.setAttribute('data-tooltip', text.textContent.trim());
                    }
                });
                if (typeof showNotification === 'function') {
                    showNotification('Sidebar collapsed', 'info');
                }
            }
            
            // Function to expand sidebar (DESKTOP ONLY)
            function expandSidebar() {
                if (!sidebar || window.innerWidth <= 768) return;
                sidebar.classList.remove('sidebar-collapsed');
                saveSidebarState('false');
                document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                    link.removeAttribute('data-tooltip');
                });
                if (typeof showNotification === 'function') {
                    showNotification('Sidebar expanded', 'info');
                }
            }
            
            // Toggle sidebar function for DESKTOP
            function toggleSidebarDesktop() {
                if (isSidebarCollapsed()) {
                    expandSidebar();
                } else {
                    collapseSidebar();
                }
            }
            
            // Handle collapse button click (chevron in sidebar) - DESKTOP ONLY
            if (collapseBtn) {
                collapseBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Only toggle on desktop
                    if (window.innerWidth > 768) {
                        toggleSidebarDesktop();
                    }
                });
            }
            
            // Create overlay for mobile sidebar
            function createOverlay() {
                if (!document.getElementById('sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.id = 'sidebar-overlay';
                    overlay.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.5);
                        z-index: 1025;
                        transition: opacity 0.3s ease;
                    `;
                    document.body.appendChild(overlay);
                    
                    overlay.addEventListener('click', function() {
                        closeMobileSidebar();
                    });
                }
            }
            
            function removeOverlay() {
                const overlay = document.getElementById('sidebar-overlay');
                if (overlay) {
                    overlay.remove();
                }
            }
            
            function openMobileSidebar() {
                if (!sidebar) return;
                sidebar.classList.add('show');
                createOverlay();
                document.body.style.overflow = 'hidden';
            }
            
            function closeMobileSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove('show');
                removeOverlay();
                document.body.style.overflow = '';
            }
            
            function toggleMobileSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            }
            
            // Handle toggle button click (hamburger in top navbar)
            if (sidebarToggleBtn && sidebar) {
                // Remove existing listeners to avoid duplicates
                const newToggleBtn = sidebarToggleBtn.cloneNode(true);
                sidebarToggleBtn.parentNode.replaceChild(newToggleBtn, sidebarToggleBtn);
                
                newToggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (window.innerWidth <= 768) {
                        // Mobile: open as overlay
                        toggleMobileSidebar();
                    } else {
                        // Desktop: toggle collapse
                        toggleSidebarDesktop();
                    }
                });
            }
            
            // Close mobile sidebar when clicking outside
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768 && sidebar && sidebar.classList.contains('show')) {
                    const isClickInside = sidebar.contains(event.target);
                    const isToggleClick = sidebarToggleBtn && sidebarToggleBtn.contains(event.target);
                    
                    if (!isClickInside && !isToggleClick) {
                        closeMobileSidebar();
                    }
                }
            });
            
            // Handle Escape key to close mobile sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar && sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                }
            });
            
            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 768) {
                        // Switching from mobile to desktop
                        // Close mobile overlay if open
                        if (sidebar && sidebar.classList.contains('show')) {
                            closeMobileSidebar();
                        }
                        document.body.style.overflow = '';
                        
                        // Restore desktop collapse state from cookie
                        const savedState = document.cookie.match(/sidebar_collapsed=(true|false)/);
                        if (savedState && savedState[1] === 'true') {
                            if (!isSidebarCollapsed()) {
                                collapseSidebar();
                            }
                        } else {
                            if (isSidebarCollapsed()) {
                                expandSidebar();
                            }
                        }
                    } else {
                        // Switching from desktop to mobile
                        // Remove collapsed class on mobile (it causes layout issues)
                        if (sidebar && isSidebarCollapsed()) {
                            sidebar.classList.remove('sidebar-collapsed');
                        }
                        // Also close the sidebar if it was open
                        if (sidebar && sidebar.classList.contains('show')) {
                            closeMobileSidebar();
                        }
                    }
                }, 250);
            });
            
            // Initialize sidebar state on page load
            const savedState = document.cookie.match(/sidebar_collapsed=(true|false)/);
            if (window.innerWidth > 768) {
                // Desktop: apply saved collapse state
                if (savedState && savedState[1] === 'true') {
                    if (!isSidebarCollapsed()) {
                        collapseSidebar();
                    }
                } else {
                    if (isSidebarCollapsed()) {
                        expandSidebar();
                    }
                }
            } else {
                // Mobile: ensure collapsed class is removed
                if (isSidebarCollapsed()) {
                    sidebar.classList.remove('sidebar-collapsed');
                }
                // Ensure sidebar is closed
                if (sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                }
            }
            
            // Initialize tooltips for collapsed mode on page load
            if (isSidebarCollapsed() && window.innerWidth > 768) {
                document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                    const text = link.querySelector('.nav-text');
                    if (text && text.textContent.trim()) {
                        link.setAttribute('data-tooltip', text.textContent.trim());
                    }
                });
            }
            
            // Scroll to top button
            const scrollBtn = document.getElementById('scrollToTopBtn');
            if (scrollBtn) {
                window.addEventListener('scroll', function () {
                    scrollBtn.classList.toggle('visible', window.scrollY > 300);
                });
                
                scrollBtn.addEventListener('click', function () {
                    const contentContainer = document.querySelector('.container-fluid.py-4');
                    if (contentContainer) {
                        contentContainer.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                });
            }
            
            // Content height adjustment
            function adjustContentHeight() {
                const topNavbar = document.getElementById('top-navbar');
                const pageContent = document.querySelector('.container-fluid.py-4');
                
                if (topNavbar && pageContent) {
                    const navbarHeight = topNavbar.offsetHeight;
                    const viewportHeight = window.innerHeight;
                    const availableHeight = viewportHeight - navbarHeight;
                    
                    pageContent.style.height = availableHeight + 'px';
                    pageContent.style.maxHeight = availableHeight + 'px';
                    
                    // Check if last element is visible
                    const contentArea = document.querySelector('.regular-content, .dashboard-content');
                    if (contentArea) {
                        const lastElement = contentArea.lastElementChild;
                        if (lastElement) {
                            const lastElementBottom = lastElement.getBoundingClientRect().bottom;
                            const containerBottom = pageContent.getBoundingClientRect().bottom;
                            
                            if (lastElementBottom > containerBottom - 20) {
                                pageContent.style.paddingBottom = '50px';
                            }
                        }
                    }
                }
            }
            
            // Initial adjustment
            setTimeout(adjustContentHeight, 100);
            
            // Adjust on resize
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    adjustContentHeight();
                }, 250);
            });
            
            // Handle submenu toggles
            document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(toggle => {
                toggle.addEventListener('click', function () {
                    const icon = this.querySelector('.fa-chevron-down');
                    if (icon) {
                        icon.style.transform = this.getAttribute('aria-expanded') === 'true'
                            ? 'rotate(0deg)'
                            : 'rotate(180deg)';
                    }
                });
            });
            
            // Initialize submenu icons
            document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(toggle => {
                const icon = toggle.querySelector('.fa-chevron-down');
                if (icon) {
                    icon.style.transition = 'transform 0.3s ease';
                    icon.style.transform = toggle.getAttribute('aria-expanded') === 'true'
                        ? 'rotate(180deg)'
                        : 'rotate(0deg)';
                }
            });
        });
    })();
</script>




<!-- PWA Service Worker Registration -->
<script>
// PWA Service Worker Registration
if ('serviceWorker' in navigator) {
    console.log('[PWA] Service Worker is supported');
    
    window.addEventListener('load', function() {
        // Use absolute path from root
        const swPath = '/mpazi/app/sw.js';
        
        navigator.serviceWorker.register(swPath)
            .then(function(registration) {
                console.log('[PWA] Service Worker registered successfully:', registration.scope);
                console.log('[PWA] Service Worker active:', !!registration.active);
                
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    console.log('[PWA] New service worker found:', newWorker);
                    
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            if (typeof showNotification === 'function') {
                                showNotification('New version available. Refresh to update.', 'info');
                            }
                        }
                    });
                });
            })
            .catch(function(error) {
                console.log('[PWA] Service Worker registration failed:', error);
            });
    });
} else {
    console.log('[PWA] Service Worker is NOT supported in this browser');
}

// PWA Install Prompt
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    console.log('[PWA] beforeinstallprompt event fired!');
    e.preventDefault();
    deferredPrompt = e;
    
    const installBtn = document.getElementById('installAppBtn');
    console.log('[PWA] Install button found:', installBtn !== null);
    
    if (installBtn) {
        installBtn.style.display = 'flex';
        installBtn.style.backgroundColor = '#28a745';
        installBtn.style.color = 'white';
        installBtn.style.borderRadius = '4px';
        installBtn.style.padding = '8px 12px';
        console.log('[PWA] Install button should now be visible');
        
        // Remove existing listeners and add new one
        const newInstallBtn = installBtn.cloneNode(true);
        installBtn.parentNode.replaceChild(newInstallBtn, installBtn);
        
        newInstallBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('[PWA] Install button clicked!');
            
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('[PWA] User accepted install prompt');
                        if (typeof showNotification === 'function') {
                            showNotification('App installed successfully!', 'success');
                        }
                    } else {
                        console.log('[PWA] User dismissed install prompt');
                    }
                    deferredPrompt = null;
                    newInstallBtn.style.display = 'none';
                });
            } else {
                console.log('[PWA] No deferredPrompt available');
            }
        });
    } else {
        console.log('[PWA] Install button NOT found in DOM');
    }
});

// App installed event
window.addEventListener('appinstalled', (evt) => {
    console.log('[PWA] App installed event fired');
    if (typeof showNotification === 'function') {
        showNotification('SATI ERP has been installed!', 'success');
    }
});

// Check install button on page load
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('installAppBtn');
    console.log('[PWA] Page loaded - install button exists:', btn !== null);
    if (btn) {
        console.log('[PWA] Install button display style:', window.getComputedStyle(btn).display);
    }
});

// Check if already installed
if (window.matchMedia('(display-mode: standalone)').matches) {
    console.log('[PWA] Already running as installed app');
    const installBtn = document.getElementById('installAppBtn');
    if (installBtn) installBtn.style.display = 'none';
}
</script>

</body>
</html>