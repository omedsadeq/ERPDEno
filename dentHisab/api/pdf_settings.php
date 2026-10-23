<?php
/**
 * PDF Settings API Endpoint
 * Provides CRUD operations for PDF invoice settings
 *
 * Actions:
 * - get: Get active PDF settings
 * - update: Update PDF settings
 * - upload_header: Upload header image
 * - upload_footer: Upload footer image
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/PdfSettings.php';

$auth = Auth::getInstance();
$pdfSettings = new PdfSettings();

// Check authentication
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'نەخولی چوونە ژوورەوە']);
    exit;
}

$action = $_REQUEST['action'] ?? 'get';
$response = ['success' => false, 'message' => ''];

// CSRF: protect every state-changing action (the 'get' read stays open).
if (!in_array($action, ['get', 'list', 'fetch', ''], true)) {
    Csrf::require();
}

try {
    switch ($action) {
        case 'get':
            // Get active settings
            $settings = $pdfSettings->getActiveSettings();

            if (!$settings) {
                $settings = $pdfSettings->getDefaults();
            }

            $response = [
                'success' => true,
                'data' => $settings
            ];
            break;

        case 'update':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            $data = $_POST['pdf'] ?? [];

            if (empty($data)) {
                $response = ['success' => false, 'message' => 'داتا نییە'];
                break;
            }

            $existingSettings = $pdfSettings->getActiveSettings();

            if ($existingSettings) {
                $pdfSettings->update($existingSettings['id'], $data);
            } else {
                $data['is_active'] = 1;
                $pdfSettings->create($data);
            }

            $response = [
                'success' => true,
                'message' => 'ڕێکخستنەکان پاشەکەوتکران',
                'data' => $pdfSettings->getActiveSettings()
            ];
            break;

        case 'upload_header':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            if (!isset($_FILES['header_image']) || $_FILES['header_image']['error'] !== UPLOAD_ERR_OK) {
                $response = ['success' => false, 'message' => 'هەڵەی هەڵگرتنی فایل'];
                break;
            }

            $path = $pdfSettings->uploadHeaderImage($_FILES['header_image']);

            if ($path) {
                $existingSettings = $pdfSettings->getActiveSettings();
                if ($existingSettings) {
                    $pdfSettings->update($existingSettings['id'], ['header_image_path' => $path]);
                }

                $response = [
                    'success' => true,
                    'message' => 'وێنەی سەرپەڕە هەڵگیرا',
                    'path' => $path
                ];
            } else {
                $response = ['success' => false, 'message' => 'هەڵەی هەڵگرتنی وێنە'];
            }
            break;

        case 'upload_footer':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            if (!isset($_FILES['footer_image']) || $_FILES['footer_image']['error'] !== UPLOAD_ERR_OK) {
                $response = ['success' => false, 'message' => 'هەڵەی هەڵگرتنی فایل'];
                break;
            }

            $path = $pdfSettings->uploadFooterImage($_FILES['footer_image']);

            if ($path) {
                $existingSettings = $pdfSettings->getActiveSettings();
                if ($existingSettings) {
                    $pdfSettings->update($existingSettings['id'], ['footer_image_path' => $path]);
                }

                $response = [
                    'success' => true,
                    'message' => 'وێنەی ژێرپەڕە هەڵگیرا',
                    'path' => $path
                ];
            } else {
                $response = ['success' => false, 'message' => 'هەڵەی هەڵگرتنی وێنە'];
            }
            break;

        case 'delete_header_image':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            $existingSettings = $pdfSettings->getActiveSettings();
            if ($existingSettings) {
                $pdfSettings->update($existingSettings['id'], ['header_image_path' => '']);
                $response = ['success' => true, 'message' => 'وێنەی سەرپەڕە سڕایەوە'];
            } else {
                $response = ['success' => false, 'message' => 'ڕێکخستن نەدۆزرایەوە'];
            }
            break;

        case 'delete_footer_image':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            $existingSettings = $pdfSettings->getActiveSettings();
            if ($existingSettings) {
                $pdfSettings->update($existingSettings['id'], ['footer_image_path' => '']);
                $response = ['success' => true, 'message' => 'وێنەی ژێرپەڕە سڕایەوە'];
            } else {
                $response = ['success' => false, 'message' => 'ڕێکخستن نەدۆزرایەوە'];
            }
            break;

        case 'get_defaults':
            $response = [
                'success' => true,
                'data' => $pdfSettings->getDefaults()
            ];
            break;

        case 'reset_to_defaults':
            // Admin only
            if (!$auth->isAdmin()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'مۆڵەتی ئەم کردارەت نییە'];
                break;
            }

            $defaults = $pdfSettings->getDefaults();
            $existingSettings = $pdfSettings->getActiveSettings();

            if ($existingSettings) {
                $pdfSettings->update($existingSettings['id'], $defaults);
            } else {
                $pdfSettings->create($defaults);
            }

            $response = [
                'success' => true,
                'message' => 'ڕێکخستنەکان گەڕێنرانەوە بۆ بنەڕەت',
                'data' => $pdfSettings->getActiveSettings()
            ];
            break;

        default:
            $response = ['success' => false, 'message' => 'کرداری نەناسراو'];
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'هەڵە: ' . $e->getMessage()
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
