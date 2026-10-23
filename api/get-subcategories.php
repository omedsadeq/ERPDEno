<?php
/**
 * API Endpoint: Get Subcategories by Category
 * سیستەمی زیرەکی کلینیکی ددان
 */

header('Content-Type: application/json');

// Do NOT include includes/header.php here — it renders the full HTML layout,
// which corrupts the JSON response. Bootstrap auth + DB directly instead.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$db = Database::getInstance();

// Get category ID from request
$categoryId = $_GET['category_id'] ?? null;

if (!$categoryId) {
    http_response_code(400);
    echo json_encode(['error' => 'Category ID is required']);
    exit;
}

// Get unique subcategories for this category from expenses table
$subcategories = $db->fetchAll(
    "SELECT DISTINCT subcategory
     FROM expenses
     WHERE category_id = :category_id
       AND subcategory IS NOT NULL
       AND subcategory != ''
     ORDER BY subcategory",
    ['category_id' => intval($categoryId)]
);

// If expense_subcategories table exists, get from there too
try {
    $predefinedSubcategories = $db->fetchAll(
        "SELECT subcategory_name as subcategory
         FROM expense_subcategories
         WHERE category_id = :category_id
           AND is_active = 1
         ORDER BY subcategory_name",
        ['category_id' => intval($categoryId)]
    );

    // Merge both arrays
    $allSubcategories = array_merge($subcategories, $predefinedSubcategories);

    // Remove duplicates
    $uniqueSubcategories = array_values(array_unique(array_column($allSubcategories, 'subcategory')));
} catch (Exception $e) {
    // Table doesn't exist, use only existing subcategories
    $uniqueSubcategories = array_column($subcategories, 'subcategory');
}

echo json_encode([
    'success' => true,
    'subcategories' => $uniqueSubcategories
]);
