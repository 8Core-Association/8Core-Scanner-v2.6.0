<?php
/**
 * 8Core Integrity — helper functions
 */

// ── Storage ────────────────────────────────────────────────────────────────────

function integrity_storage_base(): string {
    return '/home/8core_integrity';
}

/**
 * Returns all top-level storage directories that must exist and be writable.
 */
function integrity_storage_dirs(): array {
    $base = integrity_storage_base();
    return [
        $base,
        $base . '/repo',
        $base . '/trash',
        $base . '/tmp',
        $base . '/logs',
    ];
}

/**
 * Returns the running PHP process username.
 * Tries posix_getpwuid first (most accurate), falls back to get_current_user().
 */
function integrity_php_user(): string {
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (!empty($info['name'])) return $info['name'];
    }
    $u = get_current_user();
    return $u !== '' ? $u : 'www-data';
}

/**
 * Returns status for each storage directory:
 * ['path' => ..., 'exists' => bool, 'writable' => bool]
 */
function integrity_storage_status(): array {
    $result = [];
    foreach (integrity_storage_dirs() as $dir) {
        $exists   = is_dir($dir);
        $writable = $exists && is_writable($dir);
        $result[] = ['path' => $dir, 'exists' => $exists, 'writable' => $writable];
    }
    return $result;
}

/**
 * Returns true when all required storage directories exist and are writable.
 */
function integrity_storage_ready(): bool {
    foreach (integrity_storage_status() as $s) {
        if (!$s['exists'] || !$s['writable']) return false;
    }
    return true;
}

/**
 * Generates the one-time shell setup command using the actual PHP user.
 */
function integrity_storage_setup_cmd(): string {
    $base = integrity_storage_base();
    $user = integrity_php_user();
    return 'mkdir -p ' . escapeshellarg($base . '/repo') . ' \\'  . "\n"
         . '         ' . escapeshellarg($base . '/trash') . ' \\'  . "\n"
         . '         ' . escapeshellarg($base . '/tmp') . ' \\'    . "\n"
         . '         ' . escapeshellarg($base . '/logs') . "\n\n"
         . 'chown -R ' . escapeshellarg($user) . ':' . escapeshellarg($user) . ' ' . escapeshellarg($base) . "\n"
         . 'chmod -R 750 ' . escapeshellarg($base);
}



function integrity_repo_root(): string {
    return '/home/8core_integrity/repo';
}

/** a-z, 0-9, underscore, hyphen */
function integrity_valid_name(string $n): bool {
    return $n !== '' && (bool) preg_match('/^[a-z0-9_-]+$/', $n);
}

/** a-z, 0-9, dot, underscore, hyphen (for branch and version) */
function integrity_valid_loose(string $n): bool {
    return $n !== '' && (bool) preg_match('/^[a-z0-9._-]+$/', $n);
}

// ── Default tree ───────────────────────────────────────────────────────────────

function integrity_default_groups(): array {
    return [
        ['label' => 'Joomla',     'key' => 'joomla',     'versions' => ['v3x', 'v4x', 'v5x']],
        ['label' => 'WordPress',  'key' => 'wordpress',  'versions' => ['v6x', 'v7x']],
        ['label' => 'WHMCS',      'key' => 'whmcs',      'versions' => []],
        ['label' => 'PrestaShop', 'key' => 'prestashop', 'versions' => []],
        ['label' => 'Custom',     'key' => 'custom',     'versions' => []],
    ];
}

function integrity_default_keys(): array {
    return array_column(integrity_default_groups(), 'key');
}

function integrity_default_dirs(): array {
    $root = integrity_repo_root();
    $dirs = [];
    foreach (integrity_default_groups() as $g) {
        $dirs[] = $root . '/' . $g['key'];
        foreach ($g['versions'] as $v) {
            $dirs[] = $root . '/' . $g['key'] . '/' . $v;
        }
    }
    return $dirs;
}

// ── Disk inspection ────────────────────────────────────────────────────────────

/**
 * Returns admin-added root apps (not in default list).
 */
function integrity_extra_apps(): array {
    $root     = integrity_repo_root();
    $defaults = integrity_default_keys();
    if (!is_dir($root)) return [];
    $names = [];
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($root . '/' . $entry)) continue;
        if (!in_array($entry, $defaults, true)) $names[] = $entry;
    }
    sort($names);
    return $names;
}

/**
 * Returns sub-directories (branches or versions) inside a given path.
 */
function integrity_app_versions(string $appKey): array {
    $dir = integrity_repo_root() . '/' . $appKey;
    if (!is_dir($dir)) return [];
    $names = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (is_dir($dir . '/' . $entry)) $names[] = $entry;
    }
    sort($names);
    return $names;
}

/**
 * Scans 3 levels deep (app/branch/version) and returns non-empty leaf dirs.
 * Used for the Integrity Check "origin" dropdown.
 *
 * @return array of ['label' => string, 'path' => string]
 */
function integrity_all_imported(): array {
    $root    = integrity_repo_root();
    $results = [];
    if (!is_dir($root)) return $results;
    foreach (scandir($root) ?: [] as $app) {
        if ($app === '.' || $app === '..') continue;
        $appDir = $root . '/' . $app;
        if (!is_dir($appDir)) continue;
        foreach (scandir($appDir) ?: [] as $branch) {
            if ($branch === '.' || $branch === '..') continue;
            $branchDir = $appDir . '/' . $branch;
            if (!is_dir($branchDir)) continue;
            foreach (scandir($branchDir) ?: [] as $version) {
                if ($version === '.' || $version === '..') continue;
                $vDir = $branchDir . '/' . $version;
                if (!is_dir($vDir)) continue;
                if (!empty(array_diff(scandir($vDir) ?: [], ['.', '..']))) {
                    $results[] = ['label' => "$app / $branch / $version", 'path' => $vDir];
                }
            }
        }
    }
    return $results;
}

// ── Default structure creation ─────────────────────────────────────────────────

function integrity_ensure_repo_structure(): array {
    $results = [];
    foreach (integrity_default_dirs() as $dir) {
        if (is_dir($dir)) {
            $results[] = ['path' => $dir, 'ok' => true, 'note' => 'already exists'];
            continue;
        }
        $ok        = @mkdir($dir, 0755, true);
        $results[] = ['path' => $dir, 'ok' => $ok, 'note' => $ok ? 'created' : 'failed'];
    }
    return $results;
}

// ── App / version creation ─────────────────────────────────────────────────────

function integrity_create_app(string $name): array {
    if (!integrity_valid_name($name)) {
        return ['ok' => false, 'exists' => false, 'path' => '', 'note' => 'invalid name'];
    }
    $path = integrity_repo_root() . '/' . $name;
    if (is_dir($path)) {
        return ['ok' => false, 'exists' => true, 'path' => $path, 'note' => 'already exists'];
    }
    $ok = @mkdir($path, 0755, true);
    return ['ok' => $ok, 'exists' => false, 'path' => $path, 'note' => $ok ? 'created' : 'failed'];
}

function integrity_create_version(string $app, string $version): array {
    if (!integrity_valid_name($app) || !integrity_valid_loose($version)) {
        return ['ok' => false, 'exists' => false, 'path' => '', 'note' => 'invalid name'];
    }
    $appPath = integrity_repo_root() . '/' . $app;
    if (!is_dir($appPath)) {
        return ['ok' => false, 'exists' => false, 'path' => $appPath . '/' . $version, 'note' => 'app folder does not exist'];
    }
    $path = $appPath . '/' . $version;
    if (is_dir($path)) {
        return ['ok' => false, 'exists' => true, 'path' => $path, 'note' => 'already exists'];
    }
    $ok = @mkdir($path, 0755, true);
    return ['ok' => $ok, 'exists' => false, 'path' => $path, 'note' => $ok ? 'created' : 'failed'];
}

// ── Filesystem utilities ───────────────────────────────────────────────────────

/**
 * Recursively deletes a directory.
 * Safety guard: path must be under integrity_repo_root().
 */
function integrity_rmdir_recursive(string $dir): bool {
    $real = realpath($dir);
    if ($real === false) return false;
    if (strncmp($real, integrity_repo_root(), strlen(integrity_repo_root())) !== 0) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    return @rmdir($dir);
}

function integrity_dir_stats(string $dir): array {
    $files = 0;
    $dirs  = 0;
    $size  = 0;
    if (!is_dir($dir)) return compact('files', 'dirs', 'size');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) { $dirs++; } else { $files++; $size += $item->getSize(); }
    }
    return compact('files', 'dirs', 'size');
}

function integrity_format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ── ZIP security & extraction ──────────────────────────────────────────────────

/**
 * Scans all ZIP entries for path traversal, absolute paths, and symlinks.
 * Returns array of error strings (empty = safe).
 */
function integrity_zip_scan(ZipArchive $zip): array {
    $errors = [];
    for ($i = 0; $i < $zip->count(); $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $name = $stat['name'];
        if ($name === '' || $name === '/') continue;
        // Absolute paths
        if ($name[0] === '/' || preg_match('/^[a-zA-Z]:[\\/]/', $name)) {
            $errors[] = 'Absolute path in ZIP entry: ' . $name;
            continue;
        }
        // Path traversal or null bytes
        if (
            str_contains($name, '../') ||
            str_contains($name, "..\\") ||
            str_contains($name, "\0")
        ) {
            $errors[] = 'Unsafe path in ZIP entry: ' . $name;
            continue;
        }
        // Symlinks via Unix external attributes
        $extAttr  = $stat['external_attr'] ?? 0;
        $unixMode = ($extAttr >> 16) & 0xFFFF;
        if (($unixMode & 0xF000) === 0xA000) {
            $errors[] = 'Symlink in ZIP entry: ' . $name;
        }
    }
    return $errors;
}

