<?php
/**
 * Notifications API Endpoint
 * ئایپیئای ئاگادارکردنەوەکان
 *
 * Handles AJAX requests for real-time notifications
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Notification.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = $currentUser['id'];
$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$notification = new Notification();

// Financial notification types — visible to admins & accountants only.
// Same list everywhere (poll, lists, counts) so all roles see consistent data.
$adminOnlyTypes = Notification::FINANCIAL_TYPES;
$canSeeFinancial = $isAdmin || $isAccountant;
$typeFilter = $canSeeFinancial ? null : $adminOnlyTypes;

$action = $_GET['action'] ?? $_POST['action'] ?? 'poll';

// State-changing actions must be POST + CSRF-protected
$mutatingActions = ['mark_read', 'mark_all_read', 'mark_all_seen', 'delete', 'update_settings', 'complete_case'];
if (in_array($action, $mutatingActions, true)) {
    Csrf::require();
} else {
    // Read-only from here on. PHP holds an exclusive lock on the session file
    // for the whole request, so every open tab polling this endpoint every
    // 10s would otherwise serialise that user's real page loads behind it.
    // Everything needed from the session ($currentUser, role flags) is already
    // read above.
    session_write_close();
}

/**
 * Decorate a notification row for JSON output (decode data, icon, color, link).
 */
function decorate_notification($n) {
    $n['data'] = $n['data'] ? json_decode($n['data'], true) : null;
    $n['icon'] = Notification::getTypeIcon($n['type']);
    $n['color'] = Notification::getTypeColor($n['type']);
    $n['time_ago'] = timeAgo($n['created_at']);
    if (empty($n['link'])) {
        $n['link'] = Notification::resolveLink($n['type'], $n['data']);
    }
    return $n;
}

/**
 * Human module label for a notification type (shown in dropdown + modal).
 */
function notification_module($type) {
    $map = [
        'patient_called' => 'ژووری چاوەڕوانی', 'patient_waiting' => 'ژووری چاوەڕوانی',
        'patient_returned' => 'ژووری چاوەڕوانی', 'appointment_checkin' => 'ژووری چاوەڕوانی',
        'visit_completed' => 'ژووری چاوەڕوانی', 'visit_cancelled' => 'ژووری چاوەڕوانی',
        'visit_created' => 'سەردانەکان',
        'patient_accounting' => 'ژمێریاری',
        'payment_pending' => 'ژمێریاری', 'payment_approved' => 'ژمێریاری',
        'payment_rejected' => 'ژمێریاری', 'payment_received' => 'ژمێریاری',
        'installment_plan' => 'ژمێریاری', 'installment_due' => 'ژمێریاری',
        'xray' => 'ئێکسڕەی', 'xray_ready' => 'ئێکسڕەی',
        'xray_payment' => 'ئێکسڕەی', 'xray_payment_ready' => 'ئێکسڕەی',
        'case_completed' => 'کەیسەکان',
        'appointment_reminder' => 'مەوعیدەکان', 'appointment_new' => 'مەوعیدەکان',
        'appointment_updated' => 'مەوعیدەکان', 'appointment_cancelled' => 'مەوعیدەکان',
    ];
    return $map[$type] ?? 'سیستەم';
}

