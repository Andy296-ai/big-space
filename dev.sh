#!/usr/bin/env bash
# Запускает всё, что нужно для локальной разработки, одной командой:
# бэкенд (php artisan serve), WebSocket-сервер (Reverb) и фронтенд (Vite).
# Ctrl+C останавливает все три процесса разом.

set -euo pipefail
cd "$(dirname "$0")"

PORT="${PORT:-8000}"

pids=()

cleanup() {
    echo ""
    echo "Останавливаю dev-серверы..."
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

php artisan serve --port="$PORT" &
pids+=($!)

php artisan reverb:start &
pids+=($!)

npm run dev &
pids+=($!)

echo ""
echo "Готово. Сайт: http://localhost:$PORT"
echo "Останов: Ctrl+C"
echo ""

wait
