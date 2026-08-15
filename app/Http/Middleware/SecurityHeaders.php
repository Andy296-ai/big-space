<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Проставляет защитные заголовки и генерирует CSP-nonce до рендера
     * вида, чтобы Vite (@vite, @fonts) и наш инлайновый скрипт в
     * app.blade.php могли подписаться тем же значением.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );
        // Не перетираем CSP, если его уже явно выставил сам контроллер (см.
        // MessageAttachmentController::preview() — строгий "sandbox" для
        // HTML-вложений мессенджера): тот случай сознательно строже общего
        // CSP приложения, а не дополняет его.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        // HSTS имеет смысл только поверх HTTPS — на голом http:// (как сейчас на
        // localhost) браузер его всё равно игнорирует, но незачем светить лишний
        // заголовок там, где соединение не защищено.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $devServer = $this->viteDevServerOrigin();
        $devSocket = $devServer !== null ? preg_replace('#^http#', 'ws', $devServer) : null;

        $scriptSrc = array_filter(["'self'", "'nonce-{$nonce}'", $devServer]);
        // Инлайновый индикатор загрузки страниц из @inertiajs/core (NProgress) не
        // подписывается nonce'ом — у него фиксированный CSS, поэтому вместо
        // unsafe-inline разрешаем его точным хэшем.
        $styleSrc = array_filter([
            "'self'",
            "'nonce-{$nonce}'",
            "'sha256-o9wGWu2JerZ/3zHqAg2OdAfwNt3JRjOntlQBuBKK6hA='",
            $devServer,
        ]);
        $connectSrc = array_filter(["'self'", $devServer, $devSocket, $this->reverbOrigin()]);

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            'script-src '.implode(' ', $scriptSrc),
            'style-src '.implode(' ', $styleSrc),
            // Тайлы карты (Leaflet + OpenStreetMap/CARTO), см. NodeInfoDialog.vue.
            "img-src 'self' data: https://*.basemaps.cartocdn.com",
            'connect-src '.implode(' ', $connectSrc),
            'font-src '.implode(' ', array_filter(["'self'", 'data:', $devServer])),
            "object-src 'none'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }

    /** Адрес Reverb для живых обновлений графа (см. resources/js/lib/echo.ts) — ws/wss в зависимости от схемы. */
    private function reverbOrigin(): ?string
    {
        $host = config('broadcasting.connections.reverb.options.host');

        if (! $host) {
            return null;
        }

        $port = config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.useTLS') ? 'wss' : 'ws';

        return "{$scheme}://{$host}:{$port}";
    }

    /** Адрес dev-сервера Vite (файл public/hot), пока он поднят — иначе null. */
    private function viteDevServerOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($hotFile));

        return $contents !== '' ? $contents : null;
    }
}
