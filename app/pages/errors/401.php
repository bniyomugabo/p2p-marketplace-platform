<?php
// pages/errors/401.php
$pageTitle = '401 - Unauthorized';
?>
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center py-5">
            <div class="error-container">
                <div class="error-code display-1 text-warning mb-3">401</div>
                <div class="error-icon mb-4">
                    <i class="fas fa-user-lock fa-4x text-muted"></i>
                </div>
                <h2 class="mb-3">Unauthorized Access</h2>
                <p class="lead text-muted mb-4">
                    Please log in to access this page.
                </p>
                <div class="error-actions">
                    <a href="<?php echo BASE_URL; ?>auth/signin.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-sign-in-alt me-2"></i>Go to Login
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