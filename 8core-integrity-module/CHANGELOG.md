# 8Core Integrity — Changelog

## [0.3.0] — 2026-07-01

### Added

- `includes/integrity.php` — `integrity_browse_dir(string $path): array` — secure directory browser constrained to `/home`; blocks path traversal, symlinks escaping `/home`, non-directory entries
- `includes/integrity.php` — `integrity_browser_resolve(string $path): ?string` — canonical path resolver with `/home` guard
- `includes/integrity.php` — `integrity_detect_software(string $path): array` — detects Joomla, WordPress, WHMCS, PrestaShop, phpBB, Dolibarr from filesystem markers; returns software name, version, root
- `includes/integrity.php` — version reader helpers: `_int_read_xml_version`, `_int_read_wp_version`, `_int_read_ps_version`, `_int_read_phpbb_version`
- `admin/module_integrity.php` — AJAX POST handlers: `browse_dir`, `detect_software` (JSON responses, auth-gated)
- `admin/module_integrity.php` — **Browse /home** modal in Integrity Check → Destination field: tree-navigable, breadcrumb, double-click to enter, single-click to select, "Use this path" sets destination
- `admin/module_integrity.php` — **Software detection info box**: auto-triggers after path selection (or 600 ms after manual input); shows Detected software, version, root; warning if unknown

### Changed

- `admin/module_integrity.php` — Destination field replaced with `<input> + Browse button` row
- `module.php` — version bumped to `0.3.0`

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
