<?php
/**
 * View Folder Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Folder.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../includes/case-name-picker.php';

$auth = new Auth();
$auth->requireLogin();

$folderId = intval($_GET['id'] ?? 0);
if (!$folderId) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

$folderManager = new Folder();
$caseManager = new CaseManager();
$patientManager = new Patient();

$folder = $folderManager->getById($folderId);
if (!$folder) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$db = Database::getInstance();

// Check access - doctors can only see their own folders
if (!$isAdmin && $folder['doctor_id'] != $currentUser['id']) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Get cases for this folder
$cases = $caseManager->getByFolder($folderId);
$patient = $patientManager->getById($folder['patient_id']);

$pageTitle = $folder['folder_name'];
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $folder['patient_name'], 'url' => APP_URL . '/patients/view.php?id=' . $folder['patient_id']],
    ['title' => $folder['folder_name']]
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_case') {
        // Case names come from the admin-managed list only
        if (!(new CaseNamePreset())->exists($_POST['case_name'] ?? '')) {
            header('Location: ' . APP_URL . '/folders/view.php?id=' . $folderId);
            exit;
        }
        $caseData = [
            'folder_id' => $folderId,
            'case_name' => trim($_POST['case_name']),
            'scope_type' => $_POST['scope_type'] ?? 'tooth',
            'selected_arch' => !empty($_POST['selected_arch']) ? $_POST['selected_arch'] : null,
            'chief_complaint' => trim($_POST['chief_complaint'] ?? ''),
            'notes' => trim($_POST['notes'] ?? '')
        ];
        $result = $caseManager->create($caseData);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/cases/view.php?id=' . $result['case_id']);
            exit;
        }
    } elseif ($_POST['action'] === 'archive_folder' && $folder['status'] === 'active') {
        $closingComment = trim($_POST['closing_comment'] ?? '');
        $result = $folderManager->archive($folderId, $closingComment ?: null);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/folders/view.php?id=' . $folderId . '&archived=1');
            exit;
        } else {
            $archiveError = $result['message'] ?? 'هەڵەیەک ڕوویدا';
        }
    } elseif ($_POST['action'] === 'reopen_folder' && $folder['status'] === 'archived') {
        $result = $folderManager->reopen($folderId);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/folders/view.php?id=' . $folderId . '&reopened=1');
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Compact Folder View Styling */

/* Windows 10 Style Folder Header Card */
.folder-header-card {
    background: white;
    border-radius: 8px;
    padding: 14px 18px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}
.folder-header-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}
.folder-icon-box {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.25);
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.folder-icon-box:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.35);
}
.folder-header-info h2 {
    margin: 0 0 4px;
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
}
.folder-header-info p {
    margin: 0;
    font-size: 13px;
    color: #757575;
}
.folder-header-info a {
    color: #0078d4;
    text-decoration: none;
    transition: all 0.2s ease;
}
.folder-header-info a:hover {
    color: #0063b1;
    text-decoration: underline;
}

