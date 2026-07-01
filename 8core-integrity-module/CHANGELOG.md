# 8Core Integrity — Changelog

## [0.1.2] — 2026-07-01

### Changed

- `includes/integrity.php` — replaced flat `integrity_default_tree()` with `integrity_default_groups()` (grouped by app) and `integrity_default_dirs()` (flat list for mkdir)
- `includes/integrity.php` — added `integrity_custom_dirs()` — reads existing subdirs of `custom/`
- `includes/integrity.php` — added `integrity_create_custom_dir(string $name)` — creates a single custom folder with validation
- `admin/module_integrity.php` — repository structure now displayed as grouped cards (Joomla, WordPress, WHMCS, PrestaShop, Custom) with disk-status indicators
- `admin/module_integrity.php` — added "Add custom repository folder" form (pattern: `^[a-z0-9_-]+$`)
- `admin/module_integrity.php` — root command output is now dynamically generated per action
- `admin/module_integrity.php` — root cmd box uses amber/green colours (was dark theme)

## [0.1.1] — 2026-07-01

### Fixed

- `module.php` — `admin_menu` prepisano kao single-object (ne lista), kompatibilno sa sidebar normalizacijom
- `admin/module_integrity.php` — verzija u headeru: `v0.1.1`
- `admin/module_integrity.php` — CSS: `../assets/css/scanner.css` (ne PHP filesystem path)
- `admin/module_integrity.php` — logout: `../logout.php`
- `admin/module_integrity.php` — sidebar include: relativan path `../../../admin/sidebar.php`
- `admin/module_integrity.php` — root komanda prikazuje se kad PHP nema dozvolu (ne silent fail)
- `includes/integrity.php` — funkcija preimenovana u `integrity_default_tree()` (uskladeno s instaliranim modulom)

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
