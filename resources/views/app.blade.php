<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Конвенция Vite: dev-сервер читает этот тег и подписывает им инжектируемые <style> в HMR. --}}
        <meta property="csp-nonce" nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">

        @fonts

        {{-- Тема и язык применяются до первой отрисовки, иначе видно вспышку темы по умолчанию. --}}
        <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function () {
                try {
                    var saved = JSON.parse(localStorage.getItem('infinite-space-settings') || '{}');
                    if (saved.theme) {
                        document.documentElement.dataset.theme = saved.theme;
                    }
                    if (saved.lang) {
                        document.documentElement.lang = saved.lang;
                        document.documentElement.dir = saved.lang === 'fa' ? 'rtl' : 'ltr';
                    }
                } catch (e) {
                    // Настроек ещё нет или хранилище недоступно — останется тема по умолчанию.
                }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
