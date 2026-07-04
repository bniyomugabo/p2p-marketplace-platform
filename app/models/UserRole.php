<?php
// models/UserRole.php
// ============================================
// USER ROLE MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class UserRole extends BaseModel
{
    protected $table = 'user_roles';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = false; // Roles table has company_id but can be NULL for system roles

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Find role by code (company-specific if applicable)
     */
    public function findByCode($code)
    {
        $sql = "SELECT * FROM {$this->table} WHERE role_code = :code";

        $params = ['code' => $code];

        // If company_id column exists and we have company context, filter by it
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND (company_id = :company_id OR company_id IS NULL)";
            $params['company_id'] = $this->companyId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Find role by name (company-specific if applicable)
     */
    public function findByName($name)
    {
        $sql = "SELECT * FROM {$this->table} WHERE role_name = :name";

        $params = ['name' => $name];

        // If company_id column exists and we have company context, filter by it
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND (company_id = :company_id OR company_id IS NULL)";
            $params['company_id'] = $this->companyId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get all roles with user count (company-specific)
     */
    public function getAllWithUserCount()
    {
        $sql = "
            SELECT 
                r.*,
                COUNT(u.id) as user_count
            FROM {$this->table} r
            LEFT JOIN users u ON r.id = u.role_id AND u.is_deleted = 0
        ";

        $params = [];

        // If company_id column exists and we have company context, filter by it
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " WHERE (r.company_id = :company_id OR r.company_id IS NULL)";
            $params['company_id'] = $this->companyId;
        }

        $sql .= " GROUP BY r.id ORDER BY r.role_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get role options for dropdown (company-specific)
     */
    public function getOptions($selected = null)
    {
        $sql = "SELECT id, role_code, role_name, is_system_role FROM {$this->table}";

        $params = [];

        // If company_id column exists and we have company context, filter by it
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " WHERE (company_id = :company_id OR company_id IS NULL)";
            $params['company_id'] = $this->companyId;
        }

        $sql .= " ORDER BY is_system_role DESC, role_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $roles = $stmt->fetchAll();

        $options = [];
        foreach ($roles as $role) {
            $text = $role['role_name'] . ' (' . $role['role_code'] . ')';
            if ($role['is_system_role']) {
                $text .= ' [System]';
            }
            $options[] = [
                'value' => $role['id'],
                'text' => $text,
                'selected' => ($selected == $role['id'])
            ];
        }

        return $options;
    }

    /**
     * Check if role is system role (company_id IS NULL)
     */
    public function isSystemRole($roleId): bool
    {
        $sql = "SELECT is_system_role, company_id FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $roleId]);
        $result = $stmt->fetch();

        // If company_id is NULL, it's a system-wide role
        return $result ? ($result['is_system_role'] == 1 || $result['company_id'] === null) : false;
    }

    /**
     * Create a new role (company-specific if applicable)
     */
    public function createRole($data): int
    {
        // Validate required fields
        if (empty($data['role_code']) || empty($data['role_name'])) {
            throw new Exception('Role code and name are required');
        }

        // Check if role code already exists in this company
        $sql = "SELECT id FROM {$this->table} WHERE role_code = :role_code";
        $params = ['role_code' => $data['role_code']];

        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND (company_id = :company_id OR company_id IS NULL)";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetch()) {
            throw new Exception('Role code already exists in this company');
        }

        // Add company_id if not set and we have company context
        if ($this->companyId && !isset($data['company_id']) && $this->tableHasColumn('company_id')) {
            $data['company_id'] = $this->companyId;
        }

        // Set created_at if not provided
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $this->create($data);
    }

    /**
     * Update role (with company check)
     */
    public function updateRole($id, $data): bool
    {
        // Don't allow updating system roles' code or name
        if ($this->isSystemRole($id)) {
            unset($data['role_code']);
            unset($data['role_name']);
            unset($data['company_id']);
        }

        // Verify role belongs to this company (if company-specific)
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $role = $this->find($id);
            if ($role && $role['company_id'] && $role['company_id'] != $this->companyId) {
                throw new Exception("Role does not belong to this company");
            }
        }

        // Add updated_at timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->update($id, $data);
    }

    /**
     * Delete role (with company check)
     */
    public function deleteRole($id): bool
    {
        // Check if system role
        if ($this->isSystemRole($id)) {
            throw new Exception('System roles cannot be deleted');
        }

        // Verify role belongs to this company (if company-specific)
        if ($this->companyId && $this->tableHasColumn('company_id')) {
            $role = $this->find($id);
            if ($role && $role['company_id'] && $role['company_id'] != $this->companyId) {
                throw new Exception("Role does not belong to this company");
            }
        }

        // Check if role has users in this company
        $sql = "SELECT COUNT(*) as count FROM users WHERE role_id = :role_id AND is_deleted = 0";
        $params = ['role_id' => $id];

        if ($this->companyId) {
            $sql .= " AND company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        if ($result && $result['count'] > 0) {
            throw new Exception('Cannot delete role that has users assigned');
        }

        // Delete role permissions first
        $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
        $stmt->execute(['role_id' => $id]);

        // Then delete the role
        return parent::delete($id, false); // Hard delete
    }



    /**
     * Assign permission to role
     */
    public function assignPermission($roleId, $permissionId): bool
    {
        // Check if already assigned
        $sql = "SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);

        if ($stmt->fetch()) {
            return true; // Already assigned
        }

        $sql = "INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (:role_id, :permission_id, NOW())";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);
    }

    /**
     * Remove permission from role
     */
    public function removePermission($roleId, $permissionId): bool
    {
        $sql = "DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);
    }


    /**
     * Get all roles for a company (including system roles)
     */
    public function getCompanyRoles($companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        $sql = "SELECT * FROM {$this->table} WHERE company_id = :company_id OR company_id IS NULL ORDER BY role_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get system roles only
     */
    public function getSystemRoles()
    {
        $sql = "SELECT * FROM {$this->table} WHERE company_id IS NULL AND is_system_role = 1 ORDER BY role_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get custom roles for a company (non-system)
     */
    public function getCustomRoles($companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        $sql = "SELECT * FROM {$this->table} WHERE company_id = :company_id AND is_system_role = 0 ORDER BY role_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Duplicate a role for a company (create copy)
     */
    public function duplicateRole($sourceRoleId, $newRoleCode, $newRoleName, $companyId = null): int
    {
        $companyId = $companyId ?? $this->companyId;

        try {
            $this->beginTransaction();

            // Get source role
            $sourceRole = $this->find($sourceRoleId);
            if (!$sourceRole) {
                throw new Exception('Source role not found');
            }

            // Create new role
            $newRoleData = [
                'role_code' => $newRoleCode,
                'role_name' => $newRoleName,
                'description' => $sourceRole['description'] . ' (Copy)',
                'company_id' => $companyId,
                'is_system_role' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $newRoleId = $this->create($newRoleData);

            // Copy permissions
            $permissions = $this->getPermissions($sourceRoleId);
            foreach ($permissions as $permission) {
                $this->assignPermission($newRoleId, $permission['id']);
            }

            $this->commit();
            return $newRoleId;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get role statistics
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_roles' => 0,
                'system_roles' => 0,
                'custom_roles' => 0,
                'total_permissions' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT r.id) as total_roles,
                SUM(CASE WHEN r.company_id IS NULL AND r.is_system_role = 1 THEN 1 ELSE 0 END) as system_roles,
                SUM(CASE WHEN r.company_id = :company_id AND r.is_system_role = 0 THEN 1 ELSE 0 END) as custom_roles,
                (SELECT COUNT(*) FROM permissions) as total_permissions
            FROM {$this->table} r
            WHERE r.company_id = :company_id OR r.company_id IS NULL
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }
    /**
 * Get permissions for a role (company-specific)
 */
public function getPermissions($roleId)
{
    $permissionModel = new Permission($this->companyId);
    return $permissionModel->getRolePermissions($roleId);
}

/**
 * Sync permissions for a role (company-specific)
 */
public function syncPermissions($roleId, array $permissionIds)
{
    $permissionModel = new Permission($this->companyId);
    return $permissionModel->syncPermissions($roleId, $permissionIds);
}
}