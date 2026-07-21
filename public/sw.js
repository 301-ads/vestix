/* global self, clients */

self.addEventListener('push', (event) => {
    let data = {
        title: 'Vestix',
        body: '',
        url: '/admin',
        icon: '/images/favicon-192x192.png',
    };

    try {
        if (event.data) {
            data = { ...data, ...event.data.json() };
        }
    } catch (error) {
        // Ignore malformed payloads and fall back to defaults.
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Vestix', {
            body: data.body || '',
            icon: data.icon || '/images/favicon-192x192.png',
            badge: '/images/favicon-192x192.png',
            data: {
                url: data.url || '/admin',
            },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification?.data?.url || '/admin';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (const client of windowClients) {
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }

            return undefined;
        }),
    );
});
