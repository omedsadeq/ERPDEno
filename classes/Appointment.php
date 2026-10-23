<?php
/**
 * Appointment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DoctorSchedule.php';
require_once __DIR__ . '/XrayManager.php';

class Appointment {
    private $db;
    private $scheduleManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->scheduleManager = new DoctorSchedule();
    }

    /**
     * Create new appointment
     */
    public function create($data) {
        if (empty($data['appointment_date']) || empty($data['appointment_time'])) {
            return ['success' => false, 'message' => 'بەروار و کات داواکراون'];
        }

        // Overlapping appointments are allowed by clinic policy:
        // a doctor may book several patients in the same time slot.

        // Always record which user created the appointment
        if (empty($data['created_by']) && !empty($_SESSION['user_id'])) {
            $data['created_by'] = (int)$_SESSION['user_id'];
        }

        try {
            $appointmentId = $this->db->insert('appointments', $data);
            return ['success' => true, 'appointment_id' => $appointmentId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update appointment
     */
    public function update($id, $data) {
        // Overlapping appointments are allowed by clinic policy — no conflict check.

        // Always record which user made the change
        if (empty($data['updated_by']) && !empty($_SESSION['user_id'])) {
            $data['updated_by'] = (int)$_SESSION['user_id'];
        }

        try {
            $this->db->update('appointments', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check for appointment conflict.
     * NOTE: no longer enforced on create/update/reschedule — overlapping
     * bookings for one doctor are allowed. Kept for informational use.
     */
    public function hasConflict($doctorId, $date, $time, $duration = 30, $excludeId = null) {
        try {
            $startTime = new DateTime($date . ' ' . $time);
        } catch (Exception $e) {
            return false;
        }
        $endTime = clone $startTime;
        $endTime->modify('+' . $duration . ' minutes');

        $sql = "SELECT COUNT(*) as count FROM appointments
                WHERE doctor_id = :doctor_id
                AND appointment_date = :date
                AND status NOT IN ('cancelled', 'no_show')
                AND (
                    (appointment_time >= :start_time AND appointment_time < :end_time)
                    OR (ADDTIME(appointment_time, SEC_TO_TIME(duration * 60)) > :start_time2
                        AND appointment_time < :end_time2)
                )";
        $params = [
            'doctor_id' => $doctorId,
            'date' => $date,
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'start_time2' => $startTime->format('H:i:s'),
            'end_time2' => $endTime->format('H:i:s')
        ];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        return $this->db->fetch($sql, $params)['count'] > 0;
    }

    /**
     * Get appointment by ID
     */
    public function getById($id) {
        $sql = "SELECT a.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                u.full_name as doctor_name,
                caller.full_name as called_to_center_by_name
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.doctor_id = u.id
                LEFT JOIN users caller ON a.called_to_center_by = caller.id
                WHERE a.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get appointments for a day
     */
    public function getByDate($date, $doctorId = null) {
        $sql = "SELECT a.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                u.full_name as doctor_name,
                caller.full_name AS called_to_center_by_name,
                wt.work_name AS work_type_name,
                (
                    COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                              FROM patient_folders pf WHERE pf.patient_id = a.patient_id), 0)
                  + COALESCE((SELECT SUM(xr.final_amount)
                              FROM xray_requests xr WHERE xr.patient_id = a.patient_id
                              AND xr.status='completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
                ) AS total_debt
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.doctor_id = u.id
                LEFT JOIN users caller ON a.called_to_center_by = caller.id
                LEFT JOIN work_types wt ON wt.id = a.work_type_id
                WHERE a.appointment_date = :date";
        $params = ['date' => $date];

        if ($doctorId) {
            $sql .= " AND a.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY a.appointment_time";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get appointments for a week or date range
     */
    public function getByWeek($startDate, $endDate, $doctorId = null) {
        $sql = "SELECT a.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                u.full_name as doctor_name,
                caller.full_name AS called_to_center_by_name,
                wt.work_name AS work_type_name,
                (
                    COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                              FROM patient_folders pf WHERE pf.patient_id = a.patient_id), 0)
                  + COALESCE((SELECT SUM(xr.final_amount)
                              FROM xray_requests xr WHERE xr.patient_id = a.patient_id
                              AND xr.status='completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
                ) AS total_debt
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.doctor_id = u.id
                LEFT JOIN users caller ON a.called_to_center_by = caller.id
                LEFT JOIN work_types wt ON wt.id = a.work_type_id
                WHERE a.appointment_date BETWEEN :start_date AND :end_date";
        $params = ['start_date' => $startDate, 'end_date' => $endDate];

        if ($doctorId) {
            $sql .= " AND a.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY a.appointment_date, a.appointment_time";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get today's appointments
     */
    public function getToday($doctorId = null) {
        return $this->getByDate(date('Y-m-d'), $doctorId);
    }

    /**
     * Get appointments by patient
     */
    public function getByPatient($patientId) {
        $sql = "SELECT a.*, u.full_name as doctor_name
                FROM appointments a
                LEFT JOIN users u ON a.doctor_id = u.id
                WHERE a.patient_id = :patient_id
                ORDER BY a.appointment_date DESC, a.appointment_time DESC";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get appointments by patient and specific doctor
     * Used for doctor-specific view (privacy system)
     */
    public function getByPatientAndDoctor($patientId, $doctorId) {
        $sql = "SELECT a.*, u.full_name as doctor_name
                FROM appointments a
                LEFT JOIN users u ON a.doctor_id = u.id
                WHERE a.patient_id = :patient_id AND a.doctor_id = :doctor_id
                ORDER BY a.appointment_date DESC, a.appointment_time DESC";
        return $this->db->fetchAll($sql, [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId
        ]);
    }

    /**
     * Get appointments by doctor
     */
    public function getByDoctor($doctorId, $status = null, $page = 1, $limit = ITEMS_PER_PAGE) {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT a.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                WHERE a.doctor_id = :doctor_id";
        $params = ['doctor_id' => $doctorId];

        if ($status) {
            $sql .= " AND a.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Update appointment status
     */
    public function updateStatus($id, $status) {
        return $this->update($id, ['status' => $status]);
    }

    /**
     * Record the latest successful phone call inviting the patient to center.
     * AuditLog at the controller layer preserves every previous call.
     */
    public function markCalledToCenter($id, $userId) {
        if ((int)$id <= 0 || (int)$userId <= 0) {
            return ['success' => false, 'message' => 'Invalid appointment or user.'];
        }
        return $this->update((int)$id, [
            'called_to_center_at' => date('Y-m-d H:i:s'),
            'called_to_center_by' => (int)$userId,
            'updated_by' => (int)$userId,
        ]);
    }

    /** Clear an accidental call mark without deleting its AuditLog history. */
    public function clearCalledToCenter($id, $userId) {
        if ((int)$id <= 0 || (int)$userId <= 0) {
            return ['success' => false, 'message' => 'Invalid appointment or user.'];
        }
        return $this->update((int)$id, [
            'called_to_center_at' => null,
            'called_to_center_by' => null,
            'updated_by' => (int)$userId,
        ]);
    }

    /**
     * Cancel appointment
     */
    public function cancel($id, $reason = '') {
        return $this->update($id, [
            'status' => 'cancelled',
            'notes' => $reason
        ]);
    }

    /**
     * Mark as no-show
     */
    public function markNoShow($id) {
        return $this->update($id, ['status' => 'no_show']);
    }

    /**
     * Get available time slots
     * Uses DoctorSchedule for proper availability calculation
     * Formula: Free Slots = Working Hours - Breaks - Existing Appointments
     */
    public function getAvailableSlots($doctorId, $date, $duration = 30) {
        return $this->scheduleManager->getAvailableSlots($doctorId, $date, $duration);
    }

    /**
     * Get all time slots with status (for visual display)
     */
    public function getAllSlotsWithStatus($doctorId, $date, $duration = 30) {
        return $this->scheduleManager->getAllSlotsWithStatus($doctorId, $date, $duration);
    }

    /**
     * Check if doctor is working on a specific date
     */
    public function isDoctorWorking($doctorId, $date) {
        return $this->scheduleManager->isWorkingDay($doctorId, $date);
    }

    /**
     * Get calendar events for FullCalendar
     */
    public function getCalendarEvents($doctorId, $startDate, $endDate) {
        $events = [];

        // Get appointments
        $appointments = $this->getByWeek($startDate, $endDate, $doctorId);

        // Status colors
        $statusColors = [
            'scheduled' => '#ff8c00',
            'confirmed' => '#0078d4',
            'waiting' => '#ff9800',
            'in_progress' => '#2196f3',
            'completed' => '#4caf50',
            'cancelled' => '#9e9e9e',
            'no_show' => '#f44336'
        ];

        foreach ($appointments as $apt) {
            $endTime = date('Y-m-d\TH:i:s', strtotime($apt['appointment_date'] . ' ' . $apt['appointment_time']) + ($apt['duration'] * 60));

            $events[] = [
                'id' => 'apt_' . $apt['id'],
                'title' => $apt['patient_name'],
                'start' => $apt['appointment_date'] . 'T' . $apt['appointment_time'],
                'end' => $endTime,
                'backgroundColor' => $statusColors[$apt['status']] ?? '#757575',
                'borderColor' => $statusColors[$apt['status']] ?? '#757575',
                'extendedProps' => [
                    'appointmentId' => $apt['id'],
                    'patientId' => $apt['patient_id'],
                    'patientName' => $apt['patient_name'],
                    'patientPhone' => $apt['patient_phone'] ?? '',
                    'doctorId' => $apt['doctor_id'],
                    'doctorName' => $apt['doctor_name'],
                    'status' => $apt['status'],
                    'type' => $apt['type'],
                    'duration' => $apt['duration'],
                    'notes' => $apt['notes'] ?? ''
                ]
            ];
        }

        return $events;
    }

    /**
     * Delete appointment
     */
    public function delete($id) {
        try {
            $this->db->delete('appointments', 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get statistics
     */
    public function getStatistics($doctorId = null, $dateFrom = null, $dateTo = null) {
        $stats = [];
        $params = [];
        $where = "1=1";

        if ($doctorId) {
            $where .= " AND doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        if ($dateFrom) {
            $where .= " AND appointment_date >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= " AND appointment_date <= :date_to";
            $params['date_to'] = $dateTo;
        }

        // Count by status
        $sql = "SELECT status, COUNT(*) as count FROM appointments WHERE {$where} GROUP BY status";
        $byStatus = $this->db->fetchAll($sql, $params);
        $stats['by_status'] = [];
        foreach ($byStatus as $status) {
            $stats['by_status'][$status['status']] = $status['count'];
        }

        // Total appointments
        $sql = "SELECT COUNT(*) as total FROM appointments WHERE {$where}";
        $stats['total'] = $this->db->fetch($sql, $params)['total'];

        // Today's appointments
        $sql = "SELECT COUNT(*) as today FROM appointments WHERE appointment_date = CURDATE()";
        if ($doctorId) {
            $sql .= " AND doctor_id = :doctor_id";
        }
        $stats['today'] = $this->db->fetch($sql, $doctorId ? ['doctor_id' => $doctorId] : [])['today'];

        return $stats;
    }

    /**
     * Get upcoming appointments
     */
    public function getUpcoming($doctorId = null, $limit = 10) {
        $sql = "SELECT a.*, p.full_name as patient_name, p.phone as patient_phone,
                u.full_name as doctor_name
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.doctor_id = u.id
                WHERE a.appointment_date >= CURDATE()
                AND a.status IN ('scheduled', 'confirmed')";
        $params = [];

        if ($doctorId) {
            $sql .= " AND a.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY a.appointment_date, a.appointment_time LIMIT {$limit}";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get missed appointments (no-shows)
     */
    public function getMissed($doctorId = null) {
        $sql = "SELECT a.*, p.full_name as patient_name, p.phone as patient_phone
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                WHERE a.status = 'no_show'
                AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $params = [];

        if ($doctorId) {
            $sql .= " AND a.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY a.appointment_date DESC";
        return $this->db->fetchAll($sql, $params);
    }
}
