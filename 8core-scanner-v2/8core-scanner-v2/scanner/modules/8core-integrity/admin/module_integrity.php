<?php
/**
 * 8Core Integrity v0.1.1 — Admin: Integrity Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Included by scanner/admin/module.php router.
 * Auth, $pdo, h(), csrf_field() are already bootstrapped by the router.
 * This file provides its own full HTML page (html/head/body).
 */

require_once __DIR__ . '/../includes/integrity.php';

// ── Handle POST ────────────────────────────────────────────────────────────────
$_intMessages    = [];
$_intShowRootCmd = false;
$_intRootCmd     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($postAction === 'create_repo_structure') {
        $results   = integrity_ensure_repo_structure();
        $anyFailed = false;
        foreach ($results as $r) {
            $_intMessages[] = [
                'type' => $r['ok'] ? 'ok' : 'err',
                'text' => ($r['ok'] ? 'OK' : 'FAIL') . '  ' . $r['path'] . '  [' . $r['note'] . ']',
            ];
            if (!$r['ok']) $anyFailed = true;
        }
        if ($anyFailed) {
            $_intShowRootCmd = true;
            $root = integrity_repo_root();
            $dirs = implode(" \\\n         ", integrity_default_dirs());
            $_intRootCmd = "mkdir -p " . $dirs . "\nchown -R {webuser}:{webuser} {$root}";
        }
    }

    if ($postAction === 'add_custom_dir') {
        $name = strtolower(trim($_POST['folder_name'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  Invalid folder name "' . h($name) . '". Use only: a-z 0-9 _ -'];
        } else {
            $r = integrity_create_custom_dir($name);
            if ($r['exists']) {
                $_intMessages[] = ['type' => 'warn', 'text' => 'Custom repository "' . h($name) . '" already exists.'];
            } elseif ($r['ok']) {
                $_intMessages[] = ['type' => 'ok', 'text' => 'OK  ' . $r['path'] . '  [created]'];
            } else {
                $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  ' . $r['path'] . '  [' . $r['note'] . ']'];
                $_intShowRootCmd = true;
                $root = integrity_repo_root();
                $_intRootCmd = "mkdir -p {$root}/custom/" . $name
                    . "\nchown -R {webuser}:{webuser} {$root}/custom/" . $name;
            }
        }
    }
}

$_intRepoRoot   = integrity_repo_root();
$_intGroups     = integrity_default_groups();
$_intCustomDirs = integrity_custom_dirs();
$_intWebUser    = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
$_intRootCmd    = str_replace('{webuser}', $_intWebUser, $_intRootCmd ?? '');

require __DIR__ . '/../../../includes/version.php';
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Integrity</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.int-section { background:var(--surface); border:1px solid var(--border); border-radius:10px; margin-bottom:18px; }
.int-section-header { padding:16px 20px 0; display:flex; align-items:center; gap:10px; }
.int-section-header h3 { margin:0; font-size:13px; font-weight:700; color:var(--text); }
.int-divider { border:none; border-top:1px solid var(--border); margin:12px 0 0; }
.int-body { padding:16px 20px; }

/* ── Repo root path ── */
.int-repo-path { font-family:var(--font-mono,monospace); font-size:13px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:8px 12px; color:var(--text); display:inline-block; margin-bottom:16px; word-break:break-all; }

/* ── Grouped tree ── */
.int-tree-wrap { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:18px; }
.int-tree-group { background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:12px 14px; min-width:140px; }
.int-tree-group-label { font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
.int-tree-group ul { margin:0; padding:0 0 0 2px; list-style:none; }
.int-tree-group li { font-family:var(--font-mono,monospace); font-size:12px; color:var(--text-muted); padding:2px 0; display:flex; align-items:center; gap:5px; }
.int-tree-group li::before { content:''; display:inline-block; width:12px; height:1px; background:var(--border); flex-shrink:0; }
.int-tree-group-empty { font-size:12px; color:#94a3b8; font-style:italic; padding:2px 0; }

/* ── Disk status indicators ── */
.dir-exists { color:#16a34a; }
.dir-missing { color:#94a3b8; }

/* ── Messages ── */
.msg-list { list-style:none; margin:0 0 12px; padding:0; }
.msg-list li { font-family:var(--font-mono,monospace); font-size:12px; padding:3px 0; border-bottom:1px solid var(--border); }
.msg-list li:last-child { border-bottom:none; }
.msg-ok  { color:#16a34a; }
.msg-warn { color:#d97706; font-style:italic; }
.msg-err { color:#dc2626; }

/* ── Root cmd box ── */
.int-root-cmd { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px 16px; margin-top:12px; }
.int-root-cmd p { margin:0 0 8px; font-size:12px; color:#92400e; font-weight:600; }
.int-root-cmd pre { margin:0; font-family:var(--font-mono,monospace); font-size:12px; color:#166534; background:#dcfce7; border-radius:6px; padding:10px 12px; white-space:pre-wrap; word-break:break-all; line-height:1.6; }

/* ── Custom folder form ── */
.int-add-custom { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:4px; }
.int-add-custom-field { display:flex; flex-direction:column; gap:4px; }
.int-add-custom-field label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.int-add-custom-field input[type=text] { padding:8px 10px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--font-mono,monospace); font-size:13px; outline:none; width:220px; transition:border-color .13s; }
.int-add-custom-field input[type=text]::placeholder { color:#94a3b8; }
.int-add-custom-field input[type=text]:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.int-hint { font-size:11px; color:#94a3b8; margin-top:5px; }

/* ── Integrity check ── */
.int-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:14px; }
.int-field label { display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.int-field input[type=text] { width:100%; padding:8px 10px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--font-mono,monospace); font-size:13px; outline:none; box-sizing:border-box; transition:border-color .13s; }
.int-field input[type=text]::placeholder { color:#94a3b8; }
.int-field input[type=text]:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.int-placeholder { background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:14px 16px; font-size:13px; color:var(--text-muted); margin-top:12px; }
@media (max-width:640px) { .int-form-grid { grid-template-columns:1fr; } .int-tree-wrap { flex-direction:column; } }
</style>
</head>
<body>
<div class="layout">

<?php include __DIR__ . '/../../../admin/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">8Core Integrity</div>
    <div class="topbar-meta">
      <span style="font-size:12px;color:var(--text-muted);">v0.1.1</span>
      &nbsp;&nbsp;<a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if (!empty($_intMessages)): ?>
    <div class="int-section" style="padding:14px 20px;margin-bottom:16px;">
      <ul class="msg-list" style="margin:0;">
        <?php foreach ($_intMessages as $m): ?>
          <li class="msg-<?= h($m['type']) ?>"><?= h($m['text']) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($_intShowRootCmd && $_intRootCmd): ?>
      <div class="int-root-cmd">
        <p>PHP nema dozvolu za kreiranje direktorija. Pokreni kao root:</p>
        <pre><?= h($_intRootCmd) ?></pre>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Repository Manager ── -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Repository Manager</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">

        <div style="margin-bottom:6px;font-size:12px;color:var(--text-muted);">Repo root</div>
        <div class="int-repo-path"><?= h($_intRepoRoot) ?></div>

        <div style="margin-bottom:10px;font-size:12px;color:var(--text-muted);">Repository structure</div>
        <div class="int-tree-wrap">
          <?php foreach ($_intGroups as $group):
            $isCustom = ($group['key'] === 'custom');
            $groupDir = $_intRepoRoot . '/' . $group['key'];
            $groupExists = is_dir($groupDir);
          ?>
          <div class="int-tree-group">
            <div class="int-tree-group-label">
              <?= h($group['label']) ?>
              <span style="font-weight:400;color:<?= $groupExists ? '#16a34a' : '#94a3b8' ?>;font-size:10px;text-transform:none;letter-spacing:0;margin-left:4px;">
                <?= $groupExists ? '&#10003;' : '&mdash;' ?>
              </span>
            </div>
            <?php if (!$isCustom): ?>
              <?php if (!empty($group['versions'])): ?>
              <ul>
                <?php foreach ($group['versions'] as $v):
                  $vExists = is_dir($groupDir . '/' . $v);
                ?>
                  <li class="<?= $vExists ? 'dir-exists' : 'dir-missing' ?>"><?= h($v) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php else: ?>
                <div class="int-tree-group-empty">no versions</div>
              <?php endif; ?>
            <?php else: ?>
              <?php if (!empty($_intCustomDirs)): ?>
              <ul>
                <?php foreach ($_intCustomDirs as $cd): ?>
                  <li class="dir-exists"><?= h($cd) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php else: ?>
                <div class="int-tree-group-empty">no custom repos yet</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="create_repo_structure">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary">Create repository structure</button>
        </form>
        <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted);">
          Kreira sve direktorije iznad. Postojeci se ne mijenjaju. Checkmark (&#10003;) = postoji na disku.
        </p>

        <hr style="border:none;border-top:1px solid var(--border);margin:20px 0 16px;">

        <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:10px;">Add custom repository folder</div>
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="add_custom_dir">
          <?= csrf_field() ?>
          <div class="int-add-custom">
            <div class="int-add-custom-field">
              <label>Folder name</label>
              <input type="text" name="folder_name"
                     placeholder="phpbb"
                     pattern="[a-z0-9_\-]+"
                     title="Samo mala slova, brojevi, crtica, underscore"
                     maxlength="64"
                     value="<?= h(strtolower($_POST['folder_name'] ?? '')) ?>">
            </div>
            <button type="submit" class="btn btn-ghost" style="font-size:13px;">Add folder</button>
          </div>
          <div class="int-hint">
            Kreira: <?= h($_intRepoRoot) ?>/custom/<em>&lt;folder_name&gt;</em>
            &nbsp;&middot;&nbsp;
            Dopušteno: a–z, 0–9, crtica, underscore
          </div>
        </form>

      </div>
    </div>

    <!-- ── Integrity Check ── -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Integrity Check</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="integrity_check">
          <?= csrf_field() ?>
          <div class="int-form-grid">
            <div class="int-field">
              <label>Origin / Repository path</label>
              <input type="text" name="origin_path"
                     placeholder="<?= h($_intRepoRoot) ?>/joomla/v4x"
                     value="<?= h($_POST['origin_path'] ?? '') ?>">
            </div>
            <div class="int-field">
              <label>Destination / Installation path</label>
              <input type="text" name="dest_path"
                     placeholder="/home/client/public_html"
                     value="<?= h($_POST['dest_path'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary" disabled title="Dostupno nakon hash database implementacije">
            Run Integrity Check
          </button>
        </form>
        <div class="int-placeholder">
          Integrity comparison will be available after hash database update.
        </div>
      </div>
    </div>

  </div>
</div>
</div>
</body>
</html>
