<?php
/**
 * Builds the HR data migration from a Panjamor (old attendance system) dump.
 *
 *   php database/tools/hr_import_panjamor.php <panjamor-dump.sql> <output.sql> [db_host] [db_user] [db_pass]
 *
 * The dump is loaded into a throw-away database on the given MySQL server (the
 * local XAMPP one by default), the rows the clinic actually uses are read back,
 * and one migration file is written with them as hr_* rows. The throw-away
 * database is dropped afterwards.
 *
 * Every row keeps its Panjamor id and is written with INSERT IGNORE, so a
 * migration generated from a newer dump can be added later: rows already
 * imported are skipped and only the new punches and adjustments go in.
 *
 * Moved over:  employees, the shifts they use, holidays, every punch, every
 *              manual hour adjustment.
 * Left behind: Panjamor's logins (Lezan users are used instead), its demo
 *              departments, leave types, and ~45 tables the old app never used.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc < 3) {
    fwrite(STDERR, "usage: php hr_import_panjamor.php <panjamor-dump.sql> <output.sql> [db_host] [db_user] [db_pass]\n");
    exit(1);
}

[$dumpFile, $outFile] = [$argv[1], $argv[2]];
$host = $argv[3] ?? '127.0.0.1';
$user = $argv[4] ?? 'root';
$pass = $argv[5] ?? '';
$tmpDb = 'hr_panjamor_import_tmp';

/**
 * Panjamor employee id → Lezan username, for the people whose HR record
 * belongs to an existing login. Matched by username (not users.id) so it holds
 * on any copy of the database. Anyone not listed is linked by hand on
 * hr/employees.php.
 */
const USER_LINKS = [
    18 => 'Dr.hersh',
    15 => 'renas',
    19 => 'dctorshwan',
    8  => 'drdana',
    16 => 'drkazhin',
    24 => 'Drrdwan',
    5  => 'chra najat',
    10 => 'Nuradin sallih',
    21 => 'kazhin',
    27 => 'Ersa',
];

const SHIFT_NAMES = [
    'Morning Shift' => 'دەوامی بەیانی',
    'Evening Shift' => 'دەوامی ئێوارە',
    'Night Shift'   => 'دەوامی شەو',
];

const HOLIDAY_NAMES = [
    'New Year Day'     => 'سەری ساڵی زایینی',
    'Newroz'           => 'نەورۆز',
    'Labor Day'        => 'ڕۆژی کرێکاران',
    'Kurdistan Day'    => 'ڕۆژی کوردستان',
    'Independence Day' => 'ڕۆژی سەربەخۆیی',
];

const DAY_NUMBERS = [
    'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
    'thursday' => 4, 'friday' => 5, 'saturday' => 6,
];

const TABLES = ['employees', 'shifts', 'holidays', 'biometric_entries', 'manual_adj'];

$sql = @file_get_contents($dumpFile);
if ($sql === false) {
    fwrite(STDERR, "cannot read $dumpFile\n");
    exit(1);
}

$pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("DROP DATABASE IF EXISTS `$tmpDb`");
$pdo->exec("CREATE DATABASE `$tmpDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$tmpDb`");
$pdo->exec("SET SESSION sql_mode = ''");

try {
    $loaded = 0;
    foreach (splitStatements($sql) as $stmt) {
        if (preg_match('/^(CREATE TABLE|INSERT INTO) `([A-Za-z0-9_]+)`/', $stmt, $m) && in_array($m[2], TABLES, true)) {
            $pdo->exec($stmt);
            $loaded++;
        }
    }
    foreach (TABLES as $t) {
        if (!$pdo->query("SHOW TABLES LIKE '$t'")->fetch()) {
            throw new RuntimeException("table $t is not in the dump");
        }
    }

    $out = buildMigration($pdo, basename($dumpFile));
    file_put_contents($outFile, $out['sql']);
    echo "loaded $loaded statements from the dump\n";
    foreach ($out['counts'] as $what => $n) {
        echo str_pad($what, 12) . " $n\n";
    }
    echo "written to $outFile\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$tmpDb`");
}

