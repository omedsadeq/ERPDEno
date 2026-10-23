<?php
/**
 * Edit Patient Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

// Require login BEFORE processing the POST (this runs before header.php).
$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();

$patientId = intval($_GET['id'] ?? 0);
if (!$patientId) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}
require_once __DIR__ . '/../classes/Patient.php';

$patientManager = new Patient();
$patient = $patientManager->getById($patientId);

if (!$patient) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

// Doctor isolation: a doctor may only view/edit the demographics of a patient
// they own or that reception referred to them. Admin/receptionist are unaffected.
// (This runs on BOTH the initial GET and the POST update — no bypass by id.)
AccessGuard::requirePatientGeneral($patientId, false);

global $MEDICAL_CONDITIONS;
$medicalConditions = $patient['medical_conditions'] ?? [];
$conditionTypes = array_column($medicalConditions, 'condition_type');

$errors = [];

// Handle form submission BEFORE including header
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? '';

    if (empty($fullName)) $errors['full_name'] = 'ناو پێویستە';
    if (empty($phone)) $errors['phone'] = 'ژمارەی مۆبایل پێویستە';
    if (empty($gender)) $errors['gender'] = 'ڕەگەز پێویستە';

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
            'notes' => trim($_POST['notes'] ?? '')
        ];

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

        $result = $patientManager->update($patientId, $data);

        if ($result['success']) {
            header('Location: ' . APP_URL . '/patients/index.php?updated=1');
            exit;
        } else {
            $errors['general'] = $result['message'];
        }
    }
}

$pageTitle = 'دەستکاری: ' . $patient['full_name'];
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $patient['full_name'], 'url' => APP_URL . '/patients/view.php?id=' . $patientId],
    ['title' => 'دەستکاری']
];

// Now include header (after any potential redirects)
require_once __DIR__ . '/../includes/header.php';

// Prepare condition details for display
$conditionDetails = [];
$conditionSeverity = [];
foreach ($medicalConditions as $cond) {
    $conditionDetails[$cond['condition_type']] = $cond['condition_details'] ?? '';
    $conditionSeverity[$cond['condition_type']] = $cond['severity'] ?? 'medium';
}
?>

<style>
/* Compact Form Styling */
.form-section {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
    border: 1px solid #e8e8e8;
}
.form-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f0f0f0;
}
.form-section-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    background: linear-gradient(135deg, var(--primary-color), var(--win-accent-deep, #0063b1));
    color: white;
    box-shadow: 0 2px 6px rgba(var(--win-accent-rgb, 0, 120, 212), 0.2);
}
.form-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0;
}

