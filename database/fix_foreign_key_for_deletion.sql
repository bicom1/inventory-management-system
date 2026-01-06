-- Fix Foreign Key Constraints to Allow Inventory Deletion
-- This allows deletion of inventory items while preserving assignment history
-- Run this SQL script in phpMyAdmin or MySQL command line

USE biinventory;

-- ============================================
-- Fix assignments table
-- ============================================

-- Drop the existing foreign key constraint for assignments
ALTER TABLE assignments 
DROP FOREIGN KEY assignments_ibfk_1;

-- Modify inventory_id column to allow NULL
ALTER TABLE assignments 
MODIFY COLUMN inventory_id INT NULL;

-- Re-add the foreign key with ON DELETE SET NULL
ALTER TABLE assignments 
ADD CONSTRAINT assignments_ibfk_1 
FOREIGN KEY (inventory_id) REFERENCES inventory(id) 
ON DELETE SET NULL;

-- ============================================
-- Fix assignment_history table
-- ============================================

-- Drop the existing foreign key constraint for assignment_history
ALTER TABLE assignment_history 
DROP FOREIGN KEY assignment_history_ibfk_2;

-- Modify inventory_id column to allow NULL (to preserve history when inventory is deleted)
ALTER TABLE assignment_history 
MODIFY COLUMN inventory_id INT NULL;

-- Re-add the foreign key with ON DELETE SET NULL (preserves history, allows deletion)
ALTER TABLE assignment_history 
ADD CONSTRAINT assignment_history_ibfk_2 
FOREIGN KEY (inventory_id) REFERENCES inventory(id) 
ON DELETE SET NULL;

-- ============================================
-- Verify the changes
-- ============================================
SHOW CREATE TABLE assignments;
SHOW CREATE TABLE assignment_history;

