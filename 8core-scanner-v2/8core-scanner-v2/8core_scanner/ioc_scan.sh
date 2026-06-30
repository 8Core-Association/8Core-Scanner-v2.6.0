#!/bin/bash
# ==========================================================
# 8Core IOC Scanner v3.3
# Copyright (c) 2026 8Core
# Author: Tomislav Galić / 8Core
# Web: https://8core.hr
# Output: MariaDB + live tail log
# Novosti v3.3: DB-driven pravila iz scanner_rules,
#               ignore lista iz scanner_ignore_list,
#               poboljšani sql_escape
# ==========================================================

BASE="/home"
TARGET_TYPE="all"
TARGET_VALUE="/home"

# Konfiguracija se učitava iz scanner-db.conf koji se nalazi u
# istom direktoriju kao i ova skripta.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${SCRIPT_DIR}/scanner-db.conf"

for arg in "$@"; do
  case "$arg" in
    --all)
      BASE="/home"
      TARGET_TYPE="all"
      TARGET_VALUE="/home"
      ;;
    --account=*)
      ACCOUNT="${arg#*=}"
      BASE="/home/$ACCOUNT"
      TARGET_TYPE="account"
      TARGET_VALUE="$ACCOUNT"
      ;;
    --path=*)
      BASE="${arg#*=}"
      TARGET_TYPE="custom_path"
      TARGET_VALUE="$BASE"
      ;;
    --config=*)
      CONFIG="${arg#*=}"
      ;;
    *)
      echo "Nepoznati argument: $arg"
      echo "Upotreba: $0 --all | --account=korisnik | --path=/home/korisnik/putanja [--config=/putanja/do/scanner-db.conf]"
      exit 1
      ;;
  esac
done

[ -f "$CONFIG" ] || { echo "GREŠKA: Nedostaje config: $CONFIG"; exit 1; }
source "$CONFIG"

DB_HOST="${DB_HOST//$'\r'/}"
DB_NAME="${DB_NAME//$'\r'/}"
DB_USER="${DB_USER//$'\r'/}"
DB_PASS="${DB_PASS//$'\r'/}"
DB_CHARSET="${DB_CHARSET//$'\r'/}"

# Putanje iz konfiguracije ili default
LOG_PATH="${LOG_PATH:-${SCRIPT_DIR}/logs}"
RUN_LOG="${LOG_PATH}/ioc-scan-live.log"

# Karantena se isključuje iz skeniranja
QUARANTINE_BASE_PATH="${QUARANTINE_BASE_PATH:-${QUARANTINE_PATH:-}}"

mkdir -p "$LOG_PATH"
: > "$RUN_LOG"

log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$RUN_LOG"
}

die() {
  log "GREŠKA: $*"
  exit 1
}

[ -d "$BASE" ] || die "Putanja skeniranja ne postoji: $BASE"

