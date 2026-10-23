<?php
/**
 * Setup Script - Run once to initialize the database
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<link rel='stylesheet' href='" . APP_URL . "/assets/css/fonts.css'>";
echo "<pre style='direction: rtl; font-family: \"NRT\", Tahoma, sans-serif; font-size: 14px;'>";
echo "<h2>دامەزراندنی سیستەمی کلینیکی ددان</h2>";

try {
    $db = Database::getInstance();

    // Check and create admin user
    $adminExists = $db->fetch("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    if ($adminExists) {
        echo "⚠️ بەکارهێنەری ئەدمین پێشتر هەیە\n";
    } else {
        echo "✓ دروستکردنی بەکارهێنەری ئەدمین...\n";
        $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $db->insert('users', [
            'username' => 'admin',
            'password' => $adminPassword,
            'full_name' => 'بەڕێوەبەری سەرەکی',
            'email' => 'admin@denthisab.local',
            'phone' => '0750-000-0000',
            'role' => 'admin',
            'is_active' => 1
        ]);
    }

    // Check and create sample doctor
    $doctorExists = $db->fetch("SELECT id FROM users WHERE username = 'doctor' LIMIT 1");
    if ($doctorExists) {
        // Reset password for existing doctor
        echo "✓ نوێکردنەوەی وشەی نهێنی دکتۆر...\n";
        $doctorPassword = password_hash('doctor123', PASSWORD_DEFAULT);
        $db->update('users', ['password' => $doctorPassword, 'is_active' => 1], "username = :username", ['username' => 'doctor']);
    } else {
        echo "✓ دروستکردنی بەکارهێنەری دکتۆر نموونە...\n";
        $doctorPassword = password_hash('doctor123', PASSWORD_DEFAULT);
        $db->insert('users', [
            'username' => 'doctor',
            'password' => $doctorPassword,
            'full_name' => 'د. هاوڕێ محمد',
            'email' => 'doctor@denthisab.local',
            'phone' => '0750-111-1111',
            'role' => 'doctor',
            'specialization' => 'پزیشکی دارووسازی ددان',
            'is_active' => 1
        ]);
    }

    // Add sample treatments (check if already exist)
    $treatmentsExist = $db->fetch("SELECT COUNT(*) as cnt FROM treatments")['cnt'] ?? 0;
    if ($treatmentsExist > 0) {
        echo "⚠️ چارەسەرەکان پێشتر هەیە ({$treatmentsExist})\n";
    } else {
        echo "✓ زیادکردنی چارەسەرەکان...\n";
        $treatments = [
            ['TRT001', 'پشکنین و ڕاوێژ', 'Examination', 'examination', 15000],
            ['TRT002', 'پاککردنەوەی ددان', 'Dental Cleaning', 'cleaning', 35000],
            ['TRT003', 'پڕکردنەوەی سادە', 'Simple Filling', 'filling', 25000],
            ['TRT004', 'پڕکردنەوەی کۆمپۆزیت', 'Composite Filling', 'filling', 40000],
            ['TRT005', 'ڕاکێشانی سادە', 'Simple Extraction', 'extraction', 25000],
            ['TRT006', 'ڕاکێشانی نەشتەرگەری', 'Surgical Extraction', 'extraction', 75000],
            ['TRT007', 'چارەسەری دەمار', 'Root Canal', 'root_canal', 100000],
            ['TRT008', 'تاجی زیرکۆنیا', 'Zirconia Crown', 'crown', 200000],
            ['TRT009', 'تاجی PFM', 'PFM Crown', 'crown', 150000],
            ['TRT010', 'چاندنی ددان', 'Dental Implant', 'implant', 750000],
            ['TRT011', 'سپیکردنەوە', 'Teeth Whitening', 'whitening', 100000],
            ['TRT012', 'ڕاستکردنەوە (براکێت)', 'Orthodontic Braces', 'orthodontic', 1500000],
        ];

        foreach ($treatments as $t) {
            $db->insert('treatments', [
                'treatment_code' => $t[0],
                'treatment_name' => $t[1],
                'treatment_name_en' => $t[2],
                'category' => $t[3],
                'default_price' => $t[4],
                'is_active' => 1
            ]);
        }
    }

    // Add default settings (check if already exist)
    $settingsExist = $db->fetch("SELECT COUNT(*) as cnt FROM settings")['cnt'] ?? 0;
    if ($settingsExist > 0) {
        echo "⚠️ ڕێکخستنەکان پێشتر هەیە ({$settingsExist})\n";
    } else {
        echo "✓ زیادکردنی ڕێکخستنەکان...\n";
        $settings = [
            ['app_name', 'دێنت حیساب'],
            ['clinic_name', 'سەنتەری ددان'],
            ['currency', 'IQD'],
            ['work_start', '09:00'],
            ['work_end', '21:00'],
            ['default_appointment_duration', '30'],
            ['invoice_size', 'A4'],
            ['invoice_footer', 'سوپاس بۆ متمانەکەت - بەخێر بێیتەوە']
        ];

        foreach ($settings as $s) {
            $db->insert('settings', [
                'setting_key' => $s[0],
                'setting_value' => $s[1]
            ]);
        }
    }

    echo "\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "✓✓ دامەزراندن بە سەرکەوتوویی تەواوبوو!\n";
    echo "═══════════════════════════════════════════════════════\n\n";

    echo "زانیاری چوونەژوورەوە:\n\n";

    echo "<strong>بەکارهێنەری ئەدمین:</strong>\n";
    echo "   ناو: admin\n";
    echo "   وشەی نهێنی: admin123\n\n";

    echo "<strong>بەکارهێنەری دکتۆر:</strong>\n";
    echo "   ناو: doctor\n";
    echo "   وشەی نهێنی: doctor123\n\n";

    echo "⚠️ تکایە دوای چوونەژوورەوە وشەی نهێنی بگۆڕە!\n\n";

    echo "<a href='" . APP_URL . "/login.php' style='background: #0078D4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px;'>چوونەژوورەوە</a>\n";

} catch (Exception $e) {
    echo "❌ هەڵە: " . $e->getMessage() . "\n";
}

echo "</pre>";
