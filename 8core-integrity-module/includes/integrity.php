<?php
/**
 * 8Core Integrity — helper functions
 */

// ── Path / name validation ─────────────────────────────────────────────────────

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
 * Returns array of ignored relative paths for a specific origin+destination pair.
 */
function integrity_ignores_for(PDO $pdo, string $originPath, string $destPath): array {
    $stmt = $pdo->prepare(
        'SELECT ignored_path FROM scanner_integrity_ignores
          WHERE origin_path = :o AND destination_path = :d'
    );
    $stmt->execute([':o' => $originPath, ':d' => $destPath]);
    return array_column($stmt->fetchAll(), 'ignored_path');
}

/**
 * Adds an integrity ignore entry. Silently deduplicates.
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
    $ignoredSet = array_flip($ignoredPaths);
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
        if (isset($ignoredSet[$rel])) continue;
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
        if (isset($ignoredSet[$rel])) continue;
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
    ];
}

