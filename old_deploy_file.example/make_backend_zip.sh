#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
SRC="$ROOT_DIR/backend"
DIST_DIR="$ROOT_DIR/dist"
OUT_DIR="$DIST_DIR/backend"
ZIP_PATH="$DIST_DIR/backend_deploy.zip"

rm -rf "$OUT_DIR" "$ZIP_PATH"
mkdir -p "$OUT_DIR"

# rsync if available for speed; fallback to cp -a
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='node_modules' \
    --exclude='tests' \
    "$SRC/" "$OUT_DIR/"
else
  cp -a "$SRC/." "$OUT_DIR/"
  rm -rf "$OUT_DIR/.git" "$OUT_DIR/.github" "$OUT_DIR/node_modules" "$OUT_DIR/tests" || true
fi

# Clean storage caches/logs/session/debug artifacts
rm -rf \
  "$OUT_DIR/storage/logs"/* \
  "$OUT_DIR/storage/framework/cache"/* \
  "$OUT_DIR/storage/framework/sessions"/* \
  "$OUT_DIR/storage/framework/views"/* \
  "$OUT_DIR/storage/debugbar"/* 2>/dev/null || true

# Ensure required writable dirs exist
mkdir -p \
  "$OUT_DIR/storage/logs" \
  "$OUT_DIR/storage/framework/cache" \
  "$OUT_DIR/storage/framework/sessions" \
  "$OUT_DIR/storage/framework/views"

# Create zip
mkdir -p "$DIST_DIR"
cd "$DIST_DIR"
if command -v zip >/dev/null 2>&1; then
  zip -rq "$ZIP_PATH" backend
else
  # Fallback to tar.gz if zip not available
  tar -czf backend_deploy.tar.gz backend
fi

echo "Created: $ZIP_PATH"

