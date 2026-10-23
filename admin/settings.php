<?php
/**
 * System Settings Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'ڕێکخستنەکان';
$breadcrumb = [['title' => 'ڕێکخستنەکان']];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Treatment.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/UnassignedCaseLog.php';
require_once __DIR__ . '/../classes/AutoApproval.php';
require_once __DIR__ . '/../classes/CashboxLedger.php';

// Check admin access
$auth = new Auth();
$auth->requireLogin();
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$treatmentManager = new Treatment();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        // Handle logo file upload
        if (isset($_FILES['clinic_logo_file']) && $_FILES['clinic_logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../logo/';

            // Create logo directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileInfo = pathinfo($_FILES['clinic_logo_file']['name']);
            $extension = strtolower($fileInfo['extension']);
            // SVG removed: it is served inline and can carry embedded scripts (stored XSS).
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($extension, $allowedExts)) {
                $fileName = 'logo.' . $extension;
                $uploadPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['clinic_logo_file']['tmp_name'], $uploadPath)) {
                    // Update the logo path in POST data
                    $_POST['settings']['clinic_logo_path'] = 'logo/' . $fileName;
                }
            }
        }

        if (isset($_POST['settings']['app_font_scale'])) {
            $allowedFontScales = ['1', '1.15', '1.3'];
            $requestedFontScale = (string)$_POST['settings']['app_font_scale'];
            $_POST['settings']['app_font_scale'] = in_array($requestedFontScale, $allowedFontScales, true) ? $requestedFontScale : '1';
        }

        // Boolean settings are stored as the strings '1'/'0'. An unchecked box
        // sends nothing, so each toggle ships a hidden '0' companion input and
        // the checkbox overrides it with '1' — coerce whatever arrives.
        $booleanSettingKeys = [
            Settings::ALLOW_UNASSIGNED_PATIENT_CASES,
            Settings::ALLOW_EDIT_SENT_INVOICE,
            Settings::ALLOW_EDIT_APPROVED_INVOICE,
            AutoApproval::ENABLED,
            CashboxLedger::SPLIT_SETTING,
            Settings::WAITING_CALL_RESTRICTED,
        ];
        foreach ($booleanSettingKeys as $booleanKey) {
            if (isset($_POST['settings'][$booleanKey])) {
                $_POST['settings'][$booleanKey] = ((string)$_POST['settings'][$booleanKey] === '1') ? '1' : '0';
            }
        }
        // «کام دکتۆر دەتوانێت نەخۆش بانگ بکات» — the access tab posts the marker
        // plus one checkbox per doctor; keep only real active doctors.
        if (isset($_POST['call_doctors_posted'])) {
            $postedCallIds = array_map('intval', (array)($_POST['call_doctor_ids'] ?? []));
            $activeDoctorIds = array_map('intval', array_column(
                $db->fetchAll("SELECT id FROM users WHERE role = 'doctor' AND is_active = 1"), 'id'));
            $callIds = array_values(array_intersect(array_unique($postedCallIds), $activeDoctorIds));
            sort($callIds);
            $_POST['settings'][Settings::WAITING_CALL_DOCTOR_IDS] = implode(',', $callIds);
        }
        // Who may call patients in is an access-policy change — log it.
        foreach ([Settings::WAITING_CALL_RESTRICTED, Settings::WAITING_CALL_DOCTOR_IDS] as $callKey) {
            if (isset($_POST['settings'][$callKey]) && $_POST['settings'][$callKey] !== Settings::get($callKey, '')) {
                AuditLog::log('settings_waiting_call', 'settings', null,
                    [$callKey => Settings::get($callKey, '')], [$callKey => $_POST['settings'][$callKey]]);
            }
        }
        if (isset($_POST['settings'][AutoApproval::TIME])
            && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$_POST['settings'][AutoApproval::TIME])) {
            unset($_POST['settings'][AutoApproval::TIME]); // keep the old time rather than save junk
        }
        // Turning the nightly approval on/off or moving its time is a
        // financial-control change too.
        foreach ([AutoApproval::ENABLED, AutoApproval::TIME, CashboxLedger::SPLIT_SETTING] as $autoKey) {
            if (isset($_POST['settings'][$autoKey]) && $_POST['settings'][$autoKey] !== Settings::get($autoKey, '')) {
                AuditLog::log('settings_auto_approve', 'settings', null,
                    [$autoKey => Settings::get($autoKey, '')], [$autoKey => $_POST['settings'][$autoKey]]);
            }
        }

        // Unlocking a sent or an approved receipt is a financial-control change
        // — always logged, one entry per switch that actually moved.
        foreach ([Settings::ALLOW_EDIT_SENT_INVOICE, Settings::ALLOW_EDIT_APPROVED_INVOICE] as $invLockKey) {
            if (!isset($_POST['settings'][$invLockKey])) {
                continue;
            }
            $newInvValue = $_POST['settings'][$invLockKey];
            $oldInvValue = Settings::get($invLockKey, '0');
            if ($newInvValue !== $oldInvValue) {
                AuditLog::log(
                    'settings_invoice_lock', 'settings', null,
                    [$invLockKey => $oldInvValue],
                    [$invLockKey => $newInvValue]
                );
            }
        }

        // Access-policy changes are security-relevant — record who changed what.
        if (isset($_POST['settings'][Settings::ALLOW_UNASSIGNED_PATIENT_CASES])) {
            $newAccessValue = $_POST['settings'][Settings::ALLOW_UNASSIGNED_PATIENT_CASES];
            $oldAccessValue = Settings::get(Settings::ALLOW_UNASSIGNED_PATIENT_CASES, '1');
            if ($newAccessValue !== $oldAccessValue) {
                AuditLog::log(
                    'settings_access_policy', 'settings', null,
                    [Settings::ALLOW_UNASSIGNED_PATIENT_CASES => $oldAccessValue],
                    [Settings::ALLOW_UNASSIGNED_PATIENT_CASES => $newAccessValue]
                );
            }
        }

        foreach ($_POST['settings'] as $key => $value) {
            $existing = $db->fetch("SELECT id FROM settings WHERE setting_key = :key", ['key' => $key]);
            if ($existing) {
                $db->update('settings', ['setting_value' => $value], "setting_key = :key", ['key' => $key]);
            } else {
                $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
        }
        // دەسەڵاتەکانی دکتۆر — real role permissions (role_permissions, doctor
        // role), so they also show on admin/role-permissions.php.
        if (isset($_POST['doctor_perm']) && is_array($_POST['doctor_perm'])) {
            require_once __DIR__ . '/../classes/Permission.php';
            $permissions = Permission::getInstance();
            foreach (['cases.delete_own', 'cases.edit_sent_invoice'] as $permKey) {
                if (!array_key_exists($permKey, $_POST['doctor_perm'])) {
                    continue;
                }
                $allow = (string)$_POST['doctor_perm'][$permKey] === '1';
                $was = $permissions->hasPermission(ROLE_DOCTOR, $permKey);
                if ($allow !== $was) {
                    $permissions->updatePermission(ROLE_DOCTOR, $permKey, $allow);
                    AuditLog::log('settings_doctor_permission', 'role_permissions', null,
                        [$permKey => $was ? 1 : 0], [$permKey => $allow ? 1 : 0]);
                }
            }
        }

        // Just switched on: move the centre's share of every doctor's earlier
        // money now (CashboxLedger::openingSplit), not on someone's next visit.
        if (($_POST['settings'][CashboxLedger::SPLIT_SETTING] ?? null) === '1') {
            Settings::invalidate();
            try {
                (new CashboxLedger())->reconcile();
            } catch (Throwable $e) {
                error_log('cashbox split on: ' . $e->getMessage());
            }
        }
        $returnTab = preg_replace('/[^a-z_]/', '', $_POST['return_tab'] ?? 'general') ?: 'general';
        header('Location: ' . APP_URL . '/admin/settings.php?tab=' . urlencode($returnTab) . '&saved=1');
        exit;
    } elseif ($action === 'auto_approve_now') {
        // «ئێستا جێبەجێی بکە» — the nightly run, now, for everything sent so far.
        $autoResult = AutoApproval::run(date('Y-m-d H:i:s'), 'manual');
        header('Location: ' . APP_URL . '/admin/settings.php?tab=access&auto_ran='
            . (isset($autoResult['skipped']) ? 'busy' : (int)$autoResult['approved']));
        exit;
    } elseif ($action === 'add_treatment') {
        $treatmentManager->create([
            'treatment_code' => '',
            'treatment_name' => trim($_POST['treatment_name']),
            'treatment_name_en' => trim($_POST['treatment_name_en'] ?? ''),
            'category' => $_POST['category'],
            'scope' => $_POST['scope'] ?? 'both',
            'default_price' => floatval($_POST['default_price']),
            'description' => trim($_POST['description'] ?? ''),
            'is_active' => 1
        ]);
        header('Location: ' . APP_URL . '/admin/settings.php?tab=treatments&added=1');
        exit;
    } elseif ($action === 'update_treatment') {
        $treatmentManager->update(intval($_POST['treatment_id']), [
            'treatment_name' => trim($_POST['treatment_name']),
            'treatment_name_en' => trim($_POST['treatment_name_en'] ?? ''),
            'category' => $_POST['category'],
            'scope' => $_POST['scope'] ?? 'both',
            'default_price' => floatval($_POST['default_price']),
            'description' => trim($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ]);
        header('Location: ' . APP_URL . '/admin/settings.php?tab=treatments&updated=1');
        exit;
    } elseif ($action === 'toggle_treatment') {
        $t = $treatmentManager->getById(intval($_POST['treatment_id']));
        if ($t) {
            $treatmentManager->update($t['id'], ['is_active' => $t['is_active'] ? 0 : 1]);
        }
        header('Location: ' . APP_URL . '/admin/settings.php?tab=treatments&'
            . ($t && $t['is_active'] ? 'deactivated=1' : 'activated=1'));
        exit;
    } elseif ($action === 'delete_treatment') {
        // A treatment already used in cases/plans is never deleted — only deactivated.
        $result = $treatmentManager->hardDelete(intval($_POST['treatment_id']));
        header('Location: ' . APP_URL . '/admin/settings.php?tab=treatments&'
            . ($result['success'] ? 'deleted=1' : (!empty($result['in_use']) ? 'in_use=1' : 'delete_failed=1')));
        exit;
    }
}

// Get current settings after POST redirects have completed.
$settings = $db->fetchAll("SELECT * FROM settings");
$settingsMap = [];
foreach ($settings as $s) {
    $settingsMap[$s['setting_key']] = $s['setting_value'];
}

// Get treatments for display.
$treatments = $treatmentManager->getAll(false);
$treatmentCategories = $treatmentManager->getCategories();

$activeTab = $_GET['tab'] ?? 'general';

// Usage per treatment decides delete (unused) vs deactivate-only (used).
$treatmentUsage = $activeTab === 'treatments' ? $treatmentManager->getUsageCounts() : [];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Modern Compact Settings Page */
.settings-panel {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
}

