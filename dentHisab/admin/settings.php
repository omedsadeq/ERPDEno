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

        foreach ($_POST['settings'] as $key => $value) {
            $existing = $db->fetch("SELECT id FROM settings WHERE setting_key = :key", ['key' => $key]);
            if ($existing) {
                $db->update('settings', ['setting_value' => $value], "setting_key = :key", ['key' => $key]);
            } else {
                $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
        }
        $returnTab = preg_replace('/[^a-z_]/', '', $_POST['return_tab'] ?? 'general') ?: 'general';
        header('Location: ' . APP_URL . '/admin/settings.php?tab=' . urlencode($returnTab) . '&saved=1');
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

@media (max-width: 768px) {
    .tabs-settings {
        flex-direction: column;
    }
}
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> ڕێکخستنەکان پاشەکەوتکران</div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر زیادکرا</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر نوێکرایەوە</div>
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
    </div>

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
                            <button class="btn btn-sm btn-secondary" onclick="editTreatment(<?= htmlspecialchars(json_encode($t)) ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
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
