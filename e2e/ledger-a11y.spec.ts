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

test('calendar days are named, expose today/selection and use arrow keys', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    const calendar = page.getByTestId('month-calendar');
    const days = calendar.getByRole('group');
    await expect(days).toHaveAccessibleName(/\d{4}/);

    const selected = calendar.locator('[aria-pressed="true"]');
    await expect(selected).toHaveCount(1);
    await expect(selected).toHaveAttribute('tabindex', '0');
    await expect(
        calendar.locator('button[tabindex="0"][data-date]'),
    ).toHaveCount(1);
    // The accessible name is the full localized date, not just "12".
    await expect(selected).toHaveAccessibleName(/\w+, \w+ \d{1,2}, \d{4}/);

    const date = await selected.getAttribute('data-date');
    const [y, m, d] = (date ?? '').split('-').map(Number);
    const isoOf = (day: Date) =>
        `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
    if (date === isoOf(new Date())) {
        await expect(selected).toHaveAttribute('aria-current', 'date');
    }
    // Move one day back — inside the ledger's period (2026) unless the
    // selected day is its first day, then move forward.
    const back = new Date(y, m - 1, d - 1);
    const goBack = isoOf(back) >= '2026-01-01';
    const target = isoOf(goBack ? back : new Date(y, m - 1, d + 1));

    await selected.focus();
    await page.keyboard.press(goBack ? 'ArrowLeft' : 'ArrowRight');
    const targetDay = calendar.locator(`[data-date="${target}"]`);
    await expect(targetDay).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(targetDay).toHaveAttribute('aria-pressed', 'true');
});

test('the ledger reflows at 320 px; the table scrolls in a labelled region', async ({
    page,
}) => {
    await page.setViewportSize({ width: 320, height: 800 });
    await login(page);
    await page.goto('/ledger');
    const region = page.getByRole('region', { name: 'Transactions' });
    await expect(region).toBeVisible();

    // Nothing but the table region scrolls horizontally.
    const overflowing = await page.evaluate(() =>
        [...document.querySelectorAll<HTMLElement>('body *')]
            .filter(
                (el) =>
                    el.scrollWidth > el.clientWidth + 1 &&
                    ['auto', 'scroll'].includes(getComputedStyle(el).overflowX),
            )
            .map((el) => el.getAttribute('aria-label') ?? el.className),
    );
    expect(overflowing).toEqual(['Transactions']);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);

    // The region is keyboard-scrollable to reach the trailing columns.
    await region.focus();
    await expect(region).toBeFocused();
    await page.keyboard.press('End');
    await page.keyboard.press('ArrowRight');
    await expect
        .poll(() => region.evaluate((el) => el.scrollLeft))
        .toBeGreaterThan(0);
});
