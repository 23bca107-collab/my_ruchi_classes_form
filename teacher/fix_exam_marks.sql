-- Fix for exam_marks table to support larger mark values
-- The marks column was int(3) which only supports -99 to 99
-- Change it to int to support 0 to 1000+

ALTER TABLE `exam_marks` MODIFY COLUMN `marks` INT(11) NOT NULL DEFAULT 0;

-- Verify the change
DESCRIBE exam_marks;
