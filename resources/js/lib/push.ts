import { apiFetch } from './api';

const SW_URL = '/sw.js';
const VAPID_META_SELECTOR = 'meta[name="vapid-public-key"]';

export function isPushSupported(): boolean {
    return (
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * pushManager.subscribe() хочет ключ как BufferSource, а сервер отдаёт его в
 * base64url. Строим Uint8Array поверх настоящего ArrayBuffer явно — иначе TS
 * выводит Uint8Array<ArrayBufferLike> (допускает SharedArrayBuffer), который
 * не проходит под BufferSource.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const base64Safe = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64Safe);
    const buffer = new ArrayBuffer(raw.length);
    const output = new Uint8Array(buffer);

    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

function vapidPublicKey(): string {
    return (
        document.querySelector(VAPID_META_SELECTOR)?.getAttribute('content') ??
        ''
    );
}

/** Уже есть активная подписка в этом браузере — источник истины для UI-переключателя. */
export async function isPushEnabled(): Promise<boolean> {
    if (!isPushSupported()) {
        return false;
    }

    const registration = await navigator.serviceWorker.getRegistration(SW_URL);
    const subscription = await registration?.pushManager.getSubscription();

    return subscription != null;
}

/**
 * Регистрирует service worker, запрашивает разрешение (только по явному
 * действию пользователя — здесь, из тумблера в настройках, не при загрузке
 * страницы: браузеры штрафуют сайты за непрошеный запрос разрешения) и
 * подписывает на push.
 */
export async function enablePush(): Promise<boolean> {
    if (!isPushSupported()) {
        return false;
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return false;
    }

    const registration = await navigator.serviceWorker.register(SW_URL);
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey()),
    });

    const json = subscription.toJSON();

    await apiFetch('/api/push/subscribe', {
        method: 'POST',
        body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
    });

    return true;
}

export async function disablePush(): Promise<void> {
    const registration = await navigator.serviceWorker.getRegistration(SW_URL);
    const subscription = await registration?.pushManager.getSubscription();

    if (!subscription) {
        return;
    }

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();

    await apiFetch('/api/push/unsubscribe', {
        method: 'POST',
        body: JSON.stringify({ endpoint }),
    });
}
