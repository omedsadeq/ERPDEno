# X-Ray Payment Routing Workflow Implementation Guide

## Overview
Implemented a payment-based routing workflow for X-Ray requests that allows doctors to select between three payment options: **Cash**, **Don't Cash**, and **Debt**. Based on the selection, requests are routed through the accountant for approval before reaching the X-Ray department.

---

## Database Changes

### New Migration File
**File:** `database/migrations/add_xray_payment_workflow_statuses.sql`

**New Fields Added to `xray_requests` Table:**
1. `payment_type` (ENUM: 'cash', 'dont_cash', 'debt') - Payment method selected by doctor
2. `request_status` (VARCHAR 50) - New workflow status field:
   - `pending_accountant` - Awaiting accountant approval (for Cash/Debt)
   - `pending_xray` - Ready for X-Ray department processing
   - `completed` - X-Ray scan completed
   - `cancelled` - Request cancelled
3. `accountant_id` (INT) - User ID of the accountant who approved the request
4. `accountant_approved_at` (DATETIME) - Timestamp of accountant approval

**Indexes Added:**
- `idx_xray_request_status` - For filtering by request_status
- `idx_xray_payment_type` - For filtering by payment_type
- `idx_xray_accountant_id` - For filtering by accountant
- `idx_xray_accountant_approved_at` - For date-based reporting

**SQL Injection Protection:**
- All queries use prepared statements with parameterized queries
- Foreign key added for `accountant_id` referencing `users(id)`

---

## Workflow Logic

### 1. Doctor Creates X-Ray Request (`xray/request.php`)

**Payment Type Selection:**
- **Cash**: Request goes to accountant for payment collection
- **Don't Cash**: Request bypasses accountant, goes directly to X-Ray dept
- **Debt**: Request goes to accountant for debt recording/approval

**Status Assignments:**
```php
if ($paymentType === 'dont_cash') {
    $request_status = 'pending_xray';      // Go directly to X-Ray
} else {  // 'cash' or 'debt'
    $request_status = 'pending_accountant'; // Go to Accountant first
}
```

**Database Update (with Prepared Statements):**
```php
$db->query(
    "UPDATE xray_requests SET request_status = :request_status, status = :status WHERE id = :id",
    [
        'request_status' => $initialStatus,
        'status' => $initialStatus,
        'id' => $newRequestId
    ]
);
```

---

### 2. Accountant Reviews & Approves (`accountant/pending-reports.php`)

**Query to Fetch Pending Requests:**
```php
$xrayRequestsQuery = "
    SELECT xr.*, p.full_name AS patient_name, u.full_name AS doctor_name
    FROM xray_requests xr
    JOIN patients p ON xr.patient_id = p.id
    LEFT JOIN users u ON xr.requested_by = u.id
    WHERE xr.request_status = 'pending_accountant'
    ORDER BY xr.created_at DESC
";
```

**Approval Action (Prepared Statement):**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_xray_id'])) {
    $xrayId = intval($_POST['approve_xray_id']);
    $db->query(
        "UPDATE xray_requests SET request_status = :request_status, status = :status, 
         accountant_id = :accountant_id, accountant_approved_at = NOW() WHERE id = :id",
        [
            'request_status' => 'pending_xray',
            'status' => 'pending_xray',
            'accountant_id' => $currentUser['id'],
            'id' => $xrayId
        ]
    );
}
```

**Rejection Action:**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_xray_id'])) {
    $xrayId = intval($_POST['reject_xray_id']);
    $db->query(
        "UPDATE xray_requests SET request_status = :request_status, status = :status WHERE id = :id",
        [
            'request_status' => 'cancelled',
            'status' => 'cancelled',
            'id' => $xrayId
        ]
    );
}
```

---

### 3. X-Ray Department Processes Requests (`xray/index.php`)

**Default Filter for X-Ray Department:**
```php
// X-ray department sees only pending_xray requests by default
if ($auth->isXrayDepartment() && empty($_GET['status'])) {
    $baseFilters['request_status'] = 'pending_xray';
}
```

