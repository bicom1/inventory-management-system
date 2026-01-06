# BI Communications Inventory & Asset Management System

A complete, fully functional Inventory Management System built with Core PHP and MySQL for tracking IT assets and furniture, monitoring stock levels, assigning items to employees, managing returns, and generating reports.

## Features

### Core Functionality
- ✅ **Inventory Management** - Add, edit, delete inventory items (assets & furniture)
- ✅ **Stock Tracking** - Automatic calculation of total, assigned, and available stock
- ✅ **Employee Management** - Complete employee database with department and position tracking
- ✅ **Assignment System** - Assign multiple items to employees with stock validation
- ✅ **Return Management** - Return items with condition updates
- ✅ **Assignment History** - Complete audit trail of all assignments (no deletion)
- ✅ **Category Management** - Manage item categories
- ✅ **Dashboard** - Real-time statistics and overview
- ✅ **Reports** - Employee-wise, category-wise, and stock summary reports
- ✅ **Audit Logs** - Complete system activity logging
- ✅ **User Management** - Role-based access control

### Security Features
- ✅ Session-based authentication
- ✅ Bcrypt password hashing
- ✅ Prepared statements (SQL injection prevention)
- ✅ Role-based access control (Super Admin, IT Admin, HR/Manager)
- ✅ Session timeout (30 minutes)
- ✅ Input sanitization

### Asset Categories
- Desktop PC
- Laptop
- Monitor
- Mouse
- Keyboard
- Headset
- Furniture (Chair, Desk, Table, Cabinet, etc.)
- Other (custom categories)

## Technology Stack

- **Backend**: Core PHP (Procedural/OOP, no frameworks)
- **Database**: MySQL / MariaDB
- **Frontend**: HTML, CSS, JavaScript, Bootstrap 5
- **Server**: Apache (Laragon)
- **Security**: Sessions, bcrypt, prepared statements

## Installation & Setup

### Prerequisites
- Laragon (or any Apache + MySQL + PHP environment)
- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.3+

### Step 1: Database Setup

1. Open Laragon and start Apache and MySQL services
2. Open phpMyAdmin or MySQL command line
3. Import the database schema:
   ```sql
   -- Option 1: Via phpMyAdmin
   -- Go to phpMyAdmin > Import > Select database/schema.sql
   
   -- Option 2: Via MySQL command line
   mysql -u root -p < database/schema.sql
   ```

### Step 2: Database Configuration

Edit `config/database.php` and update database credentials if needed:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // Your MySQL password
define('DB_NAME', 'biinventory');
```

### Step 3: Base URL Configuration

Edit `config/config.php` and update BASE_URL if your installation path differs:
```php
define('BASE_URL', 'http://localhost/biinventory/');
```

### Step 4: Access the System

1. Open your browser and navigate to: `http://localhost/biinventory/`
2. You will be redirected to the login page
3. **Default Login Credentials:**
   - Username: `admin`
   - Password: `admin123`

**⚠️ IMPORTANT:** Change the default password immediately after first login!

### Step 5: Generate New Admin Password Hash (Optional)

If you need to reset the admin password, run this PHP script:
```php
<?php
echo password_hash('your_new_password', PASSWORD_BCRYPT);
?>
```

Then update the password hash in the `users` table.

## User Roles & Permissions

### Super Admin
- Full system access
- User management
- Audit logs access
- All inventory operations

### IT Admin
- Inventory management
- Assignment operations
- Employee management
- Category management
- Reports (read-only)

### HR/Manager
- Reports (read-only)
- View dashboard
- View assignments

## System Structure

```
biinventory/
├── assets/
│   ├── css/
│   │   └── style.css          # Custom styles
│   └── js/
│       └── main.js            # JavaScript functions
├── config/
│   ├── config.php             # Main configuration
│   └── database.php           # Database connection
├── database/
│   └── schema.sql             # Database schema
├── includes/
│   ├── functions.php          # Helper functions
│   ├── header.php             # Page header
│   └── footer.php             # Page footer
├── ajax/
│   └── get_employee_assignments.php  # AJAX endpoints
├── index.php                  # Dashboard
├── login.php                  # Login page
├── logout.php                 # Logout handler
├── inventory.php             # Inventory management
├── employees.php              # Employee management
├── assignments.php            # Assignment management
├── categories.php             # Category management
├── reports.php                # Reports
├── users.php                  # User management (Super Admin only)
└── audit_logs.php            # Audit logs (Super Admin only)
```

## Key Features Explained

### Stock Management
- **Total Stock**: Total quantity of items in inventory
- **Assigned Stock**: Currently assigned to employees
- **Available Stock**: Automatically calculated (Total - Assigned)
- Stock updates automatically on:
  - Assignment
  - Return
  - Damage/Loss marking
  - Retirement

### Assignment System
- Prevents over-assignment (validates available stock)
- Uses database transactions for data integrity
- Tracks assignment history (no deletion)
- Supports condition tracking

### Audit Logging
- Logs all system actions
- Tracks user, action, table, record ID
- Stores old and new values
- Records IP address and user agent

## Database Schema

The system uses a normalized database schema with:
- Foreign key constraints
- Indexed fields for performance
- Transaction support
- Audit trail tables

Key tables:
- `users` - System users
- `categories` - Item categories
- `employees` - Employee database
- `inventory` - Inventory items
- `assignments` - Active assignments
- `assignment_history` - Complete assignment history
- `audit_logs` - System audit trail

## Troubleshooting

### Database Connection Error
- Check MySQL service is running in Laragon
- Verify database credentials in `config/database.php`
- Ensure database `biinventory` exists

### Session Issues
- Check PHP session directory is writable
- Verify `session_start()` is called before any output

### Permission Denied Errors
- Check user role permissions
- Verify session is active
- Check role constants in `config/config.php`

### Stock Not Updating
- Check for active assignments
- Verify transaction handling
- Check `updateInventoryStock()` function

## Security Best Practices

1. **Change Default Password**: Immediately change admin password
2. **Database Security**: Use strong MySQL password
3. **File Permissions**: Restrict access to config files
4. **HTTPS**: Use HTTPS in production
5. **Regular Backups**: Backup database regularly
6. **Updates**: Keep PHP and MySQL updated

## Support & Maintenance

### Regular Maintenance Tasks
- Review audit logs regularly
- Check for low stock items
- Review and clean up old assignments
- Backup database weekly

### Backup Database
```bash
mysqldump -u root -p biinventory > backup_$(date +%Y%m%d).sql
```

### Restore Database
```bash
mysql -u root -p biinventory < backup_YYYYMMDD.sql
```

## License

This system is built for BI Communications internal use.

## Version

**Version**: 1.0.0  
**Last Updated**: 2024

---

**Built with ❤️ for BI Communications**

