<?php
/**
 * AJAX Patient Search Endpoint
 * Supports doctor-specific filtering for privacy
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/XrayManager.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

try {
    $patientManager = new Patient();
    $currentUser = $auth->getCurrentUser();
    $isDoctor = $auth->isDoctor();
    $isAdmin = $auth->isAdmin();

    // Get parameters
    $page = max(1, intval($_POST['page'] ?? 1));
    $search = trim($_POST['search'] ?? '');
    // Whitelisted: $orderBy is interpolated straight into ORDER BY by
    // Patient::getAll(), so a raw value here is SQL injection.
    $allowedOrderBy = [
        'created_at DESC' => 'p.created_at DESC',
        'created_at ASC'  => 'p.created_at ASC',
        'full_name ASC'   => 'p.full_name ASC',
        'full_name DESC'  => 'p.full_name DESC',
        'patient_code ASC'  => 'p.patient_code ASC',
        'patient_code DESC' => 'p.patient_code DESC',
    ];
    $requestedOrderBy = (string)($_POST['orderBy'] ?? 'created_at DESC');
    $orderBy = $allowedOrderBy[$requestedOrderBy] ?? 'p.created_at DESC';

    $requestedViewMode = (string)($_POST['view_mode'] ?? 'own');
    $viewMode = in_array($requestedViewMode, ['own', 'all'], true) ? $requestedViewMode : 'own';
    $profileDoctorId = max(0, intval($_POST['doctor_id'] ?? 0));

    // Keep doctor-profile searches scoped to the requested doctor's own patients.
    // A doctor can never use this endpoint to inspect another doctor's patient list.
    if ($profileDoctorId > 0) {
        if ($isDoctor && !$isAdmin && $profileDoctorId !== intval($currentUser['id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $doctorExists = Database::getInstance()->fetch(
            "SELECT id FROM users WHERE id = :id AND role = 'doctor'",
            ['id' => $profileDoctorId]
        );
        if (!$doctorExists) {
            http_response_code(404);
            echo json_encode(['error' => 'Doctor not found']);
            exit;
        }
        $viewMode = 'own';
    }

    // Get filters
    $filters = [];
    if (!empty($_POST['gender'])) {
        $filters['gender'] = $_POST['gender'];
    }
    if (!empty($_POST['date_from'])) {
        $filters['date_from'] = $_POST['date_from'];
    }
    if (!empty($_POST['date_to'])) {
        $filters['date_to'] = $_POST['date_to'];
    }
    if (isset($_POST['has_debt']) && $_POST['has_debt'] !== '') {
        $filters['has_debt'] = $_POST['has_debt'];
    }
    if (isset($_POST['has_medical_alerts']) && $_POST['has_medical_alerts'] !== '') {
        $filters['has_medical_alerts'] = $_POST['has_medical_alerts'];
    }
    if (!empty($_POST['folder_status'])) {
        $filters['folder_status'] = $_POST['folder_status'];
    }

    // Doctor-specific filtering
    $isRestrictedView = false;
    $needsSearchPrompt = false;
    if ($profileDoctorId > 0) {
        $patients = $patientManager->getDoctorPatients($profileDoctorId, $page, ITEMS_PER_PAGE, $search, $filters);
        $totalPatients = $patientManager->countDoctorPatients($profileDoctorId, $search, $filters);
    } elseif ($isDoctor && !$isAdmin) {
        $doctorId = $currentUser['id'];
        if ($viewMode === 'own') {
            // Get only doctor's own patients
            $patients = $patientManager->getDoctorPatients($doctorId, $page, ITEMS_PER_PAGE, $search, $filters);
            $totalPatients = $patientManager->countDoctorPatients($doctorId, $search, $filters);
        } elseif (mb_strlen($search) >= DIRECTORY_SEARCH_MIN_CHARS) {
            // Every other patient in the clinic, demographic columns only.
            // Filters are forwarded; the restricted builder accepts only the
            // demographic ones, so debt/folder filters cannot widen this view.
            $patients = $patientManager->getAllPatientsRestricted($doctorId, $page, ITEMS_PER_PAGE, $search, $filters);
            $totalPatients = $patientManager->countAllPatientsRestricted($doctorId, $search, $filters);
            $isRestrictedView = true;
        } else {
            // Lazy directory: never dump thousands of colleagues' patients.
            // Enforced here too, not only in the UI — the browser could POST a
            // blank search directly. Mirrors patients/index.php.
            $patients = [];
            $totalPatients = 0;
            $isRestrictedView = true;
            $needsSearchPrompt = true;
        }
    } else {
        // Admin or staff - see all patients
        $patients = $patientManager->getAll($page, ITEMS_PER_PAGE, $search, $orderBy, $filters);
        $totalPatients = $patientManager->count($search, $filters);
    }
    $totalPages = ceil($totalPatients / ITEMS_PER_PAGE);

    // Get statistics based on filters
    $statistics = [
        'totalPatients' => $totalPatients,
        'activeFolders' => 0,
        'closedFolders' => 0,
        'activeCases' => 0,
        'newThisMonth' => 0,
        'patientsWithDebt' => 0,
        'totalDebt' => 0
    ];

    // Calculate statistics for filtered results
    if (!empty($patients)) {
        // Count folders and cases for filtered patients
        $patientIds = array_column($patients, 'id');
        $ids = implode(',', array_map('intval', $patientIds));

        $db = Database::getInstance();
        $doctorScope = $profileDoctorId > 0 ? ' AND doctor_id = ' . $profileDoctorId : '';

        // Active folders
        $result = $db->fetch("SELECT COUNT(*) as count FROM patient_folders WHERE status = 'active' AND patient_id IN ($ids)$doctorScope");
        $statistics['activeFolders'] = $result['count'];

        // Closed folders
        $result = $db->fetch("SELECT COUNT(*) as count FROM patient_folders WHERE status = 'closed' AND patient_id IN ($ids)$doctorScope");
        $statistics['closedFolders'] = $result['count'];

        // Active cases
        $result = $db->fetch("SELECT COUNT(*) as count FROM cases WHERE status = 'open' AND folder_id IN (SELECT id FROM patient_folders WHERE patient_id IN ($ids)$doctorScope)");
        $statistics['activeCases'] = $result['count'];

        // New this month
        $result = $db->fetch("SELECT COUNT(*) as count FROM patients WHERE id IN ($ids) AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
        $statistics['newThisMonth'] = $result['count'];

        // Patients with debt (folder debt OR X-ray debt)
        $result = $db->fetch("SELECT COUNT(DISTINCT patient_id) as count FROM (
            SELECT pf.patient_id FROM patient_folders pf WHERE pf.patient_id IN ($ids)$doctorScope AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0
            UNION
            SELECT xr.patient_id FROM xray_requests xr WHERE xr.patient_id IN ($ids) AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")
        ) as debt_patients");
        $statistics['patientsWithDebt'] = $result['count'];

        // Total debt amount (folder debt + X-ray debt)
        $folderDebtResult = $db->fetch("SELECT COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) as total FROM patient_folders pf WHERE pf.patient_id IN ($ids)$doctorScope AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0");
        $xrayDebtResult = $db->fetch("SELECT COALESCE(SUM(xr.final_amount), 0) as total FROM xray_requests xr WHERE xr.patient_id IN ($ids) AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")");
        $statistics['totalDebt'] = ($folderDebtResult['total'] ?? 0) + ($xrayDebtResult['total'] ?? 0);
    }

    // Generate HTML for patient cards
    $html = '';

    if (!empty($needsSearchPrompt)) {
        // Directory idle state — the doctor has not typed enough yet.
        $html = '<div class="empty-state" style="padding: 40px 20px;">
            <i class="fas fa-search empty-state-icon" style="font-size: 64px; color: var(--text-secondary); opacity: 0.3;"></i>
            <p class="empty-state-title" style="font-size: 18px; font-weight: 700; margin: 16px 0 8px;">گەڕان بۆ نەخۆشێکی کلینیک</p>
            <p class="empty-state-text" style="font-size: 14px; color: var(--text-secondary); margin-bottom: 20px;">
                لانیکەم ' . DIRECTORY_SEARCH_MIN_CHARS . ' پیت بنووسە بە ناو، ژمارەی مۆبایل، یان کۆدی نەخۆش
            </p>
        </div>';
    } elseif (empty($patients)) {
        $emptyMessage = $isRestrictedView ? 'هیچ نەخۆشێکی تری کلینیک نەدۆزرایەوە' : 'هیچ نەخۆشێک نەدۆزرایەوە';
        $html = '<div class="empty-state" style="padding: 40px 20px;">
            <i class="fas fa-users empty-state-icon" style="font-size: 64px; color: var(--text-secondary); opacity: 0.3;"></i>
            <p class="empty-state-title" style="font-size: 18px; font-weight: 700; margin: 16px 0 8px;">' . $emptyMessage . '</p>
            <p class="empty-state-text" style="font-size: 14px; color: var(--text-secondary); margin-bottom: 20px;">
                گەڕانەکەت ئەنجامی نەبوو
            </p>
        </div>';
    } else {
        foreach ($patients as $patient) {
            // Get first letter of name for avatar
            $firstLetter = mb_substr($patient['full_name'], 0, 1, 'UTF-8');

            if ($isRestrictedView) {
                // RESTRICTED VIEW - Show only basic info for other doctors' patients
                $html .= '<div class="patient-card restricted-view">
                    <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '">
                        <div class="patient-avatar ' . ($patient['gender'] === 'female' ? 'female' : '') . '">
                            ' . htmlspecialchars($firstLetter) . '
                        </div>
                    </a>
                    <div class="patient-info">
                        <div class="patient-name">
                            <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '">
                                ' . htmlspecialchars($patient['full_name']) . '
                            </a>
                            <span class="badge badge-orange" style="font-size: 10px;">
                                <i class="fas fa-lock" style="font-size: 9px;"></i> بینینی سنوورمەند
                            </span>
                            <span class="badge ' . ($patient['gender'] === 'male' ? 'badge-blue' : 'badge-pink') . '">
                                ' . ($patient['gender'] === 'male' ? 'نێر' : 'مێ') . '
                            </span>
                        </div>
                        <div class="patient-meta">
                            <div class="patient-meta-item">
                                <i class="fas fa-id-card"></i>
                                <span>' . htmlspecialchars($patient['patient_code'] ?? '') . '</span>
                            </div>
                            <div class="patient-meta-item">
                                <i class="fas fa-phone"></i>
                                <span>' . htmlspecialchars($patient['phone'] ?? '') . '</span>
                            </div>';

                // Show X-ray count if available
                if (isset($patient['xray_count']) && $patient['xray_count'] > 0) {
                    $html .= '<div class="patient-meta-item" style="color: #00897b;">
                                <i class="fas fa-x-ray"></i>
                                <span>' . $patient['xray_count'] . ' ئێکسڕەی</span>
                            </div>';
                }

                $html .= '</div>
                    </div>
                    <div class="patient-actions">
                        <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '" class="btn btn-secondary" title="بینینی پرۆفایل">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>';
            } else {
                // FULL VIEW - Show complete info for own patients or admin
                $hasConditions = $patientManager->hasMedicalAlert($patient['id']);

                $html .= '<div class="patient-card ' . ($hasConditions ? 'has-alert' : '') . '">
                    <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '">
                        <div class="patient-avatar ' . ($patient['gender'] === 'female' ? 'female' : '') . '">
                            ' . htmlspecialchars($firstLetter) . '
                        </div>
                    </a>
                    <div class="patient-info">
                        <div class="patient-name">
                            <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '">
                                ' . htmlspecialchars($patient['full_name']) . '
                            </a>';

                if ($hasConditions) {
                    $html .= '<i class="fas fa-exclamation-triangle" style="color: var(--pt-danger); font-size: 13px;" title="ئاگادارکردنەوەی پزیشکی"></i>';
                }

                $html .= '<span class="badge ' . ($patient['gender'] === 'male' ? 'badge-blue' : 'badge-pink') . '">
                                ' . ($patient['gender'] === 'male' ? 'نێر' : 'مێ') . '
                            </span>
                        </div>
                        <div class="patient-meta">
                            <div class="patient-meta-item">
                                <i class="fas fa-id-card"></i>
                                <span>' . htmlspecialchars($patient['patient_code'] ?? '') . '</span>
                            </div>
                            <div class="patient-meta-item">
                                <i class="fas fa-phone"></i>
                                <a href="tel:' . rawurlencode($patient['phone'] ?? '') . '" style="color: inherit;">' . htmlspecialchars($patient['phone'] ?? '') . '</a>
                            </div>
                            <div class="patient-meta-item">
                                <i class="fas fa-folder"></i>
                                <span>' . ($patient['folder_count'] ?? 0) . ' دۆسیە</span>
                            </div>';

                if (($patient['total_debt'] ?? 0) > 0) {
                    $html .= '<div class="patient-meta-item" style="color: var(--pt-danger); font-weight: 600;">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>قەرز: ' . number_format($patient['total_debt']) . '</span>
                            </div>';
                }

                $html .= '<div class="patient-meta-item">
                                <i class="fas fa-calendar"></i>
                                <span>' . date('Y-m-d', strtotime($patient['created_at'])) . '</span>
                            </div>
                        </div>
                    </div>
                    <div class="patient-actions">
                        <a href="' . APP_URL . '/patients/view.php?id=' . $patient['id'] . '" class="btn btn-primary" title="بینین">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="' . APP_URL . '/patients/edit.php?id=' . $patient['id'] . '" class="btn btn-secondary" title="دەستکاری">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="' . APP_URL . '/patients/statement.php?patient_id=' . (int)$patient['id'] . '" class="btn btn-secondary" title="کشف حساب" style="color:#0878d1;border-color:#0878d1;">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </a>';

                if ($auth->isAdmin()) {
                    // Must mirror the server-rendered delete in index.php: the handler
                    // is POST + action=delete_patient + CSRF. The old '?delete=<id>'
                    // GET link matched no handler at all, so delete silently did
                    // nothing after any search. The CSRF token is attached by the
                    // form-submit patch in includes/header.php.
                    $pid = (int)$patient['id'];
                    $html .= '<button type="button" onclick="confirmDelete(\'ئایا دڵنیایت لە سڕینەوەی ئەم نەخۆشە؟ زانیاری نەخۆش دەشاردرێتەوە بەڵام مێژووی دارایی و کەیسەکان دەمێننەوە.\', () => document.getElementById(\'deletePatientForm' . $pid . '\').submit())" class="btn btn-danger" title="سڕینەوە">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <form id="deletePatientForm' . $pid . '" method="POST" action="' . APP_URL . '/patients/index.php" style="display:none;">
                                <input type="hidden" name="action" value="delete_patient">
                                <input type="hidden" name="patient_id" value="' . $pid . '">
                            </form>';
                }

                $html .= '</div>
                </div>';
            }
        }
    }

    // Generate pagination HTML
    $paginationHtml = '';
    if ($totalPages > 1) {
        $paginationHtml = '<div class="pagination">';

        // First page
        $paginationHtml .= '<a href="#" class="pagination-btn ' . ($page <= 1 ? 'disabled' : '') . '" data-page="1">
            <i class="fas fa-angle-double-right"></i>
        </a>';

        // Previous page
        $paginationHtml .= '<a href="#" class="pagination-btn ' . ($page <= 1 ? 'disabled' : '') . '" data-page="' . ($page - 1) . '">
            <i class="fas fa-angle-right"></i>
        </a>';

        // Page numbers
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        for ($i = $startPage; $i <= $endPage; $i++) {
            $paginationHtml .= '<a href="#" class="pagination-btn ' . ($i == $page ? 'active' : '') . '" data-page="' . $i . '">' . $i . '</a>';
        }

        // Next page
        $paginationHtml .= '<a href="#" class="pagination-btn ' . ($page >= $totalPages ? 'disabled' : '') . '" data-page="' . ($page + 1) . '">
            <i class="fas fa-angle-left"></i>
        </a>';

        // Last page
        $paginationHtml .= '<a href="#" class="pagination-btn ' . ($page >= $totalPages ? 'disabled' : '') . '" data-page="' . $totalPages . '">
            <i class="fas fa-angle-double-left"></i>
        </a>';

        $paginationHtml .= '</div>';
    }

    // Return response
    echo json_encode([
        'success' => true,
        'html' => $html,
        'pagination' => $paginationHtml,
        'total' => $totalPatients,
        'page' => $page,
        'totalPages' => $totalPages,
        'statistics' => $statistics,
        'patients' => $patients  // Add patient data for live search
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
