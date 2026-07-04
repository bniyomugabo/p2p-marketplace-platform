/**
 * admin/users.js
 * User Management Page Scripts
 * Handles user CRUD operations, filtering, modals, and DataTable
 */

$(document).ready(function() {
    // ============================================
    // DataTable Initialization
    // ============================================
    if ($.fn.DataTable && $('#usersTable tbody tr').length > 0) {
        const hasDataRows = $('#usersTable tbody tr:first td[colspan]').length === 0;
        
        if (hasDataRows) {
            if ($.fn.DataTable.isDataTable('#usersTable')) {
                $('#usersTable').DataTable().destroy();
                $('#usersTable').removeClass('dataTable');
            }
            
            $('#usersTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    search: "Search users:",
                    lengthMenu: "Show _MENU_ users per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ users",
                    infoEmpty: "No users found",
                    emptyTable: "No users found",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [8] } // Actions column
                ],
                searching: true,
                paging: true,
                responsive: true
            });
        }
    }
    
    // ============================================
    // Password Confirmation Validation
    // ============================================
    $('#addUserForm, #editUserForm').on('submit', function(e) {
        const form = $(this);
        const passwordField = form.find('#password, #edit_password');
        const confirmField = form.find('#confirm_password, #edit_confirm_password');
        const password = passwordField.val();
        const confirm = confirmField.val();
        
        if (password || confirm) {
            if (password !== confirm) {
                e.preventDefault();
                showNotification('Passwords do not match!', 'danger');
                confirmField.focus();
                return false;
            }
            if (password.length < 8) {
                e.preventDefault();
                showNotification('Password must be at least 8 characters long!', 'danger');
                passwordField.focus();
                return false;
            }
            // Check password strength
            if (!/(?=.*[A-Z])/.test(password)) {
                e.preventDefault();
                showNotification('Password must contain at least one uppercase letter!', 'danger');
                passwordField.focus();
                return false;
            }
            if (!/(?=.*[a-z])/.test(password)) {
                e.preventDefault();
                showNotification('Password must contain at least one lowercase letter!', 'danger');
                passwordField.focus();
                return false;
            }
            if (!/(?=.*[0-9])/.test(password)) {
                e.preventDefault();
                showNotification('Password must contain at least one number!', 'danger');
                passwordField.focus();
                return false;
            }
        }
    });
    
    // ============================================
    // Real-time Password Strength Indicator
    // ============================================
    $('#password, #edit_password').on('input', function() {
        const password = $(this).val();
        const strength = getPasswordStrength(password);
        updatePasswordStrengthIndicator($(this), strength);
    });
    
    // ============================================
    // Role Filter Change - Auto-submit
    // ============================================
    $('#role_id, #status').on('change', function() {
        $('#filterForm').submit();
    });
    
    // ============================================
    // Search with debounce
    // ============================================
    let searchTimeout;
    $('#search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            $('#filterForm').submit();
        }, 500);
    });
    
    // ============================================
    // Initialize Tooltips
    // ============================================
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add export button to card header
    const $cardHeader = $('.card-header .d-flex');
    if ($cardHeader.length && $('#usersTable tbody tr').length > 0) {
        if (!$cardHeader.find('.export-btn').length) {
            $cardHeader.append(`
                <button class="btn btn-sm btn-success export-btn" onclick="exportUsersToCSV()">
                    <i class="fas fa-download me-1"></i> Export
                </button>
            `);
        }
    }
});

// ============================================
// User View Functions
// ============================================

/**
 * View user details in modal
 * @param {Object} user - User object from PHP
 */
function viewUser(user) {
    const modalContent = document.getElementById('userDetailsContent');
    if (!modalContent) return;
    
    modalContent.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading user details...</p>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('viewUserModal'));
    modal.show();
    
    // Display user details
    displayUserDetails(user);
}

/**
 * Display user details in modal
 * @param {Object} user - User object
 */
