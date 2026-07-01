-- 8Core Scanner — Migration 011
-- Creates scanner_integrity_exclusion_templates + items tables.
-- Inserts default Joomla 4 production exclusion template.

CREATE TABLE IF NOT EXISTS `scanner_integrity_exclusion_templates` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(190) NOT NULL,
  `description` TEXT         NULL,
  `cms`         VARCHAR(100) NULL,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scanner_integrity_exclusion_template_items` (
  `id`          INT           NOT NULL AUTO_INCREMENT,
  `template_id` INT           NOT NULL,
  `path`        VARCHAR(1024) NOT NULL,
  `sort_order`  INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_template_id` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default: Joomla 4 production exclusions
INSERT INTO `scanner_integrity_exclusion_templates` (`name`, `description`, `cms`, `active`)
VALUES ('Joomla 4 production', 'Standard exclusions for a Joomla 4 production site — extensions, templates, media, and runtime directories.', 'Joomla', 1);

SET @tpl_id = LAST_INSERT_ID();

INSERT INTO `scanner_integrity_exclusion_template_items` (`template_id`, `path`, `sort_order`) VALUES
(@tpl_id, 'administrator/components/', 1),
(@tpl_id, 'administrator/modules/', 2),
(@tpl_id, 'administrator/templates/', 3),
(@tpl_id, 'administrator/manifests/packages/', 4),
(@tpl_id, 'administrator/manifests/libraries/', 5),
(@tpl_id, 'administrator/manifests/modules/', 6),
(@tpl_id, 'administrator/manifests/plugins/', 7),
(@tpl_id, 'administrator/language/', 8),
(@tpl_id, 'components/', 9),
(@tpl_id, 'modules/', 10),
(@tpl_id, 'plugins/', 11),
(@tpl_id, 'templates/', 12),
(@tpl_id, 'media/', 13),
(@tpl_id, 'images/', 14),
(@tpl_id, 'language/', 15),
(@tpl_id, 'cache/', 16),
(@tpl_id, 'tmp/', 17),
(@tpl_id, 'logs/', 18);
