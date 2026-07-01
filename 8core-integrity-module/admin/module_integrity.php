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

// Check tab state
$_intRunId        = 0;      // current run_id (0 = no persisted run loaded)
$_intRunMeta      = null;   // run row from DB
$_intRunResults   = null;   // array of result rows from DB (null = not loaded)
$_intRunFilters   = [];     // active filter values [type, severity, status, path]
$_intBulkConfirm  = null;   // bulk preview data before destructive execute
$_intCheckOrigin  = '';
$_intCheckDest    = '';
$_intDetSoftware  = '';     // detected software name (passed through forms)
$_intScanExcl     = '';     // raw textarea text for scan exclusions (repopulated on error)
$_intExclTemplates = [];   // loaded from DB for dropdown/manage UI
$_intTplManageId   = 0;    // template being edited in Manage view (0 = none)

// ── Flash message helpers ──────────────────────────────────────────────────────

function _int_flash_set(string $type, string $text): void {
    $_SESSION['8int_flash'][] = ['type' => $type, 'text' => $text];
}

function _int_flash_drain(): array {
    $msgs = $_SESSION['8int_flash'] ?? [];
    unset($_SESSION['8int_flash']);
    return $msgs;
}

// ── URL helpers ────────────────────────────────────────────────────────────────

function _int_build_check_url(int $runId, array $filters = [], string $base = 'module.php?module=8core-integrity&page=module_integrity'): string {
    $url = $base . '&tab=check';
    if ($runId > 0) $url .= '&run_id=' . $runId;
    foreach (['ft', 'fs', 'fst', 'fp'] as $k) {
        if (!empty($filters[$k])) $url .= '&' . $k . '=' . urlencode($filters[$k]);
    }
    return $url;
}

function _int_filters_from_get(): array {
    return [
        'type'     => trim($_GET['ft']  ?? ''),
        'severity' => trim($_GET['fs']  ?? ''),
        'status'   => trim($_GET['fst'] ?? ''),
        'path'     => trim($_GET['fp']  ?? ''),
    ];
}

function _int_filters_to_get(array $f): array {
    return [
        'ft'  => $f['type']     ?? '',
        'fs'  => $f['severity'] ?? '',
        'fst' => $f['status']   ?? '',
        'fp'  => $f['path']     ?? '',
    ];
}

// ── ID range parser: "1,5,10-20,33" → [1,5,10,11,...,20,33] ──────────────────

