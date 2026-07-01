-- 8Core Scanner — Migration 010
-- Adds hash and summary columns to existing integrity tables.
-- Each ALTER is separate so partial failures do not block the rest.

ALTER TABLE `scanner_integrity_results`
  ADD COLUMN IF NOT EXISTS `repo_sha256`        CHAR(64)     NULL AFTER `full_path`,
  ADD COLUMN IF NOT EXISTS `destination_sha256` CHAR(64)     NULL AFTER `repo_sha256`,
  ADD COLUMN IF NOT EXISTS `repo_size`          BIGINT       NULL AFTER `destination_sha256`,
  ADD COLUMN IF NOT EXISTS `destination_size`   BIGINT       NULL AFTER `repo_size`;

ALTER TABLE `scanner_integrity_runs`
  ADD COLUMN IF NOT EXISTS `check_mode`    VARCHAR(20)  NOT NULL DEFAULT 'structural' AFTER `software`,
  ADD COLUMN IF NOT EXISTS `summary_json`  TEXT         NULL AFTER `scan_exclusions`;
