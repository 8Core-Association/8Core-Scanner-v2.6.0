# 8Core Integrity — Changelog

## [0.8.0] — 2026-07-01

### Added

- `install/migrations/20260701_009_add_integrity_repo_files.sql`: creates `scanner_integrity_repo_files` table — stores per-file sha256 hashes for imported origin repositories (`repo_key`, `application`, `branch`, `version`, `relative_path`, `file_type`, `sha256`, `size_bytes`, `mtime`)
- `install/migrations/20260701_010_alter_integrity_add_hash_cols.sql`: adds `repo_sha256 CHAR(64)`, `destination_sha256 CHAR(64)`, `repo_size BIGINT`, `destination_size BIGINT` to `scanner_integrity_results`; adds `check_mode VARCHAR(20)`, `summary_json TEXT` to `scanner_integrity_runs`
- `includes/integrity.php` — `integrity_repo_key(string $app, string $branch, string $version): string`: canonical lowercase `app/branch/version` key for DB lookups
- `includes/integrity.php` — `integrity_repo_has_hashes(PDO $pdo, string $repoKey): int`: returns file count in hash index for a repo key
- `includes/integrity.php` — `integrity_generate_repo_hashes(PDO $pdo, string $application, string $branch, string $version, string $repoPath): array`: scans repo dir, computes sha256 per file, stores in `scanner_integrity_repo_files`; replaces any existing index for the same key; respects `set_time_limit(300)`
- `includes/integrity.php` — `integrity_load_repo_index(PDO $pdo, string $repoKey): array`: loads full hash index keyed by relative_path
- `includes/integrity.php` — `integrity_clear_results(PDO $pdo, string $mode, int $runId = 0, string $destPath = ''): bool`: deletes integrity run/result rows; modes: `run` (single run), `dest` (all for destination path), `all`; does NOT touch ignores, repo hashes, or scanner findings
- `includes/integrity.php` — `integrity_hash_check(PDO $pdo, string $repoKey, string $originPath, string $destPath, string $software, array $ignoredPaths): array`: full hash comparison — MISSING_FILE/MISSING_DIRECTORY (warning), MODIFIED_FILE (suspicious, both hashes recorded), EXTRA_FILE/EXTRA_DIRECTORY, USER_CONTENT_FOLDER; returns `mode = 'hash'`, `summary` counts, and per-finding `repo_sha256`/`destination_sha256`
- `includes/integrity.php` — `_int_under_uc_folder(string $rel, array $ucFolders): bool`: prevents MISSING false positives for files inside user-content directories when comparing repo index to dest scan
- `includes/integrity.php` — `integrity_update_result_status()` now accepts `reviewed` as a valid status
- `includes/integrity.php` — `integrity_save_results()` now stores `repo_sha256`, `destination_sha256`, `repo_size`, `destination_size` per finding
- `includes/integrity.php` — `integrity_save_run()` now accepts `string $checkMode = 'structural'` and `array $summary = []` parameters; summary stored as JSON
- `includes/integrity.php` — `integrity_ensure_tables()` now also creates `scanner_integrity_repo_files` and runs safe ALTER statements for new hash columns
- `admin/module_integrity.php` — ZIP import (`import_zip`, `import_zip_replace`): automatically runs `integrity_generate_repo_hashes()` after successful extraction; import success panel shows hash count and any errors
- `admin/module_integrity.php` — `regenerate_hashes` POST handler: deletes existing index, regenerates hashes for a specific repo, flash message with file count
- `admin/module_integrity.php` — `clear_results` POST handler: supports `run`, `dest`, `all` modes with PRG flow
- `admin/module_integrity.php` — `run_structural_check` handler: auto-detects hash DB availability from origin path; uses `integrity_hash_check()` when hashes exist, falls back to `integrity_structural_check()` with notice; passes `check_mode` and `summary` to `integrity_save_run()`
- `admin/module_integrity.php` — `action_result` handler: MODIFIED_FILE replace now trashes existing dest file first, then copies clean version from origin
- `admin/module_integrity.php` — `action_result` handler: new `mark_reviewed` action sets status to `reviewed`
- `admin/module_integrity.php` — bulk actions: added `replace_modified` (trash + replace MODIFIED_FILE rows) and `mark_reviewed`
- `admin/module_integrity.php` — Run info bar: check mode badge (HASH CHECK / STRUCTURAL), hash summary counters (checked, ok, modified, missing, extra), Clear Run button
- `admin/module_integrity.php` — Results table: hash columns (repo sha256, dest sha256, 8-char truncated with full on hover, color-coded match/differ); MODIFIED_FILE row highlight; updated action buttons (MISSING + MODIFIED get Replace; Replace for MODIFIED notes trash-first); Mark Reviewed button on all new rows
- `admin/module_integrity.php` — Type filter: added MODIFIED_FILE option
- `admin/module_integrity.php` — Status filter: added `reviewed` option; status badge CSS added
- `admin/module_integrity.php` — Bulk dropdown: added "Replace modified from Origin" and "Mark as Reviewed"
- `admin/module_integrity.php` — Repository Manager: new "Imported Repositories & Hash Index" section showing hash count per repo with Regenerate Hashes button
- `admin/module_integrity.php` — Integrity Check form button renamed to "Run Integrity Check"; placeholder text updated to reflect hash-first logic

