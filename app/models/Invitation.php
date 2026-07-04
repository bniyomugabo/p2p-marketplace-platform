<?php
// models/Invitation.php
// ============================================
// INVITATION TOKEN MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Invitation extends BaseModel
{
    protected $table = 'invitation_tokens';
    protected $primaryKey = 'id';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Validate company context
     */
    protected function validateCompanyContext()
    {
        if (!$this->companyId) {
            throw new Exception('Company context is required for this operation');
        }
        return true;
    }

    /**
     * Verify invitation belongs to company
     */
    protected function verifyCompanyOwnership($invitationId)
    {
        if (!$this->companyId) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$invitationId]);
        $invitation = $stmt->fetch();

        if (!$invitation || $invitation['company_id'] != $this->companyId) {
            throw new Exception('Invitation does not belong to this company');
        }

        return true;
    }

    /**
     * Generate invitation token for new user
     */
    public function createInvitation($companyId, $email, $roleId, $createdBy, $fullName = null, $expiryDays = 7)
    {
        // Validate inputs
        if (!$companyId) {
            throw new Exception('Company ID is required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address.');
        }

        if ($expiryDays < 1 || $expiryDays > 90) {
            $expiryDays = 7; // Default to 7 days if invalid
        }

        // Check if email already has pending invitation for this company
        $existing = $this->getPendingByEmail($email, $companyId);
        if ($existing) {
            throw new Exception('An invitation has already been sent to this email.');
        }

        // Check if user already exists with this email in this company
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND company_id = ?");
        $stmt->execute([$email, $companyId]);
        if ($stmt->fetch()) {
            throw new Exception('A user with this email already exists in your company.');
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));

        // Set expiration
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

        $data = [
            'company_id' => $companyId,
            'email' => $email,
            'full_name' => $fullName,
            'role_id' => $roleId,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $invitationId = $this->create($data);

        // Send invitation email
        $emailSent = $this->sendInvitationEmail($email, $token, $companyId, $fullName, $expiryDays);

        return [
            'id' => $invitationId,
            'token' => $token,
            'expires_at' => $expiresAt,
            'email_sent' => $emailSent
        ];
    }

    /**
     * Validate invitation token
     */
    public function validateToken($token)
    {
        $sql = "SELECT i.*, r.role_name, r.role_code, c.company_name, c.company_code
                FROM {$this->table} i
                LEFT JOIN user_roles r ON i.role_id = r.id
                LEFT JOIN companies c ON i.company_id = c.id
                WHERE i.token = :token 
                AND i.expires_at > NOW() 
                AND i.used_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['token' => $token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return false;
        }

        return $invitation;
    }

    /**
     * Get pending invitation by email
     */
    public function getPendingByEmail($email, $companyId = null)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE email = :email 
                AND expires_at > NOW() 
                AND used_at IS NULL";

        $params = ['email' => $email];

        if ($companyId) {
            $sql .= " AND company_id = :company_id";
            $params['company_id'] = $companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get invitation by token
     */
    public function getByToken($token)
    {
        $sql = "SELECT * FROM {$this->table} WHERE token = :token";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['token' => $token]);
        return $stmt->fetch();
    }

    /**
     * Mark invitation as used
     */
    public function markAsUsed($token, $userId)
    {
        $sql = "UPDATE {$this->table} 
                SET used_at = NOW(), used_by = :user_id 
                WHERE token = :token AND used_at IS NULL";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'token' => $token,
            'user_id' => $userId
        ]);
    }

    /**
     * Get invitations by company
     */
    public function getByCompany($companyId, $status = 'pending')
    {
        $sql = "SELECT i.*, 
                       u.full_name as created_by_name,
                       r.role_name,
                       r.role_code,
                       ru.full_name as used_by_name
                FROM {$this->table} i
                LEFT JOIN users u ON i.created_by = u.id
                LEFT JOIN user_roles r ON i.role_id = r.id
                LEFT JOIN users ru ON i.used_by = ru.id
                WHERE i.company_id = :company_id";

        if ($status === 'pending') {
            $sql .= " AND i.used_at IS NULL AND i.expires_at > NOW()";
        } elseif ($status === 'expired') {
            $sql .= " AND i.used_at IS NULL AND i.expires_at <= NOW()";
        } elseif ($status === 'accepted') {
            $sql .= " AND i.used_at IS NOT NULL";
        }

        $sql .= " ORDER BY i.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get invitation by ID with company check
     */
    public function getById($id)
    {
        $sql = "SELECT i.*, 
                       u.full_name as created_by_name,
                       r.role_name,
                       ru.full_name as used_by_name
                FROM {$this->table} i
                LEFT JOIN users u ON i.created_by = u.id
                LEFT JOIN user_roles r ON i.role_id = r.id
                LEFT JOIN users ru ON i.used_by = ru.id
                WHERE i.id = :id";

        if ($this->companyId) {
            $sql .= " AND i.company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id] + ($params ?? []));
        return $stmt->fetch();
    }

    /**
     * Resend invitation
     */
    public function resendInvitation($invitationId)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($invitationId);

        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $invitationId]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            throw new Exception('Invitation not found');
        }

        if ($invitation['used_at']) {
            throw new Exception('Invitation has already been used');
        }

        // Generate new token
        $newToken = bin2hex(random_bytes(32));
        $newExpiry = date('Y-m-d H:i:s', strtotime('+7 days'));

        $sql = "UPDATE {$this->table} 
                SET token = :token, expires_at = :expires_at, updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'token' => $newToken,
            'expires_at' => $newExpiry,
            'id' => $invitationId
        ]);

        // Resend email
        $emailSent = $this->sendInvitationEmail(
            $invitation['email'],
            $newToken,
            $invitation['company_id'],
            $invitation['full_name'],
            7
        );

        return ['success' => true, 'email_sent' => $emailSent];
    }

    /**
     * Cancel invitation
     */
    public function cancelInvitation($invitationId)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($invitationId);

        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $invitationId]);
    }

    /**
     * Send invitation email
     */
    private function sendInvitationEmail($email, $token, $companyId, $fullName = null, $expiryDays = 7)
    {
        try {
            $companyName = $this->getCompanyName($companyId);
            $registerUrl = (defined('BASE_URL') ? BASE_URL : '') . "/auth/register.php?token=" . urlencode($token);

            $subject = "Invitation to join " . $companyName . " on SATI ERP";

            $message = "
            <html>
            <head>
                <title>Invitation to join SATI ERP</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .btn { background-color: #4CAF50; color: white; padding: 14px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block; }
                    .footer { text-align: center; font-size: 12px; color: #777; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
                    .info { background: #f5f5f5; padding: 10px; border-radius: 5px; word-break: break-all; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2 style='color: #4CAF50;'>You're Invited!</h2>
                    </div>
                    
                    <p>Hello " . ($fullName ? htmlspecialchars($fullName) : 'there') . ",</p>
                    
                    <p>You have been invited to join <strong>" . htmlspecialchars($companyName) . "</strong> on the <strong>SATI ERP Inventory Management System</strong>.</p>
                    
                    <p>Click the button below to create your account and get started:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='" . $registerUrl . "' class='btn'>
                            Accept Invitation
                        </a>
                    </div>
                    
                    <p>Or copy and paste this link into your browser:</p>
                    <p class='info'>" . $registerUrl . "</p>
                    
                    <p><strong>Note:</strong> This invitation link will expire in <strong>{$expiryDays} days</strong> and can only be used once.</p>
                    
                    <p>If you didn't expect this invitation, please ignore this email.</p>
                    
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " SATI ERP. All rights reserved.</p>
                        <p>This is an automated message, please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8\r\n";
            $headers .= "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";

            return mail($email, $subject, $message, $headers);

        } catch (Exception $e) {
            error_log("Failed to send invitation email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get company name by ID
     */
    private function getCompanyName($companyId)
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $result = $stmt->fetch();
        return $result ? $result['company_name'] : 'the company';
    }

    /**
     * Get invitation statistics
     */
    public function getStats($companyId)
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN used_at IS NULL AND expires_at > NOW() THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN expires_at <= NOW() AND used_at IS NULL THEN 1 ELSE 0 END) as expired
                FROM {$this->table}
                WHERE company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        $result = $stmt->fetch();

        return $result ?: ['total' => 0, 'pending' => 0, 'accepted' => 0, 'expired' => 0];
    }

    /**
     * Delete expired invitations (cleanup)
     */
    public function deleteExpired()
    {
        $sql = "DELETE FROM {$this->table} WHERE expires_at <= NOW() AND used_at IS NULL";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute();
    }

    /**
     * Get invitation by email for a company
     */
    public function getByEmail($email)
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = :email AND company_id = :company_id ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }
}