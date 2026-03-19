import { defineConfig } from '@playwright/test';

function normalizeBaseUrl(value) {
    const normalized = String(value || 'http://127.0.0.1:8000').trim();
    if (normalized === '') {
        return 'http://127.0.0.1:8000/';
    }

    return normalized.endsWith('/') ? normalized : `${normalized}/`;
}

const baseURL = normalizeBaseUrl(process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000');
const manageLocalWebServer = !process.env.PLAYWRIGHT_BASE_URL;
const loginUrl = new URL('login', baseURL).toString();

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    timeout: 30_000,
    expect: {
        timeout: 5_000,
    },
    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    webServer: manageLocalWebServer
        ? {
            command: 'npm run serve:e2e',
            url: loginUrl,
            reuseExistingServer: !process.env.CI,
            timeout: 120_000,
        }
        : undefined,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : [['list'], ['html', { open: 'never' }]],
});
