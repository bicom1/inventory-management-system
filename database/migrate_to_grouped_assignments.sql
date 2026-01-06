-- Migration: Convert assignments to support multiple items per assignment
-- This migration creates a new assignment_items table and modifies the assignments table

-- Step 1: Create assignment_items table to store items within an assignment
CREATE TABLE IF NOT EXISTS assignment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    inventory_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    condition_on_assignment ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    condition_on_return ENUM('excellent', 'good', 'fair', 'poor', 'damaged') DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE RESTRICT,
    INDEX idx_assignment_id (assignment_id),
    INDEX idx_inventory_id (inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Make inventory_id nullable in assignments table (for backward compatibility during migration)
-- Note: We'll keep it for now but new assignments won't use it directly
ALTER TABLE assignments MODIFY COLUMN inventory_id INT NULL;

-- Step 3: Migrate existing data (if any)
-- For each existing assignment, create a corresponding assignment_item
INSERT INTO assignment_items (assignment_id, inventory_id, quantity, condition_on_assignment)
SELECT id, inventory_id, quantity, condition_on_assignment
FROM assignments
WHERE inventory_id IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM assignment_items WHERE assignment_items.assignment_id = assignments.id
);

