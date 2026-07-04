
$(document).ready(function () {
    $('#invitationsTable').DataTable({
        pageLength: 25,
        order: [[4, 'desc']]
    });

    // Form validation
    $('#invitationForm').on('submit', function (e) {
        const email = $('#email').val();
        const role = $('#role_id').val();

        if (!email || !role) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return;
        }

        // Disable button to prevent double submission
        $('#sendInviteBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-2"></i>Sending...');
    });
});

function changeCompany(companyId) {
    window.location.href = '?page=admin/invitations&company_id=' + companyId +
        '&status=' + status;
}

function copyInvitationLink(token) {
    const baseUrl = baseUrl;
    const link = baseUrl + '/auth/register.php?token=' + token;

    $('#invitationLink').val(link);
    new bootstrap.Modal(document.getElementById('invitationLinkModal')).show();
}

function copyToClipboard() {
    const linkInput = document.getElementById('invitationLink');
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    document.execCommand('copy');

    alert('Invitation link copied to clipboard!');
}

function resendInvitation(id) {
    if (confirm('Resend invitation email?')) {
        $.ajax({
            url: 'api/invitations/resend.php',
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            data: JSON.stringify({ id: id }),
            contentType: 'application/json',
            success: function (response) {
                if (response.success) {
                    alert('Invitation resent successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.error);
                }
            },
            error: function () {
                alert('Failed to resend invitation');
            }
        });
    }
}

function cancelInvitation(id) {
    if (confirm('Cancel this invitation?')) {
        $.ajax({
            url: 'api/invitations/cancel.php',
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            data: JSON.stringify({ id: id }),
            contentType: 'application/json',
            success: function (response) {
                if (response.success) {
                    alert('Invitation cancelled successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.error);
                }
            },
            error: function () {
                alert('Failed to cancel invitation');
            }
        });
    }
}

function approveUser(userId) {
    if (confirm('Approve this user registration?')) {
        $.ajax({
            url: '../../../api/users/approve.php',
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            data: JSON.stringify({ user_id: userId }),
            contentType: 'application/json',
            success: function (response) {
                if (response.success) {
                    alert('User approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.error);
                }
            },
            error: function (xhr, status, error) {
                console.error("Status: " + status);
                console.error("Error: " + error);
                console.dir(xhr); // This lets you inspect the full response object
                alert('Failed to approve user. Check console for details.');
            }
        });
    }
}

function rejectUser(userId) {
    const reason = prompt('Please provide a reason for rejection (optional):');

    $.ajax({
        url: 'api/users/reject.php',
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken
        },
        data: JSON.stringify({
            user_id: userId,
            reason: reason || null
        }),
        contentType: 'application/json',
        success: function (response) {
            if (response.success) {
                alert('User rejected successfully!');
                location.reload();
            } else {
                alert('Error: ' + response.error);
            }
        },
        error: function () {
            alert('Failed to reject user');
        }
    });
}