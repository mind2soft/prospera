import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const SHOT = (name: string) => `./e2e/.out/${name}.png`;

async function login(page: Page, email: string): Promise<void> {
    // Reset Fortify's login rate limiter (5/min per email+IP).
    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await page.goto('/login');
    await page.getByRole('textbox', { name: 'Email address' }).fill(email);
    await page.getByRole('textbox', { name: 'Password' }).fill('Password!234');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed-ledger-plain.php', { cwd: '.' });
});

test('transactions can be recorded with envelopes only (no budget)', async ({
    page,
}) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error)));

    await login(page, 'playwright-plain@example.com');
    await page.goto('/ledger');

    // No budget-related banner: envelopes are the only requirement.
    await expect(page.getByText('A published budget is required')).toHaveCount(
        0,
    );
    const entryCard = page.getByText('New transaction', { exact: true });
    await expect(entryCard).toBeVisible();

    // Add a transaction against the seeded envelope.
    await page.getByRole('combobox').nth(1).click();
    await page.getByRole('option', { name: /Groceries/ }).click();
    await page.getByPlaceholder('e.g. 42').fill('35');
    await page.getByRole('button', { name: 'Add' }).click();

    const row = page.locator('tbody tr', { hasText: 'Groceries' });
    await expect(row).toBeVisible();
    // No published budget → no currency symbol, plain integer formatting.
    await expect(row).toContainText('(35)');

    // No budget snapshot → no "remaining allocated" value.
    await expect(row.locator('td:nth-child(4)')).toContainText('—');

    await page.screenshot({
        path: SHOT('01-ledger-no-budget'),
        fullPage: true,
    });
    expect(consoleErrors).toEqual([]);
});

test('the entry form is replaced by an envelope banner when none exist', async ({
    page,
}) => {
    await login(page, 'playwright-bare@example.com');
    await page.goto('/ledger');

    const banner = page.getByText(
        'Envelopes are required to record transactions.',
    );
    await expect(banner).toBeVisible();
    await expect(
        page.getByText('New transaction', { exact: true }),
    ).toHaveCount(0);

    await page.getByRole('link', { name: 'Manage envelopes' }).click();
    await expect(page).toHaveURL(/\/envelopes$/);

    await page.screenshot({
        path: SHOT('02-ledger-no-envelopes'),
        fullPage: true,
    });
});
