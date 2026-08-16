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


# opcache.enable_cli — по умолчанию выключен для CLI SAPI, а php artisan
# serve именно к нему и относится: без него КАЖДЫЙ запрос заново
# компилирует весь Laravel + vendor с нуля (сотни файлов), и это особенно
# бьёт по параллельным запросам (несколько одновременно открытых fetch
# с фронта конкурируют за CPU на компиляции) — выглядит как зависание
# страницы при открытии, например, мессенджера. С опцией — только первый
# запрос на воркер холодный, дальше отдаётся из общего кэша байткода.
#
# PHP_CLI_SERVER_WORKERS + --no-reload — включает реальную параллельность
# у встроенного сервера (по умолчанию он обрабатывает запросы строго по
# одному). Без --no-reload Laravel эту переменную окружения игнорирует.
PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=1 artisan serve --port="$PORT" --no-reload &
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
