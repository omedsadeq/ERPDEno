<?php
/**
 * Cases Excel/CSV Export
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$db = Database::getInstance();
$caseManager = new CaseManager();
$isAdmin = $auth->isAdmin();
$currentUserId = $auth->getCurrentUser()['id'];
$doctorId = $isAdmin ? null : $currentUserId;

// Get filter parameters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterDoctorId = $isAdmin ? trim($_GET['doctor_id'] ?? '') : $currentUserId;
$paymentStatus = trim($_GET['payment_status'] ?? '');
$caseIds = isset($_GET['case_ids']) ? array_filter(explode(',', $_GET['case_ids'])) : [];

// Get cases based on filters or specific IDs
if (!empty($caseIds)) {
    $cases = getCasesByIds($db, $caseIds, $doctorId);
} else {
    $filters = [
        'status' => $status,
        'search' => $search,
        'doctor_id' => $filterDoctorId ?: $doctorId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ];

    if ($paymentStatus) {
        $cases = getCasesWithPaymentFilterAll($db, $filters, $paymentStatus);
    } else {
        $cases = getAllCasesForExport($db, $filters);
    }
}

// Calculate totals
$totalAmount = 0;
$totalPaid = 0;
$totalDiscount = 0;
$totalRemaining = 0;

// Invoice ledger, exactly what cases/index.php prints on screen.
foreach ($cases as $case) {
    $totalAmount += (float)$case['ledger_total'];
    $totalPaid += (float)$case['ledger_paid'];
    $totalDiscount += (float)$case['ledger_discount'];
    $totalRemaining += (float)$case['ledger_balance'];
}

// Set headers for Excel download
$filename = 'cases_report_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

// Generate Excel XML format (compatible with Excel 2007+)
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>ڕاپۆرتی کەیسەکان</Title>
  <Author><?= htmlspecialchars(CLINIC_NAME) ?></Author>
  <Created><?= date('c') ?></Created>
 </DocumentProperties>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10"/>
  </Style>
  <Style ss:ID="HeaderTitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="16" ss:Bold="1" ss:Color="#0078D4"/>
  </Style>
  <Style ss:ID="HeaderSub">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="11" ss:Color="#666666"/>
  </Style>
  <Style ss:ID="TableHeader">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#0078D4" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="TableCell">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="TableCellAlt">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10"/>
   <Interior ss:Color="#F9F9F9" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="AmountTotal">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10" ss:Bold="1" ss:Color="#0078D4"/>
   <NumberFormat ss:Format="#,##0"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="AmountPaid">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10" ss:Bold="1" ss:Color="#4CAF50"/>
   <NumberFormat ss:Format="#,##0"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="AmountRemaining">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10" ss:Bold="1" ss:Color="#F44336"/>
   <NumberFormat ss:Format="#,##0"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
  </Style>
  <Style ss:ID="SummaryHeader">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="12" ss:Bold="1"/>
   <Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="SummaryLabel">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="10" ss:Bold="1"/>
   <Interior ss:Color="#F5F5F5" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="SummaryValue">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Tahoma" ss:Size="11" ss:Bold="1" ss:Color="#0078D4"/>
   <NumberFormat ss:Format="#,##0"/>
   <Interior ss:Color="#F5F5F5" ss:Pattern="Solid"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="کەیسەکان">
  <Table ss:DefaultColumnWidth="80" ss:DefaultRowHeight="20">
   <Column ss:Index="1" ss:Width="40"/>
   <Column ss:Index="2" ss:Width="100"/>
   <Column ss:Index="3" ss:Width="120"/>
   <Column ss:Index="4" ss:Width="100"/>
   <Column ss:Index="5" ss:Width="80"/>
   <Column ss:Index="6" ss:Width="80"/>
   <Column ss:Index="7" ss:Width="70"/>
   <Column ss:Index="8" ss:Width="80"/>
   <Column ss:Index="9" ss:Width="60"/>
   <Column ss:Index="10" ss:Width="90"/>

   <!-- Header Row: Clinic Name -->
   <Row ss:Height="30">
    <Cell ss:MergeAcross="9" ss:StyleID="HeaderTitle"><Data ss:Type="String"><?= htmlspecialchars(CLINIC_NAME) ?></Data></Cell>
   </Row>

   <!-- Header Row: Report Title -->
   <Row ss:Height="25">
    <Cell ss:MergeAcross="9" ss:StyleID="HeaderSub"><Data ss:Type="String">ڕاپۆرتی کەیسەکان - <?= date('Y-m-d H:i') ?></Data></Cell>
   </Row>

   <!-- Date Range Row (if applicable) -->
<?php if ($dateFrom || $dateTo): ?>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="9" ss:StyleID="HeaderSub"><Data ss:Type="String">لە <?= $dateFrom ?: 'سەرەتا' ?> بۆ <?= $dateTo ?: 'ئێستا' ?></Data></Cell>
   </Row>
<?php endif; ?>

   <!-- Empty Row -->
   <Row ss:Height="10"></Row>

   <!-- Table Header -->
   <Row ss:Height="25">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">#</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">ژمارەی کەیس</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">نەخۆش</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">دکتۆر</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">کۆی گشتی</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">پارەدراو</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">داشکاندن</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">ماوە</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">دۆخ</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">بەروار</Data></Cell>
   </Row>

   <!-- Data Rows -->
<?php
$counter = 1;
$statusLabels = [
    'open' => 'کراوە',
    'closed' => 'داخراوە',
    'pending' => 'چاوەڕوان',
    'cancelled' => 'هەڵوەشاوە'
];

foreach ($cases as $index => $case):
    $remaining = (float)$case['ledger_balance'];
    $cellStyle = ($index % 2 == 0) ? 'TableCell' : 'TableCellAlt';
    $statusLabel = $statusLabels[$case['status']] ?? $case['status'];
?>
   <Row ss:Height="22">
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="Number"><?= $counter++ ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= htmlspecialchars($case['case_number']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= htmlspecialchars($case['patient_name']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String">د. <?= htmlspecialchars($case['doctor_name']) ?></Data></Cell>
    <Cell ss:StyleID="AmountTotal"><Data ss:Type="Number"><?= (float)$case['ledger_total'] ?></Data></Cell>
    <Cell ss:StyleID="AmountPaid"><Data ss:Type="Number"><?= (float)$case['ledger_paid'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="Number"><?= (float)$case['ledger_discount'] ?></Data></Cell>
    <Cell ss:StyleID="AmountRemaining"><Data ss:Type="Number"><?= $remaining ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= $statusLabel ?></Data></Cell>
    <Cell ss:StyleID="<?= $cellStyle ?>"><Data ss:Type="String"><?= date('Y-m-d', strtotime($case['created_at'])) ?></Data></Cell>
   </Row>
<?php endforeach; ?>

   <!-- Empty Row -->
   <Row ss:Height="15"></Row>

   <!-- Summary Section -->
   <Row ss:Height="25">
    <Cell ss:MergeAcross="9" ss:StyleID="SummaryHeader"><Data ss:Type="String">کورتەی داراییەکان</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">ژمارەی کەیس</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">کۆی گشتی</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">پارەی دراو</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">داشکان</Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryLabel"><Data ss:Type="String">کۆی ماوە</Data></Cell>
   </Row>
   <Row ss:Height="25">
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= count($cases) ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= $totalAmount ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= $totalPaid ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= $totalDiscount ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="SummaryValue"><Data ss:Type="Number"><?= $totalRemaining ?></Data></Cell>
   </Row>

  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <Header x:Margin="0.3"/>
    <Footer x:Margin="0.3"/>
    <PageMargins x:Bottom="0.75" x:Left="0.7" x:Right="0.7" x:Top="0.75"/>
   </PageSetup>
   <Print>
    <ValidPrinterInfo/>
    <HorizontalResolution>600</HorizontalResolution>
    <VerticalResolution>600</VerticalResolution>
   </Print>
   <Selected/>
   <DisplayRightToLeft/>
   <Panes>
    <Pane>
     <Number>3</Number>
     <ActiveRow>5</ActiveRow>
     <ActiveCol>0</ActiveCol>
    </Pane>
   </Panes>
   <ProtectObjects>False</ProtectObjects>
   <ProtectScenarios>False</ProtectScenarios>
  </WorksheetOptions>
 </Worksheet>
</Workbook>
<?php
exit;

/**
 * Get cases by specific IDs
 */
