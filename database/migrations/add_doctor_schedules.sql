-- Migration: Doctor Scheduling System
-- Date: 2025-12-30
-- Description: Add tables for doctor working hours, breaks, and date exceptions

-- Create doctor_schedules table (weekly working hours)
CREATE TABLE IF NOT EXISTS doctor_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday',
    is_working_day TINYINT(1) DEFAULT 1 COMMENT '0=Off day, 1=Working day',
    start_time TIME DEFAULT '09:00:00',
    end_time TIME DEFAULT '21:00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_doctor_day (doctor_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create doctor_breaks table (break times per day)
CREATE TABLE IF NOT EXISTS doctor_breaks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday...6=Saturday',
    break_name VARCHAR(50) DEFAULT 'پشوو' COMMENT 'Break name in Kurdish',
    break_start TIME NOT NULL,
    break_end TIME NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create doctor_exceptions table (date-specific overrides)
CREATE TABLE IF NOT EXISTS doctor_exceptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM('off', 'custom') NOT NULL COMMENT 'off=day off, custom=different hours',
    start_time TIME DEFAULT NULL COMMENT 'NULL if day off',
    end_time TIME DEFAULT NULL,
    reason VARCHAR(255) COMMENT 'Reason for exception',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_doctor_exception (doctor_id, exception_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for better performance
CREATE INDEX idx_doctor_schedules_doctor ON doctor_schedules(doctor_id);
CREATE INDEX idx_doctor_breaks_doctor ON doctor_breaks(doctor_id);
CREATE INDEX idx_doctor_breaks_day ON doctor_breaks(doctor_id, day_of_week);
CREATE INDEX idx_doctor_exceptions_date ON doctor_exceptions(exception_date);
CREATE INDEX idx_doctor_exceptions_doctor_date ON doctor_exceptions(doctor_id, exception_date);

-- Initialize default schedules for existing doctors
-- Saturday-Thursday working (9:00-21:00), Friday off
INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 0, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 1, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 2, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 3, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 4, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

-- Friday OFF (day 5)
INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 5, 0, NULL, NULL FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

-- Saturday working (day 6)
INSERT INTO doctor_schedules (doctor_id, day_of_week, is_working_day, start_time, end_time)
SELECT id, 6, 1, '09:00:00', '21:00:00' FROM users WHERE role = 'doctor'
ON DUPLICATE KEY UPDATE doctor_id = doctor_id;

-- Add default lunch break for all doctors (12:00 - 13:00) on working days
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 0, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 1, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 2, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 3, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 4, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
INSERT INTO doctor_breaks (doctor_id, day_of_week, break_name, break_start, break_end)
SELECT id, 6, 'نان', '12:00:00', '13:00:00' FROM users WHERE role = 'doctor';