function _int_parse_id_range(string $input, int $maxId = 9999999): array {
    $ids = [];
    foreach (explode(',', $input) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (str_contains($part, '-')) {
            [$from, $to] = explode('-', $part, 2);
            $from = (int) trim($from);
            $to   = (int) trim($to);
            if ($from < 1 || $to < $from || ($to - $from) > 10000) continue;
            for ($i = $from; $i <= $to; $i++) $ids[] = $i;
        } elseif (ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }
    return array_values(array_unique(array_filter($ids, fn($id) => $id >= 1 && $id <= $maxId)));
}

/**
 * Returns ignore options for a result row's relative path.
 * Each option: ['label' => string, 'value' => 'exact:path' or 'prefix:path/'].
 * Up to 4 options: exact + up to 3 parent prefix levels.
 */
function _int_ignore_options(string $relPath): array {
    $relPath = trim($relPath, '/');
    $parts   = array_filter(explode('/', $relPath), fn($p) => $p !== '');
    $opts    = [];

    // Exact path
    $opts[] = ['label' => basename($relPath), 'value' => 'exact:' . $relPath];

    // Parent prefix levels (bottom-up, up to 3)
    for ($depth = count($parts) - 1; $depth >= 1 && count($opts) < 4; $depth--) {
        $prefix    = implode('/', array_slice($parts, 0, $depth)) . '/';
        $leafLabel = array_slice($parts, 0, $depth)[count(array_slice($parts, 0, $depth)) - 1] . '/';
        $opts[] = ['label' => 'Subtree: ' . $leafLabel, 'value' => 'prefix:' . $prefix];
    }

    return $opts;
}

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

    // Guard: POST with data but no action — almost always means nested form
    // ate the submit or a JS error swallowed the action hidden input.
    if (!$skipHandlers && !empty($_POST) && $postAction === '') {
        $_intMessages[] = [
            'type' => 'err',
            'text' => 'Invalid form submit: action was not received. '
                    . 'This usually means a form was nested inside another form. '
                    . 'Clear your browser cache and try again.',
        ];
        $skipHandlers = true;
    }

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
                    // Auto-generate repo hashes
                    $hashResult = integrity_generate_repo_hashes($pdo, $impApp, $impBranch, $impVersion, $targetDir);
                    $result['hash_result'] = $hashResult;
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
                // Delete old hashes then regenerate for the replaced repo
                $oldKey = integrity_repo_key($stored['app'], $stored['branch'], $stored['version']);
                try {
                    $pdo->prepare('DELETE FROM `scanner_integrity_repo_files` WHERE `repo_key` = ?')->execute([$oldKey]);
                } catch (PDOException $e) { /* ignore */ }
                $hashResult = integrity_generate_repo_hashes($pdo, $stored['app'], $stored['branch'], $stored['version'], $stored['dir']);
                $result['hash_result'] = $hashResult;
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

    // ── Regenerate repo hashes ─────────────────────────────────────────────────
    if ($postAction === 'regenerate_hashes') {
        $rhApp     = strtolower(trim($_POST['rh_app']     ?? ''));
        $rhBranch  = strtolower(trim($_POST['rh_branch']  ?? ''));
        $rhVersion = strtolower(trim($_POST['rh_version'] ?? ''));

        if (!integrity_valid_name($rhApp) || !integrity_valid_loose($rhBranch) || !integrity_valid_loose($rhVersion)) {
            _int_flash_set('err', 'Invalid app / branch / version for hash regeneration.');
        } else {
            $repoPath = integrity_repo_root() . '/' . $rhApp . '/' . $rhBranch . '/' . $rhVersion;
            if (!is_dir($repoPath)) {
                _int_flash_set('err', 'Repository path does not exist: ' . $repoPath);
            } else {
                $hr = integrity_generate_repo_hashes($pdo, $rhApp, $rhBranch, $rhVersion, $repoPath);
                if ($hr['ok']) {
                    _int_flash_set('ok', 'Hashes regenerated for ' . $rhApp . '/' . $rhBranch . '/' . $rhVersion
                        . ': ' . number_format($hr['files']) . ' file(s), '
                        . ($hr['errors'] > 0 ? $hr['errors'] . ' error(s).' : 'no errors.'));
                } else {
                    _int_flash_set('err', 'Hash generation failed: ' . ($hr['error'] ?? 'unknown error'));
                }
            }
        }
        header('Location: module.php?module=8core-integrity&page=module_integrity&tab=repo');
        exit;
    }

    // ── Clear integrity results ────────────────────────────────────────────────
    if ($postAction === 'clear_results') {
        $clearMode   = trim($_POST['clear_mode']   ?? '');
        $clearRunId  = (int) ($_POST['clear_run_id']  ?? 0);
        $clearDest   = trim($_POST['clear_dest']   ?? '');

        $allowedModes = ['run', 'dest', 'all'];
        if (!in_array($clearMode, $allowedModes, true)) {
            _int_flash_set('err', 'Invalid clear mode.');
        } else {
            $ok = integrity_clear_results($pdo, $clearMode, $clearRunId, $clearDest);
            if ($ok) {
                $label = match($clearMode) {
                    'run'  => 'Run #' . $clearRunId . ' results cleared.',
                    'dest' => 'All results for destination "' . $clearDest . '" cleared.',
                    'all'  => 'All integrity check results cleared.',
                };
                _int_flash_set('ok', $label);
                header('Location: module.php?module=8core-integrity&page=module_integrity&tab=check');
                exit;
            } else {
                _int_flash_set('err', 'Failed to clear results.');
            }
        }
        $redirectRunId = ($clearMode === 'run' && $clearRunId > 0) ? $clearRunId : 0;
        header('Location: ' . _int_build_check_url($redirectRunId));
        exit;
    }

    // ── Run structural check ───────────────────────────────────────────────────
    if ($postAction === 'run_structural_check') {
        $_intDetSoftware  = trim($_POST['detected_software'] ?? '');
        $_intCheckOrigin  = trim($_POST['origin_path_manual'] ?? '') ?: trim($_POST['origin_path'] ?? '');
        $_intCheckDest    = trim($_POST['dest_path'] ?? '');
        $_intScanExcl     = trim($_POST['scan_exclusions'] ?? '');

        // Store submit debug info (shown in Check tab after redirect)
        $_SESSION['8int_check_debug'] = [
            'action'     => $postAction,
            'origin'     => $_intCheckOrigin,
            'dest'       => $_intCheckDest,
            'excl_count' => $__ec = count(array_filter(explode("\n", $_intScanExcl))),
            'ts'         => date('H:i:s'),
        ];

        if ($_intCheckOrigin === '' || $_intCheckDest === '') {
            $_intMessages[] = ['type' => 'err', 'text' => 'Both origin and destination paths are required.'];
        } else {
            // Parse and normalize pre-run exclusions
            $preRunExclusions = integrity_parse_scan_exclusions($_intScanExcl, $_intCheckDest);
            // Load persistent post-run ignores from DB (exact paths)
            $dbIgnores = integrity_ignores_for($pdo, $_intCheckOrigin, $_intCheckDest);
            // Merge: pre-run exclusions (prefix/) + DB exact ignores
            $combinedIgnores = array_merge($preRunExclusions, $dbIgnores);

            // Detect if origin has a hash DB — use hash check when available
            $__originReal = rtrim(realpath($_intCheckOrigin) ?: $_intCheckOrigin, '/');
            $__parts      = array_filter(explode('/', $__originReal));
            $__partsArr   = array_values($__parts);
            $__repoRoot   = rtrim(realpath(integrity_repo_root()) ?: integrity_repo_root(), '/');
            $__rootParts  = array_filter(explode('/', $__repoRoot));
            $__rootDepth  = count($__rootParts);
            // Extract app/branch/version from path relative to repo root
            $__relParts   = array_slice($__partsArr, $__rootDepth);
            $__useHash    = false;
            $__repoKey    = '';
            if (count($__relParts) >= 3) {
                $__repoKey = integrity_repo_key($__relParts[0], $__relParts[1], $__relParts[2]);
                $__useHash = integrity_repo_has_hashes($pdo, $__repoKey) > 0;
            }

            if ($__useHash) {
                $result = integrity_hash_check($pdo, $__repoKey, $_intCheckOrigin, $_intCheckDest, $_intDetSoftware, $combinedIgnores);
            } else {
                $result = integrity_structural_check($_intCheckOrigin, $_intCheckDest, $_intDetSoftware, $combinedIgnores);
            }

            if (!$result['ok']) {
                $_intMessages[] = ['type' => 'err', 'text' => 'Check failed: ' . $result['error']];
            } else {
                $checkMode = $result['mode'] ?? 'structural';
                $summary   = $result['summary'] ?? [];
                $runId = integrity_save_run($pdo, $result['origin'], $result['dest'], $_intDetSoftware, $result['counts'], $preRunExclusions, $checkMode, $summary);
                if ($runId > 0) {
                    integrity_save_results($pdo, $runId, $result['origin'], $result['dest'], $result['findings']);
                    $modeLabel = ($checkMode === 'hash') ? 'Hash check' : 'Structural check';
                    _int_flash_set('ok', $modeLabel . ' complete. '
                        . count($result['findings']) . ' finding(s) found'
                        . (!empty($preRunExclusions) ? ' (' . count($preRunExclusions) . ' exclusion(s) applied)' : '')
                        . ($__useHash ? '' : ' (no hash index — structural only)')
                        . '.');
                    header('Location: ' . _int_build_check_url($runId));
                    exit;
                }
                // DB save failed — show in-memory
                $_intMessages[] = ['type' => 'warn', 'text' => 'Results could not be saved to database. Showing in-memory results.'];
                $_SESSION['8int_inmem']    = $result;
                $_SESSION['8int_inmem_sw'] = $_intDetSoftware;
                header('Location: module.php?module=8core-integrity&page=module_integrity&tab=check&inmem=1');
                exit;
            }
        }
    }

    // ── Single result action ───────────────────────────────────────────────────
    if ($postAction === 'action_result') {
        $runId    = (int) ($_POST['run_id']    ?? 0);
        $resultId = (int) ($_POST['result_id'] ?? 0);
        $action   = trim($_POST['result_action'] ?? '');
        $filters  = _int_filters_to_get([
            'type'     => trim($_POST['ft']  ?? ''),
            'severity' => trim($_POST['fs']  ?? ''),
            'status'   => trim($_POST['fst'] ?? ''),
            'path'     => trim($_POST['fp']  ?? ''),
        ]);

        if ($runId > 0 && $resultId > 0) {
            $rows = integrity_load_results_by_ids($pdo, $runId, [$resultId]);
            $row  = $rows[0] ?? null;

            if (!$row) {
                _int_flash_set('err', 'Result not found.');
            } elseif ($action === 'ignore') {
                // ignore_path field encodes match type: "exact:path" or "prefix:path/"
                $rawIgnorePath = trim($_POST['ignore_path'] ?? ('exact:' . $row['relative_path']));
                $colonPos      = strpos($rawIgnorePath, ':');
                $matchType     = $colonPos !== false ? substr($rawIgnorePath, 0, $colonPos) : 'exact';
                $ignPath       = $colonPos !== false ? substr($rawIgnorePath, $colonPos + 1) : $rawIgnorePath;
                // Normalize: prefix entries have trailing slash
                if ($matchType === 'prefix') {
                    $ignPath = rtrim($ignPath, '/') . '/';
                } else {
                    $ignPath = rtrim($ignPath, '/');
                }
                $ignType = str_contains($row['type'], 'MISSING') ? 'missing_path' : 'extra_path';
                integrity_add_ignore($pdo, $row['origin_path'], $row['destination_path'], $ignPath, $ignType);
                integrity_update_result_status($pdo, $runId, $resultId, 'ignored_integrity');
                $label = ($matchType === 'prefix') ? 'Subtree ignored: ' . $ignPath : 'Ignored: ' . $ignPath;
                _int_flash_set('ok', $label);
            } elseif ($action === 'trash') {
                $tr = integrity_do_trash_path($row['full_path'], $row['destination_path'], $row['relative_path']);
                if ($tr['ok']) {
                    integrity_update_result_status($pdo, $runId, $resultId, 'trashed', $tr['trash_path']);
                    _int_flash_set('ok', 'Moved to Integrity Trash: ' . $row['relative_path']);
                } elseif ($tr['needs_queue']) {
                    $qid = integrity_queue_action(
                        $pdo, $resultId, 'move_to_trash',
                        $row['full_path'], $tr['trash_path'], $row['relative_path'],
                        $_SESSION['user'] ?? ''
                    );
                    if ($qid > 0) {
                        integrity_update_result_status($pdo, $runId, $resultId, 'pending_action', 'action_id:' . $qid);
                        _int_flash_set('ok', 'Action queued for root worker (action #' . $qid . '): ' . $row['relative_path']);
                    } else {
                        _int_flash_set('err', 'Storage not ready and queue insert failed: ' . $tr['error']);
                    }
                } else {
                    $_SESSION['8int_fail_detail'][$resultId] = ['error' => $tr['error'], 'root_cmd' => ''];
                    _int_flash_set('err', 'Trash failed: ' . $tr['error']);
                }
            } elseif ($action === 'replace') {
                if ($row['type'] === 'MODIFIED_FILE') {
                    // Trash the modified dest file first, then copy clean from origin
                    $tr = integrity_do_trash_path($row['full_path'], $row['destination_path'], $row['relative_path']);
                    if (!$tr['ok'] && !$tr['needs_queue']) {
                        integrity_update_result_status($pdo, $runId, $resultId, 'failed', $tr['error']);
                        _int_flash_set('err', 'Trash (pre-replace) failed: ' . $tr['error']);
                        header('Location: ' . _int_build_check_url($runId, $filters));
                        exit;
                    }
                    if (!$tr['ok'] && $tr['needs_queue']) {
                        $qid = integrity_queue_action(
                            $pdo, $resultId, 'replace_from_origin',
                            $row['full_path'], $tr['trash_path'], $row['relative_path'],
                            $_SESSION['user'] ?? ''
                        );
                        if ($qid > 0) {
                            integrity_update_result_status($pdo, $runId, $resultId, 'pending_action', 'action_id:' . $qid);
                            _int_flash_set('ok', 'Replace queued for root worker (action #' . $qid . '): ' . $row['relative_path']);
                        } else {
                            _int_flash_set('err', 'Storage not ready and queue insert failed: ' . $tr['error']);
                        }
                        header('Location: ' . _int_build_check_url($runId, $filters));
                        exit;
                    }
                }
                $rr = integrity_do_replace_path($row['origin_path'], $row['relative_path'],
                                                $row['destination_path'], $row['relative_path']);
                if ($rr['ok']) {
                    integrity_update_result_status($pdo, $runId, $resultId, 'replaced');
                    _int_flash_set('ok', 'Replaced from origin: ' . $row['relative_path']);
                } else {
                    integrity_update_result_status($pdo, $runId, $resultId, 'failed', $rr['error']);
                    _int_flash_set('err', 'Replace failed: ' . $rr['error']);
                }
            } elseif ($action === 'mark_reviewed') {
                integrity_update_result_status($pdo, $runId, $resultId, 'reviewed');
                _int_flash_set('ok', 'Marked as reviewed: ' . $row['relative_path']);
            } elseif ($action === 'cancel_pending_action') {
                // Cancel a queued action and reset result status to 'new'
                if ($row['status'] === 'pending_action') {
                    // Extract action_id from note field
                    $actionId = 0;
                    if (preg_match('/^action_id:(\d+)$/', $row['note'] ?? '', $m)) {
                        $actionId = (int) $m[1];
                    }
                    $cancelled = $actionId > 0 && integrity_cancel_action($pdo, $actionId);
                    integrity_update_result_status($pdo, $runId, $resultId, 'new', '');
                    _int_flash_set('ok', 'Pending action cancelled. Result reset to new.' . ($cancelled ? '' : ' (action already executed)'));
                } else {
                    _int_flash_set('err', 'No pending action to cancel.');
                }
            } elseif ($action === 'reset_status') {
                // Reset failed/reviewed/pending_action rows back to 'new' for retry
                if (in_array($row['status'], ['failed', 'reviewed', 'pending_action'], true)) {
                    integrity_update_result_status($pdo, $runId, $resultId, 'new', '');
                    unset($_SESSION['8int_fail_detail'][$resultId]);
                    _int_flash_set('ok', 'Reset to new: ' . $row['relative_path']);
                } else {
                    _int_flash_set('err', 'Only failed, reviewed, or pending_action rows can be reset.');
                }
            }
        }
        header('Location: ' . _int_build_check_url($runId, $filters));
        exit;
    }

    // ── Bulk preview (render confirm screen) ───────────────────────────────────
    if ($postAction === 'bulk_preview') {
        $runId      = (int) ($_POST['run_id']      ?? 0);
        $bulkAction = trim($_POST['bulk_action']   ?? '');
        $selectMode = trim($_POST['select_mode']   ?? 'checked'); // checked | range
        $idRange    = trim($_POST['id_range']      ?? '');
        $checkedIds = array_map('intval', (array) ($_POST['result_ids'] ?? []));

        $filters = _int_filters_to_get([
            'type'     => trim($_POST['ft']  ?? ''),
            'severity' => trim($_POST['fs']  ?? ''),
            'status'   => trim($_POST['fst'] ?? ''),
            'path'     => trim($_POST['fp']  ?? ''),
        ]);

        $ids = ($selectMode === 'range')
            ? _int_parse_id_range($idRange)
            : $checkedIds;

        $allowedBulk = ['ignore', 'trash', 'replace_missing', 'replace_modified', 'mark_reviewed'];
        if (!in_array($bulkAction, $allowedBulk, true) || empty($ids) || $runId <= 0) {
            _int_flash_set('err', 'No items selected or invalid bulk action.');
            header('Location: ' . _int_build_check_url($runId, $filters));
            exit;
        }

        $rows = integrity_load_results_by_ids($pdo, $runId, $ids);
        if (empty($rows)) {
            _int_flash_set('err', 'None of the selected IDs exist in this run.');
            header('Location: ' . _int_build_check_url($runId, $filters));
            exit;
        }

        // Filter to eligible rows per action
        if ($bulkAction === 'trash') {
            $eligible   = array_filter($rows, fn($r) => str_starts_with($r['type'], 'EXTRA_') && $r['status'] === 'new');
            $skipped    = count($rows) - count($eligible);
            $skipReason = 'only EXTRA_* rows with status "new" can be trashed';
        } elseif ($bulkAction === 'replace_missing') {
            $eligible   = array_filter($rows, fn($r) => str_starts_with($r['type'], 'MISSING_') && $r['status'] === 'new');
            $skipped    = count($rows) - count($eligible);
            $skipReason = 'only MISSING_* rows with status "new" can be replaced';
        } elseif ($bulkAction === 'replace_modified') {
            $eligible   = array_filter($rows, fn($r) => $r['type'] === 'MODIFIED_FILE' && $r['status'] === 'new');
            $skipped    = count($rows) - count($eligible);
            $skipReason = 'only MODIFIED_FILE rows with status "new" can be replaced';
        } elseif ($bulkAction === 'mark_reviewed') {
            $eligible   = array_filter($rows, fn($r) => $r['status'] === 'new');
            $skipped    = count($rows) - count($eligible);
            $skipReason = 'only rows with status "new" can be marked reviewed';
        } else { // ignore
            $eligible   = array_filter($rows, fn($r) => $r['status'] === 'new');
            $skipped    = count($rows) - count($eligible);
            $skipReason = 'only rows with status "new" can be ignored';
        }
        $eligible = array_values($eligible);

        if (empty($eligible)) {
            $msg = 'No eligible items for "' . $bulkAction . '" among selected IDs';
            if ($skipped > 0) $msg .= ' (' . $skipReason . ')';
            _int_flash_set('err', $msg . '.');
            header('Location: ' . _int_build_check_url($runId, $filters));
            exit;
        }

        $_intBulkConfirm = [
            'action'   => $bulkAction,
            'run_id'   => $runId,
            'rows'     => $eligible,
            'skipped'  => $skipped,
            'skip_reason' => $skipReason,
            'filters'  => $filters,
        ];
        // Fall through to render (no redirect — confirm screen rendered in-page)
    }

    // ── Bulk execute ───────────────────────────────────────────────────────────
    if ($postAction === 'bulk_execute') {
        $runId      = (int) ($_POST['run_id']    ?? 0);
        $bulkAction = trim($_POST['bulk_action'] ?? '');
        $ids        = array_map('intval', (array) ($_POST['confirmed_ids'] ?? []));
        $filters    = _int_filters_to_get([
            'type'     => trim($_POST['ft']  ?? ''),
            'severity' => trim($_POST['fs']  ?? ''),
            'status'   => trim($_POST['fst'] ?? ''),
            'path'     => trim($_POST['fp']  ?? ''),
        ]);

        if ($runId > 0 && !empty($ids) && in_array($bulkAction, ['ignore', 'trash', 'replace_missing', 'replace_modified', 'mark_reviewed'], true)) {
            $rows  = integrity_load_results_by_ids($pdo, $runId, $ids);
            $ok    = 0;
            $fail  = 0;

            foreach ($rows as $row) {
                if ($row['status'] !== 'new') continue;

                if ($bulkAction === 'ignore') {
                    integrity_add_ignore($pdo, $row['origin_path'], $row['destination_path'],
                                         $row['relative_path'],
                                         str_contains($row['type'], 'MISSING') ? 'missing_path' : 'extra_path');
                    integrity_update_result_status($pdo, $runId, (int) $row['id'], 'ignored_integrity');
                    $ok++;
                } elseif ($bulkAction === 'trash' && str_starts_with($row['type'], 'EXTRA_')) {
                    $tr = integrity_do_trash_path($row['full_path'], $row['destination_path'], $row['relative_path']);
                    if ($tr['ok']) {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'trashed', $tr['trash_path']);
                        $ok++;
                    } elseif ($tr['needs_queue']) {
                        $qid = integrity_queue_action($pdo, (int)$row['id'], 'move_to_trash',
                            $row['full_path'], $tr['trash_path'], $row['relative_path'], $_SESSION['user'] ?? '');
                        if ($qid > 0) {
                            integrity_update_result_status($pdo, $runId, (int)$row['id'], 'pending_action', 'action_id:' . $qid);
                            $ok++;
                        } else {
                            $fail++;
                        }
                    } else {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'failed', $tr['error']);
                        $fail++;
                    }
                } elseif ($bulkAction === 'replace_missing' && str_starts_with($row['type'], 'MISSING_')) {
                    $rr = integrity_do_replace_path($row['origin_path'], $row['relative_path'],
                                                    $row['destination_path'], $row['relative_path']);
                    if ($rr['ok']) {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'replaced');
                        $ok++;
                    } else {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'failed', $rr['error']);
                        $fail++;
                    }
                } elseif ($bulkAction === 'replace_modified' && $row['type'] === 'MODIFIED_FILE') {
                    $tr = integrity_do_trash_path($row['full_path'], $row['destination_path'], $row['relative_path']);
                    if (!$tr['ok'] && $tr['needs_queue']) {
                        $qid = integrity_queue_action($pdo, (int)$row['id'], 'replace_from_origin',
                            $row['full_path'], $tr['trash_path'], $row['relative_path'], $_SESSION['user'] ?? '');
                        if ($qid > 0) {
                            integrity_update_result_status($pdo, $runId, (int)$row['id'], 'pending_action', 'action_id:' . $qid);
                            $ok++;
                        } else {
                            $fail++;
                        }
                        continue;
                    }
                    if (!$tr['ok']) {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'failed', $tr['error']);
                        $fail++;
                        continue;
                    }
                    $rr = integrity_do_replace_path($row['origin_path'], $row['relative_path'],
                                                    $row['destination_path'], $row['relative_path']);
                    if ($rr['ok']) {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'replaced');
                        $ok++;
                    } else {
                        integrity_update_result_status($pdo, $runId, (int) $row['id'], 'failed', $rr['error']);
                        $fail++;
                    }
                } elseif ($bulkAction === 'mark_reviewed') {
                    integrity_update_result_status($pdo, $runId, (int) $row['id'], 'reviewed');
                    $ok++;
                }
            }

            $label = match($bulkAction) {
                'ignore'          => 'Bulk ignore',
                'trash'           => 'Bulk trash',
                'replace_missing' => 'Bulk replace missing',
                'replace_modified'=> 'Bulk replace modified',
                'mark_reviewed'   => 'Bulk mark reviewed',
                default           => 'Bulk action',
            };
            $msg = "{$label}: {$ok} succeeded";
            if ($fail > 0) $msg .= ", {$fail} failed";
            _int_flash_set($fail > 0 ? 'warn' : 'ok', $msg . '.');
        }

        header('Location: ' . _int_build_check_url($runId, $filters));
        exit;
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

    // ── Save exclusion template ────────────────────────────────────────────────
    if ($postAction === 'save_excl_template') {
        $tplName  = trim($_POST['tpl_name']  ?? '');
        $tplDesc  = trim($_POST['tpl_desc']  ?? '');
        $tplCms   = trim($_POST['tpl_cms']   ?? '');
        $tplPaths = trim($_POST['tpl_paths'] ?? '');

        if ($tplName === '') {
            _int_flash_set('err', 'Template name is required.');
        } elseif ($tplPaths === '') {
            _int_flash_set('err', 'Template must have at least one path.');
        } else {
            // Reuse integrity_parse_scan_exclusions without a destRoot (relative paths only)
            $parsed = [];
            foreach (explode("\n", str_replace("\r", "\n", $tplPaths)) as $line) {
                $line = trim($line, " \t/\r");
                if ($line === '' || str_contains($line, '..') || str_contains($line, "\0")) continue;
                $parsed[] = $line . '/';
            }
            $parsed = array_values(array_unique($parsed));
            if (empty($parsed)) {
                _int_flash_set('err', 'No valid paths found after normalization.');
            } else {
                $newId = integrity_save_exclusion_template($pdo, $tplName, $tplDesc, $tplCms, $parsed);
                if ($newId > 0) {
                    _int_flash_set('ok', 'Template "' . $tplName . '" saved with ' . count($parsed) . ' path(s).');
                } else {
                    _int_flash_set('err', 'Failed to save template.');
                }
            }
        }
        header('Location: module.php?module=8core-integrity&page=module_integrity&tab=check');
        exit;
    }

    // ── Update exclusion template (from Manage view) ───────────────────────────
    if ($postAction === 'update_excl_template') {
        $tplId    = (int) ($_POST['tpl_id']    ?? 0);
        $tplName  = trim($_POST['tpl_name']    ?? '');
        $tplDesc  = trim($_POST['tpl_desc']    ?? '');
        $tplCms   = trim($_POST['tpl_cms']     ?? '');
        $tplPaths = trim($_POST['tpl_paths']   ?? '');

        if ($tplId <= 0 || $tplName === '') {
            _int_flash_set('err', 'Invalid template or missing name.');
        } else {
            $parsed = [];
            foreach (explode("\n", str_replace("\r", "\n", $tplPaths)) as $line) {
                $line = trim($line, " \t/\r");
                if ($line === '' || str_contains($line, '..') || str_contains($line, "\0")) continue;
                $parsed[] = $line . '/';
            }
            $parsed = array_values(array_unique($parsed));
            $ok = integrity_update_exclusion_template($pdo, $tplId, $tplName, $tplDesc, $tplCms, $parsed);
            _int_flash_set($ok ? 'ok' : 'err', $ok ? 'Template updated.' : 'Failed to update template.');
        }
        header('Location: module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1');
        exit;
    }

    // ── Toggle / delete exclusion template ────────────────────────────────────
    if ($postAction === 'toggle_excl_template') {
        $tplId  = (int) ($_POST['tpl_id']    ?? 0);
        $active = (int) ($_POST['tpl_active'] ?? 1);
        if ($tplId > 0) {
            $ok = integrity_toggle_exclusion_template($pdo, $tplId, (bool)$active);
            _int_flash_set($ok ? 'ok' : 'err', $ok ? ($active ? 'Template enabled.' : 'Template disabled.') : 'Failed.');
        }
        header('Location: module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1');
        exit;
    }

    if ($postAction === 'delete_excl_template') {
        $tplId = (int) ($_POST['tpl_id'] ?? 0);
        if ($tplId > 0) {
            $ok = integrity_delete_exclusion_template($pdo, $tplId);
            _int_flash_set($ok ? 'ok' : 'err', $ok ? 'Template deleted.' : 'Failed to delete template.');
        }
        header('Location: module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1');
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
$_intWebUser = integrity_php_user();
$_intRootCmd = str_replace('{webuser}', $_intWebUser, $_intRootCmd ?? '');

// Storage status
$_intStorageStatus  = integrity_storage_status();
$_intStorageReady   = integrity_storage_ready();
$_intStorageSetupCmd = integrity_storage_setup_cmd();

// All existing root-level dirs for dropdowns
$_intAllAppKeys = [];
foreach ($_intGroups as $g) {
    if (is_dir($_intRepoRoot . '/' . $g['key'])) $_intAllAppKeys[] = $g['key'];
}
foreach ($_intExtraApps as $ea) {
    $_intAllAppKeys[] = $ea;
}

// Load exclusion templates (all, including inactive, for Manage; active-only for dropdown)
$_intExclTemplates    = integrity_load_exclusion_templates($pdo, false); // all for manage
$_intExclTplsActive   = array_values(array_filter($_intExclTemplates, fn($t) => $t['active']));
// Template being edited in Manage view
$_intTplManageId      = (int) ($_GET['tpl_edit'] ?? 0);
$_intTplManageData    = ($_intTplManageId > 0) ? integrity_load_exclusion_template($pdo, $_intTplManageId) : null;
// Whether to scroll Repo tab to template section
$_intShowTplSection   = isset($_GET['tpl_section']);
// Whether to show the Create new template form
$_intTplNew           = isset($_GET['tpl_new']) && !$_intTplManageData;

// Drain flash messages
foreach (_int_flash_drain() as $fm) {
    $_intMessages[] = $fm;
}

// Drain per-result failure details (set by failed trash/replace actions)
$_intFailDetails = $_SESSION['8int_fail_detail'] ?? [];
// Do NOT unset here — keep until reset so View error survives filter navigation

// Drain check submit debug (set by run_structural_check handler; shown once then cleared)
$_intCheckDebug = $_SESSION['8int_check_debug'] ?? null;
unset($_SESSION['8int_check_debug']);

// In-memory result fallback (no DB)
if (isset($_GET['inmem']) && isset($_SESSION['8int_inmem'])) {
    $_intCheckResults = $_SESSION['8int_inmem'];
    $_intDetSoftware  = $_SESSION['8int_inmem_sw'] ?? '';
    $_intScanExcl     = $_SESSION['8int_inmem_excl'] ?? '';
    unset($_SESSION['8int_inmem'], $_SESSION['8int_inmem_sw'], $_SESSION['8int_inmem_excl']);
}

// Load persisted run from GET run_id
if (!isset($_intBulkConfirm) || $_intBulkConfirm === null) {
    $__getRunId = (int) ($_GET['run_id'] ?? 0);
    if ($__getRunId > 0) {
        $_intRunId      = $__getRunId;
        $_intRunMeta    = integrity_load_run($pdo, $_intRunId);
        $_intRunFilters = _int_filters_from_get();
        if ($_intRunMeta) {
            $_intRunResults = integrity_load_results($pdo, $_intRunId, $_intRunFilters);
            $_intCheckOrigin  = $_intRunMeta['origin_path'];
            $_intCheckDest    = $_intRunMeta['destination_path'];
            $_intDetSoftware  = $_intRunMeta['software'] ?? '';
        }
    }
}

// ── Tab routing ────────────────────────────────────────────────────────────────
$_intTabBase    = 'module.php?module=8core-integrity&page=module_integrity';
$_rawTab        = trim($_GET['tab'] ?? '');
// tpl_section always opens the repo tab (template management section)
if ($_intShowTplSection) $_rawTab = 'repo';
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

/* ── Run info bar ── */
.int-run-bar { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:10px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; margin-bottom:14px; font-size:12px; }
.int-run-bar-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:2px; }
.int-run-bar-val { font-family:var(--font-mono,monospace); color:var(--text); word-break:break-all; font-size:12px; }
.int-run-bar-item { display:flex; flex-direction:column; min-width:0; }
.int-run-bar-sep { border-left:1px solid var(--border); height:28px; align-self:center; }
.int-run-bar-actions { margin-left:auto; display:flex; gap:6px; }

