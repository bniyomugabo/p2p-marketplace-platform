<?php
// pages/quotations/delete.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';



// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to delete quotations.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get quotation ID from URL
$quotationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$quotationId) {
    SessionManager::flash('error', 'Quotation ID is required.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Validate CSRF token (if coming from POST)
$isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
$hasValidCsrf = false;

if ($isPostRequest) {
    if (isset($_POST['csrf_token']) && CSRF::validate($_POST['csrf_token'])) {
        $hasValidCsrf = true;
    } else {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ' . route_url('quotations'));
        exit;
    }
}

$db = Database::getInstance();

// Initialize model with company ID
$quotationModel = new Quotation($companyId);

try {
    // Get quotation details with company verification
    $quotation = $quotationModel->find($quotationId);

    if (!$quotation) {
        throw new Exception('Quotation not found or does not belong to your company.');
    }

    // Only allow deletion of draft quotations
    if ($quotation['status'] !== 'draft') {
        throw new Exception('Only draft quotations can be deleted. Current status: ' . $quotation['status']);
    }

    // If this is a POST request (with confirmation), proceed with deletion
    if ($isPostRequest && $hasValidCsrf) {
        // Start transaction
        $db->beginTransaction();

        // Log the activity before deletion
        $activitySql = "
            INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, created_at)
            VALUES (:company_id, :user_id, 'quotation_deleted', 'quotation', :quotation_id, :old_data, NOW())
        ";
        $activityStmt = $db->prepare($activitySql);
        $activityStmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'quotation_id' => $quotationId,
            'old_data' => json_encode([
                'quotation_number' => $quotation['quotation_number'],
                'customer_name' => $quotation['customer_name'],
                'total_amount' => $quotation['total_amount']
            ])
        ]);

        // Delete quotation (cascade will delete items automatically)
        $result = $quotationModel->delete($quotationId);

        if (!$result) {
            throw new Exception('Failed to delete quotation.');
        }

        $db->commit();

        error_log("User {$userId} from company {$companyId} deleted quotation ID: {$quotationId}, Number: {$quotation['quotation_number']}");

        SessionManager::flash('success', 'Quotation #' . htmlspecialchars($quotation['quotation_number']) . ' deleted successfully.');
        header('Location: ' . route_url('quotations'));
        exit;
    } else {
        // GET request - show confirmation page
        // Generate CSRF token for the confirmation form
        $csrfToken = CSRF::generate();
        ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Delete Quotation - <?php echo htmlspecialchars($quotation['quotation_number']); ?></title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    <style>
                        body {
                            background-color: #f8f9fc;
                        }
                        .delete-container {
                            max-width: 600px;
                            margin: 50px auto;
                        }
                        .warning-icon {
                            font-size: 64px;
                            color: #e74a3b;
                            margin-bottom: 20px;
                        }
                        .quotation-details {
                            background-color: #f8f9fc;
                            border-left: 4px solid #e74a3b;
                        }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="delete-container">
                            <div class="card shadow">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Confirm Deletion
                                    </h5>
                                </div>
                                <div class="card-body text-center">
                                    <div class="warning-icon">
                                        <i class="fas fa-trash-alt"></i>
                                    </div>
                                    <h4 class="mb-3">Are you sure you want to delete this quotation?</h4>
                                    <p class="text-muted">This action cannot be undone.</p>
                            
                                    <div class="quotation-details p-3 mb-4 text-start">
                                        <div class="row mb-2">
                                            <div class="col-5 fw-bold">Quotation Number:</div>
                                            <div class="col-7"><?php echo htmlspecialchars($quotation['quotation_number']); ?></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-5 fw-bold">Customer Name:</div>
                                            <div class="col-7"><?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-5 fw-bold">Date:</div>
                                            <div class="col-7"><?php echo date('d/m/Y', strtotime($quotation['quotation_date'])); ?></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-5 fw-bold">Total Amount:</div>
                                            <div class="col-7"><?php echo format_currency($quotation['total_amount']); ?></div>
                                        </div>
                                        <div class="row">
                                            <div class="col-5 fw-bold">Status:</div>
                                            <div class="col-7">
                                                <span class="badge bg-secondary"><?php echo ucfirst($quotation['status']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                            
                                    <p class="text-danger mb-4">
                                        <i class="fas fa-info-circle me-1"></i>
                                        This will permanently delete the quotation and all its items.
                                    </p>
                            
                                    <form method="POST" action="?page=quotations/delete&id=<?php echo $quotationId; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <div class="d-flex justify-content-center gap-3">
                                            <a href="<?php echo route_url('quotations/view', ['id' => $quotationId]); ?>" 
                                               class="btn btn-secondary">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </a>
                                            <button type="submit" class="btn btn-danger">
                                                <i class="fas fa-trash-alt me-2"></i>Yes, Delete Quotation
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
            
                    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
                </body>
                </html>
                <?php
                exit;
    }

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Delete quotation error for company {$companyId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to delete quotation: ' . $e->getMessage());
    header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
    exit;
}
?>