/* Colorful Field Labels */
.form-label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.form-label i {
    font-size: 14px;
}
.form-label.label-blue { color: var(--win-accent, #0078d4); }
.form-label.label-green { color: #107c10; }
.form-label.label-purple { color: #8764b8; }
.form-label.label-orange { color: #ff8c00; }
.form-label.label-teal { color: #00897b; }
.form-label.label-pink { color: #e81e63; }
.form-label.label-indigo { color: #5e35b1; }

/* Enhanced Input Groups */
.input-with-icon {
    position: relative;
}
.input-with-icon .input-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9e9e9e;
    font-size: 14px;
    pointer-events: none;
}
.input-with-icon .form-control {
    padding-right: 38px;
}
.input-with-icon .form-control:focus ~ .input-icon {
    color: var(--primary-color);
}

/* Form Field Hints */
.field-hint {
    font-size: 11px;
    color: #757575;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.field-hint i {
    font-size: 10px;
}

/* Medical Conditions Cards - Compact */
.medical-conditions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.condition-card {
    background: white;
    border: 2px solid #e8e8e8;
    border-radius: 8px;
    padding: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
}
.condition-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(var(--win-accent-rgb, 0, 120, 212), 0.12);
    transform: translateY(-1px);
}
.condition-card.active {
    border-color: var(--primary-color);
    background: linear-gradient(to bottom, #e3f2fd, white);
    box-shadow: 0 2px 10px rgba(var(--win-accent-rgb, 0, 120, 212), 0.18);
}
.condition-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.condition-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.condition-label {
    font-size: 13px;
    font-weight: 600;
    color: #2c3e50;
    cursor: pointer;
    flex: 1;
}
.condition-details {
    padding-top: 8px;
    border-top: 1px solid #e8e8e8;
    animation: slideDown 0.2s ease;
}
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Compact Buttons */
.action-buttons {
    display: flex;
    gap: 10px;
    padding-top: 20px;
    border-top: 2px solid #f0f0f0;
}
.btn-save {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    padding: 11px 28px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.25);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
}
.btn-cancel {
    background: white;
    color: #607d8b;
    padding: 11px 28px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    border: 2px solid #e0e0e0;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.btn-cancel:hover {
    border-color: #bdbdbd;
    background: #f5f5f5;
}
</style>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-error" style="margin-bottom: 16px;">
    <i class="fas fa-exclamation-circle alert-icon"></i>
    <div class="alert-content"><?= htmlspecialchars($errors['general']) ?></div>
</div>
<?php endif; ?>

<form method="POST" action="" id="patientForm">
    <!-- Basic Information Section -->
    <div class="form-section">
        <div class="form-section-header">
            <div class="form-section-icon">
                <i class="fas fa-user"></i>
            </div>
            <h3 class="form-section-title">زانیاری سەرەکی</h3>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label label-blue required">
                    <i class="fas fa-id-card"></i>
                    ناوی تەواو
                </label>
                <div class="input-with-icon">
                    <input type="text" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'error' : '' ?>"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? $patient['full_name']) ?>" required placeholder="بۆ نموونە: ئەحمەد محەمەد عەلی">
                    <i class="fas fa-user input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> ناوی سیانی و ناوی باوک و باپیرە</div>
                <?php if (isset($errors['full_name'])): ?>
                <div class="form-error"><?= $errors['full_name'] ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label label-purple required">
                    <i class="fas fa-venus-mars"></i>
                    ڕەگەز
                </label>
                <div class="input-with-icon">
                    <select name="gender" class="form-control <?= isset($errors['gender']) ? 'error' : '' ?>" required>
                        <option value="">هەڵبژێرە</option>
                        <option value="male" <?= ($_POST['gender'] ?? $patient['gender']) === 'male' ? 'selected' : '' ?>>نێر</option>
                        <option value="female" <?= ($_POST['gender'] ?? $patient['gender']) === 'female' ? 'selected' : '' ?>>مێ</option>
                    </select>
                    <i class="fas fa-venus-mars input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> جیاوازی چارەسەری بەپێی ڕەگەز</div>
                <?php if (isset($errors['gender'])): ?>
                <div class="form-error"><?= $errors['gender'] ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label label-orange">
                    <i class="fas fa-birthday-cake"></i>
                    بەرواری لەدایکبوون
                </label>
                <div class="input-with-icon">
                    <input type="date" name="date_of_birth" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_of_birth'] ?? $patient['date_of_birth'] ?? '') ?>">
                    <i class="fas fa-calendar-alt input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> بۆ ژمارەکردنی تەمەنی نەخۆش</div>
            </div>
        </div>
    </div>

    <!-- Contact Information Section -->
    <div class="form-section">
        <div class="form-section-header">
            <div class="form-section-icon">
                <i class="fas fa-phone"></i>
            </div>
            <h3 class="form-section-title">زانیاری پەیوەندی</h3>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label label-green required">
                    <i class="fas fa-mobile-alt"></i>
                    ژمارەی مۆبایل
                </label>
                <div class="input-with-icon">
                    <input type="tel" name="phone" class="form-control <?= isset($errors['phone']) ? 'error' : '' ?>"
                           value="<?= htmlspecialchars($_POST['phone'] ?? $patient['phone']) ?>" required placeholder="بۆ نموونە: 0750-123-4567">
                    <i class="fas fa-phone input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> ژمارە بۆ پەیوەندی و یادەوەری چاوپێکەوتنەکان</div>
                <?php if (isset($errors['phone'])): ?>
                <div class="form-error"><?= $errors['phone'] ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label label-teal">
                    <i class="fas fa-phone-alt"></i>
                    ژمارەی دووەم
                </label>
                <div class="input-with-icon">
                    <input type="tel" name="phone2" class="form-control"
                           value="<?= htmlspecialchars($_POST['phone2'] ?? $patient['phone2'] ?? '') ?>" placeholder="بۆ نموونە: 0770-987-6543">
                    <i class="fas fa-phone-square input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> ژمارەی خێزان یان هاوکار (ئارەزوومەندانە)</div>
            </div>

            <div class="form-group">
                <label class="form-label label-pink">
                    <i class="fas fa-tint"></i>
                    جۆری خوێن
                </label>
                <div class="input-with-icon">
                    <select name="blood_type" class="form-control">
                        <option value="">نەزانراو</option>
                        <option value="A+" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'A+' ? 'selected' : '' ?>>A+</option>
                        <option value="A-" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'A-' ? 'selected' : '' ?>>A-</option>
                        <option value="B+" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'B+' ? 'selected' : '' ?>>B+</option>
                        <option value="B-" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'B-' ? 'selected' : '' ?>>B-</option>
                        <option value="AB+" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'AB+' ? 'selected' : '' ?>>AB+</option>
                        <option value="AB-" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'AB-' ? 'selected' : '' ?>>AB-</option>
                        <option value="O+" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'O+' ? 'selected' : '' ?>>O+</option>
                        <option value="O-" <?= ($_POST['blood_type'] ?? $patient['blood_type']) === 'O-' ? 'selected' : '' ?>>O-</option>
                    </select>
                    <i class="fas fa-tint input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> گرنگە بۆ کاتی نەخۆشی یان نەشتەرگەری</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label label-indigo">
                    <i class="fas fa-city"></i>
                    شار
                </label>
                <div class="input-with-icon">
                    <input type="text" name="city" class="form-control"
                           value="<?= htmlspecialchars($_POST['city'] ?? $patient['city'] ?? '') ?>" placeholder="بۆ نموونە: هەولێر">
                    <i class="fas fa-map-marker-alt input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> شاری نیشتەجێبوون</div>
            </div>

            <div class="form-group">
                <label class="form-label label-orange">
                    <i class="fas fa-briefcase"></i>
                    پیشە
                </label>
                <div class="input-with-icon">
                    <input type="text" name="occupation" class="form-control"
                           value="<?= htmlspecialchars($_POST['occupation'] ?? $patient['occupation'] ?? '') ?>" placeholder="بۆ نموونە: مامۆستا، دکتۆر">
                    <i class="fas fa-user-tie input-icon"></i>
                </div>
                <div class="field-hint"><i class="fas fa-info-circle"></i> کاری نەخۆش (ئارەزوومەندانە)</div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label label-blue">
                <i class="fas fa-map-marked-alt"></i>
                ناونیشانی تەواو
            </label>
            <textarea name="address" class="form-control" rows="2" placeholder="بۆ نموونە: گەڕەکی ١٠٠ مەتری، نزیک قوتابخانەی..."><?= htmlspecialchars($_POST['address'] ?? $patient['address'] ?? '') ?></textarea>
            <div class="field-hint"><i class="fas fa-info-circle"></i> ناونیشانی وردی ماڵەوە (گەڕەک، نزیکترین شوێن، هتد)</div>
        </div>
    </div>

    <!-- Medical Conditions Section -->
    <div class="form-section">
        <div class="form-section-header">
            <div class="form-section-icon" style="background: linear-gradient(135deg, #e91e63, #c2185b);">
                <i class="fas fa-heartbeat"></i>
            </div>
            <h3 class="form-section-title">ئاگادارکردنەوەی پزیشکی</h3>
        </div>

        <div class="alert alert-warning" style="margin-bottom: 16px;">
            <i class="fas fa-exclamation-triangle alert-icon"></i>
            <div class="alert-content">
                ئەگەر نەخۆشەکە کێشەی تەندروستی هەیە، دیاری بکە بۆ سەلامەتی چارەسەرەکان
            </div>
        </div>

        <div class="medical-conditions-grid">
            <?php foreach ($MEDICAL_CONDITIONS as $key => $label): ?>
            <div class="condition-card" id="card_<?= $key ?>" onclick="toggleConditionCard('<?= $key ?>')">
                <div class="condition-card-header">
                    <input type="checkbox" name="medical_conditions[]" value="<?= $key ?>"
                           id="condition_<?= $key ?>" class="condition-checkbox"
                           onclick="event.stopPropagation(); toggleConditionDetails('<?= $key ?>')"
                           <?= in_array($key, $_POST['medical_conditions'] ?? $conditionTypes) ? 'checked' : '' ?>>
                    <label class="condition-label" for="condition_<?= $key ?>"><?= $label ?></label>
                </div>
                <div id="details_<?= $key ?>" class="condition-details" style="display: <?= in_array($key, $_POST['medical_conditions'] ?? $conditionTypes) ? 'block' : 'none' ?>;" onclick="event.stopPropagation()">
                    <input type="text" name="condition_details[<?= $key ?>]" class="form-control"
                           placeholder="وردەکاری (بۆ نموونە: دوای نانخواردن، ٢ جار لە ڕۆژدا)" value="<?= htmlspecialchars($_POST['condition_details'][$key] ?? $conditionDetails[$key] ?? '') ?>"
                           style="font-size: 12px; padding: 7px 10px; margin-bottom: 6px;">
                    <select name="condition_severity[<?= $key ?>]" class="form-control" style="font-size: 12px; padding: 7px 10px;">
                        <option value="low" <?= ($_POST['condition_severity'][$key] ?? $conditionSeverity[$key] ?? '') === 'low' ? 'selected' : '' ?>>کەم</option>
                        <option value="medium" <?= ($_POST['condition_severity'][$key] ?? $conditionSeverity[$key] ?? 'medium') === 'medium' ? 'selected' : '' ?>>مامناوەند</option>
                        <option value="high" <?= ($_POST['condition_severity'][$key] ?? $conditionSeverity[$key] ?? '') === 'high' ? 'selected' : '' ?>>زۆر</option>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Additional Notes Section -->
    <div class="form-section">
        <div class="form-section-header">
            <div class="form-section-icon" style="background: linear-gradient(135deg, #ff9800, #f57c00);">
                <i class="fas fa-sticky-note"></i>
            </div>
            <h3 class="form-section-title">تێبینی زیاتر</h3>
        </div>

        <div class="form-group">
            <label class="form-label label-orange">
                <i class="fas fa-comment-medical"></i>
                تێبینی گشتی
            </label>
            <textarea name="notes" class="form-control" rows="3" placeholder="بۆ نموونە: نەخۆش تۆڕەیەکی لە ددانی ژوورەوە، پێویستی بە چارەسەری تایبەتە، ترس لە ددان هەیە، هتد..."><?= htmlspecialchars($_POST['notes'] ?? $patient['notes'] ?? '') ?></textarea>
            <div class="field-hint"><i class="fas fa-info-circle"></i> هەر زانیاری گرنگی تر دەربارەی نەخۆش (ترس، ئارەزوو، تایبەتمەندی، هتد)</div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button type="submit" class="btn-save">
            <i class="fas fa-save"></i>
            پاشەکەوتکردن
        </button>
        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patientId ?>" class="btn-cancel">
            <i class="fas fa-times"></i>
            هەڵوەشاندنەوە
        </a>
    </div>
</form>

<script>
function toggleConditionDetails(key) {
    const checkbox = document.getElementById('condition_' + key);
    const details = document.getElementById('details_' + key);
    const card = document.getElementById('card_' + key);

    if (checkbox.checked) {
        details.style.display = 'block';
        card.classList.add('active');
    } else {
        details.style.display = 'none';
        card.classList.remove('active');
    }
}

function toggleConditionCard(key) {
    const checkbox = document.getElementById('condition_' + key);
    checkbox.checked = !checkbox.checked;
    toggleConditionDetails(key);
}

// Initialize active cards on page load
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.condition-checkbox');
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            const key = checkbox.value;
            document.getElementById('card_' + key).classList.add('active');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
