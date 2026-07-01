<?php
/**
 * 8Core Integrity v0.2.0 — Admin: Integrity Manager
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Included by scanner/admin/module.php router.
 * Auth, session, $pdo, h(), csrf_field() bootstrapped by router.
 */

require_once __DIR__ . '/../includes/integrity.php';

// ── State vars ─────────────────────────────────────────────────────────────────
$_intMessages      = [];
$_intShowRootCmd   = false;
$_intRootCmd       = '';
$_intImportSuccess = null;   // set on successful ZIP import
$_intZipConflict   = null;   // set when target exists, needs replace confirmation

// ── POST handlers ──────────────────────────────────────────────────────────────

/**
 * Parses php.ini size shorthand (8M, 256K, 2G) to bytes.
 */
function _int_ini_bytes(string $v): int {
    $v    = trim($v);
    $last = strtolower(substr($v, -1));
    $n    = (int) $v;
    return match($last) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Detect post_max_size exceeded ──────────────────────────────────────────
    // When exceeded PHP silently empties $_POST and $_FILES — no upload error,
    // no action field, handler never runs. Catch it here via CONTENT_LENGTH.
    $clen     = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxB = _int_ini_bytes(ini_get('post_max_size'));
    $skipHandlers = false;

    if ($clen > 0 && empty($_POST) && $clen > $postMaxB) {
        $_intMessages[] = [
            'type' => 'err',
            'text' => 'Upload failed: request size (' . integrity_format_bytes($clen) . ') '
                    . 'exceeds post_max_size (' . ini_get('post_max_size') . '). '
                    . 'Increase post_max_size (and upload_max_filesize) in php.ini.',
        ];
        $skipHandlers = true;
    } elseif (!empty($_POST)) {
        // ── CSRF check ─────────────────────────────────────────────────────────
        $submitted = $_POST['csrf_token'] ?? '';
        if (function_exists('csrf_enabled') && csrf_enabled()) {
            if (!hash_equals(csrf_token(), $submitted)) {
                $_intMessages[] = ['type' => 'err', 'text' => 'Invalid CSRF token. Refresh the page and try again.'];
                $skipHandlers = true;
            }
        }
    }

    $postAction = $skipHandlers ? '' : trim($_POST['action'] ?? '');

    // ── Create default directory structure ─────────────────────────────────────
    if ($postAction === 'create_repo_structure') {
        $anyFailed = false;
        foreach (integrity_ensure_repo_structure() as $r) {
            $_intMessages[] = [
                'type' => $r['ok'] ? 'ok' : 'err',
                'text' => ($r['ok'] ? 'OK' : 'FAIL') . '  ' . $r['path'] . '  [' . $r['note'] . ']',
            ];
            if (!$r['ok']) $anyFailed = true;
        }
        if ($anyFailed) {
            $_intShowRootCmd = true;
            $root            = integrity_repo_root();
            $_intRootCmd     = 'mkdir -p ' . implode(" \\\n         ", integrity_default_dirs())
                             . "\nchown -R {webuser}:{webuser} {$root}";
        }
    }

    // ── Add root application folder ────────────────────────────────────────────
    if ($postAction === 'add_app') {
        $name = strtolower(trim($_POST['app_name'] ?? ''));
        if (!integrity_valid_name($name)) {
            $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  Invalid application name "' . h($name) . '". Use only: a-z 0-9 _ -'];
        } else {
            $r = integrity_create_app($name);
            if ($r['exists']) {
                $_intMessages[] = ['type' => 'warn', 'text' => 'Application folder "' . h($name) . '" already exists.'];
            } elseif ($r['ok']) {
                $_intMessages[] = ['type' => 'ok', 'text' => 'OK  ' . $r['path'] . '  [created]'];
            } else {
                $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  ' . $r['path'] . '  [' . $r['note'] . ']'];
                $_intShowRootCmd = true;
                $root            = integrity_repo_root();
                $_intRootCmd     = "mkdir -p {$root}/{$name}\nchown -R {webuser}:{webuser} {$root}/{$name}";
            }
        }
    }

    // ── Add version sub-folder ─────────────────────────────────────────────────
    if ($postAction === 'add_version') {
        $app     = strtolower(trim($_POST['app_key']      ?? ''));
        $version = strtolower(trim($_POST['version_name'] ?? ''));
        if (!integrity_valid_name($app) || !integrity_valid_loose($version)) {
            $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  Invalid application or version name. Use only: a-z 0-9 . _ -'];
        } else {
            $r = integrity_create_version($app, $version);
            if ($r['exists']) {
                $_intMessages[] = ['type' => 'warn', 'text' => 'Version "' . h($version) . '" already exists in "' . h($app) . '".'];
            } elseif ($r['ok']) {
                $_intMessages[] = ['type' => 'ok', 'text' => 'OK  ' . $r['path'] . '  [created]'];
            } else {
                $_intMessages[] = ['type' => 'err', 'text' => 'FAIL  ' . $r['path'] . '  [' . $r['note'] . ']'];
                $_intShowRootCmd = true;
                $root            = integrity_repo_root();
                $_intRootCmd     = "mkdir -p {$root}/{$app}/{$version}\nchown -R {webuser}:{webuser} {$root}/{$app}/{$version}";
            }
        }
    }

    // ── Import ZIP — initial upload ────────────────────────────────────────────
    if ($postAction === 'import_zip') {
        $impApp     = strtolower(trim($_POST['imp_app']     ?? ''));
        $impBranch  = strtolower(trim($_POST['imp_branch']  ?? ''));
        $impVersion = strtolower(trim($_POST['imp_version'] ?? ''));
        $errors     = [];

        if (!integrity_valid_name($impApp))     $errors[] = 'Invalid application name.';
        if (!integrity_valid_loose($impBranch)) $errors[] = 'Invalid branch name.';
        if (!integrity_valid_loose($impVersion)) $errors[] = 'Invalid version name.';

        // Validate file upload
        $upload = $_FILES['imp_zip'] ?? null;
        $tmpZip = null;
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
            $uploadMaxIni = ini_get('upload_max_filesize');
            $postMaxIni   = ini_get('post_max_size');
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => "File exceeds upload_max_filesize ({$uploadMaxIni}). Increase upload_max_filesize and post_max_size ({$postMaxIni}) in php.ini.",
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE declared in the form.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded. Select a .zip file.',
                UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary folder is missing. Check sys_temp_dir in php.ini.',
                UPLOAD_ERR_CANT_WRITE => 'PHP failed to write the uploaded file to disk. Check tmp folder permissions.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            ];
            $code = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
            $errors[] = $uploadErrors[$code] ?? 'Upload error code ' . $code . '.';
        } else {
            if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'zip') {
                $errors[] = 'Only .zip files are allowed.';
            } else {
                $tmpZip = tempnam(sys_get_temp_dir(), '8int_') . '.zip';
                if (!move_uploaded_file($upload['tmp_name'], $tmpZip)) {
                    $errors[] = 'Failed to process uploaded file.';
                    $tmpZip = null;
                }
            }
        }

        if (empty($errors) && $tmpZip) {
            $targetDir = integrity_repo_root() . '/' . $impApp . '/' . $impBranch . '/' . $impVersion;
            $exists    = is_dir($targetDir);
            $empty     = $exists ? empty(array_diff(scandir($targetDir) ?: [], ['.', '..'])) : true;

            if ($exists && !$empty) {
                // Save ZIP to session for the replace confirmation step
                $token = bin2hex(random_bytes(12));
                $_SESSION['8int_zip'][$token] = [
                    'path'    => $tmpZip,
                    'app'     => $impApp,
                    'branch'  => $impBranch,
                    'version' => $impVersion,
                    'dir'     => $targetDir,
                    'expires' => time() + 600,
                ];
                $stats = integrity_dir_stats($targetDir);
                $_intZipConflict = [
                    'token'  => $token,
                    'dir'    => $targetDir,
                    'app'    => $impApp,
                    'branch' => $impBranch,
                    'ver'    => $impVersion,
                    'files'  => $stats['files'],
                    'dirs'   => $stats['dirs'],
                    'size'   => integrity_format_bytes($stats['size']),
                ];
            } else {
                // Proceed immediately
                $result = _int_do_extract($tmpZip, $targetDir, $impApp, $impBranch, $impVersion);
                if ($result['ok']) {
                    $_intImportSuccess = $result;
                } else {
                    foreach ($result['errors'] as $e) $_intMessages[] = ['type' => 'err', 'text' => $e];
                    if (!empty($result['rootCmd'])) {
                        $_intShowRootCmd = true;
                        $_intRootCmd     = $result['rootCmd'];
                    }
                }
                @unlink($tmpZip);
            }
        } else {
            if ($tmpZip) @unlink($tmpZip);
            foreach ($errors as $e) $_intMessages[] = ['type' => 'err', 'text' => $e];
        }
    }

    // ── Import ZIP — replace confirmation ──────────────────────────────────────
    if ($postAction === 'import_zip_replace') {
        $token  = trim($_POST['zip_token'] ?? '');
        $stored = $_SESSION['8int_zip'][$token] ?? null;
        unset($_SESSION['8int_zip'][$token]);

        if (!$stored || $stored['expires'] < time() || !file_exists($stored['path'])) {
            $_intMessages[] = ['type' => 'err', 'text' => 'Session expired or token invalid. Please re-upload the ZIP.'];
        } else {
            integrity_rmdir_recursive($stored['dir']);
            $result = _int_do_extract($stored['path'], $stored['dir'], $stored['app'], $stored['branch'], $stored['version']);
            if ($result['ok']) {
                $_intImportSuccess = $result;
            } else {
                foreach ($result['errors'] as $e) $_intMessages[] = ['type' => 'err', 'text' => $e];
                if (!empty($result['rootCmd'])) {
                    $_intShowRootCmd = true;
                    $_intRootCmd     = $result['rootCmd'];
                }
            }
            @unlink($stored['path']);
        }
    }

    // ── Import ZIP — abort (discard session temp file) ─────────────────────────
    if ($postAction === 'import_zip_abort') {
        $token  = trim($_POST['zip_token'] ?? '');
        $stored = $_SESSION['8int_zip'][$token] ?? null;
        unset($_SESSION['8int_zip'][$token]);
        if ($stored && isset($stored['path'])) @unlink($stored['path']);
    }
}

