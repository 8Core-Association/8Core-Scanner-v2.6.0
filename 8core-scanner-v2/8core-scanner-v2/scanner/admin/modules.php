<?php
/**
 * 8Core Scanner v2.6.5 — Admin: Module Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/modules.php';
require_admin();

$tableReady = scanner_modules_table_exists($pdo);
$modules    = $tableReady ? scanner_modules_all($pdo) : [];

$flash     = '';
$flashType = '';
if (!empty($_SESSION['modules_flash'])) {
    $flash     = $_SESSION['modules_flash'];
    $flashType = $_SESSION['modules_flash_type'] ?? 'ok';
    unset($_SESSION['modules_flash'], $_SESSION['modules_flash_type']);
}
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

<?php include __DIR__ . '/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Modules</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
    <div class="panel" style="border-color:<?= $flashType === 'error' ? 'var(--risk-critical)' : 'var(--risk-low)' ?>;margin-bottom:16px;">
      <p style="margin:0;color:<?= $flashType === 'error' ? 'var(--risk-critical)' : 'var(--text)' ?>;"><?= h($flash) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
    <div class="panel">
      <p style="margin:0;color:var(--text-muted);">Tablica <code>scanner_modules</code> ne postoji. Primijeni pending migracije u <a href="update.php">Admin &rarr; Update</a>.</p>
    </div>

    <?php else: ?>
    <div class="panel" style="padding:0;">
      <div class="table-wrap" style="margin:0;">
        <table>
          <thead>
            <tr>
              <th>Module</th>
              <th>Key</th>
              <th>Version</th>
              <th>Description</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($modules)): ?>
          <tr>
            <td colspan="6" style="color:var(--text-muted);text-align:center;">No modules installed yet.</td>
          </tr>
          <?php else: ?>
          <?php foreach ($modules as $mod): ?>
          <tr>
            <td><b><?= h($mod['name']) ?></b></td>
            <td><code style="font-size:12px;"><?= h($mod['module_key']) ?></code></td>
            <td><?= h($mod['version'] ?? '—') ?></td>
            <td style="color:var(--text-muted);font-size:13px;"><?= h($mod['description'] ?? '') ?></td>
            <td>
              <?php if ($mod['active']): ?>
                <span class="badge risk-low">Active</span>
              <?php else: ?>
                <span class="badge" style="background:var(--bg-alt);color:var(--text-muted);">Disabled</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" action="modules_action.php" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="module_key" value="<?= h($mod['module_key']) ?>">
                <?php if ($mod['active']): ?>
                  <input type="hidden" name="action" value="disable">
                  <button type="submit" class="btn btn-ghost" style="font-size:12px;">Disable</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="enable">
                  <button type="submit" class="btn btn-primary" style="font-size:12px;">Enable</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</div>
</body>
</html>
