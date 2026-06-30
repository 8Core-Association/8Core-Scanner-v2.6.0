<?php
/**
 * 8Core Scanner v2.5.3 — Dashboard (Nalazi)
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/version.php';
require_login();

// Defaulti koji su uvijek definirani, čak i ako try padne
$findings        = array();
$actionStats     = array();
$accountBreakdown = array();
$accounts        = array();
$totalFindings   = 0;
$lastScan        = null;
$qbase           = '/home/8core_quarantine/';

try {
    $user = current_user();

    $hasAction      = has_column($pdo, 'findings', 'action_status');
    $hasAccount     = has_column($pdo, 'findings', 'account_name');
    $hasRel         = has_column($pdo, 'findings', 'relative_path');
    $hasCtime       = has_column($pdo, 'findings', 'ctime');
    $hasBirth       = has_column($pdo, 'findings', 'birth_time');
    $hasDetected    = has_column($pdo, 'findings', 'detected_at');
    $hasSourceGuess = has_column($pdo, 'findings', 'source_guess');
    $hasSourceType  = has_column($pdo, 'findings', 'source_type');
    $hasExt         = has_column($pdo, 'findings', 'file_ext');
    $hasActionError = has_column($pdo, 'findings', 'action_error');
    $hasQPath       = has_column($pdo, 'findings', 'quarantine_path');

    $accountCol  = $hasAccount  ? 'account_name' : 'owner_name';
    $relCol      = $hasRel      ? 'relative_path' : 'file_path';
    $detectedCol = $hasDetected ? 'detected_at'   : 'created_at';

    $risk     = isset($_GET['risk'])    ? $_GET['risk']    : '';
    $account  = isset($_GET['account']) ? $_GET['account'] : '';
    $status   = isset($_GET['status'])  ? $_GET['status']  : '';
    $q        = isset($_GET['q'])       ? trim($_GET['q']) : '';
    $filterIdRaw = isset($_GET['id']) ? trim($_GET['id']) : '';
    // Parse comma-separated IDs; keep only positive integers
    $filterIds = array();
    foreach (explode(',', $filterIdRaw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part) && (int)$part > 0) {
            $filterIds[] = (int)$part;
        }
    }
    $filterIds = array_unique($filterIds);

    $where  = array();
    $params = array();

    if (!is_admin()) {
        $accounts = user_accounts();
        if (empty($accounts)) {
            $where[] = "1 = 0";
        } else {
            $placeholders = implode(',', array_fill(0, count($accounts), '?'));
            $where[]      = "$accountCol IN ($placeholders)";
            foreach ($accounts as $a) $params[] = $a;
        }
        $account = implode(', ', $accounts);
    } else if ($account !== '') {
        $where[]  = "$accountCol = ?";
        $params[] = $account;
    }

    if ($risk !== '')                     { $where[] = "risk = ?";          $params[] = $risk; }
    if ($hasAction && $status !== '')     { $where[] = "action_status = ?"; $params[] = $status; }
    if ($q !== '') {
        $where[]  = "(file_path LIKE ? OR file_name LIKE ? OR rule_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }
    if (count($filterIds) === 1) {
        $where[]  = "id = ?";
        $params[] = $filterIds[0];
    } elseif (count($filterIds) > 1) {
        $placeholders = implode(',', array_fill(0, count($filterIds), '?'));
        $where[]  = "id IN ($placeholders)";
        foreach ($filterIds as $fid) $params[] = $fid;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Statistike po action_status ───────────────────────────────────────────
    if ($hasAction) {
        $as = $pdo->prepare("SELECT action_status, COUNT(*) total FROM findings $whereSql GROUP BY action_status");
        $as->execute($params);
        $actionStats = $as->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $totalFindings = (int)array_sum($actionStats);

    // ── Account list + breakdown ──────────────────────────────────────────────
    if (is_admin()) {
        $accounts = $pdo->query("
            SELECT $accountCol AS account_name, COUNT(*) total
            FROM findings
            WHERE $accountCol IS NOT NULL AND $accountCol != ''
            GROUP BY $accountCol
            ORDER BY total DESC
        ")->fetchAll();

        $accountBreakdown = array();
        if ($hasAction) {
            $abRows = $pdo->query("
                SELECT $accountCol AS account_name, action_status, COUNT(*) AS cnt
                FROM findings
                WHERE $accountCol IS NOT NULL AND $accountCol != ''
                GROUP BY $accountCol, action_status
            ")->fetchAll();
            foreach ($abRows as $row) {
                $acc = $row['account_name'];
                $st  = $row['action_status'];
                if (!isset($accountBreakdown[$acc])) {
                    $accountBreakdown[$acc] = array('total' => 0, 'quarantine_requested' => 0, 'quarantined' => 0, 'failed' => 0);
                }
                $accountBreakdown[$acc]['total'] += (int)$row['cnt'];
                if ($st === 'quarantine_requested') $accountBreakdown[$acc]['quarantine_requested'] += (int)$row['cnt'];
                if ($st === 'quarantined')          $accountBreakdown[$acc]['quarantined']          += (int)$row['cnt'];
                if (in_array($st, array('quarantine_failed','delete_failed','restore_failed','purge_failed'), true)) {
                    $accountBreakdown[$acc]['failed'] += (int)$row['cnt'];
                }
            }
        }
    } else {
        $accounts = array(array('account_name' => $user['account_name'], 'total' => 0));
        $accountBreakdown = array();
    }

    // ── Nalazi ────────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            id, scan_id, rule_name, risk,
            $accountCol AS account_name,
            owner_name, group_name, perms, file_size,
            file_name,
            " . ($hasExt         ? "file_ext"        : "'' AS file_ext") . ",
            file_path,
            $relCol AS relative_path,
            " . ($hasQPath       ? "quarantine_path" : "'' AS quarantine_path") . ",
            mtime,
            " . ($hasCtime       ? "ctime"           : "NULL AS ctime") . ",
            " . ($hasBirth       ? "birth_time"      : "NULL AS birth_time") . ",
            $detectedCol AS detected_at,
            " . ($hasSourceGuess ? "source_guess"    : "'' AS source_guess") . ",
            " . ($hasSourceType  ? "source_type"     : "'' AS source_type") . ",
            " . ($hasAction      ? "action_status"   : "'new' AS action_status") . ",
            " . ($hasAction      ? "action_note"     : "'' AS action_note") . ",
            " . ($hasActionError ? "action_error"    : "'' AS action_error") . ",
            sha256,
            created_at
        FROM findings
        $whereSql
        ORDER BY id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $findings = $stmt->fetchAll();

    $lastScan = $pdo->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch();

    // Baza karantene za safe preview
    if (isset($config['quarantine_path']) && $config['quarantine_path'] !== '') {
        $qbase = rtrim($config['quarantine_path'], '/') . '/';
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>8Core Scanner greška</title></head><body>';
    echo '<h2>8Core Scanner - PHP greška</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<p>Pokreni <a href="install/">installer</a> ili <a href="install/migrate.php">migrate.php</a>.</p>';
    echo '</body></html>';
    exit;
}

// ── Inline preview fajla (safe, read-only, 200 KB limit) ─────────────────────
// Admin only. Pokušava quarantine_path, a ako ne postoji — file_path (original).
$preview   = null;
$previewId = isset($_GET['preview_id']) ? (int)$_GET['preview_id'] : 0;

if ($previewId > 0 && is_admin()) {
    try {
        $pfStmt = $pdo->prepare("
            SELECT id, file_path, " . ($hasQPath ? "quarantine_path," : "'' AS quarantine_path,") . "
                   account_name, sha256,
                   perms, owner_name, group_name, file_size, mtime, rule_name,
                   " . ($hasAction ? "action_status" : "'new' AS action_status") . "
            FROM findings WHERE id = ? LIMIT 1
        ");
        $pfStmt->execute(array($previewId));
        $pf = $pfStmt->fetch();

        if (!$pf) {
            $preview = array('error' => 'Nalaz nije pronađen.');
        } else {
            // Odaberi putanju: preferiramo quarantine_path ako postoji i file postoji
            $qpath      = $pf['quarantine_path'] ?? '';
            $filePath   = $pf['file_path'] ?? '';
            $readPath   = '';
            $pathLabel  = '';
            $fromQuaran = false;

            if (!empty($qpath) && file_exists($qpath)) {
                // Provjeri prefix karantene za sigurnost
                if (strpos($qpath, $qbase) === 0) {
                    $readPath   = $qpath;
                    $pathLabel  = 'karantena';
                    $fromQuaran = true;
                } else {
                    $preview = array('error' => 'Nesigurna karantenska putanja — preview odbijen.');
                }
            } elseif (!empty($filePath) && file_exists($filePath)) {
                // Original file — provjeri da je unutar /home/
                if (strpos($filePath, '/home/') === 0) {
                    $readPath  = $filePath;
                    $pathLabel = 'original';
                } else {
                    $preview = array('error' => 'Originalni fajl nije unutar /home/ — preview odbijen.');
                }
            } else {
                $whyMissing = !empty($qpath) ? 'Fajl u karanteni nije pronađen.' : 'Originalni fajl nije pronađen na disku.';
                $preview = array('error' => $whyMissing . ' (' . h($filePath) . ')');
            }

            if ($preview === null && $readPath !== '') {
                $rawSize  = filesize($readPath);
                $maxRead  = 200 * 1024;
                $raw      = file_get_contents($readPath, false, null, 0, $maxRead);
                $isBinary = strpos(substr($raw, 0, 8192), "\0") !== false;
                $sha256   = hash_file('sha256', $readPath);
                $preview  = array(
                    'id'          => $pf['id'],
                    'file_path'   => $filePath,
                    'read_path'   => $readPath,
                    'path_label'  => $pathLabel,
                    'from_quaran' => $fromQuaran,
                    'sha256'      => $sha256,
                    'sha256_det'  => $pf['sha256'],
                    'size'        => $rawSize,
                    'perms'       => $pf['perms'],
                    'owner'       => $pf['owner_name'],
                    'group'       => $pf['group_name'],
                    'mtime'       => $pf['mtime'],
                    'rule'        => $pf['rule_name'],
                    'binary'      => $isBinary,
                    'truncated'   => ($rawSize > $maxRead),
                    'content'     => $isBinary ? null : $raw,
                );
            }
        }
    } catch (Throwable $previewEx) {
        $preview = array('error' => 'Greška pri učitavanju previewa: ' . $previewEx->getMessage());
    }
}

$backParams = http_build_query(array(
    'risk'    => $risk,
    'account' => is_admin() ? $account : '',
    'status'  => $status,
    'q'       => $q,
    'id'      => $filterIdRaw,
));
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner</title>
<link rel="stylesheet" href="assets/css/scanner.css">
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="logo-text">8Core Scanner</span>
    </div>
    <div class="logo-version">IOC Scanner v<?= SCANNER_VERSION ?></div>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section-label">Menu</div>
    <a class="sidebar-link active" href="index.php">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="sidebar-link" href="scan.php">
      <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      Skeniranja
    </a>
    <?php if (is_admin()): ?>
    <a class="sidebar-link" href="admin/index.php">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Admin panel
    </a>
    <a class="sidebar-link" href="admin/quarantine.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Karantena
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= h(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
      <div class="user-info">
        <div class="user-name"><?= h($user['username']) ?></div>
        <div class="user-role"><?= h($user['role']) ?><?php if (!is_admin()): $accs = user_accounts(); if (!empty($accs)): ?> &middot; <?= h(implode(', ', $accs)) ?><?php endif; endif; ?></div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Nalazi</div>
    <div class="topbar-meta">
      <?php if ($lastScan): ?>
        <span class="scan-dot <?= $lastScan['status'] === 'RUNNING' ? 'running' : '' ?>"></span>
        Zadnji scan: <?= h($lastScan['started_at']) ?>
        &nbsp;&middot;&nbsp; <?= h($lastScan['status']) ?>
        &nbsp;&middot;&nbsp; <?= (int)$lastScan['files_found'] ?> nalaza
      <?php else: ?>
        <span class="scan-dot" style="background:#94a3b8"></span>
        Nema podataka o scanu
      <?php endif; ?>
      &nbsp;&nbsp;
      <?php if (is_admin()): ?>
      <a href="scan.php" class="btn btn-primary btn-sm" style="font-size:12px;padding:4px 12px;">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px;margin-right:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>Pokreni scan
      </a>
      &nbsp;
      <?php endif; ?>
      <a href="logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if (!$hasAction): ?>
      <div class="notice">Baza nema action stupce. Otvori <a href="install/migrate.php">migrate.php</a>.</div>
    <?php endif; ?>

    <!-- WORKFLOW STAT KARTICE -->
    <div class="cards dash-cards">
      <div class="card card-total">
        <div class="label">Ukupno nalaza</div>
        <div class="num"><?= $totalFindings ?></div>
      </div>
      <div class="card card-new">
        <div class="label">Novo / detected</div>
        <div class="num"><?= (int)(($actionStats['new'] ?? 0) + ($actionStats['checked'] ?? 0)) ?></div>
      </div>
      <div class="card card-qreq">
        <div class="label">Za karantenu</div>
        <div class="num"><?= (int)($actionStats['quarantine_requested'] ?? 0) ?></div>
      </div>
      <div class="card card-qd">
        <div class="label">U karanteni</div>
        <div class="num"><?= (int)($actionStats['quarantined'] ?? 0) ?></div>
      </div>
      <div class="card card-failed">
        <div class="label">Karantena failed</div>
        <div class="num"><?= (int)($actionStats['quarantine_failed'] ?? 0) ?></div>
      </div>
      <div class="card card-dreq">
        <div class="label">Za brisanje</div>
        <div class="num"><?= (int)($actionStats['delete_requested'] ?? 0) ?></div>
      </div>
      <div class="card card-failed">
        <div class="label">Delete failed</div>
        <div class="num"><?= (int)($actionStats['delete_failed'] ?? 0) ?></div>
      </div>
      <div class="card card-failed">
        <div class="label">Restore failed</div>
        <div class="num"><?= (int)($actionStats['restore_failed'] ?? 0) ?></div>
      </div>
    </div>

    <?php if (is_admin() && !empty($accountBreakdown)): ?>
    <!-- BREAKDOWN PO ACCOUNTU -->
    <div class="account-breakdown">
      <div class="ab-title">Pregled po accountu</div>
      <div class="ab-table-wrap">
        <table class="ab-table">
          <thead>
            <tr>
              <th>Account</th>
              <th>Ukupno</th>
              <th>Za karantenu</th>
              <th>U karanteni</th>
              <th>Failed</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($accountBreakdown as $accName => $ab): ?>
            <tr>
              <td><a href="?account=<?= urlencode($accName) ?>" class="ab-account-link"><?= h($accName) ?></a></td>
              <td class="ab-num"><?= (int)$ab['total'] ?></td>
              <td class="ab-num"><?php echo $ab['quarantine_requested'] > 0 ? (int)$ab['quarantine_requested'] : '<span class="ab-zero">&mdash;</span>'; ?></td>
              <td class="ab-num"><?php echo $ab['quarantined'] > 0 ? (int)$ab['quarantined'] : '<span class="ab-zero">&mdash;</span>'; ?></td>
              <td class="ab-num">
                <?php if ($ab['failed'] > 0): ?>
                  <span class="ab-failed"><?= (int)$ab['failed'] ?></span>
                <?php else: ?>
                  <span class="ab-zero">&mdash;</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- FILTERI -->
    <form class="filters" method="get">
      <select name="risk">
        <option value="">Svi rizici</option>
        <?php foreach (array('CRITICAL','HIGH','MEDIUM','LOW') as $r): ?>
          <option value="<?= h($r) ?>" <?= $risk === $r ? 'selected' : '' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if (is_admin()): ?>
      <select name="account">
        <option value="">Svi accounti</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= h($a['account_name']) ?>" <?= $account === $a['account_name'] ? 'selected' : '' ?>>
            <?= h($a['account_name']) ?> (<?= (int)$a['total'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <select name="status">
        <option value="">Svi statusi</option>
        <?php
        $statusLabels = array(
            'new'                  => 'Novo',
            'checked'              => 'Pregledano',
            'ignore'               => 'Ignorirano',
            'quarantine_requested' => 'Za karantenu',
            'quarantined'          => 'U karanteni',
            'quarantine_failed'    => 'Karantena neuspješna',
            'delete_requested'     => 'Za brisanje',
            'deleted'              => 'Obrisano',
            'delete_failed'        => 'Brisanje neuspješno',
            'restore_requested'    => 'Za obnavljanje',
            'restored'             => 'Obnovljeno',
            'restore_failed'       => 'Obnavljanje neuspješno',
            'purge_requested'      => 'Za trajno brisanje',
            'purged'               => 'Trajno obrisano',
            'purge_failed'         => 'Trajno brisanje neuspješno',
        );
        foreach ($statusLabels as $val => $label):
        ?>
          <option value="<?= h($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Pretraga file / path / rule">
      <input type="text" name="id" value="<?= h($filterIdRaw) ?>"
             placeholder="ID ili ID-evi" style="width:130px;" title="ID nalaza (npr. 3710 ili 3710,3711,3712)">
      <button type="submit" class="btn btn-primary btn-sm">Filtriraj</button>
      <a href="index.php" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <!-- BULK BAR -->
    <div class="bulk-bar" id="bulk-bar">
      <span class="bulk-count" id="bulk-count">0 odabrano</span>
      <select id="bulk-action">
        <option value="">-- Odaberi akciju --</option>
        <option value="checked">Checked</option>
        <option value="ignore">Ignore</option>
        <option value="quarantine_requested">Quarantine</option>
        <option value="delete_requested">Delete</option>
        <option value="new">Reset na new</option>
      </select>
      <button type="button" class="btn btn-primary btn-sm" onclick="submitBulk()">Primijeni na odabrane</button>
      <button type="button" class="btn btn-ghost btn-sm" id="btn-copy-ids" onclick="copySelectedIds()" title="Kopiraj ID-eve u clipboard">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        Copy IDs
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="clearSelection()">Odznači sve</button>
    </div>

    <!-- TABLICA NALAZA -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:32px">
              <input type="checkbox" id="chk-all" title="Odaberi sve" onclick="toggleAll(this)">
            </th>
            <th style="width:20px"></th>
            <th class="col-id" style="width:54px">#</th>
            <th>Risk</th>
            <th>Status</th>
            <th>Account</th>
            <th>File / Path</th>
            <th>Rule</th>
            <th>Source</th>
            <th>Akcije</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($findings as $f): ?>
          <?php $isFailed = is_failed_status($f['action_status']); ?>
          <tr class="data-row<?= $isFailed ? ' row-failed' : '' ?>" data-id="<?= (int)$f['id'] ?>">
            <td>
              <input type="checkbox" class="row-chk" name="ids[]" value="<?= (int)$f['id'] ?>"
                     onclick="event.stopPropagation(); updateBulkBar()">
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="expand-toggle">
                <svg viewBox="0 0 12 12"><line x1="6" y1="1" x2="6" y2="11"/><line x1="1" y1="6" x2="11" y2="6"/></svg>
              </span>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)" class="small mono" style="color:var(--text-muted)"><?= (int)$f['id'] ?></td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="badge <?= risk_class($f['risk']) ?>"><?= h($f['risk']) ?></span>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <span class="status-pill <?= action_class($f['action_status']) ?>"><?= h($f['action_status']) ?></span>
              <?php if (!empty($f['action_error'])): ?>
                <span class="action-error-hint" title="<?= h($f['action_error']) ?>">
                  <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="7"/><line x1="8" y1="5" x2="8" y2="8"/><circle cx="8" cy="11" r="1" fill="#dc2626" stroke="none"/></svg>
                </span>
              <?php endif; ?>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <?= h($f['account_name']) ?>
              <div class="small">owner: <?= h($f['owner_name']) ?></div>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <b><?= h($f['file_name']) ?></b><?php if ($f['file_ext']): ?><span class="small" style="margin-left:2px;">.<?= h($f['file_ext']) ?></span><?php endif; ?>
              <div class="small mono path-truncate" title="<?= h($f['file_path']) ?>">
                <?= h($f['relative_path'] ? $f['relative_path'] : $f['file_path']) ?>
              </div>
            </td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)"><?= h($f['rule_name']) ?></td>
            <td onclick="toggleRow(<?= (int)$f['id'] ?>)">
              <?= h($f['source_guess']) ?>
              <div class="small"><?= h($f['source_type']) ?></div>
            </td>
            <td>
              <div class="action-drop" id="drop-<?= (int)$f['id'] ?>">
                <button class="action-drop-btn" type="button"
                        onclick="toggleDrop('drop-<?= (int)$f['id'] ?>', event)">
                  Akcija
                  <svg class="chevron" viewBox="0 0 12 12"><polyline points="2,4 6,8 10,4"/></svg>
                </button>
                <div class="action-menu">
                  <?php foreach (array(
                    'checked'              => array('Checked',   'act-checked'),
                    'ignore'               => array('Ignore',    'act-ignore'),
                    'quarantine_requested' => array('Karantena', 'act-quarantine'),
                    'delete_requested'     => array('Delete',    'act-delete'),
                  ) as $act => $meta): ?>
                  <form method="post" action="action.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id"     value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="action" value="<?= h($act) ?>">
                    <button type="submit" class="action-menu-item <?= h($meta[1]) ?>">
                      <span class="dot"></span><?= h($meta[0]) ?>
                    </button>
                  </form>
                  <?php endforeach; ?>
                  <?php if ($f['action_status'] === 'quarantined' && !empty($f['quarantine_path'])): ?>
                  <form method="post" action="action.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id"     value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="action" value="restore_requested">
                    <button type="submit" class="action-menu-item act-restore">
                      <span class="dot"></span>Vrati iz karantene
                    </button>
                  </form>
                  <?php endif; ?>
                  <?php if (is_admin()): ?>
                  <a href="?preview_id=<?= (int)$f['id'] ?>&<?= h($backParams) ?>"
                     class="action-menu-item" style="text-decoration:none;color:var(--text);"
                     onclick="event.stopPropagation()">
                    <span class="dot" style="background:#64748b"></span>Preview sadržaja
                  </a>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
          <!-- DETALJI -->
          <tr class="detail-row hidden" id="detail-<?= (int)$f['id'] ?>">
            <td colspan="10">
              <div class="detail-panel">
                <div class="detail-item">
                  <span class="detail-label">ID nalaza</span>
                  <span class="detail-value mono">#<?= (int)$f['id'] ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Full path</span>
                  <span class="detail-value mono"><?= h($f['file_path']) ?></span>
                </div>
                <?php if (is_admin()): ?>
                <div class="detail-item">
                  <span class="detail-label">Preview</span>
                  <span class="detail-value">
                    <a href="?preview_id=<?= (int)$f['id'] ?>&<?= h($backParams) ?>"
                       style="font-size:13px;font-weight:600;color:var(--accent,#2563eb);">
                      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                           stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                           style="vertical-align:-2px;margin-right:3px;">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                      </svg>Prikaži sadržaj fajla
                    </a>
                  </span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                  <span class="detail-label">Relative path</span>
                  <span class="detail-value mono"><?= h($f['relative_path']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Owner / Group</span>
                  <span class="detail-value mono"><?= h($f['owner_name']) ?> / <?= h($f['group_name']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Dozvole</span>
                  <span class="detail-value mono"><?= h($f['perms']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">Veličina</span>
                  <span class="detail-value"><?= number_format((int)$f['file_size']) ?> B</span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">mtime</span>
                  <span class="detail-value"><?= h($f['mtime']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">ctime</span>
                  <span class="detail-value"><?= h($f['ctime']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">birth</span>
                  <span class="detail-value"><?= h($f['birth_time']) ?></span>
                </div>
                <div class="detail-item">
                  <span class="detail-label">detected_at</span>
                  <span class="detail-value"><?= h($f['detected_at']) ?></span>
                </div>
                <?php if ($f['sha256']): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">SHA-256</span>
                  <span class="detail-value mono"><?= h($f['sha256']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($f['action_note'])): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">Napomena</span>
                  <span class="detail-value"><?= h($f['action_note']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($f['action_error'])): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">Action error</span>
                  <span class="detail-value detail-error"><?= h($f['action_error']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (is_admin() && $f['action_status'] === 'quarantined' && !empty($f['quarantine_path'])): ?>
                <div class="detail-item" style="grid-column:1/-1">
                  <span class="detail-label">Karantena</span>
                  <span class="detail-value">
                    <span class="mono small"><?= h($f['quarantine_path']) ?></span>
                    &nbsp;
                    <a href="?preview_id=<?= (int)$f['id'] ?>&<?= h($backParams) ?>" style="font-size:12px;">Preview sadržaja</a>
                    &nbsp;&middot;&nbsp;
                    <a href="admin/quarantine.php?status=quarantined&preview_id=<?= (int)$f['id'] ?>" style="font-size:12px;">Otvori u karanteni</a>
                  </span>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php if ($preview !== null): ?>
<!-- PREVIEW OVERLAY -->
<?php $closeUrl = 'index.php?' . h($backParams); ?>
<div class="finding-preview-overlay"
     onclick="if(event.target===this)window.location='<?= $closeUrl ?>'">
  <div class="finding-preview-box">
    <div class="finding-preview-header">
      <div class="finding-preview-title">Preview fajla &middot; ID <?= $previewId ?></div>
      <a href="<?= $closeUrl ?>" class="btn btn-ghost btn-sm">Zatvori &times;</a>
    </div>

    <?php if (isset($preview['error'])): ?>
      <div class="notice error"><?= h($preview['error']) ?></div>
    <?php else: ?>
      <div class="finding-preview-meta">
        <span><b>Original path:</b> <?= h($preview['file_path']) ?></span>
        <?php if ($preview['from_quaran']): ?>
        <span><b>Čita se iz:</b> karantena — <?= h($preview['read_path']) ?></span>
        <?php else: ?>
        <span><b>Čita se iz:</b> original (<?= h($preview['path_label']) ?>)</span>
        <?php endif; ?>
        <span><b>Veličina:</b> <?= number_format($preview['size']) ?> B<?= $preview['truncated'] ? ' (prikazano prvih 200 KB)' : '' ?></span>
        <span><b>Owner:</b> <?= h($preview['owner']) ?> / <?= h($preview['group']) ?></span>
        <span><b>Dozvole:</b> <?= h($preview['perms']) ?></span>
        <span><b>mtime:</b> <?= h($preview['mtime']) ?></span>
        <span><b>Pravilo:</b> <?= h($preview['rule']) ?></span>
      </div>
      <div class="finding-preview-meta" style="margin-top:6px;">
        <span style="width:100%;"><b>SHA-256 (čitano):</b>
          <span class="finding-sha"><?= h($preview['sha256']) ?></span>
        </span>
        <?php if (!empty($preview['sha256_det'])): ?>
        <span style="width:100%;"><b>SHA-256 (detekcija):</b>
          <span class="finding-sha<?= ($preview['sha256'] !== $preview['sha256_det']) ? ' sha-mismatch' : '' ?>">
            <?= h($preview['sha256_det']) ?>
            <?php if ($preview['sha256'] !== $preview['sha256_det']): ?>
              <span class="sha-warn">RAZLIKA!</span>
            <?php endif; ?>
          </span>
        </span>
        <?php endif; ?>
      </div>
      <?php if ($preview['binary']): ?>
        <div class="finding-preview-binary">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
               stroke-width="2" style="vertical-align:-5px;margin-right:6px;">
            <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
          </svg>
          Binary fajl — tekstualni preview nije dostupan
        </div>
      <?php else: ?>
        <pre class="finding-preview-code"><?= h($preview['content']) ?></pre>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script src="assets/js/scanner.js"></script>
</body>
</html>
