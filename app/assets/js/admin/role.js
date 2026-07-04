/**
 * admin/roles.js
 * Role Management Page Scripts
 * Handles role CRUD operations, permissions management, and modals
 */

let currentRoleId = null;
let currentRoleCode = null;
let allPermissions = null;

$(document).ready(function() {
    // ============================================
    // Initialize DataTable if needed
    // ============================================
    if ($.fn.DataTable && $('#rolesTable tbody tr').length > 0) {
        const hasDataRows = $('#rolesTable tbody tr:first td[colspan]').length === 0;
        
        if (hasDataRows) {
            if ($.fn.DataTable.isDataTable('#rolesTable')) {
                $('#rolesTable').DataTable().destroy();
                $('#rolesTable').removeClass('dataTable');
            }
            
            $('#rolesTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    search: "Search roles:",
                    lengthMenu: "Show _MENU_ roles per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ roles",
                    infoEmpty: "No roles found",
                    emptyTable: "No roles found"
                },
                searching: true,
                paging: true,
                responsive: true
            });
        }
    }
    
    // ============================================
    // Initialize Tooltips
    // ============================================
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// ============================================
// Edit Role Functions
// ============================================

/**
 * Open edit modal with role data
 * @param {number} roleId - Role ID
 * @param {string} roleCode - Role code
 * @param {string} roleName - Role name
 * @param {string} description - Role description
 * @param {object} rolePermissions - Role permissions (passed from PHP)
 */
function openEditModal(roleId, roleCode, roleName, description, rolePermissions) {
    currentRoleId = roleId;
    currentRoleCode = roleCode;
    
    // Populate role info tab
    document.getElementById('edit_role_id').value = roleId;
    document.getElementById('edit_role_code').value = roleCode;
    document.getElementById('edit_role_name').value = roleName;
    document.getElementById('edit_description').value = description || '';
    document.getElementById('editRoleNameDisplay').textContent = roleName;
    
    // Reset tabs to role info
    const roleInfoTab = document.querySelector('#roleInfoTab');
    const permissionsTab = document.querySelector('#permissionsTab');
    if (roleInfoTab && permissionsTab) {
        roleInfoTab.classList.add('show', 'active');
        permissionsTab.classList.remove('show', 'active');
    }
    
    const tabButtons = document.querySelectorAll('[data-bs-toggle="tab"]');
    if (tabButtons[0]) {
        tabButtons[0].classList.add('active');
        tabButtons[1].classList.remove('active');
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editRoleModal'));
    modal.show();
    
    // Render permissions (passed from PHP)
    renderPermissionsModal(rolePermissions);
}

/**
 * Render permissions modal
 * @param {object} rolePermissions - Role permissions data
 */
function renderPermissionsModal(rolePermissions) {
    const permissionsContent = document.getElementById('permissionsContent');
    if (!permissionsContent) return;
    
    if (!rolePermissions || !rolePermissions.permissions) {
        permissionsContent.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No permissions configured. Please install default permissions first.
            </div>
        `;
        return;
    }
    
    const permissions = rolePermissions.permissions;
    const rolePermissionIds = rolePermissions.role_permission_ids || [];
    
    let html = `
        <div class="mb-3">
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-success" onclick="selectAllPermissions()">
                    <i class="fas fa-check-double me-1"></i> Select All
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllPermissions()">
                    <i class="fas fa-times me-1"></i> Deselect All
                </button>
            </div>
        </div>
        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
            <table class="table table-bordered table-hover">
                <thead class="table-light sticky-top">
                    <tr>
                        <th style="width: 200px">Module</th>
                        <th style="width: 200px">Permission</th>
                        <th class="text-center" style="width: 80px">Granted</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    for (const [module, perms] of Object.entries(permissions)) {
        let moduleRowSpan = perms.length;
        let firstRow = true;
        
        for (const perm of perms) {
            html += `
                <tr>
                    ${firstRow ? `<td rowspan="${moduleRowSpan}"><strong>${escapeHtml(module)}</strong></td>` : ''}
                    <td>${escapeHtml(perm.permission_name)}<br><small class="text-muted">${escapeHtml(perm.permission_code)}</small></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-flex justify-content-center">
                            <input type="checkbox" class="form-check-input permission-checkbox" 
                                   data-permission-id="${perm.id}"
                                   ${rolePermissionIds.includes(perm.id) ? 'checked' : ''}
                                   style="transform: scale(1.2);">
                        </div>
                    </td>
                </tr>
            `;
            firstRow = false;
        }
    }
    
    html += `
                </tbody>
            </table>
        </div>
        <input type="hidden" id="current_role_id" value="${currentRoleId}">
    `;
    
    permissionsContent.innerHTML = html;
}

function selectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

function savePermissions() {
    const roleId = document.getElementById('current_role_id').value;
    const checkboxes = document.querySelectorAll('.permission-checkbox');
    const selectedPermissions = [];
    
    checkboxes.forEach(cb => {
        if (cb.checked) {
            selectedPermissions.push(cb.dataset.permissionId);
        }
    });
    
    const saveBtn = event.target;
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
    
    // Send via AJAX to the same page
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=sync_permissions&role_id=${roleId}&permissions[]=${selectedPermissions.join('&permissions[]=')}&csrf_token=${csrfToken}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
        } else {
            showAlert('Error: ' + data.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to save permissions. Please try again.', 'danger');
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    });
}

// ============================================
// Role Form Submissions
// ============================================

/**
 * Add Role Form Submission
 */
document.getElementById('addRoleForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=create&role_code=${encodeURIComponent(data.role_code)}&role_name=${encodeURIComponent(data.role_name)}&description=${encodeURIComponent(data.description || '')}&csrf_token=${csrfToken}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showAlert('Error: ' + data.message, 'danger');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to create role. Please try again.', 'danger');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

/**
 * Edit Role Info Form Submission
 */
document.getElementById('editRoleInfoForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update&role_id=${data.role_id}&role_name=${encodeURIComponent(data.role_name)}&description=${encodeURIComponent(data.description || '')}&csrf_token=${csrfToken}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            document.getElementById('editRoleNameDisplay').textContent = data.role?.role_name || data.role_name;
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showAlert('Error: ' + data.message, 'danger');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to update role. Please try again.', 'danger');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// ============================================
// Helper Functions
// ============================================

/**
 * Show alert message
 * @param {string} message - Alert message
 * @param {string} type - Alert type (success, danger, warning, info)
 */
function showAlert(message, type = 'info') {
    const existingAlerts = document.querySelectorAll('.alert');
    existingAlerts.forEach(alert => alert.remove());
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
        ${escapeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    const firstChild = container.firstChild;
    container.insertBefore(alertDiv, firstChild);
    
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 300);
    }, 5000);
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