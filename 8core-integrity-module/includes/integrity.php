<?php
/**
 * 8Core Integrity — helper funkcije
 */

function integrity_repo_root(): string {
    return '/home/8core_integrity/repo';
}

function integrity_get_default_tree(): array {
    $root = integrity_repo_root();
    return [
        $root . '/joomla/v3x',
        $root . '/joomla/v4x',
        $root . '/joomla/v5x',
        $root . '/wordpress/v6x',
        $root . '/wordpress/v7x',
        $root . '/whmcs',
        $root . '/prestashop',
    ];
}

/**
 * Creates the default repository directory tree.
 * Returns array of ['path' => string, 'ok' => bool, 'note' => string].
 */
function integrity_ensure_repo_structure(): array {
    $results = [];
    foreach (integrity_get_default_tree() as $dir) {
        if (is_dir($dir)) {
            $results[] = ['path' => $dir, 'ok' => true, 'note' => 'already exists'];
            continue;
        }
        $created = @mkdir($dir, 0755, true);
        $results[] = ['path' => $dir, 'ok' => $created, 'note' => $created ? 'created' : 'failed'];
    }
    return $results;
}