function buildMigration(PDO $pdo, string $source): array
{
    $q = fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v);
    $blank = fn($v) => ($v === null || trim((string)$v) === '') ? null : trim((string)$v);
    $counts = [];
    $parts = [];

    // ── Shifts the employees actually use ──
    $shifts = $pdo->query(
        "SELECT * FROM shifts WHERE id IN (SELECT shift_id FROM employees WHERE shift_id IS NOT NULL) ORDER BY id"
    )->fetchAll();
    $rows = [];
    foreach ($shifts as $s) {
        $days = json_decode((string)$s['work_days'], true);
        if (!is_array($days)) {
            $days = explode(',', (string)$s['work_days']);
        }
        $nums = [];
        foreach ($days as $d) {
            $d = strtolower(trim((string)$d));
            if (isset(DAY_NUMBERS[$d])) {
                $nums[] = DAY_NUMBERS[$d];
            }
        }
        $rows[] = '(' . implode(', ', [
            (int)$s['id'],
            $q(SHIFT_NAMES[$s['shift_name']] ?? $s['shift_name']),
            $q($s['start_time']),
            $q($s['end_time']),
            (int)$s['break_duration'],
            $q(implode(',', $nums ?: [6, 0, 1, 2, 3, 4])),
            (int)$s['is_active'],
        ]) . ')';
    }
    $counts['shifts'] = count($rows);
    if ($rows) {
        $parts[] = "INSERT IGNORE INTO hr_shifts (id, name, start_time, end_time, break_minutes, work_days, is_active) VALUES\n"
            . implode(",\n", $rows) . ';';
    }

    // ── Holidays ──
    $rows = [];
    foreach ($pdo->query("SELECT * FROM holidays ORDER BY id")->fetchAll() as $h) {
        $rows[] = '(' . implode(', ', [
            (int)$h['id'],
            $q(HOLIDAY_NAMES[$h['holiday_name']] ?? $h['holiday_name']),
            $q($h['holiday_date']),
            (int)$h['is_recurring'],
            (int)$h['is_active'],
        ]) . ')';
    }
    $counts['holidays'] = count($rows);
    if ($rows) {
        $parts[] = "INSERT IGNORE INTO hr_holidays (id, name, holiday_date, is_recurring, is_active) VALUES\n"
            . implode(",\n", $rows) . ';';
    }

    // ── Employees ──
    $rows = [];
    foreach ($pdo->query("SELECT * FROM employees ORDER BY id")->fetchAll() as $e) {
        $name = preg_replace('/\s+/u', ' ', trim((string)$e['full_name']));
        $isDoctor = (bool)preg_match('/^dra?\.?\s/iu', $name);
        $position = $blank($e['position']);
        if ($position !== null && strcasecmp($position, 'Employee') === 0) {
            $position = null;
        }
        $rows[] = '(' . implode(', ', [
            (int)$e['id'],
            $q($name),
            $q($isDoctor ? 'doctor' : 'staff'),
            $q($position),
            $e['shift_id'] !== null ? (int)$e['shift_id'] : 'NULL',
            $q($blank($e['phone'])),
            $q($blank($e['national_id'])),
            $q(in_array($e['gender'], ['male', 'female'], true) ? $e['gender'] : null),
            $q($blank($e['birth_date'])),
            $q($blank($e['address'])),
            $q($blank($e['emergency_contact'])),
            $q($blank($e['emergency_phone'])),
            $q($blank($e['hire_date'])),
            $e['basic_salary'] !== null ? $q($e['basic_salary']) : 'NULL',
            $e['hourly_rate'] !== null ? $q($e['hourly_rate']) : 'NULL',
            $q($e['salary_percentage'] ?? '0.00'),
            $e['display_order'] !== null ? (int)$e['display_order'] : 'NULL',
            $q($e['status'] === 'active' ? 'active' : 'inactive'),
            $q($blank($e['notes'])),
        ]) . ')';
    }
    $counts['employees'] = count($rows);
    $parts[] = "INSERT IGNORE INTO hr_employees (id, full_name, employee_type, position, shift_id, phone, national_id, gender, birth_date, address, emergency_contact, emergency_phone, hire_date, basic_salary, hourly_rate, salary_percentage, display_order, status, notes) VALUES\n"
        . implode(",\n", $rows) . ';';

    // ── Links to Lezan logins ──
    // Only an unlinked record is touched. A login already linked to someone
    // else trips uq_hr_employee_user (1062), which MigrationRunner skips.
    $links = [];
    foreach (USER_LINKS as $empId => $username) {
        $links[] = 'UPDATE hr_employees e JOIN users u ON u.username = ' . $q($username)
            . ' SET e.user_id = u.id WHERE e.id = ' . (int)$empId . ' AND e.user_id IS NULL;';
    }
    $counts['user links'] = count($links);
    $parts[] = implode("\n", $links);

    // ── Punches ──
    $all = $pdo->query(
        "SELECT id, employee_id, punch_time, punch_type, device_id, verification_type, is_manual, notes
         FROM biometric_entries ORDER BY id"
    )->fetchAll();
    $counts['punches'] = count($all);
    foreach (array_chunk($all, 500) as $chunk) {
        $rows = [];
        foreach ($chunk as $p) {
            $manual = (int)$p['is_manual'] === 1 || $p['verification_type'] === 'manual';
            $rows[] = '(' . implode(', ', [
                (int)$p['id'],
                (int)$p['employee_id'],
                $q($p['punch_time']),
                $q($p['punch_type']),
                $q($manual ? 'manual' : 'device'),
                $q($manual ? null : $blank($p['device_id'])),
                $q($blank($p['notes'])),
            ]) . ')';
        }
        $parts[] = "INSERT IGNORE INTO hr_punches (id, employee_id, punch_time, punch_type, source, device_id, notes) VALUES\n"
            . implode(",\n", $rows) . ';';
    }

    // ── Manual hour adjustments ──
    // Rows of employees deleted in Panjamor are left out: no report there
    // showed them, and their punches went with the employee.
    $counts['adjustments of deleted employees (skipped)'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM manual_adj m LEFT JOIN employees e ON e.id = m.emp_id WHERE e.id IS NULL"
    )->fetchColumn();
    $rows = [];
    foreach ($pdo->query("SELECT m.* FROM manual_adj m JOIN employees e ON e.id = m.emp_id ORDER BY m.ID")->fetchAll() as $a) {
        if (!in_array($a['manual_type'], ['overtime', 'deduction'], true)) {
            continue;
        }
        $rows[] = '(' . implode(', ', [
            (int)$a['ID'],
            (int)$a['emp_id'],
            $q($a['Date']),
            $q($a['manual_type']),
            $q(round((float)$a['hour'], 2)),
            $q($blank($a['reason'])),
        ]) . ')';
    }
    $counts['adjustments'] = count($rows);
    if ($rows) {
        $parts[] = "INSERT IGNORE INTO hr_adjustments (id, employee_id, adj_date, adj_type, hours, reason) VALUES\n"
            . implode(",\n", $rows) . ';';
    }

    $header = "/*\n"
        . " * سەرچاوە مرۆییەکان — data brought over from Panjamor.\n"
        . " *\n"
        . " * Generated by database/tools/hr_import_panjamor.php from $source\n"
        . " * on " . date('Y-m-d H:i') . ". Do not edit by hand: generate a new file instead.\n"
        . " *\n"
        . " * Ids are Panjamor's own (an employee id is the fingerprint-device PIN) and\n"
        . " * every insert is INSERT IGNORE, so running it again, or a later file built\n"
        . " * from a newer dump, never duplicates a row.\n"
        . " *\n";
    foreach ($counts as $what => $n) {
        $header .= " *   " . str_pad($what, 12) . " $n\n";
    }
    $header .= " */\n\n";

    return ['sql' => $header . implode("\n\n", $parts) . "\n", 'counts' => $counts];
}

/** Split a phpMyAdmin dump into statements, respecting quotes and comments. */
function splitStatements(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buf .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $c . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $quote && $i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $c . $c;
                    $i += 2;
                    continue;
                }
                $buf .= $c;
                $i++;
                if ($c === $quote) {
                    break;
                }
            }
            continue;
        }
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            continue;
        }
        if (($ch === '-' && ($sql[$i + 1] ?? '') === '-') || $ch === '#') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $len : $end;
            continue;
        }
        if ($ch === ';') {
            $out[] = trim($buf);
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $ch;
        $i++;
    }
    $out[] = trim($buf);
    return array_values(array_filter($out, 'strlen'));
}
