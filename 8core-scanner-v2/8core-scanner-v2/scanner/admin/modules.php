<?php
/**
 * 8Core Scanner v2.7.0 — Admin: Module Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/modules.php';
require_admin();

$tableReady = scanner_modules_table_exists($pdo);

// ── Flash ──────────────────────────────────────────────────────────────────────
$flash     = '';
$flashType = '';
if (!empty($_SESSION['modules_flash'])) {
    $flash     = $_SESSION['modules_flash'];
    $flashType = $_SESSION['modules_flash_type'] ?? 'ok';
    unset($_SESSION['modules_flash'], $_SESSION['modules_flash_type']);
}

// ── Discover: fajlovi modula u modules/ ───────────────────────────────────────
$modulesDir        = __DIR__ . '/../modules/';
$discoveredModules = [];

if (is_dir($modulesDir)) {
    foreach (glob($modulesDir . '*/module.php') ?: [] as $manifestPath) {
        $manifest = scanner_load_manifest($manifestPath);
        if (!$manifest) continue;
        $key = $manifest['module_key'];
        $discoveredModules[$key] = [
            'module_key'    => $key,
            'name'          => $manifest['name'],
            'version'       => $manifest['version']     ?? null,
            'description'   => $manifest['description'] ?? null,
            'manifest_path' => $manifestPath,
        ];
    }
}

// ── Installed modules ─────────────────────────────────────────────────────────
$installedModules = $tableReady ? scanner_modules_all($pdo) : [];
$installedKeys    = array_column($installedModules, null, 'module_key');