**Display Status:**
- Shows only requests with `request_status = 'pending_xray'`
- Other staff can see their own requests
- Admin can see all requests

**Status Display in Table:**
```php
$displayStatus = !empty($request['request_status']) ? $request['request_status'] : $request['status'];
$statusBadges = [
    'pending_xray' => ['badge-yellow', 'چاوەڕوانی ئێکسڕەی'],
    'pending_accountant' => ['badge-orange', 'چاوەڕوانی ژمێریار'],
    'completed' => ['badge-green', 'تەواوبوو'],
    ...
];
```

---

## Class Updates

### XrayManager Class (`classes/XrayManager.php`)

**Updated Methods for Request Status Support:**

1. **getAll() Method** - Now supports both `status` and `request_status` filters:
   ```php
   if (!empty($filters['status'])) {
       $sql .= " AND (xr.status = :status OR xr.request_status = :status)";
       $params['status'] = $filters['status'];
   }
   if (!empty($filters['request_status'])) {
       $sql .= " AND xr.request_status = :request_status";
       $params['request_status'] = $filters['request_status'];
   }
   ```

2. **count() Method** - Backward compatible counting:
   ```php
   if (!empty($filters['status'])) {
       $sql .= " AND (xr.status = :status OR xr.request_status = :status)";
   }
   if (!empty($filters['request_status'])) {
       $sql .= " AND xr.request_status = :request_status";
   }
   ```

3. **getStatistics() Method** - Updated to report by request_status:
   ```php
   $sql = "SELECT 
               COALESCE(NULLIF(request_status, ''), status) as status_key,
               COUNT(*) as count 
           FROM xray_requests 
           WHERE {$where} 
           GROUP BY status_key";
   ```

**All Queries Use Prepared Statements:**
- ✅ No SQL injection vulnerabilities
- ✅ Parameterized inputs for all user data
- ✅ Type-safe integer casting for IDs

---

## Request Workflow Diagram

```
Doctor Creates Request
        ↓
    ┌───────────────────────────────┐
    │   Select Payment Type         │
    └───────────────────────────────┘
              ↙        ↓        ↖
        CASH      DON'T CASH    DEBT
         ↓             ↓         ↓
    [PENDING_      [PENDING_   [PENDING_
     ACCOUNTANT]    XRAY]      ACCOUNTANT]
         ↓             ↓         ↓
    Accountant   X-Ray Dept   Accountant
    Approves     Processes    Records
    Payment      Request      Debt
         ↓             ↓         ↓
    [PENDING_      [IN_PROG]  [PENDING_
     XRAY]                     XRAY]
         ↓                      ↓
         └──────────────────────┘
                    ↓
             X-Ray Department
             Scans Patient
                    ↓
              [COMPLETED]
```

---

## UI Changes

### Payment Type Buttons (`xray/request.php`)
Three clear buttons:
- 🟢 **Cash** - Green styling (normal payment workflow)
- 🟠 **Don't Cash** - Orange styling (skip accountant)
- 🔴 **Debt** - Red styling (debt tracking)

### Pending Reports Table (`accountant/pending-reports.php`)
New section showing:
- Patient name
- Doctor name
- X-Ray type and reason
- Payment type badge
- Amount
- Approve/Reject buttons (with confirmations)
- Timestamps

### X-Ray List (`xray/index.php`)
Status badges:
- `pending_xray` (Yellow) - Ready for X-Ray processing
- `pending_accountant` (Orange) - Waiting for approval
- `in_progress` (Blue) - Being processed
- `completed` (Green) - Scan completed
- `cancelled` (Red) - Request cancelled

---

## Security Implementation

✅ **SQL Injection Prevention:**
- All database queries use prepared statements
- Parameterized placeholders (`:id`, `:status`, etc.)
- No string concatenation for user data

✅ **Authorization Checks:**
- Accountant role check in pending-reports.php
- X-Ray department visibility filtering
- User ID validation on approval

