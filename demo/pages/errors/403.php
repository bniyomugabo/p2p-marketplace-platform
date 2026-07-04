<?php
// pages/errors/403.php
$pageTitle = '403 - Access Denied';
?>
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center py-5">
            <div class="error-container">
                <div class="error-code display-1 text-danger mb-3">403</div>
                <div class="error-icon mb-4">
                    <i class="fas fa-lock fa-4x text-muted"></i>
                </div>
                <h2 class="mb-3">Access Denied</h2>
                <p class="lead text-muted mb-4">
                    You don't have permission to access this page.<br>
                    Please contact your administrator if you believe this is an error.
                </p>
                <div class="error-actions">
                    <a href="<?php echo route_url('dashboard'); ?>" class="btn btn-primary btn-lg me-2">
                        <i class="fas fa-home me-2"></i>Go to Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>auth/logout.php" class="btn btn-outline-danger btn-lg">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .error-container {
        animation: fadeIn 0.5s ease-in-out;
    }

    .error-code {
        font-weight: 700;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>