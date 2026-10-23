<?php
/**
 * Run Debt Payment System Migration
 * Execute this file once to add the debt payment tables
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<pre>";
echo "=== Running Debt Payment System Migration ===\n\n";

$db = Database::getInstance()->getConnection();

try {
    // Execute each statement separately

    // 1. Create patient_debt_payments table
    echo "Creating patient_debt_payments table...\n";
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS patient_debt_payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_method ENUM('cash', 'card', 'transfer', 'credit', 'other') DEFAULT 'cash',
            received_by INT,
            notes TEXT,
            allocations JSON,
            credit_amount DECIMAL(12,2) DEFAULT 0.00,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "  OK\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  SKIP (already exists)\n";
        } else {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }
    }

    // 2. Create indexes for patient_debt_payments
    echo "Creating indexes for patient_debt_payments...\n";
    try {
        $db->exec("CREATE INDEX idx_patient_debt_payments_patient ON patient_debt_payments(patient_id)");
        echo "  OK (patient index)\n";
    } catch (PDOException $e) {
        echo "  SKIP (index may exist)\n";
    }
    try {
        $db->exec("CREATE INDEX idx_patient_debt_payments_date ON patient_debt_payments(payment_date)");
        echo "  OK (date index)\n";
    } catch (PDOException $e) {
        echo "  SKIP (index may exist)\n";
    }

    // 3. Create patient_credits table
    echo "Creating patient_credits table...\n";
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS patient_credits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL UNIQUE,
            balance DECIMAL(12,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "  OK\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  SKIP (already exists)\n";
        } else {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }
    }

    // 4. Create patient_credit_logs table
    echo "Creating patient_credit_logs table...\n";
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS patient_credit_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            type ENUM('credit', 'debit') NOT NULL,
            description TEXT,
            performed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "  OK\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  SKIP (already exists)\n";
        } else {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }
    }

    // 5. Create index for patient_credit_logs
    echo "Creating index for patient_credit_logs...\n";
    try {
        $db->exec("CREATE INDEX idx_patient_credit_logs_patient ON patient_credit_logs(patient_id)");
        echo "  OK\n";
    } catch (PDOException $e) {
        echo "  SKIP (index may exist)\n";
    }

    // 6. Add master_payment_id column to payments table
    echo "Adding master_payment_id column to payments table...\n";
    try {
        $db->exec("ALTER TABLE payments ADD COLUMN master_payment_id INT DEFAULT NULL");
        echo "  OK\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  SKIP (column already exists)\n";
        } else {
            echo "  SKIP: " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== Migration Complete ===\n";

    // Verify tables exist
    $tables = ['patient_debt_payments', 'patient_credits', 'patient_credit_logs'];
    echo "\nVerifying tables:\n";

    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if ($result->rowCount() > 0) {
            echo "  - $table: EXISTS\n";

            // Show table structure
            $cols = $db->query("DESCRIBE $table");
            foreach ($cols as $col) {
                echo "      {$col['Field']}: {$col['Type']}\n";
            }
        } else {
            echo "  - $table: MISSING!\n";
        }
    }

    // Check if master_payment_id column exists in payments table
    echo "\nChecking payments table for master_payment_id column:\n";
    $cols = $db->query("DESCRIBE payments");
    $hasColumn = false;
    foreach ($cols as $col) {
        if ($col['Field'] === 'master_payment_id') {
            echo "  - master_payment_id: EXISTS ({$col['Type']})\n";
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        echo "  - master_payment_id: MISSING (may need manual addition)\n";
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "\n<p><strong>You can now delete this file.</strong></p>";
echo "<p><a href='debts.php'>Go to Debts Page</a></p>";
