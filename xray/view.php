<?php
/**
 * View X-Ray Request Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/S3Uploader.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$requestId = intval($_GET['id'] ?? 0);
if (!$requestId) {
    header('Location: ' . APP_URL . '/xray/index.php');
    exit;
}

$xrayManager = new XrayManager();
$request = $xrayManager->getById($requestId);

if (!$request) {
    header('Location: ' . APP_URL . '/xray/index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isXrayDept = $auth->isXrayDepartment();
$isAccountant = $auth->isAccountant();
$canProcess = $isAdmin || $isXrayDept;
$canHandlePayments = $isAdmin || $isAccountant;

// Check access - Admin-controlled permission system
// - Admin/X-ray department: full access
// - Doctor who requested: always can view their own request
// - Other doctors: only if they have can_view_other_xrays permission
$canView = $canProcess || $request['requested_by'] == $currentUser['id'];

if (!$canView && $auth->isDoctor()) {
    // Check if doctor has permission to view other doctors' X-rays
    $patientManager = new Patient();
    $canViewOtherXrays = $patientManager->canDoctorViewOtherXrays($currentUser['id']);
    if ($canViewOtherXrays) {
        $canView = true;
        // Log access for audit
        $patientManager->logAccess($request['patient_id'], $currentUser['id'], 'view_xray', json_encode(['xray_id' => $requestId]));
    }
}

if (!$canView) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Get images
$images = $xrayManager->getImages($requestId);

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'upload_image' && $canProcess) {
        if (isset($_FILES['xray_image']) && $_FILES['xray_image']['error'] === UPLOAD_ERR_OK) {
            $description = trim($_POST['image_description'] ?? '');
            $result = $xrayManager->uploadImage($requestId, $_FILES['xray_image'], $currentUser['id'], $description);
            if ($result['success']) {
                if ($request['request_status'] === 'pending_xray' || $request['status'] === 'pending') {
                    $xrayManager->startXray($requestId, $currentUser['id'], $_SESSION['role'] ?? ROLE_XRAY_DEPARTMENT);
                }
                header('Location: ' . APP_URL . '/xray/view.php?id=' . $requestId . '&uploaded=1');
                exit;
            } else {
                $message = $result['message'];
                $messageType = 'error';
            }
        } else {
            $message = 'تکایە فایلێک هەڵبژێرە';
            $messageType = 'error';
        }
    } elseif ($action === 'complete' && $canProcess) {
        $notes = trim($_POST['completion_notes'] ?? '');
        $result = $xrayManager->completeXray($requestId, $currentUser['id'], $_SESSION['role'] ?? ROLE_XRAY_DEPARTMENT, $notes);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/xray/view.php?id=' . $requestId . '&completed=1');
            exit;
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    } elseif ($action === 'start_progress' && $canProcess) {
        $xrayManager->startXray($requestId, $currentUser['id'], $_SESSION['role'] ?? ROLE_XRAY_DEPARTMENT);
        header('Location: ' . APP_URL . '/xray/view.php?id=' . $requestId);
        exit;
    } elseif ($action === 'cancel' && ($canProcess || $request['requested_by'] == $currentUser['id'])) {
        $reason = trim($_POST['cancel_reason'] ?? '');
        $result = $xrayManager->cancelWorkflowRequest($requestId, $currentUser['id'], $_SESSION['role'] ?? '', $reason);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/xray/index.php?cancelled=1');
            exit;
        }
    } elseif ($action === 'delete_image' && $canProcess) {
        $imageId = intval($_POST['image_id']);
        $xrayManager->deleteImage($imageId, $requestId); // scoped to this request
        header('Location: ' . APP_URL . '/xray/view.php?id=' . $requestId . '&deleted=1');
        exit;
    } elseif ($action === 'return_to_accountant' && $canProcess) {
        $reason = trim($_POST['return_reason'] ?? '');
        $xrayManager->returnToAccounting($requestId, $reason, $currentUser['id'], $_SESSION['role'] ?? ROLE_XRAY_DEPARTMENT);
        header('Location: ' . APP_URL . '/xray/index.php?returned=1');
        exit;
    }
}

// Refresh data after actions
$request = $xrayManager->getById($requestId);
$images = $xrayManager->getImages($requestId);

// Fetch History
$historyLogs = [];
try {
    $historyLogs = $db->fetchAll("
        SELECT h.*, u.full_name as user_name 
        FROM xray_request_history h 
        LEFT JOIN users u ON h.changed_by_user_id = u.id 
        WHERE h.request_id = ? 
        ORDER BY h.created_at DESC", 
        [$requestId]
    );
} catch(Exception $e) {}

// Get payment logs for admin/accountant
$paymentLogs = [];
if ($canHandlePayments) {
    $paymentLogs = $xrayManager->getPaymentLogs($requestId);
}

global $XRAY_TYPES, $XRAY_REASONS, $XRAY_STATUS;
$XRAY_STATUS = array_merge($XRAY_STATUS, [
    'returned_to_accountant' => 'Returned to Accountant',
    'in_xray' => 'In X-Ray'
]);

// Payment status labels
$PAYMENT_STATUS = [
    'pending' => 'چاوەڕوان',
    'awaiting_payment' => 'چاوەڕوانی پارەدان',
    'paid' => 'پارەدراوە',
    'debt' => 'قەرز'
];

$PAYMENT_STATUS = array_merge($PAYMENT_STATUS, [
    'pending_payment' => 'چاوەڕوانی پارەدان',
    'not_required' => 'پێویست ناکات',
    'debt_pending' => 'قەرز چاوەڕوانە',
    'debt_approved' => 'قەرز پەسەندکرا',
    'refunded' => 'پارە گەڕاوەتەوە'
]);

// English X-Ray Types
$XRAY_TYPES_EN = [
    'panoramic' => 'Panoramic',
    'cephalometric' => 'Cephalometric',
    'periapical' => 'Periapical',
    'cbct' => 'CBCT (3D)'
];

// English X-Ray Reasons
$XRAY_REASONS_EN = [
    'implant' => 'Dental Implant',
    'impaction' => 'Impactions',
    'pathology' => 'Pathology',
    'trauma' => 'Maxillofacial Trauma',
    'endo' => 'Endo',
    'other' => 'Other'
];

// Parse selected teeth for dental chart (convert to integers for comparison)
$selectedTeethArray = [];
if (!empty($request['selected_teeth'])) {
    $selectedTeethArray = array_map('intval', array_filter(explode(',', $request['selected_teeth'])));
}

// Parse determine teeth for dental chart
$determineTeethArray = [];
if (!empty($request['determine_teeth'])) {
    $determineTeethArray = array_map('intval', array_filter(explode(',', $request['determine_teeth'])));
}

$pageTitle = $request['request_number'];
$currentPage = 'xray';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Modern X-Ray View Styles */
.xray-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.xray-header-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.xray-number {
    font-family: var(--app-font-family);
    font-size: 24px;
    font-weight: 700;
    color: var(--win-accent, #0078d4);
}

.xray-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: 600;
    font-size: 14px;
}

