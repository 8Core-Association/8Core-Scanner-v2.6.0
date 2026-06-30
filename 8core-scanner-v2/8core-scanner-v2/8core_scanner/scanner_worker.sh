#!/bin/bash
# ==========================================================
# 8Core Scanner Worker v1.4
# Copyright (c) 2026 8Core
# Author: Tomislav Galić / 8Core
# Web: https://8core.hr
# Svrha: Izvršava scan queue + delete/quarantine akcije
# ==========================================================

# Konfiguracija se učitava iz scanner-db.conf koji se nalazi u
# istom direktoriju kao i ova skripta.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${SCRIPT_DIR}/scanner-db.conf"
SCANNER="${SCRIPT_DIR}/ioc_scan.sh"
LOCK="/var/run/8core-scanner-worker.lock"

# Putanje iz konfiguracije ili default
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
LOG="${LOG_PATH}/scanner-worker.log"
QUARANTINE_BASE="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-${SCRIPT_DIR}/quarantine}}"

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"
}

[ -f "$CONFIG" ] || { log "GREŠKA: nedostaje $CONFIG"; exit 1; }
[ -x "$SCANNER" ] || { log "GREŠKA: scanner nije izvršiv: $SCANNER"; exit 1; }

source "$CONFIG"

# Primijeni putanje iz config ako su postavljene
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
LOG="${LOG_PATH}/scanner-worker.log"
QUARANTINE_BASE="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-${SCRIPT_DIR}/quarantine}}"

WEB_PANEL_USER="${WEB_PANEL_USER:-}"
WEB_PANEL_GROUP="${WEB_PANEL_GROUP:-$WEB_PANEL_USER}"

DB_HOST="${DB_HOST//$'\r'/}"
DB_NAME="${DB_NAME//$'\r'/}"
DB_USER="${DB_USER//$'\r'/}"
DB_PASS="${DB_PASS//$'\r'/}"
DB_CHARSET="${DB_CHARSET//$'\r'/}"

mkdir -p "$LOG_PATH"

mysql_run() {
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    --default-character-set="${DB_CHARSET:-utf8mb4}" -N -B -e "$1"
}

sql_escape() {
  # Stripa null bajtove i escapeuje single-quote za SQL string literal
  printf "%s" "$1" | tr -d '\000' | sed "s/'/''/g"
}

