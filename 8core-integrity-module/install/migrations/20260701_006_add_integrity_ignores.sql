-- 8Core Integrity — Migration 006
-- Creates scanner_integrity_ignores table.
-- Integrity ignore is module-local and does not affect scanner_ignore_list or IOC rules.

CREATE TABLE IF NOT EXISTS `scanner_integrity_ignores` (
  `id`               INT           NOT NULL AUTO_INCREMENT,
  `origin_path`      VARCHAR(1024) NOT NULL,
  `destination_path` VARCHAR(1024) NOT NULL,
  `ignored_path`     VARCHAR(1024) NOT NULL,
  `ignore_type`      VARCHAR(50)   NOT NULL DEFAULT 'extra_path',
  `note`             TEXT          NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_origin_path`      (`origin_path`(100)),
  KEY `idx_destination_path` (`destination_path`(100)),
  KEY `idx_ignored_path`     (`ignored_path`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
