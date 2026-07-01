<?php
/**
 * 8Core Scanner v2.6.6 — Admin: POST handler za Module Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/modules.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: modules.php');
    exit;
}

csrf_verify();

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// ── Helpers ────────────────────────────────────────────────────────────────────

function mod_flash(string $msg, string $type = 'ok'): void {
    $_SESSION['modules_flash']      = $msg;
    $_SESSION['modules_flash_type'] = $type;
}

function mod_redirect(): void {
    header('Location: modules.php');
    exit;
}

/**
 * Validates a module_key: lowercase letters, digits, hyphens only.
 */
function valid_module_key(string $key): bool {
    return $key !== '' && preg_match('/^[a-z0-9\-]+$/', $key) === 1;
}

/**
 * Load and validate a module.php manifest array.
 * Returns the array on success or null on failure.
 */
function load_manifest(string $path): ?array {
    if (!file_exists($path)) return null;
    $m = @include $path;
    if (!is_array($m)) return null;
    $key = isset($m['module_key']) ? trim($m['module_key']) : '';
    if (!valid_module_key($key))                         return null;
    if (empty($m['name']))                               return null;
    $m['module_key'] = $key;
    return $m;
}

/**
 * Recursively removes a directory.
 */
function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

/**
 * Recursively copies a directory tree. Returns true on success.
 */
function copy_recursive(string $src, string $dst): bool {
    if (!is_dir($dst)) {
        if (!mkdir($dst, 0755, true)) return false;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $target = $dst . '/' . $iter->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true)) return false;
        } else {
            if (!copy($item->getRealPath(), $target)) return false;
        }
    }
    return true;
}

$modulesBaseDir = realpath(__DIR__ . '/..') . '/modules';

// ── ACTION: upload ─────────────────────────────────────────────────────────────
if ($action === 'upload') {
    $file = $_FILES['module_zip'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        mod_flash('Upload fajla nije uspio.', 'error');
        mod_redirect();
    }

    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        mod_flash('Dozvoljeni su samo ZIP fajlovi.', 'error');
        mod_redirect();
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        mod_flash('Ne mogu otvoriti ZIP fajl.', 'error');
        mod_redirect();
    }

    // Security: path traversal check + locate module.php
    $hasManifest   = false;
    $pathTraversal = false;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (strpos($entry, '..') !== false || strpos($entry, "\0") !== false || substr($entry, 0, 1) === '/') {
            $pathTraversal = true;
            break;
        }
        // module.php must be at root of ZIP (not nested deeper than one dir)
        $parts = explode('/', trim($entry, '/'));
        if (end($parts) === 'module.php' && count($parts) <= 2) {
            $hasManifest = true;
        }
    }

    $zip->close();

    if ($pathTraversal) {
        mod_flash('ZIP sadrži opasne putanje (path traversal). Odbijeno.', 'error');
        mod_redirect();
    }

    if (!$hasManifest) {
        mod_flash('ZIP ne sadrži module.php manifest. Odbijeno.', 'error');
        mod_redirect();
    }

    // Extract to temp dir
    $tmpDir = sys_get_temp_dir() . '/8core_module_' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0700, true);

    $zip2 = new ZipArchive();
    $zip2->open($file['tmp_name']);
    $zip2->extractTo($tmpDir);
    $zip2->close();

    // Find manifest — could be at root or inside one subdirectory
    $manifestPath = null;
    if (file_exists($tmpDir . '/module.php')) {
        $manifestPath = $tmpDir . '/module.php';
        $moduleRootInTmp = $tmpDir;
    } else {
        foreach (glob($tmpDir . '/*/module.php') ?: [] as $mp) {
            $manifestPath    = $mp;
            $moduleRootInTmp = dirname($mp);
            break;
        }
    }

    if (!$manifestPath) {
        rmdir_recursive($tmpDir);
        mod_flash('module.php manifest nije pronađen u raspakiranom ZIP-u.', 'error');
        mod_redirect();
    }

    $manifest = load_manifest($manifestPath);
    if (!$manifest) {
        rmdir_recursive($tmpDir);
        mod_flash('module.php manifest je neispravan ili sadrži nevažeći module_key.', 'error');
        mod_redirect();
    }

    $moduleKey = $manifest['module_key'];
    $destDir   = $modulesBaseDir . '/' . $moduleKey;

    // Security: destination must be inside modulesBaseDir
    if (!is_dir($modulesBaseDir)) {
        mkdir($modulesBaseDir, 0755, true);
    }
    $realDest = realpath($modulesBaseDir) . '/' . $moduleKey;
    if (strpos($realDest, realpath($modulesBaseDir)) !== 0) {
        rmdir_recursive($tmpDir);
        mod_flash('Neispravan odredišni direktorij.', 'error');
        mod_redirect();
    }

    // Copy module files to modules/<module_key>/
    // Use recursive copy instead of rename() — rename fails across filesystems (e.g. /tmp → web root).
    if (is_dir($destDir)) {
        rmdir_recursive($destDir);
    }
    $copied = copy_recursive($moduleRootInTmp, $destDir);
    rmdir_recursive($tmpDir);

    if (!$copied) {
        mod_flash('Greška kopiranja modula u modules/. Provjeri dozvole.', 'error');
        mod_redirect();
    }

    mod_flash('Modul "' . htmlspecialchars($manifest['name'], ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') . ') uploadoan u modules/. Možeš ga instalirati iz sekcije "Dostupni moduli".');
    mod_redirect();
}

