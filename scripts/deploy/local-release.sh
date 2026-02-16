#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BRANCH="${DEPLOY_BRANCH:-main}"
COMMIT_MESSAGE="${1:-release: deploy production}"

cd "$ROOT_DIR"

echo "[local-release] Installing frontend dependencies..."
npm ci

echo "[local-release] Building Vite assets..."
npm run build

echo "[local-release] Staging git changes..."
git add -A

if git diff --cached --quiet; then
  echo "[local-release] No staged changes to commit."
else
  echo "[local-release] Creating commit: $COMMIT_MESSAGE"
  git commit -m "$COMMIT_MESSAGE"
fi

echo "[local-release] Pushing branch $BRANCH"
git push origin "$BRANCH"

echo "[local-release] Done."
