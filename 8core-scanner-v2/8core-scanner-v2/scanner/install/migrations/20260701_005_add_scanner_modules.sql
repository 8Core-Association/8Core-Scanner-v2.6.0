-- 8Core Scanner — Migracija 005
-- Dodaje tablicu scanner_modules za module manager.

CREATE TABLE IF NOT EXISTS `scanner_modules` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `module_key`   VARCHAR(100) NOT NULL,
  `name`         VARCHAR(190) NOT NULL,
  `description`  TEXT         NULL,
  `version`      VARCHAR(50)  NULL,
  `active`       TINYINT(1)   NOT NULL DEFAULT 0,
  `installed_at` DATETIME     NULL,
  `updated_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
