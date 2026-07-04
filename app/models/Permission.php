<?php
// models/Permission.php
// ============================================
// PERMISSION MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Permission extends BaseModel
{
    protected $table = 'permissions';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = true; // Now permissions can be company-specific

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
            throw new Exception('Company context is required for company-specific operations');
        }
        return true;
    }

    /**
     * Verify permission belongs to company
     */
    protected function verifyCompanyOwnership($permissionId)
    {
        if (!$this->companyId) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$permissionId]);
        $permission = $stmt->fetch();

        if (!$permission) {
            throw new Exception('Permission not found');
        }

        // System permissions (company_id NULL) are available to all companies
        if ($permission['company_id'] === null) {
            return true;
        }

        if ($permission['company_id'] != $this->companyId) {
            throw new Exception('Permission does not belong to this company');
        }

        return true;
    }

    /**
     * Get all permissions for a company (including system permissions)
     */
    public function getAllGrouped()
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE company_id = :company_id OR company_id IS NULL 
                ORDER BY module, permission_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $permissions = $stmt->fetchAll();

        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }

        return $grouped;
    }

    /**
     * Get all permissions as flat array for a company
     */
    public function getAll()
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE company_id = :company_id OR company_id IS NULL 
                ORDER BY module, permission_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get permissions by module for a company
     */
    public function getByModule($module)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE module = :module 
                AND (company_id = :company_id OR company_id IS NULL)
                ORDER BY permission_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'module' => $module,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get permission by code for a company
     */
    public function getByCode($code)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE permission_code = :code 
                AND (company_id = :company_id OR company_id IS NULL)
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'code' => $code,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get role permissions for a company
     */
    public function getRolePermissions($roleId)
    {
        $sql = "SELECT p.* 
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = :role_id 
                AND (p.company_id = :company_id OR p.company_id IS NULL)
                ORDER BY p.module, p.permission_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_id' => $roleId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get role permission IDs for a company
     */
    public function getRolePermissionIds($roleId)
    {
        $sql = "SELECT rp.permission_id 
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = :role_id 
                AND (p.company_id = :company_id OR p.company_id IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_id' => $roleId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Check if role has permission
     */
    public function roleHasPermission($roleId, $permissionCode)
    {
        $sql = "SELECT COUNT(*) as count 
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = :role_id 
                AND p.permission_code = :permission_code
                AND (p.company_id = :company_id OR p.company_id IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_id' => $roleId,
            'permission_code' => $permissionCode,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get user permissions (through role) for a company
     */
    public function getUserPermissions($userId)
    {
        $sql = "SELECT DISTINCT p.* 
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                INNER JOIN users u ON u.role_id = rp.role_id
                WHERE u.id = :user_id 
                AND (p.company_id = :company_id OR p.company_id IS NULL)
                ORDER BY p.module, p.permission_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get user permission codes for a company
     */
    public function getUserPermissionCodes($userId)
    {
        $sql = "SELECT DISTINCT p.permission_code 
                FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                INNER JOIN users u ON u.role_id = rp.role_id
                WHERE u.id = :user_id 
                AND (p.company_id = :company_id OR p.company_id IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Check if user has permission for a company
     */
    public function userHasPermission($userId, $permissionCode)
    {
        $sql = "SELECT COUNT(*) as count 
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                JOIN users u ON u.role_id = rp.role_id
                WHERE u.id = :user_id 
                AND p.permission_code = :permission_code
                AND (p.company_id = :company_id OR p.company_id IS NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Create a new permission for a company
     */
    public function createPermission($module, $code, $name, $description = null, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        // Check if permission already exists for this company or system
        $sql = "SELECT id FROM {$this->table} 
                WHERE permission_code = :code 
                AND (company_id = :company_id OR company_id IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'code' => $code,
            'company_id' => $companyId
        ]);

        if ($stmt->fetch()) {
            throw new Exception("Permission with code '{$code}' already exists for this company.");
        }

        $data = [
            'company_id' => $companyId,
            'permission_code' => $code,
            'permission_name' => $name,
            'module' => $module,
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s')
        ];

        return $this->create($data);
    }

    /**
     * Assign permission to role (with company check)
     */
    public function assignPermission($roleId, $permissionId)
    {
        // Verify permission belongs to company
        $this->verifyCompanyOwnership($permissionId);

        // Check if already assigned
        $sql = "SELECT id FROM role_permissions 
                WHERE role_id = :role_id AND permission_id = :permission_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);

        if ($stmt->fetch()) {
            return true; // Already assigned
        }

        $sql = "INSERT INTO role_permissions (role_id, permission_id, company_id, created_at) 
                VALUES (:role_id, :permission_id, :company_id, NOW())";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Remove permission from role
     */
    public function removePermission($roleId, $permissionId)
    {
        $sql = "DELETE rp FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = :role_id 
                AND rp.permission_id = :permission_id
                AND (p.company_id = :company_id OR p.company_id IS NULL)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Sync permissions for a role
     */
    public function syncPermissions($roleId, array $permissionIds)
    {
        try {
            $this->beginTransaction();

            // Remove all existing permissions for this role (only company-specific ones)
            $deleteSql = "DELETE rp FROM role_permissions rp
                          JOIN permissions p ON rp.permission_id = p.id
                          WHERE rp.role_id = :role_id 
                          AND (p.company_id = :company_id OR p.company_id IS NULL)";
            $deleteStmt = $this->db->prepare($deleteSql);
            $deleteStmt->execute([
                'role_id' => $roleId,
                'company_id' => $this->companyId
            ]);

            // Add new permissions
            foreach ($permissionIds as $permissionId) {
                $this->assignPermission($roleId, $permissionId);
            }

            $this->commit();
            return true;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get modules list for a company
     */
    public function getModules()
    {
        $sql = "SELECT DISTINCT module FROM {$this->table} 
                WHERE company_id = :company_id OR company_id IS NULL 
                ORDER BY module";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get permission statistics for a company
     */
    public function getStats()
    {
        $sql = "SELECT 
                    COUNT(*) as total_permissions,
                    COUNT(DISTINCT module) as total_modules,
                    SUM(CASE WHEN company_id = :company_id THEN 1 ELSE 0 END) as company_permissions,
                    SUM(CASE WHEN company_id IS NULL THEN 1 ELSE 0 END) as system_permissions
                FROM {$this->table}
                WHERE company_id = :company_id OR company_id IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Initialize default system permissions (run once during installation)
     */
    public function installSystemPermissions()
    {
        $defaultPermissions = [
            // Dashboard
            ['module' => 'dashboard', 'code' => 'dashboard_view', 'name' => 'View Dashboard'],

            // Products
            ['module' => 'products', 'code' => 'products_view', 'name' => 'View Products'],
            ['module' => 'products', 'code' => 'products_create', 'name' => 'Create Products'],
            ['module' => 'products', 'code' => 'products_edit', 'name' => 'Edit Products'],
            ['module' => 'products', 'code' => 'products_delete', 'name' => 'Delete Products'],

            // Inventory
            ['module' => 'inventory', 'code' => 'inventory_view', 'name' => 'View Inventory'],
            ['module' => 'inventory', 'code' => 'inventory_manage', 'name' => 'Manage Inventory'],
            ['module' => 'inventory', 'code' => 'inventory_adjust', 'name' => 'Adjust Stock'],

            // Sales
            ['module' => 'sales', 'code' => 'sales_view', 'name' => 'View Sales'],
            ['module' => 'sales', 'code' => 'sales_create', 'name' => 'Create Sales'],
            ['module' => 'sales', 'code' => 'sales_edit', 'name' => 'Edit Sales'],
            ['module' => 'sales', 'code' => 'sales_delete', 'name' => 'Delete Sales'],

            // Quotations
            ['module' => 'quotations', 'code' => 'quotations_view', 'name' => 'View Quotations'],
            ['module' => 'quotations', 'code' => 'quotations_create', 'name' => 'Create Quotations'],
            ['module' => 'quotations', 'code' => 'quotations_edit', 'name' => 'Edit Quotations'],
            ['module' => 'quotations', 'code' => 'quotations_delete', 'name' => 'Delete Quotations'],

            // Purchasing
            ['module' => 'purchasing', 'code' => 'purchasing_view', 'name' => 'View Purchasing'],
            ['module' => 'purchasing', 'code' => 'purchasing_create', 'name' => 'Create Purchase Orders'],
            ['module' => 'purchasing', 'code' => 'purchasing_approve', 'name' => 'Approve Purchase Orders'],

            // Reports
            ['module' => 'reports', 'code' => 'reports_view', 'name' => 'View Reports'],
            ['module' => 'reports', 'code' => 'reports_export', 'name' => 'Export Reports'],

            // Administration
            ['module' => 'admin', 'code' => 'admin_access', 'name' => 'Access Administration'],
            ['module' => 'admin', 'code' => 'users_manage', 'name' => 'Manage Users'],
            ['module' => 'admin', 'code' => 'roles_manage', 'name' => 'Manage Roles'],
            ['module' => 'admin', 'code' => 'settings_manage', 'name' => 'Manage Settings'],
            ['module' => 'admin', 'code' => 'permissions_manage', 'name' => 'Manage Permissions'],
        ];

        $created = 0;
        foreach ($defaultPermissions as $perm) {
            try {
                $this->createPermission($perm['module'], $perm['code'], $perm['name'], null, null);
                $created++;
            } catch (Exception $e) {
                // Permission already exists, skip
                continue;
            }
        }

        return $created;
    }

    /**
     * Copy system permissions to a company (for customization)
     */
    public function copyToCompany($companyId)
    {
        $sql = "SELECT * FROM permissions WHERE company_id IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $systemPermissions = $stmt->fetchAll();

        $copied = 0;
        foreach ($systemPermissions as $perm) {
            try {
                $this->createPermission(
                    $perm['module'],
                    $perm['permission_code'],
                    $perm['permission_name'],
                    $perm['description'],
                    $companyId
                );
                $copied++;
            } catch (Exception $e) {
                // Already exists, skip
                continue;
            }
        }

        return $copied;
    }
}