# 8Core Integrity — Changelog

## [0.1.1] — 2026-07-01

### Fixed

- `admin/module_integrity.php` — CSS link ispravljen: `../assets/css/scanner.css` (ne vise filesystem `$scannerRoot`)
- `admin/module_integrity.php` — Odjava link ispravljen: `../logout.php`
- `module.php` — verzija bumped na `0.1.1`

---

## [0.1.0] — 2026-07-01

### Initial release

- `module.php` manifest with `module_key`, `name`, `version`, `description`, `admin_menu`
- `admin/module_integrity.php` — admin UI with two sections:
  - **Repository Manager**: displays repo root path, default directory tree,
    "Create repository structure" button (creates `/home/8core_integrity/repo/...`)
  - **Integrity Check**: origin/destination form with placeholder result;
    actual comparison available after hash database implementation
- `includes/integrity.php` — helper functions:
  - `integrity_repo_root()` — returns repo root path
  - `integrity_get_default_tree()` — returns list of default repo directories
  - `integrity_ensure_repo_structure()` — creates repo directories, returns results array

### Planned for next version

- Upload core ZIP to repository
- Hash database generation from repository
- Real file-level integrity comparison (presence + hash)
- Findings: ignore / replace from repo / quarantine