/* ── Filter bar ── */
.int-filter-bar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px; padding:10px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:8px; margin-bottom:14px; }
.int-filter-bar label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); display:block; margin-bottom:3px; }
.int-filter-bar select,
.int-filter-bar input[type=text] { padding:6px 9px; background:#fff; border:1px solid var(--border); border-radius:5px; font-size:12px; color:var(--text); outline:none; transition:border-color .12s; }
.int-filter-bar select:focus,
.int-filter-bar input[type=text]:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.int-filter-bar input[type=text] { width:160px; }
.int-filter-reset { font-size:11px; color:#64748b; text-decoration:none; padding:5px 8px; border:1px solid var(--border); border-radius:5px; background:none; cursor:pointer; align-self:flex-end; margin-bottom:1px; transition:background .12s; }
.int-filter-reset:hover { background:var(--surface2); }

/* ── Bulk action bar ── */
.int-bulk-bar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px; padding:10px 14px; background:#f8fafc; border:1px solid var(--border); border-radius:8px; margin-bottom:10px; }
.int-bulk-bar label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); display:block; margin-bottom:3px; }
.int-bulk-bar select { padding:6px 9px; background:#fff; border:1px solid var(--border); border-radius:5px; font-size:12px; color:var(--text); outline:none; }
.int-bulk-counter { font-size:12px; color:var(--text-muted); align-self:flex-end; margin-bottom:1px; }
.int-bulk-counter strong { color:var(--text); }
.int-bulk-id-group { display:flex; flex-direction:column; }
.int-bulk-id-input { padding:6px 9px; background:#fff; border:1px solid var(--border); border-radius:5px; font-size:12px; color:var(--text); width:200px; outline:none; }
.int-bulk-id-hint { font-size:10px; color:var(--text-muted); margin-top:3px; }

/* ── Selection helpers ── */
.int-sel-bar { display:flex; gap:6px; margin-bottom:6px; }
.int-sel-btn { font-size:11px; padding:4px 9px; border:1px solid var(--border); background:none; border-radius:5px; cursor:pointer; color:var(--text-muted); transition:background .12s, color .12s; }
.int-sel-btn:hover { background:var(--surface2); color:var(--text); }

/* ── Status badges ── */
.int-status-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; letter-spacing:.04em; white-space:nowrap; }
.int-status-badge.new               { background:#f1f5f9; color:#475569; }
.int-status-badge.ignored_integrity { background:#fef3c7; color:#92400e; }
.int-status-badge.trashed           { background:#fee2e2; color:#dc2626; }
.int-status-badge.replaced          { background:#dcfce7; color:#166534; }
.int-status-badge.failed            { background:#fce7f3; color:#9d174d; }

/* ── Row action buttons ── */
.int-row-actions { display:flex; gap:5px; flex-wrap:wrap; }
.int-btn-act { background:none; border:1px solid var(--border); border-radius:5px; padding:3px 8px; font-size:11px; color:var(--text-muted); cursor:pointer; white-space:nowrap; transition:background .12s, color .12s, border-color .12s; }
.int-btn-act:hover { background:var(--surface2); color:var(--text); }
.int-btn-act.act-ignore:hover  { background:#fef3c7; border-color:#d97706; color:#92400e; }
.int-btn-act.act-trash:hover   { background:#fee2e2; border-color:#dc2626; color:#dc2626; }
.int-btn-act.act-replace:hover { background:#dcfce7; border-color:#16a34a; color:#166534; }
.int-btn-act:disabled { opacity:.4; cursor:default; }

/* ── Bulk confirm screen ── */
.int-bulk-confirm { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:16px 20px; margin-bottom:16px; }
.int-bulk-confirm-title { font-size:13px; font-weight:700; color:#92400e; margin:0 0 10px; }
.int-bulk-confirm-list { max-height:220px; overflow-y:auto; border:1px solid #fde68a; border-radius:6px; background:#fff; margin-bottom:14px; }
.int-bulk-confirm-list table { width:100%; border-collapse:collapse; font-size:12px; }
.int-bulk-confirm-list th { padding:7px 10px; border-bottom:1px solid #fde68a; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#92400e; background:#fffbeb; }
.int-bulk-confirm-list td { padding:6px 10px; border-bottom:1px solid #fef3c7; vertical-align:middle; font-family:var(--font-mono,monospace); }
.int-bulk-confirm-list tr:last-child td { border-bottom:none; }
.int-bulk-confirm-actions { display:flex; gap:10px; }

/* ── Results table enhancements ── */
.int-results-table th.col-cb { width:30px; }
.int-results-table th.col-id { width:48px; }
.int-results-table td { vertical-align:middle; }
.int-results-table input[type=checkbox] { cursor:pointer; width:14px; height:14px; }

/* ── Check results (kept from v0.5.0) ── */
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
.int-results-table tr.is-actioned td { opacity:.55; }
.int-sev-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; letter-spacing:.04em; white-space:nowrap; }
.int-sev-badge.suspicious { background:#fee2e2; color:#dc2626; }
.int-sev-badge.warning    { background:#fff7ed; color:#d97706; }
.int-sev-badge.info       { background:#eff6ff; color:#2563eb; }
.int-result-type { font-family:var(--font-mono,monospace); font-size:11px; font-weight:700; color:var(--text); white-space:nowrap; }
.int-result-rel  { font-family:var(--font-mono,monospace); font-size:12px; color:var(--text); word-break:break-all; }
.int-result-full { font-family:var(--font-mono,monospace); font-size:10px; color:var(--text-muted); word-break:break-all; margin-top:2px; }
.int-truncated-note { padding:10px 14px; font-size:11px; color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; margin-top:12px; }
.int-no-findings { padding:20px 16px; font-size:13px; color:#16a34a; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-weight:600; margin-top:14px; }
.int-check-context { display:flex; flex-wrap:wrap; gap:16px; padding:10px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:7px; margin-bottom:14px; font-size:11px; }
.int-check-context-item { display:flex; flex-direction:column; gap:2px; min-width:0; }
.int-check-context-label { font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
.int-check-context-val { font-family:var(--font-mono,monospace); color:var(--text); word-break:break-all; }

/* ── Scan exclusions section ── */
.int-excl-section { margin-top:14px; padding:14px 16px; background:#f8fafc; border:1px solid var(--border); border-radius:8px; }
.int-excl-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:8px; display:flex; align-items:center; gap:8px; }
.int-excl-section-title span { font-size:10px; font-weight:400; letter-spacing:0; text-transform:none; color:#64748b; }

/* ── Template toolbar ── */
.int-excl-tpl-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:8px; padding:8px 10px; background:#fff; border:1px solid var(--border); border-radius:6px; }
.int-excl-tpl-bar label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; }
.int-excl-tpl-select { padding:5px 8px; font-size:12px; border:1px solid var(--border); border-radius:5px; background:#fff; color:var(--text); outline:none; flex:1; min-width:160px; max-width:320px; }
.int-excl-tpl-select:focus { border-color:#2563eb; }
.int-excl-tpl-sep { border-left:1px solid var(--border); height:20px; align-self:center; }

/* ── Save-as-template inline form ── */
.int-excl-save-form { margin-top:10px; padding:12px 14px; background:#fff; border:1px dashed var(--border); border-radius:7px; display:none; }
.int-excl-save-form.is-open { display:block; }
.int-excl-save-form-title { font-size:11px; font-weight:700; color:var(--text); margin-bottom:10px; }
.int-excl-save-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.int-excl-save-field { display:flex; flex-direction:column; gap:3px; }
.int-excl-save-field label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
.int-excl-save-field input[type=text] { padding:6px 9px; border:1px solid var(--border); border-radius:5px; font-size:12px; background:#fff; color:var(--text); width:180px; outline:none; }
.int-excl-save-field input[type=text]:focus { border-color:#2563eb; }

/* ── Template manage table ── */
.int-tpl-table { width:100%; border-collapse:collapse; font-size:12px; }
.int-tpl-table th { text-align:left; padding:7px 12px; border-bottom:2px solid var(--border); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); background:var(--surface2); }
.int-tpl-table td { padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.int-tpl-table tr:last-child td { border-bottom:none; }
.int-tpl-paths-preview { font-family:var(--font-mono,monospace); font-size:11px; color:var(--text-muted); max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.int-tpl-active-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; }
.int-tpl-active-badge.on  { background:#dcfce7; color:#166534; }
.int-tpl-active-badge.off { background:#f1f5f9; color:#64748b; }

/* ── Edit template form ── */
.int-tpl-edit-box { background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:16px 18px; margin-bottom:16px; }
.int-tpl-edit-title { font-size:13px; font-weight:700; color:var(--text); margin-bottom:12px; }
.int-tpl-edit-grid { display:grid; grid-template-columns:1fr 1fr 120px; gap:10px; margin-bottom:10px; }
.int-tpl-edit-grid label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); display:block; margin-bottom:3px; }
.int-tpl-edit-grid input[type=text] { width:100%; box-sizing:border-box; padding:7px 9px; border:1px solid var(--border); border-radius:5px; font-size:12px; color:var(--text); background:#fff; outline:none; }
.int-tpl-edit-grid input[type=text]:focus { border-color:#2563eb; }
.int-tpl-edit-paths { width:100%; box-sizing:border-box; padding:8px 10px; font-family:var(--font-mono,monospace); font-size:12px; border:1px solid var(--border); border-radius:6px; color:var(--text); background:#fff; resize:vertical; min-height:160px; outline:none; margin-bottom:10px; }
.int-tpl-edit-paths:focus { border-color:#2563eb; }
.int-excl-textarea { width:100%; box-sizing:border-box; padding:9px 11px; font-family:var(--font-mono,monospace); font-size:12px; background:#fff; border:1px solid var(--border); border-radius:6px; color:var(--text); resize:vertical; min-height:88px; outline:none; transition:border-color .13s; }
.int-excl-textarea:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.int-excl-hint { font-size:11px; color:var(--text-muted); margin-top:5px; line-height:1.5; }
.int-excl-hint code { font-family:var(--font-mono,monospace); background:var(--surface2); border:1px solid var(--border); border-radius:3px; padding:1px 5px; }

/* ── Ignore select+button ── */
.int-ignore-combo { display:flex; gap:4px; align-items:center; }
.int-ignore-select { padding:3px 6px; font-size:11px; border:1px solid var(--border); border-radius:5px; background:#fff; color:var(--text); outline:none; max-width:200px; }
.int-ignore-select:focus { border-color:#d97706; }

/* ── Run exclusions tag ── */
.int-excl-tag { display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:10px; font-size:10px; font-weight:600; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-family:var(--font-mono,monospace); }

/* ── Check mode badge ── */
.int-mode-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; letter-spacing:.04em; white-space:nowrap; }
.int-mode-badge.is-hash       { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.int-mode-badge.is-structural { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }

/* ── Hash columns ── */
.int-hash-cell { font-family:var(--font-mono,monospace); font-size:10px; white-space:nowrap; }
.int-hash-short { cursor:default; border-bottom:1px dashed var(--border); }
.int-hash-match  { color:#16a34a; }
.int-hash-differ { color:#dc2626; font-weight:700; }
.int-hash-none   { color:#94a3b8; }

/* ── Status badges extended ── */
.int-status-badge.reviewed       { background:#eff6ff; color:#1d4ed8; }
.int-status-badge.pending_action { background:#fef9c3; color:#713f12; border:1px solid #fde047; }

/* ── MODIFIED_FILE row highlight ── */
.int-results-table tr.type-modified td { background:rgba(220,38,38,.06); }

/* ── Failed row detail panel ── */
.int-fail-detail { margin-top:4px; font-size:11px; }
.int-fail-msg    { color:#dc2626; font-family:var(--font-mono,monospace); word-break:break-all; }
.int-fail-rootcmd { margin-top:5px; background:#fffbeb; border:1px solid #fde68a; border-radius:5px; padding:6px 10px; }
.int-fail-rootcmd-label { font-size:10px; font-weight:700; color:#92400e; margin-bottom:4px; }
.int-fail-rootcmd pre { margin:0; font-family:var(--font-mono,monospace); font-size:11px; color:#166534; background:#dcfce7; border-radius:4px; padding:6px 8px; white-space:pre-wrap; word-break:break-all; line-height:1.55; }

/* ── Summary counters in run bar ── */
.int-summary-counters { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:3px; }
.int-summary-counter  { font-size:11px; color:var(--text-muted); }
.int-summary-counter strong { color:var(--text); }

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
      <span style="font-size:12px;color:var(--text-muted);">v0.9.0</span>
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
            <?php if (!empty($s['hash_result'])): $hr = $s['hash_result']; ?>
            <div class="int-success-stat">
              <div class="int-success-stat-label">Hashes generated</div>
              <div class="int-success-stat-value" style="<?= $hr['ok'] ? 'color:#16a34a' : 'color:#dc2626' ?>">
                <?= $hr['ok'] ? number_format($hr['files']) : 'Error' ?>
              </div>
            </div>
            <?php if (!$hr['ok']): ?>
            <div style="width:100%;margin-top:8px;font-size:11px;color:#dc2626;background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;padding:6px 10px;">
              Hash generation failed: <?= h($hr['error'] ?? 'unknown error') ?>
            </div>
            <?php elseif (!empty($hr['errors']) && $hr['errors'] > 0): ?>
            <div style="width:100%;margin-top:8px;font-size:11px;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:5px;padding:6px 10px;">
              <?= (int)$hr['errors'] ?> file(s) could not be hashed (permission error or unreadable).
            </div>
            <?php endif; ?>
            <?php endif; ?>
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
    <!-- ── Section: Module Storage Status ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="int-section">
      <div class="int-section-header">
        <h3>Module Storage Status</h3>
        <?php if ($_intStorageReady): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#16a34a;font-weight:600;">
          <span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
          Storage ready
        </span>
        <?php else: ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#dc2626;font-weight:600;">
          <span style="width:8px;height:8px;border-radius:50%;background:#dc2626;display:inline-block;"></span>
          Setup required
        </span>
        <?php endif; ?>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <p style="margin:0 0 12px;font-size:12px;color:var(--text-muted);">
          These directories must exist and be writable by the PHP process user
          (<code><?= h($_intWebUser) ?></code>) before trash and queue actions work.
        </p>
        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;">
          <thead>
            <tr style="border-bottom:1px solid var(--border);">
              <th style="text-align:left;padding:5px 8px;color:var(--text-muted);font-weight:600;">Directory</th>
              <th style="text-align:center;padding:5px 8px;color:var(--text-muted);font-weight:600;">Exists</th>
              <th style="text-align:center;padding:5px 8px;color:var(--text-muted);font-weight:600;">Writable</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($_intStorageStatus as $__sd): ?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:5px 8px;font-family:monospace;"><?= h($__sd['path']) ?></td>
              <td style="text-align:center;padding:5px 8px;">
                <?php if ($__sd['exists']): ?>
                <span style="color:#16a34a;font-weight:700;">&#10003;</span>
                <?php else: ?>
                <span style="color:#dc2626;font-weight:700;">&#10007;</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;padding:5px 8px;">
                <?php if ($__sd['writable']): ?>
                <span style="color:#16a34a;font-weight:700;">&#10003;</span>
                <?php elseif ($__sd['exists']): ?>
                <span style="color:#f59e0b;font-weight:700;" title="Directory exists but is not writable by <?= h($_intWebUser) ?>">&#9888;</span>
                <?php else: ?>
                <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if (!$_intStorageReady): ?>
        <div style="background:#fefce8;border:1px solid #fde047;border-radius:7px;padding:12px 14px;margin-bottom:4px;">
          <div style="font-size:11px;font-weight:700;color:#713f12;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">
            One-time setup — run as root or via sudo:
          </div>
          <pre style="margin:0;font-size:11px;line-height:1.7;color:#1e293b;background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:10px 12px;overflow-x:auto;"><?= h($_intStorageSetupCmd) ?></pre>
          <div style="margin-top:8px;font-size:11px;color:#92400e;">
            After running the command, refresh this page to verify the status.
          </div>
        </div>
        <?php else: ?>
        <p style="margin:0;font-size:12px;color:var(--text-muted);">
          Trash and queue operations will work without manual server intervention.
          Actions that cannot be executed by the PHP user will be queued and processed by the root worker.
        </p>
        <?php endif; ?>
      </div>
    </div>

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

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Imported Repositories & Hash Index ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <?php if (!empty($_intAllImported)): ?>
    <div class="int-section">
      <div class="int-section-header">
        <h3>Imported Repositories &amp; Hash Index</h3>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <p style="margin:0 0 14px;font-size:12px;color:var(--text-muted);">
          Hashes are generated automatically after each ZIP import. Use <strong>Regenerate hashes</strong> to rebuild the index after a manual file change or if the initial generation failed.
        </p>
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead>
            <tr>
              <th style="text-align:left;padding:7px 12px;border-bottom:2px solid var(--border);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--surface2);">Repository</th>
              <th style="text-align:left;padding:7px 12px;border-bottom:2px solid var(--border);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--surface2);">Hash index</th>
              <th style="padding:7px 12px;border-bottom:2px solid var(--border);background:var(--surface2);"></th>
            </tr>
          </thead>
          <tbody>
            <?php
            $__repoRootReal = rtrim(realpath($_intRepoRoot) ?: $_intRepoRoot, '/');
            foreach ($_intAllImported as $__imp):
                $__impPath  = rtrim($__imp['path'], '/');
                $__impRel   = ltrim(substr($__impPath, strlen($__repoRootReal)), '/');
                $__impParts = explode('/', $__impRel);
                if (count($__impParts) < 3) continue;
                [$__impApp, $__impBranch, $__impVer] = $__impParts;
                $__rk       = integrity_repo_key($__impApp, $__impBranch, $__impVer);
                $__hcount   = integrity_repo_has_hashes($pdo, $__rk);
            ?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:8px 12px;font-family:var(--font-mono,monospace);"><?= h($__imp['label']) ?></td>
              <td style="padding:8px 12px;">
                <?php if ($__hcount > 0): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;">
                  <span style="color:#16a34a;font-size:12px;font-weight:700;">&#10003;</span>
                  <span style="font-size:12px;color:var(--text);"><?= number_format($__hcount) ?> files indexed</span>
                </span>
                <?php else: ?>
                <span style="color:#dc2626;font-size:12px;">No hash index</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px 12px;text-align:right;">
                <form method="post" action="module.php?module=8core-integrity&page=module_integrity" style="display:inline">
                  <input type="hidden" name="action"     value="regenerate_hashes">
                  <input type="hidden" name="rh_app"     value="<?= h($__impApp) ?>">
                  <input type="hidden" name="rh_branch"  value="<?= h($__impBranch) ?>">
                  <input type="hidden" name="rh_version" value="<?= h($__impVer) ?>">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost" style="font-size:11px;padding:4px 10px;"
                          onclick="return confirm('Regenerate hashes for <?= h(addslashes($__imp['label'])) ?>? Existing index will be replaced.')">
                    Regenerate hashes
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- ── Section: Exclusion Templates ── -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="int-section" id="int-tpl-section"<?= $_intShowTplSection ? '' : '' ?>>
      <div class="int-section-header" style="justify-content:space-between;">
        <h3>Exclusion Templates</h3>
        <?php if ($_intTplManageData || $_intTplNew): ?>
        <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1"
           class="btn btn-ghost" style="font-size:11px;padding:4px 10px;">&larr; Back to list</a>
        <?php else: ?>
        <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1&tpl_new=1"
           class="btn btn-primary" style="font-size:11px;padding:4px 10px;">+ Add new template</a>
        <?php endif; ?>
      </div>
      <hr class="int-divider">
      <div class="int-body">
        <p style="margin:0 0 14px;font-size:12px;color:var(--text-muted);">
          Templates store sets of scan exclusion paths that can be applied to the Integrity Check form in one click.
          They do not affect the malware scanner, global ignores, or quarantine.
        </p>

        <?php if ($_intTplManageData): $__ted = $_intTplManageData; ?>
        <!-- ── Edit template ── -->
        <div class="int-tpl-edit-box">
          <div class="int-tpl-edit-title">Edit template: <?= h($__ted['name']) ?></div>
          <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
            <input type="hidden" name="action"  value="update_excl_template">
            <input type="hidden" name="tpl_id"  value="<?= (int)$__ted['id'] ?>">
            <?= csrf_field() ?>
            <div class="int-tpl-edit-grid">
              <div>
                <label>Name *</label>
                <input type="text" name="tpl_name" required value="<?= h($__ted['name']) ?>" maxlength="190">
              </div>
              <div>
                <label>Description</label>
                <input type="text" name="tpl_desc" value="<?= h($__ted['description'] ?? '') ?>" maxlength="255">
              </div>
              <div>
                <label>CMS</label>
                <input type="text" name="tpl_cms" value="<?= h($__ted['cms'] ?? '') ?>" maxlength="100">
              </div>
            </div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px;">Paths (one per line)</label>
            <textarea name="tpl_paths" class="int-tpl-edit-paths"><?= h(implode("\n", $__ted['paths'])) ?></textarea>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary" style="font-size:12px;">Save changes</button>
              <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1"
                 class="btn btn-ghost" style="font-size:12px;">Cancel</a>
            </div>
          </form>
        </div>

        <?php else: ?>
        <!-- ── Create new template ── -->
        <?php if ($_intTplNew): ?>
        <div class="int-tpl-edit-box">
          <div class="int-tpl-edit-title">Create new template</div>
          <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
            <input type="hidden" name="action" value="save_excl_template">
            <?= csrf_field() ?>
            <div class="int-tpl-edit-grid">
              <div>
                <label>Name *</label>
                <input type="text" name="tpl_name" required maxlength="190" placeholder="e.g. WordPress production">
              </div>
              <div>
                <label>Description</label>
                <input type="text" name="tpl_desc" maxlength="255" placeholder="Optional description">
              </div>
              <div>
                <label>CMS</label>
                <input type="text" name="tpl_cms" maxlength="100" placeholder="e.g. WordPress, Joomla">
              </div>
            </div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);display:block;margin-bottom:4px;">Paths (one per line)</label>
            <textarea name="tpl_paths" class="int-tpl-edit-paths" placeholder="wp-content/uploads/&#10;wp-content/cache/&#10;wp-content/plugins/"></textarea>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary" style="font-size:12px;">Create template</button>
              <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1"
                 class="btn btn-ghost" style="font-size:12px;">Cancel</a>
            </div>
          </form>
        </div>

        <?php else: ?>
        <!-- ── Template list ── -->
        <?php if (empty($_intExclTemplates)): ?>
        <div class="int-placeholder">No exclusion templates yet. Create one from the Integrity Check tab using &ldquo;Save current as template&rdquo;.</div>
        <?php else: ?>
        <div class="int-results-wrap" style="margin-bottom:16px;">
          <table class="int-tpl-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>CMS</th>
                <th>Paths preview</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($_intExclTemplates as $__t): ?>
              <tr>
                <td>
                  <strong style="font-size:12px;"><?= h($__t['name']) ?></strong>
                  <?php if (!empty($__t['description'])): ?>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:1px;"><?= h($__t['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="font-size:12px;color:var(--text-muted);"><?= h($__t['cms'] ?? '—') ?></td>
                <td>
                  <div class="int-tpl-paths-preview" title="<?= h(implode("\n", $__t['paths'])) ?>">
                    <?= h(implode(', ', array_slice($__t['paths'], 0, 4))) ?>
                    <?php if (count($__t['paths']) > 4): ?>
                    <span style="color:#94a3b8;">&hellip; +<?= count($__t['paths']) - 4 ?> more</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="int-tpl-active-badge <?= $__t['active'] ? 'on' : 'off' ?>">
                    <?= $__t['active'] ? 'Active' : 'Disabled' ?>
                  </span>
                </td>
                <td style="text-align:right;">
                  <div style="display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap;">
                    <!-- Edit -->
                    <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1&tpl_edit=<?= (int)$__t['id'] ?>"
                       class="btn btn-ghost" style="font-size:11px;padding:3px 8px;">Edit</a>
                    <!-- Toggle active -->
                    <form method="post" action="module.php?module=8core-integrity&page=module_integrity" style="display:inline">
                      <input type="hidden" name="action"     value="toggle_excl_template">
                      <input type="hidden" name="tpl_id"     value="<?= (int)$__t['id'] ?>">
                      <input type="hidden" name="tpl_active" value="<?= $__t['active'] ? '0' : '1' ?>">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost" style="font-size:11px;padding:3px 8px;">
                        <?= $__t['active'] ? 'Disable' : 'Enable' ?>
                      </button>
                    </form>
                    <!-- Delete -->
                    <form method="post" action="module.php?module=8core-integrity&page=module_integrity" style="display:inline">
                      <input type="hidden" name="action" value="delete_excl_template">
                      <input type="hidden" name="tpl_id" value="<?= (int)$__t['id'] ?>">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost"
                              style="font-size:11px;padding:3px 8px;color:#dc2626;border-color:#fca5a5;"
                              onclick="return confirm('Delete template &quot;<?= h(addslashes($__t['name'])) ?>&quot;? This cannot be undone.')">
                        Delete
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; // end template list ?>
        <?php endif; // end tpl_new else ?>
        <?php endif; // end edit vs create/list ?>

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

        <?php if ($_intCheckDebug): ?>
        <div style="margin-bottom:14px;padding:10px 14px;background:#fefce8;border:1px solid #fde047;border-radius:7px;font-size:11px;font-family:monospace;color:#713f12;">
          <strong style="display:block;margin-bottom:4px;">Submit debug (last run_structural_check received)</strong>
          <div>Action: <?= h($_intCheckDebug['action']) ?></div>
          <div>Origin: <?= h($_intCheckDebug['origin'] ?: '<em>empty</em>') ?></div>
          <div>Destination: <?= h($_intCheckDebug['dest'] ?: '<em>empty</em>') ?></div>
          <div>Exclusion lines: <?= (int)$_intCheckDebug['excl_count'] ?></div>
          <div>Time: <?= h($_intCheckDebug['ts']) ?></div>
        </div>
        <?php endif; ?>

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

          </div><!-- /.int-form-grid -->

          <!-- ── Scan exclusions ── -->
          <div class="int-excl-section">
            <div class="int-excl-section-title">
              Scan exclusions
              <span>— these paths are excluded during structural comparison, not just filtered from the view</span>
            </div>

            <?php if (!empty($_intExclTplsActive)): ?>
            <!-- Template toolbar -->
            <div class="int-excl-tpl-bar">
              <label>Template</label>
              <select id="int-excl-tpl-select" class="int-excl-tpl-select">
                <option value="">— select exclusion template —</option>
                <?php foreach ($_intExclTplsActive as $__tpl): ?>
                <option value="<?= (int)$__tpl['id'] ?>"
                        data-paths="<?= h(implode("\n", $__tpl['paths'])) ?>"
                        <?= $__tpl['cms'] ? 'data-cms="' . h($__tpl['cms']) . '"' : '' ?>>
                  <?= h($__tpl['name']) ?><?= $__tpl['cms'] ? ' (' . h($__tpl['cms']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-ghost" id="int-excl-tpl-apply"
                      style="font-size:12px;padding:5px 12px;" title="Paste template paths into the textarea below">
                Apply template
              </button>
              <div class="int-excl-tpl-sep"></div>
              <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1"
                 class="btn btn-ghost" style="font-size:12px;padding:5px 12px;">Manage templates</a>
            </div>
            <?php else: ?>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">
              No active templates.
              <a href="module.php?module=8core-integrity&page=module_integrity&tab=repo&tpl_section=1"
                 style="color:#2563eb;">Manage templates</a>
            </div>
            <?php endif; ?>

            <textarea name="scan_exclusions" id="int-excl-textarea" class="int-excl-textarea"
                      placeholder="administrator/components/&#10;plugins/&#10;templates/&#10;media/"><?= h($_intScanExcl) ?></textarea>
            <div class="int-excl-hint">
              One path per line. Relative to the Destination root, or absolute (auto-stripped to relative).
              Entire subtrees are excluded — e.g. <code>administrator/components/</code> excludes all extensions.
              Malware scanner runs independently and is NOT affected by these exclusions.
            </div>

            <!-- Save current as new template (toggle button only — form is outside int-check-form below) -->
            <div style="margin-top:8px;">
              <button type="button" class="btn btn-ghost" id="int-excl-save-toggle"
                      style="font-size:11px;padding:4px 10px;">Save current as template&hellip;</button>
            </div>
          </div>

        </form><!-- /#int-check-form -->

        <!-- Save-as-template form (outside int-check-form to avoid nested-form breakage) -->
        <div class="int-excl-save-form" id="int-excl-save-form">
          <div class="int-excl-save-form-title">Save exclusions as new template</div>
          <form method="post" action="module.php?module=8core-integrity&page=module_integrity">
            <input type="hidden" name="action" value="save_excl_template">
            <?= csrf_field() ?>
            <div class="int-excl-save-row">
              <div class="int-excl-save-field">
                <label>Template name *</label>
                <input type="text" name="tpl_name" required placeholder="Joomla 4 production" maxlength="190">
              </div>
              <div class="int-excl-save-field">
                <label>CMS (optional)</label>
                <input type="text" name="tpl_cms" placeholder="Joomla" maxlength="100" style="width:120px;">
              </div>
              <div class="int-excl-save-field">
                <label>Description (optional)</label>
                <input type="text" name="tpl_desc" placeholder="Short description" maxlength="255" style="width:220px;">
              </div>
            </div>
            <input type="hidden" name="tpl_paths" id="int-excl-save-paths">
            <div style="margin-top:10px;display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary" style="font-size:12px;padding:6px 14px;"
                      onclick="document.getElementById('int-excl-save-paths').value=document.getElementById('int-excl-textarea').value;">
                Save template
              </button>
              <button type="button" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;"
                      onclick="document.getElementById('int-excl-save-form').classList.remove('is-open');">
                Cancel
              </button>
            </div>
          </form>
        </div>

        <!-- Run button submits int-check-form via form= attribute -->
        <div style="margin-top:14px;">
          <button type="submit" form="int-check-form" class="btn btn-primary">
            Run Integrity Check
          </button>
        </div>
        <div class="int-placeholder">
          If the selected origin repository has a hash index, a full hash check (MISSING / MODIFIED / EXTRA) will run automatically. Otherwise a structural (file existence only) check is used.
        </div>

        <?php
        // ── Bulk confirm screen ────────────────────────────────────────────────
        if ($_intBulkConfirm):
            $bc      = $_intBulkConfirm;
            $bcLabel = match($bc['action']) {
                'trash'            => 'Move to Integrity Trash',
                'replace_missing'  => 'Replace missing from Origin',
                'replace_modified' => 'Replace modified from Origin',
                'mark_reviewed'    => 'Mark as Reviewed',
                default            => 'Ignore in Integrity',
            };
        ?>
        <div class="int-bulk-confirm">
          <div class="int-bulk-confirm-title">Confirm bulk action: <?= h($bcLabel) ?> — <?= count($bc['rows']) ?> item(s)</div>
          <?php if (!empty($bc['skipped'])): ?>
          <div style="font-size:11px;color:#92400e;margin-bottom:8px;">
            <?= (int)$bc['skipped'] ?> item(s) skipped: <?= h($bc['skip_reason'] ?? '') ?>.
          </div>
          <?php endif; ?>
          <div class="int-bulk-confirm-list">
            <table>
              <thead><tr><th>ID</th><th>Severity</th><th>Type</th><th>Relative path</th></tr></thead>
              <tbody>
                <?php foreach ($bc['rows'] as $bcr): ?>
                <tr>
                  <td><?= (int)$bcr['id'] ?></td>
                  <td><span class="int-sev-badge <?= h($bcr['severity']) ?>"><?= strtoupper(h($bcr['severity'])) ?></span></td>
                  <td><span class="int-result-type"><?= h($bcr['type']) ?></span></td>
                  <td><?= h($bcr['relative_path']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="int-bulk-confirm-actions">
            <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
              <input type="hidden" name="action"       value="bulk_execute">
              <input type="hidden" name="run_id"       value="<?= (int)$bc['run_id'] ?>">
              <input type="hidden" name="bulk_action"  value="<?= h($bc['action']) ?>">
              <?php foreach ($bc['filters'] as $fk => $fv): ?>
              <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
              <?php endforeach; ?>
              <?php foreach ($bc['rows'] as $bcr): ?>
              <input type="hidden" name="confirmed_ids[]" value="<?= (int)$bcr['id'] ?>">
              <?php endforeach; ?>
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-danger"><?= h($bcLabel) ?></button>
            </form>
            <a href="<?= h(_int_build_check_url($bc['run_id'], $bc['filters'])) ?>" class="btn btn-ghost">Cancel</a>
          </div>
        </div>
        <?php endif; ?>

        <?php
        // ── Persisted run results ──────────────────────────────────────────────
        if ($_intRunId > 0 && $_intRunMeta && $_intRunResults !== null):
            $meta    = $_intRunMeta;
            $results = $_intRunResults;
            $tabBase = $_intTabBase . '&tab=check&run_id=' . $_intRunId;
        ?>
        <div class="int-check-results">

          <!-- Run info bar -->
          <div class="int-run-bar">
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Run #<?= (int)$meta['id'] ?></div>
              <div class="int-run-bar-val"><?= h(date('Y-m-d H:i', strtotime($meta['created_at']))) ?></div>
            </div>
            <div class="int-run-bar-sep"></div>
            <?php
              $__checkMode = $meta['check_mode'] ?? 'structural';
              $__isHash    = $__checkMode === 'hash';
            ?>
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Mode</div>
              <div style="margin-top:2px;">
                <span class="int-mode-badge <?= $__isHash ? 'is-hash' : 'is-structural' ?>">
                  <?= $__isHash ? 'HASH CHECK' : 'STRUCTURAL' ?>
                </span>
              </div>
            </div>
            <div class="int-run-bar-sep"></div>
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Origin</div>
              <div class="int-run-bar-val"><?= h($meta['origin_path']) ?></div>
            </div>
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Destination</div>
              <div class="int-run-bar-val"><?= h($meta['destination_path']) ?></div>
            </div>
            <?php if ($meta['software']): ?>
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Software</div>
              <div class="int-run-bar-val"><?= h($meta['software']) ?></div>
            </div>
            <?php endif; ?>
            <div class="int-run-bar-sep"></div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
              <?php if ($meta['suspicious'] > 0): ?>
                <span class="int-sev-tag is-suspicious"><?= (int)$meta['suspicious'] ?> suspicious</span>
              <?php endif; ?>
              <?php if ($meta['warnings'] > 0): ?>
                <span class="int-sev-tag is-warning"><?= (int)$meta['warnings'] ?> warning<?= $meta['warnings'] != 1 ? 's' : '' ?></span>
              <?php endif; ?>
              <?php if ($meta['info'] > 0): ?>
                <span class="int-sev-tag is-info"><?= (int)$meta['info'] ?> info</span>
              <?php endif; ?>
              <span style="font-size:11px;color:var(--text-muted);"><?= (int)$meta['total'] ?> total</span>
            </div>
            <?php
              $__summary = [];
              if (!empty($meta['summary_json'])) {
                  $__summary = json_decode($meta['summary_json'], true) ?: [];
              }
              if (!empty($__summary)):
            ?>
            <div class="int-run-bar-sep"></div>
            <div class="int-run-bar-item">
              <div class="int-run-bar-label">Hash summary</div>
              <div class="int-summary-counters">
                <?php if (isset($__summary['checked_files'])): ?>
                <span class="int-summary-counter"><strong><?= number_format((int)$__summary['checked_files']) ?></strong> checked</span>
                <?php endif; ?>
                <?php if (isset($__summary['ok_files']) && $__summary['ok_files'] > 0): ?>
                <span class="int-summary-counter" style="color:#16a34a;"><strong><?= number_format((int)$__summary['ok_files']) ?></strong> ok</span>
                <?php endif; ?>
                <?php if (isset($__summary['modified_files']) && $__summary['modified_files'] > 0): ?>
                <span class="int-summary-counter" style="color:#dc2626;"><strong><?= number_format((int)$__summary['modified_files']) ?></strong> modified</span>
                <?php endif; ?>
                <?php if (isset($__summary['missing_files']) && $__summary['missing_files'] > 0): ?>
                <span class="int-summary-counter" style="color:#d97706;"><strong><?= number_format((int)$__summary['missing_files']) ?></strong> missing</span>
                <?php endif; ?>
                <?php if (isset($__summary['extra_files']) && $__summary['extra_files'] > 0): ?>
                <span class="int-summary-counter"><strong><?= number_format((int)$__summary['extra_files']) ?></strong> extra</span>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($meta['scan_exclusions'])): ?>
            <div class="int-run-bar-sep"></div>
            <div class="int-run-bar-item" style="max-width:320px;">
              <div class="int-run-bar-label">Scan exclusions applied</div>
              <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:3px;">
                <?php foreach (explode("\n", $meta['scan_exclusions']) as $excl): ?>
                  <?php if (trim($excl) !== ''): ?>
                  <span class="int-excl-tag"><?= h(trim($excl)) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            <div class="int-run-bar-actions">
              <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline;">
                <input type="hidden" name="action"       value="clear_results">
                <input type="hidden" name="clear_mode"   value="run">
                <input type="hidden" name="clear_run_id" value="<?= (int)$_intRunId ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost" style="font-size:11px;padding:4px 10px;color:#dc2626;border-color:#fca5a5;"
                        onclick="return confirm('Delete all results for Run #<?= (int)$_intRunId ?>? This cannot be undone.')">
                  Clear run
                </button>
              </form>
            </div>
          </div>

          <!-- Filter bar (result filters only — not scan exclusions) -->
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px;">
            Result filters
            <span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;margin-left:6px;">— filter the view of stored results; does not re-run the check</span>
          </div>
          <form method="get" action="module.php" id="int-filter-form" class="int-filter-bar">
            <input type="hidden" name="module"  value="8core-integrity">
            <input type="hidden" name="page"    value="module_integrity">
            <input type="hidden" name="tab"     value="check">
            <input type="hidden" name="run_id"  value="<?= (int)$_intRunId ?>">
            <div>
              <label>Type</label>
              <select name="ft">
                <option value="">All types</option>
                <?php foreach (['EXTRA_FILE','EXTRA_DIRECTORY','MISSING_FILE','MISSING_DIRECTORY','MODIFIED_FILE','USER_CONTENT_FOLDER'] as $ft): ?>
                <option value="<?= h($ft) ?>" <?= ($_intRunFilters['type'] ?? '') === $ft ? 'selected' : '' ?>><?= h($ft) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Severity</label>
              <select name="fs">
                <option value="">All</option>
                <option value="suspicious" <?= ($_intRunFilters['severity'] ?? '') === 'suspicious' ? 'selected' : '' ?>>Suspicious</option>
                <option value="warning"    <?= ($_intRunFilters['severity'] ?? '') === 'warning'    ? 'selected' : '' ?>>Warning</option>
                <option value="info"       <?= ($_intRunFilters['severity'] ?? '') === 'info'       ? 'selected' : '' ?>>Info</option>
              </select>
            </div>
            <div>
              <label>Status</label>
              <select name="fst">
                <option value="">All</option>
                <option value="new"               <?= ($_intRunFilters['status'] ?? '') === 'new'               ? 'selected' : '' ?>>New</option>
                <option value="ignored_integrity" <?= ($_intRunFilters['status'] ?? '') === 'ignored_integrity' ? 'selected' : '' ?>>Ignored</option>
                <option value="reviewed"          <?= ($_intRunFilters['status'] ?? '') === 'reviewed'          ? 'selected' : '' ?>>Reviewed</option>
                <option value="trashed"           <?= ($_intRunFilters['status'] ?? '') === 'trashed'           ? 'selected' : '' ?>>Trashed</option>
                <option value="replaced"          <?= ($_intRunFilters['status'] ?? '') === 'replaced'          ? 'selected' : '' ?>>Replaced</option>
                <option value="failed"            <?= ($_intRunFilters['status'] ?? '') === 'failed'            ? 'selected' : '' ?>>Failed</option>
                <option value="pending_action"   <?= ($_intRunFilters['status'] ?? '') === 'pending_action'   ? 'selected' : '' ?>>Pending action</option>
              </select>
            </div>
            <div>
              <label>Path contains</label>
              <input type="text" name="fp" value="<?= h($_intRunFilters['path'] ?? '') ?>" placeholder="e.g. administrator">
            </div>
            <button type="submit" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;align-self:flex-end;">Filter</button>
            <?php if (array_filter($_intRunFilters)): ?>
            <a href="<?= h($_intTabBase . '&tab=check&run_id=' . $_intRunId) ?>" class="int-filter-reset">Clear filters</a>
            <?php endif; ?>
          </form>

          <?php if (empty($results)): ?>
          <div class="int-no-findings">No results match the current filters.</div>
          <?php else: ?>

          <!-- Bulk action bar -->
          <form method="post" action="<?= h($_intTabBase) ?>&tab=check" id="int-bulk-form">
            <input type="hidden" name="action"   value="bulk_preview">
            <input type="hidden" name="run_id"   value="<?= (int)$_intRunId ?>">
            <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
            <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
            <?php endforeach; ?>
            <?= csrf_field() ?>

            <!-- Selection controls -->
            <div class="int-sel-bar">
              <button type="button" class="int-sel-btn" id="int-sel-all">Check all</button>
              <button type="button" class="int-sel-btn" id="int-sel-none">Uncheck all</button>
              <span class="int-bulk-counter" id="int-sel-count" style="margin-left:4px;"><strong>0</strong> selected</span>
            </div>

            <div class="int-bulk-bar">
              <div>
                <label>Bulk action</label>
                <select name="bulk_action" id="int-bulk-action">
                  <option value="">— select action —</option>
                  <option value="ignore">Ignore in Integrity</option>
                  <option value="trash">Move to Integrity Trash (EXTRA only)</option>
                  <option value="replace_missing">Replace missing from Origin (MISSING only)</option>
                  <option value="replace_modified">Replace modified from Origin (MODIFIED_FILE only)</option>
                  <option value="mark_reviewed">Mark as Reviewed</option>
                </select>
              </div>
              <div style="display:flex;flex-direction:column;">
                <label>Select mode</label>
                <select name="select_mode" id="int-select-mode">
                  <option value="checked">Use checked rows</option>
                  <option value="range">Use ID range</option>
                </select>
              </div>
              <div class="int-bulk-id-group" id="int-id-range-group" style="display:none;">
                <label>ID range</label>
                <input type="text" name="id_range" class="int-bulk-id-input" placeholder="1,5,10-20,33" id="int-id-range">
                <div class="int-bulk-id-hint">e.g. 1,5,10-20,33</div>
              </div>
              <button type="submit" class="btn btn-primary" id="int-bulk-apply" style="align-self:flex-end;" disabled>Apply</button>
            </div>

          <!-- Results table (inside the bulk form) -->
          <div class="int-results-wrap">
            <table class="int-results-table" id="int-results-table">
              <thead>
                <tr>
                  <th class="col-cb"><input type="checkbox" id="int-cb-all" title="Toggle all"></th>
                  <th class="col-id">ID</th>
                  <th>Severity</th>
                  <th>Type</th>
                  <th>Path</th>
                  <th>Hashes</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $r):
                  $isActioned  = $r['status'] !== 'new';
                  $isExtra     = str_starts_with($r['type'], 'EXTRA_');
                  $isMissing   = str_starts_with($r['type'], 'MISSING_');
                  $isModified  = $r['type'] === 'MODIFIED_FILE';
                  $isFailed    = $r['status'] === 'failed';
                  $isReviewed  = $r['status'] === 'reviewed';
                  $isPending   = $r['status'] === 'pending_action';
                  $__trClass   = 'sev-' . h($r['severity']) . ($isActioned ? ' is-actioned' : '') . ($isModified ? ' type-modified' : '');
                  // Failure detail: from session (fresh) or from DB note
                  $__failDetail = $_intFailDetails[(int)$r['id']] ?? null;
                  $__failError  = $__failDetail['error'] ?? ($isFailed ? $r['note'] : '');
                  $__failRootCmd = $__failDetail['root_cmd'] ?? '';
                ?>
                <tr class="<?= $__trClass ?>">
                  <td>
                    <?php if (!$isActioned): ?>
                    <input type="checkbox" name="result_ids[]" value="<?= (int)$r['id'] ?>" class="int-row-cb">
                    <?php endif; ?>
                  </td>
                  <td style="font-family:var(--font-mono,monospace);font-size:11px;color:var(--text-muted);"><?= (int)$r['id'] ?></td>
                  <td><span class="int-sev-badge <?= h($r['severity']) ?>"><?= strtoupper(h($r['severity'])) ?></span></td>
                  <td><span class="int-result-type"><?= h($r['type']) ?></span></td>
                  <td>
                    <div class="int-result-rel"><?= h($r['relative_path']) ?></div>
                    <div class="int-result-full"><?= h($r['full_path']) ?></div>
                  </td>
                  <td class="int-hash-cell">
                    <?php
                      $__rsha = $r['repo_sha256']        ?? null;
                      $__dsha = $r['destination_sha256'] ?? null;
                      if ($__rsha || $__dsha):
                        $__match = ($__rsha && $__dsha && $__rsha === $__dsha);
                    ?>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                      <?php if ($__rsha): ?>
                      <span title="Repo: <?= h($__rsha) ?>" class="int-hash-short <?= $__match ? 'int-hash-match' : ($__dsha ? 'int-hash-differ' : '') ?>">
                        R:<?= h(substr($__rsha, 0, 8)) ?>&hellip;
                      </span>
                      <?php endif; ?>
                      <?php if ($__dsha): ?>
                      <span title="Dest: <?= h($__dsha) ?>" class="int-hash-short <?= $__match ? 'int-hash-match' : 'int-hash-differ' ?>">
                        D:<?= h(substr($__dsha, 0, 8)) ?>&hellip;
                      </span>
                      <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="int-hash-none">&mdash;</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="int-status-badge <?= h($r['status']) ?>"><?= h(str_replace('_', ' ', $r['status'])) ?></span>
                    <?php if ($r['note'] && $r['status'] === 'trashed'): ?>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:2px;word-break:break-all;"><?= h($r['note']) ?></div>
                    <?php endif; ?>
                    <?php if ($isPending): ?>
                    <div style="font-size:10px;color:#92400e;margin-top:3px;">
                      Queued for root worker
                      <?php if (preg_match('/^action_id:(\d+)$/', $r['note'] ?? '', $m)): ?>
                      &mdash; action #<?= (int)$m[1] ?>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($isFailed && $__failError): ?>
                    <div class="int-fail-detail">
                      <div class="int-fail-msg"><?= h($__failError) ?></div>
                      <?php if ($__failRootCmd): ?>
                      <div class="int-fail-rootcmd">
                        <div class="int-fail-rootcmd-label">Root command to execute manually:</div>
                        <pre><?= h(str_replace('{webuser}', 'www-data', $__failRootCmd)) ?></pre>
                      </div>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$isActioned): ?>
                    <div class="int-row-actions">
                      <!-- Ignore with subtree select (all non-USER_CONTENT types) -->
                      <?php if ($r['type'] !== 'USER_CONTENT_FOLDER'):
                        $ignOpts = _int_ignore_options($r['relative_path']);
                      ?>
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="ignore">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <div class="int-ignore-combo">
                          <?php if (count($ignOpts) > 1): ?>
                          <select name="ignore_path" class="int-ignore-select">
                            <?php foreach ($ignOpts as $opt): ?>
                            <option value="<?= h($opt['value']) ?>"><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <?php else: ?>
                          <input type="hidden" name="ignore_path" value="<?= h($ignOpts[0]['value'] ?? 'exact:' . $r['relative_path']) ?>">
                          <?php endif; ?>
                          <button type="button" class="int-btn-act act-ignore"
                                  onclick="this.closest('form').submit()">
                            Ignore
                          </button>
                        </div>
                      </form>
                      <?php endif; ?>
                      <?php if ($isExtra): ?>
                      <!-- Trash EXTRA -->
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="trash">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="button" class="int-btn-act act-trash"
                                onclick="if(confirm('Move &quot;<?= h(addslashes($r['relative_path'])) ?>&quot; to Integrity Trash?')) this.closest('form').submit()">
                          Trash
                        </button>
                      </form>
                      <?php elseif ($isMissing || $isModified): ?>
                      <!-- Replace from origin (MISSING or MODIFIED) -->
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="replace">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="button" class="int-btn-act act-replace"
                                title="<?= $isModified ? 'Trash modified file then copy clean version from origin.' : 'Copy missing file/folder from origin repository to destination.' ?>"
                                onclick="if(confirm('Replace &quot;<?= h(addslashes($r['relative_path'])) ?>&quot; from origin?<?= $isModified ? ' (modified file will be trashed first)' : '' ?>')) this.closest('form').submit()">
                          Replace
                        </button>
                      </form>
                      <?php endif; ?>
                      <!-- Mark reviewed -->
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="mark_reviewed">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="button" class="int-btn-act"
                                style="font-size:10px;padding:2px 6px;"
                                title="Mark this finding as manually reviewed."
                                onclick="this.closest('form').submit()">
                          Reviewed
                        </button>
                      </form>
                    </div>
                    <?php else: ?>
                    <?php if ($isFailed): ?>
                    <!-- Failed row: Retry (re-run original action) + Reset to new -->
                    <div class="int-row-actions">
                      <?php
                        // Determine what action to retry based on type
                        $__retryAction = null;
                        $__retryLabel  = null;
                        if ($isExtra) { $__retryAction = 'trash';   $__retryLabel = 'Retry Trash'; }
                        elseif ($isMissing)  { $__retryAction = 'replace'; $__retryLabel = 'Retry Replace'; }
                        elseif ($isModified) { $__retryAction = 'replace'; $__retryLabel = 'Retry Replace'; }
                        if ($__retryAction):
                      ?>
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="<?= h($__retryAction) ?>">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="submit" class="int-btn-act <?= $isExtra ? 'act-trash' : 'act-replace' ?>"
                                style="font-size:10px;padding:2px 7px;">
                          <?= h($__retryLabel) ?>
                        </button>
                      </form>
                      <?php endif; ?>
                      <!-- Reset to new -->
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="reset_status">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="submit" class="int-btn-act" style="font-size:10px;padding:2px 7px;"
                                title="Reset status to new so this row can be actioned again.">
                          Reset
                        </button>
                      </form>
                    </div>
                    <?php elseif ($isReviewed): ?>
                    <!-- Reviewed: allow reset only -->
                    <div class="int-row-actions">
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="reset_status">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="submit" class="int-btn-act" style="font-size:10px;padding:2px 7px;"
                                title="Reset status back to new.">
                          Reset
                        </button>
                      </form>
                    </div>
                    <?php elseif ($isPending): ?>
                    <!-- Pending action: Cancel or Reset -->
                    <div class="int-row-actions">
                      <form method="post" action="<?= h($_intTabBase) ?>&tab=check" style="display:inline">
                        <input type="hidden" name="action"        value="action_result">
                        <input type="hidden" name="run_id"        value="<?= (int)$_intRunId ?>">
                        <input type="hidden" name="result_id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="result_action" value="cancel_pending_action">
                        <?php foreach (_int_filters_to_get($_intRunFilters) as $fk => $fv): ?>
                        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                        <?= csrf_field() ?>
                        <button type="submit" class="int-btn-act" style="font-size:10px;padding:2px 7px;color:#92400e;border-color:#fde047;"
                                title="Cancel queued action and reset to new."
                                onclick="return confirm('Cancel queued action for this result?')">
                          Cancel action
                        </button>
                      </form>
                    </div>
                    <?php else: ?>
                    <span style="font-size:11px;color:var(--text-muted);">—</span>
                    <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div><!-- /.int-results-wrap -->
          </form><!-- /#int-bulk-form -->

          <?php endif; ?>

        </div><!-- /.int-check-results -->

        <?php
        // ── In-memory fallback (no DB) ─────────────────────────────────────────
        elseif (isset($_intCheckResults) && $_intCheckResults): $cr = $_intCheckResults; ?>
        <div class="int-check-results">
          <div class="int-check-context">
            <div class="int-check-context-item">
              <span class="int-check-context-label">Origin</span>
              <span class="int-check-context-val"><?= h($cr['origin']) ?></span>
            </div>
            <div class="int-check-context-item">
              <span class="int-check-context-label">Destination</span>
              <span class="int-check-context-val"><?= h($cr['dest']) ?></span>
            </div>
          </div>
          <?php if (empty($cr['findings'])): ?>
          <div class="int-no-findings">No structural differences found.</div>
          <?php else: ?>
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
          <div class="int-results-wrap">
            <table class="int-results-table">
              <thead><tr><th>Severity</th><th>Type</th><th>Path</th></tr></thead>
              <tbody>
                <?php foreach ($cr['findings'] as $f): ?>
                <tr class="sev-<?= h($f['severity']) ?>">
                  <td><span class="int-sev-badge <?= h($f['severity']) ?>"><?= strtoupper(h($f['severity'])) ?></span></td>
                  <td><span class="int-result-type"><?= h($f['type']) ?></span></td>
                  <td>
                    <div class="int-result-rel"><?= h($f['rel']) ?></div>
                    <div class="int-result-full"><?= h($f['fullpath']) ?></div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
          <?php if ($cr['truncated']): ?>
          <div class="int-truncated-note">Result truncated: installation exceeds 20,000 items.</div>
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

// ── Bulk selection ─────────────────────────────────────────────────────────────
(function () {
  'use strict';

  var cbAll       = document.getElementById('int-cb-all');
  var selAll      = document.getElementById('int-sel-all');
  var selNone     = document.getElementById('int-sel-none');
  var selCount    = document.getElementById('int-sel-count');
  var bulkAction  = document.getElementById('int-bulk-action');
  var bulkApply   = document.getElementById('int-bulk-apply');
  var selectMode  = document.getElementById('int-select-mode');
  var idRangeGrp  = document.getElementById('int-id-range-group');
  var idRangeInp  = document.getElementById('int-id-range');
  var bulkForm    = document.getElementById('int-bulk-form');

  if (!bulkApply) return; // no results on page

  function getCheckboxes() {
    return Array.from(document.querySelectorAll('.int-row-cb'));
  }

  function updateCount() {
    var n = getCheckboxes().filter(function(c) { return c.checked; }).length;
    var total = getCheckboxes().length;
    if (selCount) selCount.innerHTML = '<strong>' + n + '</strong> selected';
    // Sync header checkbox state
    if (cbAll) {
      cbAll.checked       = (n > 0 && n === total);
      cbAll.indeterminate = (n > 0 && n < total);
    }
    updateApplyState();
  }

  function updateApplyState() {
    var hasAction  = bulkAction && bulkAction.value !== '';
    var isRange    = selectMode && selectMode.value === 'range';
    var hasRange   = idRangeInp && idRangeInp.value.trim() !== '';
    var hasChecked = getCheckboxes().some(function(c) { return c.checked; });
    var canApply   = hasAction && (isRange ? hasRange : hasChecked);
    if (bulkApply) bulkApply.disabled = !canApply;
  }

  // Header checkbox toggles all rows
  if (cbAll) cbAll.addEventListener('change', function () {
    getCheckboxes().forEach(function(c) { c.checked = cbAll.checked; });
    updateCount();
  });

  if (selAll) selAll.addEventListener('click', function () {
    getCheckboxes().forEach(function(c) { c.checked = true; });
    updateCount();
  });

  if (selNone) selNone.addEventListener('click', function () {
    getCheckboxes().forEach(function(c) { c.checked = false; });
    updateCount();
  });

  getCheckboxes().forEach(function(c) {
    c.addEventListener('change', updateCount);
  });

  if (bulkAction) bulkAction.addEventListener('change', updateApplyState);

  if (selectMode) selectMode.addEventListener('change', function () {
    var isRange = this.value === 'range';
    if (idRangeGrp) idRangeGrp.style.display = isRange ? '' : 'none';
    updateApplyState();
  });

  if (idRangeInp) idRangeInp.addEventListener('input', updateApplyState);

  // Guard: block submit if no rows checked and mode is 'checked'
  if (bulkForm) bulkForm.addEventListener('submit', function (e) {
    var isRange = selectMode && selectMode.value === 'range';
    if (!isRange) {
      var n = getCheckboxes().filter(function(c) { return c.checked; }).length;
      if (n === 0) {
        e.preventDefault();
        alert('No rows selected.');
        return false;
      }
    }
    if (!bulkAction || bulkAction.value === '') {
      e.preventDefault();
      alert('Select a bulk action first.');
      return false;
    }
  });

  updateCount();
})();

// Exclusion template apply
(function () {
  var tplSelect  = document.getElementById('int-excl-tpl-select');
  var tplApply   = document.getElementById('int-excl-tpl-apply');
  var exclArea   = document.getElementById('int-excl-textarea');
  var saveToggle = document.getElementById('int-excl-save-toggle');
  var saveForm   = document.getElementById('int-excl-save-form');

  if (tplApply && tplSelect && exclArea) {
    tplApply.addEventListener('click', function () {
      var opt = tplSelect.options[tplSelect.selectedIndex];
      if (!opt || !opt.value) { alert('Select a template first.'); return; }
      exclArea.value = opt.dataset.paths || '';
      exclArea.focus();
    });
  }

  if (saveToggle && saveForm) {
    saveToggle.addEventListener('click', function () {
      saveForm.classList.toggle('is-open');
    });
  }
})();
</script>
</body>
</html>
