<?php
/**
 * Doctor Permissions Management
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Admin Control Panel for:
 * - Toggle doctor permissions (view X-rays, view profiles)
 * - Reassign patients between doctors
 * - View access audit logs
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';

$auth = new Auth();
$auth->requireLogin();

// Admin only
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();

// Handle AJAX requests BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    Csrf::require();

    if ($_POST['action'] === 'update_permission') {
        try {
            $doctorId = intval($_POST['doctor_id']);
            $permission = $_POST['permission'] ?? '';
            $value = intval($_POST['value'] ?? 0);

            // Validate permission field to prevent SQL injection
            if (!in_array($permission, ['can_view_other_xrays', 'can_view_other_profiles'])) {
                echo json_encode(['success' => false, 'message' => 'مۆڵەتی نادروست: ' . $permission]);
                exit;
            }

            // Check if doctor_permissions table exists
            $tableCheck = $db->fetch("SHOW TABLES LIKE 'doctor_permissions'");
            if (!$tableCheck) {
                // Create the table if it doesn't exist
                $db->query("CREATE TABLE IF NOT EXISTS doctor_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    doctor_id INT NOT NULL UNIQUE,
                    can_view_other_xrays TINYINT(1) DEFAULT 1,
                    can_view_other_profiles TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Check if permission record exists
            $existing = $db->fetch("SELECT id FROM doctor_permissions WHERE doctor_id = :doctor_id", ['doctor_id' => $doctorId]);

            if ($existing) {
                $db->query("UPDATE doctor_permissions SET {$permission} = :value, updated_at = NOW() WHERE doctor_id = :doctor_id", [
                    'value' => $value,
                    'doctor_id' => $doctorId
                ]);
            } else {
                $db->insert('doctor_permissions', [
                    'doctor_id' => $doctorId,
                    $permission => $value
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'مۆڵەت نوێکرایەوە']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'هەڵەی داتابەیس: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($_POST['action'] === 'reassign_patient') {
        $patientId = intval($_POST['patient_id']);
        $fromDoctorId = intval($_POST['from_doctor_id']);
        $toDoctorId = intval($_POST['to_doctor_id']);

        if ($fromDoctorId === $toDoctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆری نوێ نابێت هەمان دکتۆری کۆن بێت']);
            exit;
        }

        // Update all folders from old doctor to new doctor
        $db->query("UPDATE patient_folders SET doctor_id = :to_doctor, updated_at = NOW()
                    WHERE patient_id = :patient_id AND doctor_id = :from_doctor", [
            'to_doctor' => $toDoctorId,
            'patient_id' => $patientId,
            'from_doctor' => $fromDoctorId
        ]);

        // Update appointments
        $db->query("UPDATE appointments SET doctor_id = :to_doctor
                    WHERE patient_id = :patient_id AND doctor_id = :from_doctor", [
            'to_doctor' => $toDoctorId,
            'patient_id' => $patientId,
            'from_doctor' => $fromDoctorId
        ]);

        // Log this action
        $db->insert('patient_access_logs', [
            'patient_id' => $patientId,
            'user_id' => $auth->getCurrentUser()['id'],
            'access_type' => 'patient_reassigned',
            'data_accessed' => json_encode(['from_doctor' => $fromDoctorId, 'to_doctor' => $toDoctorId]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'message' => 'نەخۆش گوازرایەوە بۆ دکتۆری نوێ']);
        exit;
    }

    if ($_POST['action'] === 'get_doctor_patients') {
        $doctorId = intval($_POST['doctor_id']);
        $patients = $db->fetchAll("SELECT DISTINCT p.id, p.full_name, p.patient_code, p.phone,
            (SELECT COUNT(*) FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id) as folder_count
            FROM patients p
            INNER JOIN patient_folders pf ON p.id = pf.patient_id
            WHERE pf.doctor_id = :doctor_id2
            ORDER BY p.full_name", [
            'doctor_id' => $doctorId,
            'doctor_id2' => $doctorId
        ]);
        echo json_encode(['success' => true, 'patients' => $patients]);
        exit;
    }

    // Unknown action
    echo json_encode(['success' => false, 'message' => 'کردارێکی نەناسراو']);
    exit;
}

$pageTitle = 'ڕێکخستنی مۆڵەتەکانی دکتۆر';
$breadcrumb = [
    ['title' => 'بەڕێوەبەری', 'url' => APP_URL . '/admin/'],
    ['title' => 'مۆڵەتەکانی دکتۆر']
];

require_once __DIR__ . '/../includes/header.php';

$patientManager = new Patient();

// Get all doctors
$doctors = $db->fetchAll("SELECT u.*,
    (SELECT COUNT(DISTINCT pf.patient_id) FROM patient_folders pf WHERE pf.doctor_id = u.id) as patient_count,
    (SELECT COUNT(*) FROM patient_folders pf WHERE pf.doctor_id = u.id) as folder_count,
    dp.can_view_other_xrays,
    dp.can_view_other_profiles
    FROM users u
    LEFT JOIN doctor_permissions dp ON u.id = dp.doctor_id
    WHERE u.role = 'doctor' AND u.is_active = 1
    ORDER BY u.full_name");

// Get recent access logs
$recentLogs = $db->fetchAll("SELECT pal.*,
    p.full_name as patient_name, p.patient_code,
    u.full_name as user_name, u.role as user_role
    FROM patient_access_logs pal
    LEFT JOIN patients p ON pal.patient_id = p.id
    LEFT JOIN users u ON pal.user_id = u.id
    ORDER BY pal.created_at DESC
    LIMIT 50");

// Get statistics
$totalDoctors = count($doctors);
$totalAccessLogs = $db->fetch("SELECT COUNT(*) as count FROM patient_access_logs")['count'] ?? 0;
$restrictedViews = $db->fetch("SELECT COUNT(*) as count FROM patient_access_logs WHERE access_type LIKE '%restricted%'")['count'] ?? 0;
$todayLogs = $db->fetch("SELECT COUNT(*) as count FROM patient_access_logs WHERE DATE(created_at) = CURDATE()")['count'] ?? 0;
?>

<style>
/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: linear-gradient(135deg, white 0%, #f8f9fa 100%);
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.stat-card h4 {
    font-size: 13px;
    color: #546e7a;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.stat-card .stat-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--primary-color);
}

/* Tabs */
.tabs-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 8px;
}
.tab-btn {
    padding: 10px 20px;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 600;
    color: #546e7a;
    cursor: pointer;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
}
.tab-btn:hover {
    background: #f5f5f5;
}
.tab-btn.active {
    background: var(--primary-color);
    color: white;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* Doctor Cards */
.doctor-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    border: 1px solid #e0e0e0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    display: grid;
    grid-template-columns: 60px 1fr auto;
    gap: 16px;
    align-items: center;
}
.doctor-avatar {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0078d4, #0063b1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
}
.doctor-info h3 {
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 4px 0;
    color: #2c3e50;
}
.doctor-meta {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #546e7a;
}
.doctor-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
}
.doctor-meta-item i {
    color: var(--primary-color);
}
.doctor-permissions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.permission-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: #f5f5f5;
    border-radius: 8px;
    font-size: 12px;
}
.permission-toggle label {
    flex: 1;
    cursor: pointer;
}
.toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    display: inline-block;
}
.toggle-switch input {
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
.toggle-switch input:checked + .toggle-slider {
    background-color: #4caf50;
}
.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(20px);
}