/**
 * Detects a single wrapper folder (e.g. Joomla_4.4.14/).
 * Returns the wrapper name, or null if there are multiple top-level entries.
 */
function integrity_zip_wrapper(ZipArchive $zip): ?string {
    $tops = [];
    for ($i = 0; $i < $zip->count(); $i++) {
        $name = $zip->getNameIndex($i);
        $top  = explode('/', trim($name, '/'), 2)[0] ?? '';
        if ($top !== '') $tops[$top] = true;
    }
    return count($tops) === 1 ? array_key_first($tops) : null;
}

/**
 * Extracts a ZIP to $targetDir, stripping $wrapper prefix if provided.
 * Returns ['ok' => bool, 'error' => string].
 */
function integrity_zip_extract(string $zipPath, string $targetDir, ?string $wrapper): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => 'Cannot open ZIP file.'];
    }
    $prefix    = $wrapper !== null ? rtrim($wrapper, '/') . '/' : '';
    $prefixLen = strlen($prefix);
    for ($i = 0; $i < $zip->count(); $i++) {
        $name = $zip->getNameIndex($i);
        $rel  = $prefix !== '' ? (str_starts_with($name, $prefix) ? substr($name, $prefixLen) : null) : $name;
        if ($rel === null || $rel === '' || $rel === '/') continue;
        $dest = $targetDir . '/' . ltrim($rel, '/');
        if (substr($name, -1) === '/') {
            @mkdir($dest, 0755, true);
        } else {
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
            $stream = $zip->getStream($name);
            if ($stream) {
                $fp = @fopen($dest, 'wb');
                if ($fp) { stream_copy_to_stream($stream, $fp); fclose($fp); }
                fclose($stream);
            }
        }
    }
    $zip->close();
    return ['ok' => true, 'error' => ''];
}

// ── Path browser (security-constrained to /home) ───────────────────────────────

/**
 * Resolves a browser path safely. Returns canonical path or null if disallowed.
 * Rules: must resolve under /home, no symlinks escaping /home.
 */
function integrity_browser_resolve(string $path): ?string
{
    $path = rtrim($path, '/');
    if ($path === '') $path = '/home';

    // Reject before realpath if obvious traversal
    if (str_contains($path, "\0")) return null;

    $real = realpath($path);
    if ($real === false) return null;

    // Must stay inside /home
    if ($real !== '/home' && !str_starts_with($real, '/home/')) return null;

    // Must be a directory
    if (!is_dir($real)) return null;

    return $real;
}

/**
 * Lists immediate subdirectories of a path, constrained to /home.
 * Returns ['ok' => bool, 'path' => string, 'entries' => [...], 'error' => string].
 * Each entry: ['name' => string, 'path' => string, 'has_children' => bool].
 */
function integrity_browse_dir(string $requestedPath): array
{
    $resolved = integrity_browser_resolve($requestedPath);
    if ($resolved === null) {
        return ['ok' => false, 'path' => '', 'entries' => [], 'error' => 'Path is not allowed. Must be inside /home.'];
    }

    $entries = [];
    $scan    = @scandir($resolved);
    if ($scan === false) {
        return ['ok' => false, 'path' => $resolved, 'entries' => [], 'error' => 'Cannot read directory (permission denied).'];
    }

    foreach ($scan as $name) {
        if ($name === '.' || $name === '..') continue;
        $fullPath = $resolved . '/' . $name;

        // Only directories; skip symlinks that point outside /home
        if (!is_dir($fullPath)) continue;
        $realSub = realpath($fullPath);
        if ($realSub === false) continue;
        if ($realSub !== '/home' && !str_starts_with($realSub, '/home/')) continue;

        // Does it have any subdirectories?
        $hasChildren = false;
        $subScan     = @scandir($fullPath);
        if ($subScan) {
            foreach ($subScan as $sub) {
                if ($sub === '.' || $sub === '..') continue;
                if (is_dir($fullPath . '/' . $sub)) { $hasChildren = true; break; }
            }
        }

        $entries[] = ['name' => $name, 'path' => $realSub, 'has_children' => $hasChildren];
    }

    usort($entries, fn($a, $b) => strcmp($a['name'], $b['name']));

    return ['ok' => true, 'path' => $resolved, 'entries' => $entries, 'error' => ''];
}

// ── Software / version detector ────────────────────────────────────────────────

/**
 * Detects CMS/software installed at $path.
 * Returns ['software' => string, 'version' => string, 'root' => string].
 * 'software' is one of: Joomla, WordPress, WHMCS, PrestaShop, phpBB, Dolibarr, Unknown.
 * 'version' is the detected version string or 'unknown'.
 */
function integrity_detect_software(string $path): array
{
    $real = integrity_browser_resolve($path);
    if ($real === null) {
        return ['software' => 'Unknown', 'version' => 'unknown', 'root' => $path, 'error' => 'Path not accessible.'];
    }

    // ── Joomla ────────────────────────────────────────────────────────────────
    if (
        is_file($real . '/configuration.php') &&
        is_file($real . '/administrator/manifests/files/joomla.xml')
    ) {
        $version = _int_read_xml_version($real . '/administrator/manifests/files/joomla.xml');
        return ['software' => 'Joomla', 'version' => $version, 'root' => $real, 'error' => ''];
    }

    // ── WordPress ─────────────────────────────────────────────────────────────
    if (
        is_file($real . '/wp-config.php') &&
        is_file($real . '/wp-includes/version.php')
    ) {
        $version = _int_read_wp_version($real . '/wp-includes/version.php');
        return ['software' => 'WordPress', 'version' => $version, 'root' => $real, 'error' => ''];
    }

    // ── WHMCS ─────────────────────────────────────────────────────────────────
    if (
        is_file($real . '/configuration.php') &&
        (
            is_dir($real . '/vendor/whmcs') ||
            is_file($real . '/vendor/whmcs/whmcs-foundation/lib/Version/SemanticVersion.php')
        )
    ) {
        return ['software' => 'WHMCS', 'version' => 'unknown', 'root' => $real, 'error' => ''];
    }

    // ── PrestaShop ────────────────────────────────────────────────────────────
    $psAutoload = is_file($real . '/classes/PrestaShopAutoload.php');
    if ($psAutoload) {
        $version = 'unknown';
        if (is_file($real . '/app/config/parameters.php')) {
            // modern PS
        } elseif (is_file($real . '/config/settings.inc.php')) {
            $version = _int_read_ps_version($real . '/config/settings.inc.php');
        }
        return ['software' => 'PrestaShop', 'version' => $version, 'root' => $real, 'error' => ''];
    }

    // ── phpBB ─────────────────────────────────────────────────────────────────
    if (
        is_file($real . '/config.php') &&
        is_file($real . '/includes/constants.php') &&
        is_dir($real . '/phpbb')
    ) {
        $version = _int_read_phpbb_version($real . '/includes/constants.php');
        return ['software' => 'phpBB', 'version' => $version, 'root' => $real, 'error' => ''];
    }

    // ── Dolibarr ──────────────────────────────────────────────────────────────
    // Path might be /htdocs or the parent
    if (is_file($real . '/htdocs/main.inc.php') && is_file($real . '/htdocs/conf/conf.php')) {
        return ['software' => 'Dolibarr', 'version' => 'unknown', 'root' => $real, 'error' => ''];
    }
    if (is_file($real . '/main.inc.php') && is_file($real . '/conf/conf.php')) {
        return ['software' => 'Dolibarr', 'version' => 'unknown', 'root' => $real, 'error' => ''];
    }

    return ['software' => 'Unknown', 'version' => 'unknown', 'root' => $real, 'error' => ''];
}

// ── Detector helpers ──────────────────────────────────────────────────────────