/* Windows 10 Style Panel */
.panel {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
    overflow: hidden;
    margin-bottom: 16px;
    background: white;
    transition: all 0.3s ease;
}
.panel:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}
.panel-header {
    background: linear-gradient(135deg, #0078d4 0%, #0063b1 100%);
    padding: 12px 16px;
    border-bottom: none;
}
.panel-title {
    font-size: 14px;
    font-weight: 700;
    color: white;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.panel-title i {
    color: white;
    font-size: 15px;
}
.panel-title .badge {
    margin-left: 6px;
    font-size: 11px;
    padding: 3px 8px;
    background: rgba(255, 255, 255, 0.25);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.panel-body {
    padding: 16px;
    background: white;
}

/* Windows 10 Style Table */
.table-container {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.table {
    margin: 0;
}
.table thead th {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    font-weight: 700;
    font-size: 12px;
    color: #2c3e50;
    padding: 12px 14px;
    border-bottom: 2px solid #e0e0e0;
}
.table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    vertical-align: middle;
    background: white;
}
.table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid #f0f0f0;
}
.table tbody tr:hover {
    background: #f9f9f9;
    transform: scale(1.01);
}
.table tbody tr:last-child {
    border-bottom: none;
}
.table-actions {
    display: flex;
    gap: 6px;
}
.table-actions .btn-icon {
    width: 28px;
    height: 28px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-size: 12px;
    transition: all 0.2s ease;
}
.table-actions .btn-icon.btn-primary {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    box-shadow: 0 2px 6px rgba(0, 120, 212, 0.2);
    border: none;
}
.table-actions .btn-icon.btn-primary:hover {
    transform: translateY(-1px) scale(1.08);
    box-shadow: 0 3px 10px rgba(0, 120, 212, 0.35);
    background: linear-gradient(135deg, #0067c0, #005a9e);
}
.table-actions .btn-icon i {
    font-size: 12px;
    color: white;
}

/* Windows 10 Style Summary Table */
.summary-table {
    width: 100%;
}
.summary-table tr {
    border-bottom: 1px solid #f5f5f5;
}
.summary-table tr:last-child {
    border-bottom: none;
}
.summary-table td {
    padding: 8px 0;
    font-size: 13px;
}
.summary-table td:first-child {
    color: #757575;
    font-weight: 500;
}
.summary-table td:last-child {
    text-align: left;
    font-weight: 600;
}
.summary-total {
    border-top: 2px solid #e8e8e8 !important;
    padding-top: 12px !important;
}
.summary-total td {
    padding-top: 12px !important;
    font-weight: 700 !important;
}
.summary-total td:last-child {
    font-size: 18px !important;
}

/* Windows 10 Style Progress Bar */
.progress-section {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
}
.progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    margin-bottom: 6px;
    color: #757575;
    font-weight: 600;
}
.progress-bar-container {
    background: #f0f0f0;
    border-radius: 8px;
    height: 8px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #66bb6a);
    border-radius: 8px;
    transition: width 0.4s ease;
}

/* Windows 10 Style Badges */
.badge {
    padding: 5px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    cursor: default;
}
.badge:hover {
    transform: translateY(-1px);
}
.badge-green {
    background: linear-gradient(135deg, #c8e6c9, #a5d6a7);
    color: #2e7d32;
    border: 1px solid #81c784;
}
.badge-orange {
    background: linear-gradient(135deg, #ffe0b2, #ffcc80);
    color: #e65100;
    border: 1px solid #ffb74d;
}
.badge-red {
    background: linear-gradient(135deg, #ffcdd2, #ef9a9a);
    color: #c62828;
    border: 1px solid #e57373;
}
.badge-blue {
    background: linear-gradient(135deg, #bbdefb, #90caf9);
    color: #1565c0;
    border: 1px solid #64b5f6;
}

/* Windows 10 Style Buttons */
.btn {
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 10px;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn i {
    font-size: 13px;
}
.btn-primary {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.25);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.35);
    background: linear-gradient(135deg, #0067c0, #005a9e);
}
.btn-secondary {
    background: linear-gradient(135deg, #e0e0e0, #bdbdbd);
    color: #616161;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    background: linear-gradient(135deg, #d5d5d5, #b0b0b0);
}
.btn-success {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.25);
}
.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
    background: linear-gradient(135deg, #43a047, #2e7d32);
}
.btn-outline {
    background: white;
    color: #0078d4;
    border: 2px solid #0078d4;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.15);
}
.btn-outline:hover {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.25);
}
.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
.btn-icon {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}
.btn-icon.btn-primary {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.25);
}
.btn-icon.btn-primary:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 120, 212, 0.35);
}
.w-100 {
    width: 100%;
}
.mb-2 {
    margin-bottom: 8px;
}

/* Windows 10 Style Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}
.empty-state-icon {
    font-size: 56px;
    color: #bdbdbd;
    opacity: 0.5;
    margin-bottom: 16px;
}
.empty-state-title {
    font-size: 16px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 8px;
}
.empty-state-text {
    font-size: 13px;
    color: #757575;
    margin: 0 0 20px;
}

/* Windows 10 Style Dialog */
.dialog-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}
.dialog-overlay.open {
    display: flex;
}
.dialog {
    background: white;
    border-radius: 10px;
    width: 90%;
    max-width: 450px;
    max-height: 85vh;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}
.dialog-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 10px 10px 0 0;
}
.dialog-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
}
.dialog-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}
.dialog-close:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: rotate(90deg) scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}
.dialog-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
}
.dialog-body::-webkit-scrollbar {
    width: 8px;
}
.dialog-body::-webkit-scrollbar-track {
    background: #f0f0f0;
    border-radius: 10px;
}
.dialog-body::-webkit-scrollbar-thumb {
    background: #c0c0c0;
    border-radius: 10px;
}
.dialog-body::-webkit-scrollbar-thumb:hover {
    background: #a0a0a0;
}
.dialog-footer {
    padding: 12px 16px;
    border-top: 1px solid #e8e8e8;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    background: #f8f9fa;
}

