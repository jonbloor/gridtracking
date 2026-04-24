self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Handle notification clicks (open the app)
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            if (clientList.length > 0) {
                return clientList[0].focus();
            }
            return clients.openWindow('/');
        })
    );
});

// Listen for messages from the main page to show notifications
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SHOW_REMINDER') {
        const title = event.data.title || 'Grid Tracking Reminder';
        const body = event.data.body || "You haven't checked in for 15 minutes. Please update your location!";
        
        self.registration.showNotification(title, {
            body: body,
            icon: 'android-chrome-192x192.png',
            badge: 'favicon-32x32.png',
            vibrate: [200, 100, 200],
            tag: 'gridtracking-reminder',
            requireInteraction: true,
            data: { url: '/' }
        });
    }
});