function _int_read_xml_version(string $file): string
{
    $content = @file_get_contents($file, false, null, 0, 8192);
    if ($content === false) return 'unknown';
    if (preg_match('/<version>\s*([^<\s]+)\s*<\/version>/i', $content, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

function _int_read_wp_version(string $file): string
{
    $content = @file_get_contents($file, false, null, 0, 4096);
    if ($content === false) return 'unknown';
    if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

function _int_read_ps_version(string $file): string
{
    $content = @file_get_contents($file, false, null, 0, 4096);
    if ($content === false) return 'unknown';
    if (preg_match("/define\s*\(\s*'_PS_VERSION_'\s*,\s*'([^']+)'/i", $content, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

function _int_read_phpbb_version(string $file): string
{
    $content = @file_get_contents($file, false, null, 0, 8192);
    if ($content === false) return 'unknown';
    if (preg_match("/define\s*\(\s*'PHPBB_VERSION'\s*,\s*'([^']+)'/i", $content, $m)) {
        return trim($m[1]);
    }
    return 'unknown';
}

// ── User-content folders ───────────────────────────────────────────────────────

/**
 * Returns relative folder paths whose contents should not be structurally compared.
 * The folders themselves are still expected to exist.
 */
function integrity_user_content_folders(string $software): array {
    return match (strtolower($software)) {
        'joomla'     => ['images', 'cache', 'tmp', 'logs'],
        'wordpress'  => ['wp-content/uploads', 'wp-content/cache'],
        'prestashop' => ['img', 'cache', 'log', 'download', 'upload'],
        default      => [],
    };
}

// ── DB: integrity ignore list ──────────────────────────────────────────────────

/**
 * Creates all Integrity module tables if they do not exist.
 * Called once per page load before any DB query in this module.
 */
function integrity_ensure_tables(PDO $pdo): bool {
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_ignores` (
               `id`               INT           NOT NULL AUTO_INCREMENT,
               `origin_path`      VARCHAR(1024) NOT NULL,
               `destination_path` VARCHAR(1024) NOT NULL,
               `ignored_path`     VARCHAR(1024) NOT NULL,
               `ignore_type`      VARCHAR(50)   NOT NULL DEFAULT \'extra_path\',
               `note`             TEXT          NULL,
               `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_origin_path`      (`origin_path`(100)),
               KEY `idx_destination_path` (`destination_path`(100)),
               KEY `idx_ignored_path`     (`ignored_path`(100))
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_runs` (
               `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `origin_path`      VARCHAR(1000) NOT NULL,
               `destination_path` VARCHAR(1000) NOT NULL,
               `software`         VARCHAR(100)  NULL,
               `total`            INT UNSIGNED  NOT NULL DEFAULT 0,
               `suspicious`       INT UNSIGNED  NOT NULL DEFAULT 0,
               `warnings`         INT UNSIGNED  NOT NULL DEFAULT 0,
               `info`             INT UNSIGNED  NOT NULL DEFAULT 0,
               `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_origin_path`      (`origin_path`(100)),
               KEY `idx_destination_path` (`destination_path`(100)),
               KEY `idx_created_at`       (`created_at`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_results` (
               `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `run_id`           INT UNSIGNED  NOT NULL,
               `origin_path`      VARCHAR(1000) NOT NULL,
               `destination_path` VARCHAR(1000) NOT NULL,
               `type`             VARCHAR(40)   NOT NULL,
               `severity`         VARCHAR(20)   NOT NULL,
               `relative_path`    VARCHAR(2000) NOT NULL,
               `full_path`        VARCHAR(2000) NOT NULL,
               `status`           VARCHAR(30)   NOT NULL DEFAULT \'new\',
               `note`             TEXT          NULL,
               `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               `updated_at`       DATETIME      NULL      ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_run_id`   (`run_id`),
               KEY `idx_status`   (`status`),
               KEY `idx_severity` (`severity`),
               KEY `idx_type`     (`type`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Add scan_exclusions column if table was created by an older migration.
        try {
            $pdo->exec("ALTER TABLE `scanner_integrity_runs` ADD COLUMN `scan_exclusions` TEXT NULL AFTER `info`");
        } catch (PDOException $e) { /* column already exists */ }

        // Hash-related columns added in v0.8.0
        foreach ([
            "ALTER TABLE `scanner_integrity_results` ADD COLUMN `repo_sha256`        CHAR(64) NULL AFTER `full_path`",
            "ALTER TABLE `scanner_integrity_results` ADD COLUMN `destination_sha256` CHAR(64) NULL AFTER `repo_sha256`",
            "ALTER TABLE `scanner_integrity_results` ADD COLUMN `repo_size`          BIGINT   NULL AFTER `destination_sha256`",
            "ALTER TABLE `scanner_integrity_results` ADD COLUMN `destination_size`   BIGINT   NULL AFTER `repo_size`",
            "ALTER TABLE `scanner_integrity_runs`    ADD COLUMN `check_mode`         VARCHAR(20) NOT NULL DEFAULT 'structural' AFTER `software`",
            "ALTER TABLE `scanner_integrity_runs`    ADD COLUMN `summary_json`       TEXT NULL AFTER `scan_exclusions`",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { /* column already exists */ }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_repo_files` (
               `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `repo_key`      VARCHAR(255)  NOT NULL,
               `application`   VARCHAR(100)  NOT NULL,
               `branch`        VARCHAR(100)  NOT NULL,
               `version`       VARCHAR(100)  NOT NULL,
               `repo_path`     VARCHAR(1024) NOT NULL,
               `relative_path` VARCHAR(1024) NOT NULL,
               `file_type`     VARCHAR(20)   NOT NULL DEFAULT \'file\',
               `sha256`        CHAR(64)      NULL,
               `size_bytes`    BIGINT        NULL,
               `mtime`         INT           NULL,
               `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               `updated_at`    DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_repo_key`       (`repo_key`),
               KEY `idx_app_branch_ver` (`application`, `branch`, `version`),
               KEY `idx_relative_path`  (`relative_path`(100)),
               KEY `idx_sha256`         (`sha256`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Exclusion templates — v0.9.0
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_exclusion_templates` (
               `id`          INT          NOT NULL AUTO_INCREMENT,
               `name`        VARCHAR(190) NOT NULL,
               `description` TEXT         NULL,
               `cms`         VARCHAR(100) NULL,
               `active`      TINYINT(1)   NOT NULL DEFAULT 1,
               `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               `updated_at`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `idx_active` (`active`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `scanner_integrity_exclusion_template_items` (
               `id`          INT           NOT NULL AUTO_INCREMENT,
               `template_id` INT           NOT NULL,
               `path`        VARCHAR(1024) NOT NULL,
               `sort_order`  INT           NOT NULL DEFAULT 0,
               PRIMARY KEY (`id`),
               KEY `idx_template_id` (`template_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Seed default Joomla 4 template if table was just created (no rows yet)
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM `scanner_integrity_exclusion_templates`')->fetchColumn();
        if ($existing === 0) {
            $pdo->exec(
                "INSERT INTO `scanner_integrity_exclusion_templates` (`name`, `description`, `cms`, `active`)
                 VALUES ('Joomla 4 production',
                         'Standard exclusions for a Joomla 4 production site — extensions, templates, media, and runtime directories.',
                         'Joomla', 1)"
            );
            $tplId = (int) $pdo->lastInsertId();
            $joomla4Paths = [
                'administrator/components/', 'administrator/modules/', 'administrator/templates/',
                'administrator/manifests/packages/', 'administrator/manifests/libraries/',
                'administrator/manifests/modules/', 'administrator/manifests/plugins/',
                'administrator/language/', 'components/', 'modules/', 'plugins/', 'templates/',
                'media/', 'images/', 'language/', 'cache/', 'tmp/', 'logs/',
            ];
            $sort = 1;
            $stmt = $pdo->prepare(
                'INSERT INTO `scanner_integrity_exclusion_template_items` (`template_id`, `path`, `sort_order`) VALUES (?, ?, ?)'
            );
            foreach ($joomla4Paths as $p) {
                $stmt->execute([$tplId, $p, $sort++]);
            }
        }

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Exclusion templates ────────────────────────────────────────────────────────

/**
 * Returns all active (or all) exclusion templates with their paths.
 * Each entry: ['id', 'name', 'description', 'cms', 'active', 'paths' => string[]].
 */
function integrity_load_exclusion_templates(PDO $pdo, bool $activeOnly = true): array {
    try {
        $sql   = 'SELECT * FROM `scanner_integrity_exclusion_templates`' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name';
        $tmpls = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($tmpls)) return [];
        $ids   = array_column($tmpls, 'id');
        $in    = implode(',', array_fill(0, count($ids), '?'));
        $items = $pdo->prepare("SELECT template_id, path FROM `scanner_integrity_exclusion_template_items` WHERE template_id IN ($in) ORDER BY sort_order, id");
        $items->execute($ids);
        $pathMap = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pathMap[(int)$row['template_id']][] = $row['path'];
        }
        foreach ($tmpls as &$t) {
            $t['paths'] = $pathMap[(int)$t['id']] ?? [];
        }
        unset($t);
        return $tmpls;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Saves a new exclusion template and its paths. Returns the new template ID (0 on error).
 * $paths: array of normalized path strings (trailing slash, no traversal).
 */
function integrity_save_exclusion_template(PDO $pdo, string $name, string $description, string $cms, array $paths): int {
    $name = trim($name);
    if ($name === '' || empty($paths)) return 0;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `scanner_integrity_exclusion_templates` (`name`, `description`, `cms`) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, trim($description), trim($cms)]);
        $tplId = (int) $pdo->lastInsertId();
        $item  = $pdo->prepare(
            'INSERT INTO `scanner_integrity_exclusion_template_items` (`template_id`, `path`, `sort_order`) VALUES (?, ?, ?)'
        );
        $sort = 1;
        foreach ($paths as $p) {
            $p = trim($p);
            if ($p !== '') $item->execute([$tplId, $p, $sort++]);
        }
        return $tplId;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Loads a single template (with paths). Returns null if not found.
 */
function integrity_load_exclusion_template(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM `scanner_integrity_exclusion_templates` WHERE id = ?');
        $stmt->execute([$id]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) return null;
        $stmt2 = $pdo->prepare(
            'SELECT path FROM `scanner_integrity_exclusion_template_items` WHERE template_id = ? ORDER BY sort_order, id'
        );
        $stmt2->execute([$id]);
        $tpl['paths'] = $stmt2->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return $tpl;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Replaces all paths for an existing template and optionally updates name/desc/cms.
 */
function integrity_update_exclusion_template(PDO $pdo, int $id, string $name, string $description, string $cms, array $paths): bool {
    $name = trim($name);
    if ($name === '') return false;
    try {
        $pdo->prepare(
            'UPDATE `scanner_integrity_exclusion_templates` SET name=?, description=?, cms=?, updated_at=NOW() WHERE id=?'
        )->execute([trim($name), trim($description), trim($cms), $id]);
        $pdo->prepare('DELETE FROM `scanner_integrity_exclusion_template_items` WHERE template_id = ?')->execute([$id]);
        $item = $pdo->prepare(
            'INSERT INTO `scanner_integrity_exclusion_template_items` (`template_id`, `path`, `sort_order`) VALUES (?, ?, ?)'
        );
        $sort = 1;
        foreach ($paths as $p) {
            $p = trim($p);
            if ($p !== '') $item->execute([$id, $p, $sort++]);
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Toggles active flag. Returns true on success.
 */
function integrity_toggle_exclusion_template(PDO $pdo, int $id, bool $active): bool {
    try {
        return (bool) $pdo->prepare(
            'UPDATE `scanner_integrity_exclusion_templates` SET active=?, updated_at=NOW() WHERE id=?'
        )->execute([(int)$active, $id]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Deletes a template and its items.
 */
function integrity_delete_exclusion_template(PDO $pdo, int $id): bool {
    try {
        $pdo->prepare('DELETE FROM `scanner_integrity_exclusion_template_items` WHERE template_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM `scanner_integrity_exclusion_templates` WHERE id = ?')->execute([$id]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Ignore path matching ───────────────────────────────────────────────────────

/**
 * Returns true if a relative path should be ignored.
 *
 * Matching rules (per stored path):
 *   - Trailing '/'  → prefix match: rel == prefix OR rel starts with prefix + '/'
 *   - No trailing / → exact match: rel == stored
 *
 * This covers both post-run exact ignores (no trailing /) and
 * pre-run scan exclusions / subtree ignores (trailing /).
 */
function integrity_path_is_ignored(string $rel, array $storedPaths): bool {
    foreach ($storedPaths as $stored) {
        if ($stored === '') continue;
        if (substr($stored, -1) === '/') {
            $prefix = rtrim($stored, '/');
            if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) return true;
        } else {
            if ($rel === $stored) return true;
        }
    }
    return false;
}

/**
 * Parses a textarea of paths (one per line) into normalized scan exclusion strings.
 *
 * Rules:
 *   - Absolute paths inside $destRoot are stripped to relative form.
 *   - Absolute paths outside $destRoot are rejected.
 *   - Path traversal, backslash, null bytes are rejected.
 *   - All output entries end with '/' (prefix matching convention).
 *
 * @return string[]
 */
function integrity_parse_scan_exclusions(string $text, string $destRoot): array {
    $realDest = rtrim(realpath($destRoot) ?: $destRoot, '/');
    $rules    = [];

    foreach (explode("\n", str_replace("\r", "\n", $text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Reject unsafe chars
        if (str_contains($line, '\\') || str_contains($line, "\0")) continue;
        // Normalize absolute to relative
        if (str_starts_with($line, '/')) {
            $stripped = rtrim($line, '/');
            if ($realDest !== '' && str_starts_with($stripped, $realDest . '/')) {
                $line = substr($stripped, strlen($realDest) + 1);
            } elseif ($stripped === $realDest) {
                continue; // trying to exclude the root itself
            } else {
                continue; // outside destRoot
            }
        }
        $line = ltrim($line, '/');
        if ($line === '') continue;
        // Reject traversal
        foreach (explode('/', $line) as $seg) {
            if ($seg === '..') continue 2;
        }
        // Normalize to trailing slash (prefix convention)
        $rules[] = rtrim($line, '/') . '/';
    }

    return array_values(array_unique($rules));
}

/**
 * Returns array of ignored relative paths for a specific origin+destination pair.
 * Returns empty array on any DB error.
 */
function integrity_ignores_for(PDO $pdo, string $originPath, string $destPath): array {
    try {
        $stmt = $pdo->prepare(
            'SELECT ignored_path FROM scanner_integrity_ignores
              WHERE origin_path = :o AND destination_path = :d'
        );
        $stmt->execute([':o' => $originPath, ':d' => $destPath]);
        return array_column($stmt->fetchAll(), 'ignored_path');
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Adds an integrity ignore entry. Silently deduplicates.
 * Ensures the table exists before writing.
 */
function integrity_add_ignore(
    PDO    $pdo,
    string $originPath,
    string $destPath,
    string $ignoredPath,
    string $ignoreType = 'extra_path',
    string $note = ''
): bool {
    $ignoredPath = ltrim($ignoredPath, '/');
    if ($ignoredPath === '' || str_contains($ignoredPath, '..')) return false;

    $ignoreType = in_array($ignoreType, ['extra_path', 'missing_path'], true)
        ? $ignoreType : 'extra_path';

    integrity_ensure_tables($pdo);

    try {
        $check = $pdo->prepare(
            'SELECT id FROM scanner_integrity_ignores
              WHERE origin_path = :o AND destination_path = :d AND ignored_path = :i
              LIMIT 1'
        );
        $check->execute([':o' => $originPath, ':d' => $destPath, ':i' => $ignoredPath]);
        if ($check->fetchColumn()) return true;

        $stmt = $pdo->prepare(
            'INSERT INTO scanner_integrity_ignores
               (origin_path, destination_path, ignored_path, ignore_type, note, created_at)
             VALUES (:o, :d, :i, :t, :n, NOW())'
        );
        return $stmt->execute([':o' => $originPath, ':d' => $destPath, ':i' => $ignoredPath,
                               ':t' => $ignoreType, ':n' => $note]);
    } catch (PDOException $e) {
        return false;
    }
}

// ── Structural check ───────────────────────────────────────────────────────────

/**
 * Recursive tree scanner. Stops recursion at user-content folder boundaries
 * and at symlinks pointing outside $base.
 */
function _int_scan_tree(
    string $base,
    string $relPrefix,
    array  $ucFolders,
    array  &$items,
    int    $maxItems = 20000
): void {
    if (count($items) >= $maxItems) return;

    $dir  = $base . ($relPrefix !== '' ? '/' . $relPrefix : '');
    $scan = @scandir($dir);
    if ($scan === false) return;

    foreach ($scan as $name) {
        if ($name === '.' || $name === '..') continue;
        if (count($items) >= $maxItems) break;

        $rel  = $relPrefix !== '' ? $relPrefix . '/' . $name : $name;
        $full = $base . '/' . $rel;

        // Don't follow symlinks that escape the tree root
        if (is_link($full)) {
            $real = realpath($full);
            if ($real === false || !str_starts_with($real, $base . '/')) continue;
        }

        $isDir         = is_dir($full);
        $isUserContent = $isDir && in_array($rel, $ucFolders, true);

        $items[] = ['rel' => $rel, 'is_dir' => $isDir, 'is_user_content' => $isUserContent];

        if ($isDir && !$isUserContent) {
            _int_scan_tree($base, $rel, $ucFolders, $items, $maxItems);
        }
    }
}

/**
 * Structural integrity check: compares origin repo vs destination install by
 * folder/file existence only. No hash comparison. No content analysis.
 *
 * Finding types:
 *   EXTRA_DIRECTORY   — in destination root or subdirectory, not in origin
 *   EXTRA_FILE        — same, but file
 *   MISSING_DIRECTORY — in origin, absent in destination
 *   MISSING_FILE      — same, but file
 *   USER_CONTENT_FOLDER — known runtime folder, contents not compared (info only)
 *
 * Severity:
 *   extra at destination root (depth 0) → suspicious
 *   extra below root                    → warning
 *   missing                             → warning
 *   user-content folder present         → info
 *
 * @param string   $originPath   Clean repo path
 * @param string   $destPath     Installation path, must be under /home
 * @param string   $software     Detected software name
 * @param string[] $ignoredPaths Relative paths to skip
 *
 * @return array {ok, error, findings, origin, dest, truncated, counts}
 */
function integrity_structural_check(
    string $originPath,
    string $destPath,
    string $software,
    array  $ignoredPaths
): array {
    $empty = ['findings' => [], 'origin' => '', 'dest' => '', 'truncated' => false, 'counts' => []];

    $realOrigin = realpath($originPath);
    if ($realOrigin === false || !is_dir($realOrigin)) {
        return array_merge($empty, ['ok' => false, 'error' => 'Origin path not accessible: ' . $originPath]);
    }

    $realDest = realpath($destPath);
    if ($realDest === false || !is_dir($realDest)) {
        return array_merge($empty, ['ok' => false, 'error' => 'Destination path not accessible: ' . $destPath,
                                    'origin' => $realOrigin]);
    }
    if ($realDest !== '/home' && !str_starts_with($realDest, '/home/')) {
        return array_merge($empty, ['ok' => false, 'error' => 'Destination must be inside /home.',
                                    'origin' => $realOrigin, 'dest' => $realDest]);
    }

    $ucFolders  = integrity_user_content_folders($software);
    $maxItems   = 20000;
    $truncated  = false;

    $originItems = [];
    _int_scan_tree($realOrigin, '', $ucFolders, $originItems, $maxItems);
    if (count($originItems) >= $maxItems) $truncated = true;

    $destItems = [];
    _int_scan_tree($realDest, '', $ucFolders, $destItems, $maxItems);
    if (count($destItems) >= $maxItems) $truncated = true;

    $originSet = array_column($originItems, null, 'rel');
    $destSet   = array_column($destItems,   null, 'rel');

    $findings = [];
    $counts   = ['suspicious' => 0, 'warning' => 0, 'info' => 0];

    // Missing: in origin, not in destination
    foreach ($originItems as $item) {
        $rel = $item['rel'];
        if (integrity_path_is_ignored($rel, $ignoredPaths)) continue;
        if (!isset($destSet[$rel])) {
            $type = $item['is_dir'] ? 'MISSING_DIRECTORY' : 'MISSING_FILE';
            $findings[]       = ['type' => $type, 'rel' => $rel,
                                  'fullpath' => $realDest . '/' . $rel, 'severity' => 'warning'];
            $counts['warning']++;
        } elseif ($item['is_user_content']) {
            $findings[] = ['type' => 'USER_CONTENT_FOLDER', 'rel' => $rel,
                           'fullpath' => $realDest . '/' . $rel, 'severity' => 'info'];
            $counts['info']++;
        }
    }

    // Extra: in destination, not in origin
    foreach ($destItems as $item) {
        $rel = $item['rel'];
        if (integrity_path_is_ignored($rel, $ignoredPaths)) continue;
        if ($item['is_user_content']) continue;
        if (isset($originSet[$rel]))  continue;

        $type     = $item['is_dir'] ? 'EXTRA_DIRECTORY' : 'EXTRA_FILE';
        $depth    = substr_count($rel, '/');
        $severity = ($depth === 0) ? 'suspicious' : 'warning';

        $findings[]         = ['type' => $type, 'rel' => $rel,
                                'fullpath' => $realDest . '/' . $rel, 'severity' => $severity];
        $counts[$severity]++;
    }

    $order = ['suspicious' => 0, 'warning' => 1, 'info' => 2];
    usort($findings, static fn($a, $b) =>
        ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9)
        ?: strcmp($a['rel'], $b['rel'])
    );

    return [
        'ok'        => true,
        'error'     => '',
        'findings'  => $findings,
        'origin'    => $realOrigin,
        'dest'      => $realDest,
        'truncated' => $truncated,
        'counts'    => $counts,
        'mode'      => 'structural',
        'summary'   => [],
    ];
}

// ── DB: run persistence ────────────────────────────────────────────────────────

/**
 * Saves a structural check run to DB. Returns run_id on success, 0 on error.
 * $scanExclusions: array of normalized exclusion strings (trailing-slash prefix form).
 * $checkMode: 'hash' or 'structural'.
 * $summary: optional extended counters (checked_files, ok_files, modified_files, etc.).
 */
function integrity_save_run(PDO $pdo, string $origin, string $dest, string $software, array $counts, array $scanExclusions = [], string $checkMode = 'structural', array $summary = []): int {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO scanner_integrity_runs
               (origin_path, destination_path, software, check_mode, total, suspicious, warnings, info,
                scan_exclusions, summary_json, created_at)
             VALUES (:o, :d, :s, :mode, :total, :susp, :warn, :info, :excl, :sumj, NOW())'
        );
        $stmt->execute([
            ':o'     => $origin,
            ':d'     => $dest,
            ':s'     => $software ?: null,
            ':mode'  => $checkMode,
            ':total' => ($counts['suspicious'] ?? 0) + ($counts['warning'] ?? 0) + ($counts['info'] ?? 0),
            ':susp'  => $counts['suspicious'] ?? 0,
            ':warn'  => $counts['warning']    ?? 0,
            ':info'  => $counts['info']       ?? 0,
            ':excl'  => !empty($scanExclusions) ? implode("\n", $scanExclusions) : null,
            ':sumj'  => !empty($summary) ? json_encode($summary) : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Bulk-inserts findings for a run. Returns number of rows inserted.
 * Each finding may include optional hash fields: repo_sha256, destination_sha256, repo_size, destination_size.
 */
function integrity_save_results(PDO $pdo, int $runId, string $origin, string $dest, array $findings): int {
    if (empty($findings)) return 0;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO scanner_integrity_results
               (run_id, origin_path, destination_path, type, severity, relative_path, full_path,
                repo_sha256, destination_sha256, repo_size, destination_size, status, created_at)
             VALUES (:run, :o, :d, :t, :sev, :rel, :full, :rsha, :dsha, :rsz, :dsz, \'new\', NOW())'
        );
        $inserted = 0;
        foreach ($findings as $f) {
            $stmt->execute([
                ':run'  => $runId,
                ':o'    => $origin,
                ':d'    => $dest,
                ':t'    => $f['type'],
                ':sev'  => $f['severity'],
                ':rel'  => $f['rel'],
                ':full' => $f['fullpath'],
                ':rsha' => $f['repo_sha256']        ?? null,
                ':dsha' => $f['destination_sha256'] ?? null,
                ':rsz'  => $f['repo_size']          ?? null,
                ':dsz'  => $f['destination_size']   ?? null,
            ]);
            $inserted++;
        }
        return $inserted;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Loads run metadata by ID. Returns array or null.
 */
function integrity_load_run(PDO $pdo, int $runId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM scanner_integrity_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Loads results for a run with optional filters.
 * $filters keys: type, severity, status, path (substring match on relative_path).
 */
function integrity_load_results(PDO $pdo, int $runId, array $filters = []): array {
    try {
        $allRuns = !empty($filters['all_runs']);
        $where   = [];
        $params  = [];

        // run_id scope (skip when all_runs + id filter is used)
        if ($runId > 0 && !$allRuns) {
            $where[]        = 'run_id = :run';
            $params[':run'] = $runId;
        }

        // ID filter — parse "1,5,10-20,33" syntax
        $idList = [];
        if (!empty($filters['ids'])) {
            foreach (explode(',', $filters['ids']) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (str_contains($part, '-')) {
                    [$a, $b] = explode('-', $part, 2);
                    $a = (int) trim($a);
                    $b = (int) trim($b);
                    if ($a > 0 && $b >= $a && ($b - $a) <= 10000) {
                        for ($i = $a; $i <= $b; $i++) $idList[] = $i;
                    }
                } else {
                    $n = (int) $part;
                    if ($n > 0) $idList[] = $n;
                }
            }
            $idList = array_values(array_unique($idList));
        }

        if (!empty($idList)) {
            $ph      = implode(',', array_fill(0, count($idList), '?'));
            $where[] = 'id IN (' . $ph . ')';
            foreach ($idList as $idVal) $params[] = $idVal;
        }

        if (!empty($filters['type'])) {
            $where[] = 'type = :ft';
            $params[':ft'] = $filters['type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = :fs';
            $params[':fs'] = $filters['severity'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :fst';
            $params[':fst'] = $filters['status'];
        }
        if (!empty($filters['path'])) {
            $where[] = 'relative_path LIKE :fp';
            $params[':fp'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['path']) . '%';
        }

        $whereClause = empty($where) ? '1' : implode(' AND ', $where);
        $sql  = 'SELECT * FROM scanner_integrity_results WHERE ' . $whereClause
              . ' ORDER BY FIELD(severity,\'suspicious\',\'warning\',\'info\'), relative_path ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Loads specific result rows by their IDs, scoped to a run.
 */
function integrity_load_results_by_ids(PDO $pdo, int $runId, array $ids): array {
    if (empty($ids)) return [];
    try {
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM scanner_integrity_results WHERE run_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$runId], $ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function integrity_load_result_by_id(PDO $pdo, int $resultId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM scanner_integrity_results WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Updates status (and optional note) for a single result row, scoped to a run.
 */
function integrity_update_result_status(PDO $pdo, int $runId, int $resultId, string $status, string $note = ''): bool {
    $allowed = ['new', 'ignored_integrity', 'replaced', 'trashed', 'failed', 'reviewed', 'pending_action'];
    if (!in_array($status, $allowed, true)) return false;
    try {
        $stmt = $pdo->prepare(
            'UPDATE scanner_integrity_results
                SET status = :s, note = :n, updated_at = NOW()
              WHERE id = :id AND run_id = :run LIMIT 1'
        );
        return $stmt->execute([':s' => $status, ':n' => $note, ':id' => $resultId, ':run' => $runId]);
    } catch (PDOException $e) {
        return false;
    }
}

// ── Integrity Trash ────────────────────────────────────────────────────────────

function integrity_trash_root(): string {
    return integrity_storage_base() . '/trash';
}

/**
 * Moves a file or directory from its current location to the Integrity Trash.
 * Trash path: /home/8core_integrity/trash/YYYYMMDD-HHMMSS/<account>/<relative_path>
 * Safety: fullPath must be under /home and must match destRoot + / + relativePath.
 *
 * Returns:
 *   ['ok' => true, 'trash_path' => string]                    — success
 *   ['ok' => false, 'needs_queue' => true, 'error' => string, 'trash_path' => string]  — storage not ready / permission denied; caller should queue
 *   ['ok' => false, 'needs_queue' => false, 'error' => string] — hard error (invalid path, not in /home, etc.)
 */
function integrity_do_trash_path(string $fullPath, string $destRoot, string $relativePath): array {
    $empty = ['ok' => false, 'needs_queue' => false, 'error' => '', 'trash_path' => '', 'root_cmd' => ''];

    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return array_merge($empty, ['error' => 'Invalid relative path.']);
    }

    // Safety: path must exist and be inside /home
    $real = realpath($fullPath);
    if ($real === false) {
        $errMsg = file_exists($fullPath)
            ? 'Permission denied reading path: ' . $fullPath
            : 'Source path does not exist: ' . $fullPath;
        return array_merge($empty, ['error' => $errMsg]);
    }
    if ($real !== '/home' && !str_starts_with($real, '/home/')) {
        return array_merge($empty, ['error' => 'Path is not inside /home: ' . $real]);
    }

    // Prevent trashing the destination root itself
    $realDestRoot = rtrim(realpath($destRoot) ?: $destRoot, '/');
    if ($real === $realDestRoot) {
        return array_merge($empty, ['error' => 'Refusing to trash the destination root itself.']);
    }

    // Must match expected destination root + relative_path
    $expectedBase = $realDestRoot . '/' . $relativePath;
    $realExpected = realpath($expectedBase);
    if ($real !== $realExpected && $real !== $expectedBase) {
        return array_merge($empty, ['error' => 'Path does not match expected destination (' . $expectedBase . ').']);
    }

    // Must be inside destination root
    if (!str_starts_with($real, $realDestRoot . '/')) {
        return array_merge($empty, ['error' => 'Source path escapes destination root.']);
    }

    // Extract account name from destination path for trash sub-directory
    // e.g. /home/buckhr/public_html -> account = buckhr
    $destParts = array_values(array_filter(explode('/', $realDestRoot)));
    $account   = (count($destParts) >= 2 && $destParts[0] === 'home') ? $destParts[1] : 'unknown';

    $trashTs     = date('Ymd-His');
    $trashRoot   = integrity_trash_root();
    $trashDir    = $trashRoot . '/' . $trashTs . '/' . $account;
    $trashDest   = $trashDir . '/' . $relativePath;
    $trashParent = dirname($trashDest);

    // Storage not ready → signal caller to queue
    if (!is_dir($trashRoot) || !is_writable($trashRoot)) {
        return array_merge($empty, [
            'needs_queue' => true,
            'error'       => 'Integrity storage is not ready. Run one-time setup first.',
            'trash_path'  => $trashDest,
        ]);
    }

    if (!is_dir($trashParent) && !@mkdir($trashParent, 0755, true)) {
        $err = error_get_last();
        return array_merge($empty, [
            'needs_queue' => true,
            'error'       => 'Cannot create trash directory "' . $trashParent . '": ' . ($err['message'] ?? 'permission denied'),
            'trash_path'  => $trashDest,
        ]);
    }

    if (!@rename($real, $trashDest)) {
        $err     = error_get_last();
        $errMsg  = $err['message'] ?? 'rename() failed';
        $details = [];
        if (!is_readable($real))          $details[] = 'source not readable';
        if (!is_writable(dirname($real))) $details[] = 'source parent not writable';
        if (!is_writable($trashParent))   $details[] = 'trash destination not writable';
        if (!empty($details))             $errMsg .= ' (' . implode('; ', $details) . ')';
        return array_merge($empty, [
            'needs_queue' => true,
            'error'       => $errMsg,
            'trash_path'  => $trashDest,
        ]);
    }

    return ['ok' => true, 'needs_queue' => false, 'error' => '', 'trash_path' => $trashDest, 'root_cmd' => ''];
}

// ── Action queue ───────────────────────────────────────────────────────────────

function integrity_queue_action(
    PDO $pdo,
    int $resultId,
    string $action,
    string $sourcePath,
    string $targetPath,
    string $relativePath,
    string $requestedBy = ''
): int {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO scanner_integrity_actions
                (result_id, action, status, source_path, target_path, relative_path, requested_by)
             VALUES (:rid, :act, \'pending\', :src, :tgt, :rel, :by)'
        );
        $stmt->execute([
            ':rid' => $resultId,
            ':act' => $action,
            ':src' => $sourcePath,
            ':tgt' => $targetPath,
            ':rel' => $relativePath,
            ':by'  => $requestedBy,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        return 0;
    }
}

function integrity_load_action(PDO $pdo, int $actionId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM scanner_integrity_actions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $actionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function integrity_load_pending_actions(PDO $pdo): array {
    try {
        $stmt = $pdo->query(
            'SELECT * FROM scanner_integrity_actions WHERE status = \'pending\' ORDER BY created_at ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function integrity_cancel_action(PDO $pdo, int $actionId): bool {
    try {
        $stmt = $pdo->prepare(
            'UPDATE scanner_integrity_actions SET status = \'cancelled\' WHERE id = :id AND status = \'pending\' LIMIT 1'
        );
        $stmt->execute([':id' => $actionId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Returns the queued action for a result (pending or running), or null.
 */
function integrity_result_pending_action(PDO $pdo, int $resultId): ?array {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM scanner_integrity_actions
              WHERE result_id = :rid AND status IN (\'pending\', \'running\')
              ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([':rid' => $resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Replaces a missing path in the destination by copying it from the origin.
 * Only copies files; directories are created without recursion into origin sub-tree.
 * Safety: destRoot must be under /home; originFull must be under originRoot.
 *
 * Returns ['ok' => bool, 'error' => string].
 */
function integrity_do_replace_path(
    string $originRoot,
    string $originRelPath,
    string $destRoot,
    string $destRelPath
): array {
    $originRelPath = ltrim($originRelPath, '/');
    $destRelPath   = ltrim($destRelPath,   '/');

    if ($originRelPath === '' || $destRelPath === '') {
        return ['ok' => false, 'error' => 'Empty path.'];
    }
    if (str_contains($originRelPath, '..') || str_contains($destRelPath, '..')) {
        return ['ok' => false, 'error' => 'Path traversal rejected.'];
    }

    $realDestRoot = realpath($destRoot);
    if ($realDestRoot === false || ($realDestRoot !== '/home' && !str_starts_with($realDestRoot, '/home/'))) {
        return ['ok' => false, 'error' => 'Destination root must be inside /home.'];
    }

    $srcFull  = realpath($originRoot . '/' . $originRelPath);
    if ($srcFull === false) {
        return ['ok' => false, 'error' => 'Source path not found in origin.'];
    }
    $realOriginRoot = realpath($originRoot);
    if ($realOriginRoot === false || !str_starts_with($srcFull, $realOriginRoot . '/')) {
        return ['ok' => false, 'error' => 'Source escapes origin root.'];
    }

    $destFull = $realDestRoot . '/' . $destRelPath;
    if (file_exists($destFull)) {
        return ['ok' => false, 'error' => 'Destination already exists (will not overwrite).'];
    }

    $destParent = dirname($destFull);
    if (!is_dir($destParent) && !@mkdir($destParent, 0755, true)) {
        return ['ok' => false, 'error' => 'Cannot create destination parent directory.'];
    }

    if (is_dir($srcFull)) {
        if (!@mkdir($destFull, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create destination directory.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    if (!@copy($srcFull, $destFull)) {
        return ['ok' => false, 'error' => 'copy() failed. Check permissions.'];
    }

    return ['ok' => true, 'error' => ''];
}

// ── Repo hash database ─────────────────────────────────────────────────────────

function integrity_repo_key(string $app, string $branch, string $version): string {
    return strtolower($app) . '/' . strtolower($branch) . '/' . strtolower($version);
}

function integrity_repo_has_hashes(PDO $pdo, string $repoKey): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM scanner_integrity_repo_files WHERE repo_key = :k");
        $stmt->execute([':k' => $repoKey]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Generates sha256 hashes for all files in a repo folder and stores them in DB.
 * Deletes existing records for this repo_key first.
 * Returns ['ok' => bool, 'error' => string, 'files' => int, 'dirs' => int, 'size' => int, 'errors' => array].
 */
function integrity_generate_repo_hashes(PDO $pdo, string $application, string $branch, string $version, string $repoPath): array {
    @set_time_limit(300);

    $repoKey  = integrity_repo_key($application, $branch, $version);
    $realRepo = realpath($repoPath);
    $repoRoot = integrity_repo_root();

    if ($realRepo === false || !is_dir($realRepo)) {
        return ['ok' => false, 'error' => 'Repo path not found: ' . $repoPath, 'files' => 0, 'dirs' => 0, 'size' => 0, 'errors' => []];
    }
    if (!str_starts_with($realRepo, $repoRoot . '/')) {
        return ['ok' => false, 'error' => 'Repo path must be inside repo root.', 'files' => 0, 'dirs' => 0, 'size' => 0, 'errors' => []];
    }

    try {
        $pdo->prepare("DELETE FROM scanner_integrity_repo_files WHERE repo_key = :k")->execute([':k' => $repoKey]);

        $stmt = $pdo->prepare(
            'INSERT INTO scanner_integrity_repo_files
               (repo_key, application, branch, version, repo_path, relative_path, file_type, sha256, size_bytes, mtime, created_at)
             VALUES (:key, :app, :branch, :ver, :repo, :rel, :ft, :sha, :sz, :mt, NOW())'
        );

        // Scan all items without ucFolder filtering (repo is the reference)
        $items = [];
        _int_scan_tree($realRepo, '', [], $items, 50000);

        $files = 0; $dirs = 0; $totalSize = 0; $errors = [];

        foreach ($items as $item) {
            $fullPath = $realRepo . '/' . $item['rel'];

            if ($item['is_dir']) {
                $dirs++;
                $stmt->execute([
                    ':key' => $repoKey, ':app' => $application, ':branch' => $branch, ':ver' => $version,
                    ':repo' => $realRepo, ':rel' => $item['rel'],
                    ':ft' => 'directory', ':sha' => null, ':sz' => null, ':mt' => null,
                ]);
            } else {
                $sha256 = @hash_file('sha256', $fullPath);
                $size   = @filesize($fullPath);
                $mtime  = @filemtime($fullPath);
                if ($sha256 === false) {
                    $errors[] = $item['rel'];
                    continue;
                }
                $files++;
                $totalSize += (int) $size;
                $stmt->execute([
                    ':key' => $repoKey, ':app' => $application, ':branch' => $branch, ':ver' => $version,
                    ':repo' => $realRepo, ':rel' => $item['rel'],
                    ':ft' => 'file', ':sha' => $sha256, ':sz' => $size ?: null, ':mt' => $mtime ?: null,
                ]);
            }
        }

        return ['ok' => true, 'error' => '', 'files' => $files, 'dirs' => $dirs, 'size' => $totalSize, 'errors' => $errors];

    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'DB error: ' . $e->getMessage(), 'files' => 0, 'dirs' => 0, 'size' => 0, 'errors' => []];
    }
}

/**
 * Loads all repo index entries for a repo_key, keyed by relative_path.
 * Returns ['relative_path' => ['file_type', 'sha256', 'size_bytes'], ...].
 */
function integrity_load_repo_index(PDO $pdo, string $repoKey): array {
    try {
        $stmt = $pdo->prepare(
            'SELECT relative_path, file_type, sha256, size_bytes
               FROM scanner_integrity_repo_files
              WHERE repo_key = :k'
        );
        $stmt->execute([':k' => $repoKey]);
        $index = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $index[$row['relative_path']] = $row;
        }
        return $index;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Clears run results from DB.
 * Modes:
 *   'run'  — deletes scanner_integrity_results and scanner_integrity_runs for a specific run_id
 *   'dest' — deletes all runs+results for a destination_path
 *   'all'  — deletes all runs+results
 * Does NOT delete: repo hashes, integrity ignores, scanner findings, quarantine.
 */
function integrity_clear_results(PDO $pdo, string $mode, int $runId = 0, string $destPath = ''): bool {
    try {
        if ($mode === 'run' && $runId > 0) {
            $pdo->prepare("DELETE FROM scanner_integrity_results WHERE run_id = :id")->execute([':id' => $runId]);
            $pdo->prepare("DELETE FROM scanner_integrity_runs    WHERE id = :id")    ->execute([':id' => $runId]);
        } elseif ($mode === 'dest' && $destPath !== '') {
            $pdo->prepare("DELETE r FROM scanner_integrity_results r
                           INNER JOIN scanner_integrity_runs ru ON ru.id = r.run_id
                           WHERE ru.destination_path = :d")->execute([':d' => $destPath]);
            $pdo->prepare("DELETE FROM scanner_integrity_runs WHERE destination_path = :d")->execute([':d' => $destPath]);
        } elseif ($mode === 'all') {
            $pdo->exec("DELETE FROM scanner_integrity_results");
            $pdo->exec("DELETE FROM scanner_integrity_runs");
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Hash-based integrity check ─────────────────────────────────────────────────

/**
 * Returns true if a relative path has any parent directory that is a user-content folder.
 * Used to skip content inside ucFolders when checking repo items against dest.
 */
function _int_under_uc_folder(string $rel, array $ucFolders): bool {
    foreach ($ucFolders as $ucf) {
        if (str_starts_with($rel, rtrim($ucf, '/') . '/')) return true;
    }
    return false;
}

/**
 * Full hash-based integrity check.
 * Loads repo hash index from DB, scans destination, compares:
 *   MISSING_FILE / MISSING_DIRECTORY — in repo, absent in dest
 *   MODIFIED_FILE                    — in both, sha256 differs
 *   EXTRA_FILE / EXTRA_DIRECTORY     — in dest, absent in repo
 *   USER_CONTENT_FOLDER              — ucFolder present in dest
 *
 * @param string   $repoKey      e.g. 'joomla/v4x/4.4.14'
 * @param string   $originPath   Filesystem path of the repo (for Replace operations)
 * @param string   $destPath     Live installation path (must be under /home)
 * @param string   $software     Detected software for user-content folder list
 * @param string[] $ignoredPaths Combined pre-run exclusions + post-run DB ignores
 *
 * @return array {ok, error, findings, origin, dest, truncated, counts, summary, mode}
 */
function integrity_hash_check(
    PDO    $pdo,
    string $repoKey,
    string $originPath,
    string $destPath,
    string $software,
    array  $ignoredPaths
): array {
    @set_time_limit(600);

    $empty = ['findings' => [], 'origin' => '', 'dest' => '', 'truncated' => false, 'counts' => [], 'summary' => [], 'mode' => 'hash'];

    $realDest = realpath($destPath);
    if ($realDest === false || !is_dir($realDest)) {
        return array_merge($empty, ['ok' => false, 'error' => 'Destination path not accessible: ' . $destPath]);
    }
    if ($realDest !== '/home' && !str_starts_with($realDest, '/home/')) {
        return array_merge($empty, ['ok' => false, 'error' => 'Destination must be inside /home.']);
    }

    // Load repo index
    $repoIndex = integrity_load_repo_index($pdo, $repoKey);
    if (empty($repoIndex)) {
        return array_merge($empty, ['ok' => false, 'error' => 'No hash database found for this repository. Regenerate hashes first.']);
    }

    $ucFolders = integrity_user_content_folders($software);
    $maxItems  = 20000;
    $truncated = false;

    // Scan destination with ucFolders stop
    $destItems = [];
    _int_scan_tree($realDest, '', $ucFolders, $destItems, $maxItems);
    if (count($destItems) >= $maxItems) $truncated = true;
    $destSet = array_column($destItems, null, 'rel');

    $findings = [];
    $counts   = ['suspicious' => 0, 'warning' => 0, 'info' => 0];
    $summary  = ['checked_files' => 0, 'ok_files' => 0, 'modified_files' => 0,
                 'missing_files' => 0, 'missing_dirs' => 0, 'extra_files' => 0, 'extra_dirs' => 0];

    // ── Repo → Dest comparison ─────────────────────────────────────────────────
    foreach ($repoIndex as $rel => $item) {
        if (integrity_path_is_ignored($rel, $ignoredPaths)) continue;
        // Skip files inside user-content dirs (dest scan stopped there; would falsely appear MISSING)
        if (_int_under_uc_folder($rel, $ucFolders)) continue;

        $isUcFolder = ($item['file_type'] === 'directory') && in_array($rel, $ucFolders, true);

        if (!isset($destSet[$rel])) {
            if ($isUcFolder) {
                // User-content dir expected but absent — treat as MISSING_DIRECTORY
                $findings[] = ['type' => 'MISSING_DIRECTORY', 'rel' => $rel,
                               'fullpath' => $realDest . '/' . $rel, 'severity' => 'warning',
                               'repo_sha256' => null, 'destination_sha256' => null,
                               'repo_size' => null, 'destination_size' => null];
                $counts['warning']++;
                $summary['missing_dirs']++;
            } else {
                $type = ($item['file_type'] === 'directory') ? 'MISSING_DIRECTORY' : 'MISSING_FILE';
                $findings[] = ['type' => $type, 'rel' => $rel,
                               'fullpath' => $realDest . '/' . $rel, 'severity' => 'warning',
                               'repo_sha256' => $item['sha256'], 'destination_sha256' => null,
                               'repo_size' => $item['size_bytes'], 'destination_size' => null];
                $counts['warning']++;
                if ($item['file_type'] === 'directory') $summary['missing_dirs']++;
                else $summary['missing_files']++;
            }
        } elseif ($isUcFolder) {
            // User-content dir present in dest → info finding
            $findings[] = ['type' => 'USER_CONTENT_FOLDER', 'rel' => $rel,
                           'fullpath' => $realDest . '/' . $rel, 'severity' => 'info',
                           'repo_sha256' => null, 'destination_sha256' => null,
                           'repo_size' => null, 'destination_size' => null];
            $counts['info']++;
        } elseif ($item['file_type'] === 'file') {
            $summary['checked_files']++;
            $destFull  = $realDest . '/' . $rel;
            $destSha   = @hash_file('sha256', $destFull);
            $destSize  = @filesize($destFull);

            if ($destSha !== false && $item['sha256'] !== null && $destSha !== $item['sha256']) {
                $summary['modified_files']++;
                $findings[] = ['type' => 'MODIFIED_FILE', 'rel' => $rel,
                               'fullpath' => $destFull, 'severity' => 'suspicious',
                               'repo_sha256' => $item['sha256'], 'destination_sha256' => $destSha,
                               'repo_size' => $item['size_bytes'], 'destination_size' => $destSize ?: null];
                $counts['suspicious']++;
            } else {
                $summary['ok_files']++;
            }
        }
        // directories other than ucFolders: if present in dest → OK, no finding
    }

    // ── Dest → Repo: find EXTRA items ─────────────────────────────────────────
    foreach ($destItems as $item) {
        $rel = $item['rel'];
        if (integrity_path_is_ignored($rel, $ignoredPaths)) continue;
        if ($item['is_user_content']) continue;
        if (isset($repoIndex[$rel])) continue;

        $type     = $item['is_dir'] ? 'EXTRA_DIRECTORY' : 'EXTRA_FILE';
        $depth    = substr_count($rel, '/');
        $severity = ($depth === 0) ? 'suspicious' : 'warning';

        $destSha  = (!$item['is_dir']) ? (@hash_file('sha256', $realDest . '/' . $rel) ?: null) : null;
        $destSize = (!$item['is_dir']) ? (@filesize($realDest . '/' . $rel) ?: null) : null;

        $findings[] = ['type' => $type, 'rel' => $rel,
                       'fullpath' => $realDest . '/' . $rel, 'severity' => $severity,
                       'repo_sha256' => null, 'destination_sha256' => $destSha,
                       'repo_size' => null, 'destination_size' => $destSize];
        $counts[$severity]++;
        if ($item['is_dir']) $summary['extra_dirs']++;
        else $summary['extra_files']++;
    }

    $order = ['suspicious' => 0, 'warning' => 1, 'info' => 2];
    usort($findings, static fn($a, $b) =>
        ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9)
        ?: strcmp($a['rel'], $b['rel'])
    );

    return [
        'ok'        => true,
        'error'     => '',
        'findings'  => $findings,
        'origin'    => $originPath,
        'dest'      => $realDest,
        'truncated' => $truncated,
        'counts'    => $counts,
        'summary'   => $summary,
        'mode'      => 'hash',
    ];
}

// ── Log infrastructure ─────────────────────────────────────────────────────────

function integrity_logs_root(): string {
    return integrity_storage_base() . '/logs';
}

function integrity_run_log_path(int $runId): string {
    return integrity_logs_root() . '/runs/run_' . $runId . '.log';
}

function integrity_hash_log_path(string $jobId): string {
    return integrity_logs_root() . '/hash/hash_job_' . preg_replace('/[^a-z0-9_-]/i', '_', $jobId) . '.log';
}

function integrity_action_log_path(int $actionId): string {
    return integrity_logs_root() . '/actions/action_' . $actionId . '.log';
}

/**
 * Validates that a log path is inside the integrity logs root and has no traversal.
 * Returns the realpath on success, or null on failure.
 */
function integrity_log_safe_path(string $path): ?string {
    // Must not contain null bytes
    if (str_contains($path, "\0")) return null;

    // Resolve as much as possible; parent dirs may not exist yet
    $logsRoot = integrity_logs_root();
    // Normalize without realpath (file may not exist)
    $normalised = $path;
    if (str_contains($path, '..')) return null;
    if (!str_starts_with($path, $logsRoot . '/')) return null;

    // If file exists, use realpath for extra safety
    if (file_exists($path)) {
        $real = realpath($path);
        if ($real === false) return null;
        $realLogsRoot = realpath($logsRoot);
        if ($realLogsRoot === false) return null;
        if (!str_starts_with($real, $realLogsRoot . '/')) return null;
        return $real;
    }

    return $path;
}

/**
 * Appends a line to a log file, creating the directory if needed.
 */
function integrity_log_write(string $path, string $line): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Loads all results for a run, ordered by severity then relative_path.
 */
function integrity_load_run_results(PDO $pdo, int $runId): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM scanner_integrity_results
              WHERE run_id = :rid
              ORDER BY FIELD(severity,'suspicious','warning','info'), type, relative_path"
        );
        $stmt->execute([':rid' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Writes a complete run log file including grouped per-result detail.
 */
function integrity_write_run_log(int $runId, array $data): void {
    $path      = integrity_run_log_path($runId);
    $results   = $data['results']   ?? [];
    $duration  = isset($data['duration_sec']) ? round((float)$data['duration_sec'], 2) . 's' : '—';
    $excls     = $data['exclusions'] ?? [];

    $lines = [
        '8Core Integrity — Run Log',
        str_repeat('=', 60),
        'Run ID    : ' . $runId,
        'Log file  : ' . $path,
        'Started   : ' . ($data['started_at']  ?? date('Y-m-d H:i:s')),
        'Finished  : ' . ($data['finished_at'] ?? date('Y-m-d H:i:s')),
        'Duration  : ' . $duration,
        'Mode      : ' . ($data['mode']     ?? 'structural'),
        'Origin    : ' . ($data['origin']   ?? ''),
        'Dest      : ' . ($data['dest']     ?? ''),
        'Software  : ' . ($data['software'] ?? ''),
        '',
    ];

    // Exclusions
    $lines[] = 'Exclusions (' . count($excls) . '):';
    if (empty($excls)) {
        $lines[] = '  (none)';
    } else {
        foreach ($excls as $excl) {
            $lines[] = '  ' . $excl;
        }
    }
    $lines[] = '';

    // Counts
    $lines[] = 'Counts:';
    foreach ($data['counts'] ?? [] as $k => $v) {
        $lines[] = '  ' . str_pad($k, 18) . ': ' . $v;
    }
    $lines[] = '';

    // Summary
    if (!empty($data['summary'])) {
        $lines[] = 'Summary:';
        foreach ($data['summary'] as $k => $v) {
            $lines[] = '  ' . str_pad($k, 22) . ': ' . $v;
        }
        $lines[] = '';
    }

    // DB result count
    $lines[] = 'DB results inserted: ' . count($results);
    $lines[] = '';

    // Errors
    if (!empty($data['errors'])) {
        $lines[] = 'Errors:';
        foreach ((array) $data['errors'] as $e) {
            $lines[] = '  [ERROR] ' . $e;
        }
        $lines[] = '';
    }

    // Per-result detail grouped by type category
    if (!empty($results)) {
        $groups = [
            'suspicious'          => [],
            'MODIFIED_FILE'       => [],
            'MISSING_FILE'        => [],
            'MISSING_DIRECTORY'   => [],
            'EXTRA_FILE'          => [],
            'EXTRA_DIRECTORY'     => [],
            'USER_CONTENT_FOLDER' => [],
            'info'                => [],
        ];

        foreach ($results as $r) {
            $t = $r['type'] ?? '';
            $s = $r['severity'] ?? 'info';
            if ($s === 'suspicious') {
                $groups['suspicious'][] = $r;
            } elseif (isset($groups[$t])) {
                $groups[$t][] = $r;
            } else {
                $groups['info'][] = $r;
            }
        }

        $groupLabels = [
            'suspicious'          => 'SUSPICIOUS',
            'MODIFIED_FILE'       => 'MODIFIED FILES',
            'MISSING_FILE'        => 'MISSING FILES',
            'MISSING_DIRECTORY'   => 'MISSING DIRECTORIES',
            'EXTRA_FILE'          => 'EXTRA FILES',
            'EXTRA_DIRECTORY'     => 'EXTRA DIRECTORIES',
            'USER_CONTENT_FOLDER' => 'USER CONTENT FOLDERS',
            'info'                => 'INFO',
        ];

        $lines[] = str_repeat('-', 60);
        $lines[] = 'RESULTS BY GROUP';
        $lines[] = str_repeat('-', 60);

        foreach ($groups as $key => $rows) {
            if (empty($rows)) continue;
            $lines[] = '';
            $lines[] = '[' . ($groupLabels[$key] ?? strtoupper($key)) . '] — ' . count($rows) . ' item(s)';
            $lines[] = str_repeat('·', 40);
            foreach ($rows as $r) {
                $rid    = isset($r['id'])       ? '#' . $r['id']    : '#?';
                $sev    = strtoupper($r['severity']    ?? '');
                $type   = $r['type']            ?? '';
                $status = $r['status']          ?? 'new';
                $rel    = $r['relative_path']   ?? '';
                $full   = $r['full_path']       ?? '';
                $rsha   = $r['repo_sha256']         ?? null;
                $dsha   = $r['destination_sha256']  ?? null;
                $note   = $r['note']            ?? '';

                $lines[] = $rid . ' ' . $sev . ' ' . $status . '  ' . $rel;
                if ($type !== ($key === 'suspicious' ? '' : $key)) {
                    $lines[] = '  type      : ' . $type;
                }
                if ($full !== '') {
                    $lines[] = '  full      : ' . $full;
                }
                if ($rsha !== null) {
                    $lines[] = '  repo_sha  : ' . $rsha;
                }
                if ($dsha !== null) {
                    $lines[] = '  dest_sha  : ' . $dsha;
                }
                if ($note !== '' && !preg_match('/^action_id:\d+$/', $note)) {
                    $lines[] = '  note      : ' . $note;
                }
                $lines[] = '';
            }
        }
    }

    $lines[] = str_repeat('=', 60);
    $lines[] = 'END OF LOG';

    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, implode("\n", $lines) . "\n");
}

/**
 * Writes a hash job log file.
 */
function integrity_write_hash_log(string $jobId, array $data): void {
    $path  = integrity_hash_log_path($jobId);
    $lines = [
        '8Core Integrity — Hash Job Log',
        str_repeat('=', 60),
        'Job ID    : ' . $jobId,
        'Started   : ' . ($data['started_at'] ?? date('Y-m-d H:i:s')),
        'Finished  : ' . ($data['finished_at'] ?? date('Y-m-d H:i:s')),
        'App       : ' . ($data['app'] ?? ''),
        'Branch    : ' . ($data['branch'] ?? ''),
        'Version   : ' . ($data['version'] ?? ''),
        'Repo path : ' . ($data['repo_path'] ?? ''),
        'Files     : ' . ($data['files'] ?? 0),
        'Errors    : ' . ($data['errors'] ?? 0),
        'Status    : ' . ($data['status'] ?? 'unknown'),
    ];
    if (!empty($data['error_detail'])) {
        $lines[] = 'Error     : ' . $data['error_detail'];
    }
    $lines[] = '';
    $lines[] = str_repeat('-', 60);

    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, implode("\n", $lines) . "\n");
}

// ── File preview ───────────────────────────────────────────────────────────────

const INTEGRITY_PREVIEW_MAX_BYTES = 204800; // 200 KB

/**
 * Returns a safe preview of a file for display in the UI.
 * Result: ['ok' => bool, 'content' => string, 'truncated' => bool, 'binary' => bool, 'size' => int, 'error' => string]
 */
function integrity_preview_file(string $fullPath, string $allowedRoot1, string $allowedRoot2 = ''): array {
    $empty = ['ok' => false, 'content' => '', 'truncated' => false, 'binary' => false, 'size' => 0, 'error' => ''];

    if ($fullPath === '' || str_contains($fullPath, "\0") || str_contains($fullPath, '..')) {
        return array_merge($empty, ['error' => 'Invalid path.']);
    }

    $real = realpath($fullPath);
    if ($real === false) {
        return array_merge($empty, ['error' => 'File not found: ' . $fullPath]);
    }

    // Must be under one of the allowed roots
    $inRoot1 = $allowedRoot1 !== '' && str_starts_with($real, rtrim($allowedRoot1, '/') . '/');
    $inRoot2 = $allowedRoot2 !== '' && str_starts_with($real, rtrim($allowedRoot2, '/') . '/');
    if (!$inRoot1 && !$inRoot2) {
        return array_merge($empty, ['error' => 'Path is outside allowed roots.']);
    }

    if (is_dir($real)) {
        return array_merge($empty, ['error' => 'Path is a directory, not a file.']);
    }

    if (!is_readable($real)) {
        return array_merge($empty, ['error' => 'File is not readable.']);
    }

    $size = filesize($real);
    if ($size === false) $size = 0;

    // Read up to preview max
    $fh = @fopen($real, 'rb');
    if ($fh === false) {
        return array_merge($empty, ['error' => 'Cannot open file.']);
    }
    $raw = fread($fh, INTEGRITY_PREVIEW_MAX_BYTES + 1);
    fclose($fh);

    $truncated = strlen($raw) > INTEGRITY_PREVIEW_MAX_BYTES;
    if ($truncated) $raw = substr($raw, 0, INTEGRITY_PREVIEW_MAX_BYTES);

    // Binary detection: null bytes in first 8 KB
    $sample = substr($raw, 0, 8192);
    $binary = str_contains($sample, "\0");

    if ($binary) {
        return ['ok' => true, 'content' => '', 'truncated' => false, 'binary' => true, 'size' => $size, 'error' => ''];
    }

    return ['ok' => true, 'content' => $raw, 'truncated' => $truncated, 'binary' => false, 'size' => $size, 'error' => ''];
}