.status-chip.pending {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    color: #e65100;
    border: 1px solid #ffcc80;
}

.status-chip.in_progress {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #1565c0;
    border: 1px solid #90caf9;
}

.status-chip.completed {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.status-chip.cancelled {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #c62828;
    border: 1px solid #ef9a9a;
}

/* Payment Status Chips */
.payment-status-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 13px;
}

.payment-status-chip.awaiting_payment {
    background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
    color: #f57c00;
    border: 1px solid #ffca28;
}

.payment-status-chip.paid {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.payment-status-chip.debt {
    background: linear-gradient(135deg, #fce4ec 0%, #f8bbd0 100%);
    color: #c2185b;
    border: 1px solid #f48fb1;
}

.urgent-chip {
    background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.xray-view-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 1024px) {
    .xray-view-grid {
        grid-template-columns: 1fr;
    }
}

/* Info Cards */
.info-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.info-card-header {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    padding: 14px 18px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.info-card-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-card-body {
    padding: 0;
}

/* Modern Info Table */
.info-table-modern {
    width: 100%;
    border-collapse: collapse;
}

.info-table-modern tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.info-table-modern tr:hover {
    background: #fafafa;
}

.info-table-modern tr:last-child {
    border-bottom: none;
}

.info-table-modern th {
    text-align: right;
    padding: 14px 18px;
    color: #546e7a;
    font-weight: 600;
    font-size: 13px;
    width: 140px;
    background: #fafafa;
    vertical-align: top;
}

.info-table-modern td {
    padding: 14px 18px;
    font-size: 14px;
    color: #2c3e50;
}

.info-value-link {
    color: var(--win-accent, #0078d4);
    text-decoration: none;
    font-weight: 600;
}

.info-value-link:hover {
    text-decoration: underline;
}

.info-value-amount {
    font-weight: 700;
    font-size: 16px;
    color: var(--win-accent, #0078d4);
}

/* X-Ray Images Grid */
.xray-images-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
    padding: 18px;
}

.xray-image-card {
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    background: white;
    transition: all 0.3s ease;
}

.xray-image-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.xray-image-preview {
    height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f5f5f5 0%, #eeeeee 100%);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.xray-image-preview::after {
    content: '\f00e';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    color: white;
    background: rgba(0, 0, 0, 0.6);
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.xray-image-preview:hover::after {
    opacity: 1;
}

.xray-image-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.xray-image-preview .pdf-icon {
    font-size: 56px;
    color: #d32f2f;
}

.xray-image-info {
    padding: 14px;
    background: white;
    border-top: 1px solid #f0f0f0;
}

.xray-image-info .filename {
    font-weight: 600;
    font-size: 13px;
    color: #2c3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}

.xray-image-info .date {
    font-size: 12px;
    color: #757575;
}

.xray-image-actions {
    display: flex;
    gap: 6px;
    margin-top: 10px;
}

/* Empty State */
.empty-images {
    text-align: center;
    padding: 50px 20px;
    color: #757575;
}

.empty-images i {
    font-size: 64px;
    margin-bottom: 16px;
    opacity: 0.3;
    color: var(--win-accent, #0078d4);
}

.empty-images p {
    font-size: 15px;
    margin-bottom: 16px;
}

/* Action Buttons */
.action-buttons-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding: 18px;
    background: #fafafa;
    border-top: 1px solid #f0f0f0;
}

/* Payment Amount Breakdown */
.payment-breakdown {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 10px;
    padding: 16px;
    margin: 16px;
    border: 1px solid #dee2e6;
}

.payment-breakdown-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px dashed #ced4da;
}

.payment-breakdown-row:last-child {
    border-bottom: none;
    padding-top: 14px;
    margin-top: 6px;
    border-top: 2px solid #adb5bd;
}

.payment-breakdown-label {
    font-size: 14px;
    color: #495057;
}

.payment-breakdown-value {
    font-weight: 600;
    font-size: 15px;
    color: #212529;
}

.payment-breakdown-value.discount {
    color: #dc3545;
}

.payment-breakdown-value.final {
    font-size: 18px;
    font-weight: 700;
    color: #28a745;
}

/* Payment Logs Table */
.payment-logs-table {
    width: 100%;
    border-collapse: collapse;
}

.payment-logs-table th {
    background: #f8f9fa;
    padding: 12px 16px;
    text-align: right;
    font-weight: 600;
    font-size: 13px;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
}

.payment-logs-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    color: #212529;
}

.payment-logs-table tr:hover {
    background: #f8f9fa;
}

.log-action-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.log-action-badge.completed { background: #e3f2fd; color: #1565c0; }
.log-action-badge.discount_applied { background: #fff3e0; color: #e65100; }
.log-action-badge.cash_payment { background: #e8f5e9; color: #2e7d32; }
.log-action-badge.debt_recorded { background: #fce4ec; color: #c2185b; }

/* Additional Badge Colors */
.badge-orange { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
.badge-purple { background: #e1bee7; color: #7b1fa2; }
.badge-red { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }

/* Pulse Animation for Urgent */
@keyframes pulse {
    0% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.02); }
    100% { opacity: 1; transform: scale(1); }
}

/* English Label Style */
.en-label {
    display: block;
    font-size: 11px;
    color: #888;
    font-weight: normal;
    margin-top: 2px;
}

/* Dental Chart View Styles - Compact */
.dental-chart-view {
    background: linear-gradient(135deg, #f0f4ff, #e8eaf6);
    border-radius: 8px;
    padding: 10px 12px;
    margin-top: 12px;
    border: 1px solid #c5cae9;
}

.chart-view-title {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    color: #1a237e;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

.jaw-row-view {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 2px;
    margin: 4px 0;
}

.jaw-label-view {
    font-size: 9px;
    font-weight: 600;
    color: #666;
    width: 40px;
    text-align: center;
}

.teeth-group-view {
    display: flex;
    gap: 2px;
    padding: 3px 5px;
    background: white;
    border-radius: 5px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}

.center-line-view {
    width: 2px;
    height: 26px;
    background: linear-gradient(to bottom, #1a237e, #3949ab);
    border-radius: 1px;
    margin: 0 2px;
}

.tooth-view {
    width: 22px;
    height: 26px;
    border: 1.5px solid #e0e0e0;
    border-radius: 4px;
    background: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 8px;
    font-weight: 700;
    color: #999;
    transition: all 0.2s;
}

.tooth-view.selected {
    background: linear-gradient(135deg, #1a237e, #3949ab);
    border-color: #1a237e;
    color: white;
    box-shadow: 0 1px 4px rgba(26, 35, 126, 0.3);
}

/* Only the 1-8 position number is shown; the FDI number (11-48) was removed
   because the x-ray room reads teeth by quadrant position, not FDI. The
   quadrant is already given by the Upper/Lower Left/Right labels either side. */
.tooth-view .tooth-num {
    font-size: 13px;
    font-weight: 800;
}

.selected-teeth-summary {
    margin-top: 8px;
    padding: 6px 10px;
    background: white;
    border-radius: 5px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
}

.selected-teeth-summary .label {
    font-size: 10px;
    font-weight: 600;
    color: #1a237e;
}

.tooth-tag-view {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 3px 8px;
    background: linear-gradient(135deg, #e8eaf6, #c5cae9);
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    color: #1a237e;
    border: 1px solid #9fa8da;
}

/* Info row with English */
.info-row-bilingual th {
    line-height: 1.3;
}

.info-row-bilingual .th-en {
    font-size: 10px;
    color: #999;
    font-weight: 500;
    display: block;
    margin-top: 2px;
}

/* Determine Chart View Styles - Compact */
.determine-chart-view {
    background: linear-gradient(135deg, #fff8e1, #ffecb3);
    border-radius: 8px;
    padding: 10px 12px;
    margin-top: 12px;
    border: 1.5px solid #ffc107;
}

.determine-chart-title {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    color: #f57c00;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

.tooth-view.det-selected {
    background: linear-gradient(135deg, #ff9800, #f57c00);
    border-color: #e65100;
    color: white;
    box-shadow: 0 1px 4px rgba(255, 152, 0, 0.3);
}

.det-tooth-tag-view {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 3px 8px;
    background: linear-gradient(135deg, #fff8e1, #ffecb3);
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    color: #e65100;
    border: 1px solid #ffca28;
}
</style>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success" style="display: flex; justify-content: space-between; align-items: center;">
    <span><i class="fas fa-check-circle"></i> داواکاری سەرکەوتوانە دروستکرا</span>
    <div style="display: flex; gap: 8px;">
        <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>&pdf=1" target="_blank" class="btn btn-danger btn-sm">
            <i class="fas fa-file-pdf"></i> داگرتنی PDF
        </a>
        <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>" target="_blank" class="btn btn-primary btn-sm">
            <i class="fas fa-print"></i> چاپکردن
        </a>
    </div>
</div>
<?php endif; ?>
<?php if (isset($_GET['uploaded'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> وێنە سەرکەوتوانە بارکرا</div>
<?php endif; ?>
<?php if (isset($_GET['completed'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> داواکاری تەواوکرا و بڕی ئێکسڕەیەکە زیادکرا بۆ قەرزی گشتی نەخۆش</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle"></i> وێنە سڕایەوە</div>
<?php endif; ?>
<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>"><i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Header Section -->
<div class="xray-header">
    <div class="xray-header-info">
        <span class="xray-number"><?= htmlspecialchars($request['request_number']) ?></span>
        <?php
        $statusIcons = [
            'pending' => 'clock',
        'pending_accountant' => 'hourglass-half',
        'pending_xray' => 'x-ray',
        'in_progress' => 'spinner fa-spin',
        'in_xray' => 'spinner fa-spin',
            'completed' => 'check-circle',
        'returned_to_accountant' => 'undo',
            'cancelled' => 'times-circle'
        ];
        ?>
        <span class="status-chip <?= $request['request_status'] ?: $request['status'] ?>">
            <i class="fas fa-<?= $statusIcons[$request['request_status'] ?: $request['status']] ?? 'circle' ?>"></i>
            <?= $XRAY_STATUS[$request['request_status'] ?: $request['status']] ?? $request['request_status'] ?: $request['status'] ?>
        </span>
        <?php if ($request['priority'] === 'urgent'): ?>
        <span class="urgent-chip"><i class="fas fa-exclamation"></i> پەلەیی</span>
        <?php endif; ?>
        <?php if (isset($request['payment_status']) && $request['payment_status'] !== ''): ?>
        <?php
        $paymentStatusIcons = [
            'awaiting_payment' => 'hourglass-half',
            'pending_payment' => 'hourglass-half',
            'paid' => 'check-double',
            'not_required' => 'ban',
            'debt' => 'hand-holding-usd',
            'debt_pending' => 'hand-holding-usd',
            'debt_approved' => 'check-double',
            'refunded' => 'undo'
        ];
        ?>
        <span class="payment-status-chip <?= $request['payment_status'] ?>">
            <i class="fas fa-<?= $paymentStatusIcons[$request['payment_status']] ?? 'money-bill' ?>"></i>
            <?= $PAYMENT_STATUS[$request['payment_status']] ?? $request['payment_status'] ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="xray-header-actions">
        <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>&pdf=1" class="btn btn-danger" target="_blank" title="داگرتنی PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>" class="btn btn-secondary" target="_blank" title="چاپکردن">
            <i class="fas fa-print"></i> چاپ
        </a>
        <a href="<?= APP_URL ?>/xray/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<?php
// Calculate patient age
$patientAge = '';
if (!empty($request['date_of_birth'])) {
    $dob = new DateTime($request['date_of_birth']);
    $now = new DateTime();
    $patientAge = $now->diff($dob)->y . ' ساڵ';
}
?>
<div class="xray-view-grid">
    <!-- Main Info Card -->
    <div class="info-card">
        <div class="info-card-header">
            <h3 class="info-card-title">
                <i class="fas fa-info-circle" style="color: var(--win-accent, #0078d4);"></i>
                Request Information / زانیاری داواکاری
            </h3>
        </div>
        <div class="info-card-body">
            <table class="info-table-modern">
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-hashtag"></i> ژمارەی داواکاری<span class="th-en">Request Number</span></th>
                    <td>
                        <strong><?= (int)$request['id'] ?></strong>
                        <br><span style="font-family:var(--app-font-family);color:var(--win-accent, #0078d4);"><?= htmlspecialchars($request['request_number']) ?></span>
                    </td>
                </tr>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-user-injured"></i> نەخۆش
                        <span class="th-en">Patient</span>
                    </th>
                    <td>
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $request['patient_id'] ?>" class="info-value-link">
                            <?= htmlspecialchars($request['patient_name']) ?>
                        </a>
                        <?php if ($patientAge): ?>
                        <span class="badge badge-orange" style="margin-right: 8px;"><?= $patientAge ?></span>
                        <?php endif; ?>
                        <br><small class="text-muted">ناسنامەی نەخۆش: <?= (int)$request['patient_id'] ?> <?= htmlspecialchars($request['patient_code'] ?? '') ?> <?= htmlspecialchars($request['patient_phone'] ?? '') ?></small>
                    </td>
                </tr>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-user-md"></i> دکتۆر
                        <span class="th-en">Doctor</span>
                    </th>
                    <td>
                        Dr. <?= htmlspecialchars($request['requested_by_name']) ?>
                        <?php if (!empty($request['external_doctor_name'])): ?>
                        <span style="display:inline-block;margin-inline-start:6px;padding:2px 8px;border-radius:999px;background:#fff3e0;color:#b25e09;border:1px solid #ffd9a8;font-size:11px;font-weight:700;">
                            <i class="fas fa-external-link-alt"></i> دکتۆری دەرەوە
                        </span>
                        <?php if (!empty($request['filed_by_name'])): ?>
                        <small style="display:block;margin-top:4px;color:#888;font-size:11.5px;">
                            تۆمارکراوە لەلایەن: <?= htmlspecialchars($request['filed_by_name']) ?>
                        </small>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($request['case_number'])): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-folder"></i> کەیس
                        <span class="th-en">Case</span>
                    </th>
                    <td>
                        <a href="<?= APP_URL ?>/cases/view.php?id=<?= $request['case_id'] ?>" class="info-value-link">
                            <?= htmlspecialchars($request['case_number']) ?>
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-x-ray"></i> جۆری ئێکسڕەی
                        <span class="th-en">X-Ray Type</span>
                    </th>
                    <td>
                        <span class="badge badge-blue" style="font-size: 13px; padding: 6px 12px;">
                            <?= $XRAY_TYPES_EN[$request['xray_type']] ?? $request['xray_type'] ?>
                        </span>
                        <span class="en-label"><?= $XRAY_TYPES[$request['xray_type']] ?? '' ?></span>
                    </td>
                </tr>
                <?php if (($request['xray_type'] ?? '') === 'cbct' && !empty($request['cbct_option']) && isset(XRAY_CBCT_OPTIONS[$request['cbct_option']])):
                    $cbctOpt = XRAY_CBCT_OPTIONS[$request['cbct_option']]; ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-cube"></i> بژاردەی CBCT
                        <span class="th-en">CBCT Option</span>
                    </th>
                    <td>
                        <span class="badge" style="font-size: 13px; padding: 6px 12px; background: #f3e5f5; color: #6a1b9a;">
                            <?= htmlspecialchars($cbctOpt['en']) ?>
                        </span>
                        <span class="en-label"><?= htmlspecialchars($cbctOpt['ku']) ?></span>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-question-circle"></i> هۆکار
                        <span class="th-en">Reason</span>
                    </th>
                    <td>
                        <span class="badge badge-purple" style="font-size: 13px; padding: 6px 12px; background: #e1bee7; color: #7b1fa2;">
                            <?= $XRAY_REASONS_EN[$request['xray_reason']] ?? $request['xray_reason'] ?>
                        </span>
                        <span class="en-label"><?= $XRAY_REASONS[$request['xray_reason']] ?? '' ?></span>
                    </td>
                </tr>
                <?php if (!empty($request['reason_details'])): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-align-left"></i> وردەکاری هۆکار
                        <span class="th-en">Additional Details</span>
                    </th>
                    <td style="background: #f8f9fa; border-radius: 6px;"><?= nl2br(htmlspecialchars($request['reason_details'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($request['full_mouth'])): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-teeth"></i> Full Mouth
                        <span class="th-en">Complete Dentition</span>
                    </th>
                    <td><span class="badge badge-green" style="font-size: 12px;">✓ Yes - All Teeth</span></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($request['determine_details']) || !empty($determineTeethArray)): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-map-marker-alt"></i> ناوچەی دیاریکراو
                        <span class="th-en">Determine / Specific Area</span>
                    </th>
                    <td>
                        <?php if (!empty($request['determine_details'])): ?>
                        <span style="background: #fff3e0; padding: 6px 12px; border-radius: 6px; border: 1px solid #ffcc80; color: #e65100;">
                            <?= htmlspecialchars($request['determine_details']) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($determineTeethArray)): ?>
                        <span style="margin-right: 8px; font-size: 12px; color: #f57c00;">
                            <i class="fas fa-tooth"></i> <?= count($determineTeethArray) ?> teeth selected
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($request['priority']) && $request['priority'] === 'urgent'): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-exclamation-triangle"></i> پلە
                        <span class="th-en">Priority</span>
                    </th>
                    <td>
                        <span class="badge badge-red" style="font-size: 12px; animation: pulse 1s infinite;">
                            <i class="fas fa-bolt"></i> Urgent / پەلەیی
                        </span>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-calendar-plus"></i> کاتی دروستکردن</th>
                    <td><?= date('Y-m-d H:i', strtotime($request['created_at'])) ?></td>
                </tr>
                <?php if ($request['accountant_approved_at']): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-user-tie"></i> پەسەندکردنی ژمێریار</th>
                    <td>
                        <?= date('Y-m-d H:i', strtotime($request['accountant_approved_at'])) ?>
                        <?php if (!empty($request['accountant_approved_by_name'])): ?>
                        <br><small><?= htmlspecialchars($request['accountant_approved_by_name']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($request['xray_received_at'])): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-inbox"></i> X-Ray Received</th>
                    <td><?= date('Y-m-d H:i', strtotime($request['xray_received_at'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($request['xray_started_at']): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-play-circle"></i> دەستپێکردنی ئێکسڕەی</th>
                    <td><?= date('Y-m-d H:i', strtotime($request['xray_started_at'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($request['xray_completed_at']): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-check-circle"></i> تەواوبوونی ئێکسڕەی</th>
                    <td><?= date('Y-m-d H:i', strtotime($request['xray_completed_at'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($request['returned_to_accountant_at']): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-undo"></i> گەڕاندنەوە بۆ ژمێریار</th>
                    <td>
                        <span style="color:#c62828;"><?= date('Y-m-d H:i', strtotime($request['returned_to_accountant_at'])) ?></span>
                        <?php if (!empty($request['returned_by_xray_name'])): ?>
                        <br><small>لە لایەن: <?= htmlspecialchars($request['returned_by_xray_name']) ?></small>
                        <?php endif; ?>
                        <br><small><?= htmlspecialchars($request['return_reason']) ?></small>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($request['refunded_at']): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-hand-holding-usd"></i> گەڕاندنەوەی پارە</th>
                    <td><span style="color:#e65100;"><?= date('Y-m-d H:i', strtotime($request['refunded_at'])) ?></span></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($request['cancelled_at']) || !empty($request['cancel_reason'])): ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-ban"></i> هەڵوەشاوە</th>
                    <td>
                        <?php if (!empty($request['cancelled_at'])): ?>
                        <?= date('Y-m-d H:i', strtotime($request['cancelled_at'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($request['cancel_reason'])): ?>
                        <br><small><?= htmlspecialchars($request['cancel_reason']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-clock"></i> دوایین نوێکردنەوە</th>
                    <td><?= !empty($request['last_updated_at']) ? date('Y-m-d H:i', strtotime($request['last_updated_at'])) : date('Y-m-d H:i', strtotime($request['updated_at'])) ?></td>
                </tr>
            </table>

            <?php if ($request['xray_type'] === 'periapical'): ?>
            <!-- Periapical Dental Chart Display -->
            <div class="dental-chart-view">
                <div class="chart-view-title">
                    <i class="fas fa-tooth"></i>
                    Periapical X-Ray - Selected Teeth / ددانە هەڵبژێردراوەکان
                </div>

                <!-- Upper Jaw -->
                <div class="jaw-row-view">
                    <span class="jaw-label-view">Upper Left</span>
                    <div class="teeth-group-view">
                        <?php for($i = 28; $i >= 21; $i--): ?>
                        <div class="tooth-view <?= in_array($i, $selectedTeethArray) ? 'selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line-view"></div>
                    <div class="teeth-group-view">
                        <?php for($i = 11; $i <= 18; $i++): ?>
                        <div class="tooth-view <?= in_array($i, $selectedTeethArray) ? 'selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label-view">Upper Right</span>
                </div>

                <!-- Lower Jaw -->
                <div class="jaw-row-view">
                    <span class="jaw-label-view">Lower Left</span>
                    <div class="teeth-group-view">
                        <?php for($i = 38; $i >= 31; $i--): ?>
                        <div class="tooth-view <?= in_array($i, $selectedTeethArray) ? 'selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line-view"></div>
                    <div class="teeth-group-view">
                        <?php for($i = 41; $i <= 48; $i++): ?>
                        <div class="tooth-view <?= in_array($i, $selectedTeethArray) ? 'selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label-view">Lower Right</span>
                </div>

                <?php if (!empty($selectedTeethArray)): ?>
                <!-- Selected Teeth Summary -->
                <div class="selected-teeth-summary">
                    <span class="label"><i class="fas fa-check-circle"></i> Selected Teeth:</span>
                    <?php
                    // Group teeth by quadrant
                    $quadrants = ['UR' => [], 'UL' => [], 'LR' => [], 'LL' => []];
                    $fdiToQuadrant = function($fdi) {
                        if ($fdi >= 11 && $fdi <= 18) return 'UR';
                        if ($fdi >= 21 && $fdi <= 28) return 'UL';
                        if ($fdi >= 31 && $fdi <= 38) return 'LL';
                        if ($fdi >= 41 && $fdi <= 48) return 'LR';
                        return null;
                    };
                    foreach ($selectedTeethArray as $tooth) {
                        $q = $fdiToQuadrant((int)$tooth);
                        if ($q) $quadrants[$q][] = (int)$tooth % 10;
                    }
                    foreach (['UR' => 'Upper Right', 'UL' => 'Upper Left', 'LR' => 'Lower Right', 'LL' => 'Lower Left'] as $key => $label):
                        if (!empty($quadrants[$key])):
                            sort($quadrants[$key]);
                    ?>
                    <span class="tooth-tag-view">
                        <strong><?= $key ?>:</strong> <?= implode(', ', $quadrants[$key]) ?>
                    </span>
                    <?php endif; endforeach; ?>
                </div>
                <?php else: ?>
                <div class="selected-teeth-summary">
                    <span style="color: #999;"><i class="fas fa-info-circle"></i> No specific teeth selected / هیچ ددانێک هەڵنەبژێردراوە</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($determineTeethArray)): ?>
            <!-- Determine Teeth Chart Display -->
            <div class="determine-chart-view">
                <div class="determine-chart-title">
                    <i class="fas fa-th"></i>
                    Determine - Selected Teeth / ددانە دیاریکراوەکان
                </div>

                <!-- Upper Jaw -->
                <div class="jaw-row-view">
                    <span class="jaw-label-view">Upper Left</span>
                    <div class="teeth-group-view">
                        <?php for($i = 28; $i >= 21; $i--): ?>
                        <div class="tooth-view <?= in_array($i, $determineTeethArray) ? 'det-selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line-view"></div>
                    <div class="teeth-group-view">
                        <?php for($i = 11; $i <= 18; $i++): ?>
                        <div class="tooth-view <?= in_array($i, $determineTeethArray) ? 'det-selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label-view">Upper Right</span>
                </div>

                <!-- Lower Jaw -->
                <div class="jaw-row-view">
                    <span class="jaw-label-view">Lower Left</span>
                    <div class="teeth-group-view">
                        <?php for($i = 38; $i >= 31; $i--): ?>
                        <div class="tooth-view <?= in_array($i, $determineTeethArray) ? 'det-selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line-view"></div>
                    <div class="teeth-group-view">
                        <?php for($i = 41; $i <= 48; $i++): ?>
                        <div class="tooth-view <?= in_array($i, $determineTeethArray) ? 'det-selected' : '' ?>">
                            <span class="tooth-num"><?= $i % 10 ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label-view">Lower Right</span>
                </div>

                <!-- Selected Teeth Summary -->
                <div class="selected-teeth-summary">
                    <span class="label" style="color: #e65100;"><i class="fas fa-check-circle"></i> Determine Teeth:</span>
                    <?php
                    // Group teeth by quadrant for determine
                    $detQuadrants = ['UR' => [], 'UL' => [], 'LR' => [], 'LL' => []];
                    $fdiToQuadrantDet = function($fdi) {
                        if ($fdi >= 11 && $fdi <= 18) return 'UR';
                        if ($fdi >= 21 && $fdi <= 28) return 'UL';
                        if ($fdi >= 31 && $fdi <= 38) return 'LL';
                        if ($fdi >= 41 && $fdi <= 48) return 'LR';
                        return null;
                    };
                    foreach ($determineTeethArray as $tooth) {
                        $q = $fdiToQuadrantDet((int)$tooth);
                        if ($q) $detQuadrants[$q][] = (int)$tooth % 10;
                    }
                    foreach (['UR' => 'Upper Right', 'UL' => 'Upper Left', 'LR' => 'Lower Right', 'LL' => 'Lower Left'] as $key => $label):
                        if (!empty($detQuadrants[$key])):
                            sort($detQuadrants[$key]);
                    ?>
                    <span class="det-tooth-tag-view">
                        <strong><?= $key ?>:</strong> <?= implode(', ', $detQuadrants[$key]) ?>
                    </span>
                    <?php endif; endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment & Date Info Card -->
    <div class="info-card">
        <div class="info-card-header">
            <h3 class="info-card-title">
                <i class="fas fa-money-bill-wave" style="color: #4caf50;"></i>
                Payment & Time / پارەدان و کات
            </h3>
        </div>
        <div class="info-card-body">
            <table class="info-table-modern">
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-credit-card"></i> جۆری پارەدان لەلایەن دکتۆر دیاریکراوە
                        <span class="th-en">Payment Type (Set by Doctor)</span>
                    </th>
                    <td>
                        <?php if ($request['payment_type'] === 'cash'): ?>
                        <span class="badge badge-green">کاش / نەقد</span>
                        <?php elseif ($request['payment_type'] === 'debt'): ?>
                        <span class="badge badge-orange">قەرز</span>
                        <?php else: ?>
                        <span class="badge badge-blue">کاش نییە (بەخۆڕایی)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (in_array($request['payment_type'], ['non_cash', 'dont_cash'], true) && $request['payment_reason']): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-comment"></i> هۆکاری نانەقد
                        <span class="th-en">Non-Cash Reason</span>
                    </th>
                    <td><?= htmlspecialchars($request['payment_reason']) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-coins"></i> بڕی پارە
                        <span class="th-en">Amount</span>
                    </th>
                    <td><span class="info-value-amount"><?= number_format($request['amount']) ?></span></td>
                </tr>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-receipt"></i> دۆخی پارەدان<span class="th-en">Financial State</span></th>
                    <td><?= htmlspecialchars($PAYMENT_STATUS[$request['payment_status']] ?? $request['payment_status']) ?></td>
                </tr>
                <tr class="info-row-bilingual">
                    <th><i class="fas fa-tasks"></i> دۆخی داواکاری<span class="th-en">Workflow State</span></th>
                    <td><?= htmlspecialchars($XRAY_STATUS[$request['request_status'] ?: $request['status']] ?? ($request['request_status'] ?: $request['status'])) ?></td>
                </tr>
                <?php if (isset($request['discount_amount']) && $request['discount_amount'] > 0): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-percentage"></i> داشکاندن
                        <span class="th-en">Discount</span>
                    </th>
                    <td>
                        <span style="color: #dc3545; font-weight: 600;">- <?= number_format($request['discount_amount']) ?></span>
                        <?php if (!empty($request['discount_reason'])): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($request['discount_reason']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-calculator"></i> بڕی کۆتایی
                        <span class="th-en">Final Amount</span>
                    </th>
                    <td><span class="info-value-amount" style="color: #28a745; font-size: 18px;"><?= number_format($request['final_amount']) ?></span></td>
                </tr>
                <?php endif; ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-calendar-plus"></i> دروستکراوە
                        <span class="th-en">Created At</span>
                    </th>
                    <td><?= date('Y-m-d H:i', strtotime($request['created_at'])) ?></td>
                </tr>
                <?php if ($request['completed_at']): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-calendar-check"></i> تەواوبوو
                        <span class="th-en">Completed At</span>
                    </th>
                    <td><?= date('Y-m-d H:i', strtotime($request['completed_at'])) ?></td>
                </tr>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-user-check"></i> تەواوکراوە لەلایەن
                        <span class="th-en">Completed By</span>
                    </th>
                    <td><?= htmlspecialchars($request['completed_by_name'] ?? '') ?></td>
                </tr>
                <?php endif; ?>
                <?php if (isset($request['paid_at']) && $request['paid_at']): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-money-check-alt"></i> پارەدراوە لە
                        <span class="th-en">Paid At</span>
                    </th>
                    <td><?= date('Y-m-d H:i', strtotime($request['paid_at'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (isset($request['settled_at']) && $request['settled_at']): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-file-invoice-dollar"></i> سەتڵ کراوە لە
                        <span class="th-en">Settled At</span>
                    </th>
                    <td><?= date('Y-m-d H:i', strtotime($request['settled_at'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($request['notes']): ?>
                <tr class="info-row-bilingual">
                    <th>
                        <i class="fas fa-sticky-note"></i> تێبینی
                        <span class="th-en">Notes</span>
                    </th>
                    <td><?= nl2br(htmlspecialchars($request['notes'])) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Action Buttons -->
        <?php if ($request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
        <div class="action-buttons-bar">
            <?php if ($canProcess): ?>
                <?php if (in_array($request['request_status'], ['pending_xray', 'pending'])): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="start_progress">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> دەستپێکردن
                    </button>
                </form>
                <?php endif; ?>

                <?php // A free request has no accountant stage to go back to — XrayManager::returnToAccounting() refuses it too. ?>
                <?php if (($isXrayDept || $isAdmin)
                    && in_array($request['request_status'], ['pending_xray', 'in_progress', 'in_xray'])
                    && ($request['payment_type'] ?? '') !== 'dont_cash'
                    && ($request['payment_status'] ?? '') !== 'not_required'): ?>
                <button type="button" class="btn btn-danger" onclick="openDialog('returnAccountantDialog')">
                    <i class="fas fa-undo"></i> گەڕاندنەوە بۆ ژمێریار
                </button>
                <?php endif; ?>

                <?php if (in_array($request['request_status'], ['pending_xray', 'in_progress', 'in_xray'], true)): ?>
                <button type="button" class="btn btn-success" onclick="openDialog('completeDialog')">
                    <i class="fas fa-check"></i> تەواوکردن
                </button>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($request['request_status'] === 'pending' || $request['request_status'] === 'pending_accountant'): ?>
            <form method="POST" style="display: inline;" onsubmit="return confirm('ئایا دڵنیایت لە هەڵوەشاندنەوە؟')">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times"></i> هەڵوەشاندنەوە
                </button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Payment Processing Button (Admin/Accountant) - shown when payment not yet processed -->
        <?php if ($canHandlePayments && isset($request['payment_status']) && in_array($request['payment_status'], ['pending', 'awaiting_payment']) && $request['status'] !== 'cancelled'): ?>
        <div class="action-buttons-bar" style="background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%); border-top: 2px solid #ffca28;">
            <div style="flex: 1;">
                <strong style="color: #f57c00;"><i class="fas fa-exclamation-triangle"></i> ئەم داواکارییە چاوەڕوانی پارەدانە</strong>
                <p style="margin: 6px 0 0; font-size: 13px; color: #666;">بۆ داشکاندن و پارەدان کلیک لەسەر دوگمەکە بکە - دوای پارەدان بۆ بەشی ئێکسڕەی دەنێردرێت</p>
            </div>
            <a href="<?= APP_URL ?>/accountant/approval-center.php?tab=xray&q=<?= urlencode('X-' . $requestId) ?>" class="btn btn-warning">
                <i class="fas fa-cash-register"></i> پارەدان بکە
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Images Section -->
<div class="info-card" style="margin-top: 20px;">
    <div class="info-card-header">
        <h3 class="info-card-title">
            <i class="fas fa-images" style="color: #9c27b0;"></i>
            X-Ray Images / وێنەکانی ئێکسڕەی
            <span class="badge" style="margin-right: 8px;"><?= count($images) ?></span>
        </h3>
        <?php if ($canProcess && $request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
        <button class="btn btn-primary btn-sm" onclick="openDialog('uploadDialog')">
            <i class="fas fa-upload"></i> بارکردنی وێنە
        </button>
        <?php endif; ?>
    </div>
    <div class="info-card-body">
        <?php if (empty($images)): ?>
        <div class="empty-images">
            <i class="fas fa-images"></i>
            <p>هیچ وێنەیەک بارنەکراوە</p>
            <?php if ($canProcess && $request['status'] !== 'completed' && $request['status'] !== 'cancelled'): ?>
            <button class="btn btn-primary" onclick="openDialog('uploadDialog')">
                <i class="fas fa-upload"></i> بارکردنی وێنە
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="xray-images-grid">
            <?php foreach ($images as $image):
                // Check if image is from S3 or local storage
                if (!empty($image['s3_key'])) {
                    // Use presigned URL for S3 images (valid for 60 minutes)
                    $s3 = S3Uploader::getInstance();
                    $imageUrl = $s3->getPresignedUrl($image['s3_key'], 60);
                } elseif (strpos($image['image_path'], 'http') === 0) {
                    $imageUrl = $image['image_path'];
                } else {
                    // Local file: served only via the authenticated gateway
                    // (the uploads/xray folder is blocked from direct access).
                    $imageUrl = APP_URL . '/file.php?type=xray&id=' . (int)($image['id'] ?? 0);
                }
            ?>
            <div class="xray-image-card">
                <div class="xray-image-preview" onclick="window.open('<?= htmlspecialchars($imageUrl) ?>', '_blank')">
                    <?php if ($image['image_type'] === 'pdf'): ?>
                    <i class="fas fa-file-pdf pdf-icon"></i>
                    <?php else: ?>
                    <img src="<?= htmlspecialchars($imageUrl) ?>" alt="X-Ray">
                    <?php endif; ?>
                </div>
                <div class="xray-image-info">
                    <div class="filename" title="<?= htmlspecialchars($image['original_filename']) ?>">
                        <?= htmlspecialchars($image['original_filename']) ?>
                    </div>
                    <div class="date"><?= date('Y-m-d H:i', strtotime($image['created_at'])) ?></div>
                    <?php if ($image['description']): ?>
                    <div style="margin-top: 6px; font-size: 12px; color: #546e7a;"><?= htmlspecialchars($image['description']) ?></div>
                    <?php endif; ?>
                    <?php if ($canProcess && $request['request_status'] !== 'completed'): ?>
                    <div class="xray-image-actions">
                        <a href="<?= htmlspecialchars($imageUrl) ?>" class="btn btn-sm btn-secondary" download title="داگرتن">
                            <i class="fas fa-download"></i>
                        </a>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="delete_image">
                            <input type="hidden" name="image_id" value="<?= $image['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت لە سڕینەوە؟')" title="سڕینەوە">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Upload Dialog -->
<div class="dialog-overlay" id="uploadDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-upload"></i> بارکردنی وێنەی ئێکسڕەی</h4>
            <button class="dialog-close" onclick="closeDialog('uploadDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_image">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">وێنە</label>
                    <input type="file" name="xray_image" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                    <small class="form-text">جۆرەکان: JPG, PNG, PDF (زۆرترین قەبارە: 10MB)</small>
                </div>
                <div class="form-group">
                    <label class="form-label">وەسف</label>
                    <textarea name="image_description" class="form-control" rows="2" placeholder="وەسفی وێنە..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('uploadDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> بارکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- History Timeline Section -->
<?php if (!empty($historyLogs)): ?>
<div class="info-card" style="margin-top: 20px;">
    <div class="info-card-header">
        <h3 class="info-card-title">
            <i class="fas fa-history" style="color: #607d8b;"></i>
            Timeline & History / مێژووی گۆڕانکارییەکان
        </h3>
    </div>
    <div class="info-card-body" style="padding: 20px;">
        <div style="position: relative; padding-left: 20px;">
            <div style="position: absolute; left: 6px; top: 5px; bottom: 5px; width: 2px; background: #e0e0e0;"></div>
            <?php foreach ($historyLogs as $log): ?>
            <?php 
            $iconColor = '#0078d4';
            $iconFa = 'fa-info-circle';
            if (strpos($log['action_type'], 'completed') !== false) { $iconColor = '#107c10'; $iconFa = 'fa-check'; }
            elseif (strpos($log['action_type'], 'returned') !== false) { $iconColor = '#c50f1f'; $iconFa = 'fa-undo'; }
            elseif (strpos($log['action_type'], 'cancelled') !== false) { $iconColor = '#c50f1f'; $iconFa = 'fa-times'; }
            elseif (strpos($log['action_type'], 'collected') !== false) { $iconColor = '#107c10'; $iconFa = 'fa-money-bill-wave'; }
            elseif (strpos($log['action_type'], 'debt_approved') !== false) { $iconColor = '#ff8c00'; $iconFa = 'fa-hand-holding-usd'; }
            ?>
            <div style="position: relative; margin-bottom: 20px;">
                <div style="position: absolute; left: -21px; top: 2px; width: 14px; height: 14px; border-radius: 50%; background: <?= $iconColor ?>; border: 3px solid #fff; box-shadow: 0 0 0 1px <?= $iconColor ?>;"></div>
                <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <strong style="font-size: 13px; color: <?= $iconColor ?>;"><i class="fas <?= $iconFa ?>"></i> <?= htmlspecialchars($log['action_type']) ?></strong>
                        <span style="font-size: 11px; color: #888;"><i class="fas fa-clock"></i> <?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></span>
                    </div>
                    <div style="font-size: 13px; color: #333; margin-bottom: 4px;">
                        <?php if ($log['old_status'] !== $log['new_status']): ?>
                            Status changed: <span style="color:#666;"><?= $log['old_status'] ?></span> &rarr; <span style="font-weight:600;"><?= $log['new_status'] ?></span><br>
                        <?php endif; ?>
                        <?php if ($log['old_payment_status'] !== $log['new_payment_status']): ?>
                            Payment changed: <span style="color:#666;"><?= $log['old_payment_status'] ?></span> &rarr; <span style="font-weight:600;"><?= $log['new_payment_status'] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['action_note'])): ?>
                        <div style="background: #fff; padding: 8px 12px; border-radius: 6px; border-left: 3px solid <?= $iconColor ?>; font-size: 12px; color: #555; margin-top: 6px;">
                            <?= nl2br(htmlspecialchars($log['action_note'])) ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size: 11px; color: #999; margin-top: 8px; text-align: right;">
                        By: <?= htmlspecialchars($log['user_name'] ?? 'System') ?> (<?= htmlspecialchars($log['changed_by_role'] ?? '') ?>)
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Payment Logs Section (Admin/Accountant) -->
<?php if ($canHandlePayments && !empty($paymentLogs)): ?>
<div class="info-card" style="margin-top: 20px;">
    <div class="info-card-header">
        <h3 class="info-card-title">
            <i class="fas fa-history" style="color: #607d8b;"></i>
            مێژووی پارەدان
            <span class="badge" style="margin-right: 8px;"><?= count($paymentLogs) ?></span>
        </h3>
    </div>
    <div class="info-card-body" style="padding: 0;">
        <table class="payment-logs-table">
            <thead>
                <tr>
                    <th>چالاکی</th>
                    <th>زانیاری</th>
                    <th>لەلایەن</th>
                    <th>کات</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $actionLabels = [
                    'completed' => 'تەواوکرا',
                    'discount_applied' => 'داشکاندن',
                    'cash_payment' => 'پارەدان (نەقد)',
                    'debt_recorded' => 'قەرز تۆمارکرا'
                ];
                foreach ($paymentLogs as $log):
                    $actionData = !empty($log['action_data']) ? json_decode($log['action_data'], true) : [];
                ?>
                <tr>
                    <td>
                        <span class="log-action-badge <?= $log['action'] ?>">
                            <?= $actionLabels[$log['action']] ?? $log['action'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($log['action'] === 'discount_applied' && $actionData): ?>
                            <strong><?= number_format($actionData['discount_amount'] ?? 0) ?></strong>
                            <?php if (!empty($actionData['discount_reason'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($actionData['discount_reason']) ?></small>
                            <?php endif; ?>
                        <?php elseif ($log['action'] === 'cash_payment' && $actionData): ?>
                            <strong style="color: #28a745;"><?= number_format($actionData['final_amount'] ?? 0) ?></strong>
                            <?php if (!empty($actionData['notes'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($actionData['notes']) ?></small>
                            <?php endif; ?>
                        <?php elseif ($log['action'] === 'debt_recorded' && $actionData): ?>
                            <strong style="color: #c2185b;"><?= number_format($actionData['debt_amount'] ?? 0) ?></strong>
                            <?php if (!empty($actionData['due_date'])): ?>
                            <br><small class="text-muted">سەررێکەوت: <?= $actionData['due_date'] ?></small>
                            <?php endif; ?>
                        <?php elseif ($log['action'] === 'completed' && $actionData): ?>
                            <?php if (!empty($actionData['notes'])): ?>
                            <small class="text-muted"><?= htmlspecialchars($actionData['notes']) ?></small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($log['performed_by_name'] ?? '') ?></td>
                    <td><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Complete Dialog -->
<div class="dialog-overlay" id="completeDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-check-circle"></i> تەواوکردنی سکان</h4>
            <button class="dialog-close" onclick="closeDialog('completeDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="complete">
            <div class="dialog-body">
                <p style="margin-bottom: 16px;">ئایا دڵنیایت کە سکانی ئەم داواکارییە تەواوبووە؟</p>

                <!-- Info Box -->
                <div style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 1px solid #a5d6a7; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <i class="fas fa-info-circle" style="color: #2e7d32;"></i>
                        <strong style="color: #2e7d32;">تێبینی:</strong>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: #33691e;">
                        پارەدان پێشتر لەلایەن ژمێریارەوە ئەنجامدراوە. تەنها سکانەکە وەک تەواوبوو دیاری دەکرێت.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">تێبینی تەواوکردن</label>
                    <textarea name="completion_notes" class="form-control" rows="3" placeholder="تێبینی..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('completeDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> تەواوکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Return to Accountant Dialog -->
<div class="dialog-overlay" id="returnAccountantDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title" style="color: #c62828;"><i class="fas fa-undo"></i> گەڕاندنەوە بۆ ژمێریار</h4>
            <button class="dialog-close" onclick="closeDialog('returnAccountantDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="return_to_accountant">
            <div class="dialog-body">
                <p style="margin-bottom: 16px;">ئایا دڵنیایت کە دەتەوێت ئەم داواکارییە بگەڕێنیتەوە بۆ ژمێریار؟ تکایە هۆکارەکەی بنووسە.</p>
                <div class="form-group">
                    <label class="form-label required">هۆکاری گەڕاندنەوە</label>
                    <textarea name="return_reason" class="form-control" rows="4" required placeholder="بۆ نموونە: نەخۆش داوای گەڕاندنەوەی پارەی کرد، بڕی پارە هەڵەیە..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('returnAccountantDialog')">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-undo"></i> گەڕاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
