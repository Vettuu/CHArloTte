#!/usr/bin/env bash
set -euo pipefail

# Build frontend + backend artifacts and deploy them to an Aruba FTP hosting.
# Requirements:
#   - bash + rsync + lftp + composer + npm installed locally
#   - .env.deploy (copy from .env.deploy.example) with FTP credentials
#   - apps/backend/.env.production with production env values (copied to dist/.env)
#   - apps/frontend/.env.production (optional, used during npm build)

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
DIST_DIR="$ROOT_DIR/dist"
BACKEND_SRC="$ROOT_DIR/apps/backend"
FRONTEND_SRC="$ROOT_DIR/apps/frontend"
BACKEND_DIST="$DIST_DIR/backend"
FRONTEND_DIST="$DIST_DIR/frontend"
ENV_FILE="$ROOT_DIR/.env.deploy"

DRY_RUN=false
SKIP_BUILD=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --skip-build) SKIP_BUILD=true ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

if [[ ! -f "$ENV_FILE" ]]; then
  cat >&2 <<EOF
ERROR: $ENV_FILE not found.
Run: cp .env.deploy.example .env.deploy
and fill it with FTP_HOST, FTP_USER, FTP_PASS, FTP_REMOTE_DIR.
EOF
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

: "${FTP_HOST:?FTP_HOST missing in .env.deploy}"
: "${FTP_USER:?FTP_USER missing in .env.deploy}"
: "${FTP_PASS:?FTP_PASS missing in .env.deploy}"
: "${FTP_REMOTE_DIR:?FTP_REMOTE_DIR missing in .env.deploy}"
FTP_SSL_ALLOW=${FTP_SSL_ALLOW:-false}

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "ERROR: $1 is required but not installed." >&2
    exit 1
  fi
}

need_cmd lftp
need_cmd composer
need_cmd npm

copy_backend_source() {
  rm -rf "$BACKEND_DIST"
  mkdir -p "$BACKEND_DIST"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude=".git" \
      --exclude=".github" \
      --exclude="node_modules" \
      "$BACKEND_SRC/" "$BACKEND_DIST/"
  else
    cp -a "$BACKEND_SRC/." "$BACKEND_DIST/"
    rm -rf "$BACKEND_DIST/.git" "$BACKEND_DIST/.github" "$BACKEND_DIST/node_modules" || true
  fi
}

build_backend() {
  echo "[1/4] Preparing Laravel backend dist"
  copy_backend_source

  pushd "$BACKEND_DIST" >/dev/null
  rm -rf vendor
  rm -f .env .env.production .env.deploy
  composer install --no-dev --optimize-autoloader --prefer-dist

  rm -rf storage/logs/* storage/framework/{cache,sessions,views}/* 2>/dev/null || true
  mkdir -p storage/logs storage/framework/{cache,sessions,views}

  if [[ -f "$BACKEND_SRC/.env.production" ]]; then
    cp "$BACKEND_SRC/.env.production" .env
  else
    echo "WARNING: $BACKEND_SRC/.env.production non trovato. Assicurati di caricare manualmente il .env sul server." >&2
  fi
  popd >/dev/null
}

build_frontend() {
  echo "[2/4] Building Next.js static export"
  pushd "$FRONTEND_SRC" >/dev/null
  if [[ -f package-lock.json ]]; then
    npm ci
  else
    npm install
  fi
  npm run build:static
  popd >/dev/null

  rm -rf "$FRONTEND_DIST"
  mkdir -p "$FRONTEND_DIST"
  if [[ ! -d "$FRONTEND_SRC/out" ]]; then
    echo "ERROR: Next export non ha prodotto la cartella out/" >&2
    exit 1
  fi
  cp -a "$FRONTEND_SRC/out/." "$FRONTEND_DIST/"
}

if [[ "$SKIP_BUILD" == "false" ]]; then
  rm -rf "$DIST_DIR"
  mkdir -p "$DIST_DIR"

  build_backend
  build_frontend
else
  echo "Skip build abilitato: uso dist/ esistente"
fi

if [[ ! -d "$BACKEND_DIST" || ! -d "$FRONTEND_DIST" ]]; then
  echo "ERROR: dist/backend o dist/frontend non trovate. Eseguire senza --skip-build." >&2
  exit 1
fi

DRY_ARG=""
if [[ "$DRY_RUN" == "true" ]]; then
  DRY_ARG="--dry-run"
  echo "DRY RUN attivo: nessun file verrà effettivamente caricato."
fi

echo "[3/4] Connetto a $FTP_HOST per sincronizzare backend/frontend"

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP_CMDS
set ftp:ssl-allow ${FTP_SSL_ALLOW}
set net:max-retries 2
set net:timeout 25
set mirror:parallel-transfer-count 4
set mirror:use-pget-n 4
set ftp:prefer-epsv no

mkdir -p $FTP_REMOTE_DIR/backend
mirror -R --only-newer --delete ${DRY_ARG} "$BACKEND_DIST" "$FTP_REMOTE_DIR/backend"

mkdir -p $FTP_REMOTE_DIR/frontend
mirror -R --only-newer --delete ${DRY_ARG} "$FRONTEND_DIST" "$FTP_REMOTE_DIR/frontend"

quit
LFTP_CMDS

echo "[4/4] Deploy completato su $FTP_REMOTE_DIR (backend + frontend)."
