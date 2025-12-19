#!/usr/bin/env bash
set -euo pipefail

# Deploy Laravel backend to Aruba via FTP (lftp mirror) – lean/quiet edition

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
DIST_DIR="$ROOT_DIR/dist"
LOCAL_SRC="$DIST_DIR/backend"
ENV_FILE="$ROOT_DIR/.deploy.env"

# --- New: quiet/brief + logging --------------------------------------------
QUIET=true          # riduce output console del nostro wrapper
BRIEF=true          # stampa solo step & esito
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
LOG_DIR="$ROOT_DIR/.deploy"
LOG_FILE="$LOG_DIR/deploy_$TIMESTAMP.log"
mkdir -p "$LOG_DIR"

# Flags
DRY_RUN=false
INCLUDE_ENV=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --include-env) INCLUDE_ENV=true ;;
    --no-quiet) QUIET=false ;;
    --verbose) BRIEF=false ;;
  esac
done

have() { command -v "$1" >/dev/null 2>&1; }

if ! have lftp; then
  echo "ERROR: lftp not installed. Install with: sudo apt-get install -y lftp" >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  cat >&2 <<EOF
ERROR: $ENV_FILE not found.
Create it with:
  FTP_HOST=ftp.your-host.tld
  FTP_USER=your-user
  FTP_PASS=your-password
  FTP_REMOTE_DIR=/acoinazionale2026
  # optional:
  # FTP_SSL_ALLOW=true
  # FTP_SSL_VERIFY=true
  # FTP_PREFER_EPSV=yes
  # FTP_PARALLEL=2
EOF
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

: "${FTP_HOST:?FTP_HOST is required in .deploy.env}"
: "${FTP_USER:?FTP_USER is required in .deploy.env}"
: "${FTP_PASS:?FTP_PASS is required in .deploy.env}"
: "${FTP_REMOTE_DIR:?FTP_REMOTE_DIR is required in .deploy.env}"
FTP_SSL_ALLOW=${FTP_SSL_ALLOW:-true}
FTP_SSL_VERIFY=${FTP_SSL_VERIFY:-true}
FTP_PREFER_EPSV=${FTP_PREFER_EPSV:-yes}
FTP_PARALLEL=${FTP_PARALLEL:-2}

$BRIEF && echo "[1/3] Build produzione (dist/backend)…"
"$ROOT_DIR/scripts/make_backend_zip.sh" >/dev/null

if [[ ! -d "$LOCAL_SRC" ]]; then
  echo "ERROR: $LOCAL_SRC not found after build" >&2
  exit 1
fi

# Ensure Laravel config/routes caches are removed so env toggles (es. Telescope) take effect.
rm -f "$LOCAL_SRC/bootstrap/cache/config.php" \
      "$LOCAL_SRC/bootstrap/cache/config.php.php" \
      "$LOCAL_SRC/bootstrap/cache/routes.php" \
      "$LOCAL_SRC/bootstrap/cache/routes-v7.php"

# --- Esclusioni estese (meno byte) ------------------------------------------
EXCLUDES=(
  "--exclude-glob" ".git*"
  "--exclude-glob" ".DS_Store"
  "--exclude-glob" "node_modules"
  "--exclude-glob" "tests"
  "--exclude-glob" "storage/logs/*"
  "--exclude-glob" "storage/debugbar/*"
  "--exclude-glob" "storage/framework/cache/*"
  "--exclude-glob" "storage/framework/sessions/*"
  "--exclude-glob" "public/storage"
  "--exclude-glob" ".github"
  "--exclude-glob" "README.md"
  "--exclude-glob" "package*.json"
  "--exclude-glob" "vite.config.js"
  "--exclude-glob" "*.map"
  "--exclude-glob" "*.log"
  "--exclude-glob" "vendor/laravel/sail/.docker"
)
[[ "$INCLUDE_ENV" == "false" ]] && EXCLUDES+=("--exclude" ".env" "--exclude-glob" ".env.*")

DRY_ARG=""
[[ "$DRY_RUN" == "true" ]] && DRY_ARG="--dry-run"

$BRIEF && echo "[2/3] Connessione a $FTP_HOST (FTPS=${FTP_SSL_ALLOW})…"
$BRIEF && echo "[3/3] Sync verso $FTP_REMOTE_DIR/backend (parallel=${FTP_PARALLEL})…"

# --- LFTP: silenzioso, robusto, veloce --------------------------------------
# Tutto l’output di lftp finisce nel LOG_FILE; console = solo righe sintetiche
{
  echo "== $(date -Iseconds) :: DEPLOY START =="
  echo "host=$FTP_HOST remote=$FTP_REMOTE_DIR/backend dry_run=$DRY_RUN"

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP_CMDS
set ftp:ssl-allow ${FTP_SSL_ALLOW}
set ssl:verify-certificate ${FTP_SSL_VERIFY}
set ftp:passive-mode true
set ftp:prefer-epsv ${FTP_PREFER_EPSV}
set net:max-retries 2
set net:timeout 20
set net:persist-retries 1
set mirror:parallel-transfer-count ${FTP_PARALLEL}
set mirror:use-pget-n 0
set xfer:clobber on
set cmd:trace 0
set cmd:fail-exit yes
# opzionale: forza FTPS esplicito se necessario
# set ftp:ssl-force true

set cmd:fail-exit no
mkdir -p $FTP_REMOTE_DIR/backend
set cmd:fail-exit yes
cd $FTP_REMOTE_DIR/backend || cd $FTP_REMOTE_DIR

mirror -R \
  --only-newer \
  --delete \
  --no-perms \
  --verbose=0 \
  --parallel=${FTP_PARALLEL} \
  ${DRY_ARG} \
  ${EXCLUDES[@]} \
  "$LOCAL_SRC" "$FTP_REMOTE_DIR/backend"
quit
LFTP_CMDS

  RC=$?
  echo "return_code=$RC"
  echo "== $(date -Iseconds) :: DEPLOY END =="
  exit $RC
} >>"$LOG_FILE" 2>&1

RC=$?
if [[ $RC -eq 0 ]]; then
  echo "Deploy OK. Log: $LOG_FILE"
else
  echo "Deploy FAILED (rc=$RC). Vedi log: $LOG_FILE" >&2
  exit $RC
fi
