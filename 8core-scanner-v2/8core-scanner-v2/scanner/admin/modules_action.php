<?php
/**
 * 8Core Scanner v2.6.5 — Admin: POST handler za Module Manager
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

$action     = isset($_POST['action'])     ? trim($_POST['action'])     : '';
$module_key = isset($_POST['module_key']) ? trim($_POST['module_key']) : '';

if (!in_array($action, array('enable', 'disable'), true) || $module_key === '') {
    $_SESSION['modules_flash']      = 'Neispravan zahtjev.';
    $_SESSION['modules_flash_type'] = 'error';
    header('Location: modules.php');
    exit;
}

if (!scanner_modules_table_exists($pdo)) {
    $_SESSION['modules_flash']      = 'Tablica scanner_modules ne postoji. Primijeni migracije.';
    $_SESSION['modules_flash_type'] = 'error';
    header('Location: modules.php');
    exit;
}

$mod = scanner_module_get($pdo, $module_key);
if (!$mod) {
    $_SESSION['modules_flash']      = 'Modul "' . htmlspecialchars($module_key, ENT_QUOTES, 'UTF-8') . '" nije pronađen.';
    $_SESSION['modules_flash_type'] = 'error';
    header('Location: modules.php');
    exit;
}

$active = $action === 'enable' ? 1 : 0;
scanner_module_set_active($pdo, $module_key, $active);

$label = htmlspecialchars($mod['name'], ENT_QUOTES, 'UTF-8');
$_SESSION['modules_flash']      = 'Modul "' . $label . '" je ' . ($active ? 'omogućen' : 'onemogućen') . '.';
$_SESSION['modules_flash_type'] = 'ok';

header('Location: modules.php');
exit;