/* Logs Table */
.logs-table {
    width: 100%;
    border-collapse: collapse;
}
.logs-table th,
.logs-table td {
    padding: 12px;
    text-align: right;
    border-bottom: 1px solid #e0e0e0;
}
.logs-table th {
    background: #f5f5f5;
    font-weight: 600;
    font-size: 13px;
    color: #37474f;
}
.logs-table tr:hover {
    background: #fafafa;
}
.access-type-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
.access-type-badge.full {
    background: #e8f5e9;
    color: #2e7d32;
}
.access-type-badge.restricted {
    background: #fff3e0;
    color: #e65100;
}
.access-type-badge.reassigned {
    background: #e3f2fd;
    color: #1565c0;
}

/* Reassign Dialog */
.reassign-form {
    display: grid;
    gap: 16px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-group label {
    font-weight: 600;
    font-size: 13px;
    color: #37474f;
}
.form-group select,
.form-group input {
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
}
.form-group select:focus,
.form-group input:focus {
    border-color: var(--primary-color);
    outline: none;
}

/* Filter */
.log-filters {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.log-filters select,
.log-filters input {
    padding: 8px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
}

/* Responsive */
@media (max-width: 768px) {
    .doctor-card {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .doctor-avatar {
        margin: 0 auto;
    }
    .doctor-meta {
        justify-content: center;
    }
}
</style>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <h4><i class="fas fa-user-md"></i> کۆی دکتۆرەکان</h4>
        <div class="stat-value"><?= number_format($totalDoctors) ?></div>
    </div>
    <div class="stat-card">
        <h4><i class="fas fa-history"></i> کۆی لۆگەکان</h4>
        <div class="stat-value" style="color: #2196f3;"><?= number_format($totalAccessLogs) ?></div>
    </div>
    <div class="stat-card">
        <h4><i class="fas fa-lock"></i> بینینی سنوورمەند</h4>
        <div class="stat-value" style="color: #ff9800;"><?= number_format($restrictedViews) ?></div>
    </div>
    <div class="stat-card">
        <h4><i class="fas fa-calendar-day"></i> لۆگی ئەمڕۆ</h4>
        <div class="stat-value" style="color: #4caf50;"><?= number_format($todayLogs) ?></div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="tabs-nav">
    <button class="tab-btn active" onclick="showTab('permissions')">
        <i class="fas fa-shield-alt"></i> مۆڵەتەکانی دکتۆر
    </button>
    <button class="tab-btn" onclick="showTab('reassign')">
        <i class="fas fa-exchange-alt"></i> گواستنەوەی نەخۆش
    </button>
    <button class="tab-btn" onclick="showTab('logs')">
        <i class="fas fa-history"></i> لۆگی چاودێری
    </button>
</div>

<!-- Tab: Doctor Permissions -->
<div class="tab-content active" id="tab-permissions">
    <div class="panel">
        <div class="panel-header">
            <h3><i class="fas fa-shield-alt"></i> مۆڵەتەکانی دکتۆرەکان</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($doctors)): ?>
            <div class="empty-state" style="padding: 40px; text-align: center;">
                <i class="fas fa-user-md" style="font-size: 48px; color: #ccc;"></i>
                <p style="margin-top: 16px; color: #666;">هیچ دکتۆرێک نەدۆزرایەوە</p>
            </div>
            <?php else: ?>
            <?php foreach ($doctors as $doctor): ?>
            <div class="doctor-card">
                <div class="doctor-avatar">
                    <?= mb_substr($doctor['full_name'], 0, 1, 'UTF-8') ?>
                </div>
                <div class="doctor-info">
                    <h3><?= htmlspecialchars($doctor['full_name']) ?></h3>
                    <div class="doctor-meta">
                        <div class="doctor-meta-item">
                            <i class="fas fa-users"></i>
                            <span><?= $doctor['patient_count'] ?> نەخۆش</span>
                        </div>
                        <div class="doctor-meta-item">
                            <i class="fas fa-folder"></i>
                            <span><?= $doctor['folder_count'] ?> دۆسیە</span>
                        </div>
                        <div class="doctor-meta-item">
                            <i class="fas fa-envelope"></i>
                            <span><?= htmlspecialchars($doctor['email'] ?? 'N/A') ?></span>
                        </div>
                        <?php if (!empty($doctor['specialization'])): ?>
                        <div class="doctor-meta-item">
                            <i class="fas fa-stethoscope"></i>
                            <span><?= htmlspecialchars($doctor['specialization']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="doctor-permissions">
                    <div class="permission-toggle">
                        <i class="fas fa-x-ray" style="color: #00897b;"></i>
                        <label for="xray_<?= $doctor['id'] ?>">بینینی ئێکسڕەی نەخۆشەکانی تر</label>
                        <div class="toggle-switch">
                            <input type="checkbox" id="xray_<?= $doctor['id'] ?>"
                                   data-doctor="<?= $doctor['id'] ?>"
                                   data-permission="can_view_other_xrays"
                                   <?= ($doctor['can_view_other_xrays'] ?? 1) ? 'checked' : '' ?>
                                   onchange="updatePermission(this)">
                            <span class="toggle-slider"></span>
                        </div>
                    </div>
                    <div class="permission-toggle">
                        <i class="fas fa-user" style="color: #1976d2;"></i>
                        <label for="profile_<?= $doctor['id'] ?>">بینینی پرۆفایلی نەخۆشەکانی تر</label>
                        <div class="toggle-switch">
                            <input type="checkbox" id="profile_<?= $doctor['id'] ?>"
                                   data-doctor="<?= $doctor['id'] ?>"
                                   data-permission="can_view_other_profiles"
                                   <?= ($doctor['can_view_other_profiles'] ?? 1) ? 'checked' : '' ?>
                                   onchange="updatePermission(this)">
                            <span class="toggle-slider"></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab: Reassign Patients -->
<div class="tab-content" id="tab-reassign">
    <div class="panel">
        <div class="panel-header">
            <h3><i class="fas fa-exchange-alt"></i> گواستنەوەی نەخۆش لە دکتۆرێکەوە بۆ دکتۆرێکی تر</h3>
        </div>
        <div class="panel-body">
            <div class="reassign-form" style="max-width: 600px;">
                <div class="form-group">
                    <label><i class="fas fa-user-md"></i> دکتۆری ئێستا (لێی بگوازەرەوە)</label>
                    <select id="fromDoctor" onchange="loadDoctorPatients(this.value)">
                        <option value="">دکتۆرێک هەڵبژێرە...</option>
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= $doctor['id'] ?>"><?= htmlspecialchars($doctor['full_name']) ?> (<?= $doctor['patient_count'] ?> نەخۆش)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-injured"></i> نەخۆش</label>
                    <select id="patientToReassign" disabled>
                        <option value="">سەرەتا دکتۆرێک هەڵبژێرە...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-md"></i> دکتۆری نوێ (بۆی بگوازەرەوە)</label>
                    <select id="toDoctor">
                        <option value="">دکتۆرێک هەڵبژێرە...</option>
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= $doctor['id'] ?>"><?= htmlspecialchars($doctor['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="background: #fff3e0; border: 1px solid #ff9800; border-radius: 8px; padding: 12px; margin-top: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px; color: #e65100;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>ئاگاداری:</strong>
                    </div>
                    <p style="font-size: 13px; color: #bf360c; margin: 8px 0 0 0;">
                        کاتێک نەخۆشێک دەگوازیتەوە، هەموو دۆسیەکان و مەوعیدەکانی لەلای دکتۆری کۆنەوە دەگوازرێنەوە بۆ دکتۆری نوێ.
                        دکتۆری کۆن چیتر ناتوانێت ئەم نەخۆشە ببینێت.
                    </p>
                </div>

                <button type="button" class="btn btn-primary" onclick="reassignPatient()" style="margin-top: 16px; padding: 12px 24px;">
                    <i class="fas fa-exchange-alt"></i>
                    گواستنەوەی نەخۆش
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Tab: Audit Logs -->
<div class="tab-content" id="tab-logs">
    <div class="panel">
        <div class="panel-header">
            <h3><i class="fas fa-history"></i> لۆگی چاودێری دەستگەیشتن</h3>
        </div>
        <div class="panel-body">
            <div class="log-filters">
                <select id="filterAccessType" onchange="filterLogs()">
                    <option value="">هەموو جۆرەکان</option>
                    <option value="view_profile_full">بینینی تەواو</option>
                    <option value="view_profile_restricted">بینینی سنوورمەند</option>
                    <option value="patient_reassigned">گواستنەوە</option>
                </select>
                <input type="date" id="filterDate" onchange="filterLogs()">
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> پاککردنەوە
                </button>
            </div>

            <?php if (empty($recentLogs)): ?>
            <div class="empty-state" style="padding: 40px; text-align: center;">
                <i class="fas fa-history" style="font-size: 48px; color: #ccc;"></i>
                <p style="margin-top: 16px; color: #666;">هیچ لۆگێک نەدۆزرایەوە</p>
            </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="logs-table" id="logsTable">
                    <thead>
                        <tr>
                            <th>بەکارهێنەر</th>
                            <th>نەخۆش</th>
                            <th>جۆری دەستگەیشتن</th>
                            <th>IP</th>
                            <th>کات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr data-type="<?= $log['access_type'] ?>" data-date="<?= date('Y-m-d', strtotime($log['created_at'])) ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">
                                        <?= mb_substr($log['user_name'] ?? '?', 0, 1, 'UTF-8') ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($log['user_name'] ?? 'نەناسراو') ?></div>
                                        <div style="font-size: 11px; color: #666;"><?= $log['user_role'] === 'doctor' ? 'دکتۆر' : ($log['user_role'] === 'admin' ? 'بەڕێوەبەر' : 'کارمەند') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($log['patient_name'] ?? 'نەناسراو') ?></div>
                                <div style="font-size: 11px; color: #666;"><?= $log['patient_code'] ?? '' ?></div>
                            </td>
                            <td>
                                <?php
                                $typeClass = 'full';
                                $typeLabel = 'بینینی تەواو';
                                if (strpos($log['access_type'], 'restricted') !== false) {
                                    $typeClass = 'restricted';
                                    $typeLabel = 'بینینی سنوورمەند';
                                } elseif (strpos($log['access_type'], 'reassigned') !== false) {
                                    $typeClass = 'reassigned';
                                    $typeLabel = 'گواستنەوە';
                                }
                                ?>
                                <span class="access-type-badge <?= $typeClass ?>"><?= $typeLabel ?></span>
                            </td>
                            <td style="font-family: var(--app-font-family); font-size: 12px;"><?= $log['ip_address'] ?? '-' ?></td>
                            <td style="font-size: 12px;">
                                <div><?= date('Y-m-d', strtotime($log['created_at'])) ?></div>
                                <div style="color: #666;"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Tab switching
function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));

    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}

