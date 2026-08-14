<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Приложение не рассчитано на сторонних cross-origin потребителей API —
    | сам Inertia-фронт и десктопный Tauri-клиент (nodus-desktop) всегда
    | обращаются к тому же origin'у, что и сервер. Поэтому вместо дефолтного
    | wildcard '*' от Laravel явно ограничиваем origin'ы адресом самого
    | приложения (APP_URL); при необходимости отдельного домена под фронт
    | добавьте его сюда.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('APP_URL', 'http://localhost:8000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
