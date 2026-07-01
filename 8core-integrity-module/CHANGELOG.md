# 8Core Integrity — Changelog

## [0.5.0] — 2026-07-01

### Added

- `includes/integrity.php` — `integrity_user_content_folders(software)`: returns predefined user-content folder list per CMS (Joomla: images, cache, tmp, logs; WordPress: wp-content/uploads, wp-content/cache; PrestaShop: img, cache, log, download, upload)
- `includes/integrity.php` — `_int_scan_tree()`: recursive tree scanner that stops at user-content boundaries and rejects symlinks escaping the tree root; hard limit 20,000 items per tree
- `includes/integrity.php` — `integrity_structural_check()`: compares origin repo vs destination by file/folder existence; finding types: EXTRA_DIRECTORY, EXTRA_FILE, MISSING_DIRECTORY, MISSING_FILE, USER_CONTENT_FOLDER; severity: suspicious (extra at root depth 0), warning (extra below root, all missing), info (user-content folder present)
- `includes/integrity.php` — `integrity_ignores_for()` / `integrity_add_ignore()`: Integrity-specific ignore list backed by `scanner_integrity_ignores` DB table. Independent of scanner_ignore_list, scanner_rules, and IOC engine.
- `admin/module_integrity.php` — `run_structural_check` POST handler: runs structural check for submitted origin+destination pair
- `admin/module_integrity.php` — `add_integrity_ignore` POST handler: adds ignore entry and immediately re-runs check with updated list
- `admin/module_integrity.php` — Integrity Check tab: results table with Severity / Type / Path / Action columns, summary bar (counts by severity), context bar (origin, destination, software), "Ignore in Integrity" action per finding
- `admin/module_integrity.php` — `detected_software` hidden input propagated through check and ignore forms; populated by JS after software detection
- `install/migrations/20260701_006_add_integrity_ignores.sql`: new `scanner_integrity_ignores` table

### Changed

- "Run Integrity Check" button renamed to "Run Structural Check" and enabled
- Placeholder text updated to reflect hash comparison as future feature (structural check is live)
- `module.php` version bumped to `0.5.0`

### Not implemented (planned)

- Hash comparison (file content integrity)
- Malware scan integration
- Quarantine / delete / replace actions
- Scanner worker integration

## [0.4.0] — 2026-07-01

### Added

- `admin/module_integrity.php` — tab bar: **Repository Manager** (`?tab=repo`) | **Integrity Check** (`?tab=check`)
- `admin/module_integrity.php` — `$_intActiveTab` computed server-side from `?tab=` param; default logic: `check` if imported repos exist, else `repo`
- `admin/module_integrity.php` — URL param is source of truth; inactive tab panel rendered with `style="display:none"` (no JS-only tab state)
- `admin/module_integrity.php` — all POST form `action=""` URLs include `&tab=repo` or `&tab=check` so POST–render cycle preserves active tab
- `module.php` — version bumped to `0.4.0`

### Changed

- Import Repository ZIP section moved into `tab=repo` panel
- Repository Manager section (tree, add app, add version) moved into `tab=repo` panel
- Integrity Check section (origin, destination, browse, detector) moved into `tab=check` panel
- Messages, import success, and ZIP conflict banners remain above tab bar (always visible)

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
