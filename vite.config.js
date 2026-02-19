import os from 'node:os';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

function detectLanHost() {
    const interfaces = os.networkInterfaces();
    const privateRanges = [
        /^10\./,
        /^192\.168\./,
        /^172\.(1[6-9]|2\d|3[0-1])\./,
    ];

    for (const networkList of Object.values(interfaces)) {
        if (!Array.isArray(networkList)) {
            continue;
        }

        for (const network of networkList) {
            if (
                network
                && network.family === 'IPv4'
                && !network.internal
                && privateRanges.some((range) => range.test(network.address))
            ) {
                return network.address;
            }
        }
    }

    for (const networkList of Object.values(interfaces)) {
        if (!Array.isArray(networkList)) {
            continue;
        }

        for (const network of networkList) {
            if (network && network.family === 'IPv4' && !network.internal) {
                return network.address;
            }
        }
    }

    return '127.0.0.1';
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const devPort = Number(env.VITE_DEV_SERVER_PORT || 5173);
    const devHost = String(env.VITE_DEV_SERVER_HOST || '0.0.0.0');
    const autoLanHost = detectLanHost();
    const defaultReachableHost = devHost === '0.0.0.0' ? autoLanHost : devHost;
    const hmrHost = String(env.VITE_HMR_HOST || defaultReachableHost).trim();
    const hmrProtocol = String(env.VITE_HMR_PROTOCOL || 'ws');
    const origin = String(
        env.VITE_DEV_SERVER_ORIGIN
            || `http://${hmrHost}:${devPort}`,
    ).trim();

    return {
        base: mode === 'production' ? './' : undefined,
        plugins: [
            laravel({
                input: 'resources/js/app.js',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        server: {
            host: devHost,
            port: devPort,
            strictPort: true,
            origin,
            hmr: {
                host: hmrHost,
                protocol: hmrProtocol,
                port: devPort,
            },
        },
    };
});
