-- BI Communications Inventory & Asset Management System
-- Database Schema

CREATE DATABASE IF NOT EXISTS biinventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE biinventory;

-- Users table (for authentication and role management)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('super_admin', 'it_admin', 'hr_manager') DEFAULT 'hr_manager',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees table
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    department VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('active', 'inactive', 'terminated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_status (status),
    INDEX idx_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory items table
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(200) NOT NULL,
    category_id INT NOT NULL,
    type ENUM('asset', 'furniture') NOT NULL,
    brand VARCHAR(100),
    serial_number VARCHAR(100),
    condition ENUM('excellent', 'good', 'fair', 'poor', 'damaged') DEFAULT 'good',
    status ENUM('available', 'assigned', 'damaged', 'retired', 'lost') DEFAULT 'available',
    total_quantity INT NOT NULL DEFAULT 0,
    assigned_quantity INT NOT NULL DEFAULT 0,
    available_quantity INT NOT NULL DEFAULT 0,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_item_name (item_name),
    INDEX idx_category_id (category_id),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_serial_number (serial_number),
    INDEX idx_stock (total_quantity, assigned_quantity, available_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignments table (current active assignments)
CREATE TABLE IF NOT EXISTS assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    employee_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    assigned_date DATE NOT NULL,
    expected_return_date DATE,
    condition_on_assignment ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    notes TEXT,
    assigned_by INT NOT NULL,
    status ENUM('active', 'returned', 'damaged', 'lost') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_inventory_id (inventory_id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_status (status),
    INDEX idx_assigned_date (assigned_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment history table (complete history, no deletion)
CREATE TABLE IF NOT EXISTS assignment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    inventory_id INT NOT NULL,
    employee_id INT NOT NULL,
    quantity INT NOT NULL,
    action ENUM('assigned', 'returned', 'damaged', 'lost', 'updated') NOT NULL,
    action_date DATE NOT NULL,
    condition_before ENUM('excellent', 'good', 'fair', 'poor', 'damaged'),
    condition_after ENUM('excellent', 'good', 'fair', 'poor', 'damaged'),
    notes TEXT,
    performed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_assignment_id (assignment_id),
    INDEX idx_inventory_id (inventory_id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_action (action),
    INDEX idx_action_date (action_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit logs table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase Categories table
CREATE TABLE IF NOT EXISTS purchase_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchases table
CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    title VARCHAR(200),
    description TEXT,
    purchase_category_id INT,
    purchase_date DATE NOT NULL,
    expiry_date DATE,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10, 2) DEFAULT 0.00,
    total_amount DECIMAL(10, 2) DEFAULT 0.00,
    supplier VARCHAR(200),
    invoice_number VARCHAR(100),
    status ENUM('active', 'expired', 'consumed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_category_id) REFERENCES purchase_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_name (name),
    INDEX idx_purchase_date (purchase_date),
    INDEX idx_expiry_date (expiry_date),
    INDEX idx_status (status),
    INDEX idx_category (purchase_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default categories
INSERT INTO categories (name, description) VALUES
('Desktop PC', 'Desktop computers and workstations'),
('Laptop', 'Laptop computers and notebooks'),
('Monitor', 'Computer monitors and displays'),
('Mouse', 'Computer mice and pointing devices'),
('Keyboard', 'Computer keyboards'),
('Headset', 'Headsets and headphones'),
('Furniture', 'Office furniture including chairs, desks, tables, cabinets'),
('Other', 'Other miscellaneous items');

-- Insert default purchase categories
INSERT INTO purchase_categories (name, description) VALUES
('Office Supplies', 'General office supplies and stationery'),
('IT Equipment', 'IT hardware and equipment purchases'),
('Software', 'Software licenses and subscriptions'),
('Furniture', 'Office furniture purchases'),
('Maintenance', 'Maintenance and repair items'),
('Utilities', 'Utility bills and services'),
('Other', 'Other miscellaneous purchases');

-- Insert default super admin user (password: admin123)
-- Password is stored as plain text for simplicity
INSERT INTO users (username, email, password, full_name, role, status) VALUES
('admin', 'admin@bicommunications.com', 'admin123', 'Super Administrator', 'super_admin', 'active');

