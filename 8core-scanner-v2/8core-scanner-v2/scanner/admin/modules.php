<?php
/**
 * 8Core Scanner v2.6.3 — Admin: Modules
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require_admin();
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
      <p style="color:var(--text-muted);margin:0;">Module manager will be available after database update.</p>
    </div>
  </div>
</div>
</div>
</body>
</html>
