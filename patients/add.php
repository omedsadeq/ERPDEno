<?php
/**
 * Add Patient Page
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Form data + style mirror the "زانیاری تایبەتی نەخۆش" card of new_visit.php
 * (same fields, same compact form-card grid, same medical-condition cards,
 * searchable city combo and profile photo).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';

// Require login BEFORE processing the POST (this block runs before header.php,
// which is where auth used to be set — so previously the handler ran
// unauthenticated and registered_by was always NULL).
$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();

global $MEDICAL_CONDITIONS;

$patientManager = new Patient();
$errors = [];
$success = false;
$embeddedMode = isset($_GET['embedded']) && $_GET['embedded'] === '1';
$seedValue = trim($_GET['seed'] ?? '');
$prefillName = '';
$prefillPhone = '';

if ($seedValue !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (preg_match('/^[0-9+\-\s()]+$/u', $seedValue)) {
        $prefillPhone = $seedValue;
    } else {
        $prefillName = $seedValue;
    }
}

/**
 * Save + shrink the optional profile photo (same rules as new_visit.php:
 * jpg/png/gif, resized down to max 1200px). Returns the relative path or null.
 */
function save_patient_profile_image(array $file) {
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        return null;
    }
    $dir = APP_ROOT . '/uploads/patients/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $name = uniqid('profile_') . '.' . $ext;
    $dest = $dir . $name;

    $saved = false;
    $info  = @getimagesize($file['tmp_name']);
    if ($info) {
        $img = null;
        switch ($info['mime']) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($file['tmp_name']); break;
            case 'image/png':  $img = @imagecreatefrompng($file['tmp_name']);  break;
            case 'image/gif':  $img = @imagecreatefromgif($file['tmp_name']);  break;
        }
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            $max = 1200;
            if ($w > $max || $h > $max) {
                $nw = $w > $h ? $max : (int)floor($w * ($max / $h));
                $nh = $w > $h ? (int)floor($h * ($max / $w)) : $max;
            } else {
                $nw = $w;
                $nh = $h;
            }
            $out = imagecreatetruecolor($nw, $nh);
            if ($info['mime'] !== 'image/jpeg') {
                imagealphablending($out, false);
                imagesavealpha($out, true);
                imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
            }
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            switch ($info['mime']) {
                case 'image/jpeg': $saved = imagejpeg($out, $dest, 80); break;
                case 'image/png':  $saved = imagepng($out, $dest, 6);   break;
                case 'image/gif':  $saved = imagegif($out, $dest);      break;
            }
            imagedestroy($out);
            imagedestroy($img);
        }
    }
    if (!$saved && !move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }
    return 'uploads/patients/' . $name;
}

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    // Validate
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? '';

    if (empty($fullName)) {
        $errors['full_name'] = 'ناو پێویستە';
    }

    if (empty($phone)) {
        $errors['phone'] = 'ژمارەی مۆبایل پێویستە';
    }

    if (empty($gender)) {
        $errors['gender'] = 'ڕەگەز پێویستە';
    }

    if (empty($errors)) {
        $data = [
            'full_name' => $fullName,
            'gender' => $gender,
            'phone' => $phone,
            'phone2' => trim($_POST['phone2'] ?? ''),
            'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
            'address' => trim($_POST['address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'occupation' => trim($_POST['occupation'] ?? ''),
            'blood_type' => $_POST['blood_type'] ?? null,
            'notes' => trim($_POST['notes'] ?? ''),
            'registered_by' => $currentUser['id']
        ];

        // Optional profile photo
        if (isset($_FILES['profile_image']) && ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $profileImage = save_patient_profile_image($_FILES['profile_image']);
            if ($profileImage !== null) {
                $data['profile_image'] = $profileImage;
            }
        }

        // Medical conditions
        $conditions = [];
        if (!empty($_POST['medical_conditions'])) {
            foreach ($_POST['medical_conditions'] as $condType) {
                $conditions[] = [
                    'type' => $condType,
                    'details' => $_POST['condition_details'][$condType] ?? '',
                    'severity' => $_POST['condition_severity'][$condType] ?? 'medium'
                ];
            }
        }
        $data['medical_conditions'] = $conditions;

        $result = $patientManager->create($data);

        if ($result['success']) {
            if ($embeddedMode) {
                $patientPayload = [
                    'id' => $result['patient_id'],
                    'patient_code' => $result['patient_code'] ?? '',
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'gender' => $gender
                ];
                ?>
                <!DOCTYPE html>
                <html lang="ku" dir="rtl">
                <head>
                    <meta charset="UTF-8">
                    <title>Patient Created</title>
                </head>
                <body>
                <script>
                window.parent.postMessage({
                    type: 'patient-created',
                    patient: <?= json_encode($patientPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
                }, window.location.origin);
                </script>
                </body>
                </html>
                <?php
                exit;
            }

            header('Location: ' . APP_URL . '/patients/view.php?id=' . $result['patient_id'] . '&new=1');
            exit;
        } else {
            $errors['general'] = $result['message'];
        }
    }
}

// City list for the searchable combo (grouped by province, like new_visit.php)
$cities_grouped = [];
try {
    $cityRows = Database::getInstance()->fetchAll(
        "SELECT name, province FROM cities WHERE is_active = 1 ORDER BY sort_order, id"
    );
    foreach ($cityRows as $cityRow) {
        $provinceKey = ($cityRow['province'] ?? '') !== '' ? $cityRow['province'] : 'شارە تر';
        $cities_grouped[$provinceKey][] = $cityRow['name'];
    }
} catch (Exception $e) {
    // cities table missing — the combo still accepts free text
}

// Set page variables AFTER form processing
$pageTitle = 'زیادکردنی نەخۆش';
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => 'زیادکردنی نەخۆش']
];

