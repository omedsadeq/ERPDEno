<?php
/**
 * Authentication Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Login user
     */
    public function login($username, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $identifier = mb_strtolower(trim((string)$username)) . '|' . $ip;

        // Brute-force throttle: block after too many recent failures
        if ($this->isLoginBlocked($identifier)) {
            return ['success' => false, 'message' => 'هەوڵی زۆری چوونەژوورەوە. تکایە چەند خولەکێک چاوەڕێ بکە و دووبارە هەوڵ بدەرەوە.'];
        }

        $sql = "SELECT * FROM users WHERE username = :username AND is_active = 1";
        $user = $this->db->fetch($sql, ['username' => $username]);

        if ($user && password_verify($password, $user['password'])) {
            // Prevent session fixation: new session id + new CSRF token on login
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['profile_image'] = $user['profile_image'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();

            $this->clearLoginAttempts($identifier);
            // Log activity
            $this->logActivity($user['id'], 'login', 'users', $user['id']);

            return ['success' => true, 'user' => $user];
        }

        $this->recordLoginAttempt($identifier, $ip);
        return ['success' => false, 'message' => 'ناوی بەکارهێنەر یان وشەی نهێنی هەڵەیە'];
    }

    /** True when 6+ failed attempts occurred for this identifier in the last 15 min. */
    private function isLoginBlocked($identifier) {
        try {
            if (!$this->db->tableExists('login_attempts')) {
                return false;
            }
            $row = $this->db->fetch(
                "SELECT COUNT(*) AS c FROM login_attempts
                 WHERE identifier = :i AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                ['i' => $identifier]
            );
            return (int)($row['c'] ?? 0) >= 6;
        } catch (Exception $e) {
            return false; // fail open — never lock people out on an infra error
        }
    }

    private function recordLoginAttempt($identifier, $ip) {
        try {
            if ($this->db->tableExists('login_attempts')) {
                $this->db->insert('login_attempts', ['identifier' => $identifier, 'ip_address' => $ip]);
            }
        } catch (Exception $e) {
            // best-effort
        }
    }

    private function clearLoginAttempts($identifier) {
        try {
            if ($this->db->tableExists('login_attempts')) {
                $this->db->delete('login_attempts', 'identifier = :i', ['i' => $identifier]);
            }
        } catch (Exception $e) {
            // best-effort
        }
    }

    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id']);
        }

        session_unset();
        session_destroy();

        // Expire the session cookie in the browser too, so the stale cookie
        // value can't linger (PWA/back-button hardening).
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
            ]);
        }

        return true;
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->isLoggedIn() && $_SESSION['role'] === ROLE_ADMIN;
    }

    /**
     * Check if user is doctor
     */
    public function isDoctor() {
        return $this->isLoggedIn() && $_SESSION['role'] === ROLE_DOCTOR;
    }

    /**
     * Check if user is X-Ray department
     */
    public function isXrayDepartment() {
        return $this->isLoggedIn() && $_SESSION['role'] === ROLE_XRAY_DEPARTMENT;
    }

    /**
     * Check if user is receptionist
     */
    public function isReceptionist() {
        return $this->isLoggedIn() && $_SESSION['role'] === ROLE_RECEPTIONIST;
    }

    /**
     * Check if user is accountant
     */
    public function isAccountant() {
        return $this->isLoggedIn() && $_SESSION['role'] === ROLE_ACCOUNTANT;
    }

    /**
     * Require X-Ray department, Accountant, or Admin access
     */
    public function requireXrayAccess() {
        $this->requireLogin();
        if (!$this->isAdmin() && !$this->isXrayDepartment() && !$this->isAccountant()) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
    }

    /**
     * Check if user can request X-Ray (doctors, X-Ray dept, accountants, and admins)
     */
    public function canRequestXray() {
        return $this->isLoggedIn() && ($this->isAdmin() || $this->isDoctor() || $this->isXrayDepartment() || $this->isAccountant());
    }

    /**
     * Check if user can process X-Ray payments (Accountant and admins)
     * Workflow: Doctor → Accountant for billing/payment
     */
    public function canProcessXray() {
        return $this->isLoggedIn() && ($this->isAdmin() || $this->isAccountant() || $this->isXrayDepartment());
    }

    /**
     * Check if user can handle X-Ray payments (Accountant and admins)
     */
    public function canHandleXrayPayments() {
        return $this->isLoggedIn() && ($this->isAdmin() || $this->isAccountant());
    }

    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $sql = "SELECT * FROM users WHERE id = :id";
        return $this->db->fetch($sql, ['id' => $_SESSION['user_id']]);
    }

    /**
     * Get current user ID
     */
    public function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user role
     */
    public function getCurrentUserRole() {
        return $_SESSION['role'] ?? null;
    }

    /**
     * Check permission
     */
    public function hasPermission($permission) {
        if ($this->isAdmin()) {
            return true;
        }

        // Add more permission logic here
        return false;
    }

    /**
     * Require login
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }

        // Sensitive pages must never be stored by the browser, a shared proxy,
        // or the PWA service worker — prevents viewing patient/payment data
        // via back-button or cache after logout.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    /**
     * Require admin
     */
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
    }

    /**
     * Create new user
     */
    public function createUser($data) {
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        try {
            $userId = $this->db->insert('users', $data);
            $this->logActivity($_SESSION['user_id'] ?? null, 'create_user', 'users', $userId);
            if (class_exists('DoctorSync')) {
                DoctorSync::syncFromUser($userId);
            }
            return ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update user
     */
    public function updateUser($id, $data) {
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        try {
            $this->db->update('users', $data, 'id = :id', ['id' => $id]);
            $this->logActivity($_SESSION['user_id'] ?? null, 'update_user', 'users', $id);
            if (class_exists('DoctorSync')) {
                DoctorSync::syncFromUser($id);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Change password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        $sql = "SELECT password FROM users WHERE id = :id";
        $user = $this->db->fetch($sql, ['id' => $userId]);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'وشەی نهێنی ئێستا هەڵەیە'];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->update('users', ['password' => $hashedPassword], 'id = :id', ['id' => $userId]);

        $this->logActivity($userId, 'change_password', 'users', $userId);

        return ['success' => true, 'message' => 'وشەی نهێنی گۆڕدرا'];
    }

    /**
     * Log activity
     */
    private function logActivity($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        $data = [
            'user_id' => $userId,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        $this->db->insert('activity_log', $data);
    }

    /**
     * Get all users
     */
    public function getAllUsers($role = null) {
        $sql = "SELECT * FROM users WHERE 1=1";
        $params = [];

        if ($role) {
            $sql .= " AND role = :role";
            $params['role'] = $role;
        }

        $sql .= " ORDER BY created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get user by ID
     */
    public function getUserById($id) {
        $sql = "SELECT * FROM users WHERE id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Delete user
     */
    public function deleteUser($id) {
        if ($id == $_SESSION['user_id']) {
            return ['success' => false, 'message' => 'ناتوانیت خۆت بسڕیتەوە'];
        }

        try {
            // Soft delete: users are referenced by visits/payments/audit rows,
            // so the account is deactivated instead of removed.
            $this->db->update('users', ['is_active' => 0], 'id = :id', ['id' => $id]);
            $this->logActivity($_SESSION['user_id'], 'deactivate_user', 'users', $id);
            if (class_exists('DoctorSync')) {
                DoctorSync::syncFromUser($id);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get doctors only
     */
    public function getDoctors() {
        return $this->getAllUsers(ROLE_DOCTOR);
    }
}
