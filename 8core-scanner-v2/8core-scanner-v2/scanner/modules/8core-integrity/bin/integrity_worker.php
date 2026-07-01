#!/usr/bin/env php
<?php
/**
 * 8Core Integrity — root worker
 *
 * Processes pending actions from scanner_integrity_actions that the PHP web
 * user could not execute due to filesystem permissions.
 *
 * Must run as root or as a user with write access to both:
 *   - /home/<account>/public_html  (source)
 *   - /home/8core_integrity/trash  (target)
 *
 * Cron example (runs every 5 minutes):
 *   * /5 * * * * /usr/bin/php /path/to/scanner/modules/8core-integrity/bin/integrity_worker.php >> /home/8core_integrity/logs/worker.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

define('INT_WORKER_ROOT', dirname(__DIR__, 4)); // scanner/
require_once INT_WORKER_ROOT . '/includes/db.php';
require_once INT_WORKER_ROOT . '/modules/8core-integrity/includes/integrity.php';

// DB connection from scanner config
$pdo = db_connect();
if (!$pdo) {
    log_worker('ERROR', 'Cannot connect to database.');
    exit(1);
}

// ── Safety constants ──────────────────────────────────────────────────────────

define('INT_TRASH_ROOT',  integrity_trash_root());
define('INT_SOURCE_ROOT', '/home');  // all source paths must be under this

// ── Run ───────────────────────────────────────────────────────────────────────

$actions = integrity_load_pending_actions($pdo);

if (empty($actions)) {
    log_worker('INFO', 'No pending actions.');
    exit(0);
}

log_worker('INFO', 'Processing ' . count($actions) . ' pending action(s).');

foreach ($actions as $action) {
    $id         = (int) $action['id'];
    $resultId   = (int) $action['result_id'];
    $act        = $action['action'];
    $sourcePath = $action['source_path'];
    $targetPath = $action['target_path'];
    $relPath    = $action['relative_path'];

    // Mark as running
    worker_update_status($pdo, $id, 'running', null);

    try {
        $err = worker_validate_paths($sourcePath, $targetPath, $relPath);
        if ($err !== null) {
            worker_fail($pdo, $id, $resultId, $err);
            continue;
        }

        if ($act === 'move_to_trash') {
            $err = worker_do_move_to_trash($sourcePath, $targetPath);
        } elseif ($act === 'replace_from_origin') {
            // For replace: targetPath holds the trash destination for the source.
            // The result row stores origin_path/destination_path — we need them.
            // Re-derive: trash the source first, then copy from origin to dest.
            $err = worker_do_replace($pdo, $id, $resultId, $sourcePath, $targetPath, $relPath);
        } else {
            $err = 'Unknown action: ' . $act;
        }

        if ($err !== null) {
            worker_fail($pdo, $id, $resultId, $err);
        } else {
            worker_done($pdo, $id, $resultId, $act, $targetPath);
        }
    } catch (Throwable $e) {
        worker_fail($pdo, $id, $resultId, 'Exception: ' . $e->getMessage());
    }
}

log_worker('INFO', 'Done.');
exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

function worker_validate_paths(string $source, string $target, string $rel): ?string {
    // Source must be a real path under /home
    $real = realpath($source);
    if ($real === false) {
        return 'Source path not found: ' . $source;
    }
    if ($real !== INT_SOURCE_ROOT && !str_starts_with($real, INT_SOURCE_ROOT . '/')) {
        return 'Source path not inside /home: ' . $real;
    }

    // Target must go into the integrity trash root
    $trashReal = realpath(INT_TRASH_ROOT) ?: INT_TRASH_ROOT;
    if (!str_starts_with($target, $trashReal . '/')) {
        return 'Target path not inside trash root: ' . $target;
    }

    // Relative path must not escape
    if ($rel === '' || str_contains($rel, '..')) {
        return 'Invalid relative path: ' . $rel;
    }

    return null;
}

function worker_do_move_to_trash(string $source, string $target): ?string {
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
        return 'Cannot create trash directory: ' . $parent;
    }
    if (!rename($source, $target)) {
        $err = error_get_last();
        return 'rename() failed: ' . ($err['message'] ?? 'unknown error');
    }
    return null;
}

function worker_do_replace(PDO $pdo, int $actionId, int $resultId, string $sourcePath, string $trashTarget, string $relPath): ?string {
    // Load result row to get origin_path and destination_path
    try {
        $stmt = $pdo->prepare(
            'SELECT r.origin_path, r.destination_path, r.relative_path
               FROM scanner_integrity_results r
              WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return 'DB error loading result: ' . $e->getMessage();
    }

    if (!$row) {
        return 'Result row not found: ' . $resultId;
    }

    // Step 1: trash the modified file
    $err = worker_do_move_to_trash($sourcePath, $trashTarget);
    if ($err !== null) {
        return 'Trash step failed: ' . $err;
    }

    // Step 2: copy clean file from origin
    $originFile = rtrim($row['origin_path'], '/') . '/' . ltrim($row['relative_path'], '/');
    $destFile   = rtrim($row['destination_path'], '/') . '/' . ltrim($row['relative_path'], '/');

    $realOrigin = realpath($originFile);
    if ($realOrigin === false) {
        return 'Origin file not found: ' . $originFile;
    }

    $destParent = dirname($destFile);
    if (!is_dir($destParent) && !mkdir($destParent, 0755, true)) {
        return 'Cannot create destination parent: ' . $destParent;
    }

    if (!copy($realOrigin, $destFile)) {
        $err = error_get_last();
        return 'copy() failed: ' . ($err['message'] ?? 'unknown error');
    }

    return null;
}

function worker_update_status(PDO $pdo, int $id, string $status, ?string $error): void {
    try {
        $pdo->prepare(
            'UPDATE scanner_integrity_actions
                SET status = :s, error_message = :e, executed_at = IF(:s2 IN (\'done\',\'failed\'), NOW(), executed_at)
              WHERE id = :id LIMIT 1'
        )->execute([':s' => $status, ':s2' => $status, ':e' => $error, ':id' => $id]);
    } catch (PDOException $e) {
        log_worker('WARN', 'Cannot update action status: ' . $e->getMessage());
    }
}

function worker_done(PDO $pdo, int $actionId, int $resultId, string $act, string $trashPath): void {
    worker_update_status($pdo, $actionId, 'done', null);

    $newStatus = ($act === 'move_to_trash') ? 'trashed' : 'replaced';
    $note      = ($act === 'move_to_trash') ? $trashPath : '';
    try {
        $pdo->prepare(
            'UPDATE scanner_integrity_results
                SET status = :s, note = :n, updated_at = NOW()
              WHERE id = :id LIMIT 1'
        )->execute([':s' => $newStatus, ':n' => $note, ':id' => $resultId]);
    } catch (PDOException $e) {
        log_worker('WARN', 'Cannot update result status: ' . $e->getMessage());
    }

    log_worker('OK', 'Action #' . $actionId . ' (' . $act . ') completed. Result #' . $resultId . ' -> ' . $newStatus);
}

function worker_fail(PDO $pdo, int $actionId, int $resultId, string $error): void {
    worker_update_status($pdo, $actionId, 'failed', $error);

    try {
        $pdo->prepare(
            'UPDATE scanner_integrity_results
                SET status = \'failed\', note = :n, updated_at = NOW()
              WHERE id = :id LIMIT 1'
        )->execute([':n' => $error, ':id' => $resultId]);
    } catch (PDOException $e) {
        log_worker('WARN', 'Cannot update result status: ' . $e->getMessage());
    }

    log_worker('ERROR', 'Action #' . $actionId . ' failed: ' . $error);
}

function log_worker(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    fwrite(STDOUT, $line . "\n");
}
