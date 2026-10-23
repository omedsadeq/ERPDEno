<?php
/**
 * Notification Class - Real-time Notification System
 * سیستەمی ئاگادارکردنەوەی ڕاستەوخۆ
 */

class Notification {
    private $db;

    // Notification types
    const TYPE_PATIENT_CALLED = 'patient_called';
    const TYPE_PATIENT_WAITING = 'patient_waiting';
    const TYPE_PATIENT_ACCOUNTING = 'patient_accounting';
    const TYPE_PATIENT_RETURNED = 'patient_returned';
    const TYPE_XRAY_READY = 'xray_ready';
    const TYPE_XRAY_PAYMENT = 'xray_payment';
    const TYPE_XRAY = 'xray';
    const TYPE_CASE_COMPLETED = 'case_completed';
    const TYPE_APPOINTMENT_REMINDER = 'appointment_reminder';
    const TYPE_APPOINTMENT_NEW = 'appointment_new';
    const TYPE_APPOINTMENT_UPDATED = 'appointment_updated';
    const TYPE_APPOINTMENT_CANCELLED = 'appointment_cancelled';
    const TYPE_APPOINTMENT_CHECKIN = 'appointment_checkin';
    const TYPE_VISIT_CREATED = 'visit_created';
    const TYPE_VISIT_COMPLETED = 'visit_completed';
    const TYPE_VISIT_CANCELLED = 'visit_cancelled';
    const TYPE_PAYMENT_PENDING = 'payment_pending';
    const TYPE_PAYMENT_APPROVED = 'payment_approved';
    const TYPE_PAYMENT_REJECTED = 'payment_rejected';
    const TYPE_PAYMENT_RECEIVED = 'payment_received';
    const TYPE_INSTALLMENT_PLAN = 'installment_plan';
    const TYPE_INSTALLMENT_DUE = 'installment_due';
    const TYPE_RECEPTION_LIST = 'reception_call_list';
    const TYPE_INVOICE_SENT = 'invoice_sent';
    // Treatment-plan routing: a plan sent to a doctor, or waiting for
    // reception to pick the doctor (TreatmentPlan::NOTIFY_TYPE).
    const TYPE_TREATMENT_PLAN = 'treatment_plan';
    const TYPE_SYSTEM = 'system';

