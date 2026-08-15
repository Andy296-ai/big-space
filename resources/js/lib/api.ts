/** Значение cookie; в document.cookie оно хранится URL-кодированным. */
function readCookie(name: string): string | null {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = document.cookie.match(
        new RegExp(`(?:^|;\\s*)${escaped}=([^;]*)`),
    );

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Тот же CSRF-токен, что apiFetch подставляет сам — экспортирован отдельно
 * для XMLHttpRequest-загрузок (см. MessengerThread.vue: видео грузится через
 * XHR ради upload-прогресса, которого у fetch нет).
 */
export function getCsrfToken(): string | null {
    return readCookie('XSRF-TOKEN');
}

/**
 * fetch, добавляющий CSRF-токен и JSON-заголовки.
 *
 * Роуты /api объявлены в routes/web.php, то есть проходят через web-middleware
 * вместе с проверкой CSRF. Голый fetch, в отличие от axios, токен сам не шлёт,
 * поэтому без этой обёртки любой POST/PUT/DELETE отлетает с 419. Laravel кладёт
 * токен в cookie XSRF-TOKEN и ждёт его обратно в одноимённом заголовке.
 */
export function apiFetch(
    url: string,
    options: RequestInit = {},
): Promise<Response> {
    const headers = new Headers(options.headers);

    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    // Для FormData заголовок ставит сам браузер — вместе с границей частей.
    if (
        options.body !== undefined &&
        !headers.has('Content-Type') &&
        !(options.body instanceof FormData)
    ) {
        headers.set('Content-Type', 'application/json');
    }

    const token = getCsrfToken();

    if (token) {
        headers.set('X-XSRF-TOKEN', token);
    }

    return fetch(url, { ...options, headers, credentials: 'same-origin' });
}
