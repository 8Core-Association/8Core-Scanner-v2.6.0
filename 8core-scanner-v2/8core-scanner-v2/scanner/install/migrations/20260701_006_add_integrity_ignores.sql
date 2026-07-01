-- 8Core Scanner — Migracija 006
-- Dodaje tablicu scanner_integrity_ignores za Integrity modul.
-- Integrity ignore nije scanner ignore — ne utječe na scanner_ignore_list,
-- scanner_rules, ni IOC engine.

CREATE TABLE IF NOT EXISTS `scanner_integrity_ignores` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `origin_path`      VARCHAR(1000) NOT NULL,
  `destination_path` VARCHAR(1000) NOT NULL,
  `ignored_path`     VARCHAR(1000) NOT NULL  COMMENT 'Relative path within destination root',
  `ignore_type`      VARCHAR(30)   NOT NULL DEFAULT 'extra_path'
                       COMMENT 'extra_path | missing_path',
  `note`             TEXT          NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_origin_path`      (`origin_path`(100)),
  KEY `idx_destination_path` (`destination_path`(100)),
  KEY `idx_ignored_path`     (`ignored_path`(100)),
  KEY `idx_ignore_type`      (`ignore_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
