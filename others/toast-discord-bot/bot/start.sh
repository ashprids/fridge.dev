#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_BIN="$SCRIPT_DIR/venv/bin/python"

if [ ! -x "$PYTHON_BIN" ]; then
  echo "Python venv not found at $PYTHON_BIN"
  exit 1
fi

cd "$SCRIPT_DIR"
export PYTHONDONTWRITEBYTECODE=1
export PYTHONUNBUFFERED=1
exec "$PYTHON_BIN" "$SCRIPT_DIR/main.py"
