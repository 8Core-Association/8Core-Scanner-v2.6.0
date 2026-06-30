<?php
/**
 * 8Core Scanner v2.5.3 — Admin: POST handler za Očisti rezultate
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Sva prava pridržana.
 *
 * Samo kreira maintenance request u bazi (scope queued).
 * Stvarno brisanje i ZIP radi scanner_worker.sh.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/maintenance.php';
require_admin();

// Samo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: clear_results.php');
    exit;
}

csrf_verify();

$user    = current_user();
$scope   = isset($_POST['scope']) ? trim($_POST['scope']) : '';
$confirm = isset($_POST['confirm_text']) ? trim($_POST['confirm_text']) : '';

// ── Scope validacija ───────────────────────────────────────────────────────────
if (!in_array($scope, array('account', 'all'), true)) {
    $_SESSION['maint_flash']      = 'Neispravan scope.';
    $_SESSION['maint_flash_type'] = 'error';
    header('Location: clear_results.php');
    exit;
}

// ── Tablica postoji? ───────────────────────────────────────────────────────────
if (!maintenance_table_exists($pdo)) {
    $_SESSION['maint_flash']      = 'Tablica scanner_maintenance_requests ne postoji. Pokreni migrate.php.';
    $_SESSION['maint_flash_type'] = 'error';
    header('Location: clear_results.php');
    exit;
}

// ── SCOPE = ACCOUNT ────────────────────────────────────────────────────────────
if ($scope === 'account') {
    $accountName = isset($_POST['account_name']) ? trim($_POST['account_name']) : '';

    // Account ne smije biti prazan ni sadržavati slash
    if ($accountName === '' || strpos($accountName, '/') !== false || strpos($accountName, '..') !== false) {
        $_SESSION['maint_flash']      = 'Neispravan ili prazan account_name.';
        $_SESSION['maint_flash_type'] = 'error';
        header('Location: clear_results.php');
        exit;
    }

    // Account mora postojati u findings
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM findings
        WHERE account_name = ? LIMIT 1
    ");
    $check->execute(array($accountName));
    if ((int)$check->fetchColumn() === 0) {
        $_SESSION['maint_flash']      = 'Account "' . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') . '" nema nalaza u bazi ili ne postoji.';
        $_SESSION['maint_flash_type'] = 'error';
        header('Location: clear_results.php');
        exit;
    }

    // Confirmation text: točno "OBRISI <account>"
    $expected = 'OBRISI ' . $accountName;
    if ($confirm !== $expected) {
        $_SESSION['maint_flash']      = 'Potvrda nije ispravna. Očekivano: "' . htmlspecialchars($expected, ENT_QUOTES, 'UTF-8') . '".';
        $_SESSION['maint_flash_type'] = 'error';
        header('Location: clear_results.php');
        exit;
    }

    try {
        $reqId = maintenance_create($pdo, 'account', $accountName, $user['id'], $user['username']);
        $_SESSION['maint_flash']      = 'Maintenance request kreiran (ID=' . $reqId . ') za account "' . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') . '". Worker će ga obraditi pri sljedećem pokretanju.';
        $_SESSION['maint_flash_type'] = 'ok';
    } catch (Exception $e) {
        $_SESSION['maint_flash']      = 'Greška pri kreiranju requesta: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $_SESSION['maint_flash_type'] = 'error';
    }

    header('Location: clear_results.php');
    exit;
}

// ── SCOPE = ALL ────────────────────────────────────────────────────────────────
if ($scope === 'all') {
    if ($confirm !== 'OBRISI SVE') {
        $_SESSION['maint_flash']      = 'Potvrda nije ispravna. Upiši točno: OBRISI SVE';
        $_SESSION['maint_flash_type'] = 'error';
        header('Location: clear_results.php');
        exit;
    }

    try {
        $reqId = maintenance_create($pdo, 'all', null, $user['id'], $user['username']);
        $_SESSION['maint_flash']      = 'Maintenance request kreiran (ID=' . $reqId . ') za brisanje svih rezultata. Worker će ga obraditi pri sljedećem pokretanju.';
        $_SESSION['maint_flash_type'] = 'ok';
    } catch (Exception $e) {
        $_SESSION['maint_flash']      = 'Greška pri kreiranju requesta: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $_SESSION['maint_flash_type'] = 'error';
    }

    header('Location: clear_results.php');
    exit;
}

// Fallback (ne bi se trebalo dogoditi)
header('Location: clear_results.php');
exit;
