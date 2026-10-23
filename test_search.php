<?php
require 'config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require 'classes/Database.php';

$_GET['q'] = 'a';
$_GET['date_from'] = '2024-05-01';

$q         = trim($_GET['q']         ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');

$db = Database::getInstance();
$where    = '1=1';
$params   = [];

if ($q !== '') {
    $terms = preg_split('/\s+/', $q);
    $searchConditions = [];
    foreach ($terms as $i => $term) {
        if (trim($term) === '') continue;
        $pName = 'search_' . $i;
        $searchConditions[] = "(v.patient_name LIKE :$pName OR v.mobile_number LIKE :$pName OR v.procedure_treatment LIKE :$pName OR v.referral_source LIKE :$pName)";
        $params[$pName] = '%' . trim($term) . '%';
    }
    if (!empty($searchConditions)) {
        $where .= " AND (" . implode(' AND ', $searchConditions) . ")";
    }
}
if ($date_from !== '' && $q === '') {
    $where .= ' AND v.visit_date >= :df';
    $params['df'] = $date_from;
}
if ($date_to !== '' && $q === '') {
    $where .= ' AND v.visit_date <= :dt';
    $params['dt'] = $date_to;
}

echo "WHERE: $where\n";
print_r($params);
