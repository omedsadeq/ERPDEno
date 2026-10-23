<?php
/**
 * Csrf - Cross-Site Request Forgery protection
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * A per-session token. The token is injected into every form and every
 * AJAX request automatically by includes/header.php; state-changing POST
 * handlers call Csrf::require() before doing any work.
 */

class Csrf {

    /** Get (and lazily create) the session token. */
    public static function token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Hidden input for use inside a <form>. */
    public static function field() {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Check the token sent with the current request (POST field or header). */
    public static function validate($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token']
                ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? '';
        }
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Abort the request when the token is missing/invalid.
     * Answers JSON for AJAX requests, plain 403 otherwise.
     */
    public static function require() {
        if (self::validate()) {
            return;
        }

        http_response_code(403);
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'داواکارییەکە ڕەتکرایەوە (CSRF). تکایە پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo 'داواکارییەکە ڕەتکرایەوە (CSRF). تکایە پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
        }
        exit;
    }
}
