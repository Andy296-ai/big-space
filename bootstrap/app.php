<?php

use App\Console\Commands\PruneActivityLog;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // No-op while ACTIVITY_LOG_RETENTION_DAYS is unset (default: keep
        // forever) — safe to always register.
        $schedule->command(PruneActivityLog::class)->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // За reverse-proxy (nginx/Caddy, см. docs/PROJECT_OVERVIEW.md §11)
        // без списка доверенных прокси Request::isSecure() всегда врёт false —
        // PHP видит только локальное HTTP-соединение от прокси, не настоящую
        // схему клиента. От этого напрямую зависит HSTS (SecurityHeaders) и
        // флаг Secure у cookie сессии. Настраивается через
        // config/trustedproxy.php (TRUSTED_PROXIES в .env) — не здесь: на
        // этой стадии bootstrap ни env(), ни config() ещё недоступны
        // (config() кидает BindingResolutionException, а сырой env() вне
        // config-файлов тихо вернёт null при закэшированном конфиге в проде).
        // TrustProxies сам читает config('trustedproxy.proxies') во время
        // обработки запроса — см. Illuminate\Http\Middleware\TrustProxies::setTrustedProxyIpAddresses().

        $middleware->web(prepend: [
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
