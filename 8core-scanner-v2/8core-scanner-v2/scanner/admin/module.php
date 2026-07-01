<?php
/**
 * 8Core Scanner v2.6.8 — Admin: Module Router
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * URL: admin/module.php?module=<module_key>&page=<page>
 *
 * Loads: scanner/modules/<module_key>/admin/<page>.php
 * Guards: auth, module installed, module active.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/modules.php';
require_admin();

// ── Input validation ───────────────────────────────────────────────────────────
$moduleKey = isset($_GET['module']) ? trim($_GET['module']) : '';
$page      = isset($_GET['page'])   ? trim($_GET['page'])   : '';

if (!preg_match('/^[a-z0-9\-]+$/', $moduleKey)) {
    http_response_code(400);
    die(h('Invalid module key.'));
}

if (!preg_match('/^[a-z0-9_\-]+$/', $page)) {
    http_response_code(400);
    die(h('Invalid page name.'));
}

// ── Module must be installed ───────────────────────────────────────────────────
if (!scanner_modules_table_exists($pdo)) {
    http_response_code(404);
    die('Module system not available. Please apply pending migrations.');
}

$mod = scanner_module_get($pdo, $moduleKey);
if (!$mod) {
    http_response_code(404);
    die(h('Module "' . $moduleKey . '" is not installed.'));
}

// ── Module must be active ──────────────────────────────────────────────────────
if (!(int)$mod['active']) {
    // Render a minimal page rather than a bare die() so the layout is preserved.
    require __DIR__ . '/../includes/version.php';
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Module Disabled</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?= h($mod['name']) ?></div>
    <div class="topbar-meta"><a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a></div>
  </div>
  <div class="content">
    <div class="panel">
      <p style="margin:0;color:var(--text-muted);">
        Module <strong><?= h($mod['name']) ?></strong> is disabled.
        <a href="modules.php">Enable it in Module Manager.</a>
      </p>
    </div>
  </div>
</div>
</div>
</body>
</html>
<?php
    exit;
}

// ── Locate and include the module admin page ───────────────────────────────────
$adminPage = realpath(__DIR__ . '/../modules/' . $moduleKey . '/admin/' . $page . '.php');
$modulesBase = realpath(__DIR__ . '/../modules');

// Path traversal guard: resolved path must be inside modules/
if (!$adminPage || !$modulesBase || strpos($adminPage, $modulesBase . '/') !== 0) {
    require __DIR__ . '/../includes/version.php';
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Page Not Found</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?= h($mod['name']) ?></div>
    <div class="topbar-meta"><a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a></div>
  </div>
  <div class="content">
    <div class="panel">
      <p style="margin:0;color:var(--text-muted);">
        Module admin page <strong><?= h($page) ?></strong> not found.
        <a href="modules.php">Back to Module Manager.</a>
      </p>
    </div>
  </div>
</div>
</div>
</body>
</html>
<?php
    exit;
}

// All checks passed — hand off to the module page.
include $adminPage;