    // Types only admins + accountants may see (financial)
    const FINANCIAL_TYPES = [
        'xray_payment', 'xray_payment_ready', 'payment_received',
        'payment_pending', 'installment_due', 'installment_plan'
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new notification
     */
    public function create($userId, $type, $title, $message, $data = null, $createdBy = null, $expiresAt = null, $link = null) {
        return $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ? json_encode($data) : null,
            'link' => $link,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt
        ]);
    }

    /**
     * Notify a list of user ids (skips the acting user). Fail-soft:
     * a notification failure must never break the main workflow.
     */
    public function notifyUsers(array $userIds, $type, $title, $message, $data = null, $link = null, $createdBy = null, $expiresAt = null) {
        $ids = [];
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0 || ($createdBy && $uid === (int)$createdBy)) {
                continue; // never notify the actor about their own action
            }
            try {
                $ids[] = $this->create($uid, $type, $title, $message, $data, $createdBy, $expiresAt, $link);
            } catch (Exception $e) {
                error_log('Notification::notifyUsers failed: ' . $e->getMessage());
            }
        }
        return $ids;
    }

    /**
     * Notify every active user having one of the given roles. Fail-soft.
     */
    public function notifyRole($roles, $type, $title, $message, $data = null, $link = null, $createdBy = null, $expiresAt = null) {
        try {
            $roles = (array)$roles;
            $placeholders = [];
            $params = [];
            foreach ($roles as $i => $role) {
                $placeholders[] = ":role{$i}";
                $params["role{$i}"] = $role;
            }
            $users = $this->db->fetchAll(
                "SELECT id FROM users WHERE role IN (" . implode(',', $placeholders) . ") AND is_active = 1",
                $params
            );
            return $this->notifyUsers(array_column($users, 'id'), $type, $title, $message, $data, $link, $createdBy, $expiresAt);
        } catch (Exception $e) {
            error_log('Notification::notifyRole failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get one notification by id, enforcing ownership.
     */
    public function getById($notificationId, $userId) {
        return $this->db->fetch(
            "SELECT n.*, u.full_name AS created_by_name
             FROM notifications n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.id = :id AND n.user_id = :user_id",
            ['id' => (int)$notificationId, 'user_id' => (int)$userId]
        );
    }

    /**
     * Persistent reception call-list inbox, scoped to one recipient.
     *
     * Each receptionist/admin receives their own notification row. Keeping the
     * user_id condition here is important: a recipient must never be able to
     * browse another user's private notification id or selected appointment set.
     */
    public function getReceptionCallLists($userId, array $filters = [], $limit = 40, $offset = 0) {
        $userId = (int)$userId;
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);

        $status = $filters['status'] ?? 'all';
        if (!in_array($status, ['all', 'unread', 'read'], true)) {
            $status = 'all';
        }

        $where = [
            'n.user_id = :user_id',
            'n.type = :type',
            '(n.expires_at IS NULL OR n.expires_at > NOW())',
        ];
        $params = [
            'user_id' => $userId,
            'type' => self::TYPE_RECEPTION_LIST,
        ];

        if ($status === 'unread') {
            $where[] = 'n.is_read = 0';
        } elseif ($status === 'read') {
            $where[] = 'n.is_read = 1';
        }

        $doctorId = (int)($filters['doctor_id'] ?? 0);
        if ($doctorId > 0) {
            $where[] = 'n.created_by = :doctor_id';
            $params['doctor_id'] = $doctorId;
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(u.full_name LIKE :query_doctor OR n.title LIKE :query_title OR n.message LIKE :query_message)';
            $params['query_doctor'] = '%' . $query . '%';
            $params['query_title'] = '%' . $query . '%';
            $params['query_message'] = '%' . $query . '%';
        }

        // `data` is LONGTEXT for backwards compatibility, so guard JSON reads.
        // Old rows without a list date fall back to the date they were sent.
        $listDateSql = "COALESCE(NULLIF(CASE WHEN JSON_VALID(n.data) THEN JSON_UNQUOTE(JSON_EXTRACT(n.data, '$.date')) ELSE NULL END, ''), DATE(n.created_at))";
        foreach (['from' => 'date_from', 'to' => 'date_to'] as $filterKey => $paramKey) {
            $value = trim((string)($filters[$filterKey] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $operator = $filterKey === 'from' ? '>=' : '<=';
                $where[] = "{$listDateSql} {$operator} :{$paramKey}";
                $params[$paramKey] = $value;
            }
        }

        $whereSql = implode(' AND ', $where);
        $totalRow = $this->db->fetch(
            "SELECT COUNT(*) AS total
             FROM notifications n
             LEFT JOIN users u ON u.id = n.created_by
             WHERE {$whereSql}",
            $params
        );

        $rows = $this->db->fetchAll(
            "SELECT n.*, COALESCE(u.full_name, '-') AS doctor_name,
                    {$listDateSql} AS list_date
             FROM notifications n
             LEFT JOIN users u ON u.id = n.created_by
             WHERE {$whereSql}
             ORDER BY list_date DESC, doctor_name ASC, n.created_at DESC, n.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $data = json_decode((string)($row['data'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            $row['data_decoded'] = $data;
            $row['doctor_id'] = (int)($data['doctor_id'] ?? $row['created_by'] ?? 0);
            $row['patient_count'] = max(0, (int)($data['count'] ?? 0));
            $row['list_note'] = trim((string)($data['note'] ?? ''));
            if (empty($row['link'])) {
                $baseLink = self::resolveLink(self::TYPE_RECEPTION_LIST, $data);
                $row['link'] = $baseLink
                    . (strpos($baseLink, '?') === false ? '?' : '&')
                    . 'n=' . (int)$row['id'];
            }
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => (int)($totalRow['total'] ?? 0),
        ];
    }

    /** Number shown beside "Reception call lists" in the sidebar. */
    public function getReceptionCallListUnreadCount($userId) {
        return $this->db->count(
            'notifications',
            'user_id = :user_id AND type = :type AND is_read = 0 '
                . 'AND (expires_at IS NULL OR expires_at > NOW())',
            ['user_id' => (int)$userId, 'type' => self::TYPE_RECEPTION_LIST]
        );
    }

    /**
     * Resolve the related page for a notification (fallback when the
     * `link` column is empty — covers rows created before v2).
     * Returns a path relative to APP_URL.
     */
    public static function resolveLink($type, $data) {
        $data = is_array($data) ? $data : (json_decode((string)$data, true) ?: []);
        switch ($type) {
            case self::TYPE_PATIENT_CALLED:
            case self::TYPE_PATIENT_WAITING:
                return '/waiting-room.php' . (!empty($data['waiting_id']) ? '?highlight=' . (int)$data['waiting_id'] : '');
            case self::TYPE_PATIENT_ACCOUNTING:
                return '/accountant/approval-center.php?tab=station';
            case self::TYPE_PATIENT_RETURNED:
                return '/waiting-room.php';
            case self::TYPE_CASE_COMPLETED:
                return !empty($data['case_id']) ? '/cases/view.php?id=' . (int)$data['case_id'] : '/cases/';
            case self::TYPE_XRAY_READY:
            case self::TYPE_XRAY:
                return !empty($data['xray_id']) ? '/xray/view.php?id=' . (int)$data['xray_id'] : '/xray/index.php';
            case self::TYPE_XRAY_PAYMENT:
            case 'xray_payment_ready':
                return '/xray/payments.php' . (!empty($data['xray_id']) ? '?highlight=' . (int)$data['xray_id'] : '');
            case self::TYPE_PAYMENT_PENDING:
                return '/admin/payments.php';
            case self::TYPE_PAYMENT_APPROVED:
            case self::TYPE_PAYMENT_REJECTED:
            case self::TYPE_PAYMENT_RECEIVED:
                return !empty($data['case_id']) ? '/cases/view.php?id=' . (int)$data['case_id'] : '/accountant/payments.php';
            case self::TYPE_APPOINTMENT_REMINDER:
            case self::TYPE_APPOINTMENT_NEW:
            case self::TYPE_APPOINTMENT_UPDATED:
            case self::TYPE_APPOINTMENT_CANCELLED:
                return '/appointments.php' . (!empty($data['date']) ? '?date=' . urlencode($data['date']) : '');
            case self::TYPE_APPOINTMENT_CHECKIN:
                return '/waiting-room.php';
            case self::TYPE_VISIT_CREATED:
                return !empty($data['visit_id']) ? '/visits.php?id=' . (int)$data['visit_id'] : '/visits.php';
            case self::TYPE_VISIT_COMPLETED:
            case self::TYPE_VISIT_CANCELLED:
                return '/waiting-room.php';
            case self::TYPE_INSTALLMENT_PLAN:
            case self::TYPE_INSTALLMENT_DUE:
                return '/installments.php';
            case self::TYPE_TREATMENT_PLAN:
                return !empty($data['plan_id'])
                    ? '/treatment-plans/view.php?id=' . (int)$data['plan_id']
                    : '/treatment-plans/index.php';
            case self::TYPE_RECEPTION_LIST:
                if (empty($data['doctor_id'])) {
                    return '/reception-call-list.php';
                }
                // The selected ids deliberately do NOT travel in the URL: `link` is
                // VARCHAR(255) and long lists were silently truncated back to a
                // whole-day list. notifyReceptionCallList() appends &n=<notification id>
                // instead, and the page reads the subset from notifications.data.
                return '/reception-call-list.php?doctor=' . (int)$data['doctor_id']
                    . (!empty($data['date']) ? '&date=' . urlencode($data['date']) : '');
            default:
                return '/notifications.php';
        }
    }

    /**
     * Create notification for patient being called (waiting room)
     */
    public function notifyPatientCalled($doctorId, $patientName, $waitingId, $calledBy = null) {
        if ($calledBy && (int)$doctorId === (int)$calledBy) {
            return null; // doctor called their own patient — no self-notification
        }
        $title = 'نەخۆش بانگکرا';
        $message = "نەخۆش {$patientName} بانگکرا بۆ ژووری تۆ";
        $data = [
            'waiting_id' => $waitingId,
            'patient_name' => $patientName,
            'action' => 'patient_called'
        ];

        // Expires in 1 hour
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        return $this->create(
            $doctorId,
            self::TYPE_PATIENT_CALLED,
            $title,
            $message,
            $data,
            $calledBy,
            $expiresAt,
            self::resolveLink(self::TYPE_PATIENT_CALLED, $data)
        );
    }

    /**
     * Create notification for new patient in waiting room
     */
    public function notifyPatientWaiting($doctorId, $patientName, $waitingId, $addedBy = null) {
        if ($addedBy && (int)$doctorId === (int)$addedBy) {
            return null; // doctor added a patient to their own queue — no self-notification
        }
        $title = 'نەخۆشی نوێ چاوەڕوانە';
        $message = "نەخۆش {$patientName} لە ژووری چاوەڕوانیدایە";
        $data = [
            'waiting_id' => $waitingId,
            'patient_name' => $patientName,
            'action' => 'patient_waiting'
        ];

        // Expires in 8 hours (workday)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));

        return $this->create(
            $doctorId,
            self::TYPE_PATIENT_WAITING,
            $title,
            $message,
            $data,
            $addedBy,
            $expiresAt,
            self::resolveLink(self::TYPE_PATIENT_WAITING, $data)
        );
    }

    /**
     * Create notification for X-ray ready
     */
    public function notifyXrayReady($doctorId, $patientName, $xrayId, $completedBy = null) {
        $title = 'ئێکسڕەی ئامادەیە';
        $message = "ئێکسڕەی {$patientName} ئامادەیە";
        $data = [
            'xray_id' => $xrayId,
            'patient_name' => $patientName,
            'action' => 'xray_ready'
        ];

        return $this->create(
            $doctorId,
            self::TYPE_XRAY_READY,
            $title,
            $message,
            $data,
            $completedBy
        );
    }

    /**
     * Create notification for X-ray payment ready
     */
    public function notifyXrayPayment($userId, $patientName, $xrayId, $amount = null, $createdBy = null) {
        $title = 'ئێکسڕەی ئامادەی پارەدان';
        $message = "ئێکسڕەی {$patientName} ئامادەیە بۆ پارەدان";
        if ($amount) {
            $message .= " - بڕی: " . number_format($amount) . " دینار";
        }
        $data = [
            'xray_id' => $xrayId,
            'patient_name' => $patientName,
            'amount' => $amount,
            'action' => 'xray_payment'
        ];

        return $this->create(
            $userId,
            self::TYPE_XRAY_PAYMENT,
            $title,
            $message,
            $data,
            $createdBy
        );
    }

    /**
     * Create notification for case completed (doctor finished with patient)
     * Sends to all admin users
     */
    public function notifyCaseCompleted($patientName, $doctorName, $caseId, $patientId, $notes = null, $nextAppointment = null, $doctorId = null) {
        $title = 'کەیسی نەخۆش تەواو بوو';
        $message = "نەخۆش {$patientName} لە لای دکتۆر {$doctorName} تەواو بوو";
        if ($notes) {
            $message .= "\nتێبینی: " . mb_substr($notes, 0, 100);
        }
        if ($nextAppointment) {
            $message .= "\nمەوعیدی داهاتوو: " . $nextAppointment;
        }

        $data = [
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'doctor_name' => $doctorName,
            'doctor_id' => $doctorId,
            'notes' => $notes,
            'next_appointment' => $nextAppointment,
            'action' => 'case_completed'
        ];

        // Notify all accountants + admins (excluding the acting doctor)
        return $this->notifyRole(
            ['accountant', 'admin'],
            self::TYPE_CASE_COMPLETED,
            $title,
            $message,
            $data,
            self::resolveLink(self::TYPE_CASE_COMPLETED, $data),
            $doctorId
        );
    }

    /**
     * Visit created — notify the assigned doctor.
     */
    public function notifyVisitCreated($doctorUserId, $patientName, $visitId, $patientId, $createdBy = null) {
        $data = ['visit_id' => $visitId, 'patient_id' => $patientId, 'patient_name' => $patientName];
        return $this->notifyUsers(
            [$doctorUserId],
            self::TYPE_VISIT_CREATED,
            'سەردانی نوێ تۆمارکرا',
            "سەردانی نوێ بۆ نەخۆش {$patientName} تۆمارکرا",
            $data,
            self::resolveLink(self::TYPE_VISIT_CREATED, $data),
            $createdBy,
            date('Y-m-d H:i:s', strtotime('+8 hours'))
        );
    }

    /**
     * Visit finished at a station (completed) or cancelled/rejected —
     * notify the doctor and reception (excluding the actor).
     */
    public function notifyVisitStatus($status, $doctorUserId, $patientName, $waitingId, $actorId = null, $reason = '') {
        $isCancel = ($status === 'cancelled' || $status === 'rejected');
        $type = $isCancel ? self::TYPE_VISIT_CANCELLED : self::TYPE_VISIT_COMPLETED;
        $title = $isCancel ? 'سەردان هەڵوەشایەوە' : 'سەردان تەواو بوو';
        $message = $isCancel
            ? "سەردانی نەخۆش {$patientName} هەڵوەشایەوە" . ($reason !== '' ? "\nهۆکار: {$reason}" : '')
            : "سەردانی نەخۆش {$patientName} تەواو بوو";
        $data = ['waiting_id' => $waitingId, 'patient_name' => $patientName, 'status' => $status];
        $link = self::resolveLink($type, $data);

        $ids = $this->notifyUsers([$doctorUserId], $type, $title, $message, $data, $link, $actorId,
            date('Y-m-d H:i:s', strtotime('+8 hours')));
        $ids = array_merge($ids, $this->notifyRole(['receptionist'], $type, $title, $message, $data, $link, $actorId,
            date('Y-m-d H:i:s', strtotime('+8 hours'))));
        return $ids;
    }

    /**
     * Appointment lifecycle — notify the doctor and reception (excluding the actor).
     * $event: 'created' | 'updated' | 'cancelled' | 'checkin' (converted to visit)
     */
    public function notifyAppointmentEvent($event, $doctorUserId, $patientName, $appointmentId, $date, $time = null, $actorId = null) {
        $map = [
            'created'   => [self::TYPE_APPOINTMENT_NEW, 'مەوعیدی نوێ', 'مەوعیدی نوێ بۆ نەخۆش'],
            'updated'   => [self::TYPE_APPOINTMENT_UPDATED, 'مەوعید نوێکرایەوە', 'مەوعیدی نەخۆش'],
            'cancelled' => [self::TYPE_APPOINTMENT_CANCELLED, 'مەوعید هەڵوەشایەوە', 'مەوعیدی نەخۆش'],
            'checkin'   => [self::TYPE_APPOINTMENT_CHECKIN, 'نەخۆش گەیشت (مەوعید)', 'نەخۆش'],
        ];
        if (!isset($map[$event])) {
            return [];
        }
        [$type, $title, $prefix] = $map[$event];

        $when = trim(($date ?: '') . ' ' . ($time ? substr($time, 0, 5) : ''));
        $message = "{$prefix} {$patientName}";
        if ($event === 'created') $message .= " زیادکرا";
        if ($event === 'updated') $message .= " گۆڕدرا";
        if ($event === 'cancelled') $message .= " هەڵوەشایەوە";
        if ($event === 'checkin') $message .= " هاتووە و چووە ژووری چاوەڕوانی";
        if ($when !== '' && $event !== 'checkin') $message .= " - کات: {$when}";

        $data = ['appointment_id' => $appointmentId, 'patient_name' => $patientName, 'date' => $date, 'time' => $time];
        $link = self::resolveLink($type, $data);

        // Each of these fans out to the doctor plus every receptionist, and an
        // appointment is typically edited several times before it happens — so
        // they were by far the largest slice of the table. They also carried no
        // expiry, which made them unprunable. A reminder about an appointment
        // is worthless once that appointment is past: expire it a day after,
        // but always allow at least 48h so same-day or back-dated bookings are
        // still seen.
        $expiresAt = null;
        $appointmentTs = $date ? strtotime($date . ' 23:59:59') : false;
        if ($appointmentTs !== false) {
            $expiresAt = date('Y-m-d H:i:s', max($appointmentTs + 86400, time() + 172800));
        }

        $ids = $this->notifyUsers([$doctorUserId], $type, $title, $message, $data, $link, $actorId, $expiresAt);
        $ids = array_merge($ids, $this->notifyRole(['receptionist'], $type, $title, $message, $data, $link, $actorId, $expiresAt));
        return $ids;
    }

    /**
     * Doctor sends a day's appointment list to reception as a call list.
     * Reception sees the list WITHOUT debt info (promised amount only).
     * $ids = specific appointment ids when the doctor picked a subset
     * (empty array = the whole day).
     */
    public function notifyReceptionCallList($doctorUserId, $doctorName, $date, $count, $note = '', $actorId = null, array $ids = []) {
        $title = 'لیستی مەوعید بۆ ڕیسێپشن';
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        $message = $ids
            ? "د. {$doctorName} {$count} نەخۆشی هەڵبژێردراوی بەرواری {$date} ی نارد"
            : "د. {$doctorName} لیستی مەوعیدەکانی {$date} ی نارد — {$count} نەخۆش";
        $message .= "\nتکایە پەیوەندییان پێوە بکە بۆ ئاگادارکردنەوە";
        if ($note !== '') {
            $message .= "\nتێبینی: " . mb_substr($note, 0, 150);
        }
        $data = [
            'doctor_id' => (int)$doctorUserId,
            'date' => $date,
            'count' => (int)$count,
            'ids' => $ids,
            'note' => trim((string)$note),
            'action' => 'reception_call_list'
        ];
        $link = self::resolveLink(self::TYPE_RECEPTION_LIST, $data);
        $created = $this->notifyRole(
            ['receptionist', 'admin'],
            self::TYPE_RECEPTION_LIST,
            $title,
            $message,
            $data,
            $link,
            $actorId
        );

        // Point each recipient's link at their own notification row, so the page
        // can read the selected ids back out of `data` (longtext, never truncated)
        // instead of carrying them in the 255-char link. Fail-soft: a failure here
        // only costs the subset filter, it must not break the send.
        if ($created) {
            foreach ($created as $nid) {
                try {
                    $this->db->update(
                        'notifications',
                        ['link' => $link . '&n=' . (int)$nid],
                        'id = ?',
                        [(int)$nid]
                    );
                } catch (Exception $e) {
                    error_log('notifyReceptionCallList link stamp failed: ' . $e->getMessage());
                }
            }
        }
        return $created;
    }

    /**
     * Payment requested (pending approval) — notify accountants + admins
     * (Task 8: was admins-only; the accounting approval center is where
     * these are actually worked, and accountants approve payments too).
     */
    public function notifyPaymentRequested($caseId, $patientName, $amount, $paymentId, $createdBy = null, $discountAmount = 0) {
        $data = ['case_id' => $caseId, 'payment_id' => $paymentId, 'patient_name' => $patientName, 'amount' => $amount, 'discount_amount' => $discountAmount];
        return $this->notifyRole(
            ['accountant', 'admin'],
            self::TYPE_PAYMENT_PENDING,
            $discountAmount > 0 ? 'داواکاری پارەدان و داشکاندن' : 'داواکاری پارەدان',
            "پارەدانی نوێ بۆ نەخۆش {$patientName} چاوەڕوانی پەسەندکردنە - بڕ: " . number_format((float)$amount)
                . ($discountAmount > 0 ? ' — داشکاندن: ' . number_format((float)$discountAmount) : ''),
            $data,
            '/accountant/approval-center.php?tab=payments',
            $createdBy
        );
    }

    /**
     * Invoice sent to accounting — notify accountants + admins (same
     * recipient-resolution mechanism as notifyCaseCompleted).
     */
    public function notifyInvoiceSent(int $caseId, string $invoiceNo, int $sentByUserId): void {
        $data = ['case_id' => $caseId, 'invoice_no' => $invoiceNo, 'action' => 'invoice_sent'];
        $this->notifyRole(
            ['accountant', 'admin'],
            self::TYPE_INVOICE_SENT,
            'پسووڵەی نوێ',
            "پسووڵەی {$invoiceNo} نێردرا بۆ ژمێریاری",
            $data,
            '/accountant/approval-center.php?tab=invoices&q=' . urlencode($invoiceNo),
            $sentByUserId
        );
    }

    /**
     * The doctor took a sent receipt back before it was approved — tell
     * accounting it left their queue so nobody goes looking for it.
     */
    public function notifyInvoiceRecalled(int $caseId, string $invoiceNo, int $recalledByUserId): void {
        $data = ['case_id' => $caseId, 'invoice_no' => $invoiceNo, 'action' => 'invoice_recalled'];
        $this->notifyRole(
            ['accountant', 'admin'],
            self::TYPE_INVOICE_SENT,
            'پسووڵە کانسڵکرایەوە',
            "دکتۆر پسووڵەی {$invoiceNo} کانسڵ کردەوە بۆ دەستکاریکردن — دواتر دووبارە دەینێرێتەوە",
            $data,
            '/accountant/approval-center.php?tab=invoices',
            $recalledByUserId
        );
    }

    /**
     * Installment plan activated/rejected by the accountant/admin — notify
     * the proposing doctor. $decision: 'activated' | 'rejected'.
     */
    public function notifyInstallmentDecision(int $planId, string $decision, int $doctorUserId, string $reason = ''): void {
        $activated = $decision === 'activated';
        $title = $activated
            ? 'قیستەکە پەسەندکرا'
            : 'قیستەکە ڕەتکرایەوە' . ($reason !== '' ? ': ' . $reason : '');
        $message = $activated
            ? 'پلانی قیستەکە پەسەندکرا و خشتەی قیستەکان ئامادەکرا'
            : ('پلانی قیستەکە ڕەتکرایەوە' . ($reason !== '' ? "\nهۆکار: {$reason}" : ''));
        $data = ['plan_id' => $planId, 'decision' => $decision, 'reason' => $reason, 'action' => 'installment_decision'];

        $this->notifyUsers(
            [$doctorUserId],
            self::TYPE_INSTALLMENT_PLAN,
            $title,
            $message,
            $data,
            self::resolveLink(self::TYPE_INSTALLMENT_PLAN, $data)
        );
    }

    /**
     * Payment approved / rejected — notify the case doctor + accountants.
     */
    public function notifyPaymentResult($approved, $caseId, $patientName, $amount, $doctorUserId, $actorId = null) {
        $type = $approved ? self::TYPE_PAYMENT_APPROVED : self::TYPE_PAYMENT_REJECTED;
        $title = $approved ? 'پارەدان پەسەندکرا' : 'پارەدان ڕەتکرایەوە';
        $message = ($approved ? "پارەدانی نەخۆش {$patientName} پەسەندکرا" : "پارەدانی نەخۆش {$patientName} ڕەتکرایەوە")
                 . " - بڕ: " . number_format((float)$amount);
        $data = ['case_id' => $caseId, 'patient_name' => $patientName, 'amount' => $amount];
        $link = self::resolveLink($type, $data);

        $ids = $this->notifyUsers([$doctorUserId], $type, $title, $message, $data, $link, $actorId);
        $ids = array_merge($ids, $this->notifyRole(['accountant'], $type, $title, $message, $data, $link, $actorId));
        return $ids;
    }

    /**
     * Direct/completed payment received — notify admins + accountants.
     */
    public function notifyPaymentReceived($caseId, $patientName, $amount, $actorId = null) {
        $data = ['case_id' => $caseId, 'patient_name' => $patientName, 'amount' => $amount];
        return $this->notifyRole(
            ['admin', 'accountant'],
            self::TYPE_PAYMENT_RECEIVED,
            'پارە وەرگیرا',
            "پارە وەرگیرا لە نەخۆش {$patientName} - بڕ: " . number_format((float)$amount),
            $data,
            self::resolveLink(self::TYPE_PAYMENT_RECEIVED, $data),
            $actorId
        );
    }

    /**
     * New installment plan created — notify admins + accountants.
     */
    public function notifyInstallmentPlan($caseId, $patientName, $totalAmount, $months, $actorId = null) {
        $data = ['case_id' => $caseId, 'patient_name' => $patientName, 'amount' => $totalAmount, 'months' => $months];
        return $this->notifyRole(
            ['admin', 'accountant'],
            self::TYPE_INSTALLMENT_PLAN,
            'پلانی قیستی نوێ',
            "پلانی قیست بۆ نەخۆش {$patientName} دروستکرا - کۆی گشتی: " . number_format((float)$totalAmount) . " بۆ {$months} مانگ",
            $data,
            self::resolveLink(self::TYPE_INSTALLMENT_PLAN, $data),
            $actorId
        );
    }

    /**
     * X-ray workflow notifications — single entry point for every stage
     * XrayManager's private notifyXrayDepartment / notifyDoctor /
     * notifyXrayDepartmentToScan methods used to insert raw rows for
     * (Task 8: those now delegate here, same recipients + links, except the
     * accountant-facing link now points at the approval center instead of
     * pending-reports.php).
     *
     * $stage:
     *   'to_accountant' — new request routed to accounting first (paid/debt
     *                     path) — notify admin + accountant.
     *   'to_xray'       — new request routed straight to x-ray (free path)
     *                     — notify admin + xray_department.
     *   'to_doctor'     — request completed — notify the requesting doctor.
     *   'to_scan'       — accountant approved payment — notify xray_department
     *                     to start the scan.
     * $ctx: ['request_number' => string, 'patient_name' => string (optional),
     *        'doctor_user_id' => int (required for 'to_doctor')]
     */
    public function notifyXrayWorkflow(int $requestId, string $stage, array $ctx): void {
        $requestNumber = $ctx['request_number'] ?? '';
        $patientName = $ctx['patient_name'] ?? '';
        $data = ['xray_id' => $requestId, 'request_number' => $requestNumber, 'patient_name' => $patientName];

        switch ($stage) {
            case 'to_accountant':
                $this->notifyRole(
                    ['admin', 'accountant'],
                    self::TYPE_XRAY,
                    'داواکاری ئێکسڕەی بۆ ژمێریار',
                    "دکتۆر داواکاری ئێکسڕەی {$requestNumber} بۆ {$patientName} ناردووە بۆ پشتڕاستکردنەوەی پارەدان.",
                    $data,
                    '/accountant/approval-center.php?tab=xray&q=' . urlencode($requestNumber)
                );
                break;

            case 'to_xray':
                $this->notifyRole(
                    ['admin', 'xray_department'],
                    self::TYPE_XRAY,
                    'داواکاری ئێکسڕەی نوێ',
                    "داواکاری ئێکسڕەی نوێ {$requestNumber} بۆ {$patientName} ئامادەیە.",
                    $data,
                    '/xray/view.php?id=' . $requestId
                );
                break;

            case 'to_doctor':
                $doctorUserId = (int)($ctx['doctor_user_id'] ?? 0);
                if ($doctorUserId > 0) {
                    $this->notifyUsers(
                        [$doctorUserId],
                        self::TYPE_XRAY,
                        'ئێکسڕەی تەواوبوو',
                        "ئێکسڕەی {$requestNumber} تەواوبوو",
                        $data,
                        '/xray/view.php?id=' . $requestId
                    );
                }
                break;

            case 'to_scan':
                $this->notifyRole(
                    ['xray_department'],
                    self::TYPE_XRAY,
                    'ئێکسڕەی ئامادەی سکان',
                    "پارەدانی ئێکسڕەی {$requestNumber} بۆ {$patientName} تەواوبوو - تکایە سکانەکە دەست پێبکە",
                    $data,
                    '/xray/view.php?id=' . $requestId
                );
                break;
        }
    }

    /**
     * Daily installment reminder — one digest per day for admins + accountants.
     * Self-throttled: skips if today's reminder already exists. Fail-soft.
     */
    public function generateInstallmentReminders() {
        try {
            $already = $this->db->fetch(
                "SELECT id FROM notifications
                 WHERE type = :type AND DATE(created_at) = CURDATE() LIMIT 1",
                ['type' => self::TYPE_INSTALLMENT_DUE]
            );
            if ($already) {
                return [];
            }

            $row = $this->db->fetch(
                "SELECT
                    SUM(CASE WHEN due_date = CURDATE() THEN 1 ELSE 0 END) AS due_today,
                    SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue
                 FROM installment_schedule
                 WHERE status IN ('pending', 'partial')"
            );
            $dueToday = (int)($row['due_today'] ?? 0);
            $overdue = (int)($row['overdue'] ?? 0);
            if ($dueToday === 0 && $overdue === 0) {
                return [];
            }

            $parts = [];
            if ($dueToday > 0) $parts[] = "ئەمڕۆ {$dueToday} قیست کاتی پارەدانیەتی";
            if ($overdue > 0) $parts[] = "{$overdue} قیست دواکەوتووە";
            $data = ['due_today' => $dueToday, 'overdue' => $overdue, 'date' => date('Y-m-d')];

            return $this->notifyRole(
                ['admin', 'accountant'],
                self::TYPE_INSTALLMENT_DUE,
                'بیرخستنەوەی قیست',
                implode(' و ', $parts),
                $data,
                self::resolveLink(self::TYPE_INSTALLMENT_DUE, $data),
                null,
                date('Y-m-d H:i:s', strtotime('+2 days'))
            );
        } catch (Exception $e) {
            error_log('Notification::generateInstallmentReminders failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread($userId, $limit = 20) {
        $sql = "SELECT n.*, u.full_name as created_by_name
                FROM notifications n
                LEFT JOIN users u ON n.created_by = u.id
                WHERE n.user_id = :user_id
                AND n.is_read = 0
                AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY n.created_at DESC
                LIMIT :limit";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get the latest notifications (read + unread) for the dropdown
     */
    public function getRecent($userId, $limit = 15) {
        $sql = "SELECT n.*, u.full_name as created_by_name
                FROM notifications n
                LEFT JOIN users u ON n.created_by = u.id
                WHERE n.user_id = :user_id
                AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY n.is_read ASC, n.created_at DESC
                LIMIT :limit";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get new notifications since last check (for polling)
     */
    public function getNewSince($userId, $lastCheck = null) {
        if (!$lastCheck) {
            // Get user's last check time
            $user = $this->db->fetch(
                "SELECT last_notification_check FROM users WHERE id = :id",
                ['id' => $userId]
            );
            $lastCheck = $user['last_notification_check'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
        }

        $sql = "SELECT n.*, u.full_name as created_by_name
                FROM notifications n
                LEFT JOIN users u ON n.created_by = u.id
                WHERE n.user_id = :user_id
                AND n.created_at > :last_check
                AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY n.created_at DESC";

        $notifications = $this->db->fetchAll($sql, [
            'user_id' => $userId,
            'last_check' => $lastCheck
        ]);

        // Update last check time
        $this->db->update(
            'users',
            ['last_notification_check' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $userId]
        );

        return $notifications;
    }

    /**
     * Get unread count for a user
     * @param int $userId
     * @param array|null $excludeTypes Types to exclude (for non-admin users)
     */
    public function getUnreadCount($userId, $excludeTypes = null) {
        $where = "user_id = :user_id AND is_read = 0 AND (expires_at IS NULL OR expires_at > NOW())";
        $params = ['user_id' => $userId];

        if ($excludeTypes && is_array($excludeTypes) && count($excludeTypes) > 0) {
            $placeholders = [];
            foreach ($excludeTypes as $i => $type) {
                $key = "exclude_type_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $type;
            }
            $where .= " AND type NOT IN (" . implode(', ', $placeholders) . ")";
        }

        return $this->db->count('notifications', $where, $params);
    }

    /**
     * Get unseen count for badge (new since last check)
     * @param int $userId
     * @param array|null $excludeTypes Types to exclude (for non-admin users)
     */
    public function getUnseenCount($userId, $excludeTypes = null) {
        try {
            $where = "user_id = :user_id AND is_seen = 0 AND (expires_at IS NULL OR expires_at > NOW())";
            $params = ['user_id' => $userId];

            if ($excludeTypes && is_array($excludeTypes) && count($excludeTypes) > 0) {
                $placeholders = [];
                foreach ($excludeTypes as $i => $type) {
                    $key = "exclude_type_{$i}";
                    $placeholders[] = ":{$key}";
                    $params[$key] = $type;
                }
                $where .= " AND type NOT IN (" . implode(', ', $placeholders) . ")";
            }

            return $this->db->count('notifications', $where, $params);
        } catch (Exception $e) {
            // Table might not exist yet
            return 0;
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId = null) {
        $where = 'id = :id';
        $params = ['id' => $notificationId];

        if ($userId) {
            $where .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return $this->db->update(
            'notifications',
            ['is_read' => 1, 'is_seen' => 1, 'read_at' => date('Y-m-d H:i:s')],
            $where,
            $params
        );
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        return $this->db->query(
            "UPDATE notifications SET is_read = 1, is_seen = 1, read_at = NOW()
             WHERE user_id = :user_id AND is_read = 0",
            ['user_id' => $userId]
        );
    }

    /**
     * Mark all notifications as seen (dropdown opened)
     */
    public function markAllAsSeen($userId) {
        return $this->db->query(
            "UPDATE notifications SET is_seen = 1 WHERE user_id = :user_id AND is_seen = 0",
            ['user_id' => $userId]
        );
    }

    /**
     * Delete a notification
     */
    public function delete($notificationId, $userId = null) {
        $where = 'id = :id';
        $params = ['id' => $notificationId];

        if ($userId) {
            $where .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return $this->db->delete('notifications', $where, $params);
    }

    /**
     * Delete expired notifications (cleanup)
     */
    public function cleanupExpired() {
        return $this->db->query(
            "DELETE FROM notifications WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    // ── Retention ─────────────────────────────────────────────────────────
    // Nothing pruned this table, so it reached 52k rows (30k of which carry a
    // NULL expires_at and could never be removed by cleanupExpired() alone).
    // A notification is a transient nudge: once it is read, or once it is old
    // enough that nobody will act on it, it is only ballast behind every
    // COUNT(*) badge and every dropdown query.
    const RETENTION_READ_DAYS = 30;  // read notifications: kept a month
    const RETENTION_ANY_DAYS  = 90;  // anything at all: hard ceiling
    const CLEANUP_BATCH       = 2000; // rows per DELETE — see note below

    /**
     * Prune expired and over-retention notifications, at most once per
     * $minIntervalSeconds across all requests.
     *
     * Deletes run in small batches: one unbounded DELETE over tens of
     * thousands of rows holds row locks long enough to stall the clinic's
     * other writers, whereas short batches let them interleave.
     *
     * Fail-soft by design — this is housekeeping invoked from the notification
     * poll, and it must never turn into a visible error for the user.
     *
     * @return int Rows deleted (0 when throttled or on error).
     */
    public function cleanupExpiredThrottled($minIntervalSeconds = 3600) {
        $marker = APP_ROOT . '/storage/notifications_cleanup.at';

        if (is_file($marker) && (time() - (int)@filemtime($marker)) < $minIntervalSeconds) {
            return 0;
        }

        // Claim the slot *before* the work: several tabs poll at the same
        // moment, and without this they would all pass the check above and
        // run the same deletes concurrently.
        if (@touch($marker) === false) {
            return 0; // unwritable storage dir — skip rather than retry hard
        }

        $passes = [
            "DELETE FROM notifications
              WHERE expires_at IS NOT NULL AND expires_at < NOW()
              LIMIT " . self::CLEANUP_BATCH,
            "DELETE FROM notifications
              WHERE is_read = 1 AND created_at < NOW() - INTERVAL " . self::RETENTION_READ_DAYS . " DAY
              LIMIT " . self::CLEANUP_BATCH,
            "DELETE FROM notifications
              WHERE created_at < NOW() - INTERVAL " . self::RETENTION_ANY_DAYS . " DAY
              LIMIT " . self::CLEANUP_BATCH,
        ];

        $deleted = 0;
        try {
            foreach ($passes as $sql) {
                // Cap the batches per pass so one poll request can never run
                // long; whatever is left is picked up by the next run.
                for ($i = 0; $i < 5; $i++) {
                    $n = $this->db->query($sql)->rowCount();
                    $deleted += $n;
                    if ($n < self::CLEANUP_BATCH) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            return $deleted;
        }

        return $deleted;
    }

    /**
     * Get all notifications for a user (paginated)
     */
    public function getAll($userId, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT n.*, u.full_name as created_by_name
                FROM notifications n
                LEFT JOIN users u ON n.created_by = u.id
                WHERE n.user_id = :user_id
                AND (n.expires_at IS NULL OR n.expires_at > NOW())
                ORDER BY n.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get user notification settings
     */
    public function getSettings($userId) {
        $settings = $this->db->fetch(
            "SELECT * FROM notification_settings WHERE user_id = :user_id",
            ['user_id' => $userId]
        );

        if (!$settings) {
            // Return default settings
            return [
                'user_id' => $userId,
                'sound_enabled' => 1,
                'toast_enabled' => 1,
                'patient_called_enabled' => 1,
                'patient_waiting_enabled' => 1,
                'xray_ready_enabled' => 1
            ];
        }

        return $settings;
    }

    /**
     * Update user notification settings
     */
    public function updateSettings($userId, $settings) {
        $existing = $this->db->fetch(
            "SELECT id FROM notification_settings WHERE user_id = :user_id",
            ['user_id' => $userId]
        );

        $data = [
            'sound_enabled' => $settings['sound_enabled'] ?? 1,
            'toast_enabled' => $settings['toast_enabled'] ?? 1,
            'patient_called_enabled' => $settings['patient_called_enabled'] ?? 1,
            'patient_waiting_enabled' => $settings['patient_waiting_enabled'] ?? 1,
            'xray_ready_enabled' => $settings['xray_ready_enabled'] ?? 1
        ];

        if ($existing) {
            return $this->db->update(
                'notification_settings',
                $data,
                'user_id = :user_id',
                ['user_id' => $userId]
            );
        } else {
            $data['user_id'] = $userId;
            return $this->db->insert('notification_settings', $data);
        }
    }

    /**
     * Get notification type icon
     */
    public static function getTypeIcon($type) {
        $icons = [
            self::TYPE_PATIENT_CALLED => 'fa-bell',
            self::TYPE_PATIENT_WAITING => 'fa-user-clock',
            self::TYPE_PATIENT_ACCOUNTING => 'fa-cash-register',
            self::TYPE_PATIENT_RETURNED => 'fa-undo',
            self::TYPE_XRAY_READY => 'fa-x-ray',
            self::TYPE_XRAY_PAYMENT => 'fa-money-bill-wave',
            self::TYPE_XRAY => 'fa-x-ray',
            self::TYPE_CASE_COMPLETED => 'fa-check-circle',
            self::TYPE_APPOINTMENT_REMINDER => 'fa-calendar-check',
            self::TYPE_APPOINTMENT_NEW => 'fa-calendar-plus',
            self::TYPE_APPOINTMENT_UPDATED => 'fa-calendar-day',
            self::TYPE_APPOINTMENT_CANCELLED => 'fa-calendar-times',
            self::TYPE_APPOINTMENT_CHECKIN => 'fa-user-check',
            self::TYPE_VISIT_CREATED => 'fa-notes-medical',
            self::TYPE_VISIT_COMPLETED => 'fa-check-double',
            self::TYPE_VISIT_CANCELLED => 'fa-ban',
            self::TYPE_PAYMENT_PENDING => 'fa-hourglass-half',
            self::TYPE_PAYMENT_APPROVED => 'fa-check-circle',
            self::TYPE_PAYMENT_REJECTED => 'fa-times-circle',
            self::TYPE_PAYMENT_RECEIVED => 'fa-money-bill-wave',
            self::TYPE_INSTALLMENT_PLAN => 'fa-file-invoice-dollar',
            self::TYPE_INSTALLMENT_DUE => 'fa-clock',
            self::TYPE_RECEPTION_LIST => 'fa-phone-volume',
            self::TYPE_INVOICE_SENT => 'fa-file-invoice',
            self::TYPE_TREATMENT_PLAN => 'fa-clipboard-list',
            self::TYPE_SYSTEM => 'fa-info-circle'
        ];

        return $icons[$type] ?? 'fa-bell';
    }

    /**
     * Get notification type color
     */
    public static function getTypeColor($type) {
        $colors = [
            self::TYPE_PATIENT_CALLED => '#0078d4',
            self::TYPE_PATIENT_WAITING => '#107c10',
            self::TYPE_PATIENT_ACCOUNTING => '#986f0b',
            self::TYPE_PATIENT_RETURNED => '#5c2d91',
            self::TYPE_XRAY_READY => '#8764b8',
            self::TYPE_XRAY_PAYMENT => '#e74c3c',
            self::TYPE_XRAY => '#8764b8',
            self::TYPE_CASE_COMPLETED => '#28a745',
            self::TYPE_APPOINTMENT_REMINDER => '#ff8c00',
            self::TYPE_APPOINTMENT_NEW => '#0078d4',
            self::TYPE_APPOINTMENT_UPDATED => '#ff8c00',
            self::TYPE_APPOINTMENT_CANCELLED => '#c50f1f',
            self::TYPE_APPOINTMENT_CHECKIN => '#107c10',
            self::TYPE_VISIT_CREATED => '#0078d4',
            self::TYPE_VISIT_COMPLETED => '#107c10',
            self::TYPE_VISIT_CANCELLED => '#c50f1f',
            self::TYPE_PAYMENT_PENDING => '#ff8c00',
            self::TYPE_PAYMENT_APPROVED => '#107c10',
            self::TYPE_PAYMENT_REJECTED => '#c50f1f',
            self::TYPE_PAYMENT_RECEIVED => '#107c10',
            self::TYPE_INSTALLMENT_PLAN => '#986f0b',
            self::TYPE_INSTALLMENT_DUE => '#e74c3c',
            self::TYPE_RECEPTION_LIST => '#0d9488',
            self::TYPE_INVOICE_SENT => '#0078d4',
            self::TYPE_TREATMENT_PLAN => '#5e35b1',
            self::TYPE_SYSTEM => '#5c5c5c'
        ];

        return $colors[$type] ?? '#0078d4';
    }

    /**
     * Get current patient in progress for a doctor
     */
    public function getCurrentPatient($doctorId) {
        $sql = "SELECT wr.*, p.full_name, p.phone, p.patient_code, p.gender,
                       TIMESTAMPDIFF(MINUTE, wr.check_in_time, NOW()) as waiting_minutes
                FROM waiting_room wr
                LEFT JOIN patients p ON wr.patient_id = p.id
                WHERE wr.doctor_id = :doctor_id
                AND wr.status = 'in_progress'
                AND DATE(wr.check_in_time) = CURDATE()
                ORDER BY wr.check_in_time DESC
                LIMIT 1";

        return $this->db->fetch($sql, ['doctor_id' => $doctorId]);
    }

    /**
     * Get waiting queue for a doctor
     */
    public function getWaitingQueue($doctorId, $limit = 5) {
        $sql = "SELECT wr.*, p.full_name, p.patient_code,
                       TIMESTAMPDIFF(MINUTE, wr.check_in_time, NOW()) as waiting_minutes
                FROM waiting_room wr
                LEFT JOIN patients p ON wr.patient_id = p.id
                WHERE wr.doctor_id = :doctor_id
                AND wr.status = 'waiting'
                AND DATE(wr.check_in_time) = CURDATE()
                ORDER BY wr.priority DESC, wr.check_in_time ASC
                LIMIT :limit";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':doctor_id', $doctorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