try {
    switch ($action) {
        case 'poll':
            // Daily installment reminder digest (self-throttled, fail-soft)
            if ($canSeeFinancial) {
                $notification->generateInstallmentReminders();
            }

            // Retention sweep — self-throttled to once an hour across all
            // pollers, so the table cannot grow without bound again.
            $notification->cleanupExpiredThrottled();
            // Handled notifications leave the bell for everyone (once a minute)
            $notification->resolveHandledThrottled();

            // Get new notifications since last check (for polling)
            $lastCheck = $_GET['last_check'] ?? null;
            $newNotifications = $notification->getNewSince($userId, $lastCheck);

            // Filter out financial notifications for other users
            if (!$canSeeFinancial) {
                $newNotifications = array_filter($newNotifications, function($n) use ($adminOnlyTypes) {
                    return !in_array($n['type'], $adminOnlyTypes);
                });
            }

            echo json_encode([
                'success' => true,
                'notifications' => array_map('decorate_notification', array_values($newNotifications)),
                'unread_count' => $notification->getUnreadCount($userId, $typeFilter),
                'unseen_count' => $notification->getUnseenCount($userId, $typeFilter),
                'server_time' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'get_unread':
        case 'get_recent':
            // Latest notifications for the dropdown (get_recent mixes read+unread)
            $notifications = ($action === 'get_recent')
                ? $notification->getRecent($userId, 15)
                : $notification->getUnread($userId, 20);

            if (!$canSeeFinancial) {
                $notifications = array_filter($notifications, function($n) use ($adminOnlyTypes) {
                    return !in_array($n['type'], $adminOnlyTypes);
                });
            }

            echo json_encode([
                'success' => true,
                'notifications' => array_map('decorate_notification', array_values($notifications)),
                'unread_count' => $notification->getUnreadCount($userId, $typeFilter)
            ]);
            break;

        case 'get_detail':
            // Full detail for the notification modal (ownership enforced)
            $notificationId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
            if (!$notificationId) {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
                break;
            }

            $n = $notification->getById($notificationId, $userId);
            if (!$n || (!$canSeeFinancial && in_array($n['type'], $adminOnlyTypes))) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Notification not found']);
                break;
            }

            // Opening the detail counts as reading it
            if (!$n['is_read']) {
                $notification->markAsRead($notificationId, $userId);
                $n['is_read'] = 1;
                $n['read_at'] = date('Y-m-d H:i:s');
            }

            $n = decorate_notification($n);
            $data = is_array($n['data']) ? $n['data'] : [];

            // Related-entity rows for the modal (label => value)
            $related = [];
            if (!empty($data['patient_name'])) $related[] = ['label' => 'نەخۆش', 'value' => $data['patient_name']];
            if (!empty($data['doctor_name']))  $related[] = ['label' => 'دکتۆر', 'value' => $data['doctor_name']];
            if (isset($data['amount']) && $data['amount'] !== null && $data['amount'] !== '')
                $related[] = ['label' => 'بڕی پارە', 'value' => number_format((float)$data['amount'])];
            if (!empty($data['date']))
                $related[] = ['label' => 'بەروار', 'value' => $data['date'] . (!empty($data['time']) ? ' - ' . substr($data['time'], 0, 5) : '')];
            if (!empty($data['next_appointment'])) $related[] = ['label' => 'مەوعیدی داهاتوو', 'value' => $data['next_appointment']];
            if (!empty($data['notes'])) $related[] = ['label' => 'تێبینی', 'value' => $data['notes']];

            echo json_encode([
                'success' => true,
                'notification' => $n,
                'module' => notification_module($n['type']),
                'related' => $related,
                'created_at_formatted' => date('Y/m/d - H:i', strtotime($n['created_at'])),
                'unread_count' => $notification->getUnreadCount($userId, $typeFilter),
                'unseen_count' => $notification->getUnseenCount($userId, $typeFilter)
            ]);
            break;

        case 'get_count':
            // Get just the counts (financial types excluded for other roles)
            echo json_encode([
                'success' => true,
                'unread_count' => $notification->getUnreadCount($userId, $typeFilter),
                'unseen_count' => $notification->getUnseenCount($userId, $typeFilter)
            ]);
            break;

        case 'mark_read':
            // Mark a notification as read
            $notificationId = intval($_POST['notification_id'] ?? 0);
            if ($notificationId) {
                $notification->markAsRead($notificationId, $userId);
                echo json_encode([
                    'success' => true,
                    'unread_count' => $notification->getUnreadCount($userId, $typeFilter),
                    'unseen_count' => $notification->getUnseenCount($userId, $typeFilter)
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            break;

        case 'mark_all_read':
            // Mark all notifications as read
            $notification->markAllAsRead($userId);
            echo json_encode(['success' => true, 'unread_count' => 0, 'unseen_count' => 0]);
            break;

        case 'mark_all_seen':
            // Mark all notifications as seen (dropdown opened)
            $notification->markAllAsSeen($userId);
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            // Delete a notification
            $notificationId = intval($_POST['notification_id'] ?? 0);
            if ($notificationId) {
                $notification->delete($notificationId, $userId);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            break;

        case 'get_settings':
            // Get notification settings
            $settings = $notification->getSettings($userId);
            echo json_encode(['success' => true, 'settings' => $settings]);
            break;

        case 'update_settings':
            // Update notification settings
            $settings = [
                'sound_enabled' => intval($_POST['sound_enabled'] ?? 1),
                'toast_enabled' => intval($_POST['toast_enabled'] ?? 1),
                'patient_called_enabled' => intval($_POST['patient_called_enabled'] ?? 1),
                'patient_waiting_enabled' => intval($_POST['patient_waiting_enabled'] ?? 1),
                'xray_ready_enabled' => intval($_POST['xray_ready_enabled'] ?? 1)
            ];
            $notification->updateSettings($userId, $settings);
            echo json_encode(['success' => true]);
            break;

        case 'get_current_patient':
            // Get current patient in progress for doctor
            $doctorId = $auth->isDoctor() ? $userId : intval($_GET['doctor_id'] ?? 0);
            if ($doctorId) {
                $currentPatient = $notification->getCurrentPatient($doctorId);
                $waitingQueue = $notification->getWaitingQueue($doctorId, 5);
                echo json_encode([
                    'success' => true,
                    'current_patient' => $currentPatient,
                    'waiting_queue' => $waitingQueue,
                    'waiting_count' => count($waitingQueue)
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid doctor ID']);
            }
            break;

        case 'get_waiting_status':
            // Get waiting room status for public display
            $doctorId = $_GET['doctor_id'] ?? null;

            // Doctor isolation: a doctor may only see their OWN queue — ignore any
            // client-supplied doctor_id and force it to their own id.
            if (AccessGuard::isRestrictedDoctor()) {
                $doctorId = AccessGuard::doctorId();
            }

            $sql = "SELECT wr.*, p.full_name, p.patient_code, u.full_name as doctor_name,
                           TIMESTAMPDIFF(MINUTE, wr.check_in_time, NOW()) as waiting_minutes
                    FROM waiting_room wr
                    LEFT JOIN patients p ON wr.patient_id = p.id
                    LEFT JOIN users u ON wr.doctor_id = u.id
                    WHERE DATE(wr.check_in_time) = CURDATE()
                    AND wr.status IN ('waiting', 'in_progress')";

            $params = [];
            if ($doctorId) {
                $sql .= " AND wr.doctor_id = :doctor_id";
                $params['doctor_id'] = $doctorId;
            }

            $sql .= " ORDER BY wr.status = 'in_progress' DESC, wr.priority DESC, wr.check_in_time ASC";

            $db = Database::getInstance();
            $waitingList = $db->fetchAll($sql, $params);

            // Separate by status
            $inProgress = array_filter($waitingList, fn($w) => $w['status'] === 'in_progress');
            $waiting = array_filter($waitingList, fn($w) => $w['status'] === 'waiting');

            echo json_encode([
                'success' => true,
                'in_progress' => array_values($inProgress),
                'waiting' => array_values($waiting),
                'total_waiting' => count($waiting),
                'server_time' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'complete_case':
            // Doctor completes case and notifies admin
            if (!$auth->isDoctor() && !$isAdmin) {
                echo json_encode(['success' => false, 'error' => 'تەنها دکتۆر دەتوانێت ئەم کارە بکات']);
                break;
            }

            $caseId = intval($_POST['case_id'] ?? 0);
            $patientId = intval($_POST['patient_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $nextAppointment = trim($_POST['next_appointment'] ?? '');

            if (!$caseId || !$patientId) {
                echo json_encode(['success' => false, 'error' => 'زانیاری ناتەواو']);
                break;
            }

            $db = Database::getInstance();

            // Get case and patient info (doctor_id is in patient_folders, not cases)
            $caseInfo = $db->fetch("
                SELECT c.*, pf.patient_id, pf.doctor_id, p.full_name as patient_name, u.full_name as doctor_name
                FROM cases c
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE c.id = :id
            ", ['id' => $caseId]);

            if (!$caseInfo) {
                echo json_encode(['success' => false, 'error' => 'کەیس نەدۆزرایەوە']);
                break;
            }

            // Ownership: a doctor may only complete their OWN cases (admins any).
            // Mirrors the access check on cases/view.php itself.
            if (!$isAdmin && (int)$caseInfo['doctor_id'] !== (int)$userId) {
                echo json_encode(['success' => false, 'error' => 'ئەم کەیسە سەر بە دکتۆرێکی ترە']);
                break;
            }

            // accounting_reports is frozen (Task 13) — there is no more
            // "was the last report processed?" gate to check here. Completing
            // a case is purely a doctor-facing event now (checkout + notify),
            // same as cases/view.php's arch_procedure_log branch, which never
            // had this gate to begin with.

            // Case is done — check the patient out of the waiting room
            $db->query("
                UPDATE waiting_room
                SET status = 'completed', check_out_time = NOW(), notes = CONCAT(IFNULL(notes, ''), :notes)
                WHERE patient_id = :patient_id
                AND doctor_id = :doctor_id
                AND status = 'in_progress'
                AND DATE(check_in_time) = CURDATE()
            ", [
                'patient_id' => $caseInfo['patient_id'],
                'doctor_id' => $caseInfo['doctor_id'],
                'notes' => $notes ? "\n[تێبینی دکتۆر]: " . $notes : ''
            ]);

            // Create next appointment if provided
            $appointmentId = null;
            $appointmentConflict = false;
            if ($nextAppointment) {
                // Parse date and time from combined value (e.g., "2024-01-15 09:30:00")
                $appointmentDateTime = strtotime($nextAppointment);
                $appointmentDate = date('Y-m-d', $appointmentDateTime);
                $appointmentTime = date('H:i:s', $appointmentDateTime);

                // Get duration from POST or use default (in minutes)
                $duration = intval($_POST['duration'] ?? 60); // Default 60 minutes (1 hour)
                if ($duration < 15) $duration = 30; // Minimum 30 minutes
                if ($duration > 480) $duration = 480; // Maximum 8 hours

                // Overlapping bookings are allowed by clinic policy — no conflict check
                $appointmentId = $db->insert('appointments', [
                    'patient_id' => $caseInfo['patient_id'],
                    'doctor_id' => $caseInfo['doctor_id'],
                    'folder_id' => $caseInfo['folder_id'],
                    'case_id' => $caseId,
                    'appointment_date' => $appointmentDate,
                    'appointment_time' => $appointmentTime,
                    'duration' => $duration,
                    'type' => 'followup',
                    'status' => 'scheduled',
                    'notes' => 'مەوعیدی چالاککراو لە لایەن دکتۆر: ' . ($notes ?: 'بەدوادادینەوە'),
                    'created_by' => $userId
                ]);
            }

            // Format appointment date/time for notification
            $formattedAppointment = null;
            if ($nextAppointment && !$appointmentConflict) {
                $formattedAppointment = date('Y/m/d', $appointmentDateTime) . ' - ' . date('H:i', $appointmentDateTime);
            }

            // No accounting_reports row any more (frozen table) — the
            // notification below is the only accounting-facing signal.

            // Send notification to all accountants
            $notification->notifyCaseCompleted(
                $caseInfo['patient_name'],
                $caseInfo['doctor_name'],
                $caseId,
                $caseInfo['patient_id'],
                $notes,
                $formattedAppointment,
                $caseInfo['doctor_id']
            );

            // Build response message
            $message = 'کەیس تەواو بوو و ئاگاداری بۆ ژمێریار نێردرا';
            if ($appointmentConflict) {
                $message .= "\n⚠️ مەوعید دانەنرا - ئەو کاتە پێشتر گیراوە";
            } elseif ($appointmentId) {
                $message .= "\n✅ مەوعیدی داهاتوو زیادکرا";
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'appointment_id' => $appointmentId,
                'appointment_conflict' => $appointmentConflict
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    // Never leak internals to the client — log and return a generic error
    error_log('notifications api error [' . $action . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە']);
}

/**
 * Helper function to format time ago
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' ساڵ پێش';
    if ($diff->m > 0) return $diff->m . ' مانگ پێش';
    if ($diff->d > 0) return $diff->d . ' ڕۆژ پێش';
    if ($diff->h > 0) return $diff->h . ' کاتژمێر پێش';
    if ($diff->i > 0) return $diff->i . ' خولەک پێش';
    return 'ئێستا';
}