### Changed

- `includes/integrity.php` — `integrity_structural_check()` returns `mode = 'structural'` in result array for consistency with `integrity_hash_check()`
- `module.php` — version bumped to `0.8.0`
- `admin/module_integrity.php` — version display updated to `v0.8.0`



### Added

- `install/migrations/20260701_008_alter_integrity_runs_add_exclusions.sql`: adds `scan_exclusions TEXT NULL` column to `scanner_integrity_runs`
- `includes/integrity.php` — `integrity_path_is_ignored(string $rel, array $storedPaths): bool`: unified ignore matching — trailing `/` = prefix/subtree match; no trailing slash = exact match
- `includes/integrity.php` — `integrity_parse_scan_exclusions(string $text, string $destRoot): array`: parses admin textarea into normalized prefix-exclusion strings; strips absolute paths to relative; rejects path traversal, backslash, paths outside destRoot; all output entries end with `/`
- `includes/integrity.php` — `integrity_ensure_tables()` now also runs `ALTER TABLE ... ADD COLUMN scan_exclusions` for existing databases (try/catch safe)
- `admin/module_integrity.php` — **Scan exclusions** section: textarea in Integrity Check form (above Run button); one path per line; relative or absolute (auto-stripped); clear label distinguishing it from result filters
- `admin/module_integrity.php` — Pre-run exclusions are parsed + merged with DB persistent ignores before structural check runs
- `admin/module_integrity.php` — Scan exclusions stored per run in `scanner_integrity_runs.scan_exclusions`; displayed as tags in the run info bar
- `admin/module_integrity.php` — `_int_ignore_options(string $relPath): array`: computes up to 4 ignore options for a finding (exact path + up to 3 parent prefix levels)
- `admin/module_integrity.php` — Row "Ignore" action now uses a select+button combo: admin chooses exact path or subtree prefix before submitting
- `admin/module_integrity.php` — `action_result` ignore handler parses `ignore_path` as `exact:path` or `prefix:path/`; stores subtree ignores with trailing `/` in `scanner_integrity_ignores`
- `admin/module_integrity.php` — Bulk action dropdown: added "Replace missing from Origin (MISSING only)"
- `admin/module_integrity.php` — `bulk_preview`: shows skipped-item count + reason when selection contains ineligible rows (wrong type, already actioned)
- `admin/module_integrity.php` — `bulk_execute`: supports `replace_missing` action
- `admin/module_integrity.php` — Filter bar labeled "Result filters" with explanatory subtext clearly distinguishing it from scan exclusions
- `admin/module_integrity.php` — Replace button tooltip: "Copy missing file/folder from origin repository to destination."

### Changed

