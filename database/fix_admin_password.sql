-- Fix Admin Password
-- Run this SQL script to reset the admin password to 'admin123'
-- 
-- Usage: Import this file in phpMyAdmin or run via MySQL command line
-- mysql -u root -p biinventory < database/fix_admin_password.sql

USE biinventory;

-- Update admin password to 'admin123' (plain text)
UPDATE users 
SET password = 'admin123' 
WHERE username = 'admin';

-- If admin user doesn't exist, create it
INSERT INTO users (username, email, password, full_name, role, status)
SELECT 'admin', 'admin@bicommunications.com', 'admin123', 'Super Administrator', 'super_admin', 'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- Verify the update
SELECT id, username, email, role, status, password
FROM users 
WHERE username = 'admin';

