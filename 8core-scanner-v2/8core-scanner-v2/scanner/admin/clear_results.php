<?php
/**
 * 8Core Scanner v2.5.3 — Admin: Očisti rezultate
 * (c) 2026 Tomislav Galić <tomislav@8core.hr>
 * Web: https://8core.hr
 * Kontakt: info@8core.hr | Tel: +385 099 851 0717
 * Sva prava pridržana. Ovaj softver je vlasnički i zabranjeno ga je
 * distribuirati ili mijenjati bez izričitog dopuštenja autora.
 */
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/maintenance.php';
require_admin();

$user    = current_user();
$message = '';
$msgType = 'ok';

// Flash iz POST redirecta
if (!empty($_SESSION['maint_flash'])) {
    $message = $_SESSION['maint_flash'];
    $msgType = $_SESSION['maint_flash_type'] ?? 'ok';
    unset($_SESSION['maint_flash'], $_SESSION['maint_flash_type']);
}

$tableOk  = maintenance_table_exists($pdo);
$accounts = $tableOk ? maintenance_accounts($pdo) : array();
$recent   = $tableOk ? maintenance_recent($pdo, 15) : array();

$statusLabels = array(
    'queued'  => 'Čeka',
    'running' => 'Izvršava se',
    'done'    => 'Završeno',
    'failed'  => 'Greška',
);
$statusClass = array(
    'queued'  => 'status-new',
    'running' => 'status-quarantine',
    'done'    => 'status-checked',
    'failed'  => 'status-failed',
);
?>
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8Core Scanner – Očisti rezultate</title>
<link rel="stylesheet" href="../assets/css/scanner.css">
<style>
.maint-warn {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-left: 4px solid #ea580c;
  border-radius: 7px;
  padding: 14px 18px;
  margin-bottom: 22px;
  color: #7c2d12;
  font-size: 13px;
  line-height: 1.6;
}
.maint-warn strong { color: #9a3412; }
.maint-section {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 22px 24px;
  margin-bottom: 22px;
}
.maint-section-title {
  font-size: 14px;
  font-weight: 700;
  color: var(--text);
  margin: 0 0 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.maint-section-title svg { flex-shrink: 0; }
.maint-field { margin-bottom: 14px; }
.maint-field label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.maint-field select,
.maint-field input[type="text"] {
  width: 100%;
  max-width: 380px;
}
.maint-confirm-hint {
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 4px;
}
.maint-confirm-hint code {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 1px 6px;
  font-family: monospace;
  color: #dc2626;
}
.btn-maint-danger {
  background: #b91c1c;
  color: #fff;
  border-color: #b91c1c;
  font-weight: 600;
}
.btn-maint-danger:hover { background: #991b1b; border-color: #991b1b; }
.btn-maint-danger:disabled { background: #6b7280; border-color: #6b7280; cursor: not-allowed; opacity: .6; }

.maint-log { margin-top: 28px; }
.maint-log-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 12px;
}
.maint-log-table { width: 100%; }
.maint-log-table th,
.maint-log-table td { font-size: 12px; vertical-align: top; }
.maint-scope-all  { background: #eff6ff; color: #1e40af; border-radius: 4px; padding: 1px 7px; font-size: 11px; font-weight: 600; }
.maint-scope-acc  { background: #f0fdf4; color: #166534; border-radius: 4px; padding: 1px 7px; font-size: 11px; font-weight: 600; }
.maint-archive    { font-family: monospace; font-size: 10px; word-break: break-all; color: var(--text-muted); max-width: 220px; display: block; }
.maint-error-cell { color: #b91c1c; font-size: 11px; max-width: 200px; word-break: break-word; }

.no-migrate-notice {
  background: #fef3c7;
  border: 1px solid #fcd34d;
  border-radius: 7px;
  padding: 14px 18px;
  color: #78350f;
  font-size: 13px;
  margin-bottom: 22px;
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<?php include __DIR__ . '/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Očisti rezultate scana</div>
    <div class="topbar-meta">
      <a href="../logout.php" style="color:var(--text-muted);font-size:12px;">Odjava</a>
    </div>
  </div>

  <div class="content">

    <?php if ($message): ?>
      <div class="notice <?= h($msgType) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if (!$tableOk): ?>
      <div class="no-migrate-notice">
        <strong>Tablica scanner_maintenance_requests ne postoji.</strong>
        Pokreni <a href="../install/migrate.php">migrate.php</a> da bi kreirao sve potrebne tablice.
      </div>
    <?php else: ?>

    <!-- UPOZORENJE -->
    <div class="maint-warn">
      <strong>Upozorenje — destruktivna akcija.</strong><br>
      Ova funkcija briše rezultate skeniranja i fajlove iz karantene. Akcija se izvršava asinkrono putem backend workera —
      zahtjev se stavlja u red čekanja, a worker radi ZIP arhivu karantene i čišćenje baze.<br><br>
      <strong>Ne dira:</strong> scanner pravila, korisnike, postavke, migracije, ignore listu ni tablicu maintenance requestova.
    </div>

    <!-- SEKCIJA 1: PO ACCOUNTU -->
    <div class="maint-section">
      <div class="maint-section-title">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
        </svg>
        Očisti rezultate za korisnika
      </div>

      <?php if (empty($accounts)): ?>
        <p style="color:var(--text-muted);font-size:13px;">Nema accounta s nalazima u bazi.</p>
      <?php else: ?>

      <form method="post" action="clear_results_action.php" onsubmit="return confirmAccount(this)">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="account">

        <div class="maint-field">
          <label>Account</label>
          <select name="account_name" id="sel-account" required>
            <option value="">-- Odaberi account --</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= h($a) ?>"><?= h($a) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="maint-field">
          <label>Potvrda</label>
          <input type="text" name="confirm_text" autocomplete="off"
                 placeholder="OBRISI <account>" required style="max-width:280px;">
          <div class="maint-confirm-hint">
            Upiši točno <code id="confirm-hint">OBRISI &lt;account&gt;</code> velikim slovima.
          </div>
        </div>

        <button type="submit" class="btn btn-maint-danger btn-sm">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
               style="vertical-align:-1px;margin-right:4px;">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
          </svg>
          Očisti korisnika
        </button>
      </form>

      <?php endif; ?>
    </div>

    <!-- SEKCIJA 2: SVE -->
    <div class="maint-section" style="border-color:#fca5a5;">
      <div class="maint-section-title" style="color:#b91c1c;">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#b91c1c"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
        </svg>
        Očisti sve rezultate
      </div>
      <p style="font-size:13px;color:#7f1d1d;margin:0 0 16px;">
        Briše <strong>sve findings, scanner_actions, scans i scanner_scan_requests</strong>.
        Cijela karantena se arhivira u ZIP prije brisanja.
      </p>

      <form method="post" action="clear_results_action.php" onsubmit="return confirmAll(this)">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="all">

        <div class="maint-field">
          <label>Potvrda</label>
          <input type="text" name="confirm_text" autocomplete="off"
                 placeholder="OBRISI SVE" required style="max-width:220px;">
          <div class="maint-confirm-hint">
            Upiši točno <code>OBRISI SVE</code> velikim slovima.
          </div>
        </div>

        <button type="submit" class="btn btn-maint-danger btn-sm">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
               style="vertical-align:-1px;margin-right:4px;">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
          </svg>
          Očisti sve rezultate
        </button>
      </form>
    </div>

    <!-- LOG MAINTENANCE REQUESTOVA -->
    <?php if (!empty($recent)): ?>
    <div class="maint-log">
      <div class="maint-log-title">Zadnjih 15 maintenance requestova</div>
      <div class="table-wrap" style="margin:0;">
        <table class="maint-log-table">
          <thead>
            <tr>
              <th style="width:50px">#</th>
              <th>Scope</th>
              <th>Account</th>
              <th>Pokrenuo</th>
              <th>Status</th>
              <th>Obrisano (f / a / s / sr / q)</th>
              <th>Arhiva</th>
              <th>Kreiran</th>
              <th>Završen</th>
              <th>Greška</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="small mono"><?= (int)$r['id'] ?></td>
              <td>
                <?php if ($r['scope'] === 'all'): ?>
                  <span class="maint-scope-all">SVE</span>
                <?php else: ?>
                  <span class="maint-scope-acc">ACCOUNT</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= h($r['account_name'] ?? '—') ?></td>
              <td class="small"><?= h($r['requested_by_username'] ?? '—') ?></td>
              <td>
                <span class="status-pill <?= h($statusClass[$r['status']] ?? 'status-new') ?>">
                  <?= h($statusLabels[$r['status']] ?? $r['status']) ?>
                </span>
              </td>
              <td class="small mono" style="white-space:nowrap;">
                <?= (int)$r['findings_deleted'] ?>
                / <?= (int)$r['actions_deleted'] ?>
                / <?= (int)$r['scans_deleted'] ?>
                / <?= (int)$r['scan_requests_deleted'] ?>
                / <?= (int)$r['quarantine_deleted_items'] ?>
              </td>
              <td>
                <?php if (!empty($r['archive_path'])): ?>
                  <span class="maint-archive"><?= h(basename($r['archive_path'])) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= h(substr($r['created_at'], 0, 16)) ?></td>
              <td class="small"><?= $r['finished_at'] ? h(substr($r['finished_at'], 0, 16)) : '<span style="color:var(--text-muted)">—</span>' ?></td>
              <td>
                <?php if (!empty($r['error'])): ?>
                  <span class="maint-error-cell" title="<?= h($r['error']) ?>">
                    <?= h(mb_substr($r['error'], 0, 80)) ?><?= mb_strlen($r['error']) > 80 ? '…' : '' ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:13px;">Nema zabilježenih maintenance requestova.</p>
    <?php endif; ?>

    <?php endif; // tableOk ?>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->

<script>
document.getElementById('sel-account') && document.getElementById('sel-account').addEventListener('change', function() {
    var hint = document.getElementById('confirm-hint');
    if (hint) hint.textContent = 'OBRISI ' + (this.value || '<account>');
});

function confirmAccount(form) {
    var acc = form.account_name.value;
    if (!acc) { alert('Odaberi account.'); return false; }
    var expected = 'OBRISI ' + acc;
    if (form.confirm_text.value !== expected) {
        alert('Potvrda nije ispravna. Upiši točno: ' + expected);
        return false;
    }
    return confirm('Sigurno obrisati SVE rezultate za account "' + acc + '"?\nWorker će arhivirati karantenu i obrisati nalaze.');
}

function confirmAll(form) {
    if (form.confirm_text.value !== 'OBRISI SVE') {
        alert('Potvrda nije ispravna. Upiši točno: OBRISI SVE');
        return false;
    }
    return confirm('Sigurno obrisati SVE rezultate skeniranja i cijelu karantenu?\nOva akcija je nepovratna.');
}
</script>
</body>
</html>
