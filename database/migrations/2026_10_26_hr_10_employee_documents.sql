/*
 * سەرچاوە مرۆییەکان — an employee's documents (hr/employee.php).
 *
 * Photo, national card, information card, health card, licence, contract …
 * one row per uploaded file; a national card's front and back are two rows.
 * The files live in uploads/hr_documents/ under random names, which denies
 * direct HTTP access, and are handed out only by hr/employee-doc.php after a
 * permission check (classes/HrDocument.php). expiry_date is optional and
 * drives the «بەسەرچووە / نزیکە» badges on licences and health cards.
 *
 * Two keys, closed by default for every role (admins hold every key): ID
 * cards and contracts are more private than the employee list, so seeing them
 * is not part of hr.employees.view.
 */

CREATE TABLE IF NOT EXISTS hr_employee_documents (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT          NOT NULL,
    doc_type       VARCHAR(30)  NOT NULL,
    title          VARCHAR(150) NULL,
    expiry_date    DATE         NULL,
    file_name      VARCHAR(80)  NOT NULL,
    original_name  VARCHAR(255) NULL,
    mime_type      VARCHAR(100) NOT NULL,
    file_size      INT          NOT NULL DEFAULT 0,
    uploaded_by    INT          NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hr_doc_employee (employee_id, doc_type),
    CONSTRAINT fk_hr_doc_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_hr_doc_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
    ('hr.documents.view',   'View Employee Documents',   'بینینی بەڵگەنامەکانی کارمەند',            'hr', 'Photo, ID cards, licences and contracts of employees'),
    ('hr.documents.manage', 'Manage Employee Documents', 'بارکردن و سڕینەوەی بەڵگەنامەکانی کارمەند', 'hr', 'Upload and delete employee documents');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('accountant',      'hr.documents.view',   0),
    ('accountant',      'hr.documents.manage', 0),
    ('receptionist',    'hr.documents.view',   0),
    ('receptionist',    'hr.documents.manage', 0),
    ('doctor',          'hr.documents.view',   0),
    ('doctor',          'hr.documents.manage', 0),
    ('xray_department', 'hr.documents.view',   0),
    ('xray_department', 'hr.documents.manage', 0);