/* Modern Tabs */
.tabs-settings {
    display: flex;
    gap: 4px;
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    padding: 8px;
    border-bottom: 2px solid #e0e0e0;
}

.tab-settings {
    flex: 1;
    padding: 10px 16px;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #546e7a;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.tab-settings:hover {
    background: rgba(255, 255, 255, 0.6);
    color: #0078d4;
}

.tab-settings.active {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    box-shadow: 0 2px 8px rgba(0, 120, 212, 0.3);
}

.tab-settings i {
    font-size: 14px;
}

/* Compact Forms */
.settings-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-row-compact {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}

.form-group-compact {
    margin-bottom: 12px;
}

.form-label-compact {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}

.form-control-compact {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    background: white;
    transition: all 0.2s ease;
}

.form-control-compact:hover {
    border-color: #bdbdbd;
}

.form-control-compact:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

/* Treatments Table */
.treatments-table {
    width: 100%;
    border-collapse: collapse;
}

.treatments-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.treatments-table th {
    padding: 9px 10px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.treatments-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.treatments-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.treatments-table td {
    padding: 10px;
    font-size: 13px;
    color: #2c3e50;
    text-align: center;
}

.treatments-table .btn-warning {
    background: var(--status-warning);
    color: var(--text-on-accent);
}

@media (max-width: 768px) {
    .tabs-settings {
        flex-direction: column;
    }
}

/* ── Access tab: transition switch ── */
.access-card {
    border: 1.5px solid #e3e8ef;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
    background: linear-gradient(135deg, #fbfcfe, #ffffff);
}

.access-card-main {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.access-card-text { flex: 1; }

.access-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.access-card-title i { color: #0078d4; }

.access-card-desc {
    font-size: 12.5px;
    color: #607d8b;
    line-height: 1.9;
}

.access-note {
    margin-top: 14px;
    padding: 10px 12px;
    background: #e8f5e9;
    border: 1px solid #c8e6c9;
    border-radius: 8px;
    display: flex;
    gap: 8px;
    align-items: flex-start;
    font-size: 12px;
    color: #1b5e20;
    line-height: 1.8;
}

.access-note i { color: #2e7d32; margin-top: 3px; }

/* ── Access tab: doctors allowed to call patients in ── */
.call-doctors {
    margin-top: 14px;
    border-top: 1px dashed #dfe5ec;
    padding-top: 12px;
    transition: opacity .2s;
}
.call-doctors.is-off { opacity: .5; }
.call-doctors-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12.5px;
    font-weight: 700;
    color: #455a64;
    margin-bottom: 10px;
}
.call-doctors-count {
    background: #e3f2fd;
    color: #0063b1;
    border-radius: 12px;
    padding: 2px 10px;
    font-size: 11.5px;
}
.call-doctors-empty { font-size: 12.5px; color: #90a4ae; }
.call-doctors-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 8px;
}
.call-doctor { margin: 0; cursor: pointer; }
.call-doctor input { position: absolute; opacity: 0; pointer-events: none; }
.call-doctor-box {
    display: flex;
    flex-direction: column;
    gap: 3px;
    position: relative;
    border: 1.5px solid #e3e8ef;
    border-radius: 9px;
    padding: 9px 12px 9px 34px;
    background: #fff;
    transition: border-color .15s, background .15s;
}
.call-doctor:hover .call-doctor-box { border-color: #90caf9; }
.call-doctor-tick {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    border: 1.5px solid #b0bec5;
    border-radius: 4px;
    font-size: 9px;
    line-height: 13px;
    text-align: center;
    color: transparent;
}
.call-doctor-name { font-size: 13px; font-weight: 600; color: #2c3e50; }
.call-doctor-room { font-size: 11px; color: #78909c; }
.call-doctor input:checked + .call-doctor-box { border-color: #4caf50; background: #f1f8f2; }
.call-doctor input:checked + .call-doctor-box .call-doctor-tick { background: #4caf50; border-color: #4caf50; color: #fff; }
.call-doctor input:focus-visible + .call-doctor-box { outline: 2px solid #0078d4; outline-offset: 2px; }

/* Toggle switch — same component as admin/doctor-permissions.php */
.toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    display: inline-block;
    flex-shrink: 0;
}

.toggle-switch input[type="checkbox"] {
    opacity: 0;
    width: 100%;
    height: 100%;
    position: absolute;
    top: 0;
    left: 0;
    cursor: pointer;
    z-index: 2;
    margin: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 24px;
    z-index: 1;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.toggle-switch input[type="checkbox"]:checked + .toggle-slider {
    background-color: #4caf50;
}

.toggle-switch input[type="checkbox"]:checked + .toggle-slider:before {
    transform: translateX(20px);
}

.access-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.access-stat {
    border: 1.5px solid #e3e8ef;
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    background: #fff;
}

.access-stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #0078d4;
}

.access-stat-label {
    font-size: 11.5px;
    color: #78909c;
    margin-top: 4px;
}

.access-stat-link {
    text-decoration: none;
    transition: all .2s;
}

.access-stat-link:hover {
    border-color: #0078d4;
    box-shadow: 0 2px 8px rgba(0,120,212,.12);
}
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> ڕێکخستنەکان پاشەکەوتکران</div>
<?php endif; ?>
<?php if (isset($_GET['auto_ran'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-robot alert-icon"></i>
    <?= $_GET['auto_ran'] === 'busy' ? 'پەسەندکردنی خۆکار لە ئێستادا کار دەکات — چەند چرکەیەکی تر سەیر بکەرەوە' : (int)$_GET['auto_ran'] . ' وەسڵ خۆکار پەسەندکرا' ?>
</div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر زیادکرا</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر نوێکرایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['activated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر چالاک کرا</div>
<?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر ناچالاک کرا</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر سڕایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['in_use'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> ئەم چارەسەرە بەکارهاتووە و ناسڕدرێتەوە — تەنها دەتوانیت ناچالاکی بکەیت</div>
<?php endif; ?>
<?php if (isset($_GET['delete_failed'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> سڕینەوەی چارەسەر سەرکەوتوو نەبوو</div>
<?php endif; ?>

<div class="settings-panel">
    <div class="tabs-settings">
        <button class="tab-settings <?= $activeTab === 'general' ? 'active' : '' ?>" onclick="location='?tab=general'">
            <i class="fas fa-cog"></i> گشتی
        </button>
        <button class="tab-settings <?= $activeTab === 'display' ? 'active' : '' ?>" onclick="location='?tab=display'">
            <i class="fas fa-text-height"></i> Text Size
        </button>
        <button class="tab-settings <?= $activeTab === 'treatments' ? 'active' : '' ?>" onclick="location='?tab=treatments'">
            <i class="fas fa-tooth"></i> چارەسەرەکان
        </button>
        <button class="tab-settings <?= $activeTab === 'clinic' ? 'active' : '' ?>" onclick="location='?tab=clinic'">
            <i class="fas fa-hospital"></i> کلینیک
        </button>
        <button class="tab-settings <?= $activeTab === 'print' ? 'active' : '' ?>" onclick="location='?tab=print'">
            <i class="fas fa-print"></i> چاپ
        </button>
        <button class="tab-settings <?= $activeTab === 'access' ? 'active' : '' ?>" onclick="location='?tab=access'">
            <i class="fas fa-user-shield"></i> دەستگەیشتن
        </button>
    </div>

    <!-- Access Settings (transition switch) -->
    <?php if ($activeTab === 'access'): ?>
    <?php
        $allowUnassigned = Settings::allowUnassignedPatientCases();
        $unassignedTotal    = UnassignedCaseLog::count();
        $unassignedPatients = count(UnassignedCaseLog::byDoctor());
    ?>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="access">
            <h4 class="mb-3">دەستگەیشتنی دکتۆر بە نەخۆشەکان</h4>

            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-unlock-alt"></i>
                            ڕێگەدان بە دروستکردنی کەیس بۆ نەخۆشی ئازاد
                        </div>
                        <div class="access-card-desc">
                            کاتێک <b>چالاک</b> بێت، دکتۆر دەتوانێت هەموو نەخۆشێکی کلینیک بدۆزێتەوە و
                            کەیسی نوێی خۆی بۆ دروست بکات — تەنانەت ئەگەر لە ڕێگەی پێشوازییەوە بۆی
                            نەنێردرابێت. ئەمە بۆ قۆناغی ڕێکخستنی داتایە.
                            <br>
                            کاتێک <b>ناچالاک</b> بێت، دکتۆر تەنها ئەو نەخۆشانە دەبینێت کە خۆی تۆماری
                            کردوون یان لە ڕێگەی پێشوازییەوە (مەوعید / ژووری چاوەڕوان) بۆی ڕەوانە کراون.
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[allow_unassigned_patient_cases]" value="0">
                        <input type="checkbox" id="allowUnassigned"
                               name="settings[allow_unassigned_patient_cases]" value="1"
                               <?= $allowUnassigned ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>

                <div class="access-note">
                    <i class="fas fa-shield-alt"></i>
                    <span>
                        ئەم ئۆپشنە <b>هەرگیز</b> ڕێگە نادات بە دکتۆرێک کەیس، چارەسەر، ئێکسڕەی یان
                        حسابی دکتۆرێکی تر ببینێت. تەنها زانیاری بنەڕەتی نەخۆش (ناو، تەمەن، ڕەگەز،
                        ژمارەی مۆبایل) دەبینرێت و کەیسی نوێ بەتاڵ دەست پێدەکات.
                    </span>
                </div>
            </div>

            <h4 class="mb-3" style="margin-top:26px;">بانگکردنی نەخۆش لە ژووری چاوەڕوانی</h4>

            <?php
                $callRestricted = Settings::waitingCallRestricted();
                $callDoctorIds  = Settings::waitingCallDoctorIds();
                $callDoctors    = $db->fetchAll(
                    "SELECT id, full_name, room_number, room_number_2 FROM users
                     WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
                );
            ?>
            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-bullhorn"></i>
                            تەنها ئەو دکتۆرانەی خوارەوە بتوانن خۆیان نەخۆش بانگ بکەن
                        </div>
                        <div class="access-card-desc">
                            کاتێک <b>ناچالاک</b> بێت، هەموو دکتۆرێک دەتوانێت نەخۆشی خۆی لە ژووری چاوەڕوانییەوە بانگ بکات.
                            <br>
                            کاتێک <b>چالاک</b> بێت، تەنها ئەو دکتۆرانەی لێرە نیشانەیان لێدراوە دوگمەی <b>«بانگکردن»</b>یان بۆ دەردەکەوێت؛
                            نەخۆشی دکتۆرەکانی تر لەلایەن <b>پێشوازی</b> یان بەڕێوەبەرەوە بانگ دەکرێن.
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[<?= Settings::WAITING_CALL_RESTRICTED ?>]" value="0">
                        <input type="checkbox" id="waitingCallRestricted"
                               name="settings[<?= Settings::WAITING_CALL_RESTRICTED ?>]" value="1"
                               <?= $callRestricted ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>

                <input type="hidden" name="call_doctors_posted" value="1">
                <div class="call-doctors <?= $callRestricted ? '' : 'is-off' ?>" id="callDoctorsList">
                    <div class="call-doctors-head">
                        <span>دکتۆرانی ڕێگەپێدراو بە بانگکردن</span>
                        <span class="call-doctors-count" id="callDoctorsCount"></span>
                    </div>
                    <?php if (!$callDoctors): ?>
                    <div class="call-doctors-empty">هیچ دکتۆرێکی چالاک نییە</div>
                    <?php else: ?>
                    <div class="call-doctors-grid">
                        <?php foreach ($callDoctors as $cd):
                            $cdRooms = array_filter([trim((string)$cd['room_number']), trim((string)$cd['room_number_2'])], 'strlen'); ?>
                        <label class="call-doctor">
                            <input type="checkbox" name="call_doctor_ids[]" value="<?= (int)$cd['id'] ?>"
                                   <?= in_array((int)$cd['id'], $callDoctorIds, true) ? 'checked' : '' ?>>
                            <span class="call-doctor-box">
                                <i class="fas fa-check call-doctor-tick"></i>
                                <span class="call-doctor-name"><?= htmlspecialchars($cd['full_name']) ?></span>
                                <?php if ($cdRooms): ?>
                                <span class="call-doctor-room"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars(implode(' / ', $cdRooms)) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="access-note">
                    <i class="fas fa-shield-alt"></i>
                    <span>
                        ئەمە تەنها دوگمەی بانگکردن دەگرێتەوە — دکتۆر هێشتا دەتوانێت نەخۆشی ناو چارەسەری خۆی تەواو بکات و بینێرێت بۆ ژمێریاری.
                        هیچ دکتۆرێک نەخۆشی دکتۆرێکی تر بانگ ناکات. پێشوازی و بەڕێوەبەر هەمیشە دەتوانن بانگ بکەن.
                    </span>
                </div>
            </div>
            <script>
            (function () {
                var sw = document.getElementById('waitingCallRestricted');
                var list = document.getElementById('callDoctorsList');
                var count = document.getElementById('callDoctorsCount');
                if (!sw || !list) return;
                function sync() {
                    list.classList.toggle('is-off', !sw.checked);
                    var n = list.querySelectorAll('input[name="call_doctor_ids[]"]:checked').length;
                    count.textContent = sw.checked ? (n + ' دکتۆر') : 'هەموو دکتۆرەکان';
                }
                sw.addEventListener('change', sync);
                list.addEventListener('change', sync);
                sync();
            })();
            </script>

            <h4 class="mb-3" style="margin-top:26px;">قوفڵی وەسڵی نێردراو</h4>

            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-lock-open"></i>
                            ڕێگەدان بە دەستکاریکردنی وەسڵی نێردراو
                        </div>
                        <div class="access-card-desc">
                            کاتێک <b>ناچالاک</b> بێت (پێشنیارکراو)، هەر وەسڵێک بنێردرێت بۆ ژمێریار
                            بە تەواوی قوفڵ دەدرێت — نە چارەسەرەکانی و نە داشکاندنەکەی ناگۆڕدرێن،
                            بۆ هیچ بەکارهێنەرێک، تەنانەت بەڕێوەبەریش.
                            <br>
                            کاتێک <b>چالاک</b> بێت، تەنها <b>بەڕێوەبەر</b> دەتوانێت هەڵەیەکی وەسڵێکی
                            نێردراو ڕاست بکاتەوە. دکتۆر هەرگیز ناتوانێت.
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[allow_edit_sent_invoice]" value="0">
                        <input type="checkbox" id="allowEditSentInvoice"
                               name="settings[allow_edit_sent_invoice]" value="1"
                               <?= Settings::allowEditSentInvoice() ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>

                <div class="access-note">
                    <i class="fas fa-shield-alt"></i>
                    <span>
                        وەسڵی نێردراو بەڵگەیەکی داراییە کە ژمێریار کاری لەسەر دەکات. گۆڕینی
                        دوای ناردن واتە ئەو ژمارەیەی چاپکراوە لەگەڵ سیستەم یەک ناگرێتەوە.
                        هەر گۆڕانێکی ئەم ئۆپشنە لە تۆماری چالاکییەکاندا هەڵدەگیرێت.
                    </span>
                </div>
            </div>

            <h4 class="mb-3" style="margin-top:26px;">دەستکاریکردنی تۆمارە پەسەندکراوەکان</h4>

            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-unlock"></i>
                            ڕێگەدان بە <b>ژمێریار و بەڕێوەبەر</b> بۆ تەعدیلی وەسڵ، قەبزی پارەدان، پلانی قیست و ئێکسڕەی دوای پەسەندکردن
                        </div>
                        <div class="access-card-desc">
                            کاتێک <b>ناچالاک</b> بێت (پێشنیارکراو)، هەر تۆمارێکی پەسەندکراو کۆتاییە و ناگۆڕدرێت.
                            <br>
                            کاتێک <b>چالاک</b> بێت، لە تابی <b>پەسەندکراو</b>ی
                            <a href="<?= APP_URL ?>/accountant/approval-center.php" target="_blank">ناوەندی پەسەندکردن</a>
                            هەر تۆمارێک بە <b>قوفڵکراوی</b> دەکرێتەوە و دوگمەی <b>«تەعدیل»</b>ی تێدایە؛ تەنها دوای
                            داگرتنی «تەعدیل» دەگۆڕدرێت:
                            <b>وەسڵ</b> — داشکاندن، وەرگیراو، جۆری پارەدان و تێبینی؛
                            <b>قەبزی پارەدان</b> — بڕەکەی (لەسەر هەمان پسووڵەکان دەمێنێتەوە)؛
                            <b>پلانی قیست</b> — کۆ، پێشەکی، ژمارەی قیست و بەرواری دەستپێک (خشتەکە دووبارە دروست دەکرێتەوە و پارە دراوەکان دەمێننەوە)؛
                            <b>ئێکسڕەی</b> — نرخ، داشکاندن و بڕی وەرگیراو لەکاتی پەسەندکردن.
                            تۆمارەکە <b>پەسەندکراو دەمێنێتەوە</b> و <b>بەروارەکەی ناگۆڕێت</b>.
                            <br>
                            دوگمەی <b>«سڕینەوە»</b>ی وەسڵ: وەسڵەکە دەچێتە دۆخی <b>هەڵوەشاوە</b>، هەموو قەبزەکانی
                            <b>«سڕینەوە»</b> — وەسڵەکە دەچێتە دۆخی <b>هەڵوەشاوە</b>، هەموو قەبزەکانی
                            پێوەبەستراو هەڵدەوەشێنەوە، ئامانەت دەگەڕێتەوە بۆ باڵانسی نەخۆش و
                            چارەسەرەکان ئازاد دەکرێن. هەموو گۆڕانکارییەک لە تۆماری چالاکییەکان
                            هەڵدەگیرێت.
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[allow_edit_approved_invoice]" value="0">
                        <input type="checkbox" id="allowEditApprovedInvoice"
                               name="settings[allow_edit_approved_invoice]" value="1"
                               <?= Settings::allowEditApprovedInvoice() ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>

                <div class="access-note">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>
                        تەعدیلی تۆماری پەسەندکراو <b>ژمارە داراییەکانی ئەو ڕۆژە دەگۆڕێت</b>
                        (کۆی کەیس، کشف حسابی نەخۆش و ڕاپۆرتەکان)، چونکە تۆمارەکە پەسەندکراوە.
                        سیستەم ڕێگە نادات پارەی دراو لە کۆ تێپەڕێت یان کۆ لە پارەی دراو کەمتر بێت؛
                        پارەی دواتر کۆکراوە هەرگیز ناگۆڕدرێت. ئەو وەسڵانەی پلانی قیستی <b>چالاک</b>یان
                        هەیە پارەکەیان ناگۆڕدرێت، و قەبزی کارە ئەنجامدراوەکان، پارەی پێشەکی و قەرزی
                        دابەشکراو لە شوێنی خۆیان دەگۆڕدرێن نەک لێرە. هەر تەعدیلێک لە تۆماری چالاکی
                        هەڵدەگیرێت.
                    </span>
                </div>
            </div>

            <h4 class="mb-3" style="margin-top:26px;">پەسەندکردنی خۆکاری شەوانە</h4>

            <?php
            $autoEnabled = AutoApproval::enabled();
            $autoTime = AutoApproval::time();
            $autoLast = AutoApproval::lastResult();
            $autoLastRun = (string)Settings::get(AutoApproval::LAST_RUN, '');
            $autoNextDay = (date('H:i') < $autoTime && $autoLastRun !== date('Y-m-d')) ? date('Y-m-d') : date('Y-m-d', strtotime('+1 day'));
            $autoWaiting = (int)$db->fetch("SELECT COUNT(*) AS n FROM case_invoices WHERE accounting_status = 'sent'")['n'];
            ?>
            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-robot"></i>
                            هەموو شەوێک، <b>هەموو وەسڵە نێردراوەکان</b> خۆکار پەسەند بکرێن
                        </div>
                        <div class="access-card-desc">
                            لە کاتی دیاریکراودا هەر وەسڵێک کە لای ژمێریار چاوەڕوانە (نەقد، قەرز یان قیست) پەسەند دەکرێت —
                            ڕێک وەک ئەوەی ژمێریار دوگمەی پەسەندکردنی دابگرێت: وەرگیراو دەچێتە سندوقی دکتۆر،
                            ماوە دەبێتە قەرزی نەخۆش، و پلانی قیست بۆ ژمێریار دەمێنێتەوە.
                            تەنها ئەو وەسڵانە دەگرێتەوە کە <b>پێش</b> ئەو کاتە نێردراون.
                            لە تابی <b>پەسەندکراو</b>ی
                            <a href="<?= APP_URL ?>/accountant/approval-center.php?tab=approved&amp;approval=auto" target="_blank">ناوەندی پەسەندکردن</a>
                            هەر وەسڵێک نیشانەی <b>🤖 خۆکار</b> یان <b>✋ دەستی</b>ی لەسەرە.
                            <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <label for="autoApproveTime" style="font-weight:700;">کات:</label>
                                <input type="time" id="autoApproveTime" name="settings[auto_approve_time]"
                                       value="<?= htmlspecialchars($autoTime) ?>" class="form-control" style="width:130px;direction:ltr;">
                                <span style="color:#64748b;font-size:12px;">
                                    <?php if ($autoEnabled): ?>
                                    جاری داهاتوو: <b dir="ltr"><?= htmlspecialchars($autoNextDay . ' ' . $autoTime) ?></b>
                                    · ئێستا <b><?= number_format($autoWaiting) ?></b> وەسڵ چاوەڕوانە
                                    <?php else: ?>
                                    ناچالاکە
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[auto_approve_enabled]" value="0">
                        <input type="checkbox" id="autoApproveEnabled"
                               name="settings[auto_approve_enabled]" value="1"
                               <?= $autoEnabled ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>

                <div class="access-note">
                    <i class="fas fa-clock"></i>
                    <span>
                        <?php if ($autoLast): ?>
                        دوایین جار: <b dir="ltr"><?= htmlspecialchars($autoLast['ran_at']) ?></b>
                        (<?= ($autoLast['trigger'] ?? '') === 'manual' ? 'بە دەست' : 'خۆکار' ?>) —
                        <b><?= (int)$autoLast['approved'] ?></b> وەسڵ پەسەندکرا
                        (نەقد <?= (int)($autoLast['by_intent']['cash'] ?? 0) ?>،
                        قەرز <?= (int)($autoLast['by_intent']['debt'] ?? 0) ?>،
                        قیست <?= (int)($autoLast['by_intent']['installment'] ?? 0) ?>)<?php if (!empty($autoLast['failed'])): ?>،
                        <b style="color:#b91c1c;"><?= count($autoLast['failed']) ?></b> پەسەند نەکرا:
                        <?= htmlspecialchars(implode(' · ', array_map(function ($f) { return $f['invoice_no'] . ' — ' . $f['message']; }, array_slice($autoLast['failed'], 0, 5)))) ?>
                        <?php endif; ?>.
                        <?php else: ?>
                        هێشتا هیچ جارێک جێبەجێ نەکراوە.
                        <?php endif; ?>
                        <br>
                        کارەکە لە ڕێگەی <b>Task Scheduler</b>ی ویندۆزەوە هەر ١٥ خولەک جارێک دەپشکنێت، بۆیە ئەگەر کۆمپیوتەر لەو کاتەدا
                        کوژاوە بوو، کە هەڵکرایەوە جێبەجێ دەبێت. هەروەها یەکەم کردنەوەی ناوەندی پەسەندکردن دوای ئەو کاتە جێبەجێی دەکات.
                        هەر وەسڵێکی خۆکار پەسەندکراو وەک هەر پەسەندکراوێکی تر دەتوانرێت تەعدیل یان ڕەش بکرێتەوە.
                    </span>
                </div>
            </div>

            <h4 class="mb-3" style="margin-top:26px;">دەسەڵاتەکانی دکتۆر</h4>

            <?php
            require_once __DIR__ . '/../classes/Permission.php';
            $docPerms = Permission::getInstance();
            $doctorPermCards = [
                'cases.delete_own' => [
                    'icon' => 'fa-trash-alt',
                    'title' => 'دکتۆر بتوانێت <b>کەیسی خۆی بسڕێتەوە</b>',
                    'desc' => 'تەنها کەیسی خۆی، و تەنها ئەو کەیسانەی <b>تەنها قەرزن</b> — هیچ پارەیەکیان لێ وەرنەگیراوە '
                            . '(نە پارەی ناو وەسڵ، نە دراوی پێشوو، نە ئامانەت، نە قەبز یان قیست، پەسەندکراو بێت یان چاوەڕوان). '
                            . 'کەیسێک پارەی وەرگیراوی هەبێت تەنها بەڕێوەبەر دەیسڕێتەوە. هەر سڕینەوەیەک هۆکاری دەوێت و ئەرشیف دەکرێت.',
                ],
                'cases.edit_sent_invoice' => [
                    'icon' => 'fa-pen-to-square',
                    'title' => 'دکتۆر بتوانێت <b>دەستکاری ناو وەسڵی کەیس</b> بکات دوای ناردن',
                    'desc' => 'چارەسەر و نرخەکانی وەسڵی نێردراو و پەسەندکراو ڕاست بکاتەوە، و داشکاندن تا وەسڵەکە لای ژمێریارە. '
                            . 'ئەمە کۆی وەسڵ و ماوە دەگۆڕێت، بەڵام <b>پارەی دراو هەرگیز ناگۆڕێت</b>.',
                ],
            ];
            ?>
            <?php foreach ($doctorPermCards as $permKey => $card): ?>
            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title"><i class="fas <?= $card['icon'] ?>"></i> <?= $card['title'] ?></div>
                        <div class="access-card-desc"><?= $card['desc'] ?></div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="doctor_perm[<?= htmlspecialchars($permKey) ?>]" value="0">
                        <input type="checkbox" name="doctor_perm[<?= htmlspecialchars($permKey) ?>]" value="1"
                               <?= $docPerms->hasPermission(ROLE_DOCTOR, $permKey) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="access-note" style="margin-bottom:6px;">
                <i class="fas fa-lock"></i>
                <span>
                    <b>هەمیشە، بەبێ گوێدانە ئەم ئۆپشنانە:</b> دکتۆر بە هیچ شێوەیەک ناتوانێت دەستکاری ئەو پارەیە بکات کە ژمێریار یان ئەدمین لە
                    نەخۆشی وەرگرتووە و پەسەندکراوە — وەرگیراو، دراوی پێشوو، ئامانەت، قەبزی پارەدان و قیست. دکتۆر تەنها لەسەر وەسڵی
                    نەنێردراو (یان گەڕێندراوە بۆ ڕاستکردنەوە) پارە دەنووسێت، و کۆی وەسڵ هەرگیز لە پارەی دراو کەمتر نابێت.
                </span>
            </div>

            <h4 class="mb-3" style="margin-top:26px;">دابەشکردنی ڕاستەوخۆی پارە بە ڕێژە</h4>

            <?php $splitOn = CashboxLedger::splitEnabled(); ?>
            <div class="access-card">
                <div class="access-card-main">
                    <div class="access-card-text">
                        <div class="access-card-title">
                            <i class="fas fa-code-branch"></i>
                            هەر پارەیەک وەردەگیرێت، <b>ڕێژەی دکتۆر</b> بچێتە سندوقی دکتۆر و <b>ئەوەی تر</b> بچێتە <b>قاسەی لێزان</b>
                        </div>
                        <div class="access-card-desc">
                            بۆ نموونە د. بە ڕێژەی 80%: لە 100,000، <b>80,000</b> دەچێتە سندوقەکەی و <b>20,000</b> دەچێتە قاسەی لێزان —
                            لە هەمان کاتی وەرگرتنی پارەکەدا (پسووڵەی پەسەندکراو، قەبز، قیست، قەرزی کۆن). گەڕاندنەوە و تەعدیل بە هەمان ڕێژە دەگەڕێنەوە.
                            <br>
                            خەرجی تاقیگە و کۆگای دکتۆر: ڕێژەی دکتۆر لێی لە سندوقەکەی دەچێتە قاسەی لێزان (چونکە لێزان خەرجییەکەی داوە).
                            ڕێژەی هەر پارەیەک لەسەری دەمێنێت؛ گۆڕینی ڕێژەی دکتۆر تەنها پارەی نوێ دەگرێتەوە.
                            ڕێژەکان لە ئەکاونتی دکتۆر (<a href="<?= APP_URL ?>/admin/users.php" target="_blank">بەکارهێنەران</a>) دیاری دەکرێن.
                        </div>
                    </div>
                    <div class="toggle-switch">
                        <input type="hidden" name="settings[cashbox_split_enabled]" value="0">
                        <input type="checkbox" id="cashboxSplitEnabled"
                               name="settings[cashbox_split_enabled]" value="1"
                               <?= $splitOn ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </div>
                <div class="access-note">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>
                        کاتێک <b>چالاک</b> دەکرێت، بۆ هەر دکتۆرێک یەک جار پشکی لێزان لە هەموو پارەی پێشووی ناو سندوقەکەی
                        (لە ١/١٠ەوە) بە یەک جووڵە دەگوازرێتەوە بۆ قاسەی لێزان. هیچ جووڵەیەکی کۆن ناسڕدرێتەوە یان ناگۆڕدرێت.
                    </span>
                </div>
            </div>

            <div class="access-stats">
                <div class="access-stat">
                    <div class="access-stat-value"><?= number_format($unassignedTotal) ?></div>
                    <div class="access-stat-label">کەیسی ئازاد کە دروستکراوە</div>
                </div>
                <div class="access-stat">
                    <div class="access-stat-value"><?= number_format($unassignedPatients) ?></div>
                    <div class="access-stat-label">دکتۆر بەکاریان هێناوە</div>
                </div>
                <a href="<?= APP_URL ?>/admin/unassigned-cases.php" class="access-stat access-stat-link">
                    <div class="access-stat-value"><i class="fas fa-list"></i></div>
                    <div class="access-stat-label">بینینی تۆماری تەواو</div>
                </a>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </form>

        <!-- Runs the nightly approval now, with the current time as the cutoff.
             Its own form: the settings form above posts save_settings. -->
        <form method="POST" action="" style="margin-top:12px;"
              onsubmit="return confirm('هەموو وەسڵە نێردراوەکان (<?= (int)$autoWaiting ?>) ئێستا خۆکار پەسەند بکرێن؟');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="auto_approve_now">
            <button type="submit" class="btn btn-secondary" <?= $autoWaiting > 0 ? '' : 'disabled' ?>>
                <i class="fas fa-robot"></i> ئێستا جێبەجێی بکە (<?= number_format($autoWaiting) ?> وەسڵ)
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- General Settings -->
    <?php if ($activeTab === 'general'): ?>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="general">
            <h4 class="mb-3">ڕێکخستنەکانی گشتی</h4>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ناوی سیستەم</label>
                    <input type="text" name="settings[app_name]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['app_name'] ?? APP_NAME) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">دراوە</label>
                    <select name="settings[currency]" class="form-control">
                        <option value="IQD" <?= ($settingsMap['currency'] ?? 'IQD') === 'IQD' ? 'selected' : '' ?>>دینار عێراقی (IQD)</option>
                        <option value="USD" <?= ($settingsMap['currency'] ?? 'IQD') === 'USD' ? 'selected' : '' ?>>دۆلار (USD)</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">کاتی دەستپێکردن</label>
                    <input type="time" name="settings[work_start]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['work_start'] ?? '09:00') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">کاتی کۆتایی</label>
                    <input type="time" name="settings[work_end]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['work_end'] ?? '21:00') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ماوەی مەوعید (خولەک)</label>
                    <select name="settings[default_appointment_duration]" class="form-control">
                        <option value="15" <?= ($settingsMap['default_appointment_duration'] ?? '30') === '15' ? 'selected' : '' ?>>١٥</option>
                        <option value="30" <?= ($settingsMap['default_appointment_duration'] ?? '30') === '30' ? 'selected' : '' ?>>٣٠</option>
                        <option value="45" <?= ($settingsMap['default_appointment_duration'] ?? '30') === '45' ? 'selected' : '' ?>>٤٥</option>
                        <option value="60" <?= ($settingsMap['default_appointment_duration'] ?? '30') === '60' ? 'selected' : '' ?>>٦٠</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Display Settings -->
    <?php if ($activeTab === 'display'): ?>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="display">
            <h4 class="mb-3">Text Size Settings</h4>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Application text size</label>
                    <select name="settings[app_font_scale]" id="appFontScaleSelect" class="form-control">
                        <?php
                        $currentFontScaleRaw = (float)($settingsMap['app_font_scale'] ?? 1);
                        $currentFontScale = $currentFontScaleRaw >= 1.25 ? '1.3' : ($currentFontScaleRaw >= 1.08 ? '1.15' : '1');
                        ?>
                        <option value="1" <?= $currentFontScale === '1' ? 'selected' : '' ?>>Level 1 - Normal (100%)</option>
                        <option value="1.15" <?= $currentFontScale === '1.15' ? 'selected' : '' ?>>Level 2 - Large (115%)</option>
                        <option value="1.3" <?= $currentFontScale === '1.3' ? 'selected' : '' ?>>Level 3 - Extra large (130%)</option>
                    </select>
                    <div class="form-hint">Three levels for the whole project: normal, large, and extra large.</div>
                </div>
            </div>

            <div class="panel" style="margin-top: 12px;">
                <div class="panel-header">
                    <div class="panel-title"><i class="fas fa-eye"></i> Preview</div>
                </div>
                <div class="panel-body">
                    <p style="margin-bottom: 10px;">Sample text preview for the selected interface size.</p>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary"><i class="fas fa-check"></i> Primary button</button>
                        <button type="button" class="btn btn-secondary"><i class="fas fa-edit"></i> Secondary button</button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save text size
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Treatments Settings -->
    <?php if ($activeTab === 'treatments'): ?>
    <div class="panel-body">
        <div class="d-flex justify-between align-center mb-3">
            <h4>لیستی چارەسەرەکان</h4>
            <button class="btn btn-primary" onclick="openDialog('addTreatmentDialog')">
                <i class="fas fa-plus"></i> چارەسەری نوێ
            </button>
        </div>

        <div class="table-container">
            <table class="treatments-table">
                <thead>
                    <tr>
                        <th>کۆد</th>
                        <th>ناو</th>
                        <th>پۆل</th>
                        <th>بوار</th>
                        <th>نرخ</th>
                        <th>دۆخ</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $scopeLabels = ['tooth' => 'ددان', 'arch' => 'ئەرک', 'both' => 'هەردووک'];
                    $scopeColors = ['tooth' => '#2196f3', 'arch' => '#9c27b0', 'both' => '#4caf50'];
                    foreach ($treatments as $t):
                    ?>
                    <tr class="<?= !$t['is_active'] ? 'opacity-50' : '' ?>">
                        <td><?= $t['treatment_code'] ?></td>
                        <td><?= htmlspecialchars($t['treatment_name']) ?></td>
                        <td><span class="badge"><?= $treatmentCategories[$t['category']] ?? $t['category'] ?></span></td>
                        <td>
                            <span class="badge" style="background: <?= $scopeColors[$t['scope'] ?? 'both'] ?>;">
                                <?= $scopeLabels[$t['scope'] ?? 'both'] ?>
                            </span>
                        </td>
                        <td><?= number_format($t['default_price']) ?></td>
                        <td>
                            <span class="badge <?= $t['is_active'] ? 'badge-green' : 'badge-red' ?>">
                                <?= $t['is_active'] ? 'چالاک' : 'ناچالاک' ?>
                            </span>
                        </td>
                        <td>
                            <?php $usage = $treatmentUsage[(int)$t['id']] ?? 0; ?>
                            <div style="display: flex; gap: 5px; align-items: center; justify-content: center;">
                                <button class="btn btn-sm btn-secondary" title="دەستکاری" onclick="editTreatment(<?= htmlspecialchars(json_encode($t)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;"
                                      onsubmit="return confirm('<?= $t['is_active'] ? 'دڵنیایت لە ناچالاککردنی ئەم چارەسەرە؟' : 'دڵنیایت لە چالاککردنی ئەم چارەسەرە؟' ?>')">
                                    <input type="hidden" name="action" value="toggle_treatment">
                                    <input type="hidden" name="treatment_id" value="<?= (int)$t['id'] ?>">
                                    <?php if ($t['is_active']): ?>
                                    <button type="submit" class="btn btn-sm btn-warning" title="ناچالاککردن">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-success" title="چالاککردن">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <?php endif; ?>
                                </form>
                                <?php if ($usage === 0): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم چارەسەرە؟ ئەم کارە ناگەڕێتەوە.')">
                                    <input type="hidden" name="action" value="delete_treatment">
                                    <input type="hidden" name="treatment_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted" style="font-size: 11px;" title="بەکارهاتووە <?= $usage ?> جار — ناسڕدرێتەوە، تەنها ناچالاک دەکرێت">
                                    <i class="fas fa-lock"></i> <?= $usage ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Clinic Settings -->
    <?php if ($activeTab === 'clinic'): ?>
    <div class="panel-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="clinic">
            <h4 class="mb-3">زانیاری کلینیک</h4>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ناوی کلینیک (کوردی)</label>
                    <input type="text" name="settings[clinic_name]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['clinic_name'] ?? '') ?>"
                           placeholder="کلینیکی ددان">
                </div>
                <div class="form-group">
                    <label class="form-label">ناوی کلینیک (ئینگلیزی)</label>
                    <input type="text" name="settings[clinic_name_en]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['clinic_name_en'] ?? '') ?>"
                           placeholder="Dental Clinic">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">ژێرناونیشان</label>
                <input type="text" name="settings[clinic_subtitle]" class="form-control"
                       value="<?= htmlspecialchars($settingsMap['clinic_subtitle'] ?? '') ?>"
                       placeholder="سەنتەری تایبەتی...">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ژمارەی پەیوەندی 1</label>
                    <input type="text" name="settings[clinic_phone]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['clinic_phone'] ?? '') ?>"
                           placeholder="075000000">
                </div>
                <div class="form-group">
                    <label class="form-label">ژمارەی پەیوەندی 2</label>
                    <input type="text" name="settings[clinic_phone_2]" class="form-control"
                           value="<?= htmlspecialchars($settingsMap['clinic_phone_2'] ?? '') ?>"
                           placeholder="0502222">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">ناونیشان</label>
                <textarea name="settings[clinic_address]" class="form-control" rows="2"><?= htmlspecialchars($settingsMap['clinic_address'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">ئیمەیڵ</label>
                <input type="email" name="settings[clinic_email]" class="form-control"
                       value="<?= htmlspecialchars($settingsMap['clinic_email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">وێنەی لۆگۆ</label>
                <input type="file" name="clinic_logo_file" id="clinic_logo_file" class="form-control" accept="image/*" onchange="previewLogo(this)">
                <small class="form-text">فایلی وێنە هەڵبژێرە (JPG, PNG, GIF, SVG)</small>

                <?php
                $currentLogoPath = $settingsMap['clinic_logo_path'] ?? 'logo/logo.jpg';
                $logoFullPath = __DIR__ . '/../' . $currentLogoPath;
                if (file_exists($logoFullPath)):
                ?>
                <div id="logoPreview" style="margin-top: 15px; text-align: center;">
                    <p style="font-size: 10pt; color: #666; margin-bottom: 10px;">لۆگۆی ئێستا:</p>
                    <img src="<?= APP_URL . '/' . $currentLogoPath ?>?v=<?= time() ?>" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; padding: 10px; border-radius: 8px;">
                </div>
                <?php endif; ?>

                <input type="hidden" name="settings[clinic_logo_path]" value="<?= htmlspecialchars($currentLogoPath) ?>">
                <input type="hidden" name="settings[clinic_logo]" value="<?= htmlspecialchars($settingsMap['clinic_logo'] ?? '🦷') ?>">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Print Settings -->
    <?php if ($activeTab === 'print'): ?>
    <div class="panel-body">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="return_tab" value="print">
            <h4 class="mb-3">ڕێکخستنەکانی چاپ</h4>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">قەبارەی وەسڵ</label>
                    <select name="settings[invoice_size]" class="form-control">
                        <option value="A4" <?= ($settingsMap['invoice_size'] ?? 'A4') === 'A4' ? 'selected' : '' ?>>A4</option>
                        <option value="A5" <?= ($settingsMap['invoice_size'] ?? 'A4') === 'A5' ? 'selected' : '' ?>>A5</option>
                        <option value="thermal" <?= ($settingsMap['invoice_size'] ?? 'A4') === 'thermal' ? 'selected' : '' ?>>تێرماڵ (٨٠ملم)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">مەرجەکان (لەسەر وەسڵ)</label>
                <textarea name="settings[invoice_terms]" class="form-control" rows="4"><?= htmlspecialchars($settingsMap['invoice_terms'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">نووسینی ژێر وەسڵ</label>
                <input type="text" name="settings[invoice_footer]" class="form-control"
                       value="<?= htmlspecialchars($settingsMap['invoice_footer'] ?? 'سوپاس بۆ متمانەکەت') ?>">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Add Treatment Dialog -->
<div class="dialog-overlay" id="addTreatmentDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title">زیادکردنی چارەسەر</h4>
            <button class="dialog-close" onclick="closeDialog('addTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_treatment">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی چارەسەر (کوردی)</label>
                    <input type="text" name="treatment_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">ناوی چارەسەر (ئینگلیزی)</label>
                    <input type="text" name="treatment_name_en" class="form-control">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">پۆل</label>
                        <select name="category" class="form-control" required>
                            <?php foreach ($treatmentCategories as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">نرخ</label>
                        <input type="number" name="default_price" class="form-control" required min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">بوار (ددان/ئەرک)</label>
                    <select name="scope" class="form-control" required>
                        <option value="tooth">ددان تەنها</option>
                        <option value="arch">ئەرک تەنها</option>
                        <option value="both" selected>هەردووکیان</option>
                    </select>
                    <small style="color: #666; font-size: 11px;">کام جۆری کەیس ئەم چارەسەرە پیشان دەدرێت</small>
                </div>
                <div class="form-group">
                    <label class="form-label">وەسف</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('addTreatmentDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Treatment Dialog -->
<div class="dialog-overlay" id="editTreatmentDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title">دەستکاری چارەسەر</h4>
            <button class="dialog-close" onclick="closeDialog('editTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_treatment">
            <input type="hidden" name="treatment_id" id="edit_t_id">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی چارەسەر (کوردی)</label>
                    <input type="text" name="treatment_name" id="edit_t_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">ناوی چارەسەر (ئینگلیزی)</label>
                    <input type="text" name="treatment_name_en" id="edit_t_name_en" class="form-control">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">پۆل</label>
                        <select name="category" id="edit_t_category" class="form-control" required>
                            <?php foreach ($treatmentCategories as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">نرخ</label>
                        <input type="number" name="default_price" id="edit_t_price" class="form-control" required min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">بوار (ددان/ئەرک)</label>
                    <select name="scope" id="edit_t_scope" class="form-control" required>
                        <option value="tooth">ددان تەنها</option>
                        <option value="arch">ئەرک تەنها</option>
                        <option value="both">هەردووکیان</option>
                    </select>
                    <small style="color: #666; font-size: 11px;">کام جۆری کەیس ئەم چارەسەرە پیشان دەدرێت</small>
                </div>
                <div class="form-group">
                    <label class="form-label">وەسف</label>
                    <textarea name="description" id="edit_t_desc" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="edit_t_active" value="1">
                    <label class="form-check-label" for="edit_t_active">چالاک</label>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('editTreatmentDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('appFontScaleSelect')?.addEventListener('change', function() {
    const scale = parseFloat(this.value || '1');
    document.documentElement.style.setProperty('--app-font-scale', scale.toFixed(2));
    document.body.classList.toggle('app-font-scaled', scale > 1);
});

function editTreatment(t) {
    document.getElementById('edit_t_id').value = t.id;
    document.getElementById('edit_t_name').value = t.treatment_name;
    document.getElementById('edit_t_name_en').value = t.treatment_name_en || '';
    document.getElementById('edit_t_category').value = t.category;
    document.getElementById('edit_t_scope').value = t.scope || 'both';
    document.getElementById('edit_t_price').value = t.default_price;
    document.getElementById('edit_t_desc').value = t.description || '';
    document.getElementById('edit_t_active').checked = t.is_active == 1;
    openDialog('editTreatmentDialog');
}

function previewLogo(input) {
    const previewDiv = document.getElementById('logoPreview');

    if (input.files && input.files[0]) {
        const reader = new FileReader();

        reader.onload = function(e) {
            if (previewDiv) {
                previewDiv.innerHTML = '<p style="font-size: 10pt; color: #666; margin-bottom: 10px;">پێشبینینی لۆگۆی نوێ:</p>' +
                    '<img src="' + e.target.result + '" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; padding: 10px; border-radius: 8px;">';
            } else {
                const newPreview = document.createElement('div');
                newPreview.id = 'logoPreview';
                newPreview.style.marginTop = '15px';
                newPreview.style.textAlign = 'center';
                newPreview.innerHTML = '<p style="font-size: 10pt; color: #666; margin-bottom: 10px;">پێشبینینی لۆگۆی نوێ:</p>' +
                    '<img src="' + e.target.result + '" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; padding: 10px; border-radius: 8px;">';
                input.parentElement.appendChild(newPreview);
            }
        };

        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