case "$BASE" in
  /home|/home/*) ;;
  *) die "Nesigurna putanja blokirana: $BASE" ;;
esac

mysql_run() {
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" --default-character-set="${DB_CHARSET:-utf8mb4}" -N -B -e "$1"
}

# Stripa null bajtove i escapeuje single-quote za SQL string literal
sql_escape() {
  printf "%s" "$1" | tr -d '\000' | sed "s/'/''/g"
}

guess_source() {
  local file="$1"

  case "$file" in
    *"/wp-content/uploads/"*)             echo "wordpress_upload|web_upload" ;;
    *"/wp-content/plugins/"*)             echo "wordpress_plugin|plugin" ;;
    *"/wp-content/themes/"*)              echo "wordpress_theme|theme" ;;
    *"/administrator/components/"*)       echo "joomla_admin_component|component" ;;
    *"/components/"*)                     echo "joomla_component|component" ;;
    *"/media/com_sppagebuilder/"*)        echo "sppagebuilder|builder" ;;
    *"/tmp/"*)                            echo "tmp_runtime_or_upload|tmp" ;;
    *"/cache/"*)                          echo "cache_runtime|cache" ;;
    *"/.well-known/"*)                    echo "well_known|system" ;;
    *)                                    echo "unknown|unknown" ;;
  esac
}

log "8Core IOC Scanner v3.3 pokrenut"
log "Base: $BASE"
log "Target tip: $TARGET_TYPE"
log "Target vrijednost: $TARGET_VALUE"
log "Baza: $DB_NAME@$DB_HOST"
log "Config: $CONFIG"

mysql_run "SELECT 1;" >/dev/null || die "Konekcija na bazu neuspješna"

mysql_run "
CREATE TABLE IF NOT EXISTS scans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  base_path VARCHAR(500) NOT NULL,
  target_type VARCHAR(30) NULL,
  target_value VARCHAR(255) NULL,
  files_found INT UNSIGNED DEFAULT 0,
  status VARCHAR(30) DEFAULT 'RUNNING',
  INDEX(status),
  INDEX(started_at),
  INDEX(target_type),
  INDEX(target_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS findings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  rule_name VARCHAR(150) NOT NULL,
  risk VARCHAR(20) NOT NULL,
  account_name VARCHAR(80) NULL,
  owner_name VARCHAR(80) NULL,
  group_name VARCHAR(80) NULL,
  perms VARCHAR(20) NULL,
  file_size BIGINT UNSIGNED NULL,
  file_name VARCHAR(255) NULL,
  file_ext VARCHAR(30) NULL,
  file_path TEXT NOT NULL,
  relative_path TEXT NULL,
  mtime DATETIME NULL,
  ctime DATETIME NULL,
  birth_time DATETIME NULL,
  detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  source_guess VARCHAR(255) NULL,
  source_type VARCHAR(80) NULL,
  sha256 CHAR(64) NULL,
  action_status VARCHAR(40) NOT NULL DEFAULT 'new',
  action_note TEXT NULL,
  action_at DATETIME NULL,
  action_by VARCHAR(80) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(scan_id),
  INDEX(risk),
  INDEX(rule_name),
  INDEX(account_name),
  INDEX(owner_name),
  INDEX(file_ext),
  INDEX(detected_at),
  INDEX(action_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" || die "Kreiranje tablica neuspješno"

mysql_run "
ALTER TABLE scans
  ADD COLUMN IF NOT EXISTS target_type VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS target_value VARCHAR(255) NULL;
" >/dev/null 2>&1

mysql_run "
ALTER TABLE findings
  ADD COLUMN IF NOT EXISTS account_name VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS relative_path TEXT NULL,
  ADD COLUMN IF NOT EXISTS ctime DATETIME NULL,
  ADD COLUMN IF NOT EXISTS birth_time DATETIME NULL,
  ADD COLUMN IF NOT EXISTS detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS source_guess VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS source_type VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS file_ext VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS action_status VARCHAR(40) NOT NULL DEFAULT 'new',
  ADD COLUMN IF NOT EXISTS action_note TEXT NULL,
  ADD COLUMN IF NOT EXISTS action_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS action_by VARCHAR(80) NULL;
" >/dev/null 2>&1

SCAN_ID=$(mysql_run "
INSERT INTO scans (started_at, base_path, target_type, target_value)
VALUES (NOW(), '$(sql_escape "$BASE")', '$(sql_escape "$TARGET_TYPE")', '$(sql_escape "$TARGET_VALUE")');
SELECT LAST_INSERT_ID();
")

[ -n "$SCAN_ID" ] || die "Ne mogu kreirati scan zapis"

log "Scan ID: $SCAN_ID"

# ── Ignore lista ─────────────────────────────────────────────────────────────
IGNORE_FILES=()
IGNORE_PATHS=()
IGNORE_HASHES=()
IGNORE_USERS=()

load_ignore_lists() {
  local raw

  raw=$(mysql_run "SELECT value FROM scanner_ignore_list WHERE category='file';" 2>/dev/null)
  [ -n "$raw" ] && mapfile -t IGNORE_FILES <<< "$raw"

  raw=$(mysql_run "SELECT value FROM scanner_ignore_list WHERE category='path';" 2>/dev/null)
  [ -n "$raw" ] && mapfile -t IGNORE_PATHS <<< "$raw"

  raw=$(mysql_run "SELECT value FROM scanner_ignore_list WHERE category='hash';" 2>/dev/null)
  [ -n "$raw" ] && mapfile -t IGNORE_HASHES <<< "$raw"

  raw=$(mysql_run "SELECT value FROM scanner_ignore_list WHERE category='user';" 2>/dev/null)
  [ -n "$raw" ] && mapfile -t IGNORE_USERS <<< "$raw"

  log "Ignore lista učitana: ${#IGNORE_FILES[@]} fajlova, ${#IGNORE_PATHS[@]} putanja, ${#IGNORE_HASHES[@]} hasheva, ${#IGNORE_USERS[@]} korisnika"
}

is_soft_ignored() {
  local file="$1"
  local sha="$2"
  local entry

  # Provjera točne putanje fajla
  for entry in "${IGNORE_FILES[@]}"; do
    [ -z "$entry" ] && continue
    [ "$file" = "$entry" ] && return 0
  done

  # Provjera path prefiksa
  for entry in "${IGNORE_PATHS[@]}"; do
    [ -z "$entry" ] && continue
    case "$file" in "${entry}"*) return 0 ;; esac
  done

  # Provjera sha256 hasha
  if [ -n "$sha" ]; then
    for entry in "${IGNORE_HASHES[@]}"; do
      [ -z "$entry" ] && continue
      [ "$sha" = "$entry" ] && return 0
    done
  fi

  # Provjera account/korisnika (3. segment putanje: /home/<account>/...)
  local acc
  acc=$(echo "$file" | awk -F/ '{print $3}')
  for entry in "${IGNORE_USERS[@]}"; do
    [ -z "$entry" ] && continue
    [ "$acc" = "$entry" ] && return 0
  done

  return 1
}

# ── Umetanje nalaza u bazu ───────────────────────────────────────────────────
# $1 = rule name, $2 = risk, $3 = file path, $4 = is_hard (0|1, default 0)
# is_hard=1: hard malware pravilo — ignore lista se ne primjenjuje
# is_hard=0: soft pravilo — fajl se preskače ako matchira allowlist
insert_finding() {
  local rule="$1"
  local risk="$2"
  local file="$3"
  local is_hard="${4:-0}"

  [ -f "$file" ] || return

  local mtime ctime birth owner group size fname perms sha account rel ext source source_guess source_type

  mtime=$(stat -c '%y' "$file" 2>/dev/null | cut -d'.' -f1)
  ctime=$(stat -c '%z' "$file" 2>/dev/null | cut -d'.' -f1)
  birth=$(stat -c '%w' "$file" 2>/dev/null | cut -d'.' -f1)

  [ "$birth" = "-" ] && birth=""

  owner=$(stat -c '%U' "$file" 2>/dev/null)
  group=$(stat -c '%G' "$file" 2>/dev/null)
  size=$(stat -c '%s' "$file" 2>/dev/null)
  perms=$(stat -c '%a' "$file" 2>/dev/null)
  fname=$(basename "$file")

  account=$(echo "$file" | awk -F/ '{print $3}')
  rel="${file#/home/$account/}"

  ext="${fname##*.}"
  [ "$ext" = "$fname" ] && ext=""

  source=$(guess_source "$file")
  source_guess="${source%%|*}"
  source_type="${source##*|}"

  # SHA256: uvijek za hard pravila, HIGH/CRITICAL, i kad postoji hash ignore lista
  if [ "$is_hard" = "1" ] || [ "$risk" = "HIGH" ] || [ "$risk" = "CRITICAL" ] || [ "${#IGNORE_HASHES[@]}" -gt 0 ]; then
    sha=$(sha256sum "$file" 2>/dev/null | awk '{print $1}')
  else
    sha=""
  fi

  # Provjera ignore liste:
  # Hard pravila (is_hard=1) uvijek pobijede — soft ignore se ne primjenjuje.
  # Soft pravila (is_hard=0) se preskaču ako je fajl na allowlisti.
  if [ "$is_hard" != "1" ]; then
    if is_soft_ignored "$file" "$sha"; then
      log "ALLOWLIST (soft pravilo '$rule') $file"
      return 0
    fi
  else
    # Hard pravilo: ignoriramo soft allowlist, ali logiramo ako bi bio ignoriran
    if is_soft_ignored "$file" "$sha"; then
      log "HARD OVERRIDE (allowlist ignorirana za '$rule') $file"
    fi
  fi

  mysql_run "
  INSERT INTO findings
  (
    scan_id, rule_name, risk,
    account_name, owner_name, group_name, perms,
    file_size, file_name, file_ext, file_path, relative_path,
    mtime, ctime, birth_time, detected_at,
    source_guess, source_type, sha256
  )
  VALUES (
    $SCAN_ID,
    '$(sql_escape "$rule")',
    '$(sql_escape "$risk")',
    '$(sql_escape "$account")',
    '$(sql_escape "$owner")',
    '$(sql_escape "$group")',
    '$(sql_escape "$perms")',
    ${size:-0},
    '$(sql_escape "$fname")',
    '$(sql_escape "$ext")',
    '$(sql_escape "$file")',
    '$(sql_escape "$rel")',
    NULLIF('$(sql_escape "$mtime")',''),
    NULLIF('$(sql_escape "$ctime")',''),
    NULLIF('$(sql_escape "$birth")',''),
    NOW(),
    '$(sql_escape "$source_guess")',
    '$(sql_escape "$source_type")',
    '$(sql_escape "$sha")'
  );
  " >/dev/null

  log "NAĐENO [$risk] $rule :: $file"
}

# ── Pomoćnik: find s automatskim quarantine prune ────────────────────────────
_scan_find() {
  if [ -n "$QUARANTINE_BASE_PATH" ] && [[ "$QUARANTINE_BASE_PATH" == "$BASE"* ]]; then
    find "$BASE" -path "$QUARANTINE_BASE_PATH" -prune -o "$@" -print 2>/dev/null
  else
    find "$BASE" "$@" 2>/dev/null
  fi
}

scan_pattern() {
  local title="$1"
  local risk="$2"
  local is_hard="$3"
  shift 3

  log "Skeniranje: $title [$risk]$([ "$is_hard" = "1" ] && echo " [HARD]")"

  _scan_find "$@" | while IFS= read -r file; do
    insert_finding "$title" "$risk" "$file" "$is_hard"
  done
}

# ── Učitaj ignore listu prije skeniranja ─────────────────────────────────────
load_ignore_lists

# ── Ugrađena pravila (hardkodirana, uvijek aktivna) ───────────────────────────
# Filename/path pravila: is_hard=0 — temelje se na imenu/putanji, ne na sadržaju s
# request inputom, pa mogu imati false-positive matcheve na legitimnim fajlovima.
scan_pattern "filefuns.php" "CRITICAL" 0 \
  -type f -name "filefuns.php"

scan_pattern ".sys-* datoteke" "HIGH" 0 \
  -type f -name ".sys-*"

scan_pattern "adman marker txt" "HIGH" 0 \
  -type f -name "adman.*.txt"

scan_pattern "mixed-case PHP ekstenzije" "MEDIUM" 0 \
  -type f \( -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.PHp" -o -name "*.phP" -o -name "*.pHP" \)

scan_pattern "tmp izvršni web fajlovi" "HIGH" 0 \
  -type f \( -path "*/tmp/*.php" -o -path "*/tmp/*.php5" -o -path "*/tmp/*.phtml" -o -path "*/tmp/*.phar" \)

scan_pattern "sumnjivi random index.php direktoriji" "HIGH" 0 \
  -type f -name "index.php" -size +10k \
  -regextype posix-extended \
  -regex '.*/([0-9a-f]{5,6}|[0-9]{5,6})/index\.php$'