// Update permission
function updatePermission(checkbox) {
    const doctorId = checkbox.dataset.doctor;
    const permission = checkbox.dataset.permission;
    const value = checkbox.checked ? 1 : 0;

    console.log('Updating permission:', { doctorId, permission, value });

    const formData = new FormData();
    formData.append('action', 'update_permission');
    formData.append('doctor_id', doctorId);
    formData.append('permission', permission);
    formData.append('value', value);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast('success', data.message);
                } else {
                    alert('سەرکەوتوو: ' + data.message);
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('error', data.message || 'هەڵەیەک ڕوویدا');
                } else {
                    alert('هەڵە: ' + (data.message || 'هەڵەیەک ڕوویدا'));
                }
                checkbox.checked = !checkbox.checked;
            }
        } catch (e) {
            console.error('JSON parse error:', e, 'Raw response:', text);
            if (typeof showToast === 'function') {
                showToast('error', 'هەڵەی سێرڤەر');
            } else {
                alert('هەڵەی سێرڤەر - تکایە کۆنسۆڵ ببینە');
            }
            checkbox.checked = !checkbox.checked;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        if (typeof showToast === 'function') {
            showToast('error', 'هەڵەیەک ڕوویدا');
        } else {
            alert('هەڵەی پەیوەندی');
        }
        checkbox.checked = !checkbox.checked;
    });
}

