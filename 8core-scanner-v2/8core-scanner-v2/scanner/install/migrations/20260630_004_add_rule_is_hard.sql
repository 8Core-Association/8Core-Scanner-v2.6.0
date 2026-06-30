-- 8Core Scanner — Migracija 004
-- Dodaje is_hard kolonu na scanner_rules.
-- is_hard=1: pravilo uvijek pobijedi allowlist/ignore listu (hard malware indikatori).
-- is_hard=0 (default): pravilo se može suppressati allowlistom (false-positive/weak pravila).

ALTER TABLE scanner_rules
  ADD COLUMN IF NOT EXISTS `is_hard` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = hard malware rule, bypasses allowlist; 0 = soft rule, suppressable'
  AFTER `active`;

-- Hardkodirani engine indikatori koji se uvijek smatraju hard (sync s ioc_scan.sh)
-- Postavljamo is_hard=1 za CRITICAL i HIGH pravila ako ih korisnik ima u bazi
-- (bezopasno — korisnik može ručno prilagoditi)
UPDATE scanner_rules SET is_hard = 1 WHERE risk IN ('CRITICAL', 'HIGH') AND is_hard = 0;