scan_pattern "cache.php sumnjive lokacije" "MEDIUM" 0 \
  -type f -name "cache.php"

# ── Grep pravila splitana po is_hard ─────────────────────────────────────────
#
# HARD: opasni request-execution patternovi koji zahtijevaju kontekst.
# Allowlist ne vrijedi.
#
# Pattern pokriva:
#   1. eval/assert/system/shell_exec/passthru/exec/popen/proc_open + $_(POST|GET|REQUEST|COOKIE)
#   2. preg_replace s /e modifierom
#   3. eval(base64_decode(  /  eval(gzinflate(  /  eval(str_rot13(  /  eval(gzdecode(
#   4. include/require('http://') — remote PHP execution (file_get_contents sam = soft)
#   5. eval(file_get_contents('http://')) — remote download + direktno izvršavanje

_HARD_PATTERN='(eval|assert|system|shell_exec|passthru|exec|popen|proc_open)\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)'
_HARD_PATTERN="${_HARD_PATTERN}"'|preg_replace\s*\(\s*['"'"'"][^'"'"'"]{0,200}\/e['"'"'"]'
_HARD_PATTERN="${_HARD_PATTERN}"'|eval\s*\(\s*(base64_decode|gzinflate|gzdecode|gzuncompress|str_rot13)\s*\('
_Q="'\""
_HARD_PATTERN="${_HARD_PATTERN}|(include|require|include_once|require_once)\\s*\\(\\s*[${_Q}](https?|ftp)://"
_HARD_PATTERN="${_HARD_PATTERN}|eval\\s*\\(\\s*file_get_contents\\s*\\(\\s*[${_Q}](https?|ftp)://"