// ── ACTION: discover ───────────────────────────────────────────────────────────
if ($action === 'discover') {
    $found = 0;
    if (is_dir($modulesBaseDir)) {
        foreach (glob($modulesBaseDir . '/*/module.php') ?: [] as $mp) {
            $m = load_manifest($mp);
            if ($m) $found++;
        }
    }
    mod_flash('Skeniranje završeno. Pronađeno ' . $found . ' valid modul(a) u modules/.');
    mod_redirect();
}

// ── ACTION: install ────────────────────────────────────────────────────────────
if ($action === 'install') {
    $moduleKey = isset($_POST['module_key']) ? trim($_POST['module_key']) : '';

    if (!valid_module_key($moduleKey)) {
        mod_flash('Neispravan module_key.', 'error');
        mod_redirect();
    }

    if (!scanner_modules_table_exists($pdo)) {
        mod_flash('Tablica scanner_modules ne postoji. Primijeni migracije.', 'error');
        mod_redirect();
    }

    $manifestPath = $modulesBaseDir . '/' . $moduleKey . '/module.php';
    $manifest     = load_manifest($manifestPath);

    if (!$manifest) {
        mod_flash('Manifest za modul "' . htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') . '" nije pronađen ili je neispravan.', 'error');
        mod_redirect();
    }

    scanner_module_install(
        $pdo,
        $manifest['module_key'],
        $manifest['name'],
        $manifest['description'] ?? null,
        $manifest['version'] ?? null
    );

    mod_flash('Modul "' . htmlspecialchars($manifest['name'], ENT_QUOTES, 'UTF-8') . '" instaliran.');
    mod_redirect();
}

// ── ACTION: enable / disable ───────────────────────────────────────────────────
if (in_array($action, ['enable', 'disable'], true)) {
    $moduleKey = isset($_POST['module_key']) ? trim($_POST['module_key']) : '';

    if (!valid_module_key($moduleKey)) {
        mod_flash('Neispravan module_key.', 'error');
        mod_redirect();
    }

    if (!scanner_modules_table_exists($pdo)) {
        mod_flash('Tablica scanner_modules ne postoji. Primijeni migracije.', 'error');
        mod_redirect();
    }

    $mod = scanner_module_get($pdo, $moduleKey);
    if (!$mod) {
        mod_flash('Modul "' . htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') . '" nije pronađen.', 'error');
        mod_redirect();
    }

    $active = $action === 'enable' ? 1 : 0;
    scanner_module_set_active($pdo, $moduleKey, $active);

    $label = htmlspecialchars($mod['name'], ENT_QUOTES, 'UTF-8');
    mod_flash('Modul "' . $label . '" je ' . ($active ? 'omogućen' : 'onemogućen') . '.');
    mod_redirect();
}

// ── Fallback ───────────────────────────────────────────────────────────────────
mod_flash('Nepoznata akcija.', 'error');
mod_redirect();
