-- Migration: Add price fields to inventory table
-- This migration adds price_per_unit and total_price columns to the inventory table

-- Add price_per_unit column
ALTER TABLE inventory 
ADD COLUMN price_per_unit DECIMAL(10, 2) DEFAULT 0.00 AFTER description;

-- Add total_price column
ALTER TABLE inventory 
ADD COLUMN total_price DECIMAL(10, 2) DEFAULT 0.00 AFTER price_per_unit;

-- Update existing records to calculate total_price if price_per_unit exists
UPDATE inventory 
SET total_price = price_per_unit * total_quantity 
WHERE price_per_unit > 0;

