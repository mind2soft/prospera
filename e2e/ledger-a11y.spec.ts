import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeAll(() => {
    seed('seed-ledger.php');
});

test('the calendar only sticks in the two-column layout', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await login(page);
    await page.goto('/ledger');

    const calendar = page.getByTestId('month-calendar');
    await expect(calendar).toBeVisible();
    await expect(calendar.locator('..')).toHaveCSS('position', 'static');

    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(calendar.locator('..')).toHaveCSS('position', 'sticky');
});
