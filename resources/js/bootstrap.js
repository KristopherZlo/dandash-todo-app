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

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

window.Pusher = Pusher;

const LOCALHOST_ALIASES = new Set(['localhost', '127.0.0.1', '::1']);
const currentHost = window.location.hostname;
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const configuredReverbHost = String(import.meta.env.VITE_REVERB_HOST ?? '').trim();
const configuredReverbHostLower = configuredReverbHost.toLowerCase();
const shouldUseCurrentHostForReverb = configuredReverbHost === ''
    || (
        LOCALHOST_ALIASES.has(configuredReverbHostLower)
        && !LOCALHOST_ALIASES.has(currentHost.toLowerCase())
    );
const reverbHost = shouldUseCurrentHostForReverb ? currentHost : configuredReverbHost;
const configuredReverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? 0);
const locationPort = Number(window.location.port || 0);
const defaultReverbPort = reverbScheme === 'https' ? 443 : 80;
const reverbPort = configuredReverbPort > 0
    ? configuredReverbPort
    : (locationPort > 0 ? locationPort : defaultReverbPort);
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

const applyEchoSocketIdToAxios = () => {
    const socketId = window.Echo?.socketId?.();
    if (!socketId) {
        return;
    }

    window.axios.defaults.headers.common['X-Socket-ID'] = socketId;
};

window.Echo.connector.pusher.connection.bind('connected', applyEchoSocketIdToAxios);
applyEchoSocketIdToAxios();

window.axios.interceptors.request.use((config) => {
    const socketId = window.Echo?.socketId?.();
    if (socketId) {
        config.headers = config.headers ?? {};
        config.headers['X-Socket-ID'] = socketId;
    }

    return config;
});
