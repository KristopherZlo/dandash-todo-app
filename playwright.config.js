import { defineConfig } from '@playwright/test';

function normalizeBaseUrl(value) {
    const normalized = String(value || 'http://127.0.0.1:8000').trim();
    if (normalized === '') {
        return 'http://127.0.0.1:8000/';
    }

    return normalized.endsWith('/') ? normalized : `${normalized}/`;
}

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    timeout: 30_000,
    expect: {
        timeout: 5_000,
    },
    use: {
        baseURL: normalizeBaseUrl(process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000'),
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : [['list'], ['html', { open: 'never' }]],
});
