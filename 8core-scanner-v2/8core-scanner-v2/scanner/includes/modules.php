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