- `includes/integrity.php` — `integrity_structural_check()`: replaces `isset($ignoredSet[$rel])` exact-only check with `integrity_path_is_ignored()` (prefix + exact); `$ignoredPaths` now supports trailing-slash prefix entries
- `includes/integrity.php` — `integrity_save_run()`: accepts `array $scanExclusions = []` parameter; stored as newline-delimited string
- `admin/module_integrity.php` — `run_structural_check` handler: parses pre-run exclusions, merges with DB ignores, passes combined to structural check; passes exclusions to `integrity_save_run()`
- `module.php` — version bumped to `0.7.0`

### Not implemented (planned)

- Hash comparison (file content integrity)
- Malware scan integration
- Scanner worker integration



### Added

- `install/migrations/20260701_007_add_integrity_results.sql`: new `scanner_integrity_runs` and `scanner_integrity_results` tables
- `includes/integrity.php` — `integrity_ensure_tables()` now also creates `scanner_integrity_runs` and `scanner_integrity_results`
- `includes/integrity.php` — `integrity_save_run()`: persists a structural check run to `scanner_integrity_runs`, returns run_id
- `includes/integrity.php` — `integrity_save_results()`: bulk-inserts findings for a run into `scanner_integrity_results`
- `includes/integrity.php` — `integrity_load_run()`, `integrity_load_results()`, `integrity_load_results_by_ids()`, `integrity_update_result_status()`: run and result data access
- `includes/integrity.php` — `integrity_trash_root()`, `integrity_do_trash_path()`: Integrity Trash — moves EXTRA paths to `/home/8core_integrity/trash/YYYYMMDD-HHMMSS/<relative_path>` via `rename()`; path safety: realpath inside /home, matches destRoot + relativePath
- `includes/integrity.php` — `integrity_do_replace_path()`: copies MISSING path from origin to destination; refuses to overwrite, rejects path traversal, guards both roots
- `admin/module_integrity.php` — `run_structural_check` POST handler now saves run + results to DB, then PRG-redirects to `?run_id=<id>`
- `admin/module_integrity.php` — `action_result` POST handler: single-row Ignore / Trash / Replace actions with PRG redirect
- `admin/module_integrity.php` — `bulk_preview` POST handler: validates and renders two-step confirm screen before destructive bulk operations
- `admin/module_integrity.php` — `bulk_execute` POST handler: executes confirmed bulk Ignore or Trash, PRG-redirects
- `admin/module_integrity.php` — flash messages via `$_SESSION['8int_flash']` (set before PRG, drained on GET render)
- `admin/module_integrity.php` — helper functions: `_int_flash_set()`, `_int_flash_drain()`, `_int_build_check_url()`, `_int_filters_from_get()`, `_int_filters_to_get()`, `_int_parse_id_range()`
- `admin/module_integrity.php` — results UI: run info bar (run #, date, origin, destination, software, severity counts)
- `admin/module_integrity.php` — filter bar: Type / Severity / Status / Path contains (GET-param driven, URL-preserving)
- `admin/module_integrity.php` — results table: ID, checkbox, Severity, Type, Relative path + Full path, Status (with badge), Actions columns
- `admin/module_integrity.php` — row actions: Ignore (all non-USER_CONTENT), Trash (EXTRA only), Replace (MISSING only), each with `confirm()` dialog
- `admin/module_integrity.php` — bulk action bar: action dropdown, select mode (checked rows / ID range), ID range input (supports `1,5,10-20,33` syntax), Apply button (disabled until valid selection)
- `admin/module_integrity.php` — bulk confirm screen: lists affected rows in amber panel before destructive execute
- `admin/module_integrity.php` — status badges: new / ignored_integrity / trashed / replaced / failed
- `admin/module_integrity.php` — in-memory fallback: if DB save fails, results stored in `$_SESSION['8int_inmem']` and shown without row actions
- `admin/module_integrity.php` — JS bulk selection: Check all, Uncheck all, selection counter, ID range show/hide, Apply button enable/disable

### Changed

- `admin/module_integrity.php` — version bumped to `0.6.0`
- `module.php` — version bumped to `0.6.0`

### Not implemented (planned)

- Hash comparison (file content integrity)
- Malware scan integration
- Scanner worker integration



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
