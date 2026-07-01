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
