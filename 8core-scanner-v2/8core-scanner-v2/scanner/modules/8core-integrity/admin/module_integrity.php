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

// Ensure the integrity ignore table exists before any DB query in this module.
integrity_ensure_tables($pdo);

// ── State vars ─────────────────────────────────────────────────────────────────
$_intMessages      = [];
$_intShowRootCmd   = false;
$_intRootCmd       = '';
$_intImportSuccess = null;
$_intZipConflict   = null;
$_intCheckResults  = null;   // set after run_structural_check or add_integrity_ignore
$_intCheckOrigin   = '';
$_intCheckDest     = '';
$_intDetSoftware   = '';     // detected software name (passed through forms)

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

    // ── Run structural check ───────────────────────────────────────────────────
    if ($postAction === 'run_structural_check') {
        $_intDetSoftware  = trim($_POST['detected_software'] ?? '');
        $_intCheckOrigin  = trim($_POST['origin_path_manual'] ?? '') ?: trim($_POST['origin_path'] ?? '');
        $_intCheckDest    = trim($_POST['dest_path'] ?? '');

        if ($_intCheckOrigin === '' || $_intCheckDest === '') {
            $_intMessages[] = ['type' => 'err', 'text' => 'Both origin and destination paths are required.'];
        } else {
            $ignores = integrity_ignores_for($pdo, $_intCheckOrigin, $_intCheckDest);
            $result  = integrity_structural_check($_intCheckOrigin, $_intCheckDest, $_intDetSoftware, $ignores);
            if (!$result['ok']) {
                $_intMessages[] = ['type' => 'err', 'text' => 'Check failed: ' . $result['error']];
            } else {
                $_intCheckResults = $result;
            }
        }
    }

    // ── Add integrity ignore (then re-run check) ───────────────────────────────
    if ($postAction === 'add_integrity_ignore') {
        $_intDetSoftware = trim($_POST['detected_software'] ?? '');
        $_intCheckOrigin = trim($_POST['origin_path']  ?? '');
        $_intCheckDest   = trim($_POST['dest_path']    ?? '');
        $ignPath         = trim($_POST['ignored_path'] ?? '');
        $ignType         = trim($_POST['ignore_type']  ?? 'extra_path');
        $ignNote         = trim($_POST['ignore_note']  ?? '');

        if ($_intCheckOrigin && $_intCheckDest && $ignPath) {
            if (integrity_add_ignore($pdo, $_intCheckOrigin, $_intCheckDest, $ignPath, $ignType, $ignNote)) {
                $_intMessages[] = ['type' => 'ok', 'text' => 'Ignored in Integrity: ' . $ignPath];
            } else {
                $_intMessages[] = ['type' => 'err', 'text' => 'Could not add ignore (invalid path or DB error).'];
            }
            // Re-run check with updated ignore list
            $ignores = integrity_ignores_for($pdo, $_intCheckOrigin, $_intCheckDest);
            $result  = integrity_structural_check($_intCheckOrigin, $_intCheckDest, $_intDetSoftware, $ignores);
            if ($result['ok']) {
                $_intCheckResults = $result;
            }
        }
    }

    // ── AJAX: browse directory ─────────────────────────────────────────────────
    if ($postAction === 'browse_dir') {
        $requestedPath = trim($_POST['path'] ?? '/home');
        $result        = integrity_browse_dir($requestedPath);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    // ── AJAX: detect software ──────────────────────────────────────────────────
    if ($postAction === 'detect_software') {
        $path   = trim($_POST['path'] ?? '');
        $result = $path !== '' ? integrity_detect_software($path) : ['software' => 'Unknown', 'version' => 'unknown', 'root' => $path, 'error' => 'No path provided.'];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
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

// ── Tab routing ────────────────────────────────────────────────────────────────
$_intTabBase    = 'module.php?module=8core-integrity&page=module_integrity';
$_rawTab        = trim($_GET['tab'] ?? '');
$_intActiveTab  = match($_rawTab) {
    'repo', 'check' => $_rawTab,
    default => !empty($_intAllImported) ? 'check' : 'repo',
};

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

/* ── Destination row ── */
.int-dest-row { display:flex; gap:6px; align-items:stretch; }
.int-dest-row input[type=text] { flex:1; min-width:0; }
.int-browse-btn { white-space:nowrap; font-size:12px; padding:7px 12px; }

/* ── Detection box ── */
.int-detect-box { margin-top:10px; border:1px solid var(--border); border-radius:7px; overflow:hidden; }
.int-detect-loading { display:flex; align-items:center; gap:8px; padding:12px 14px; font-size:12px; color:var(--text-muted); }
.int-detect-spinner { display:inline-block; width:14px; height:14px; border:2px solid var(--border); border-top-color:#2563eb; border-radius:50%; animation:int-spin .7s linear infinite; flex-shrink:0; }
@keyframes int-spin { to { transform:rotate(360deg); } }
.int-detect-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); padding:10px 14px 4px; }
.int-detect-grid { display:grid; grid-template-columns:80px 1fr; gap:4px 10px; padding:0 14px 10px; font-size:12px; }
.int-detect-key { color:var(--text-muted); font-size:11px; align-self:center; }
.int-detect-val { color:var(--text); font-family:var(--font-mono,monospace); font-size:12px; font-weight:600; }
.int-detect-path { font-weight:400; word-break:break-all; }
.int-detect-warning { margin:0 10px 10px; padding:8px 10px; background:#fffbeb; border:1px solid #fde68a; border-radius:5px; font-size:11px; color:#92400e; }

/* ── Path browser modal ── */
.int-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9000; display:flex; align-items:center; justify-content:center; }
.int-modal { background:var(--surface,#fff); border:1px solid var(--border); border-radius:10px; box-shadow:0 8px 32px rgba(0,0,0,.22); width:520px; max-width:calc(100vw - 32px); max-height:80vh; display:flex; flex-direction:column; overflow:hidden; }
.int-modal-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px 10px; border-bottom:1px solid var(--border); flex-shrink:0; }
.int-modal-title { font-size:14px; font-weight:700; color:var(--text); }
.int-modal-close { background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer; line-height:1; padding:0 2px; }
.int-modal-close:hover { color:var(--text); }
.int-modal-breadcrumb { padding:8px 18px; border-bottom:1px solid var(--border); font-size:11px; color:var(--text-muted); flex-shrink:0; display:flex; align-items:center; flex-wrap:wrap; gap:2px; min-height:34px; }
.int-bc-seg { cursor:default; padding:1px 3px; border-radius:3px; }
.int-bc-link { cursor:pointer; color:#2563eb; }
.int-bc-link:hover { background:rgba(37,99,235,.08); }
.int-bc-sep { color:var(--border); margin:0 1px; }
.int-modal-body { flex:1; overflow-y:auto; padding:8px 0; min-height:120px; }
.int-browse-loading,.int-browse-empty { padding:20px 18px; font-size:13px; color:var(--text-muted); }
.int-browse-error { padding:12px 18px; }
.int-browse-error-msg { color:#dc2626; font-size:13px; margin-bottom:10px; }
.int-browse-navi { display:flex; gap:6px; margin-top:10px; }
.int-browse-navi input[type=text] { flex:1; padding:7px 10px; border:1px solid var(--border); border-radius:6px; font-family:var(--font-mono,monospace); font-size:12px; background:var(--surface2,#f8fafc); color:var(--text); outline:none; }
.int-browse-navi input[type=text]:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.int-browse-navi-hint { font-size:11px; color:var(--text-muted); margin-top:6px; }
.int-dir-list { list-style:none; margin:0; padding:0; }
.int-dir-item { display:flex; align-items:center; gap:9px; padding:8px 18px; cursor:pointer; font-size:13px; color:var(--text); transition:background .1s; user-select:none; }
.int-dir-item:hover { background:var(--surface2,#f8fafc); }
.int-dir-item.is-selected { background:rgba(37,99,235,.1); color:#1d4ed8; }
.int-dir-icon { font-size:9px; color:var(--text-muted); flex-shrink:0; width:10px; text-align:center; }
.int-dir-name { font-family:var(--font-mono,monospace); font-size:12px; }
.int-modal-footer { border-top:1px solid var(--border); padding:10px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-shrink:0; }
.int-modal-current-path { font-family:var(--font-mono,monospace); font-size:11px; color:var(--text-muted); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.int-modal-actions { display:flex; gap:8px; flex-shrink:0; }

/* ── Tab bar ── */
.int-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:22px; }
.int-tab { padding:10px 20px; font-size:13px; font-weight:600; color:var(--text-muted); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s, border-color .15s; white-space:nowrap; }
.int-tab:hover { color:var(--text); border-bottom-color:var(--border); }
.int-tab.is-active { color:#2563eb; border-bottom-color:#2563eb; }

/* ── Check results ── */
.int-check-results { margin-top:18px; }
.int-results-summary { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:12px 16px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; margin-bottom:14px; font-size:13px; }
.int-results-total { font-weight:700; color:var(--text); margin-right:4px; }
.int-sev-tag { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:700; }
.int-sev-tag.is-suspicious { background:#fee2e2; color:#dc2626; }
.int-sev-tag.is-warning    { background:#fff7ed; color:#d97706; }
.int-sev-tag.is-info       { background:#eff6ff; color:#2563eb; }
.int-results-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:8px; }
.int-results-table { width:100%; border-collapse:collapse; font-size:12px; }
.int-results-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); padding:9px 12px; border-bottom:2px solid var(--border); text-align:left; white-space:nowrap; background:var(--surface2); }
.int-results-table td { padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.int-results-table tr:last-child td { border-bottom:none; }
.int-results-table tr.sev-suspicious td { background:rgba(220,38,38,.04); }
.int-results-table tr.sev-info td { background:rgba(37,99,235,.03); }
.int-sev-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; letter-spacing:.04em; white-space:nowrap; }
.int-sev-badge.suspicious { background:#fee2e2; color:#dc2626; }
.int-sev-badge.warning    { background:#fff7ed; color:#d97706; }
.int-sev-badge.info       { background:#eff6ff; color:#2563eb; }
.int-result-type { font-family:var(--font-mono,monospace); font-size:11px; font-weight:700; color:var(--text); white-space:nowrap; }
.int-result-rel  { font-family:var(--font-mono,monospace); font-size:12px; color:var(--text); word-break:break-all; }
.int-result-full { font-family:var(--font-mono,monospace); font-size:10px; color:var(--text-muted); word-break:break-all; margin-top:2px; }
.int-btn-ignore { background:none; border:1px solid var(--border); border-radius:5px; padding:4px 9px; font-size:11px; color:var(--text-muted); cursor:pointer; white-space:nowrap; transition:background .12s, color .12s, border-color .12s; }
.int-btn-ignore:hover { background:#fef3c7; border-color:#d97706; color:#92400e; }
.int-truncated-note { padding:10px 14px; font-size:11px; color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; margin-top:12px; }
.int-no-findings { padding:20px 16px; font-size:13px; color:#16a34a; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-weight:600; margin-top:14px; }
.int-check-context { display:flex; flex-wrap:wrap; gap:16px; padding:10px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:7px; margin-bottom:14px; font-size:11px; }
.int-check-context-item { display:flex; flex-direction:column; gap:2px; min-width:0; }
.int-check-context-label { font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
.int-check-context-val { font-family:var(--font-mono,monospace); color:var(--text); word-break:break-all; }

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
      <span style="font-size:12px;color:var(--text-muted);">v0.5.0</span>
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
            <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo">
              <input type="hidden" name="action"    value="import_zip_abort">
              <input type="hidden" name="zip_token" value="<?= h($c['token']) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost">Abort</button>
            </form>
            <!-- Replace -->
            <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo">
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

    <!-- ── Tab bar ─────────────────────────────────────────────────────────── -->
    <nav class="int-tabs">
      <a href="<?= h($_intTabBase) ?>&tab=repo"
         class="int-tab <?= $_intActiveTab === 'repo'  ? 'is-active' : '' ?>">Repository Manager</a>
      <a href="<?= h($_intTabBase) ?>&tab=check"
         class="int-tab <?= $_intActiveTab === 'check' ? 'is-active' : '' ?>">Integrity Check</a>
    </nav>

    <!-- ── Tab panel: Repository Manager ──────────────────────────────────── -->
    <div id="int-tab-repo"<?= $_intActiveTab !== 'repo' ? ' style="display:none"' : '' ?>>

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

        <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo"
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
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo">
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
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo">
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
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=repo">
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

    </div><!-- /#int-tab-repo -->

    <!-- ── Tab panel: Integrity Check ────────────────────────────────────── -->
    <div id="int-tab-check"<?= $_intActiveTab !== 'check' ? ' style="display:none"' : '' ?>>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Integrity Check ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Integrity Check</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <form method="post" action="module.php?module=8core-integrity&page=module_integrity&tab=check" id="int-check-form">
          <input type="hidden" name="action" value="run_structural_check">
          <input type="hidden" name="detected_software" id="int-detected-software"
                 value="<?= h($_intDetSoftware) ?>">
          <?= csrf_field() ?>
          <div class="int-form-grid">

            <!-- Origin -->
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

            <!-- Destination -->
            <div class="int-field">
              <label>Destination / Installation path</label>
              <div class="int-dest-row">
                <input type="text" name="dest_path" id="int-dest-path"
                       placeholder="/home/client/public_html"
                       value="<?= h($_POST['dest_path'] ?? '') ?>"
                       autocomplete="off">
                <button type="button" class="btn btn-ghost int-browse-btn" id="int-browse-btn">
                  Browse /home
                </button>
              </div>

              <!-- Detection result box (hidden until path is set) -->
              <div class="int-detect-box" id="int-detect-box" style="display:none;">
                <div class="int-detect-loading" id="int-detect-loading" style="display:none;">
                  <span class="int-detect-spinner"></span> Detecting software&hellip;
                </div>
                <div id="int-detect-result" style="display:none;">
                  <div class="int-detect-title">Detected installation</div>
                  <div class="int-detect-grid">
                    <span class="int-detect-key">Software</span>
                    <span class="int-detect-val" id="int-det-software">&mdash;</span>
                    <span class="int-detect-key">Version</span>
                    <span class="int-detect-val" id="int-det-version">&mdash;</span>
                    <span class="int-detect-key">Root</span>
                    <span class="int-detect-val int-detect-path" id="int-det-root">&mdash;</span>
                  </div>
                  <div class="int-detect-warning" id="int-detect-warning" style="display:none;">
                    Software could not be identified. You can still run the check manually.
                  </div>
                </div>
              </div>
            </div>

          </div>

          <button type="submit" class="btn btn-primary">
            Run Structural Check
          </button>
        </form>
        <div class="int-placeholder">
          Structural check compares file/folder existence only. Hash comparison will be available in a future version.
        </div>

        <?php if ($_intCheckResults): $cr = $_intCheckResults; ?>
        <div class="int-check-results">

          <!-- Context bar -->
          <div class="int-check-context">
            <div class="int-check-context-item">
              <span class="int-check-context-label">Origin</span>
              <span class="int-check-context-val"><?= h($cr['origin']) ?></span>
            </div>
            <div class="int-check-context-item">
              <span class="int-check-context-label">Destination</span>
              <span class="int-check-context-val"><?= h($cr['dest']) ?></span>
            </div>
            <?php if ($_intDetSoftware && $_intDetSoftware !== 'Unknown'): ?>
            <div class="int-check-context-item">
              <span class="int-check-context-label">Software</span>
              <span class="int-check-context-val"><?= h($_intDetSoftware) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <?php if (empty($cr['findings'])): ?>
          <div class="int-no-findings">No structural differences found.</div>
          <?php else: ?>

          <!-- Summary bar -->
          <div class="int-results-summary">
            <span class="int-results-total"><?= number_format(count($cr['findings'])) ?> finding<?= count($cr['findings']) !== 1 ? 's' : '' ?></span>
            <?php if ($cr['counts']['suspicious'] > 0): ?>
              <span class="int-sev-tag is-suspicious"><?= number_format($cr['counts']['suspicious']) ?> suspicious</span>
            <?php endif; ?>
            <?php if ($cr['counts']['warning'] > 0): ?>
              <span class="int-sev-tag is-warning"><?= number_format($cr['counts']['warning']) ?> warning<?= $cr['counts']['warning'] !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ($cr['counts']['info'] > 0): ?>
              <span class="int-sev-tag is-info"><?= number_format($cr['counts']['info']) ?> info</span>
            <?php endif; ?>
          </div>

          <!-- Results table -->
          <div class="int-results-wrap">
            <table class="int-results-table">
              <thead>
                <tr>
                  <th>Severity</th>
                  <th>Type</th>
                  <th>Path</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cr['findings'] as $f): ?>
                <tr class="sev-<?= h($f['severity']) ?>">
                  <td>
                    <span class="int-sev-badge <?= h($f['severity']) ?>">
                      <?= strtoupper(h($f['severity'])) ?>
                    </span>
                  </td>
                  <td><span class="int-result-type"><?= h($f['type']) ?></span></td>
                  <td>
                    <div class="int-result-rel"><?= h($f['rel']) ?></div>
                    <div class="int-result-full"><?= h($f['fullpath']) ?></div>
                  </td>
                  <td>
                    <?php if ($f['type'] !== 'USER_CONTENT_FOLDER'): ?>
                    <form method="post"
                          action="module.php?module=8core-integrity&page=module_integrity&tab=check"
                          style="display:inline">
                      <input type="hidden" name="action"            value="add_integrity_ignore">
                      <input type="hidden" name="origin_path"       value="<?= h($cr['origin']) ?>">
                      <input type="hidden" name="dest_path"         value="<?= h($cr['dest']) ?>">
                      <input type="hidden" name="ignored_path"      value="<?= h($f['rel']) ?>">
                      <input type="hidden" name="ignore_type"       value="<?= str_contains($f['type'], 'MISSING') ? 'missing_path' : 'extra_path' ?>">
                      <input type="hidden" name="detected_software" value="<?= h($_intDetSoftware) ?>">
                      <?= csrf_field() ?>
                      <button type="button" class="int-btn-ignore"
                              onclick="if(confirm('Ignore \&quot;<?= h(addslashes($f['rel'])) ?>\&quot; in Integrity checks for this destination?')) this.closest('form').submit()">
                        Ignore in Integrity
                      </button>
                    </form>
                    <?php else: ?>
                    <span style="font-size:11px;color:var(--text-muted);">User content</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php endif; ?>

          <?php if ($cr['truncated']): ?>
          <div class="int-truncated-note">
            Result truncated: installation exceeds 20,000 items. Only the first items are shown.
          </div>
          <?php endif; ?>

        </div>
        <?php endif; ?>
      </div>
    </div>

    </div><!-- /#int-tab-check -->

  </div>
</div>
</div>

<!-- ── Browse /home modal ─────────────────────────────────────────────────── -->
<div class="int-modal-overlay" id="int-browse-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="Browse /home">
  <div class="int-modal">
    <div class="int-modal-header">
      <div class="int-modal-title">Browse /home</div>
      <button type="button" class="int-modal-close" id="int-browse-close" aria-label="Close">&times;</button>
    </div>
    <div class="int-modal-breadcrumb" id="int-breadcrumb"></div>
    <div class="int-modal-body" id="int-browse-body">
      <div class="int-browse-loading">Loading&hellip;</div>
    </div>
    <div class="int-modal-footer">
      <span class="int-modal-current-path" id="int-modal-current-path"></span>
      <div class="int-modal-actions">
        <button type="button" class="btn btn-ghost" id="int-browse-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="int-browse-use" disabled>Use this path</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var AJAX_URL  = 'module.php?module=8core-integrity&page=module_integrity';
  var csrfToken = document.querySelector('input[name="csrf_token"]')
                  ? document.querySelector('input[name="csrf_token"]').value : '';

  // ── Elements ──────────────────────────────────────────────────────────────
  var modal        = document.getElementById('int-browse-modal');
  var browseBtn    = document.getElementById('int-browse-btn');
  var closeBtn     = document.getElementById('int-browse-close');
  var cancelBtn    = document.getElementById('int-browse-cancel');
  var useBtn       = document.getElementById('int-browse-use');
  var bodyEl       = document.getElementById('int-browse-body');
  var breadEl      = document.getElementById('int-breadcrumb');
  var curPathEl    = document.getElementById('int-modal-current-path');
  var destInput    = document.getElementById('int-dest-path');
  var detectBox    = document.getElementById('int-detect-box');
  var detectLoad   = document.getElementById('int-detect-loading');
  var detectResult = document.getElementById('int-detect-result');
  var detSoftware  = document.getElementById('int-det-software');
  var detVersion   = document.getElementById('int-det-version');
  var detRoot      = document.getElementById('int-det-root');
  var detWarning   = document.getElementById('int-detect-warning');
  var detHidden    = document.getElementById('int-detected-software');

  var selectedPath = '';

  // ── AJAX helper ───────────────────────────────────────────────────────────
  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    for (var k in data) fd.append(k, data[k]);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.onload = function () {
      try { cb(null, JSON.parse(xhr.responseText)); }
      catch(e) { cb('Invalid response'); }
    };
    xhr.onerror = function () { cb('Network error'); };
    xhr.send(fd);
  }

  // ── Breadcrumb ────────────────────────────────────────────────────────────
  function renderBreadcrumb(path) {
    var parts = path.replace(/^\/home/, '').split('/').filter(Boolean);
    var html  = '<span class="int-bc-seg int-bc-link" data-path="/home">/home</span>';
    var accum = '/home';
    for (var i = 0; i < parts.length; i++) {
      accum += '/' + parts[i];
      var p   = accum; // closure capture
      html   += '<span class="int-bc-sep">&#8250;</span>'
              + '<span class="int-bc-seg int-bc-link" data-path="' + escHtml(p) + '">' + escHtml(parts[i]) + '</span>';
    }
    breadEl.innerHTML = html;
    breadEl.querySelectorAll('.int-bc-link').forEach(function (el) {
      el.addEventListener('click', function () { loadDir(this.dataset.path); });
    });
  }

  // ── Load directory ────────────────────────────────────────────────────────
  function naviHtml(msg, prefill) {
    return '<div class="int-browse-error">'
      + (msg ? '<div class="int-browse-error-msg">' + escHtml(msg) + '</div>' : '')
      + '<div class="int-browse-navi">'
      + '<input type="text" id="int-navi-input" placeholder="/home/username/public_html" value="' + escHtml(prefill || '') + '">'
      + '<button type="button" class="btn btn-ghost" style="font-size:12px;padding:7px 12px;" id="int-navi-go">Go</button>'
      + '</div>'
      + '<div class="int-browse-navi-hint">Type a path inside /home and press Go to navigate directly.</div>'
      + '</div>';
  }

  function bindNaviGo() {
    var inp = document.getElementById('int-navi-input');
    var btn = document.getElementById('int-navi-go');
    if (!inp || !btn) return;
    function go() {
      var v = inp.value.trim();
      if (v) loadDir(v);
    }
    btn.addEventListener('click', go);
    inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
  }

  function loadDir(path) {
    bodyEl.innerHTML = '<div class="int-browse-loading">Loading&hellip;</div>';
    selectedPath = path;
    curPathEl.textContent = path;
    useBtn.disabled = false;
    renderBreadcrumb(path);

    post('browse_dir', { path: path }, function (err, data) {
      if (err || !data.ok) {
        var msg  = err || data.error || 'Error';
        var hint = (path === '/home')
          ? 'PHP cannot list /home directly. Enter a known user path, e.g. /home/username.'
          : msg;
        bodyEl.innerHTML = naviHtml(hint, path === '/home' ? '/home/' : path);
        bindNaviGo();
        return;
      }
      if (data.entries.length === 0) {
        bodyEl.innerHTML = naviHtml('No subdirectories found in ' + path + '.', path);
        bindNaviGo();
        return;
      }
      var html = '<ul class="int-dir-list">';
      data.entries.forEach(function (e) {
        html += '<li class="int-dir-item" data-path="' + escHtml(e.path) + '">'
              + '<span class="int-dir-icon">' + (e.has_children ? '&#9654;' : '&#9723;') + '</span>'
              + '<span class="int-dir-name">' + escHtml(e.name) + '</span>'
              + '</li>';
      });
      html += '</ul>';
      bodyEl.innerHTML = html;
      bodyEl.querySelectorAll('.int-dir-item').forEach(function (li) {
        li.addEventListener('click', function () {
          bodyEl.querySelectorAll('.int-dir-item').forEach(function (x) { x.classList.remove('is-selected'); });
          li.classList.add('is-selected');
          selectedPath     = li.dataset.path;
          curPathEl.textContent = selectedPath;
          useBtn.disabled  = false;
        });
        li.addEventListener('dblclick', function () {
          loadDir(li.dataset.path);
        });
      });
    });
  }

  // ── Open / close modal ────────────────────────────────────────────────────
  function openModal() {
    var start = (destInput.value.trim() || '/home');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    loadDir(start);
  }

  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  browseBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  // ── Use this path ─────────────────────────────────────────────────────────
  useBtn.addEventListener('click', function () {
    if (!selectedPath) return;
    destInput.value = selectedPath;
    closeModal();
    detectSoftware(selectedPath);
  });

  // ── Software detection ────────────────────────────────────────────────────
  var detectTimer = null;
  function detectSoftware(path) {
    if (!path) { detectBox.style.display = 'none'; return; }
    detectBox.style.display    = 'block';
    detectLoad.style.display   = 'flex';
    detectResult.style.display = 'none';

    post('detect_software', { path: path }, function (err, data) {
      detectLoad.style.display   = 'none';
      detectResult.style.display = 'block';
      if (err) {
        detSoftware.textContent = 'Error';
        detVersion.textContent  = '—';
        detRoot.textContent     = path;
        detWarning.style.display = 'block';
        return;
      }
      detSoftware.textContent = data.software || 'Unknown';
      detVersion.textContent  = data.version  || 'unknown';
      detRoot.textContent     = data.root     || path;
      detWarning.style.display = (data.software === 'Unknown') ? 'block' : 'none';
      if (detHidden) detHidden.value = data.software || '';
    });
  }

  // Also trigger detection when user manually types into dest field
  destInput.addEventListener('input', function () {
    clearTimeout(detectTimer);
    var v = this.value.trim();
    if (!v) { detectBox.style.display = 'none'; return; }
    detectTimer = setTimeout(function () { detectSoftware(v); }, 600);
  });

  // Re-trigger on page load if field already has a value (e.g. after POST error)
  if (destInput.value.trim()) detectSoftware(destInput.value.trim());

  // ── Keyboard: Escape closes modal ─────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });

  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>
</body>
</html>
