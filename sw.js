self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow('/');
            }
        })
    );
});

self.addEventListener('push', (event) => {
    let data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { body: event.data.text() };
        }
    }

    const title = data.title || 'Grid Tracking Reminder';

    const options = {
        body: data.body || "You haven't checked in for 10 minutes. Please send a location update.",
        icon: 'android-chrome-192x192.png',
        badge: 'favicon-32x32.png',
        tag: 'gridtracking-reminder',
        requireInteraction: true,
        data: {
            url: data.url || '/'
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SHOW_REMINDER') {
        const title = event.data.title || 'Grid Tracking Reminder';
        const body = event.data.body || "You haven't checked in for 10 minutes. Please update your location.";

        event.waitUntil(
            self.registration.showNotification(title, {
                body: body,
                icon: 'android-chrome-192x192.png',
                badge: 'favicon-32x32.png',
                vibrate: [200, 100, 200],
                tag: 'gridtracking-reminder',
                requireInteraction: true,
                data: { url: '/' }
            })
        );
    }
});