# 🚀 Quick Start Guide - Enhanced Expense Management
## سیستەمی زیرەکی کلینیکی ددان

---

## ⚡ Quick Setup (5 Minutes)

### Step 1: Run Database Updates
1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select your database (`dentHisab`)
3. Click "SQL" tab
4. Copy and paste contents from `admin/database-updates-expenses.sql`
5. Click "Go"

**OR** Run via command line:
```bash
mysql -u root -p dentHisab < admin/database-updates-expenses.sql
```

### Step 2: Verify Upload Directory
The directory should be auto-created, but verify:
```bash
# Check if directory exists
dir c:\xampp\htdocs\dentHisab\uploads\expenses
```

### Step 3: Access the System
Navigate to:
- **Enhanced Expenses:** `http://localhost/dentHisab/admin/expenses-enhanced.php`
- **Analytics:** `http://localhost/dentHisab/admin/expense-analytics.php`

---

## 📱 Page Access Links

### Main Pages:
| Page | URL | Description |
|------|-----|-------------|
| **Enhanced Expenses** | `/admin/expenses-enhanced.php` | Full-featured expense management |
| **Expense Analytics** | `/admin/expense-analytics.php` | Charts, reports, comparisons |
| **Expense Groups** | `/admin/expense-groups.php` | Manage categories |
| **Original Expenses** | `/admin/expenses.php` | Simple version |
| **General Expenses** | `/admin/expenses-general.php` | General category only |
| **Lab Expenses** | `/admin/expenses-lab.php` | Lab category only |
| **Inventory Expenses** | `/admin/expenses-inventory.php` | Inventory category only |

### Export & API:
| Feature | URL |
|---------|-----|
| **Export to Excel** | `/admin/export-expenses.php?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` |
| **Subcategories API** | `/api/get-subcategories.php?category_id=1` |

---

## 🎯 Common Tasks

### Add a New Expense
1. Go to: `/admin/expenses-enhanced.php`
2. Click "خەرجی نوێ" (New Expense) button
3. Fill in the form:
   - **Required:** Category, Date, Amount, Description
   - **Optional:** All other fields
4. Upload receipt (optional)
5. Click "زیادکردن" (Add)

### Upload an Attachment
1. In the expense form
2. Scroll to "پاشکۆ (PDF, Image)" field
3. Click "Choose File"
4. Select PDF, JPG, PNG, or GIF (max 5MB)
5. Submit the form

### Edit an Expense
1. Find the expense in the table
2. Click the pencil/edit icon <i class="fas fa-edit"></i>
3. Modify any field
4. To change attachment: select a new file
5. To delete attachment: click delete button in edit dialog
6. Click "پاشەکەوتکردن" (Save)

### Filter Expenses
Use the filter panel at top:
- **Search:** Enter text to search description/vendor/receipt#
- **Category:** Select from dropdown
- **Department:** Select from dropdown
- **Status:** pending/approved/rejected
- **Date Range:** From/To dates
- Click "پاڵاوتن" (Filter)

### Export to Excel
1. Apply any filters you want
2. Click "هەناردە بۆ Excel" button
3. File downloads as `expenses_YYYY-MM-DD.csv`
4. Open in Excel or Google Sheets

### View Analytics
1. Go to: `/admin/expense-analytics.php`
2. See:
   - Current month vs last month comparison
   - Top categories
   - Top vendors
   - Average/min/max by category
   - Expenses by day of week
3. Export button at bottom

---

## 🔧 Configuration

### Change Upload Directory
Edit `expenses-enhanced.php` line ~44:
```php
$uploadDir = __DIR__ . '/../uploads/expenses/';
```

### Change File Size Limit
1. Edit `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```
2. Restart Apache

### Add More Payment Methods
Edit `expenses-enhanced.php` lines ~666-670:
```php
<select name="payment_method" class="form-control">
    <option value="cash">کاش</option>
    <option value="bank_transfer">گواستنەوەی بانکی</option>
    <option value="credit_card">کارتی کڕین</option>
    <option value="check">چێک</option>
    <!-- Add more here -->
</select>
```

---

## 📊 Understanding the Fields

### Core Fields (Required)
- **پۆل (Category):** Main expense category
- **بەروار (Date):** Expense date
- **بڕ (Amount):** Expense amount
- **وەسف (Description):** What the expense is for

### Extended Fields (Optional)
- **ژێرپۆل (Subcategory):** More specific categorization
- **فرۆشیار (Vendor):** Who you paid
- **ژمارەی وەسڵ (Receipt Number):** Invoice/receipt number
- **شێوازی پارەدان (Payment Method):** How you paid
- **بەش (Department):** Which department/branch
- **پڕۆژە (Project):** Associated project/case
- **تێبینی نهێنی (Internal Notes):** Admin-only notes
- **پاشکۆ (Attachment):** Upload receipt/invoice
- **دۆخ (Status):** Approval status

