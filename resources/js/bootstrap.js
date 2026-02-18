import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios = axios;

const appBaseUrl = document
    .querySelector('meta[name="app-base-url"]')
    ?.getAttribute('content')
    ?.replace(/\/+$/, '');

if (appBaseUrl) {
    window.axios.defaults.baseURL = appBaseUrl;
}

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.Pusher = Pusher;

const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const reverbHost = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname;
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const authEndpoint = appBaseUrl
    ? `${appBaseUrl}/broadcasting/auth`
    : '/broadcasting/auth';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    authEndpoint,
    enabledTransports: ['ws', 'wss'],
});