/* Windows 10 Style Form Elements */
.form-group {
    margin-bottom: 12px;
}
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 6px;
}
.form-label.required::after {
    content: '*';
    color: #f44336;
    margin-left: 4px;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    transition: all 0.2s ease;
    background: white;
}
.form-control:focus {
    border-color: #0078d4;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
}
textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

/* Grid Layout - Compact */
.folder-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 16px;
}

/* Utility Classes */
.d-flex {
    display: flex;
}
.justify-between {
    justify-content: space-between;
}
.align-center {
    align-items: center;
}
.flex-wrap {
    flex-wrap: wrap;
}
.gap-2 {
    gap: 8px;
}
.gap-3 {
    gap: 12px;
}
.mb-3 {
    margin-bottom: 16px;
}
.mt-2 {
    margin-top: 8px;
}
.mt-3 {
    margin-top: 12px;
}
.text-secondary {
    color: #757575;
}
.text-success {
    color: #4caf50;
}
.text-error {
    color: #f44336;
}

/* Windows 10 Style Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.folder-header-card,
.panel {
    animation: fadeIn 0.3s ease;
}

/* Link Styling */
a {
    text-decoration: none;
    transition: all 0.2s ease;
}
a:hover {
    opacity: 0.8;
}

/* Strong Text */
strong {
    font-weight: 700;
}

/* Windows 10 Style Table Link */
.table a {
    color: #0078d4;
    font-weight: 600;
    transition: all 0.2s ease;
}
.table a:hover {
    color: #0063b1;
    text-decoration: underline;
}

