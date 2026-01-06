# Quick Start Guide

## Installation (5 Minutes)

### Option 1: Automated Installation (Recommended)

1. **Start Laragon**
   - Open Laragon
   - Start Apache and MySQL services

2. **Run Installation Script**
   - Open browser: `http://localhost/biinventory/install.php`
   - Follow the 2-step installation wizard
   - **Delete install.php after installation!**

3. **Login**
   - Go to: `http://localhost/biinventory/login.php`
   - Use the credentials you created during installation

### Option 2: Manual Installation

1. **Create Database**
   ```sql
   CREATE DATABASE biinventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Import Schema**
   - Open phpMyAdmin
   - Select `biinventory` database
   - Go to Import tab
   - Select `database/schema.sql`
   - Click Go

3. **Configure Database**
   - Edit `config/database.php`
   - Update database credentials if needed

4. **Set Admin Password** (Optional)
   - Access: `http://localhost/biinventory/setup_password.php`
   - Generate password hash
   - Update in database:
     ```sql
     UPDATE users SET password = 'generated_hash' WHERE username = 'admin';
     ```

5. **Login**
   - Username: `admin`
   - Password: `admin123` (or your custom password)

## Default Login

- **Username:** `admin`
- **Password:** `admin123`

⚠️ **Change this immediately after first login!**

## First Steps After Installation

1. ✅ Change admin password (Users > Edit Admin)
2. ✅ Add employees (Employees > Add Employee)
3. ✅ Add inventory items (Inventory > Add Item)
4. ✅ Create categories if needed (Categories > Add Category)
5. ✅ Assign items to employees (Assignments > New Assignment)

## System Features

### Dashboard (`index.php`)
- View total stock, assigned, and available quantities
- See recent assignments
- Monitor low stock items

### Inventory Management (`inventory.php`)
- Add/edit/delete inventory items
- Track stock levels automatically
- Filter by category, type, status

### Assignments (`assignments.php`)
- Assign items to employees
- Return items with condition updates
- Mark items as damaged/lost
- View assignment history

### Reports (`reports.php`)
- Stock summary
- Employee-wise assignments
- Category-wise inventory
- Assignment history

### User Management (`users.php`) - Super Admin Only
- Create/edit/delete users
- Assign roles (Super Admin, IT Admin, HR/Manager)

## Common Tasks

### Add New Inventory Item
1. Go to Inventory
2. Click "Add Item"
3. Fill in details (Name, Category, Type, Quantity)
4. Save

### Assign Items to Employee
1. Go to Assignments
2. Click "New Assignment"
3. Select item (shows available stock)
4. Select employee
5. Enter quantity (validated against available stock)
6. Set dates and condition
7. Save

### Return Items
1. Go to Assignments
2. Find active assignment
3. Click "Return" button
4. Set return date and condition
5. Save

### Generate Report
1. Go to Reports
2. Select report type (Summary, Employee-wise, Category-wise, History)
3. View/download report

## Troubleshooting

**Can't login?**
- Check database connection in `config/database.php`
- Verify user exists in database
- Check session directory is writable

**Stock not updating?**
- Check for active assignments
- Verify inventory item exists
- Check database transactions

**Permission denied?**
- Check user role
- Verify session is active
- Contact Super Admin

## Support

For detailed documentation, see `README.md`

---

**System Version:** 1.0.0

