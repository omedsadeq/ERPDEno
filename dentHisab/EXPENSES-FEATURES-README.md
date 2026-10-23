# Enhanced Expense Management System - Features Documentation
## سیستەمی زیرەکی کلینیکی ددان

### 📋 Overview
The enhanced expense management system includes advanced features for comprehensive expense tracking and reporting.

---

## 🆕 New Features Added

### 1. **File Attachment Upload** 📎
- Upload receipts, invoices, or supporting documents
- Supported formats: PDF, JPG, JPEG, PNG, GIF
- Maximum file size: 5MB
- Files stored in `/uploads/expenses/` directory
- View attachments directly from expense list
- Delete attachments when needed

**Database Column:** `attachment_path VARCHAR(255)`

---

### 2. **Subcategory Field** 🏷️
- Add subcategories within main expense categories
- Autocomplete feature with previously used subcategories
- Helps in detailed expense classification

**Example:**
- Main Category: "Lab Expenses"
- Subcategory: "Ceramic Materials"

**Database Column:** `subcategory VARCHAR(100)`

---

### 3. **Department Tracking** 🏢
- Track expenses by department or branch
- Autocomplete with existing departments
- Department-wise expense reports in sidebar
- Filter expenses by department

**Database Column:** `department VARCHAR(50)`

---

### 4. **Project/Case Tracking** 📊
- Associate expenses with specific projects
- Track project-wise spending
- Autocomplete with existing projects
- Useful for case-specific expenses

**Database Column:** `project_name VARCHAR(100)`

---

### 5. **Internal Notes** 📝
- Private notes visible only to administrators
- Separate from public descriptions
- Use for sensitive information or internal comments

**Database Column:** `internal_notes TEXT`

---

### 6. **Receipt Number** 🔢
- Store official receipt/invoice numbers
- Helps in document tracking and auditing
- Searchable field

**Database Column:** `receipt_number VARCHAR(50)`

---

### 7. **Payment Method Tracking** 💳
Options:
- **Cash** (کاش)
- **Bank Transfer** (گواستنەوەی بانکی)
- **Credit Card** (کارتی کڕین)
- **Check** (چێک)

**Features:**
- Payment method statistics in sidebar
- Filter by payment method
- Track payment preferences

**Database Column:** `payment_method VARCHAR(20) DEFAULT 'cash'`

---

### 8. **Approval Workflow** ✅
Status Options:
- **Pending** (چاوەڕوان) - Awaiting approval
- **Approved** (پەسەندکراو) - Approved and processed
- **Rejected** (ڕەتکراوە) - Rejected

**Features:**
- Color-coded status badges
- Filter by approval status
- Track pending expenses amount
- Useful for multi-level approval processes

**Database Column:** `status VARCHAR(20) DEFAULT 'approved'`

---

### 9. **Advanced Search & Filtering** 🔍
Filter by:
- Date range
- Category
- Department
- Status
- Payment method
- Search term (description, vendor, receipt number)

---

### 10. **Export to Excel/CSV** 📊
- Export filtered expenses to CSV format
- UTF-8 encoding with BOM for Kurdish text
- Includes all expense fields
- Filename: `expenses_YYYY-MM-DD.csv`

**Access:** Click "هەناردە بۆ Excel" button

---

### 11. **Enhanced Statistics Dashboard** 📈
Four main statistics cards:
1. **Total Expenses** - Sum of all expenses
2. **Number of Records** - Total expense count
3. **Pending Expenses** - Amount waiting approval
4. **Department Count** - Number of departments

**Sidebar Statistics:**
- Expenses by category
- Expenses by department
- Expenses by payment method

---

### 12. **Edit Functionality** ✏️
- Full edit capability for all expense fields
- Update attachments (upload new or delete existing)
- Preserve existing data when editing
- Validation and security checks

---

### 13. **Enhanced UI/UX** 🎨
- Modern gradient cards
- Responsive design
- Color-coded status badges
- Hover effects and animations
- Better mobile experience
- Improved table layout
- Icon-based navigation

---

## 📁 File Structure

```
/admin/
├── expenses-enhanced.php        # Main enhanced expense management page
├── export-expenses.php          # Excel/CSV export handler
├── database-updates-expenses.sql # Database schema updates
└── /uploads/
    └── /expenses/              # Uploaded attachment files
```

---

## 🗄️ Database Schema Updates

### Required Columns Added:
```sql
attachment_path    VARCHAR(255)   - File upload path
subcategory        VARCHAR(100)   - Expense subcategory
project_name       VARCHAR(100)   - Associated project
department         VARCHAR(50)    - Department name
internal_notes     TEXT           - Private admin notes
receipt_number     VARCHAR(50)    - Receipt/invoice number
payment_method     VARCHAR(20)    - Payment type
status             VARCHAR(20)    - Approval status
```

### Optional Tables (for future enhancement):
- `expense_subcategories` - Predefined subcategories
- `departments` - Department master list
- `projects` - Project master list

---

## 🚀 How to Use

### Step 1: Run Database Updates
```bash
# Navigate to phpMyAdmin or MySQL client
# Run the SQL file: database-updates-expenses.sql
```

### Step 2: Create Upload Directory
The directory is auto-created when you upload a file, but you can manually create it:
```bash
mkdir -p uploads/expenses
chmod 755 uploads/expenses
```