/* Windows 10 Style Alert */
.alert {
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    animation: fadeIn 0.3s ease;
    border: none;
}
.alert-success {
    background: linear-gradient(135deg, #4caf50, #66bb6a);
    color: white;
}
.alert-error {
    background: linear-gradient(135deg, #f44336, #e57373);
    color: white;
}
.alert-info {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
}
.alert-warning {
    background: linear-gradient(135deg, #ff9800, #ffb74d);
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .folder-grid {
        grid-template-columns: 1fr;
    }
    .folder-header-card {
        padding: 12px;
    }
    .folder-icon-box {
        width: 44px;
        height: 44px;
        font-size: 20px;
    }
    .table thead th,
    .table tbody td {
        padding: 8px;
        font-size: 12px;
    }
    .btn {
        padding: 7px 12px;
        font-size: 12px;
    }
}
</style>

<?php if (isset($_GET['archived'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">دۆسیەکە داخرا</div>
</div>
<?php endif; ?>

<?php if (isset($_GET['reopened'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">دۆسیەکە کرایەوە</div>
</div>
<?php endif; ?>

<!-- Folder Header -->
<div class="folder-header-card">
    <div class="d-flex justify-between align-center flex-wrap gap-2">
        <div class="d-flex align-center gap-3">
            <div class="folder-icon-box">
                <i class="fas fa-folder-open"></i>
            </div>
            <div class="folder-header-info">
                <h2><?= htmlspecialchars($folder['folder_name']) ?></h2>
                <p>
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= $folder['patient_id'] ?>">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($folder['patient_name']) ?>
                    </a>
                    &nbsp;&bull;&nbsp;
                    <i class="fas fa-user-md"></i> د. <?= htmlspecialchars($folder['doctor_name']) ?>
                </p>
            </div>
        </div>
        <div class="d-flex gap-2 align-center">
            <span class="badge <?= $folder['status'] === 'active' ? 'badge-green' : '' ?>">
                <?= $folder['status'] === 'active' ? 'کراوە' : 'داخراو' ?>
            </span>
            <?php if ($folder['status'] === 'active'): ?>
            <button class="btn btn-primary" onclick="openDialog('newCaseDialog')">
                <i class="fas fa-plus"></i>
                کەیسی نوێ
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="folder-grid">
    <!-- Cases List -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-briefcase-medical"></i>
                کەیسەکان
                <span class="badge"><?= count($cases) ?></span>
            </h3>
        </div>
        <div class="panel-body">
            <?php if (empty($cases)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open empty-state-icon"></i>
                <p class="empty-state-title">هیچ کەیسێک نییە</p>
                <p class="empty-state-text">ئەم دۆسیەیە هێشتا هیچ کەیسێکی تێدا نییە</p>
                <?php if ($folder['status'] === 'active'): ?>
                <button class="btn btn-primary" onclick="openDialog('newCaseDialog')">
                    <i class="fas fa-plus"></i>
                    کەیسی نوێ
                </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ژمارە</th>
                            <th>ناو</th>
                            <th>دۆخ</th>
                            <th>کۆی گشتی</th>
                            <th>پارەی دراو</th>
                            <th>ماوە</th>
                            <th>بەروار</th>
                            <th>کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                        <?php $remaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount']; ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>">
                                    <strong><?= $case['case_number'] ?></strong>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($case['case_name'] ?: 'کەیسی گشتی') ?></td>
                            <td>
                                <?php
                                $statusLabels = [
                                    'open' => ['badge-green', 'کراوە'],
                                    'pending' => ['badge-orange', 'چاوەڕوان'],
                                    'closed' => ['', 'داخراو']
                                ];
                                $status = $statusLabels[$case['status']] ?? ['', $case['status']];
                                ?>
                                <span class="badge <?= $status[0] ?>"><?= $status[1] ?></span>
                            </td>
                            <td><?= number_format($case['total_amount']) ?></td>
                            <td class="text-success"><?= number_format($case['paid_amount']) ?></td>
                            <td class="<?= $remaining > 0 ? 'text-error' : 'text-success' ?>">
                                <strong><?= number_format($remaining) ?></strong>
                            </td>
                            <td><?= date('Y-m-d', strtotime($case['created_at'])) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>"
                                       class="btn btn-icon btn-sm btn-primary" title="بینین">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Folder Summary -->
    <div>
        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-calculator"></i> پوختە</h4>
            </div>
            <div class="panel-body">
                <table class="summary-table">
                    <tr>
                        <td>کۆی گشتی</td>
                        <td><?= number_format($folder['total_amount']) ?></td>
                    </tr>
                    <tr>
                        <td>داشکاندن</td>
                        <td style="color: #ff9800;"><?= number_format($folder['discount_amount']) ?></td>
                    </tr>
                    <tr>
                        <td>پارەی دراو</td>
                        <td style="color: #4caf50;"><?= number_format($folder['paid_amount']) ?></td>
                    </tr>
                    <tr class="summary-total">
                        <?php $remaining = $folder['total_amount'] - $folder['paid_amount'] - $folder['discount_amount']; ?>
                        <td>ماوە</td>
                        <td style="color: <?= $remaining > 0 ? '#f44336' : '#4caf50' ?>;">
                            <?= number_format($remaining) ?>
                        </td>
                    </tr>
                </table>

                <?php if ($folder['total_amount'] > 0): ?>
                <div class="progress-section">
                    <div class="progress-label">
                        <span>پێشکەوتن</span>
                        <span><?= round(($folder['paid_amount'] / $folder['total_amount']) * 100) ?>%</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: <?= ($folder['paid_amount'] / $folder['total_amount']) * 100 ?>%;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-info-circle"></i> زانیاری</h4>
            </div>
            <div class="panel-body">
                <table class="summary-table">
                    <tr>
                        <td>جۆر</td>
                        <td>
                            <?php
                            $types = ['general' => 'گشتی', 'cosmetic' => 'جوانکاری', 'orthodontic' => 'ڕاستکردنەوە', 'surgery' => 'نەشتەرگەری', 'other' => 'هی تر'];
                            echo $types[$folder['folder_type']] ?? $folder['folder_type'];
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>کەیسی کراوە</td>
                        <td><?= $folder['open_cases'] ?></td>
                    </tr>
                    <tr>
                        <td>دروستکراو</td>
                        <td><?= date('Y-m-d', strtotime($folder['created_at'])) ?></td>
                    </tr>
                    <?php if ($folder['closed_at']): ?>
                    <tr>
                        <td>داخراو</td>
                        <td><?= date('Y-m-d', strtotime($folder['closed_at'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if (!empty($folder['notes'])): ?>
                <div class="mt-3" style="padding-top: 12px; border-top: 1px solid #f0f0f0;">
                    <div style="font-size: 12px; font-weight: 600; color: #2c3e50; margin-bottom: 6px;">تێبینی:</div>
                    <p class="text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5;"><?= nl2br(htmlspecialchars($folder['notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h4 class="panel-title"><i class="fas fa-cogs"></i> کردارەکان</h4>
            </div>
            <div class="panel-body">
                <?php if ($folder['status'] === 'active'): ?>
                <button type="button" class="btn btn-secondary w-100 mb-2" onclick="openDialog('closeFolderDialog')">
                    <i class="fas fa-archive"></i>
                    داخستنی دۆسیە
                </button>
                <?php else: ?>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="reopen_folder">
                    <button type="submit" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-folder-open"></i>
                        کردنەوەی دۆسیە
                    </button>
                </form>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $folder['patient_id'] ?>" class="btn btn-outline w-100">
                    <i class="fas fa-user"></i>
                    گەڕانەوە بۆ نەخۆش
                </a>
            </div>
        </div>

        <!-- Closing Comment (for archived folders) -->
        <?php if ($folder['status'] === 'archived' && !empty($folder['closing_comment'])): ?>
        <div class="panel">
            <div class="panel-header" style="background: var(--secondary-light);">
                <h4 class="panel-title"><i class="fas fa-comment-dots"></i> تێبینی کۆتایی</h4>
            </div>
            <div class="panel-body">
                <p style="color: var(--text-secondary); margin: 0;"><?= nl2br(htmlspecialchars($folder['closing_comment'])) ?></p>
                <?php if (!empty($folder['closed_at'])): ?>
                <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                    <i class="fas fa-clock"></i> داخرا لە: <?= date('Y/m/d H:i', strtotime($folder['closed_at'])) ?>
                </small>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Case Dialog -->
<div class="dialog-overlay" id="newCaseDialog">
    <div class="dialog" style="max-width: 500px;">
        <div class="dialog-header">
            <h4 class="dialog-title">کەیسی نوێ</h4>
            <button class="dialog-close" onclick="closeDialog('newCaseDialog')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="" id="newCaseForm">
            <input type="hidden" name="action" value="create_case">
            <div class="dialog-body">
                <?php render_case_name_field($isAdmin || $auth->isDoctor()); ?>

                <!-- Scope Selection -->
                <div class="form-group">
                    <label class="form-label required">جۆری بوار</label>
                    <div class="scope-selector">
                        <label class="scope-option selected" data-scope="tooth">
                            <input type="radio" name="scope_type" value="tooth" checked>
                            <div class="scope-icon">
                                <i class="fas fa-tooth"></i>
                            </div>
                            <div class="scope-info">
                                <strong>ددان</strong>
                                <span>ددانێک یان چەند ددانێک</span>
                            </div>
                        </label>
                        <label class="scope-option" data-scope="arch">
                            <input type="radio" name="scope_type" value="arch">
                            <div class="scope-icon">
                                <i class="fas fa-teeth"></i>
                            </div>
                            <div class="scope-info">
                                <strong>ئەرک (جاو)</strong>
                                <span>ئەرکی سەرەوە یان خوارەوە</span>
                            </div>
                        </label>
                    </div>
                    <p class="scope-hint" id="archHint" style="display: none;">
                        <i class="fas fa-info-circle" style="color: #9c27b0;"></i>
                        هەڵبژاردنی ئەرک لە ناو کەیسەکەدا دەبێت کاتی زیادکردنی چارەسەر
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">سکاڵای سەرەکی</label>
                    <textarea name="chief_complaint" class="form-control" rows="2"
                              placeholder="چی ئازاری هەیە؟"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">تێبینی</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('newCaseDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    دروستکردن
                </button>
            </div>
        </form>
    </div>
</div>

<?php render_case_name_manager($isAdmin || $auth->isDoctor(), $isAdmin); ?>

<!-- Close Folder Dialog -->
<div class="dialog-overlay" id="closeFolderDialog" <?php if (!empty($archiveError)) echo 'style="display: flex;"'; ?>>
    <div class="dialog close-dialog-modern">
        <div class="close-dialog-header">
            <h4><i class="fas fa-archive"></i> داخستنی دۆسیە</h4>
            <button type="button" class="close-dialog-close" onclick="closeDialog('closeFolderDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="archive_folder">
            <div class="close-dialog-body">
                <?php if (!empty($archiveError)): ?>
                <div class="close-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($archiveError) ?></span>
                </div>
                <?php endif; ?>
                <div class="close-warning">
                    <i class="fas fa-info-circle"></i>
                    <span>تکایە تێبینی کۆتایی سەبارەت بە ئەم دۆسیەیە بنووسە</span>
                </div>
                <div class="close-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی کۆتایی</label>
                    <textarea name="closing_comment" rows="4" placeholder="تێبینی کۆتایی سەبارەت بە دۆسیەکە..."></textarea>
                </div>
            </div>
            <div class="close-dialog-footer">
                <button type="submit" class="close-btn-primary"><i class="fas fa-archive"></i> داخستنی دۆسیە</button>
                <button type="button" class="close-btn-secondary" onclick="closeDialog('closeFolderDialog')"><i class="fas fa-arrow-right"></i> پاشگەزبوونەوە</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modern Close Dialog Styles */
.close-dialog-modern {
    max-width: 450px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35);
    background: #fff;
}

.close-dialog-header {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.close-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.close-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.close-dialog-body {
    padding: 20px;
}

.close-error {
    background: #ffebee;
    border: 1px solid #ffcdd2;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-error i {
    color: #f44336;
    font-size: 20px;
}

.close-error span {
    color: #c62828;
    font-size: 13px;
}

.close-warning {
    background: #e8f5e9;
    border: 1px solid #c8e6c9;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-warning i {
    color: #4caf50;
    font-size: 20px;
}

.close-warning span {
    color: #2e7d32;
    font-size: 13px;
}

.close-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.close-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}

.close-form-field label i {
    color: #4caf50;
    font-size: 11px;
}

.close-form-field textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    resize: vertical;
}

.close-form-field textarea:focus {
    border-color: #4caf50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
    outline: none;
}

.close-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-direction: row-reverse;
}

.close-btn-primary,
.close-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.close-btn-primary {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
}

.close-btn-primary:hover {
    background: linear-gradient(135deg, #388e3c 0%, #2e7d32 100%);
    transform: translateY(-1px);
}

.close-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.close-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}
</style>

<style>
/* Scope Selector Styles */
.scope-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.scope-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #fafafa;
}
.scope-option:hover {
    border-color: #90caf9;
    background: #f5f9ff;
}
.scope-option.selected {
    border-color: #0078d4;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.2);
}
.scope-option input[type="radio"] {
    display: none;
}
.scope-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.scope-option.selected .scope-icon {
    background: linear-gradient(135deg, #4caf50, #388e3c);
}
.scope-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.scope-info strong {
    font-size: 14px;
    color: #2c3e50;
}
.scope-info span {
    font-size: 11px;
    color: #757575;
}
.scope-hint {
    font-size: 12px;
    color: #9c27b0;
    margin: 10px 0 0;
    padding: 8px 12px;
    background: linear-gradient(135deg, #f3e5f5, #e1bee7);
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

@media (max-width: 500px) {
    .scope-selector {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Scope selection handling
document.querySelectorAll('.scope-option').forEach(option => {
    option.addEventListener('click', function() {
        document.querySelectorAll('.scope-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;

        const scope = this.dataset.scope;
        const archHint = document.getElementById('archHint');

        if (scope === 'arch') {
            archHint.style.display = 'flex';
        } else {
            archHint.style.display = 'none';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
