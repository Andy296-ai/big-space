import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Reverb говорит по протоколу Pusher — laravel-echo ищет этот глобал сама.
window.Pusher = Pusher;

/** Тот же приём, что в api.ts: без него /broadcasting/auth отлетает с 419. */
function readCookie(name: string): string | null {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = document.cookie.match(
        new RegExp(`(?:^|;\\s*)${escaped}=([^;]*)`),
    );

    return match ? decodeURIComponent(match[1]) : null;
}

let echo: Echo<'reverb'> | null = null;

/** Один на всю вкладку — переиспользуется между сменами пространства. */
export function getEcho(): Echo<'reverb'> {
    if (!echo) {
        echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 80,
            wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
            forceTLS:
                (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            auth: {
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
                },
            },
        });
    }

    return echo;
}
