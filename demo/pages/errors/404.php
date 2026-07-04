<?php
// pages/errors/404.php
$pageTitle = '404 - Page Not Found';
?>
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center py-5">
            <div class="error-container">
                <div class="error-code display-1 text-primary mb-3">404</div>
                <div class="error-icon mb-4">
                    <i class="fas fa-map-signs fa-4x text-muted"></i>
                </div>
                <h2 class="mb-3">Page Not Found</h2>
                <p class="lead text-muted mb-4">
                    The page you are looking for might have been removed,<br>
                    had its name changed, or is temporarily unavailable.
                </p>
                <div class="error-actions">
                    <a href="<?php echo route_url('dashboard'); ?>" class="btn btn-primary btn-lg me-2">
                        <i class="fas fa-home me-2"></i>Go to Dashboard
                    </a>
                    <button onclick="history.back()" class="btn btn-outline-secondary btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>Go Back
                    </button>
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