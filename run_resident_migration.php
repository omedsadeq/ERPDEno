<?php
/**
 * Resident Module Data Migration Script
 * سکریپتی کۆچکردنی داتای مۆدیولی ڕیزدەنت
 *
 * This script migrates data from the Resident database to the main Dental Clinic database
 * Run this AFTER running integrate_resident_module.sql
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

// Resident database configuration (old database)
define('RESIDENT_DB_HOST', 'localhost');
define('RESIDENT_DB_USER', 'root');
define('RESIDENT_DB_PASS', 'riosoft');
define('RESIDENT_DB_NAME', 'if0_40365985_lezan'); // Correct database name without .sql

echo "<html><head><meta charset='UTF-8'><title>Resident Migration</title>";
echo "<link rel='stylesheet' href='" . APP_URL . "/assets/css/fonts.css'>";
echo "<style>body{font-family:'NRT',Arial,sans-serif;padding:20px;direction:rtl;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}.warning{color:orange;}";
echo ".box{background:white;padding:15px;margin:10px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
echo "h2{border-bottom:2px solid #3498db;padding-bottom:10px;}</style></head><body>";

echo "<h1>🔄 کۆچکردنی داتای ڕیزدەنت بۆ سیستەمی سەرەکی</h1>";

try {
    // Connect to main database
    $mainDb = Database::getInstance();

    // Connect to Resident database
    $residentConn = new mysqli(RESIDENT_DB_HOST, RESIDENT_DB_USER, RESIDENT_DB_PASS, RESIDENT_DB_NAME);
    if ($residentConn->connect_error) {
        throw new Exception("Cannot connect to Resident database: " . $residentConn->connect_error);
    }
    $residentConn->set_charset("utf8mb4");

    echo "<div class='box'><p class='success'>✅ سەرکەوتوو: پەیوەندی بە هەردوو داتابەیس دروستکرا</p></div>";

    // ======================================
    // Step 1: Migrate Doctors
    // ======================================
    echo "<div class='box'><h2>📋 هەنگاوی ١: کۆچکردنی دکتۆرەکان</h2>";

    $doctorsResult = $residentConn->query("SELECT * FROM doctors ORDER BY id");
    $doctorMapping = []; // Map old doctor IDs to new IDs
    $doctorsInserted = 0;
    $doctorsSkipped = 0;

    if ($doctorsResult && $doctorsResult->num_rows > 0) {
        while ($doctor = $doctorsResult->fetch_assoc()) {
            // Check if doctor already exists in resident_doctors
            $existing = $mainDb->fetch(
                "SELECT id FROM resident_doctors WHERE name = ?",
                [$doctor['name']]
            );

            if ($existing) {
                $doctorMapping[$doctor['id']] = $existing['id'];
                $doctorsSkipped++;
                echo "<p class='warning'>⚠️ دکتۆر هەیە: {$doctor['name']}</p>";
            } else {
                // Try to find matching user in main users table
                $matchingUser = $mainDb->fetch(
                    "SELECT id FROM users WHERE full_name = ? AND role = 'doctor'",
                    [$doctor['name']]
                );

                $userId = $matchingUser ? $matchingUser['id'] : null;

                $newId = $mainDb->insert('resident_doctors', [
                    'user_id' => $userId,
                    'name' => $doctor['name'],
                    'specialization' => $doctor['specialization'],
                    'is_active' => $doctor['is_active'],
                    'created_at' => $doctor['created_at']
                ]);

                $doctorMapping[$doctor['id']] = $newId;
                $doctorsInserted++;
                echo "<p class='success'>✅ دکتۆر زیادکرا: {$doctor['name']} (ID: {$newId})</p>";
            }
        }
    }

    echo "<p class='info'>📊 کۆی دکتۆرەکان: زیادکرا: {$doctorsInserted}، هەبوو: {$doctorsSkipped}</p></div>";

    // ======================================
    // Step 2: Migrate Users
    // ======================================
    echo "<div class='box'><h2>👥 هەنگاوی ٢: کۆچکردنی بەکارهێنەران</h2>";

    $usersResult = $residentConn->query("SELECT * FROM users ORDER BY id");
    $userMapping = []; // Map old user IDs to new IDs
    $usersInserted = 0;
    $usersSkipped = 0;

    if ($usersResult && $usersResult->num_rows > 0) {
        while ($user = $usersResult->fetch_assoc()) {
            // Check if user already exists by username
            $existing = $mainDb->fetch(
                "SELECT id FROM users WHERE username = ?",
                [$user['username']]
            );

            if ($existing) {
                $userMapping[$user['id']] = $existing['id'];
                $usersSkipped++;
                echo "<p class='warning'>⚠️ بەکارهێنەر هەیە: {$user['username']}</p>";
            } else {
                // Map Resident roles to main system roles
                $newRole = 'resident_receptionist';
                if ($user['role'] === 'admin') {
                    $newRole = 'admin';
                }

                $newId = $mainDb->insert('users', [
                    'username' => $user['username'],
                    'password' => $user['password'],
                    'full_name' => $user['full_name'],
                    'role' => $newRole,
                    'is_active' => 1,
                    'created_at' => $user['created_at']
                ]);

                $userMapping[$user['id']] = $newId;
                $usersInserted++;
                echo "<p class='success'>✅ بەکارهێنەر زیادکرا: {$user['username']} (ڕۆڵ: {$newRole})</p>";
            }
        }
    }

    echo "<p class='info'>📊 کۆی بەکارهێنەران: زیادکرا: {$usersInserted}، هەبوو: {$usersSkipped}</p></div>";

    // ======================================
    // Step 3: Migrate Visits
    // ======================================
    echo "<div class='box'><h2>📝 هەنگاوی ٣: کۆچکردنی سەردانەکان</h2>";

    $visitsResult = $residentConn->query("SELECT * FROM visits ORDER BY id");
    $visitsInserted = 0;
    $visitsSkipped = 0;
    $patientsCreated = 0;

    if ($visitsResult && $visitsResult->num_rows > 0) {
        while ($visit = $visitsResult->fetch_assoc()) {
            // Check if visit already exists (by checking unique combination)
            $existing = $mainDb->fetch(
                "SELECT id FROM resident_visits WHERE mobile_number = ? AND visit_date = ? AND arrival_time = ?",
                [$visit['mobile_number'], $visit['visit_date'], $visit['arrival_time']]
            );

            if ($existing) {
                $visitsSkipped++;
                continue;
            }

            // Try to find or create patient in main patients table
            $patientId = null;
            if (!empty($visit['mobile_number'])) {
                $patient = $mainDb->fetch(
                    "SELECT id FROM patients WHERE phone = ? OR phone2 = ?",
                    [$visit['mobile_number'], $visit['mobile_number']]
                );

                if ($patient) {
                    $patientId = $patient['id'];
                } else {
                    // Create new patient
                    $patientCode = 'RES-' . str_pad($visit['id'], 6, '0', STR_PAD_LEFT);
                    $patientId = $mainDb->insert('patients', [
                        'patient_code' => $patientCode,
                        'full_name' => $visit['patient_name'],
                        'gender' => 'male', // Default, can be updated later
                        'phone' => $visit['mobile_number'],
                        'notes' => 'کۆچکراوە لە سیستەمی ڕیزدەنت',
                        'created_at' => $visit['created_at']
                    ]);
                    $patientsCreated++;
                }
            }

            // Map doctor IDs
            $requestedDoctorId = isset($doctorMapping[$visit['requested_doctor_id']]) ? $doctorMapping[$visit['requested_doctor_id']] : null;
            $assignedDoctorId = isset($doctorMapping[$visit['assigned_doctor_id']]) ? $doctorMapping[$visit['assigned_doctor_id']] : null;
            $appointmentDoctorId = isset($doctorMapping[$visit['appointment_doctor_id']]) ? $doctorMapping[$visit['appointment_doctor_id']] : null;
            $referringDoctorChoiceId = isset($doctorMapping[$visit['referring_doctor_choice_id']]) ? $doctorMapping[$visit['referring_doctor_choice_id']] : null;

            // Map user IDs
            $receptionistId = isset($userMapping[$visit['receptionist_id']]) ? $userMapping[$visit['receptionist_id']] : null;
            $appointmentAddedBy = isset($userMapping[$visit['appointment_added_by']]) ? $userMapping[$visit['appointment_added_by']] : null;

            // Insert visit
            $mainDb->insert('resident_visits', [
                'patient_id' => $patientId,
                'patient_name' => $visit['patient_name'],
                'mobile_number' => $visit['mobile_number'],
                'treated' => $visit['treated'],
                'treated_reason' => $visit['treated_reason'],
                'requested_doctor_id' => $requestedDoctorId,
                'assigned_doctor_id' => $assignedDoctorId,
                'assigned_doctor_reason' => $visit['assigned_doctor_reason'],
                'arrival_time' => $visit['arrival_time'],
                'checkin_time' => $visit['checkin_time'],
                'notification_time' => $visit['notification_time'],
                'procedure_treatment' => $visit['procedure_treatment'],
                'patient_status' => $visit['patient_status'],
                'receptionist_id' => $receptionistId,
                'referral_source' => $visit['referral_source'],
                'referral_source_notes' => $visit['referral_source_notes'],
                'appointment_date' => $visit['appointment_date'],
                'appointment_added_by' => $appointmentAddedBy,
                'appointment_doctor_id' => $appointmentDoctorId,
                'referring_doctor_id' => $visit['referring_doctor_id'],
                'referring_doctor_designation' => $visit['referring_doctor_designation'],
                'referring_doctor_choice_id' => $referringDoctorChoiceId,
                'appointment_image' => $visit['appointment_image'],
                'notes' => $visit['notes'],
                'visit_date' => $visit['visit_date'],
                'created_at' => $visit['created_at'],
                'updated_at' => $visit['updated_at']
            ]);

            $visitsInserted++;
        }
    }

    echo "<p class='success'>✅ سەردانەکان کۆچکران: {$visitsInserted}</p>";
    echo "<p class='warning'>⚠️ سەردانی دووبارە: {$visitsSkipped}</p>";
    echo "<p class='info'>📊 نەخۆشی نوێ دروستکرا: {$patientsCreated}</p></div>";

    // ======================================
    // Summary
    // ======================================
    echo "<div class='box' style='background:#e8f5e9;'>";
    echo "<h2>✅ کۆچکردن تەواوبوو!</h2>";
    echo "<ul>";
    echo "<li>دکتۆرەکان: {$doctorsInserted} زیادکرا، {$doctorsSkipped} هەبوو</li>";
    echo "<li>بەکارهێنەران: {$usersInserted} زیادکرا، {$usersSkipped} هەبوو</li>";
    echo "<li>سەردانەکان: {$visitsInserted} کۆچکرا، {$visitsSkipped} دووبارە</li>";
    echo "<li>نەخۆشی نوێ: {$patientsCreated} دروستکرا</li>";
    echo "</ul>";
    echo "<p><strong>هەنگاوی داهاتوو:</strong> ئێستا دەتوانیت مۆدیولی ڕیزدەنت بەکاربهێنیت لە سیستەمی سەرەکی.</p>";
    echo "</div>";

    $residentConn->close();

} catch (Exception $e) {
    echo "<div class='box'><p class='error'>❌ هەڵە: " . $e->getMessage() . "</p></div>";
}

echo "</body></html>";
?>
