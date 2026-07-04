<?php
// models/User.php
// ============================================
// USER MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class User extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Find user by email (company-specific)
     */
    public function findByEmail($email)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE email = :email 
                AND company_id = :company_id 
                AND is_deleted = 0 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Find user by username (company-specific)
     */
    public function findByUsername($username)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE username = :username 
                AND company_id = :company_id 
                AND is_deleted = 0 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Find user by ID with role information (with company check)
     */
    public function getWithRole($id)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                u.*,
                ur.role_code,
                ur.role_name
            FROM {$this->table} u
            LEFT JOIN user_roles ur ON u.role_id = ur.id
            WHERE u.id = :id 
                AND u.company_id = :company_id
                AND u.is_deleted = 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Verify password
     */
    public function verifyPassword($password, $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Update last login
     */
    public function updateLastLogin($id): bool
    {
        $sql = "UPDATE {$this->table} 
                SET last_login = NOW(), 
                    login_count = login_count + 1, 
                    updated_at = NOW() 
                WHERE id = :id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Update profile
     */
    public function updateProfile($id, array $data): bool
    {
        $allowed = ['full_name', 'phone', 'email'];
        $updateData = [];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return true;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        return $this->update($id, $updateData);
    }

    /**
     * Update password
     */
    public function updatePassword($id, $newPassword): bool
    {
        $password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $sql = "UPDATE {$this->table} 
                SET password_hash = :password_hash, 
                    updated_at = NOW() 
                WHERE id = :id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'password_hash' => $password_hash,
            'id' => $id,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Get login history
     */
    public function getLoginHistory($userId, $limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        try {
            $sql = "
                SELECT * FROM login_attempts 
                WHERE user_id = :user_id 
                    AND company_id = :company_id
                ORDER BY created_at DESC
                LIMIT :limit
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error getting login history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get users by role (company-specific)
     */
    public function getByRole($roleCode)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT u.*, ur.role_name, ur.role_code
            FROM {$this->table} u
            JOIN user_roles ur ON u.role_id = ur.id
            WHERE ur.role_code = :role_code 
                AND u.company_id = :company_id
                AND u.is_active = 1 
                AND u.is_deleted = 0
            ORDER BY u.full_name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_code' => $roleCode,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Search users (company-specific)
     */
    public function search($term)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT u.*, ur.role_name 
            FROM {$this->table} u
            LEFT JOIN user_roles ur ON u.role_id = ur.id
            WHERE (u.full_name LIKE :term OR u.username LIKE :term OR u.email LIKE :term)
                AND u.company_id = :company_id
                AND u.is_deleted = 0
            ORDER BY u.full_name 
            LIMIT 20
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'term' => "%{$term}%",
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get user statistics (company-specific)
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_users' => 0,
                'active_users' => 0,
                'inactive_users' => 0,
                'total_roles' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
                COUNT(DISTINCT role_id) as total_roles
            FROM {$this->table}
            WHERE company_id = :company_id
                AND is_deleted = 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Get user with company details
     */
    public function getWithCompany($id)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT u.*, c.company_name, c.company_code, r.role_name, r.role_code
            FROM {$this->table} u
            LEFT JOIN companies c ON u.company_id = c.id
            LEFT JOIN user_roles r ON u.role_id = r.id
            WHERE u.id = :id 
                AND u.company_id = :company_id
                AND u.is_deleted = 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get users pending approval by company
     */
    public function getPendingApproval($companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return [];
        }

        try {
            $sql = "
                SELECT u.*, r.role_name, r.role_code, rr.created_at as requested_at, rr.reason
                FROM {$this->table} u
                JOIN registration_requests rr ON u.id = rr.user_id
                LEFT JOIN user_roles r ON u.role_id = r.id
                WHERE u.company_id = :company_id 
                    AND u.is_active = 0 
                    AND u.is_deleted = 0
                    AND rr.status = 'pending'
                ORDER BY rr.created_at DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['company_id' => $companyId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error getting pending approvals: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Approve user registration
     */
    public function approveUser($userId, $approvedBy)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        try {
            $this->beginTransaction();

            // Verify user belongs to this company
            $user = $this->find($userId);
            if (!$user || $user['company_id'] != $this->companyId) {
                throw new Exception('User not found or does not belong to this company');
            }

            // Update user to active
            $this->update($userId, [
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Update registration request
            $stmt = $this->db->prepare("
                UPDATE registration_requests 
                SET status = 'approved', 
                    approved_by = :approved_by, 
                    approved_at = NOW() 
                WHERE user_id = :user_id AND company_id = :company_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'approved_by' => $approvedBy,
                'company_id' => $this->companyId
            ]);

            // Add to company_users if not already there
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO company_users (company_id, user_id, role_id, status, joined_at)
                VALUES (:company_id, :user_id, :role_id, 'active', NOW())
            ");
            $stmt->execute([
                'company_id' => $this->companyId,
                'user_id' => $userId,
                'role_id' => $user['role_id']
            ]);

            $this->commit();

            // Send notification email
            $this->sendApprovalEmail($userId);

            return true;
        } catch (Exception $e) {
            $this->rollback();
            error_log("User approval error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reject user registration
     */
    public function rejectUser($userId, $reason = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        try {
            $this->beginTransaction();

            // Verify user belongs to this company
            $user = $this->find($userId);
            if (!$user || $user['company_id'] != $this->companyId) {
                throw new Exception('User not found or does not belong to this company');
            }

            // Update registration request
            $stmt = $this->db->prepare("
                UPDATE registration_requests 
                SET status = 'rejected', 
                    rejection_reason = :reason,
                    approved_at = NOW() 
                WHERE user_id = :user_id AND company_id = :company_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'reason' => $reason,
                'company_id' => $this->companyId
            ]);

            // Soft delete the user
            $this->delete($userId);

            $this->commit();

            return true;
        } catch (Exception $e) {
            $this->rollback();
            error_log("User rejection error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send approval email
     */
    private function sendApprovalEmail($userId)
    {
        try {
            $user = $this->getWithCompany($userId);
            if (!$user || !$user['email']) {
                return false;
            }

            $subject = "Your SATI ERP Account Has Been Approved";
            $loginUrl = (defined('BASE_URL') ? BASE_URL : '') . "/auth/signin.php";

            $message = "
            <html>
            <head>
                <title>Account Approved</title>
            </head>
            <body style='font-family: Arial, sans-serif;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #4CAF50;'>Welcome to SATI ERP!</h2>
                    <p>Dear {$user['full_name']},</p>
                    <p>Your account for <strong>{$user['company_name']}</strong> has been approved.</p>
                    <p>You can now log in to the system using your credentials:</p>
                    <p><strong>Username:</strong> {$user['username']}</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$loginUrl}' 
                           style='background-color: #4CAF50; color: white; padding: 12px 30px; 
                                  text-decoration: none; border-radius: 5px;'>
                            Log In Now
                        </a>
                    </div>
                    <p>Thank you for joining SATI ERP!</p>
                </div>
            </body>
            </html>
            ";

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8\r\n";
            $headers .= "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";

            return mail($user['email'], $subject, $message, $headers);
        } catch (Exception $e) {
            error_log("Error sending approval email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new user (automatically adds company_id)
     */
    public function create(array $data): int
    {
        // Auto-add company_id if not set
        if ($this->companyId && !isset($data['company_id'])) {
            $data['company_id'] = $this->companyId;
        }

        // Hash password if provided
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        // Set created_at if not provided
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return parent::create($data);
    }

    /**
     * Update user avatar
     */
    public function updateAvatar($userId, $avatarUrl)
    {
        $sql = "UPDATE {$this->table} SET avatar = :avatar, updated_at = NOW() WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'avatar' => $avatarUrl,
            'id' => $userId,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Get all users for this company
     */
    public function all($columns = ['*'], $where = '', $params = [])
    {
        if ($this->companyId) {
            $where = empty($where)
                ? "company_id = :company_id AND is_deleted = 0"
                : "({$where}) AND company_id = :company_id AND is_deleted = 0";
            $params['company_id'] = $this->companyId;
        }

        return parent::all($columns, $where, $params);
    }

    /**
     * Find user by ID with company validation
     */
    public function find($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id AND is_deleted = 0";
        $params = ['id' => $id];

        if ($this->companyId) {
            $sql .= " AND company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Delete user (soft delete with company validation)
     */
    public function delete($id, $softDelete = true): bool
    {
        // Verify user belongs to this company
        $user = $this->find($id);
        if (!$user) {
            throw new Exception('User not found or not accessible for this company');
        }

        if ($softDelete) {
            return $this->update($id, [
                'is_deleted' => 1,
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        $sql = "DELETE FROM {$this->table} WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
    }
}