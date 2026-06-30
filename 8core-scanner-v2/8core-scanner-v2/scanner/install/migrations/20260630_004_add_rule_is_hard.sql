-- 8Core Scanner — Migracija 004
-- Dodaje is_hard kolonu na scanner_rules.
-- is_hard=1: pravilo uvijek pobijedi allowlist/ignore listu (hard malware indikatori).
-- is_hard=0 (default): pravilo se može suppressati allowlistom (false-positive/weak pravila).
--
-- NIJE sigurno automatski postaviti is_hard=1 po risku — postoje HIGH/CRITICAL false-positive pravila.
-- Ručno označite is_hard=1 samo za pravila koja JASNO čitaju request input ili izvršavaju komande.

ALTER TABLE scanner_rules
  ADD COLUMN IF NOT EXISTS `is_hard` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = hard malware rule, bypasses allowlist; 0 = soft rule, suppressable by allowlist'
  AFTER `active`;
