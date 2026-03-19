import { expect, test } from '@playwright/test';
import { ensureRegistrationCode, seedCompletedListItems } from './support/laravel';

const PRODUCT_NAMES = [
    'coffee',
    'tea',
    'milk',
    'bread',
    'butter',
    'apples',
    'rice',
    'pasta',
    'cheese',
];

function uniqueSuffix() {
    return `${Date.now()}-${Math.round(Math.random() * 1_000_000)}`;
}

async function registerFreshUser(page) {
    const suffix = uniqueSuffix();
    const registrationCode = ensureRegistrationCode(`PW${Date.now().toString(36)}`);
    const tag = `pw${suffix}`.replace(/[^a-z0-9_-]/gi, '').slice(0, 24);
    const email = `pw-${suffix}@example.test`;
    const password = 'Password123!';

    await page.goto('register');
    await page.fill('#registration_code', registrationCode);
    await page.fill('#name', `Playwright ${suffix}`);
    await page.fill('#tag', tag);
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.fill('#password_confirmation', password);
    await page.getByRole('button', { name: /создать аккаунт/i }).click();
    await page.waitForURL('**/dashboard', { timeout: 30_000 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('dashboard-tab-profile')).toBeVisible();
}

async function readDashboardOwnerId(page) {
    return page.evaluate(async () => {
        if (!window.axios) {
            throw new Error('window.axios is unavailable');
        }

        const response = await window.axios.get('/api/sync/state');
        const ownerId = Number(response?.data?.default_owner_id ?? 0);
        if (!Number.isFinite(ownerId) || ownerId <= 0) {
            throw new Error('Failed to resolve dashboard owner id');
        }

        return ownerId;
    });
}

async function closeAdsModalIfVisible(page) {
    const adsModal = page.getByTestId('ads-modal');
    if (await adsModal.count() === 0) {
        return;
    }

    await page.getByTestId('ads-modal-close').click();
    await expect(adsModal).toHaveCount(0);
}

test('suggestion stats settings support search, pagination, ignore list and interval changes', async ({ page }) => {
    test.slow();

    await registerFreshUser(page);

    const ownerId = await readDashboardOwnerId(page);
    seedCompletedListItems(ownerId, PRODUCT_NAMES);
    await closeAdsModalIfVisible(page);

    await page.getByTestId('dashboard-tab-profile').click();
    await page.getByTestId('suggestion-stats-open-product').click();

    const modal = page.getByTestId('suggestion-stats-modal');
    const rows = modal.locator('[data-testid^="suggestion-stats-row-"]');
    const search = page.getByTestId('suggestion-stats-search');

    await expect(modal).toBeVisible();
    await expect(rows).toHaveCount(8);

    await page.getByTestId('suggestion-stats-next-page').click();
    await expect(rows).toHaveCount(1);

    await page.getByTestId('suggestion-stats-prev-page').click();
    await expect(rows).toHaveCount(8);

    await search.fill('coffee');
    await expect(page.getByTestId('suggestion-stats-row-coffee')).toBeVisible();
    await expect(rows).toHaveCount(1);

    const coffeeInterval = page.getByTestId('suggestion-stats-interval-coffee');
    await coffeeInterval.selectOption('604800');
    await expect(coffeeInterval).toHaveValue('604800');

    await search.fill('tea');
    await expect(page.getByTestId('suggestion-stats-row-tea')).toBeVisible();

    await page.getByTestId('suggestion-stats-toggle-tea').click();
    await expect(page.locator('[data-testid="suggestion-stats-row-tea"]')).toHaveCount(0);

    await page.getByTestId('suggestion-stats-view-ignored').click();
    await expect(page.getByTestId('suggestion-stats-row-tea')).toBeVisible();

    await page.getByTestId('suggestion-stats-toggle-tea').click();
    await page.getByTestId('suggestion-stats-view-active').click();
    await expect(page.getByTestId('suggestion-stats-row-tea')).toBeVisible();
});