/**
 * Core extraction logic — validates ZIP, detects wrapper, creates target, extracts.
 * Returns ['ok' => bool, 'errors' => [], 'rootCmd' => '', ...stats].
 */
function _int_do_extract(string $zipPath, string $targetDir, string $app, string $branch, string $version): array {
    $root   = integrity_repo_root();
    $errors = [];

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'errors' => ['Cannot open ZIP file.'], 'rootCmd' => ''];
    }

    $scanErrors = integrity_zip_scan($zip);
    if (!empty($scanErrors)) {
        $zip->close();
        return ['ok' => false, 'errors' => $scanErrors, 'rootCmd' => ''];
    }

    $wrapper = integrity_zip_wrapper($zip);
    $zip->close();

    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true)) {
            $cmd = "mkdir -p {$targetDir}\nchown -R {webuser}:{webuser} {$targetDir}";
            return ['ok' => false, 'errors' => ['Cannot create target directory: ' . $targetDir], 'rootCmd' => $cmd];
        }
    }

    $result = integrity_zip_extract($zipPath, $targetDir, $wrapper);
    if (!$result['ok']) {
        return ['ok' => false, 'errors' => [$result['error']], 'rootCmd' => ''];
    }

    $stats = integrity_dir_stats($targetDir);
    return [
        'ok'     => true,
        'errors' => [],
        'rootCmd' => '',
        'path'   => $targetDir,
        'app'    => $app,
        'branch' => $branch,
        'version'=> $version,
        'files'  => $stats['files'],
        'dirs'   => $stats['dirs'],
        'size'   => integrity_format_bytes($stats['size']),
    ];
}

