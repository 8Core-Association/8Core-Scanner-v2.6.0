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

      if mv -- "$file" "$qpath"; then
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
              action_error='mv neuspješan',
              action_at=NOW()
          WHERE id=$id;
        "
        log "Karantena neuspješna ID=$id FILE=$file"
      fi
    fi

  done <<< "$rows"
}

process_maintenance_requests() {
  local rows id scope account_name arch_dir arch_file ts qdir
  local cnt_findings cnt_actions cnt_scans cnt_scan_req cnt_qitems err

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

    ts=$(date '+%Y%m%d_%H%M%S')

    if [ "$scope" = "account" ]; then

      # ── Validacija account_name ─────────────────────────────────────────────
      if [ -z "$account_name" ]; then
        err="account_name je prazan za scope=account"
        mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
        log "Maintenance ID=$id FAILED: $err"
        continue
      fi

      # Zaštita: ne smije sadržavati / ili ..
      case "$account_name" in
        */*|*..)
          err="account_name sadrži nesigurne znakove"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
          ;;
      esac

      # Account mora biti u bazi
      local acc_check
      acc_check=$(mysql_run "SELECT COUNT(*) FROM findings WHERE account_name='$(sql_escape "$account_name")' LIMIT 1;")
      if [ "$acc_check" = "0" ]; then
        err="account_name ne postoji u findings"
        mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
        log "Maintenance ID=$id FAILED: $err"
        continue
      fi

      # ── ZIP karantene za account ────────────────────────────────────────────
      qdir="${QUARANTINE_BASE%/}/${account_name}"
      arch_file="${arch_dir}/quarantine_$(sql_escape "$account_name")_${ts}.zip"

      if [ -d "$qdir" ] && [ "$(ls -A "$qdir" 2>/dev/null)" ]; then
        if zip -r -q "$arch_file" "$qdir"/; then
          log "Maintenance ID=$id ZIP OK: $arch_file"
        else
          err="ZIP neuspješan za $qdir"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
        fi
      else
        log "Maintenance ID=$id karantena prazna ili ne postoji: $qdir — preskačem ZIP"
        arch_file=""
      fi

      # ── Broj fajlova u karanteni za account ─────────────────────────────────
      if [ -n "$arch_file" ]; then
        cnt_qitems=$(find "$qdir" -maxdepth 1 -type f 2>/dev/null | wc -l | tr -d ' ')
      fi

      # ── Brisanje karantene accounta ─────────────────────────────────────────
      # Dodatna zaštita: qdir mora biti unutar QUARANTINE_BASE i ne smije biti prazan
      if [ -n "$qdir" ] && [ "$qdir" != "/" ] && [ "$qdir" != "$QUARANTINE_BASE" ]; then
        case "$qdir" in
          "${QUARANTINE_BASE%/}/"*)
            rm -rf -- "$qdir" && log "Maintenance ID=$id karantena obrisana: $qdir" || log "Maintenance ID=$id rm karantena FAILED: $qdir"
            ;;
          *)
            log "Maintenance ID=$id SKIP rm: qdir izvan QUARANTINE_BASE ($qdir)"
            ;;
        esac
      fi

      # ── Brisanje iz baze za account ─────────────────────────────────────────
      # Prikupi finding ID-eve za account
      local finding_ids
      finding_ids=$(mysql_run "SELECT GROUP_CONCAT(id) FROM findings WHERE account_name='$(sql_escape "$account_name")';")

      if [ -n "$finding_ids" ] && [ "$finding_ids" != "NULL" ]; then
        # Obriši scanner_actions za te finding ID-eve
        mysql_run "DELETE FROM scanner_actions WHERE finding_id IN ($finding_ids);"
        cnt_actions=$(mysql_run "SELECT ROW_COUNT();")

        # Obriši findings
        mysql_run "DELETE FROM findings WHERE account_name='$(sql_escape "$account_name")';"
        cnt_findings=$(mysql_run "SELECT ROW_COUNT();")
      fi

      # Obriši scans vezane za account (target_value = account_name)
      mysql_run "DELETE FROM scans WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';"
      cnt_scans=$(mysql_run "SELECT ROW_COUNT();")

      # Obriši scan_requests vezane za account
      mysql_run "DELETE FROM scanner_scan_requests WHERE target_type='account' AND target_value='$(sql_escape "$account_name")';"
      cnt_scan_req=$(mysql_run "SELECT ROW_COUNT();")

    elif [ "$scope" = "all" ]; then

      # ── ZIP cijele karantene ────────────────────────────────────────────────
      arch_file="${arch_dir}/quarantine_all_${ts}.zip"

      if [ -d "$QUARANTINE_BASE" ] && [ "$(ls -A "$QUARANTINE_BASE" 2>/dev/null)" ]; then
        if zip -r -q "$arch_file" "$QUARANTINE_BASE"/; then
          log "Maintenance ID=$id ZIP OK: $arch_file"
        else
          err="ZIP neuspješan za $QUARANTINE_BASE"
          mysql_run "UPDATE scanner_maintenance_requests SET status='failed', error='$(sql_escape "$err")', finished_at=NOW() WHERE id=$id;"
          log "Maintenance ID=$id FAILED: $err"
          continue
        fi
      else
        log "Maintenance ID=$id karantena prazna ili ne postoji: $QUARANTINE_BASE — preskačem ZIP"
        arch_file=""
      fi

      # Broj fajlova u cijeloj karanteni
      if [ -n "$arch_file" ]; then
        cnt_qitems=$(find "$QUARANTINE_BASE" -maxdepth 2 -type f 2>/dev/null | wc -l | tr -d ' ')
      fi

      # ── Brisanje sadržaja karantene (ne i sam direktorij) ───────────────────
      if [ -d "$QUARANTINE_BASE" ] && [ -n "$QUARANTINE_BASE" ] && [ "$QUARANTINE_BASE" != "/" ]; then
        find "$QUARANTINE_BASE" -mindepth 1 -maxdepth 2 -type f -delete 2>/dev/null || true
        find "$QUARANTINE_BASE" -mindepth 1 -maxdepth 1 -type d -empty -delete 2>/dev/null || true
        log "Maintenance ID=$id sadržaj karantene obrisan: $QUARANTINE_BASE"
      fi

      # ── Čišćenje baze — sve tablice rezultata ──────────────────────────────
      mysql_run "DELETE FROM scanner_actions;"
      cnt_actions=$(mysql_run "SELECT ROW_COUNT();")

      mysql_run "DELETE FROM findings;"
      cnt_findings=$(mysql_run "SELECT ROW_COUNT();")

      mysql_run "DELETE FROM scanner_scan_requests;"
      cnt_scan_req=$(mysql_run "SELECT ROW_COUNT();")

      mysql_run "DELETE FROM scans;"
      cnt_scans=$(mysql_run "SELECT ROW_COUNT();")

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
    log "Maintenance ID=$id DONE findings=${cnt_findings:-0} actions=${cnt_actions:-0} scans=${cnt_scans:-0} scan_req=${cnt_scan_req:-0} qitems=${cnt_qitems:-0} archive=$arch_file"

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
