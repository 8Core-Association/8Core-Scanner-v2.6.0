-- 8Core Scanner — Migracija 003
-- Dodaje tablicu scanner_maintenance_requests (audit log za clear rezultata)
-- Sigurno za ponovljeno pokretanje (IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS `scanner_maintenance_requests` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`                   ENUM('account','all') NOT NULL,
  `account_name`            VARCHAR(255)    NULL,
  `requested_by`            INT             NULL,
  `requested_by_username`   VARCHAR(255)    NULL,
  `status`                  ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  `archive_path`            TEXT            NULL,
  `findings_deleted`        INT             NOT NULL DEFAULT 0,
  `actions_deleted`         INT             NOT NULL DEFAULT 0,
  `scans_deleted`           INT             NOT NULL DEFAULT 0,
  `scan_requests_deleted`   INT             NOT NULL DEFAULT 0,
  `quarantine_deleted_items` INT            NOT NULL DEFAULT 0,
  `error`                   TEXT            NULL,
  `created_at`              DATETIME        NOT NULL,
  `started_at`              DATETIME        NULL,
  `finished_at`             DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scope`   (`scope`),
  KEY `idx_status`  (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
