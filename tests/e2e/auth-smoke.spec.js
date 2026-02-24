import { expect, test } from '@playwright/test';

test('login page renders auth form', async ({ page }) => {
    await page.goto('login');

    await expect(page.locator('form')).toBeVisible();
    await expect(page.locator('input#email[type=\"email\"]')).toBeVisible();
    await expect(page.locator('input#password[type=\"password\"]')).toBeVisible();
});