// Load doctor's patients for reassignment
function loadDoctorPatients(doctorId) {
    const patientSelect = document.getElementById('patientToReassign');

    if (!doctorId) {
        patientSelect.innerHTML = '<option value="">سەرەتا دکتۆرێک هەڵبژێرە...</option>';
        patientSelect.disabled = true;
        return;
    }

    patientSelect.innerHTML = '<option value="">چاوەڕوان بە...</option>';
    patientSelect.disabled = true;

    const formData = new FormData();
    formData.append('action', 'get_doctor_patients');
    formData.append('doctor_id', doctorId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            patientSelect.innerHTML = '<option value="">نەخۆشێک هەڵبژێرە...</option>';
            data.patients.forEach(patient => {
                patientSelect.innerHTML += `<option value="${patient.id}">${patient.full_name} (${patient.patient_code}) - ${patient.folder_count} دۆسیە</option>`;
            });
            patientSelect.disabled = false;
        } else {
            if (typeof showToast === 'function') {
                showToast('error', 'هەڵەیەک ڕوویدا');
            }
        }
    });
}

// Reassign patient
function reassignPatient() {
    const fromDoctor = document.getElementById('fromDoctor').value;
    const patientId = document.getElementById('patientToReassign').value;
    const toDoctor = document.getElementById('toDoctor').value;

    if (!fromDoctor || !patientId || !toDoctor) {
        if (typeof showToast === 'function') {
            showToast('error', 'تکایە هەموو خانەکان پڕبکەرەوە');
        } else {
            alert('تکایە هەموو خانەکان پڕبکەرەوە');
        }
        return;
    }

    if (fromDoctor === toDoctor) {
        if (typeof showToast === 'function') {
            showToast('error', 'دکتۆری نوێ نابێت هەمان دکتۆری کۆن بێت');
        } else {
            alert('دکتۆری نوێ نابێت هەمان دکتۆری کۆن بێت');
        }
        return;
    }

    if (!confirm('ئایا دڵنیایت لە گواستنەوەی ئەم نەخۆشە؟')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'reassign_patient');
    formData.append('from_doctor_id', fromDoctor);
    formData.append('patient_id', patientId);
    formData.append('to_doctor_id', toDoctor);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showToast === 'function') {
                showToast('success', data.message);
            } else {
                alert('سەرکەوتوو: ' + data.message);
            }
            // Reset form
            document.getElementById('fromDoctor').value = '';
            document.getElementById('patientToReassign').innerHTML = '<option value="">سەرەتا دکتۆرێک هەڵبژێرە...</option>';
            document.getElementById('patientToReassign').disabled = true;
            document.getElementById('toDoctor').value = '';
            // Reload page after delay
            setTimeout(() => location.reload(), 1500);
        } else {
            if (typeof showToast === 'function') {
                showToast('error', data.message || 'هەڵەیەک ڕوویدا');
            } else {
                alert('هەڵە: ' + (data.message || 'هەڵەیەک ڕوویدا'));
            }
        }
    });
}

// Filter logs
function filterLogs() {
    const typeFilter = document.getElementById('filterAccessType').value;
    const dateFilter = document.getElementById('filterDate').value;
    const rows = document.querySelectorAll('#logsTable tbody tr');

    rows.forEach(row => {
        const type = row.dataset.type;
        const date = row.dataset.date;

        let show = true;
        if (typeFilter && !type.includes(typeFilter.replace('view_profile_', ''))) {
            show = false;
        }
        if (dateFilter && date !== dateFilter) {
            show = false;
        }

        row.style.display = show ? '' : 'none';
    });
}

function clearFilters() {
    document.getElementById('filterAccessType').value = '';
    document.getElementById('filterDate').value = '';
    filterLogs();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