if ($embeddedMode) {
    $customCSS = ($customCSS ?? '') . '
        html,
        body {
            height: 100% !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        .titlebar,
        .nav-pane,
        .mobile-header,
        .page-header,
        .statusbar,
        .notification-dropdown,
        .mobile-nav-overlay {
            display: none !important;
        }
        .app-window,
        .app-container,
        .content-area,
        .page-content {
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
        }
        .app-window {
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        .app-container {
            display: block !important;
        }
        .content-area {
            width: 100% !important;
        }
        .page-content {
            padding: 14px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        body {
            background: #f3f6fb !important;
        }
    ';
}

// Now include header (after any potential redirects)
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Compact form card (same look as new_visit.php) ── */
.form-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 12px;
    overflow: visible;
}

.form-card-header {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #fafafa, #f5f5f5);
    border-radius: 10px 10px 0 0;
}

.form-card-header .card-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: white;
}

.form-card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #333;
}

.form-card-body {
    padding: 14px 16px;
}

.icon-blue { background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-deep, #0063b1)); }

.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

@media (max-width: 1100px) {
    .form-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 800px) {
    .form-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 500px) {
    .form-grid { grid-template-columns: 1fr; }
}

.form-grid .form-group {
    margin-bottom: 0;
}

.form-group.span-2 { grid-column: span 2; }

.form-grid .form-group label,
.conditions-block > label {
    display: block;
    font-size: 11px;
    color: #000;
    font-weight: 600;
    margin-bottom: 4px;
}

.form-group label .required {
    color: #f44336;
    margin-right: 2px;
}

.form-grid .form-group input,
.form-grid .form-group select,
.form-grid .form-group textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}

.form-grid .form-group input:focus,
.form-grid .form-group select:focus,
.form-grid .form-group textarea:focus {
    border-color: #4caf50;
    outline: none;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.form-grid .form-group input.error,
.form-grid .form-group select.error {
    border-color: #f44336;
}

.form-error {
    color: #f44336;
    font-size: 11px;
    margin-top: 3px;
}

/* ── Medical conditions (compact cards) ── */
.conditions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
}

.condition-card {
    background: #fafafa;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    padding: 8px 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.condition-card:hover {
    border-color: #e91e63;
    background: #fce4ec;
}