safe_home_path() {
  case "$1" in
    /home/*) return 0 ;;
    *)       return 1 ;;
  esac
}

set_quarantine_perms() {
  local path="$1"
  local type="$2"
  if [ -n "$WEB_PANEL_GROUP" ] && getent group "$WEB_PANEL_GROUP" >/dev/null 2>&1; then
    chown root:"$WEB_PANEL_GROUP" "$path" 2>/dev/null || true
  fi
  if [ "$type" = "dir" ]; then
    chmod 750 "$path"
  else
    chmod 640 "$path"
  fi
}

prepare_runtime() {
  mkdir -p "$QUARANTINE_BASE"
  set_quarantine_perms "$QUARANTINE_BASE" dir
}

process_file_actions() {
  local rows id status file account qdir qpath basefile ts final_status qpath_stored

  rows=$(mysql_run "
    SELECT id, action_status, file_path, IFNULL(account_name,'unknown'), IFNULL(quarantine_path,'')
    FROM findings
    WHERE action_status IN ('delete_requested','quarantine_requested','restore_requested','purge_requested')
    ORDER BY id ASC
    LIMIT 20;
  ")

  [ -z "$rows" ] && return 0

  while IFS=$'\t' read -r id status file account qpath_stored; do
    [ -z "$id" ] && continue

    log "Action zahtjev ID=$id STATUS=$status FILE=$file"

    # ── RESTORE ──────────────────────────────────────────────────────────────
    if [ "$status" = "restore_requested" ]; then
      if [ -z "$qpath_stored" ]; then
        mysql_run "
          UPDATE findings
          SET action_status='restore_failed',
              action_error='quarantine_path je prazan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restore neuspješan ID=$id razlog: quarantine_path prazan"
        continue
      fi

      case "$qpath_stored" in
        "$QUARANTINE_BASE"/*)  : ;;
        *)
          mysql_run "
            UPDATE findings
            SET action_status='restore_failed',
                action_error='quarantine_path nije unutar dozvoljene baze karantene',
                action_at=NOW()
            WHERE id=$id;
          "
          log "Restore neuspješan ID=$id razlog: qpath izvan dozvoljene baze ($qpath_stored)"
          continue
          ;;
      esac

      if [ ! -f "$qpath_stored" ]; then
        mysql_run "
          UPDATE findings
          SET action_status='restore_failed',
              action_error='Fajl u karanteni nije pronađen',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restore neuspješan ID=$id razlog: qpath ne postoji ($qpath_stored)"
        continue
      fi

      if ! safe_home_path "$file"; then
        mysql_run "
          UPDATE findings
          SET action_status='restore_failed',
              action_error='Nesigurna odredišna putanja',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restore neuspješan ID=$id razlog: odredište nije /home/ ($file)"
        continue
      fi

      local dest_dir
      dest_dir=$(dirname "$file")
      mkdir -p "$dest_dir"

      if [ -e "$file" ]; then
        mysql_run "
          UPDATE findings
          SET action_status='restore_failed',
              action_error='Odredišni fajl već postoji — overwrite blokiran',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restore neuspješan ID=$id razlog: conflict — fajl već postoji ($file)"
        continue
      fi

      if mv -- "$qpath_stored" "$file"; then
        # chown samo ako korisnik postoji
        if id -u "$account" &>/dev/null 2>&1; then
          chown "${account}:${account}" "$file" 2>/dev/null || true
        fi
        chmod 640 "$file"
        mysql_run "
          UPDATE findings
          SET action_status='restored',
              action_error=NULL,
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restored ID=$id FROM=$(sql_escape "$qpath_stored") TO=$(sql_escape "$file")"
      else
        mysql_run "
          UPDATE findings
          SET action_status='restore_failed',
              action_error='mv neuspješan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Restore neuspješan ID=$id FILE=$file (mv greška)"
      fi
      continue
    fi

    # ── PURGE ─────────────────────────────────────────────────────────────────
    if [ "$status" = "purge_requested" ]; then
      if [ -z "$qpath_stored" ]; then
        mysql_run "
          UPDATE findings
          SET action_status='purge_failed',
              action_error='quarantine_path je prazan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Purge neuspješan ID=$id razlog: quarantine_path prazan"
        continue
      fi

      case "$qpath_stored" in
        "$QUARANTINE_BASE"/*)  : ;;
        *)
          mysql_run "
            UPDATE findings
            SET action_status='purge_failed',
                action_error='quarantine_path nije unutar dozvoljene baze karantene',
                action_at=NOW()
            WHERE id=$id;
          "
          log "Purge neuspješan ID=$id razlog: qpath izvan dozvoljene baze ($qpath_stored)"
          continue
          ;;
      esac

      if [ -f "$qpath_stored" ]; then
        if rm -f -- "$qpath_stored"; then
          mysql_run "
            UPDATE findings
            SET action_status='purged',
                action_error=NULL,
                action_at=NOW()
            WHERE id=$id;
          "
          log "Purged ID=$id FILE=$qpath_stored"
        else
          mysql_run "
            UPDATE findings
            SET action_status='purge_failed',
                action_error='rm neuspješan',
                action_at=NOW()
            WHERE id=$id;
          "
          log "Purge neuspješan ID=$id FILE=$qpath_stored (rm greška)"
        fi
      else
        mysql_run "
          UPDATE findings
          SET action_status='purged',
              action_error='Fajl nije pronađen — označeno kao purged',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Purged (fajl nije bio na disku) ID=$id FILE=$qpath_stored"
      fi
      continue
    fi

    # ── DELETE / QUARANTINE ───────────────────────────────────────────────────

    if ! safe_home_path "$file"; then
      final_status="${status%_requested}_failed"
      mysql_run "
        UPDATE findings
        SET action_status='$final_status',
            action_error='Nesigurna putanja blokirana',
            action_at=NOW()
        WHERE id=$id;
      "
      log "Akcija neuspješna ID=$id nesigurna putanja"
      continue
    fi

    if [ ! -f "$file" ]; then
      final_status="${status%_requested}_failed"
      mysql_run "
        UPDATE findings
        SET action_status='$final_status',
            action_error='Fajl nije pronađen',
            action_at=NOW()
        WHERE id=$id;
      "
      log "Akcija neuspješna ID=$id fajl nije pronađen"
      continue
    fi

    if [ "$status" = "delete_requested" ]; then
      if rm -f -- "$file"; then
        mysql_run "
          UPDATE findings
          SET action_status='deleted',
              action_error=NULL,
              action_at=NOW()
          WHERE id=$id;
        "
        log "Obrisano ID=$id FILE=$file"
      else
        mysql_run "
          UPDATE findings
          SET action_status='delete_failed',
              action_error='rm neuspješan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Brisanje neuspješno ID=$id FILE=$file"
      fi
    fi

    if [ "$status" = "quarantine_requested" ]; then
      ts=$(date '+%Y%m%d-%H%M%S')
      basefile=$(basename "$file")
      qdir="$QUARANTINE_BASE/$account"
      qpath="$qdir/${id}_${ts}_$basefile"

      mkdir -p "$qdir"
      set_quarantine_perms "$qdir" dir

      local mv_err
      mv_err=$(mv -- "$file" "$qpath" 2>&1)
      if [ $? -eq 0 ]; then
        set_quarantine_perms "$qpath" file
        mysql_run "
          UPDATE findings
          SET action_status='quarantined',
              quarantine_path='$(sql_escape "$qpath")',
              action_error=NULL,
              action_at=NOW()
          WHERE id=$id;
        "
        log "Karantenizirano ID=$id NA=$qpath"
      else
        mysql_run "
          UPDATE findings
          SET action_status='quarantine_failed',
              action_error='mv failed: $(sql_escape "$mv_err")',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Karantena neuspješna ID=$id FILE=$file ERR=$mv_err"
      fi
    fi

  done <<< "$rows"
}

process_maintenance_requests() {
  local rows id scope account_name arch_dir arch_file ts qdir qbase_clean
  local cnt_findings cnt_actions cnt_scans cnt_scan_req cnt_qitems err
  local zip_done qdir_existed

  rows=$(mysql_run "
    SELECT id, scope, IFNULL(account_name,'')
    FROM scanner_maintenance_requests
    WHERE status='queued'
    ORDER BY id ASC
    LIMIT 5;
  ")

  [ -z "$rows" ] && return 0

  arch_dir="/root/8core_scanner/quarantine_archives"
  mkdir -p "$arch_dir"

  while IFS=$'\t' read -r id scope account_name; do
    [ -z "$id" ] && continue

    log "Maintenance request ID=$id scope=$scope account=$account_name"

    # Označi running
    mysql_run "UPDATE scanner_maintenance_requests SET status='running', started_at=NOW() WHERE id=$id;"

    err=""
    cnt_findings=0
    cnt_actions=0
    cnt_scans=0
    cnt_scan_req=0
    cnt_qitems=0
    arch_file=""
    zip_done=0
    qdir_existed=0

    ts=$(date '+%Y%m%d_%H%M%S')

    # ── Hard safety check: QUARANTINE_BASE ─────────────────────────────────────
    # Mora biti definirano, ne smije biti /, /home, niti prazan string
    if [ -z "$QUARANTINE_BASE" ]; then
      err="QUARANTINE_BASE nije postavljen u konfiguraciji"
      mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
      log "Maintenance ID=$id FAILED: $err"
      continue
    fi

    qbase_clean="${QUARANTINE_BASE%/}"

    case "$qbase_clean" in
      ""|"/"|"/home")
        err="QUARANTINE_BASE je nesigurna putanja: $QUARANTINE_BASE"
        mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
        log "Maintenance ID=$id FAILED: $err"
        continue
        ;;
    esac

    if [ "$scope" = "account" ]; then

      # ── Validacija account_name ─────────────────────────────────────────────
      if [ -z "$account_name" ]; then
        err="account_name je prazan za scope=account"
        mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
        log "Maintenance ID=$id FAILED: $err"
        continue
      fi

      # Ne smije sadržavati /, .., whitespace ni prazni string
      case "$account_name" in
        */*|*..*)
          err="account_name sadrži nesigurne znakove (/ ili ..)"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
          ;;
      esac
      case "$account_name" in
        *[[:space:]]*)
          err="account_name sadrži whitespace"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
          ;;
      esac

      # Account mora biti u bazi (provjera existence, ne samo u zahtjevu)
      local acc_check
      acc_check=$(mysql_run "SELECT COUNT(*) FROM findings WHERE account_name='$(sql_escape "$account_name")' LIMIT 1;")
      if [ "$acc_check" = "0" ]; then
        err="account_name '$account_name' ne postoji u findings"
        mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
        log "Maintenance ID=$id FAILED: $err"
        continue
      fi

      # qdir mora biti unutar QUARANTINE_BASE — konstruiramo i odmah provjeravamo
      qdir="${qbase_clean}/${account_name}"

      # Finalna provjera: qdir ne smije biti jednak qbase_clean niti izvan njega
      case "$qdir" in
        "${qbase_clean}/"*)
          : # OK
          ;;
        *)
          err="qdir izvan QUARANTINE_BASE — safety abort ($qdir)"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
          ;;
      esac

      # ── ZIP karantene za account (ako direktorij postoji i nije prazan) ───────
      if [ -d "$qdir" ]; then
        qdir_existed=1
        cnt_qitems=$(find "$qdir" -maxdepth 1 -type f 2>/dev/null | wc -l | tr -d ' ')

        if [ "${cnt_qitems:-0}" -gt 0 ]; then
          arch_file="${arch_dir}/quarantine_${account_name}_${ts}.zip"
          if zip -r -q "$arch_file" "$qdir"/; then
            zip_done=1
            log "Maintenance ID=$id ZIP OK: $arch_file (${cnt_qitems} fajlova)"
          else
            err="ZIP neuspješan za $qdir — brisanje prekinuto"
            mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
            log "Maintenance ID=$id FAILED: $err"
            continue
          fi
        else
          # Direktorij postoji ali je prazan — nema što zipati, ali brisanje je OK
          zip_done=1
          log "Maintenance ID=$id karantena prazna: $qdir — preskačem ZIP, brisanje dopušteno"
        fi
      else
        # Karantena za ovaj account ne postoji — nije fatalno, samo nastavljamo s DB cleanupom
        zip_done=1
        log "Maintenance ID=$id karantena ne postoji: $qdir — preskačem ZIP i rm, nastavljam s DB cleanupom"
      fi

      # ── Brisanje karantene accounta (SAMO ako je ZIP uspješan ili direktorij nije postojao) ──
      if [ "$zip_done" = "1" ] && [ "$qdir_existed" = "1" ]; then
        rm -rf -- "$qdir" \
          && log "Maintenance ID=$id karantena obrisana: $qdir" \
          || log "Maintenance ID=$id WARN: rm karantene nije uspio: $qdir"
      fi

      # ── Brisanje iz baze za account ─────────────────────────────────────────
      # Koristi COUNT prije/poslije umjesto ROW_COUNT (ROW_COUNT nije pouzdan između mysql poziva)
      cnt_actions=$(mysql_run "SELECT COUNT(*) FROM scanner_actions sa INNER JOIN findings f ON sa.finding_id = f.id WHERE f.account_name='$(sql_escape "$account_name")';")
      if [ "${cnt_actions:-0}" -gt 0 ]; then
        local finding_ids
        finding_ids=$(mysql_run "SELECT GROUP_CONCAT(id) FROM findings WHERE account_name='$(sql_escape "$account_name")';")
        if [ -n "$finding_ids" ] && [ "$finding_ids" != "NULL" ]; then
          mysql_run "DELETE FROM scanner_actions WHERE finding_id IN ($finding_ids);"
        fi
      fi

      cnt_findings=$(mysql_run "SELECT COUNT(*) FROM findings WHERE account_name='$(sql_escape "$account_name")';")
      mysql_run "DELETE FROM findings WHERE account_name='$(sql_escape "$account_name")';"

      # scans: target_type i target_value postoje u shemi (potvrđeno)
      cnt_scans=$(mysql_run "SELECT COUNT(*) FROM scans WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';")
      mysql_run "DELETE FROM scans WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';"

      # scanner_scan_requests: target_type i target_value postoje u shemi (potvrđeno)
      cnt_scan_req=$(mysql_run "SELECT COUNT(*) FROM scanner_scan_requests WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';")
      mysql_run "DELETE FROM scanner_scan_requests WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';"

    elif [ "$scope" = "all" ]; then

      # ── ZIP cijele karantene (ako postoji i nije prazna) ─────────────────────
      if [ -d "$QUARANTINE_BASE" ]; then
        cnt_qitems=$(find "$QUARANTINE_BASE" -mindepth 2 -maxdepth 2 -type f 2>/dev/null | wc -l | tr -d ' ')

        if [ "${cnt_qitems:-0}" -gt 0 ]; then
          arch_file="${arch_dir}/quarantine_all_${ts}.zip"
          if zip -r -q "$arch_file" "$QUARANTINE_BASE"/; then
            zip_done=1
            log "Maintenance ID=$id ZIP OK: $arch_file (${cnt_qitems} fajlova)"
          else
            err="ZIP neuspješan za $QUARANTINE_BASE — brisanje prekinuto"
            mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
            log "Maintenance ID=$id FAILED: $err"
            continue
          fi
        else
          zip_done=1
          log "Maintenance ID=$id karantena prazna ili ne sadrži fajlove — preskačem ZIP"
        fi
      else
        zip_done=1
        log "Maintenance ID=$id QUARANTINE_BASE ne postoji: $QUARANTINE_BASE — preskačem ZIP"
      fi

      # ── Brisanje sadržaja karantene (SAMO ako je ZIP uspješan) ──────────────
      # Briše fajlove i prazne poddirektorije, ne sam QUARANTINE_BASE direktorij
      if [ "$zip_done" = "1" ] && [ -d "$QUARANTINE_BASE" ]; then
        find "$QUARANTINE_BASE" -mindepth 2 -maxdepth 2 -type f -delete 2>/dev/null || true
        find "$QUARANTINE_BASE" -mindepth 1 -maxdepth 1 -type d -empty -delete 2>/dev/null || true
        log "Maintenance ID=$id sadržaj karantene obrisan: $QUARANTINE_BASE"
      fi

      # ── Čišćenje baze — brojimo prije brisanja ──────────────────────────────
      cnt_actions=$(mysql_run "SELECT COUNT(*) FROM scanner_actions;")
      mysql_run "DELETE FROM scanner_actions;"

      cnt_findings=$(mysql_run "SELECT COUNT(*) FROM findings;")
      mysql_run "DELETE FROM findings;"

      cnt_scan_req=$(mysql_run "SELECT COUNT(*) FROM scanner_scan_requests;")
      mysql_run "DELETE FROM scanner_scan_requests;"

      cnt_scans=$(mysql_run "SELECT COUNT(*) FROM scans;")
      mysql_run "DELETE FROM scans;"

    else
      err="Nepoznat scope: $scope"
      mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
      log "Maintenance ID=$id FAILED: $err"
      continue
    fi

    # ── Upis rezultata ─────────────────────────────────────────────────────────
    local arch_sql
    if [ -n "$arch_file" ]; then
      arch_sql="'$(sql_escape "$arch_file")'"
    else
      arch_sql="NULL"
    fi

    mysql_run "
      UPDATE scanner_maintenance_requests SET
        status='done',
        archive_path=$arch_sql,
        findings_deleted=${cnt_findings:-0},
        actions_deleted=${cnt_actions:-0},
        scans_deleted=${cnt_scans:-0},
        scan_requests_deleted=${cnt_scan_req:-0},
        quarantine_deleted_items=${cnt_qitems:-0},
        error=NULL,
        finished_at=NOW()
      WHERE id=$id;
    "
    log "Maintenance ID=$id DONE findings=${cnt_findings:-0} actions=${cnt_actions:-0} scans=${cnt_scans:-0} scan_req=${cnt_scan_req:-0} qitems=${cnt_qitems:-0} archive=${arch_file:-none}"

  done <<< "$rows"
}