✅ **Data Validation:**
- Integer casting for IDs: `intval($_POST['...'])`
- Enum validation for payment_type
- Status whitelist validation

✅ **Foreign Key Constraints:**
- `accountant_id` references `users(id)` with `ON DELETE SET NULL`
- `patient_id` references `patients(id)` with `ON DELETE CASCADE`

---

## Testing Checklist

### Doctor Flow
- [ ] Create X-Ray request with Cash payment
- [ ] Create X-Ray request with Don't Cash payment
- [ ] Create X-Ray request with Debt payment
- [ ] Verify redirect to appropriate page

### Accountant Flow
- [ ] View pending X-Ray requests in pending-reports.php
- [ ] Approve Cash request → Status becomes pending_xray
- [ ] Approve Debt request → Status becomes pending_xray
- [ ] Reject request → Status becomes cancelled
- [ ] Verify accountant_id and accountant_approved_at are recorded

### X-Ray Department Flow
- [ ] View only pending_xray requests
- [ ] Don't Cash requests visible immediately
- [ ] Cash requests visible after accountant approval
- [ ] Debt requests visible after accountant approval
- [ ] Update request status to completed after scanning

### Admin/Statistics
- [ ] Dashboard shows correct pending counts
- [ ] Status filters work correctly
- [ ] Reports show accountant approval info

---

## Migration Steps

### 1. Run Database Migration
```bash
mysql -u root -p dental_clinics_soran < database/migrations/add_xray_payment_workflow_statuses.sql
```

### 2. Verify Database Fields
```sql
DESCRIBE xray_requests;
-- Check for: payment_type, request_status, accountant_id, accountant_approved_at
```

### 3. Update File Permissions
```bash
chmod 755 xray/request.php
chmod 755 accountant/pending-reports.php
chmod 755 xray/index.php
chmod 755 classes/XrayManager.php
```

### 4. Clear Cache (if applicable)
```bash
# If using Redis or file-based cache
rm -rf /tmp/cache/*
```

### 5. Test Workflow
- Create a test X-Ray request with Cash payment
- Verify it appears in accountant/pending-reports.php
- Approve the request
- Verify it appears in xray/index.php for X-Ray dept

---

## Backward Compatibility

✅ **Existing Requests:**
- Old requests without `request_status` still work
- Status field is checked if request_status is empty
- Both fields are updated together for new requests
- Statistics properly handle null request_status

✅ **Role Permissions:**
- No new roles introduced
- Uses existing: accountant, doctor, xray_department, admin
- Accountant permissions already configured

---

## Important Files Modified

1. **xray/request.php**
   - Updated request creation to set request_status
   - Added redirect logic based on payment_type

2. **accountant/pending-reports.php**
   - Updated X-Ray query to use request_status = 'pending_accountant'
   - Updated approval/rejection logic with prepared statements

3. **xray/index.php**
   - Added default filter for pending_xray status for X-Ray dept
   - Updated status display logic to use request_status
   - Added status badge color for pending_accountant

4. **classes/XrayManager.php**
   - Updated getAll() for request_status support
   - Updated count() for request_status support
   - Updated getStatistics() for proper status reporting

5. **database/migrations/add_xray_payment_workflow_statuses.sql**
   - New migration with all required schema changes

---

## Support & Troubleshooting

### Issue: X-Ray request doesn't appear in pending-reports.php
**Solution:** Check if `request_status` column exists and has value 'pending_accountant'
```sql
SELECT id, request_status, payment_type FROM xray_requests WHERE id = ?;
```

### Issue: Status not updating after accountant approval
**Solution:** Verify database connection and check error logs
```sql
SELECT id, request_status, accountant_id, accountant_approved_at FROM xray_requests WHERE id = ?;
```

### Issue: X-Ray dept sees requests they shouldn't
**Solution:** Verify role check in xray/index.php and filter logic

---

## Additional Notes

- All timestamps are in UTC (database default)
- Consider adding audit logging for accountant approvals
- May need email notifications when status changes
- Dashboard widgets should be updated to show new pending_accountant count
- Reports should include accountant approval times and user info

