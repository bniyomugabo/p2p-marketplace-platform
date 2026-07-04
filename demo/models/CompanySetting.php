<?php
// models/CompanySetting.php
// ============================================
// COMPANY SETTINGS MODEL
// ============================================

require_once __DIR__ . '/BaseModel.php';

class CompanySetting extends BaseModel
{
    protected $table = 'company_settings';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = true;

    /**
     * Constructor
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
     * Get all settings for the company
     */
    public function getAll()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT setting_key, setting_value, setting_type 
                FROM {$this->table} 
                WHERE company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $results = $stmt->fetchAll();

        $settings = [];
        foreach ($results as $row) {
            $value = $row['setting_value'];
            if ($row['setting_type'] === 'boolean') {
                $value = (bool) $value;
            } elseif ($row['setting_type'] === 'number') {
                $value = is_numeric($value) ? (float) $value : 0;
            } elseif ($row['setting_type'] === 'json') {
                $value = json_decode($value, true);
            }
            $settings[$row['setting_key']] = $value;
        }

        return $settings;
    }

    /**
     * Get a specific setting
     */
    public function get($key, $default = null)
    {
        if (!$this->companyId) {
            return $default;
        }

        $sql = "SELECT setting_value, setting_type 
                FROM {$this->table} 
                WHERE company_id = :company_id AND setting_key = :key";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'company_id' => $this->companyId,
            'key' => $key
        ]);
        $result = $stmt->fetch();

        if (!$result) {
            return $default;
        }

        $value = $result['setting_value'];
        if ($result['setting_type'] === 'boolean') {
            $value = (bool) $value;
        } elseif ($result['setting_type'] === 'number') {
            $value = is_numeric($value) ? (float) $value : 0;
        } elseif ($result['setting_type'] === 'json') {
            $value = json_decode($value, true);
        }

        return $value;
    }

    /**
     * Set a setting (insert or update)
     */
    public function set($key, $value, $type = 'text')
    {
        $this->validateCompanyContext();

        // Convert value based on type for storage
        $storedValue = $value;
        if ($type === 'boolean') {
            $storedValue = $value ? 1 : 0;
        } elseif ($type === 'json') {
            $storedValue = json_encode($value);
        } elseif ($type === 'number') {
            $storedValue = (string) $value;
        } else {
            $storedValue = (string) $value;
        }

        $sql = "INSERT INTO {$this->table} (company_id, setting_key, setting_value, setting_type, created_at, updated_at)
                VALUES (:company_id, :key, :value_1, :type_1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE setting_value = :value_2, setting_type = :type_2, updated_at = NOW()";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'company_id' => $this->companyId,
            'key' => $key,
            'value_1' => $storedValue,
            'value_2' => $storedValue,
            'type_1' => $type,
            'type_2' => $type
        ]);
    }

    /**
     * Set multiple settings at once
     */
    public function setMultiple(array $settings)
    {
        $this->validateCompanyContext();

        try {
            $this->beginTransaction();

            foreach ($settings as $key => $data) {
                if (is_array($data)) {
                    $value = $data['value'];
                    $type = $data['type'] ?? 'text';
                } else {
                    $value = $data;
                    $type = 'text';
                }
                $this->set($key, $value, $type);
            }

            $this->commit();
            return true;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }


    /**
     * Delete multiple settings
     */
    public function deleteMultiple(array $keys)
    {
        $this->validateCompanyContext();

        if (empty($keys)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "DELETE FROM {$this->table} 
                WHERE company_id = ? AND setting_key IN ($placeholders)";

        $params = array_merge([$this->companyId], $keys);
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Check if a setting exists
     */
    public function exists($key)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE company_id = :company_id AND setting_key = :key";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'company_id' => $this->companyId,
            'key' => $key
        ]);
        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get all settings as key-value pairs (flat array)
     */
    public function getFlatArray()
    {
        $settings = $this->getAll();
        $flat = [];
        foreach ($settings as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = $value;
            } else {
                $flat[$key] = json_encode($value);
            }
        }
        return $flat;
    }

    /**
     * Initialize default settings for a new company
     */
    public function initializeDefaults()
    {
        $this->validateCompanyContext();

        $defaults = [
            'date_format' => ['value' => 'd/m/Y', 'type' => 'text'],
            'timezone' => ['value' => 'Africa/Kigali', 'type' => 'text'],
            'language' => ['value' => 'en', 'type' => 'text'],
            'default_tax' => ['value' => 18, 'type' => 'number'],
            'auto_invoice' => ['value' => true, 'type' => 'boolean'],
            'show_invoice_footer' => ['value' => true, 'type' => 'boolean'],
            'invoice_footer_text' => ['value' => 'Thank you for your business!', 'type' => 'text'],
            'low_stock_alert' => ['value' => 10, 'type' => 'number'],
            'default_warehouse' => ['value' => 1, 'type' => 'number'],
            'allow_negative_stock' => ['value' => false, 'type' => 'boolean'],
            'auto_reorder' => ['value' => false, 'type' => 'boolean'],
            'email_notifications' => ['value' => true, 'type' => 'boolean'],
            'invoice_reminder_days' => ['value' => 3, 'type' => 'number'],
        ];

        return $this->setMultiple($defaults);
    }

    /**
     * Get company settings with company info merged
     */
    public function getMergedWithCompany()
    {
        $companyModel = new Company();
        $company = $companyModel->find($this->companyId);
        $settings = $this->getAll();

        if ($company) {
            // Merge company fields that can be overridden by settings
            $companyFields = [
                'company_name' => $company['company_name'],
                'company_email' => $company['email'],
                'company_phone' => $company['phone'],
                'company_address' => $company['address'],
                'currency' => $company['currency'],
                'tax_id' => $company['tax_id'],
                'vat_number' => $company['vat_number'],
                'invoice_prefix' => $company['invoice_prefix'],
                'quote_prefix' => $company['quote_prefix'],
                'po_prefix' => $company['po_prefix'],
            ];

            $settings = array_merge($companyFields, $settings);
        }

        return $settings;
    }

    /**
     * Export all settings as JSON
     */
    public function exportToJson()
    {
        $settings = $this->getMergedWithCompany();
        return json_encode($settings, JSON_PRETTY_PRINT);
    }

    /**
     * Import settings from JSON
     */
    public function importFromJson($json)
    {
        $this->validateCompanyContext();

        $data = json_decode($json, true);
        if (!$data) {
            throw new Exception('Invalid JSON data');
        }

        // Filter only known setting keys (excluding company fields)
        $knownSettingKeys = [
            'date_format',
            'timezone',
            'language',
            'default_tax',
            'auto_invoice',
            'show_invoice_footer',
            'invoice_footer_text',
            'low_stock_alert',
            'default_warehouse',
            'allow_negative_stock',
            'auto_reorder',
            'email_notifications',
            'invoice_reminder_days'
        ];

        $settings = [];
        foreach ($knownSettingKeys as $key) {
            if (isset($data[$key])) {
                $settings[$key] = $data[$key];
            }
        }

        return $this->setMultiple($settings);
    }

    /**
     * Get setting with type casting
     */
    public function getTyped($key, $default = null)
    {
        return $this->get($key, $default);
    }

    /**
     * Get boolean setting
     */
    public function getBool($key, $default = false)
    {
        $value = $this->get($key, $default);
        return (bool) $value;
    }

    /**
     * Get integer setting
     */
    public function getInt($key, $default = 0)
    {
        $value = $this->get($key, $default);
        return (int) $value;
    }

    /**
     * Get float setting
     */
    public function getFloat($key, $default = 0.0)
    {
        $value = $this->get($key, $default);
        return (float) $value;
    }

    /**
     * Get string setting
     */
    public function getString($key, $default = '')
    {
        $value = $this->get($key, $default);
        return (string) $value;
    }

    /**
     * Clear all settings for a company (reset to defaults)
     */
    public function resetToDefaults()
    {
        $this->validateCompanyContext();

        // Delete all existing settings
        $sql = "DELETE FROM {$this->table} WHERE company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);

        // Initialize defaults
        return $this->initializeDefaults();
    }
}