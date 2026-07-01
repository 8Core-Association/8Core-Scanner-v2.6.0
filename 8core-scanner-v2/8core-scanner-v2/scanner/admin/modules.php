<?php
/**
 * 8Core Scanner v2.6.4 — Admin: Modules
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/modules.php';
require_admin();

$tableReady = scanner_modules_table_exists($pdo);
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Modules</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Modules</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">
    <div class="panel">
      <?php if ($tableReady): ?>
        <p style="color:var(--text-muted);margin:0;">Module manager will be available in the next update.</p>
      <?php else: ?>
        <p style="color:var(--text-muted);margin:0;">Module manager will be available after database update. Please apply pending migrations in <a href="update.php">Admin &rarr; Update</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>
</body>
</html>
