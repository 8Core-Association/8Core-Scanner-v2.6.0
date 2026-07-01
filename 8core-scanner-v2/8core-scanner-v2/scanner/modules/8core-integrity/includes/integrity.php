<?php
/**
 * 8Core Integrity — helper functions
 */

function integrity_repo_root(): string {
    return '/home/8core_integrity/repo';
}

/** Validation regex for any folder/version name. */
function integrity_valid_name(string $name): bool {
    return (bool) preg_match('/^[a-z0-9_-]+$/', $name);
}

/**
 * Built-in default app groups.
 * Each: ['label' => string, 'key' => string, 'versions' => string[]]
 */
function integrity_default_groups(): array {
    return [
        ['label' => 'Joomla',     'key' => 'joomla',     'versions' => ['v3x', 'v4x', 'v5x']],
        ['label' => 'WordPress',  'key' => 'wordpress',  'versions' => ['v6x', 'v7x']],
        ['label' => 'WHMCS',      'key' => 'whmcs',      'versions' => []],
        ['label' => 'PrestaShop', 'key' => 'prestashop', 'versions' => []],
        ['label' => 'Custom',     'key' => 'custom',     'versions' => []],
    ];
}

/**
 * Returns keys of default groups (used to distinguish built-in from user-added).
 */
function integrity_default_keys(): array {
    return array_column(integrity_default_groups(), 'key');
}

/**
 * Flat list of all directories the default tree requires.
 */
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

/**
 * Reads the repo root on disk and returns all first-level application dirs
 * that are NOT part of the built-in default groups.
 * Returns array of strings (folder names), sorted.
 */
function integrity_extra_apps(): array {
    $root = integrity_repo_root();
    if (!is_dir($root)) return [];
    $defaults = integrity_default_keys();
    $names = [];
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($root . '/' . $entry)) continue;
        if (in_array($entry, $defaults, true)) continue;
        $names[] = $entry;
    }
    sort($names);
    return $names;
}

/**
 * Returns sub-directories inside a given root-level app dir.
 * Returns array of strings, sorted.
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
 * Creates the default repository directory tree.
 * Returns array of ['path' => string, 'ok' => bool, 'note' => string].
 */
function integrity_ensure_repo_structure(): array {
    $results = [];
    foreach (integrity_default_dirs() as $dir) {
        if (is_dir($dir)) {
            $results[] = ['path' => $dir, 'ok' => true, 'note' => 'already exists'];
            continue;
        }
        $created   = @mkdir($dir, 0755, true);
        $results[] = ['path' => $dir, 'ok' => $created, 'note' => $created ? 'created' : 'failed'];
    }
    return $results;
}

/**
 * Creates a new root-level application folder in the repo.
 * Returns ['ok' => bool, 'exists' => bool, 'path' => string, 'note' => string].
 */
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

/**
 * Creates a version sub-folder inside an existing root-level application.
 * Returns ['ok' => bool, 'exists' => bool, 'path' => string, 'note' => string].
 */
function integrity_create_version(string $app, string $version): array {
    if (!integrity_valid_name($app) || !integrity_valid_name($version)) {
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