function getCasesByIds($db, $ids, $doctorId) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_map('intval', $ids);

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE c.id IN ($placeholders)";

    if ($doctorId) {
        $sql .= " AND pf.doctor_id = ?";
        $params[] = $doctorId;
    }

    $sql .= " ORDER BY c.created_at DESC";

    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all cases for export (no pagination)
 */
function getAllCasesForExport($db, $filters) {
    $params = [];

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    $sql .= " ORDER BY c.updated_at DESC";

    return $db->fetchAll($sql, $params);
}

/**
 * Get cases with payment status filter (no pagination)
 */
function getCasesWithPaymentFilterAll($db, $filters, $paymentStatus) {
    $params = [];

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    // Payment status filter — same ledger buckets as cases/ajax_cases_search.php.
    switch ($paymentStatus) {
        case 'paid':
            $sql .= " AND COALESCE(fs.approved_amount, 0) > 0 AND COALESCE(fs.balance, 0) <= 0.009";
            break;
        case 'partial':
            $sql .= " AND COALESCE(fs.paid_amount, 0) > 0 AND COALESCE(fs.balance, 0) > 0.009";
            break;
        case 'unpaid':
            $sql .= " AND COALESCE(fs.paid_amount, 0) <= 0 AND COALESCE(fs.balance, 0) > 0.009";
            break;
    }

    $sql .= " ORDER BY c.updated_at DESC";

    return $db->fetchAll($sql, $params);
}
