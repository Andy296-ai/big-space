// Минимальный service worker — только Web Push. Никаких install/fetch-
// обработчиков и никакого кэша: полноценный офлайн-режим сознательно отложен
// (см. app.blade.php), а этот файл существует ровно для одной вещи — получать
// push-события, пока вкладка/приложение закрыты.

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                // Вкладка уже открыта и в фокусе — она уже получила это же
                // сообщение по вебсокету (см. MessagePosted/Reverb), дублировать
                // системным уведомлением незачем.
                const alreadyVisible = clients.some(
                    (client) => client.visibilityState === 'visible',
                );

                if (alreadyVisible) {
                    return undefined;
                }

                return self.registration.showNotification(
                    data.title || 'Nodus',
                    {
                        body: data.body || '',
                        icon: data.icon || '/icon-192.png',
                        data: data.data || {},
                    },
                );
            }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window' }).then((clients) => {
            const existing = clients[0];

            if (existing) {
                return existing.focus().then(() => {
                    if ('navigate' in existing) {
                        return existing.navigate(url);
                    }

                    return undefined;
                });
            }

            return self.clients.openWindow(url);
        }),
    );
});
