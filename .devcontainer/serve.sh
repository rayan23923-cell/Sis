#!/usr/bin/env bash
# Runs every time the codespace starts: serves SIS on port 8000 in the background.
cd "$(dirname "$0")/.."
pkill -f "artisan serve" 2>/dev/null || true
nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/serve.log 2>&1 &