process_scan_queue() {
  local RUNNING REQ REQ_ID TARGET_TYPE TARGET_VALUE RET

  RUNNING=$(mysql_run "SELECT COUNT(*) FROM scans WHERE status='RUNNING';")
  if [ "$RUNNING" != "0" ]; then
    log "Scanner već radi. Čekanje."
    return 0
  fi

  REQ=$(mysql_run "
    SELECT id, target_type, target_value
    FROM scanner_scan_requests
    WHERE status='PENDING'
    ORDER BY id ASC
    LIMIT 1;
  ")

  [ -z "$REQ" ] && return 0

  REQ_ID=$(echo "$REQ" | awk '{print $1}')
  TARGET_TYPE=$(echo "$REQ" | awk '{print $2}')
  TARGET_VALUE=$(echo "$REQ" | cut -f3-)

  log "Obrada scan zahtjeva ID=$REQ_ID TIP=$TARGET_TYPE TARGET=$TARGET_VALUE"

  mysql_run "
    UPDATE scanner_scan_requests
    SET status='RUNNING', started_at=NOW()
    WHERE id=$REQ_ID;
  "

  if [ "$TARGET_TYPE" = "all" ]; then
    "$SCANNER" --all --config="$CONFIG"
    RET=$?
  elif [ "$TARGET_TYPE" = "account" ]; then
    "$SCANNER" --account="$TARGET_VALUE" --config="$CONFIG"
    RET=$?
  elif [ "$TARGET_TYPE" = "custom_path" ]; then
    "$SCANNER" --path="$TARGET_VALUE" --config="$CONFIG"
    RET=$?
  else
    RET=99
  fi

  if [ "$RET" = "0" ]; then
    mysql_run "
      UPDATE scanner_scan_requests
      SET status='FINISHED', finished_at=NOW(), note='OK'
      WHERE id=$REQ_ID;
    "
    log "Scan zahtjev završen ID=$REQ_ID"
  else
    mysql_run "
      UPDATE scanner_scan_requests
      SET status='FAILED', finished_at=NOW(), note='Scanner vratio kod $RET'
      WHERE id=$REQ_ID;
    "
    log "Scan zahtjev neuspješan ID=$REQ_ID RET=$RET"
  fi
}

(
  flock -n 9 || exit 0
  log "Worker pokrenut"

  prepare_runtime
  process_file_actions
  process_maintenance_requests
  process_scan_queue

) 9>"$LOCK"