### Step 3: Access Enhanced Page
Navigate to: `/admin/expenses-enhanced.php`

### Step 4: Start Using New Features
1. Click "خەرجی نوێ" to add an expense
2. Fill in all relevant fields
3. Upload receipt (optional)
4. Select payment method and status
5. Save the expense

---

## 🔐 Security Features

1. **File Upload Security:**
   - File type validation
   - Size limit (5MB)
   - Unique filenames (prevents overwrites)
   - Stored outside web root option available

2. **Admin-Only Access:**
   - All expense management restricted to admins
   - Internal notes only visible to admins

3. **SQL Injection Prevention:**
   - Prepared statements used throughout
   - Input sanitization

4. **XSS Prevention:**
   - HTML special chars escaped
   - JSON encoding for JavaScript data

---

## 📊 Reporting Features

### Available Reports:
1. **By Category** - See expense breakdown by category
2. **By Department** - Track departmental spending
3. **By Payment Method** - Analyze payment preferences
4. **By Status** - Monitor approval pipeline

### Export Options:
- Export to CSV/Excel
- Filtered exports based on search criteria
- All fields included in export

---

## 🎯 Use Cases

### 1. Dental Clinic Operations
- Track lab expenses with project (patient case) reference
- Categorize by department (general, lab, inventory)
- Upload lab invoices as attachments

### 2. Multi-Branch Management
- Department field for branch tracking
- Department-wise expense reports
- Compare spending across branches

### 3. Approval Workflow
- Staff submits expenses with "pending" status
- Manager reviews and approves/rejects
- Track pending expenses amount

### 4. Project-Based Tracking
- Associate expenses with specific projects
- Track project budgets
- Project-wise expense analysis

---

## 🔧 Configuration

### File Upload Settings
Edit in `expenses-enhanced.php`:
```php
$uploadDir = __DIR__ . '/../uploads/expenses/';  // Upload directory
$maxFileSize = 5 * 1024 * 1024;                  // 5MB limit
$allowedTypes = ['.pdf','.jpg','.jpeg','.png','.gif']; // Allowed types
```

### Payment Methods
Add/edit in form:
```php
<option value="cash">کاش</option>
<option value="bank_transfer">گواستنەوەی بانکی</option>
<option value="credit_card">کارتی کڕین</option>
<option value="check">چێک</option>
// Add more as needed
```

---

## 🐛 Troubleshooting

### Upload Directory Permission Error
```bash
chmod 755 uploads/expenses
# or
chmod 777 uploads/expenses  # if 755 doesn't work
```

### File Upload Fails
Check `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```

### Database Columns Missing
Run the SQL update script:
```sql
source database-updates-expenses.sql;
```

---

## 📝 Field Descriptions (Kurdish/English)

| Kurdish | English | Database Column |
|---------|---------|----------------|
| پۆل | Category | category_id |
| ژێرپۆل | Subcategory | subcategory |
| وەسف | Description | description |
| بڕ | Amount | amount |
| بەروار | Date | expense_date |
| فرۆشیار | Vendor | vendor |
| ژمارەی وەسڵ | Receipt Number | receipt_number |
| شێوازی پارەدان | Payment Method | payment_method |
| بەش | Department | department |
| پڕۆژە | Project | project_name |
| تێبینی نهێنی | Internal Notes | internal_notes |
| پاشکۆ | Attachment | attachment_path |
| دۆخ | Status | status |

---

## 🎓 Training Tips

1. **Start Simple:** Begin with basic fields, gradually adopt advanced features
2. **Use Templates:** Create common expense templates with subcategories
3. **Regular Exports:** Export data monthly for backup
4. **Review Pending:** Check pending expenses daily/weekly
5. **Department Standards:** Standardize department names across entries

---

## 🔮 Future Enhancement Ideas

1. Recurring expenses (monthly/yearly automatic entries)
2. Budget limits per category/department
3. Expense approval notifications
4. Advanced analytics and charts
5. Multi-currency support
6. Expense reimbursement workflow
7. Integration with accounting software
8. Mobile app for expense submission

---

## 📞 Support

For issues or questions:
1. Check this documentation first
2. Review the database schema
3. Check server error logs
4. Verify file permissions

---

## 📄 License & Credits

**System:** Dental Clinic Management System (سیستەمی زیرەکی کلینیکی ددان)
**Enhanced By:** Claude AI Assistant
**Date:** 2025

---

### Quick Reference Card

| Feature | Field Name | Required | Type |
|---------|-----------|----------|------|
| Category | category | ✅ Yes | Dropdown |
| Subcategory | subcategory | ❌ No | Text + Autocomplete |
| Date | date | ✅ Yes | Date |
| Amount | amount | ✅ Yes | Number |
| Description | description | ✅ Yes | Text |
| Vendor | vendor | ❌ No | Text |
| Receipt # | receipt_number | ❌ No | Text |
| Payment Method | payment_method | ❌ No | Dropdown |
| Department | department | ❌ No | Text + Autocomplete |
| Project | project_name | ❌ No | Text + Autocomplete |
| Status | status | ❌ No | Dropdown |
| Internal Notes | internal_notes | ❌ No | Textarea |
| Attachment | attachment | ❌ No | File Upload |

---

**End of Documentation**
