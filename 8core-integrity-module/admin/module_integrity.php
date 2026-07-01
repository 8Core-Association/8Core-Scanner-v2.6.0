<?php
/**
 * 8Core Integrity v0.1.0 — Admin: Integrity Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Installed path: scanner/modules/8core-integrity/admin/module_integrity.php
 * Scanner root:   scanner/ = __DIR__ . '/../../../'
 */

// ── Bootstrap (relative to installed location) ────────────────────────────────
$scannerRoot = realpath(__DIR__ . '/../../../');
require $scannerRoot . '/includes/auth.php';
require $scannerRoot . '/includes/helpers.php';
require __DIR__ . '/../includes/integrity.php';
require_admin();

// ── Handle POST actions ────────────────────────────────────────────────────────
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'create_repo_structure') {
        $results = integrity_ensure_repo_structure();
        foreach ($results as $r) {
            $messages[] = [
                'ok'   => $r['ok'],
                'text' => ($r['ok'] ? 'OK' : 'FAIL') . '  ' . $r['path'] . '  [' . $r['note'] . ']',
            ];
        }
    }
}

$repoRoot    = integrity_repo_root();
$defaultTree = integrity_get_default_tree();
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
.int-repo-path { font-family:var(--font-mono,monospace); font-size:13px; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:8px 12px; color:var(--text); display:inline-block; margin-bottom:14px; }
.int-tree { margin:0 0 14px; padding:0; list-style:none; }
.int-tree li { font-family:var(--font-mono,monospace); font-size:12px; color:var(--text-muted); padding:2px 0; }
.int-tree li::before { content:'├─ '; color:var(--border); }
.int-tree li:last-child::before { content:'└─ '; color:var(--border); }
.msg-list { list-style:none; margin:0 0 14px; padding:0; }
.msg-list li { font-family:var(--font-mono,monospace); font-size:12px; padding:3px 0; border-bottom:1px solid var(--bg); }
.msg-list li:last-child { border-bottom:none; }
.msg-ok  { color:#4ade80; }
.msg-err { color:#f87171; }
.int-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:14px; }
.int-field label { display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.int-field input[type=text] { width:100%; padding:8px 10px; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--font-mono,monospace); font-size:13px; outline:none; box-sizing:border-box; transition:border-color .13s; }
.int-field input[type=text]:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.int-placeholder { background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px 16px; font-size:13px; color:var(--text-muted); margin-top:12px; }
@media (max-width:640px) { .int-form-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="layout">

<?php include $scannerRoot . '/admin/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">8Core Integrity</div>
    <div class="topbar-meta">
      <span style="font-size:12px;color:var(--text-muted);">v0.1.0</span>
      &nbsp;&nbsp;<a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if (!empty($messages)): ?>
    <div class="int-section" style="padding:14px 20px;margin-bottom:16px;">
      <ul class="msg-list" style="margin:0;">
        <?php foreach ($messages as $m): ?>
          <li class="<?= $m['ok'] ? 'msg-ok' : 'msg-err' ?>"><?= h($m['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- ── 1. Repository Manager ── -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Repository Manager</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <div style="margin-bottom:6px;font-size:12px;color:var(--text-muted);">Repo root path</div>
        <div class="int-repo-path"><?= h($repoRoot) ?></div>

        <div style="margin-bottom:6px;font-size:12px;color:var(--text-muted);">Default directory tree</div>
        <ul class="int-tree">
          <?php foreach ($defaultTree as $dir): ?>
            <li><?= h(str_replace($repoRoot . '/', '', $dir)) ?></li>
          <?php endforeach; ?>
        </ul>

        <form method="post">
          <input type="hidden" name="action" value="create_repo_structure">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary">Create repository structure</button>
        </form>
        <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted);">
          Kreira direktorije navedene u listi iznad. Postojeći direktoriji se ne mijenjaju.
          Upload core ZIP-a i hashiranje dostupni u sljedećoj verziji.
        </p>
      </div>
    </div>

    <!-- ── 2. Integrity Check ── -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Integrity Check</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <form method="post">
          <input type="hidden" name="action" value="integrity_check">
          <?= csrf_field() ?>
          <div class="int-form-grid">
            <div class="int-field">
              <label>Origin / Repository path</label>
              <input type="text" name="origin_path"
                     placeholder="/home/8core_integrity/repo/joomla/v4x"
                     value="<?= h($_POST['origin_path'] ?? '') ?>">
            </div>
            <div class="int-field">
              <label>Destination / Installation path</label>
              <input type="text" name="dest_path"
                     placeholder="/home/buckhr/public_html"
                     value="<?= h($_POST['dest_path'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary" disabled title="Dostupno u sljedećoj verziji">Run Integrity Check</button>
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
