<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Illuminate\Http\Middleware\TrustProxies читает этот ключ сам, если
    | список прокси не задан статически в bootstrap/app.php (у нас не задан —
    | см. комментарий там). За reverse-proxy (nginx/Caddy, см.
    | docs/PROJECT_OVERVIEW.md §11) задайте TRUSTED_PROXIES в .env: IP через
    | запятую, либо '*', если приложение и так недоступно напрямую, минуя
    | прокси. Без этого Request::isSecure() всегда возвращает false —
    | от него зависят HSTS-заголовок и флаг Secure у cookie сессии.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
