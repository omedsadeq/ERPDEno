<?php
/**
 * Permission Class
 * Dynamic Role-Based Permission System
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Permission {
    private $db;
    private static $instance = null;
    private $cachedPermissions = [];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if a role has permission
     * Admin always has all permissions
     */
    public function hasPermission($role, $permissionKey) {
        // Admin has all permissions
        if ($role === ROLE_ADMIN) {
            return true;
        }

        // Check cache first
        $cacheKey = $role . ':' . $permissionKey;
        if (isset($this->cachedPermissions[$cacheKey])) {
            return $this->cachedPermissions[$cacheKey];
        }

        // Query database
        $sql = "SELECT is_allowed FROM role_permissions WHERE role = :role AND permission_key = :permission_key";
        $result = $this->db->fetch($sql, [
            'role' => $role,
            'permission_key' => $permissionKey
        ]);

        // If no explicit permission, default to false for non-admin
        $isAllowed = $result ? (bool)$result['is_allowed'] : false;

        // Cache result
        $this->cachedPermissions[$cacheKey] = $isAllowed;

        return $isAllowed;
    }

    /**
     * Check if current user has permission
     */
    public function can($permissionKey) {
        $role = $_SESSION['role'] ?? null;
        if (!$role) {
            return false;
        }
        return $this->hasPermission($role, $permissionKey);
    }

    /**
     * Get all permissions for a role
     */
    public function getRolePermissions($role) {
        if ($role === ROLE_ADMIN) {
            // Admin has all permissions
            $sql = "SELECT permission_key, 1 as is_allowed FROM permission_definitions";
            return $this->db->fetchAll($sql);
        }

        $sql = "SELECT rp.permission_key, rp.is_allowed, pd.permission_name, pd.permission_name_ku, pd.category
                FROM role_permissions rp
                LEFT JOIN permission_definitions pd ON rp.permission_key = pd.permission_key
                WHERE rp.role = :role
                ORDER BY pd.category, pd.permission_name";
        return $this->db->fetchAll($sql, ['role' => $role]);
    }

    /**
     * Get all permission definitions grouped by category
     */
    public function getAllPermissionDefinitions() {
        $sql = "SELECT * FROM permission_definitions ORDER BY category, permission_name";
        $permissions = $this->db->fetchAll($sql);

        $grouped = [];
        foreach ($permissions as $perm) {
            $grouped[$perm['category']][] = $perm;
        }
        return $grouped;
    }

    /**
     * Update role permission
     */
    public function updatePermission($role, $permissionKey, $isAllowed) {
        // Cannot update admin permissions
        if ($role === ROLE_ADMIN) {
            return ['success' => false, 'message' => 'Cannot modify admin permissions'];
        }

        $sql = "INSERT INTO role_permissions (role, permission_key, is_allowed)
                VALUES (:role, :permission_key, :is_allowed)
                ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_at = NOW()";

        $this->db->query($sql, [
            'role' => $role,
            'permission_key' => $permissionKey,
            'is_allowed' => $isAllowed ? 1 : 0
        ]);

        // Clear cache
        $cacheKey = $role . ':' . $permissionKey;
        unset($this->cachedPermissions[$cacheKey]);

        return ['success' => true];
    }

    /**
     * Bulk update permissions for a role
     */
    public function bulkUpdatePermissions($role, $permissions) {
        if ($role === ROLE_ADMIN) {
            return ['success' => false, 'message' => 'Cannot modify admin permissions'];
        }

        foreach ($permissions as $key => $allowed) {
            $this->updatePermission($role, $key, $allowed);
        }

        return ['success' => true];
    }

    /**
     * Get all available roles (except admin)
     */
    public function getManageableRoles() {
        return [
            ROLE_DOCTOR => 'دکتۆر',
            ROLE_RECEPTIONIST => 'کارمەند',
            ROLE_XRAY_DEPARTMENT => 'بەشی ئێکسڕەی',
            ROLE_ACCOUNTANT => 'ژمێریار'
        ];
    }

    /**
     * Get role label in Kurdish
     */
    public function getRoleLabel($role) {
        $labels = [
            ROLE_ADMIN => 'بەڕێوەبەر',
            ROLE_DOCTOR => 'دکتۆر',
            ROLE_RECEPTIONIST => 'کارمەند',
            ROLE_XRAY_DEPARTMENT => 'بەشی ئێکسڕەی',
            ROLE_ACCOUNTANT => 'ژمێریار'
        ];
        return $labels[$role] ?? $role;
    }

    /**
     * Get category label in Kurdish
     */
    public function getCategoryLabel($category) {
        $labels = [
            'main' => 'سەرەکی',
            'patients' => 'نەخۆشەکان',
            'doctors' => 'دکتۆرەکان',
            'xray' => 'ئێکسڕەی',
            'financial' => 'دارایی',
            'expenses' => 'خەرجیەکان',
            'admin' => 'بەڕێوەبردن',
            'actions' => 'کردارەکان',
            'letters' => 'نوسراوی فەرمی'
        ];
        return $labels[$category] ?? $category;
    }

    /**
     * Check page access and redirect if not allowed
     */
    public function requirePageAccess($permissionKey) {
        if (!$this->can($permissionKey)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
    }

    /**
     * Check if user is receptionist
     */
    public function isReceptionist() {
        return ($_SESSION['role'] ?? null) === ROLE_RECEPTIONIST;
    }
}