---

## 💡 Pro Tips

### 1. Use Subcategories for Detail
Instead of:
- Description: "Lab materials - ceramic"

Better:
- Category: "Lab Expenses"
- Subcategory: "Ceramic Materials"
- Description: "Premium ceramic blocks"

### 2. Standardize Department Names
Create a list of departments and use consistently:
- "Clinic Main"
- "Clinic Branch 1"
- "Laboratory"
- "Reception"

### 3. Use Projects for Cases
- Project Name: "Patient-1234"
- Description: "Crown preparation materials"

### 4. Internal Notes for Sensitive Info
- Public Description: "Office supplies"
- Internal Notes: "Specifically for Dr. Ahmed's office renovation"

### 5. Regular Exports
- Export monthly for backup
- Keep in cloud storage (Google Drive/Dropbox)

### 6. Use Pending Status for Approval
- Staff submits with status: "Pending"
- Manager reviews expenses daily
- Approves or rejects

---

## 🔍 Filtering & Searching

### Search Tips:
The search box searches in:
- Description
- Vendor name
- Receipt number

Examples:
- Search "ceramic" → finds all ceramic-related expenses
- Search "Amazon" → finds all Amazon purchases
- Search "INV-123" → finds receipt INV-123

### Filter Combinations:
- **Monthly Review:** Date range + Status: Pending
- **Department Audit:** Department + Date range
- **Vendor Analysis:** Search vendor + Date range
- **Project Tracking:** Not available in filter (use search)

---

## 📈 Analytics Explained

### Current vs Last Month
- Shows if spending is increasing/decreasing
- Percentage change calculation
- Green arrow (↓) = decrease (good)
- Red arrow (↑) = increase (may need attention)

### Top Categories
- Ranked 1-5 by total amount
- Progress bars show relative spending
- Focus on top categories for cost reduction

### Top Vendors
- Your most frequent suppliers
- Negotiate better rates with top vendors
- Consider consolidating vendors

### Day of Week Analysis
- Which days have most expenses
- Plan budget releases accordingly
- Identify spending patterns

---

## 🐛 Troubleshooting

### "Upload directory not found"
```bash
mkdir c:\xampp\htdocs\dentHisab\uploads\expenses
```

### "Permission denied" on upload
```bash
chmod 755 c:\xampp\htdocs\dentHisab\uploads\expenses
# or
chmod 777 c:\xampp\htdocs\dentHisab\uploads\expenses
```

### "Unknown column 'status'"
- You didn't run the SQL updates
- Go back to Step 1

### File upload fails
1. Check `php.ini` settings
2. Check upload directory permissions
3. Check file size < 5MB
4. Check file type is allowed

### CSV export shows garbled text
- File is UTF-8 with BOM
- Open in Excel using "Data → From Text/CSV"
- Or use Google Sheets (auto-detects)

---

## 📞 Feature Checklist

- ✅ File attachments (PDF, images)
- ✅ Subcategory field
- ✅ Department tracking
- ✅ Project tracking
- ✅ Internal notes (admin-only)
- ✅ Receipt number field
- ✅ Payment method dropdown
- ✅ Approval workflow (pending/approved/rejected)
- ✅ Advanced search & filtering
- ✅ Export to Excel/CSV
- ✅ Edit functionality
- ✅ Delete with attachment cleanup
- ✅ Statistics dashboard
- ✅ Analytics page with charts
- ✅ Top categories report
- ✅ Top vendors report
- ✅ Day of week analysis
- ✅ Average/min/max calculations
- ✅ Month-over-month comparison
- ✅ Autocomplete for departments/projects
- ✅ Responsive design

---

## 🎓 Video Tutorial (Coming Soon)

1. Adding your first expense
2. Uploading attachments
3. Using filters effectively
4. Exporting to Excel
5. Understanding analytics

---

## 📚 Related Documentation

- **Full Features:** `EXPENSES-FEATURES-README.md`
- **Database Schema:** `admin/database-updates-expenses.sql`

---

## ✨ Next Steps

1. ✅ Run database updates
2. ✅ Access enhanced page
3. ✅ Add a test expense with attachment
4. ✅ Explore analytics page
5. ✅ Export to Excel
6. ✅ Configure for your needs

---

**Version:** 1.0
**Last Updated:** 2025-12-20
**System:** DentHisab Clinic Management

---

## 🎉 You're All Set!

The enhanced expense management system is ready to use.

**Main URL:** `/admin/expenses-enhanced.php`

Happy expense tracking! 📊💰
