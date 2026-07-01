-- 8Core Scanner — Migration 007
-- Creates scanner_integrity_runs and scanner_integrity_results tables.
-- These are used by the 8Core Integrity module to persist structural check results.

CREATE TABLE IF NOT EXISTS `scanner_integrity_runs` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `origin_path`      VARCHAR(1000) NOT NULL,
  `destination_path` VARCHAR(1000) NOT NULL,
  `software`         VARCHAR(100)  NULL,
  `total`            INT UNSIGNED  NOT NULL DEFAULT 0,
  `suspicious`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `warnings`         INT UNSIGNED  NOT NULL DEFAULT 0,
  `info`             INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_origin_path`      (`origin_path`(100)),
  KEY `idx_destination_path` (`destination_path`(100)),
  KEY `idx_created_at`       (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scanner_integrity_results` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `run_id`           INT UNSIGNED  NOT NULL,
  `origin_path`      VARCHAR(1000) NOT NULL,
  `destination_path` VARCHAR(1000) NOT NULL,
  `type`             VARCHAR(40)   NOT NULL,
  `severity`         VARCHAR(20)   NOT NULL,
  `relative_path`    VARCHAR(2000) NOT NULL,
  `full_path`        VARCHAR(2000) NOT NULL,
  `status`           VARCHAR(30)   NOT NULL DEFAULT 'new',
  `note`             TEXT          NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NULL      ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_run_id`   (`run_id`),
  KEY `idx_status`   (`status`),
  KEY `idx_severity` (`severity`),
  KEY `idx_type`     (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