log "Skeniranje: opasni request-execution patternovi [CRITICAL] [HARD]"
_scan_find \
  -type f \( -name "*.php" -o -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.phtml" -o -name "*.php5" -o -name "*.phar" \) | \
  xargs -r grep -IlP "$_HARD_PATTERN" 2>/dev/null | \
  while IFS= read -r file; do
    insert_finding "opasni request-execution patternovi" "CRITICAL" "$file" "1"
  done

# SOFT: suspektne funkcije i remote URL download — česti false-positive u vendoru,
# libraryima, legitnim update/API pozivima; suppressaju se allowlistom.
log "Skeniranje: suspektne PHP funkcije [MEDIUM]"
_scan_find \
  -type f \( -name "*.php" -o -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.phtml" -o -name "*.php5" -o -name "*.phar" \) | \
  xargs -r grep -IlE "eval\(|shell_exec\(|passthru\(|exec\(|system\(|popen\(|proc_open\(|base64_decode\(|gzinflate\(|str_rot13\(|curl_exec\(" 2>/dev/null | \
  while IFS= read -r file; do
    insert_finding "suspektne PHP funkcije" "MEDIUM" "$file" "0"
  done

log "Skeniranje: remote URL download [LOW]"
_scan_find \
  -type f \( -name "*.php" -o -name "*.PHP" -o -name "*.Php" -o -name "*.pHp" -o -name "*.phtml" -o -name "*.php5" -o -name "*.phar" \) | \
  xargs -r grep -IlP "file_get_contents\s*\(\s*['\"]https?://|fopen\s*\(\s*['\"]https?://" 2>/dev/null | \
  while IFS= read -r file; do
    insert_finding "remote URL download" "LOW" "$file" "0"
  done

# ── Dinamička pravila iz baze (scanner_rules, active=1) ──────────────────────
run_dynamic_rules() {
  log "Pokretanje dinamičkih pravila iz baze..."

  local rules_raw
  rules_raw=$(mysql_run "SELECT id, name, type, pattern, IFNULL(extensions,''), risk, IFNULL(is_hard,0) FROM scanner_rules WHERE active=1 ORDER BY id ASC;" 2>/dev/null)

  if [ -z "$rules_raw" ]; then
    log "Nema aktivnih dinamičkih pravila u bazi."
    return 0
  fi

  local count=0
  while IFS=$'\t' read -r rule_id rule_name rule_type rule_pattern rule_exts rule_risk rule_is_hard; do
    [ -z "$rule_id" ] && continue

    # Normalizacija: tretiraj prazno kao 0
    [ -z "$rule_is_hard" ] && rule_is_hard=0

    log "Dinamičko pravilo [$rule_risk] $rule_name (tip: $rule_type, hard: $rule_is_hard)"
    count=$((count + 1))

    case "$rule_type" in

      filename)
        _scan_find -type f -name "$rule_pattern" | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      path)
        _scan_find -type f -path "*${rule_pattern}*" | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      regex)
        _scan_find -type f -regextype posix-extended -regex "$rule_pattern" | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      extension)
        local ext ext_args=() first=1
        for ext in $rule_pattern $rule_exts; do
          [ -z "$ext" ] && continue
          if [ "$first" -eq 1 ]; then
            ext_args+=( -name "*.${ext}" )
            first=0
          else
            ext_args+=( -o -name "*.${ext}" )
          fi
        done
        if [ "${#ext_args[@]}" -gt 0 ]; then
          _scan_find -type f \( "${ext_args[@]}" \) | while IFS= read -r file; do
            insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
          done
        fi
        ;;

      regex_content)
        local ext ext_args=() first=1
        if [ -n "$rule_exts" ]; then
          for ext in $rule_exts; do
            [ -z "$ext" ] && continue
            if [ "$first" -eq 1 ]; then
              ext_args+=( -name "*.${ext}" )
              first=0
            else
              ext_args+=( -o -name "*.${ext}" )
            fi
          done
        fi

        if [ "${#ext_args[@]}" -gt 0 ]; then
          _scan_find -type f \( "${ext_args[@]}" \)
        else
          _scan_find -type f
        fi | xargs -r grep -IlE "$rule_pattern" 2>/dev/null | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      chmod)
        _scan_find -type f -perm "$rule_pattern" | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      filesize)
        _scan_find -type f -size "$rule_pattern" | while IFS= read -r file; do
          insert_finding "$rule_name" "$rule_risk" "$file" "$rule_is_hard"
        done
        ;;

      sha256)
        log "SHA256 pravilo '$rule_name' — nije podržano u ovoj verziji enginea."
        ;;

      *)
        log "Nepoznati tip pravila '$rule_type' za '$rule_name' — preskačem."
        ;;
    esac

  done <<< "$rules_raw"

  log "Dinamička pravila završena: $count pravila izvršeno."
}

run_dynamic_rules

# ── Zaključivanje scana ───────────────────────────────────────────────────────
COUNT=$(mysql_run "SELECT COUNT(*) FROM findings WHERE scan_id=$SCAN_ID;")

mysql_run "
UPDATE scans
SET finished_at = NOW(),
    files_found = $COUNT,
    status = 'FINISHED'
WHERE id = $SCAN_ID;
" >/dev/null

log "8Core IOC Scanner završen"
log "Scan ID: $SCAN_ID"
log "Nalazi: $COUNT"
log "Log: tail -f $RUN_LOG"

echo "GOTOVO scan_id=$SCAN_ID nalazi=$COUNT"
