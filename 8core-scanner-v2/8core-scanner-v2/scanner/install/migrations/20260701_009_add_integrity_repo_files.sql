-- 8Core Scanner — Migration 009
-- Creates scanner_integrity_repo_files table.
-- Stores per-file sha256 hashes for imported origin repositories.

CREATE TABLE IF NOT EXISTS `scanner_integrity_repo_files` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `repo_key`      VARCHAR(255)  NOT NULL,
  `application`   VARCHAR(100)  NOT NULL,
  `branch`        VARCHAR(100)  NOT NULL,
  `version`       VARCHAR(100)  NOT NULL,
  `repo_path`     VARCHAR(1024) NOT NULL,
  `relative_path` VARCHAR(1024) NOT NULL,
  `file_type`     VARCHAR(20)   NOT NULL DEFAULT 'file',
  `sha256`        CHAR(64)      NULL,
  `size_bytes`    BIGINT        NULL,
  `mtime`         INT           NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_repo_key`        (`repo_key`),
  KEY `idx_app_branch_ver`  (`application`, `branch`, `version`),
  KEY `idx_relative_path`   (`relative_path`(100)),
  KEY `idx_sha256`          (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