// ── Prepare render data ────────────────────────────────────────────────────────
$_intRepoRoot    = integrity_repo_root();
$_intGroups      = integrity_default_groups();
$_intExtraApps   = integrity_extra_apps();
$_intAllImported = integrity_all_imported();
$_intWebUser     = function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data')
    : 'www-data';
$_intRootCmd = str_replace('{webuser}', $_intWebUser, $_intRootCmd ?? '');

// All existing root-level dirs for dropdowns
$_intAllAppKeys = [];
foreach ($_intGroups as $g) {
    if (is_dir($_intRepoRoot . '/' . $g['key'])) $_intAllAppKeys[] = $g['key'];
}
foreach ($_intExtraApps as $ea) {
    $_intAllAppKeys[] = $ea;
}

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
/* ── Layout atoms ── */
.int-section { background:var(--surface); border:1px solid var(--border); border-radius:10px; margin-bottom:18px; }
.int-section-header { padding:16px 20px 0; display:flex; align-items:center; gap:10px; }
.int-section-header h3 { margin:0; font-size:13px; font-weight:700; color:var(--text); }
.int-divider { border:none; border-top:1px solid var(--border); margin:12px 0 0; }
.int-body { padding:16px 20px; }
.int-section-divider { border:none; border-top:1px solid var(--border); margin:20px 0 18px; }
.int-subsection-title { font-size:12px; font-weight:700; color:var(--text); margin-bottom:10px; }

