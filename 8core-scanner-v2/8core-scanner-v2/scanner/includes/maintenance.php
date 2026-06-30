<?php
/**
 * 8Core Scanner v2.5.3 — Maintenance helper
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Sva prava pridržana.
 *
 * Sadrži funkcije za kreiranje i čitanje maintenance requestova.
 * Ne radi filesystem operacije — to radi worker.
 */

/**
 * Kreira novi maintenance request u bazi i vraća umetnutni ID.
 * Baca PDOException pri grešci.
 */
function maintenance_create(PDO $pdo, $scope, $accountName, $userId, $username) {
    if (!in_array($scope, array('account', 'all'), true)) {
        throw new InvalidArgumentException('Neispravan scope.');
    }
    if ($scope === 'account' && empty($accountName)) {
        throw new InvalidArgumentException('account_name obavezan za scope=account.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO scanner_maintenance_requests
            (scope, account_name, requested_by, requested_by_username, status, created_at)
        VALUES (?, ?, ?, ?, 'queued', NOW())
    ");
    $stmt->execute(array(
        $scope,
        $scope === 'account' ? $accountName : null,
        $userId   ? (int)$userId : null,
        $username,
    ));
    return (int)$pdo->lastInsertId();
}

/**
 * Vraća zadnjih $limit maintenance requestova za prikaz u UI-u.
 */
function maintenance_recent(PDO $pdo, $limit = 10) {
    $limit = (int)$limit;
    $stmt  = $pdo->query("
        SELECT id, scope, account_name, requested_by_username, status,
               archive_path, findings_deleted, actions_deleted,
               scans_deleted, scan_requests_deleted, quarantine_deleted_items,
               error, created_at, started_at, finished_at
        FROM scanner_maintenance_requests
        ORDER BY id DESC
        LIMIT $limit
    ");
    return $stmt->fetchAll();
}

/**
 * Vraća listu accounta iz findings tablice za dropdown.
 */
function maintenance_accounts(PDO $pdo) {
    $stmt = $pdo->query("
        SELECT DISTINCT account_name
        FROM findings
        WHERE account_name IS NOT NULL AND account_name != ''
        ORDER BY account_name
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Provjera postoji li tablica scanner_maintenance_requests.
 */
function maintenance_table_exists(PDO $pdo) {
    try {
        $pdo->query("SELECT 1 FROM scanner_maintenance_requests LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}
