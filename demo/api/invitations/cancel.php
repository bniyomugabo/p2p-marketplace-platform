<?php
// api/invitations/cancel.php
// ============================================
// CANCEL INVITATION API ENDPOINT
// ============================================

declare(strict_types=1);



// Set JSON content type
header('Content-Type: application/json');

// Check authentication and authorization
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'ADM') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Load required files
require_once __DIR__ . '/../../config/autoload.php';

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? '';

if (!CSRF::validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Validate input
$invitationId = $input['id'] ?? 0;

if (!$invitationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invitation ID is required']);
    exit;
}

try {
    $db = Database::getInstance();

    // Get invitation details to verify company access
    $stmt = $db->prepare("
        SELECT * FROM invitation_tokens 
        WHERE id = ?
    ");
    $stmt->execute([$invitationId]);
    $invitation = $stmt->fetch();

    if (!$invitation) {
        throw new Exception('Invitation not found');
    }

    // Verify invitation belongs to admin's company
    if ($invitation['company_id'] != $_SESSION['company_id']) {
        throw new Exception('You do not have permission to cancel this invitation');
    }

    // Check if already used
    if ($invitation['used_at']) {
        throw new Exception('Cannot cancel an invitation that has already been used');
    }

    // Delete the invitation
    $stmt = $db->prepare("DELETE FROM invitation_tokens WHERE id = ?");
    $stmt->execute([$invitationId]);

    // Log the action
    error_log("Invitation {$invitationId} cancelled by admin {$_SESSION['user_id']}");

    echo json_encode([
        'success' => true,
        'message' => 'Invitation cancelled successfully'
    ]);

} catch (Exception $e) {
    error_log("Cancel invitation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}