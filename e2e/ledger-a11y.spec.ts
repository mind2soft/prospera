import AxeBuilder from '@axe-core/playwright';
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

test('envelope names in the transaction table meet contrast', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('row').nth(1)).toBeVisible();

    const results = await new AxeBuilder({ page })
        .include('table')
        .withRules(['color-contrast'])
        .analyze();
    expect(results.violations).toEqual([]);
});

test('ledger form controls have accessible names', async ({ page }) => {
    await login(page);
    await page.goto('/ledger');
    await expect(
        page.getByRole('combobox', { name: 'Select ledger' }),
    ).toBeVisible();
    await expect(
        page.getByRole('combobox', { name: 'Envelope' }),
    ).toBeVisible();

    const results = await new AxeBuilder({ page })
        .withRules(['label', 'button-name', 'select-name'])
        .analyze();
    expect(results.violations.map((v) => v.id)).toEqual([]);
});
