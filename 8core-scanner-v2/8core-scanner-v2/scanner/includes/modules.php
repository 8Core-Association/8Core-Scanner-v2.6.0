<?php
/**
 * 8Core Scanner — Module helper functions.
 */

function scanner_modules_table_exists(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM scanner_modules LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function scanner_modules_all(PDO $pdo): array {
    return $pdo->query(
        "SELECT * FROM scanner_modules ORDER BY name ASC"
    )->fetchAll();
}

function scanner_module_get(PDO $pdo, string $module_key): array|false {
    $st = $pdo->prepare("SELECT * FROM scanner_modules WHERE module_key = ?");
    $st->execute([$module_key]);
    return $st->fetch();
}

function scanner_module_install(PDO $pdo, string $module_key, string $name, ?string $description, ?string $version): void {
    $st = $pdo->prepare("
        INSERT INTO scanner_modules (module_key, name, description, version, active, installed_at, updated_at)
        VALUES (?, ?, ?, ?, 0, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            name         = VALUES(name),
            description  = VALUES(description),
            version      = VALUES(version),
            updated_at   = NOW()
    ");
    $st->execute([$module_key, $name, $description, $version]);
}

function scanner_module_set_active(PDO $pdo, string $module_key, int $active): void {
    $st = $pdo->prepare("UPDATE scanner_modules SET active = ?, updated_at = NOW() WHERE module_key = ?");
    $st->execute([$active ? 1 : 0, $module_key]);
}

function scanner_module_is_active(PDO $pdo, string $module_key): bool {
    $st = $pdo->prepare("SELECT active FROM scanner_modules WHERE module_key = ?");
    $st->execute([$module_key]);
    $row = $st->fetch();
    return $row && (int)$row['active'] === 1;
}

/**
 * Load a module manifest fresh — bypasses PHP's include cache.
 * Regular @include on a path that was already include'd in the same request
 * returns 1 (the cached "already included" result) rather than the array.
 * This function always reads the file from disk and evaluates it cleanly.
 *
 * Returns the manifest array, or null on failure.
 */
function scanner_load_manifest(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $code = file_get_contents($path);
    if ($code === false) return null;
    // Strip opening <?php tag so eval() can execute the return statement.
    $code = preg_replace('/^\s*<\?php\s*/i', '', $code, 1);
    try {
        $result = eval($code);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($result)) return null;
    $key = isset($result['module_key']) ? trim($result['module_key']) : '';
    if ($key === '' || !preg_match('/^[a-z0-9\-]+$/', $key)) return null;
    if (empty($result['name'])) return null;
    return $result;
}

/**
 * Normalise admin_menu from a manifest.
 * Supports both a single item ['label'=>,'url'=>] and a list of items.
 * Returns a flat list of ['label'=>,'url'=>] arrays, or empty array.
 */
function scanner_manifest_menu_items(array $manifest): array {
    if (empty($manifest['admin_menu'])) return [];
    $menu = $manifest['admin_menu'];
    // Single item: ['label'=>,'url'=>]
    if (isset($menu['label']) && isset($menu['url'])) {
        return [['label' => $menu['label'], 'url' => $menu['url']]];
    }
    // List of items
    $items = [];
    foreach ($menu as $item) {
        if (!empty($item['label']) && !empty($item['url'])) {
            $items[] = ['label' => $item['label'], 'url' => $item['url']];
        }
    }
    return $items;
}