/* ── Repo root badge ── */
.int-repo-path { font-family:var(--font-mono,monospace); font-size:13px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:8px 12px; color:var(--text); display:inline-block; margin-bottom:16px; word-break:break-all; }

/* ── Tree cards ── */
.int-tree-wrap { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.int-tree-group { background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:12px 14px; min-width:130px; }
.int-tree-group.is-extra { border-style:dashed; }
.int-tree-group-label { font-size:11px; font-weight:700; color:var(--text); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; display:flex; align-items:center; gap:5px; }
.int-tree-group ul { margin:0; padding:0 0 0 2px; list-style:none; }
.int-tree-group li { font-family:var(--font-mono,monospace); font-size:12px; padding:2px 0; display:flex; align-items:center; gap:5px; }
.int-tree-group li::before { content:''; display:inline-block; width:10px; height:1px; background:var(--border); flex-shrink:0; }
.int-tree-group-empty { font-size:11px; color:#94a3b8; font-style:italic; padding:2px 0; }
.dir-exists { color:#16a34a; }
.dir-missing { color:#94a3b8; }
.tick-ok { color:#16a34a; }
.tick-no { color:#cbd5e1; }

/* ── Messages ── */
.msg-list { list-style:none; margin:0 0 12px; padding:0; }
.msg-list li { font-family:var(--font-mono,monospace); font-size:12px; padding:3px 0; border-bottom:1px solid var(--border); }
.msg-list li:last-child { border-bottom:none; }
.msg-ok   { color:#16a34a; }
.msg-warn { color:#d97706; font-style:italic; }
.msg-err  { color:#dc2626; }

/* ── Root command box ── */
.int-root-cmd { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px 16px; margin-top:12px; }
.int-root-cmd p { margin:0 0 8px; font-size:12px; color:#92400e; font-weight:600; }
.int-root-cmd pre { margin:0; font-family:var(--font-mono,monospace); font-size:12px; color:#166534; background:#dcfce7; border-radius:6px; padding:10px 12px; white-space:pre-wrap; word-break:break-all; line-height:1.6; }

/* ── Conflict banner ── */
.int-conflict { background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:16px 20px; }
.int-conflict-title { font-size:13px; font-weight:700; color:#c2410c; margin:0 0 6px; }
.int-conflict-path { font-family:var(--font-mono,monospace); font-size:12px; color:#7c3aed; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:5px; padding:5px 10px; display:inline-block; margin-bottom:10px; word-break:break-all; }
.int-conflict-stats { font-size:12px; color:#78350f; margin-bottom:14px; }
.int-conflict-actions { display:flex; gap:10px; flex-wrap:wrap; }

/* ── Import success ── */
.int-success { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:16px 20px; }
.int-success-title { font-size:13px; font-weight:700; color:#15803d; margin:0 0 10px; }
.int-success-path { font-family:var(--font-mono,monospace); font-size:12px; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; border-radius:5px; padding:6px 10px; display:inline-block; margin-bottom:12px; word-break:break-all; }
.int-success-stats { display:flex; gap:20px; flex-wrap:wrap; }
.int-success-stat { background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:8px 14px; }
.int-success-stat-label { font-size:10px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:2px; }
.int-success-stat-value { font-size:16px; font-weight:700; color:var(--text); }

/* ── Forms ── */
.int-inline-form { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
.int-form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.int-form-field { display:flex; flex-direction:column; gap:4px; }
.int-form-field label,
.int-field label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; display:block; margin-bottom:4px; }
.int-form-field input[type=text],
.int-form-field select,
.int-field input[type=text],
.int-field select { padding:8px 10px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--font-mono,monospace); font-size:13px; outline:none; transition:border-color .13s; }
.int-form-field input[type=text] { width:190px; }
.int-form-field select { min-width:160px; }
.int-form-field input[type=text]::placeholder { color:#94a3b8; }
.int-form-field input[type=text]:focus,
.int-form-field select:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.int-form-field input[type=file] { padding:6px 8px; background:var(--surface2); border:1px solid var(--border); border-radius:6px; color:var(--text); font-size:12px; cursor:pointer; }
.int-hint { font-size:11px; color:#94a3b8; margin-top:6px; }
.int-php-limits { background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:10px 12px; margin-top:12px; font-size:11px; color:var(--text-muted); }
.int-php-limits strong { color:var(--text); }
.int-php-limits code { font-family:var(--font-mono,monospace); background:var(--surface2); border:1px solid var(--border); border-radius:3px; padding:1px 5px; }

/* ── Integrity check grid ── */
.int-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:14px; }
.int-field { }
.int-field input[type=text],
.int-field select { width:100%; box-sizing:border-box; }
.int-placeholder { background:var(--surface2); border:1px solid var(--border); border-radius:6px; padding:14px 16px; font-size:13px; color:var(--text-muted); margin-top:12px; }

@media (max-width:720px) {
  .int-form-grid { grid-template-columns:1fr; }
  .int-tree-wrap { flex-direction:column; }
  .int-inline-form, .int-form-row { flex-direction:column; align-items:flex-start; }
  .int-form-field input[type=text], .int-form-field select { width:100%; }
  .int-success-stats { flex-direction:column; }
}
</style>
</head>
<body>
<div class="layout">

<?php
$_intSidebarPath = realpath(__DIR__ . '/../../../admin/sidebar.php');
if ($_intSidebarPath && file_exists($_intSidebarPath)) include $_intSidebarPath;
?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">8Core Integrity</div>
    <div class="topbar-meta">
      <span style="font-size:12px;color:var(--text-muted);">v0.2.0</span>
      &nbsp;&nbsp;<a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <!-- ── Message bar ── -->
    <?php if (!empty($_intMessages)): ?>
    <div class="int-section" style="padding:14px 20px;margin-bottom:16px;">
      <ul class="msg-list" style="margin:0;">
        <?php foreach ($_intMessages as $m): ?>
          <li class="msg-<?= h($m['type']) ?>"><?= h($m['text']) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($_intShowRootCmd && $_intRootCmd): ?>
      <div class="int-root-cmd">
        <p>PHP nema dozvolu. Pokreni kao root:</p>
        <pre><?= h($_intRootCmd) ?></pre>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Import success ── -->
    <?php if ($_intImportSuccess): $s = $_intImportSuccess; ?>
    <div class="int-section" style="margin-bottom:16px;">
      <div class="int-body">
        <div class="int-success">
          <div class="int-success-title">Repository imported successfully.</div>
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Origin path</div>
          <div class="int-success-path"><?= h($s['path']) ?></div>
          <div class="int-success-stats">
            <div class="int-success-stat">
              <div class="int-success-stat-label">Files</div>
              <div class="int-success-stat-value"><?= number_format($s['files']) ?></div>
            </div>
            <div class="int-success-stat">
              <div class="int-success-stat-label">Folders</div>
              <div class="int-success-stat-value"><?= number_format($s['dirs']) ?></div>
            </div>
            <div class="int-success-stat">
              <div class="int-success-stat-label">Total size</div>
              <div class="int-success-stat-value"><?= h($s['size']) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── ZIP conflict confirmation ── -->
    <?php if ($_intZipConflict): $c = $_intZipConflict; ?>
    <div class="int-section" style="margin-bottom:16px;">
      <div class="int-section-header"><h3>Existing Repository</h3></div>
      <hr class="int-divider">
      <div class="int-body">
        <div class="int-conflict">
          <div class="int-conflict-title">Target folder already exists and is not empty.</div>
          <div class="int-conflict-path"><?= h($c['dir']) ?></div>
          <div class="int-conflict-stats">
            Files: <?= number_format($c['files']) ?>
            &nbsp;&middot;&nbsp;
            Folders: <?= number_format($c['dirs']) ?>
            &nbsp;&middot;&nbsp;
            Size: <?= h($c['size']) ?>
          </div>
          <div class="int-conflict-actions">
            <!-- Abort -->
            <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
              <input type="hidden" name="action"    value="import_zip_abort">
              <input type="hidden" name="zip_token" value="<?= h($c['token']) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost">Abort</button>
            </form>
            <!-- Replace -->
            <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
              <input type="hidden" name="action"    value="import_zip_replace">
              <input type="hidden" name="zip_token" value="<?= h($c['token']) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-danger"
                      onclick="return confirm('This will permanently delete the existing repository and replace it. Continue?')">
                Replace existing repository
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Import Repository ZIP ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Import Repository ZIP</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <p style="margin:0 0 14px;font-size:12px;color:var(--text-muted);">
          Upload a clean core package ZIP and extract it as the origin repository for a specific application, branch, and version.
        </p>

        <form method="post" action="module.php?module=8core-integrity&page=module_integrity"
              enctype="multipart/form-data">
          <input type="hidden" name="action" value="import_zip">
          <?= csrf_field() ?>

          <div class="int-form-row" style="margin-bottom:14px;">
            <div class="int-form-field">
              <label>Application</label>
              <?php if (!empty($_intAllAppKeys)): ?>
              <select name="imp_app">
                <option value="">— select —</option>
                <?php foreach ($_intAllAppKeys as $ak): ?>
                  <option value="<?= h($ak) ?>"><?= h($ak) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="text" name="imp_app" placeholder="joomla" pattern="[a-z0-9_\-]+" maxlength="64">
              <?php endif; ?>
            </div>

            <div class="int-form-field">
              <label>Branch</label>
              <input type="text" name="imp_branch"
                     placeholder="v4x"
                     pattern="[a-z0-9._\-]+" maxlength="32">
            </div>

            <div class="int-form-field">
              <label>Version</label>
              <input type="text" name="imp_version"
                     placeholder="4.4.14"
                     pattern="[a-z0-9._\-]+" maxlength="32">
            </div>

            <div class="int-form-field">
              <label>ZIP file</label>
              <input type="file" name="imp_zip" accept=".zip">
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Upload and extract repository</button>
        </form>

        <div class="int-hint" style="margin-top:10px;">
          Target: <?= h($_intRepoRoot) ?>/<em>&lt;application&gt;</em>/<em>&lt;branch&gt;</em>/<em>&lt;version&gt;</em>
          &nbsp;&middot;&nbsp;
          Branch and version accept: a–z, 0–9, dot, hyphen, underscore.
          <br>
          ZIP entries with path traversal, absolute paths, or symlinks are rejected.
          Wrapper folders (e.g. <code>Joomla_4.4.14/</code>) are stripped automatically.
        </div>

        <?php
        $uploadMaxIni = ini_get('upload_max_filesize');
        $postMaxIni   = ini_get('post_max_size');
        $uploadMaxB   = _int_ini_bytes($uploadMaxIni);
        $postMaxB     = _int_ini_bytes($postMaxIni);
        $limitOk      = $uploadMaxB >= 52428800 && $postMaxB >= 52428800; // 50 MB
        ?>
        <div class="int-php-limits" style="<?= $limitOk ? '' : 'border-color:#fde68a;background:#fffbeb;' ?>">
          <strong>PHP upload limits</strong>
          &nbsp;&middot;&nbsp;
          <code>upload_max_filesize</code> = <strong style="color:<?= $limitOk ? 'inherit' : '#b45309' ?>"><?= h($uploadMaxIni) ?></strong>
          &nbsp;&nbsp;
          <code>post_max_size</code> = <strong style="color:<?= $limitOk ? 'inherit' : '#b45309' ?>"><?= h($postMaxIni) ?></strong>
          <?php if (!$limitOk): ?>
          &nbsp;&mdash;&nbsp;
          <span style="color:#b45309;">These limits may be too low for large packages (Joomla full ZIP ~30 MB).
          Set both to at least <code>64M</code> in <code>php.ini</code>.</span>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Repository Manager ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Repository Manager</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">

        <div style="margin-bottom:6px;font-size:12px;color:var(--text-muted);">Repo root</div>
        <div class="int-repo-path"><?= h($_intRepoRoot) ?></div>

        <div style="margin-bottom:10px;font-size:12px;color:var(--text-muted);">
          Repository structure
          <span style="color:#94a3b8;font-size:11px;margin-left:6px;">&#10003; = exists on disk</span>
          <span style="color:#94a3b8;font-size:11px;margin-left:6px;">dashed = user-added</span>
        </div>

        <!-- Tree -->
        <div class="int-tree-wrap">
          <?php foreach ($_intGroups as $group):
            $groupDir    = $_intRepoRoot . '/' . $group['key'];
            $groupExists = is_dir($groupDir);
          ?>
          <div class="int-tree-group">
            <div class="int-tree-group-label">
              <?= h($group['label']) ?>
              <span class="<?= $groupExists ? 'tick-ok' : 'tick-no' ?>" style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;">
                <?= $groupExists ? '&#10003;' : '&mdash;' ?>
              </span>
            </div>
            <?php
              $subDirs = !empty($group['versions'])
                ? $group['versions']
                : integrity_app_versions($group['key']);
              if (!empty($subDirs)):
            ?>
            <ul>
              <?php foreach ($subDirs as $sd):
                $sdExists = is_dir($groupDir . '/' . $sd);
              ?>
                <li class="<?= $sdExists ? 'dir-exists' : 'dir-missing' ?>"><?= h($sd) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?>
              <div class="int-tree-group-empty">no versions</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php foreach ($_intExtraApps as $ea):
            $subDirs = integrity_app_versions($ea);
          ?>
          <div class="int-tree-group is-extra">
            <div class="int-tree-group-label">
              <?= h($ea) ?>
              <span class="tick-ok" style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;">&#10003;</span>
            </div>
            <?php if (!empty($subDirs)): ?>
            <ul>
              <?php foreach ($subDirs as $sd): ?>
                <li class="dir-exists"><?= h($sd) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?>
              <div class="int-tree-group-empty">no versions</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Create default structure -->
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="create_repo_structure">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary">Create repository structure</button>
        </form>
        <p style="margin:7px 0 0;font-size:12px;color:var(--text-muted);">
          Creates the default tree (Joomla, WordPress, WHMCS, PrestaShop, Custom). Existing directories are not modified.
        </p>

        <hr class="int-section-divider">

        <!-- Add application -->
        <div class="int-subsection-title">Add application</div>
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="add_app">
          <?= csrf_field() ?>
          <div class="int-inline-form">
            <div class="int-form-field">
              <label>Application name</label>
              <input type="text" name="app_name" placeholder="magento" pattern="[a-z0-9_\-]+" maxlength="64">
            </div>
            <button type="submit" class="btn btn-ghost" style="font-size:13px;">Add application</button>
          </div>
          <div class="int-hint">Creates: <?= h($_intRepoRoot) ?>/<em>&lt;name&gt;</em> &middot; a–z, 0–9, hyphen, underscore</div>
        </form>

        <hr class="int-section-divider">

        <!-- Add version -->
        <div class="int-subsection-title">Add version folder</div>
        <?php if (empty($_intAllAppKeys)): ?>
          <p style="font-size:12px;color:var(--text-muted);margin:0;">
            No application folders on disk yet. Run &ldquo;Create repository structure&rdquo; or add an application above.
          </p>
        <?php else: ?>
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
          <input type="hidden" name="action" value="add_version">
          <?= csrf_field() ?>
          <div class="int-inline-form">
            <div class="int-form-field">
              <label>Application</label>
              <select name="app_key">
                <?php foreach ($_intAllAppKeys as $ak): ?>
                  <option value="<?= h($ak) ?>"><?= h($ak) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="int-form-field">
              <label>Version / folder name</label>
              <input type="text" name="version_name" placeholder="v4x" pattern="[a-z0-9._\-]+" maxlength="64">
            </div>
            <button type="submit" class="btn btn-ghost" style="font-size:13px;">Add version</button>
          </div>
          <div class="int-hint">Creates: <?= h($_intRepoRoot) ?>/<em>&lt;application&gt;</em>/<em>&lt;version&gt;</em></div>
        </form>
        <?php endif; ?>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Integrity Check ── -->
    <!-- ══════════════════════════════════════════════════════ -->
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
              <?php if (!empty($_intAllImported)): ?>
              <select name="origin_path">
                <option value="">— select imported repository —</option>
                <?php foreach ($_intAllImported as $imp): ?>
                  <option value="<?= h($imp['path']) ?>"><?= h($imp['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <div style="margin-top:6px;">
                <input type="text" name="origin_path_manual"
                       placeholder="or enter path manually: <?= h($_intRepoRoot) ?>/joomla/v4x/4.4.14"
                       style="width:100%;box-sizing:border-box;font-size:12px;">
              </div>
              <?php else: ?>
              <input type="text" name="origin_path"
                     placeholder="<?= h($_intRepoRoot) ?>/joomla/v4x/4.4.14"
                     value="<?= h($_POST['origin_path'] ?? '') ?>">
              <?php endif; ?>
            </div>
            <div class="int-field">
              <label>Destination / Installation path</label>
              <input type="text" name="dest_path"
                     placeholder="/home/client/public_html"
                     value="<?= h($_POST['dest_path'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary" disabled title="Available after hash database implementation">
            Run Integrity Check
          </button>
        </form>
        <div class="int-placeholder">
          Integrity comparison will be available after hash database implementation.
        </div>
      </div>
    </div>

  </div>
</div>
</div>
</body>
</html>
