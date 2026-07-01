-- 8Core Scanner — Migration 008
-- Adds scan_exclusions column to scanner_integrity_runs.
-- Stores the pre-run path exclusions used during each structural check.

ALTER TABLE `scanner_integrity_runs`
  ADD COLUMN IF NOT EXISTS `scan_exclusions` TEXT NULL AFTER `info`;