.condition-card.active {
    border-color: #e91e63;
    background: #fce4ec;
}

.condition-header {
    display: flex;
    align-items: center;
    gap: 6px;
}

.condition-header input {
    width: 14px;
    height: 14px;
    accent-color: #e91e63;
}

.condition-header label {
    font-weight: 600;
    font-size: 11px;
    cursor: pointer;
    margin-bottom: 0 !important;
}

.condition-details {
    margin-top: 8px;
    display: none;
}

.condition-card.active .condition-details {
    display: block;
}

.condition-details input,
.condition-details select {
    width: 100%;
    padding: 5px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 11px;
    margin-bottom: 4px;
}

/* ── City searchable combo ── */
.city-combo { position: relative; flex: 1; }
.city-combo input[type="text"] { width: 100%; }
.city-combo-dropdown {
    display: none; position: absolute; top: 100%; right: 0; left: 0; z-index: 300;
    background: #fff; border: 1px solid #cfd8dc; border-radius: 6px; margin-top: 2px;
    max-height: 240px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.city-combo-group {
    padding: 6px 10px; background: #f7f9fc; color: #607d8b;
    font-size: 11px; font-weight: 700; position: sticky; top: 0;
}
.city-combo-item { padding: 8px 14px; cursor: pointer; font-size: 13px; color: #37474f; }
.city-combo-item:hover { background: #e3f2fd; color: var(--win-accent, #0078d4); }
.city-combo-empty { padding: 12px 14px; color: #9aa7b3; font-size: 12px; text-align: center; }

/* ── Profile photo preview ── */
.profile-preview {
    margin-top: 6px;
}
.profile-preview img {
    max-height: 70px;
    border-radius: 8px;
    border: 1.5px solid #e0e0e0;
}

/* ── Actions ── */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding: 16px;
    background: white;
    border-radius: 10px;
    margin-top: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.form-actions .btn {
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.form-actions .btn-primary {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    box-shadow: 0 3px 10px rgba(76, 175, 80, 0.3);
}

.form-actions .btn-primary:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
}

.form-actions .btn-secondary {
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
}

.form-actions .btn-secondary:hover {
    background: #e0e0e0;
}

/* ── Add-city dialog (compact copy of new_visit.php's dialog) ── */
#addCityDialog {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,.45); align-items: center; justify-content: center;
}
#addCityDialog.open { display: flex; }
#addCityDialog .city-dialog {
    background: #fff; border-radius: 10px; width: min(400px, 92vw);
    box-shadow: 0 12px 40px rgba(0,0,0,.25); overflow: hidden;
}
#addCityDialog .city-dialog-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);
    color: #fff; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between;
}
#addCityDialog .city-dialog-header h4 { margin: 0; font-size: 14px; display: flex; align-items: center; gap: 8px; }
#addCityDialog .city-dialog-close {
    background: none; border: none; color: #fff; cursor: pointer; font-size: 14px; padding: 4px;
}
#addCityDialog .city-dialog-body { padding: 14px 16px; }
#addCityDialog .city-dialog-body label {
    display: block; font-size: 11px; font-weight: 600; color: #000; margin-bottom: 4px;
}
#addCityDialog .city-dialog-body input,
#addCityDialog .city-dialog-body select {
    width: 100%; padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px;
    font-size: 13px; font-family: inherit; background: #fff;
}
#addCityDialog .city-dialog-footer {
    padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0;
    display: flex; gap: 8px; flex-direction: row-reverse;
}
#addCityDialog .city-btn-primary,
#addCityDialog .city-btn-secondary {
    padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px;
}
#addCityDialog .city-btn-primary { background: var(--win-accent, #0078d4); color: #fff; }
#addCityDialog .city-btn-secondary { background: #eceff1; color: #546e7a; }
</style>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-error" style="margin-bottom: 16px;">
    <i class="fas fa-exclamation-circle alert-icon"></i>
    <div class="alert-content"><?= htmlspecialchars($errors['general']) ?></div>