// ── Available (discovered but not installed) ──────────────────────────────────
$availableModules = [];
foreach ($discoveredModules as $key => $info) {
    if (!isset($installedKeys[$key])) {
        $availableModules[] = $info;
    }
}
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Module Manager</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.mod-section { background:var(--surface); border:1px solid var(--border); border-radius:10px; margin-bottom:18px; }
.mod-section-header { padding:16px 20px 0; display:flex; align-items:center; gap:10px; }
.mod-section-header h3 { margin:0; font-size:13px; font-weight:700; color:var(--text); }
.mod-section-header .badge-count { background:var(--accent,#2563eb); color:#fff; border-radius:999px; padding:1px 8px; font-size:11px; }
.mod-divider { border:none; border-top:1px solid var(--border); margin:12px 0 0; }
.mod-empty { padding:18px 20px; color:var(--text-muted); font-size:13px; }
.mod-table { width:100%; border-collapse:collapse; font-size:13px; }
.mod-table th { text-align:left; padding:8px 16px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--border); }
.mod-table td { padding:10px 16px; border-bottom:1px solid var(--bg); vertical-align:middle; }
.mod-table tr:last-child td { border-bottom:none; }
.mod-table code { font-size:11px; background:var(--surface2); color:var(--text); border:1px solid var(--border); padding:2px 6px; border-radius:4px; font-family:var(--font-mono,monospace); }
.mod-desc { color:var(--text-muted); font-size:12px; }
.upload-form { padding:16px 20px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.upload-hint { padding:0 20px 16px; font-size:12px; color:var(--text-muted); }
.flash-ok  { border-color:#22c55e; }
.flash-err { border-color:#ef4444; }
.dbg-row td { font-size:11px; color:#334155; background:#f1f5f9; padding:6px 16px; border-bottom:1px solid var(--border); }
.dbg-row code { background:#e2e8f0; color:#0f172a; border:none; font-size:11px; padding:1px 5px; border-radius:3px; }
.dbg-ok  { color:#16a34a; font-weight:700; }
.dbg-err { color:#dc2626; font-weight:700; }
</style>
</head>
<body>
<div class="layout">

<?php include __DIR__ . '/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Module Manager</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
    <div class="mod-section <?= $flashType === 'error' ? 'flash-err' : 'flash-ok' ?>" style="padding:14px 20px;margin-bottom:16px;">
      <p style="margin:0;font-size:13px;color:<?= $flashType === 'error' ? '#ef4444' : 'var(--text)' ?>;"><?= h($flash) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
    <!-- ── DB not ready ── -->
    <div class="mod-section" style="padding:18px 20px;">
      <p style="margin:0;color:var(--text-muted);font-size:13px;">
        Tablica <code>scanner_modules</code> ne postoji.
        Primijeni pending migracije u <a href="update.php">Admin &rarr; Update</a>.
      </p>
    </div>

    <?php else: ?>

    <!-- ── 1. Upload module ZIP ── -->
    <div class="mod-section">
      <div class="mod-section-header">
        <h3>Upload modula (ZIP)</h3>
      </div>
      <hr class="mod-divider">
      <form method="post" action="modules_action.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">
        <?= csrf_field() ?>
        <div class="upload-form">
          <input type="file" name="module_zip" accept=".zip" required style="font-size:13px;">
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
      <div class="upload-hint">
        ZIP mora sadržavati <code>module.php</code> manifest u korijenu s ključevima:
        <code>module_key</code>, <code>name</code>, <code>version</code>, <code>description</code>.
        <code>module_key</code> smije sadržavati samo mala slova, brojeve i crticu.
      </div>
    </div>

    <!-- ── 2. Discover local modules ── -->
    <div class="mod-section">
      <div class="mod-section-header">
        <h3>Discover lokalnih modula</h3>
      </div>
      <hr class="mod-divider">
      <div style="padding:12px 20px;">
        <form method="post" action="modules_action.php">
          <input type="hidden" name="action" value="discover">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost">Skeniraj modules/</button>
        </form>
        <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted);">
          Traži <code>modules/*/module.php</code> manifeste i prikazuje pronađene module.
        </p>
      </div>
    </div>

    <!-- ── 3. Available modules (discovered, not installed) ── -->
    <div class="mod-section">
      <div class="mod-section-header">
        <h3>Dostupni moduli</h3>
        <?php if (!empty($availableModules)): ?>
          <span class="badge-count"><?= count($availableModules) ?></span>
        <?php endif; ?>
      </div>
      <hr class="mod-divider">
      <?php if (empty($availableModules)): ?>
        <div class="mod-empty">
          Nema dostupnih modula za instalaciju.
          <?= is_dir($modulesDir) ? 'Direktorij <code>modules/</code> je prazan ili svi moduli su već instalirani.' : 'Direktorij <code>modules/</code> ne postoji.' ?>
        </div>
      <?php else: ?>
        <table class="mod-table">
          <thead>
            <tr>
              <th>Module</th><th>Key</th><th>Version</th><th>Description</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($availableModules as $mod): ?>
            <tr>
              <td><b><?= h($mod['name']) ?></b></td>
              <td><code><?= h($mod['module_key']) ?></code></td>
              <td><?= h($mod['version'] ?? '—') ?></td>
              <td class="mod-desc"><?= h($mod['description'] ?? '') ?></td>
              <td>
                <form method="post" action="modules_action.php" style="display:inline;">
                  <input type="hidden" name="action" value="install">
                  <input type="hidden" name="module_key" value="<?= h($mod['module_key']) ?>">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-primary" style="font-size:12px;">Install</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- ── 4. Installed modules ── -->
    <div class="mod-section">
      <div class="mod-section-header">
        <h3>Instalirani moduli</h3>
        <?php if (!empty($installedModules)): ?>
          <span class="badge-count"><?= count($installedModules) ?></span>
        <?php endif; ?>
      </div>
      <hr class="mod-divider">
      <?php if (empty($installedModules)): ?>
        <div class="mod-empty">Nema instaliranih modula.</div>
      <?php else: ?>
        <table class="mod-table">
          <thead>
            <tr>
              <th>Module</th>
              <th>Key</th>
              <th>DB Version</th>
              <th>Manifest Version</th>
              <th>Description</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($installedModules as $mod): ?>
            <?php
            $dbgManifestPath = __DIR__ . '/../modules/' . $mod['module_key'] . '/module.php';
            $dbgReadable     = is_file($dbgManifestPath) && is_readable($dbgManifestPath);
            $dbgManifest     = $dbgReadable ? scanner_load_manifest($dbgManifestPath) : null;
            $dbgManifestVer  = $dbgManifest['version'] ?? null;
            $dbgMenuItems    = $dbgManifest ? scanner_manifest_menu_items($dbgManifest) : [];
            $modOpenUrl      = (!empty($dbgMenuItems) && (int)$mod['active']) ? $dbgMenuItems[0]['url'] : null;
            ?>
            <tr>
              <td><b><?= h($mod['name']) ?></b></td>
              <td><code><?= h($mod['module_key']) ?></code></td>
              <td><?= h($mod['version'] ?? '—') ?></td>
              <td><?= $dbgManifestVer !== null ? h($dbgManifestVer) : '<span style="color:var(--text-muted)">—</span>' ?></td>
              <td class="mod-desc"><?= h($mod['description'] ?? '') ?></td>
              <td>
                <?php if ((int)$mod['active']): ?>
                  <span class="badge risk-low">Active</span>
                <?php else: ?>
                  <span class="badge" style="background:var(--bg-alt,var(--bg));color:var(--text-muted);">Disabled</span>
                <?php endif; ?>
              </td>
              <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <?php if ($modOpenUrl): ?>
                  <a href="<?= htmlspecialchars($modOpenUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost" style="font-size:12px;">Open</a>
                <?php endif; ?>
                <form method="post" action="modules_action.php" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="module_key" value="<?= h($mod['module_key']) ?>">
                  <?php if ((int)$mod['active']): ?>
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn btn-ghost" style="font-size:12px;">Disable</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="enable">
                    <button type="submit" class="btn btn-primary" style="font-size:12px;">Enable</button>
                  <?php endif; ?>
                </form>
                <form method="post" action="modules_action.php" style="display:inline;"
                      onsubmit="return confirm('Ukloniti modul <?= h(addslashes($mod['name'])) ?>? Ova radnja briše fajlove i DB zapis.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="module_key" value="<?= h($mod['module_key']) ?>">
                  <button type="submit" class="btn btn-ghost" style="font-size:12px;color:#dc2626;border-color:#fca5a5;">Ukloni</button>
                </form>
              </td>
            </tr>
            <!-- ── debug row ── -->
            <tr class="dbg-row">
              <td colspan="7">
                Manifest path: <code><?= h($dbgManifestPath) ?></code>
                &nbsp;|&nbsp;
                Readable: <span class="<?= $dbgReadable ? 'dbg-ok' : 'dbg-err' ?>"><?= $dbgReadable ? 'YES' : 'NO' ?></span>
                &nbsp;|&nbsp;
                Manifest loaded: <span class="<?= $dbgManifest ? 'dbg-ok' : 'dbg-err' ?>"><?= $dbgManifest ? 'YES' : 'NO' ?></span>
                &nbsp;|&nbsp;
                Admin menu: <span class="<?= !empty($dbgMenuItems) ? 'dbg-ok' : 'dbg-err' ?>"><?= !empty($dbgMenuItems) ? 'YES (' . count($dbgMenuItems) . ' item)' : 'NO' ?></span>
                <?php if (!empty($dbgMenuItems)): ?>
                  &nbsp;|&nbsp; Open URL: <code><?= h($dbgMenuItems[0]['url']) ?></code>
                <?php endif; ?>
                <?php if ($dbgManifest && $dbgManifest['version'] !== ($mod['version'] ?? null)): ?>
                  &nbsp;|&nbsp;
                  <form method="post" action="modules_action.php" style="display:inline;">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="module_key" value="<?= h($mod['module_key']) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary" style="font-size:11px;padding:2px 8px;">Sync DB from manifest</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div>
</div>
</div>
</body>
</html>
