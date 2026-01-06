-- Migration: Add password field to assignments table
-- This migration adds a password field for storing optional passwords when assigning items

ALTER TABLE assignments 
ADD COLUMN password VARCHAR(255) NULL AFTER notes;