</div>
<?php endif; ?>

<form method="POST" action="" id="patientForm" enctype="multipart/form-data">
    <!-- Patient Personal Info Card (same as new_visit.php: زانیاری تایبەتی نەخۆش) -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-blue"><i class="fas fa-id-card"></i></div>
            <h3>زانیاری تایبەتی نەخۆش</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> ناوی تەواو</label>
                    <input type="text" id="full_name" name="full_name" class="<?= isset($errors['full_name']) ? 'error' : '' ?>"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? $prefillName) ?>" required placeholder="ناوی سیانی" autocomplete="off">
                    <?php if (isset($errors['full_name'])): ?>
                    <div class="form-error"><?= $errors['full_name'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> ڕەگەز</label>
                    <select id="gender" name="gender" class="<?= isset($errors['gender']) ? 'error' : '' ?>" required>
                        <option value="">هەڵبژاردن</option>
                        <option value="male" <?= ($_POST['gender'] ?? '') === 'male' ? 'selected' : '' ?>>نێر</option>
                        <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>مێ</option>
                    </select>
                    <?php if (isset($errors['gender'])): ?>
                    <div class="form-error"><?= $errors['gender'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>بەرواری لەدایکبوون</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>جۆری خوێن</label>
                    <select id="blood_type" name="blood_type">
                        <option value="">دیاری نەکراو</option>
                        <?php
                        $blood_types = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
                        $selected_blood = $_POST['blood_type'] ?? '';
                        foreach ($blood_types as $bt):
                            $selected = ($selected_blood === $bt) ? 'selected' : '';
                            echo "<option value=\"{$bt}\" {$selected}>{$bt}</option>";
                        endforeach;
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> ژمارەی مۆبایل</label>
                    <input type="tel" id="phone" name="phone" class="<?= isset($errors['phone']) ? 'error' : '' ?>"
                           value="<?= htmlspecialchars($_POST['phone'] ?? $prefillPhone) ?>" required placeholder="0750-123-4567">
                    <?php if (isset($errors['phone'])): ?>
                    <div class="form-error"><?= $errors['phone'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>ژمارەی دووەم</label>
                    <input type="tel" id="phone2" name="phone2" value="<?= htmlspecialchars($_POST['phone2'] ?? '') ?>" placeholder="0770-987-6543">
                </div>
                <div class="form-group">
                    <label>شار</label>
                    <?php $selected_city = $_POST['city'] ?? ''; ?>
                    <div style="display: flex; gap: 4px;">
                        <div class="city-combo">
                            <input type="text" id="cityComboInput" placeholder="گەڕان بۆ شار..." autocomplete="off"
                                   value="<?= htmlspecialchars($selected_city) ?>"
                                   oninput="cityComboFilter()" onfocus="cityComboOpen()">
                            <input type="hidden" id="city" name="city" value="<?= htmlspecialchars($selected_city) ?>">
                            <div id="cityComboDropdown" class="city-combo-dropdown"></div>
                        </div>
                        <button type="button" onclick="openAddCityDialog()" title="زیادکردنی شاری نوێ"
                                style="padding: 0 10px; background: var(--win-accent, #0078d4); color: #fff; border: 1px solid var(--win-accent, #0078d4); border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>پیشە</label>
                    <input type="text" id="occupation" name="occupation" value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>" placeholder="کارمەند">
                </div>
                <div class="form-group span-2">
                    <label>ناونیشان</label>
                    <input type="text" id="address" name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" placeholder="ناونیشانی وردی ماڵەوە...">
                </div>
                <div class="form-group span-2">
                    <label>وێنەی نەخۆش</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <div id="profile_image_preview" class="profile-preview" style="display:none;">
                        <img id="profile_image_preview_img" src="" alt="">
                    </div>
                </div>
            </div>

            <div style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed #e0e0e0;" class="conditions-block">
                <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#37474f; font-weight:600; margin-bottom:8px;">
                    <i class="fas fa-heartbeat" style="color:#e91e63;"></i> ئاگاداری تەندروستی
                </label>
                <div class="conditions-grid">
                    <?php foreach ($MEDICAL_CONDITIONS as $key => $label): ?>
                        <div class="condition-card <?= in_array($key, $_POST['medical_conditions'] ?? []) ? 'active' : '' ?>" id="card_<?= $key ?>" onclick="toggleConditionCard('<?= $key ?>')">
                            <div class="condition-header">
                                <input type="checkbox" name="medical_conditions[]" value="<?= $key ?>"
                                       id="condition_<?= $key ?>" class="condition-checkbox"
                                       onclick="event.stopPropagation(); toggleConditionDetails('<?= $key ?>')"
                                       <?= in_array($key, $_POST['medical_conditions'] ?? []) ? 'checked' : '' ?>>
                                <label for="condition_<?= $key ?>"><?= $label ?></label>
                            </div>
                            <div id="details_<?= $key ?>" class="condition-details" onclick="event.stopPropagation()">
                                <input type="text" name="condition_details[<?= $key ?>]" placeholder="وردەکاری..." value="<?= htmlspecialchars($_POST['condition_details'][$key] ?? '') ?>">
                                <select name="condition_severity[<?= $key ?>]">
                                    <option value="low" <?= ($_POST['condition_severity'][$key] ?? '') === 'low' ? 'selected' : '' ?>>کەم</option>
                                    <option value="medium" <?= ($_POST['condition_severity'][$key] ?? 'medium') === 'medium' ? 'selected' : '' ?>>مامناوەند</option>
                                    <option value="high" <?= ($_POST['condition_severity'][$key] ?? '') === 'high' ? 'selected' : '' ?>>زۆر</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i>
            پاشەکەوتکردن
        </button>
        <?php if (!$embeddedMode): ?>
        <a href="<?= APP_URL ?>/patients/index.php" class="btn btn-secondary">
            <i class="fas fa-times"></i>
            هەڵوەشاندنەوە
        </a>
        <?php endif; ?>
    </div>
</form>

<!-- Add City Dialog -->
<div id="addCityDialog">
    <div class="city-dialog">
        <div class="city-dialog-header">
            <h4><i class="fas fa-map-marker-alt"></i> زیادکردنی شاری نوێ</h4>
            <button type="button" class="city-dialog-close" onclick="closeAddCityDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="city-dialog-body">
            <div>
                <label><i class="fas fa-city"></i> ناوی شار <span class="required" style="color:#f44336;">*</span></label>
                <input type="text" id="newCityName" placeholder="بۆ نموونە: کۆیە">
            </div>
            <div style="margin-top:10px;">
                <label><i class="fas fa-map"></i> پارێزگا</label>
                <select id="newCityProvince">
                    <option value="">— هەڵبژێرە —</option>
                    <option value="پارێزگای سلێمانی">پارێزگای سلێمانی</option>
                    <option value="پارێزگای هەولێر">پارێزگای هەولێر</option>
                    <option value="پارێزگای دهۆک">پارێزگای دهۆک</option>
                    <option value="شارە تر">شارە تر</option>
                </select>
            </div>
            <div id="addCityErr" style="color:#f44336;font-size:12px;margin-top:6px;display:none;"></div>
        </div>
        <div class="city-dialog-footer">
            <button type="button" class="city-btn-primary" onclick="submitNewCity()"><i class="fas fa-check"></i> زیادکردن</button>
            <button type="button" class="city-btn-secondary" onclick="closeAddCityDialog()"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
        </div>
    </div>
</div>

<script>
// ── Medical condition cards ──
function toggleConditionDetails(key) {
    const checkbox = document.getElementById('condition_' + key);
    const card = document.getElementById('card_' + key);
    card.classList.toggle('active', checkbox.checked);
}

function toggleConditionCard(key) {
    const checkbox = document.getElementById('condition_' + key);
    checkbox.checked = !checkbox.checked;
    toggleConditionDetails(key);
}

// ── Profile photo local preview ──
document.getElementById('profile_image').addEventListener('change', function () {
    const wrap = document.getElementById('profile_image_preview');
    const img = document.getElementById('profile_image_preview_img');
    if (this.files && this.files[0]) {
        img.src = URL.createObjectURL(this.files[0]);
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
});

// ── Add City dialog ──
function openAddCityDialog() {
    var d = document.getElementById('addCityDialog');
    var n = document.getElementById('newCityName');
    var p = document.getElementById('newCityProvince');
    var e = document.getElementById('addCityErr');
    if (n) n.value = '';
    if (p) p.value = '';
    if (e) { e.style.display = 'none'; e.textContent = ''; }
    if (d) d.classList.add('open');
    setTimeout(function() { n && n.focus(); }, 50);
}
function closeAddCityDialog() {
    var d = document.getElementById('addCityDialog');
    if (d) d.classList.remove('open');
}
function submitNewCity() {
    var n = document.getElementById('newCityName');
    var p = document.getElementById('newCityProvince');
    var e = document.getElementById('addCityErr');
    var name = (n.value || '').trim();
    var province = (p.value || '').trim();
    if (!name) { e.textContent = 'تکایە ناوی شار بنووسە'; e.style.display = 'block'; return; }
    fetch('<?= APP_URL ?>/api/cities.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', name: name, province: province })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { e.textContent = d.message || 'هەڵە'; e.style.display = 'block'; return; }
        var groupLabel = (d.province && d.province !== '') ? d.province : 'شارە تر';
        if (!CITY_GROUPS[groupLabel]) CITY_GROUPS[groupLabel] = [];
        if (CITY_GROUPS[groupLabel].indexOf(d.name) === -1) CITY_GROUPS[groupLabel].push(d.name);
        cityComboPick(d.name);
        closeAddCityDialog();
    })
    .catch(function(err) { e.textContent = 'کێشەی پەیوەندی: ' + err.message; e.style.display = 'block'; });
}

// ── City searchable combo ──
var CITY_GROUPS = <?= json_encode($cities_grouped, JSON_UNESCAPED_UNICODE) ?>;

function cityComboRender(filter) {
    var dd = document.getElementById('cityComboDropdown');
    if (!dd) return;
    dd.innerHTML = '';
    var f = String(filter || '').trim().toLowerCase();
    var total = 0;
    Object.keys(CITY_GROUPS).forEach(function(province) {
        var items = (CITY_GROUPS[province] || []).filter(function(c) {
            return !f || String(c).toLowerCase().indexOf(f) !== -1;
        });
        if (!items.length) return;
        var g = document.createElement('div');
        g.className = 'city-combo-group';
        g.textContent = province;
        dd.appendChild(g);
        items.forEach(function(c) {
            total++;
            var it = document.createElement('div');
            it.className = 'city-combo-item';
            it.textContent = c;
            // mousedown (not click) so the input doesn't lose focus first
            it.addEventListener('mousedown', function(ev) { ev.preventDefault(); cityComboPick(c); });
            dd.appendChild(it);
        });
    });
    if (!total) {
        var em = document.createElement('div');
        em.className = 'city-combo-empty';
        em.textContent = 'هیچ شارێک نەدۆزرایەوە — دەتوانیت بە دوگمەی + زیادی بکەیت';
        dd.appendChild(em);
    }
}

function cityComboOpen() {
    var inp = document.getElementById('cityComboInput');
    cityComboRender(inp ? inp.value : '');
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'block';
}

function cityComboFilter() {
    var inp = document.getElementById('cityComboInput');
    var v = inp ? inp.value : '';
    // Free text is allowed too (legacy/custom city values)
    document.getElementById('city').value = v.trim();
    cityComboRender(v);
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'block';
}

function cityComboPick(name) {
    var inp = document.getElementById('cityComboInput');
    if (inp) inp.value = name;
    document.getElementById('city').value = name;
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'none';
}

document.addEventListener('click', function(ev) {
    if (!(ev.target.closest && ev.target.closest('.city-combo'))) {
        var dd = document.getElementById('cityComboDropdown');
        if (dd) dd.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
