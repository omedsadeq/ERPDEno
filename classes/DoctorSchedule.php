<?php
/**
 * DoctorSchedule Class
 * Manages doctor working hours, breaks, and exceptions
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class DoctorSchedule {
    private $db;

    // Kurdish day names (indexed by PHP's day_of_week: 0=Sunday...6=Saturday)
    private $dayNames = [
        0 => 'یەکشەممە',   // Sunday
        1 => 'دووشەممە',   // Monday
        2 => 'سێشەممە',    // Tuesday
        3 => 'چوارشەممە',  // Wednesday
        4 => 'پێنجشەممە',  // Thursday
        5 => 'هەینی',      // Friday
        6 => 'شەممە'       // Saturday
    ];

    // Kurdish ordered days (Saturday first - Kurdish week)
    private $orderedDays = [6, 0, 1, 2, 3, 4, 5]; // Saturday to Friday

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get weekly schedule for a doctor
     */
    public function getSchedule($doctorId) {
        $sql = "SELECT * FROM doctor_schedules WHERE doctor_id = :doctor_id ORDER BY day_of_week";
        $schedules = $this->db->fetchAll($sql, ['doctor_id' => $doctorId]);

        // If no schedule exists, initialize default
        if (empty($schedules)) {
            $this->initializeDefaultSchedule($doctorId);
            $schedules = $this->db->fetchAll($sql, ['doctor_id' => $doctorId]);
        }

        // Add Kurdish day names and reorder by Kurdish week (Saturday first)
        $result = [];
        foreach ($schedules as $schedule) {
            $schedule['day_name'] = $this->dayNames[$schedule['day_of_week']];
            $result[$schedule['day_of_week']] = $schedule;
        }

        // Reorder by Kurdish week (Saturday first)
        $orderedResult = [];
        foreach ($this->orderedDays as $day) {
            if (isset($result[$day])) {
                $orderedResult[] = $result[$day];
            }
        }

        return $orderedResult;
    }

    /**
     * Get schedule for a specific day
     */
    public function getScheduleForDay($doctorId, $dayOfWeek) {
        $sql = "SELECT * FROM doctor_schedules WHERE doctor_id = :doctor_id AND day_of_week = :day";
        $schedule = $this->db->fetch($sql, ['doctor_id' => $doctorId, 'day' => $dayOfWeek]);

        // If no schedule exists, initialize default schedule for this doctor
        if (!$schedule) {
            $this->initializeDefaultSchedule($doctorId);
            $schedule = $this->db->fetch($sql, ['doctor_id' => $doctorId, 'day' => $dayOfWeek]);
        }

        if ($schedule) {
            $schedule['day_name'] = $this->dayNames[$dayOfWeek];
        }

        return $schedule;
    }

    /**
     * Set schedule for a specific day
     */
    public function setSchedule($doctorId, $dayOfWeek, $data) {
        try {
            $sql = "INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
                    VALUES (:doctor_id, :day_of_week, :is_working_day, :start_time, :end_time)
                    ON DUPLICATE KEY UPDATE
                    is_working_day = VALUES(is_working_day),
                    start_time = VALUES(start_time),
                    end_time = VALUES(end_time)";

            $this->db->query($sql, [
                'doctor_id' => $doctorId,
                'day_of_week' => $dayOfWeek,
                'is_working_day' => $data['is_working_day'] ?? 1,
                'start_time' => $data['is_working_day'] ? ($data['start_time'] ?? '09:00:00') : null,
                'end_time' => $data['is_working_day'] ? ($data['end_time'] ?? '21:00:00') : null
            ]);

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Set weekly schedule (all days at once)
     */
    public function setWeeklySchedule($doctorId, $scheduleData) {
        try {
            $this->db->beginTransaction();

            foreach ($scheduleData as $dayData) {
                $dayOfWeek = intval($dayData['day_of_week']);
                $this->setSchedule($doctorId, $dayOfWeek, $dayData);
            }

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Initialize default schedule for a doctor
     */
    public function initializeDefaultSchedule($doctorId) {
        $defaultSchedule = [
            ['day_of_week' => 0, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Sunday
            ['day_of_week' => 1, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Monday
            ['day_of_week' => 2, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Tuesday
            ['day_of_week' => 3, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Wednesday
            ['day_of_week' => 4, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Thursday
            ['day_of_week' => 5, 'is_working_day' => 0, 'start_time' => null, 'end_time' => null],              // Friday (OFF)
            ['day_of_week' => 6, 'is_working_day' => 1, 'start_time' => '09:00:00', 'end_time' => '21:00:00'], // Saturday
        ];

        foreach ($defaultSchedule as $day) {
            $this->setSchedule($doctorId, $day['day_of_week'], $day);
        }

        return true;
    }

    // ===================== BREAK MANAGEMENT =====================

    /**
     * Get breaks for a doctor
     */
    public function getBreaks($doctorId, $dayOfWeek = null) {
        $sql = "SELECT * FROM doctor_breaks WHERE doctor_id = :doctor_id AND is_active = 1";
        $params = ['doctor_id' => $doctorId];

        if ($dayOfWeek !== null) {
            $sql .= " AND day_of_week = :day_of_week";
            $params['day_of_week'] = $dayOfWeek;
        }

        $sql .= " ORDER BY day_of_week, break_start";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Add a break time
     */
    public function addBreak($doctorId, $dayOfWeek, $breakStart, $breakEnd, $name = 'پشوو') {
        try {
            $id = $this->db->insert('doctor_breaks', [
                'doctor_id' => $doctorId,
                'day_of_week' => $dayOfWeek,
                'break_name' => $name,
                'break_start' => $breakStart,
                'break_end' => $breakEnd,
                'is_active' => 1
            ]);
            return ['success' => true, 'break_id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update a break
     */
    public function updateBreak($breakId, $data) {
        try {
            $this->db->update('doctor_breaks', $data, 'id = :id', ['id' => $breakId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a break
     */
    public function deleteBreak($breakId) {
        try {
            $this->db->delete('doctor_breaks', 'id = :id', ['id' => $breakId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================== EXCEPTION MANAGEMENT =====================

    /**
     * Get exceptions for a doctor within a date range
     */
    public function getExceptions($doctorId, $startDate = null, $endDate = null) {
        $sql = "SELECT * FROM doctor_exceptions WHERE doctor_id = :doctor_id";
        $params = ['doctor_id' => $doctorId];

        if ($startDate) {
            $sql .= " AND exception_date >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND exception_date <= :end_date";
            $params['end_date'] = $endDate;
        }

        $sql .= " ORDER BY exception_date";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get exception for a specific date
     */
    public function getExceptionForDate($doctorId, $date) {
        $sql = "SELECT * FROM doctor_exceptions WHERE doctor_id = :doctor_id AND exception_date = :date";
        return $this->db->fetch($sql, ['doctor_id' => $doctorId, 'date' => $date]);
    }

    /**
     * Add a date exception (day off or custom hours)
     */
    public function addException($doctorId, $date, $type, $startTime = null, $endTime = null, $reason = '') {
        try {
            $id = $this->db->insert('doctor_exceptions', [
                'doctor_id' => $doctorId,
                'exception_date' => $date,
                'exception_type' => $type,
                'start_time' => $type === 'custom' ? $startTime : null,
                'end_time' => $type === 'custom' ? $endTime : null,
                'reason' => $reason
            ]);
            return ['success' => true, 'exception_id' => $id];
        } catch (Exception $e) {
            // Check if duplicate
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                return ['success' => false, 'message' => 'ئەم بەروارە پێشتر تۆمارکراوە'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an exception
     */
    public function updateException($exceptionId, $data) {
        try {
            $this->db->update('doctor_exceptions', $data, 'id = :id', ['id' => $exceptionId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete an exception
     */
    public function deleteException($exceptionId) {
        try {
            $this->db->delete('doctor_exceptions', 'id = :id', ['id' => $exceptionId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===================== AVAILABILITY CALCULATION =====================

    /**
     * Get working hours for a specific date (considers exceptions)
     */
    public function getWorkingHours($doctorId, $date) {
        // First check for date exception
        $exception = $this->getExceptionForDate($doctorId, $date);

        if ($exception) {
            if ($exception['exception_type'] === 'off') {
                return null; // Day off
            }
            // Custom hours
            return [
                'is_working_day' => true,
                'start_time' => $exception['start_time'],
                'end_time' => $exception['end_time'],
                'is_exception' => true,
                'reason' => $exception['reason']
            ];
        }

        // Get regular schedule for day of week
        $dayOfWeek = date('w', strtotime($date));
        $schedule = $this->getScheduleForDay($doctorId, $dayOfWeek);

        if (!$schedule || !$schedule['is_working_day']) {
            return null; // Not working this day
        }

        return [
            'is_working_day' => true,
            'start_time' => $schedule['start_time'],
            'end_time' => $schedule['end_time'],
            'is_exception' => false
        ];
    }

    /**
     * Check if doctor is working on a specific date
     */
    public function isWorkingDay($doctorId, $date) {
        return $this->getWorkingHours($doctorId, $date) !== null;
    }

    /**
     * Get available time slots for a doctor on a specific date
     * This is the core GAP ANALYSIS logic:
     * Free Slots = Working Hours - Breaks - Existing Appointments
     */
    public function getAvailableSlots($doctorId, $date, $duration = 30) {
        // Step 1: Get working hours for the date
        $workingHours = $this->getWorkingHours($doctorId, $date);
        if (!$workingHours) {
            return []; // Not a working day
        }

        // Step 2: Get breaks for this day of week
        $dayOfWeek = date('w', strtotime($date));
        $breaks = $this->getBreaks($doctorId, $dayOfWeek);

        // Step 3: Get existing appointments for this date
        $appointments = $this->getAppointmentsForDate($doctorId, $date);

        // Step 4: Build list of unavailable time ranges
        $unavailable = [];

        // Add breaks to unavailable
        foreach ($breaks as $break) {
            $unavailable[] = [
                'start' => $break['break_start'],
                'end' => $break['break_end'],
                'type' => 'break'
            ];
        }

        // Add existing appointments to unavailable
        foreach ($appointments as $apt) {
            if (!in_array($apt['status'], ['cancelled', 'no_show'])) {
                $aptEnd = date('H:i:s', strtotime($apt['appointment_time']) + ($apt['duration'] * 60));
                $unavailable[] = [
                    'start' => $apt['appointment_time'],
                    'end' => $aptEnd,
                    'type' => 'appointment'
                ];
            }
        }

        // Step 5: Generate available slots
        $slots = [];
        $currentTime = strtotime($date . ' ' . $workingHours['start_time']);
        $endTime = strtotime($date . ' ' . $workingHours['end_time']);

        while ($currentTime + ($duration * 60) <= $endTime) {
            $slotStart = date('H:i:s', $currentTime);
            $slotEnd = date('H:i:s', $currentTime + ($duration * 60));

            $isAvailable = true;

            // Check against all unavailable ranges
            foreach ($unavailable as $block) {
                if ($this->timesOverlap($slotStart, $slotEnd, $block['start'], $block['end'])) {
                    $isAvailable = false;
                    break;
                }
            }

            if ($isAvailable) {
                $slots[] = date('H:i', $currentTime);
            }

            $currentTime += (30 * 60); // Move by 30-minute intervals
        }

        return $slots;
    }

    /**
     * Get all time slots with their status (for visual display)
     */
    public function getAllSlotsWithStatus($doctorId, $date, $duration = 30) {
        $workingHours = $this->getWorkingHours($doctorId, $date);
        if (!$workingHours) {
            return ['working' => false, 'slots' => []];
        }

        $dayOfWeek = date('w', strtotime($date));
        $breaks = $this->getBreaks($doctorId, $dayOfWeek);
        $appointments = $this->getAppointmentsForDate($doctorId, $date);

        // Build unavailable map with types
        $unavailable = [];
        foreach ($breaks as $break) {
            $unavailable[] = [
                'start' => $break['break_start'],
                'end' => $break['break_end'],
                'type' => 'break',
                'name' => $break['break_name']
            ];
        }
        foreach ($appointments as $apt) {
            if (!in_array($apt['status'], ['cancelled', 'no_show'])) {
                $aptEnd = date('H:i:s', strtotime($apt['appointment_time']) + ($apt['duration'] * 60));
                $unavailable[] = [
                    'start' => $apt['appointment_time'],
                    'end' => $aptEnd,
                    'type' => 'booked',
                    'name' => $apt['patient_name'] ?? 'مەوعید'
                ];
            }
        }

        $slots = [];
        $currentTime = strtotime($date . ' ' . $workingHours['start_time']);
        $endTime = strtotime($date . ' ' . $workingHours['end_time']);

        while ($currentTime + ($duration * 60) <= $endTime) {
            $slotStart = date('H:i:s', $currentTime);
            $slotEnd = date('H:i:s', $currentTime + ($duration * 60));

            $status = 'available';
            $blockInfo = null;

            foreach ($unavailable as $block) {
                if ($this->timesOverlap($slotStart, $slotEnd, $block['start'], $block['end'])) {
                    $status = $block['type'];
                    $blockInfo = $block['name'];
                    break;
                }
            }

            $slots[] = [
                'time' => date('H:i', $currentTime),
                'status' => $status,
                'info' => $blockInfo
            ];

            $currentTime += (30 * 60);
        }

        return [
            'working' => true,
            'start_time' => $workingHours['start_time'],
            'end_time' => $workingHours['end_time'],
            'slots' => $slots
        ];
    }

    /**
     * Get appointments for a specific date and doctor
     */
    private function getAppointmentsForDate($doctorId, $date) {
        $sql = "SELECT a.*, p.full_name as patient_name
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                WHERE a.doctor_id = :doctor_id
                AND a.appointment_date = :date
                ORDER BY a.appointment_time";
        return $this->db->fetchAll($sql, ['doctor_id' => $doctorId, 'date' => $date]);
    }

    /**
     * Check if two time ranges overlap
     */
    private function timesOverlap($start1, $end1, $start2, $end2) {
        $s1 = strtotime($start1);
        $e1 = strtotime($end1);
        $s2 = strtotime($start2);
        $e2 = strtotime($end2);

        return ($s1 < $e2 && $e1 > $s2);
    }

    // ===================== UTILITY METHODS =====================

    /**
     * Get Kurdish day name
     */
    public function getDayName($dayOfWeek) {
        return $this->dayNames[$dayOfWeek] ?? '';
    }

    /**
     * Get all day names ordered by Kurdish week (Saturday first)
     */
    public function getOrderedDayNames() {
        $result = [];
        foreach ($this->orderedDays as $day) {
            $result[$day] = $this->dayNames[$day];
        }
        return $result;
    }

    /**
     * Get calendar events for FullCalendar (doctor's schedule blocks)
     */
    public function getScheduleEvents($doctorId, $startDate, $endDate) {
        $events = [];

        // Get exceptions in range
        $exceptions = $this->getExceptions($doctorId, $startDate, $endDate);
        foreach ($exceptions as $exc) {
            if ($exc['exception_type'] === 'off') {
                $events[] = [
                    'id' => 'exc_' . $exc['id'],
                    'title' => $exc['reason'] ?: 'ڕۆژی پشوو',
                    'start' => $exc['exception_date'],
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => '#ffebee',
                    'classNames' => ['day-off-event']
                ];
            }
        }

        return $events;
    }
}
