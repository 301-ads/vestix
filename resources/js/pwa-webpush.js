const CSRF_COOKIE = 'XSRF-TOKEN';

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i += 1) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function ensureServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('Service workers worden niet ondersteund op dit apparaat.');
    }

    return navigator.serviceWorker.register('/sw.js', { scope: '/' });
}

async function api(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {}),
    };

    const xsrf = getCookie(CSRF_COOKIE);

    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    if (!response.ok) {
        let message = 'Verzoek mislukt.';

        try {
            const payload = await response.json();
            message = payload.message || message;
        } catch (error) {
            // Keep default message.
        }

        throw new Error(message);
    }

    if (response.status === 204) {
        return null;
    }

    return response.json();
}

async function subscribe() {
    const registration = await ensureServiceWorker();
    await navigator.serviceWorker.ready;

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error('Notificatietoestemming is geweigerd.');
    }

    const { publicKey } = await api('/admin/webpush/vapid-public-key');

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
    });

    const json = subscription.toJSON();

    await api('/admin/webpush/subscribe', {
        method: 'POST',
        body: JSON.stringify({
            endpoint: json.endpoint,
            keys: json.keys,
            contentEncoding: 'aes128gcm',
        }),
    });

    return true;
}

async function unsubscribe() {
    const registration = await ensureServiceWorker();
    await navigator.serviceWorker.ready;

    const subscription = await registration.pushManager.getSubscription();

    if (subscription) {
        await api('/admin/webpush/subscribe', {
            method: 'DELETE',
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });

        await subscription.unsubscribe();
    } else {
        await api('/admin/webpush/subscriptions', {
            method: 'DELETE',
        });
    }

    return true;
}

async function sendTest() {
    await api('/admin/webpush/test', {
        method: 'POST',
        body: JSON.stringify({}),
    });

    return true;
}

window.vestixWebPush = {
    isSupported() {
        return 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window;
    },
    subscribe,
    unsubscribe,
    sendTest,
    ensureServiceWorker,
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.vestixWebPush.ensureServiceWorker().catch(() => {});
    });
} else {
    window.vestixWebPush.ensureServiceWorker().catch(() => {});
}
