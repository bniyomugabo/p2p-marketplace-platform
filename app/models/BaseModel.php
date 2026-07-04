<?php
// models/BaseModel.php
// ============================================
// BASE MODEL FOR ALL MODELS WITH COMPANY SUPPORT
// ============================================

abstract class BaseModel
{
    protected $db;
    protected $table;
    protected $primaryKey = 'id';
    protected $companyId = null;
    protected $hasCompanySupport = true; // Set to false for tables without company_id

    public function __construct($companyId = null)
    {
        $this->db = Database::getInstance();

        // Set company ID from parameter or session
        if ($companyId !== null) {
            $this->companyId = (int) $companyId;
        } elseif (isset($_SESSION['company_id'])) {
            $this->companyId = (int) $_SESSION['company_id'];
        } elseif (isset($_SESSION['user_id'])) {
            // Fallback: get user's default company
            $this->companyId = $this->getUserDefaultCompany($_SESSION['user_id']);
        }
    }

    /**
     * Get user's default company
     */
    protected function getUserDefaultCompany($userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT company_id FROM users 
                WHERE id = ? AND is_deleted = 0
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            return $user ? (int) $user['company_id'] : null;
        } catch (Exception $e) {
            error_log("Error getting user company: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set company context for this model instance
     */
    public function setCompanyContext($companyId)
    {
        $this->companyId = $companyId ? (int) $companyId : null;
        return $this;
    }

    /**
     * Get current company ID
     */
    public function getCompanyContext()
    {
        return $this->companyId;
    }

    /**
     * Build WHERE clause with company filter
     */
    protected function buildWhereWithCompany($where = '', $params = [])
    {
        // Check if table has company_id column
        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            if (empty($where)) {
                $where = "company_id = :company_id";
            } else {
                $where = "({$where}) AND company_id = :company_id";
            }
            $params['company_id'] = $this->companyId;
        }

        return ['where' => $where, 'params' => $params];
    }

    /**
     * Check if table has a specific column
     */
    protected function tableHasColumn($column)
    {
        try {
            $sql = "SHOW COLUMNS FROM {$this->table} LIKE :column";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['column' => $column]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Find record by ID (with company check)
     */
    public function find($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id";

        // Add company filter if applicable
        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND company_id = :company_id";
        }

        $stmt = $this->db->prepare($sql);
        $params = ['id' => $id];

        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $params['company_id'] = $this->companyId;
        }

        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get all records with optional company filter
     */
    public function all($columns = ['*'], $where = '', $params = [])
    {
        // Apply company filter
        $companyFilter = $this->buildWhereWithCompany($where, $params);
        $where = $companyFilter['where'];
        $params = $companyFilter['params'];

        $cols = implode(', ', $columns);
        $sql = "SELECT {$cols} FROM {$this->table}";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Create new record (automatically adds company_id)
     */
    public function create(array $data): int
    {
        // Auto-add company_id if applicable and not already set
        if (!isset($data['company_id'])) {
            $data['company_id'] = $this->companyId;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();
        //error_log("{$this->companyId} created - ID: " . $this->db->lastInsertId());
       // error_log("{$this->table} created - ID: " . $this->db->lastInsertId());
       // error_log("{$this->table} created - columns: " . $columns);
        //error_log("{$this->table} created - data: " . print_r($data, true));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update record (with company check)
     */
    public function update($id, array $data): bool
    {
        // Build SET clause
        $setClause = [];
        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $setClause);

        $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = :id";

        // Add company filter to ensure user can only update their own company's records
        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND company_id = :company_id";
        }

        $stmt = $this->db->prepare($sql);

        // Bind data values
        foreach ($data as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':id', $id);

        // Bind company ID if applicable
        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $stmt->bindValue(':company_id', $this->companyId);
        }

        return $stmt->execute();
    }

    /**
     * Delete record (with company check)
     */
    public function delete($id, $softDelete = true): bool
    {
        if ($softDelete && $this->columnExists('is_deleted')) {
            return $this->update($id, ['is_deleted' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);
        }

        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id";

        // Add company filter for hard delete
        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $sql .= " AND company_id = :company_id";
        }

        $stmt = $this->db->prepare($sql);
        $params = ['id' => $id];

        if ($this->hasCompanySupport && $this->companyId && $this->tableHasColumn('company_id')) {
            $params['company_id'] = $this->companyId;
        }

        return $stmt->execute($params);
    }

    /**
     * Get count of records with optional filters
     */
    public function count($where = '', $params = []): int
    {
        // Apply company filter
        $companyFilter = $this->buildWhereWithCompany($where, $params);
        $where = $companyFilter['where'];
        $params = $companyFilter['params'];

        $sql = "SELECT COUNT(*) as count FROM {$this->table}";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Check if column exists in table
     */
    protected function columnExists($column): bool
    {
        $sql = "SHOW COLUMNS FROM {$this->table} LIKE :column";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['column' => $column]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): void
    {
        $this->db->rollBack();
    }

    /**
     * Check if a transaction is currently active
     */
    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    /**
     * Get database connection (for complex queries)
     */
    public function getConnection()
    {
        return $this->db;
    }

    /**
     * Get table name
     */
    public function getTable()
    {
        return $this->table;
    }
}