<?php
/**
 * ONE-TIME ADMIN PASSWORD RESET
 * DELETE THIS FILE IMMEDIATELY AFTER USE
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

// ── Change these if you want different values ──
$newUsername = 'admin';
$newPassword = 'admin123';
// ───────────────────────────────────────────────

$db = Database::getInstance();

$admin = $db->fetch("SELECT id, username FROM users WHERE role = :r ORDER BY id ASC LIMIT 1", ['r' => ROLE_ADMIN]);

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($admin) {
    $db->update('users', [
        'username'  => $newUsername,
        'password'  => $hash,
        'is_active' => 1,
    ], 'id = :id', ['id' => $admin['id']]);
    echo "Admin updated (id={$admin['id']}).<br>";
} else {
    $id = $db->insert('users', [
        'username'  => $newUsername,
        'password'  => $hash,
        'full_name' => 'Administrator',
        'role'      => ROLE_ADMIN,
        'is_active' => 1,
    ]);
    echo "Admin created (id={$id}).<br>";
}

echo "Username: <b>{$newUsername}</b><br>";
echo "Password: <b>{$newPassword}</b><br>";
echo "<br><span style='color:red'>DELETE reset_admin_password.php NOW.</span>";
