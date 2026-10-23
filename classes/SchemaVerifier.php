<?php
/**
 * SchemaVerifier - Post-restore / boot-time schema validation
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Answers one question: is this database complete enough to run the app?
 *
 * Two depths:
 *   - coreOk()  a two-table check cheap enough to run on every request
 *               (Database::assertCoreSchema uses it to decide on recovery.php)
 *   - verify()  full table + key-column audit, run after a restore before the
 *               admin is allowed back into the system
 *
 * Only the base schema is required here. Tables added by later migrations are
 * intentionally absent from the list: RestoreManager deletes migrations.lock
 * after a restore, so MigrationRunner brings an older dump up to date on the
 * next request. Requiring them would fail a perfectly good older backup.
 */

class SchemaVerifier {

    /** Present in every valid dump — the app cannot start without these. */
    const CORE_TABLES = ['users', 'patients'];

    /** Base schema (database/schema.sql). A restore that misses any of these is incomplete. */
    const REQUIRED_TABLES = [
        'users', 'patients', 'patient_medical_conditions', 'patient_family',
        'patient_folders', 'cases', 'treatments', 'dental_chart', 'case_treatments',
        'payments', 'installment_plans', 'installment_schedule', 'appointments',
        'waiting_room', 'expense_categories', 'expenses', 'lab_orders',
        'inventory_items', 'inventory_transactions', 'patient_images',
        'prescriptions', 'prescription_items', 'settings', 'activity_log',
        'notifications',
    ];

    /**
     * Columns the code would crash on if a dump predates them or arrives
     * truncated. Deliberately short — the load-bearing ones only.
     */
    const REQUIRED_COLUMNS = [
        'users'          => ['id', 'username', 'password', 'role', 'full_name'],
        'patients'       => ['id', 'full_name', 'phone'],
        'patient_folders'=> ['id', 'patient_id', 'doctor_id'],
        'cases'          => ['id', 'folder_id', 'total_amount', 'paid_amount'],
        'payments'       => ['id', 'case_id', 'amount', 'payment_date'],
        'appointments'   => ['id', 'patient_id', 'appointment_date'],
        'settings'       => ['setting_key', 'setting_value'],
    ];

    /** Cheap boot check: are the two tables every page touches present? */
    public static function coreOk(PDO $pdo) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)"
            );
            $stmt->execute(self::CORE_TABLES);
            return (int)$stmt->fetchColumn() >= count(self::CORE_TABLES);
        } catch (PDOException $e) {
            return true; // information_schema hiccup — don't bounce a working site to recovery
        }
    }

    /**
     * Full audit. Returns:
     *   ok               bool  — safe to use the system
     *   missing_tables   string[]
     *   missing_columns  ['table' => [columns]]
     *   table_count      int   — total tables actually in the database
     *   summary          string (Kurdish, ready to show)
     */
    public static function verify(PDO $pdo) {
        $existing = self::tableNames($pdo);
        if ($existing === null) {
            return [
                'ok'              => false,
                'missing_tables'  => [],
                'missing_columns' => [],
                'table_count'     => 0,
                'summary'         => 'نەتوانرا خشتەکانی داتابەیس بخوێنرێنەوە',
            ];
        }

        $lookup        = array_flip(array_map('strtolower', $existing));
        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!isset($lookup[strtolower($table)])) {
                $missingTables[] = $table;
            }
        }

        $missingColumns = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (!isset($lookup[strtolower($table)])) {
                continue; // already reported as a missing table
            }
            $have = self::columnNames($pdo, $table);
            if ($have === null) {
                continue;
            }
            $haveLookup = array_flip(array_map('strtolower', $have));
            foreach ($columns as $column) {
                if (!isset($haveLookup[strtolower($column)])) {
                    $missingColumns[$table][] = $column;
                }
            }
        }

        $ok = empty($missingTables) && empty($missingColumns);
        return [
            'ok'              => $ok,
            'missing_tables'  => $missingTables,
            'missing_columns' => $missingColumns,
            'table_count'     => count($existing),
            'summary'         => self::summarize($ok, $missingTables, $missingColumns, count($existing)),
        ];
    }

    private static function summarize($ok, array $missingTables, array $missingColumns, $tableCount) {
        if ($ok) {
            return 'هەموو خشتە و ستوونە پێویستەکان بوونیان هەیە (' . $tableCount . ' خشتە)';
        }
        $parts = [];
        if (!empty($missingTables)) {
            $parts[] = count($missingTables) . ' خشتە بوونیان نییە: ' . implode('، ', array_slice($missingTables, 0, 8))
                . (count($missingTables) > 8 ? '...' : '');
        }
        foreach ($missingColumns as $table => $columns) {
            $parts[] = 'ستوونی ' . implode('، ', $columns) . ' لە خشتەی ' . $table . ' بوونی نییە';
        }
        return implode(' — ', $parts);
    }

    private static function tableNames(PDO $pdo) {
        try {
            $stmt = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return null;
        }
    }

    private static function columnNames(PDO $pdo, $table) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return null;
        }
    }
}
