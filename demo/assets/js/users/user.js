    
    function viewUser(id) {
        fetch('<?php echo BASE_URL; ?>/api/users/get.php?id=${id}')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const user = data.user;
                    const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="text-muted small">Username</label>
                                <p class="fw-bold"><code>${escapeHtml(user.username)}</code></p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Full Name</label>
                                <p class="fw-bold">${escapeHtml(user.full_name)}</p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Email</label>
                                <p><a href="mailto:${escapeHtml(user.email)}">${escapeHtml(user.email)}</a></p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Phone</label>
                                <p>${escapeHtml(user.phone || '-')}</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="text-muted small">Role</label>
                                <p><span class="badge bg-info">${escapeHtml(user.role_name || 'Unknown')}</span></p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Status</label>
                                <p>${user.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Last Login</label>
                                <p>${user.last_login ? new Date(user.last_login).toLocaleString() : 'Never'}</p>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small">Login Count</label>
                                <p>${user.login_count || 0}</p>
                            </div>
                        </div>
                    </div>
                `;
                    document.getElementById('userDetailsContent').innerHTML = content;
                } else {
                    document.getElementById('userDetailsContent').innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('userDetailsContent').innerHTML = '<div class="alert alert-danger">Failed to load user details</div>';
            });

        const modal = new bootstrap.Modal(document.getElementById('viewUserModal'));
        modal.show();
    }

    function editUser(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_username').value = user.username;
        document.getElementById('edit_full_name').value = user.full_name;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_phone').value = user.phone || '';
        document.getElementById('edit_role_id').value = user.role_id;
        document.getElementById('edit_is_active').checked = user.is_active == 1;

        // Clear password fields
        document.getElementById('edit_password').value = '';
        document.getElementById('edit_confirm_password').value = '';

        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Form validation
    document.getElementById('addUserForm')?.addEventListener('submit', function (e) {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;

        if (password !== confirm) {
            e.preventDefault();
            alert('Passwords do not match');
            return false;
        }

        if (password.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters');
            return false;
        }
    });

    document.getElementById('editUserForm')?.addEventListener('submit', function (e) {
        const password = document.getElementById('edit_password').value;
        const confirm = document.getElementById('edit_confirm_password').value;

        if (password && password !== confirm) {
            e.preventDefault();
            alert('Passwords do not match');
            return false;
        }
    });