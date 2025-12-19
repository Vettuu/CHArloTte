#!/usr/bin/env bash
set -euo pipefail

# Deploy the Laravel backend to Aruba via FTP using lftp mirror
# Prereqs:
#  - bash environment (Linux/WSL/macOS)
#  - lftp installed: sudo apt-get install -y lftp
#  - create a .deploy.env file next to this script with:
#      FTP_HOST=ftp.your-host.tld
#      FTP_USER=your-user
#      FTP_PASS=your-password
#      FTP_REMOTE_DIR=/acoinazionale2026   # remote root where backend/ lives
#      # optional (defaults shown):
#      # FTP_SSL_ALLOW=true

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
DIST_DIR="$ROOT_DIR/dist"
LOCAL_SRC="$DIST_DIR/backend"
ENV_FILE="$ROOT_DIR/.deploy.env"

# Flags
DRY_RUN=false
INCLUDE_ENV=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --include-env) INCLUDE_ENV=true ;;
  esac
done

if ! command -v lftp >/dev/null 2>&1; then
  echo "ERROR: lftp is not installed. Install it with: sudo apt-get install -y lftp" >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  cat >&2 <<EOF
ERROR: $ENV_FILE not found.
Create it with contents like:
  FTP_HOST=ftp.your-host.tld
  FTP_USER=your-user
  FTP_PASS=your-password
  FTP_REMOTE_DIR=/acoinazionale2026
  # optional:
  # FTP_SSL_ALLOW=true
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

echo "[1/2] Building production backend tree (dist/backend)"
"$ROOT_DIR/scripts/make_backend_zip.sh" >/dev/null

if [[ ! -d "$LOCAL_SRC" ]]; then
  echo "ERROR: $LOCAL_SRC not found after build" >&2
  exit 1
fi

echo "[2/2] Uploading via FTP to $FTP_HOST:$FTP_REMOTE_DIR/backend"

EXCLUDES=(
  "--exclude-glob" ".git*"
  "--exclude-glob" "node_modules"
  "--exclude-glob" "tests"
  "--exclude-glob" "storage/logs/*"
  "--exclude-glob" "storage/debugbar/*"
  "--exclude-glob" "public/storage"
  "--exclude-glob" ".github"
  "--exclude-glob" "README.md"
  "--exclude-glob" "package*.json"
  "--exclude-glob" "vite.config.js"
  "--exclude-glob" "vendor/laravel/sail/.docker"
)

if [[ "$INCLUDE_ENV" == "false" ]]; then
  EXCLUDES+=("--exclude" ".env")
fi

DRY_ARG=""
if [[ "$DRY_RUN" == "true" ]]; then
  DRY_ARG="--dry-run"
  echo "Running in DRY RUN mode (no changes will be made)."
fi

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP_CMDS
set ftp:ssl-allow ${FTP_SSL_ALLOW}
set net:max-retries 2
set net:timeout 20
set mirror:parallel-transfer-count 5
set mirror:use-pget-n 5
set xfer:clobber on
set ftp:prefer-epsv no

# ensure remote path exists
mkdir -p $FTP_REMOTE_DIR/backend
cd $FTP_REMOTE_DIR/backend || cd $FTP_REMOTE_DIR

mirror -R \
  --only-newer \
  --parallel=2 \
  --delete \
  ${DRY_ARG} \
  ${EXCLUDES[@]} \
  "$LOCAL_SRC" "$FTP_REMOTE_DIR/backend"
quit
LFTP_CMDS

echo "Deploy completed. Backend synced to $FTP_REMOTE_DIR/backend"
