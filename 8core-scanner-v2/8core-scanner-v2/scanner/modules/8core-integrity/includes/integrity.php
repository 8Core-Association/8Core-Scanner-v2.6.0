<?php
/**
 * 8Core Integrity — helper functions
 */

function integrity_repo_root(): string {
    return '/home/8core_integrity/repo';
}

/**
 * Returns the default grouped tree structure.
 * Each group: ['label' => string, 'key' => string, 'versions' => string[]]
 * Groups with no versions still get a parent directory created.
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
 * Returns a flat list of all directories that must exist for the default tree.
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
 * Returns existing custom sub-directories inside custom/.
 * Returns array of folder names (strings), sorted.
 */
function integrity_custom_dirs(): array {
    $customDir = integrity_repo_root() . '/custom';
    if (!is_dir($customDir)) {
        return [];
    }
    $names = [];
    foreach (scandir($customDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (is_dir($customDir . '/' . $entry)) {
            $names[] = $entry;
        }
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
 * Creates a single custom repository folder inside custom/.
 * Returns ['ok' => bool, 'exists' => bool, 'path' => string, 'note' => string].
 */
function integrity_create_custom_dir(string $name): array {
    if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
        return ['ok' => false, 'exists' => false, 'path' => '', 'note' => 'invalid name'];
    }
    $path = integrity_repo_root() . '/custom/' . $name;
    if (is_dir($path)) {
        return ['ok' => false, 'exists' => true, 'path' => $path, 'note' => 'already exists'];
    }
    $ok = @mkdir($path, 0755, true);
    return ['ok' => $ok, 'exists' => false, 'path' => $path, 'note' => $ok ? 'created' : 'failed'];
}