function displayUserDetails(user) {
    const modalContent = document.getElementById('userDetailsContent');
    if (!modalContent) return;
    
    // Get role name
    const roleSelect = document.getElementById('edit_role_id');
    let roleName = user.role_name || 'Unknown';
    if (roleSelect && !roleName || roleName === 'Unknown') {
        const selectedOption = roleSelect.querySelector(`option[value="${user.role_id}"]`);
        if (selectedOption) {
            roleName = selectedOption.textContent.split(' [')[0];
        }
    }
    
    const content = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Personal Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Username:</th><td><code>${escapeHtml(user.username)}</code></div>
                          </div>
                        </div>
                        <tr><th>Full Name:</th><td><strong>${escapeHtml(user.full_name)}</strong></div>
                      </div>
                    </div>
                    <tr><th>Email:</th><td><a href="mailto:${escapeHtml(user.email)}">${escapeHtml(user.email)}</a></div>
                  </div>
                </div>
                <tr><th>Phone:</th><td>${user.phone ? escapeHtml(user.phone) : '-'}</div>
              </div>
            </div>
        </div>
    </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-user-tag me-2"></i>Role & Status</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <td><th style="width: 40%">Role:</th><td><span class="badge bg-info">${escapeHtml(roleName)}</span></div>
                      </div>
                    </div>
                    <td><th>Status:</th><td>
                        ${user.is_active 
                            ? '<span class="badge bg-success">Active</span>' 
                            : '<span class="badge bg-secondary">Inactive</span>'}
                    </div>
                  </div>
                </div>
                <tr><th>Last Login:</th><td>${user.last_login ? formatDate(user.last_login) : '<span class="text-muted">Never</span>'}</div>
              </div>
            </div>
            <tr><th>Login Count:</th><td>${user.login_count || 0}</div>
          </div>
        </div>
        <tr><th>Created At:</th><td>${formatDate(user.created_at)}</div>
      </div>
    </div>
            </div>
        </div>
    </div>
    `;
    
    modalContent.innerHTML = content;
}

// ============================================
// User Edit Functions
// ============================================

/**
 * Edit user in modal
 * @param {Object} user - User object
 */
function editUser(user) {
    const editId = document.getElementById('edit_id');
    const editUsername = document.getElementById('edit_username');
    const editFullName = document.getElementById('edit_full_name');
    const editEmail = document.getElementById('edit_email');
    const editPhone = document.getElementById('edit_phone');
    const editRoleId = document.getElementById('edit_role_id');
    const editIsActive = document.getElementById('edit_is_active');
    
    if (editId) editId.value = user.id;
    if (editUsername) editUsername.value = user.username;
    if (editFullName) editFullName.value = user.full_name;
    if (editEmail) editEmail.value = user.email;
    if (editPhone) editPhone.value = user.phone || '';
    if (editRoleId) editRoleId.value = user.role_id;
    if (editIsActive) editIsActive.checked = user.is_active == 1;
    
    // Clear password fields
    const editPassword = document.getElementById('edit_password');
    const editConfirmPassword = document.getElementById('edit_confirm_password');
    if (editPassword) editPassword.value = '';
    if (editConfirmPassword) editConfirmPassword.value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

// ============================================
// Helper Functions
// ============================================

/**
 * Get password strength
 * @param {string} password - Password to check
 * @returns {number} Strength score (0-4)
 */
function getPasswordStrength(password) {
    if (!password) return 0;
    
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    
    return Math.min(strength, 4);
}

/**
 * Update password strength indicator
 * @param {jQuery} $input - Password input element
 * @param {number} strength - Strength score
 */
function updatePasswordStrengthIndicator($input, strength) {
    const $container = $input.closest('.mb-3');
    if (!$container.length) return;
    
    // Remove existing indicator
    $container.find('.password-strength').remove();
    
    if (strength === 0 || !$input.val()) return;
    
    const strengthText = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const strengthColor = ['danger', 'warning', 'info', 'primary', 'success'];
    
    const $indicator = $(`
        <div class="password-strength mt-1">
            <div class="progress" style="height: 4px;">
                <div class="progress-bar bg-${strengthColor[strength]}" 
                     style="width: ${(strength + 1) * 20}%"></div>
            </div>
            <small class="text-${strengthColor[strength]}">${strengthText[strength]}</small>
        </div>
    `);
    
    $container.append($indicator);
}

/**
 * Show notification toast
 * @param {string} message - Notification message
 * @param {string} type - Notification type (success, danger, warning, info)
 */
function showNotification(message, type = 'info') {
    // Remove existing toasts
    $('.toast').remove();
    
    const bgClass = {
        'success': 'bg-success',
        'danger': 'bg-danger',
        'warning': 'bg-warning',
        'info': 'bg-info'
    }[type] || 'bg-info';
    
    const iconClass = {
        'success': 'fa-check-circle',
        'danger': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    }[type] || 'fa-info-circle';
    
    const toast = $(`
        <div class="toast align-items-center text-white ${bgClass} border-0 position-fixed top-0 end-0 m-3" 
             role="alert" style="z-index: 9999; min-width: 300px;">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${iconClass} me-2"></i>${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `);
    
    $('body').append(toast);
    const bsToast = new bootstrap.Toast(toast[0], { autohide: true, delay: 3000 });
    bsToast.show();
    
    toast.on('hidden.bs.toast', function() { 
        $(this).remove(); 
    });
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format date to locale string
 * @param {string} dateString - Date string
 * @returns {string} Formatted date
 */
function formatDate(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
}

/**
 * Export users to CSV
 */
function exportUsersToCSV() {
    const table = $('#usersTable').DataTable();
    const data = table.rows().data();
    
    if (!data || data.length === 0) {
        showNotification('No data to export', 'warning');
        return;
    }
    
    // Get headers
    const headers = [];
    $('#usersTable thead th').each(function() {
        const text = $(this).text().trim();
        if (text && text !== 'Actions') {
            headers.push(text);
        }
    });
    
    // Get data
    const rows = [];
    data.each(function(row) {
        const rowData = [];
        // Skip the last column (Actions)
        for (let i = 0; i < row.length - 1; i++) {
            rowData.push(row[i]);
        }
        rows.push(rowData);
    });
    
    // Create CSV
    const csvContent = [headers, ...rows].map(row => 
        row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')
    ).join('\n');
    
    // Download
    const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `users_export_${new Date().toISOString().slice(0, 19)}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    
    showNotification('Users exported successfully!', 'success');
}

// ============================================
// Keyboard Shortcuts
// ============================================

$(document).on('keydown', function(e) {
    // Ctrl + N - Open Add User Modal
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        const modalElement = document.getElementById('addUserModal');
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
            $('#username').focus();
        }
    }
    
    // Ctrl + F - Focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        $('#search').focus();
    }
    
    // ESC - Close modals
    if (e.key === 'Escape') {
        $('.modal').modal('hide');
    }
});

// ============================================
// AJAX Setup for CSRF
// ============================================
$.ajaxSetup({
    beforeSend: function(xhr) {
        if (typeof csrfToken !== 'undefined' && csrfToken) {
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        }
    }
});