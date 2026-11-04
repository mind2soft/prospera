import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

async function login(page: Page): Promise<void> {
    // Reset Fortify's login rate limiter (5/min per email+IP).
    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await page.goto('/login');
    await page
        .getByRole('textbox', { name: 'Email address' })
        .fill(LOGIN.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(LOGIN.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed-masked.php', { cwd: '.' });
});

/**
 * Open the ledger page and navigate the calendar to the month holding the
 * seeded transactions (October 2026). The calendar opens on the most recent
 * in-period month, which moves as time passes.
 */
async function openLedgerAtOctober(page: Page): Promise<void> {
    await login(page);
    await page.goto('/ledger');
    const today = new Date();
    const steps =
        (2026 - today.getFullYear()) * 12 + (10 - (today.getMonth() + 1));
    const button = page.getByRole('button', {
        name: steps <= 0 ? 'Previous month' : 'Next month',
    });
    for (let i = 0; i < Math.abs(steps); i += 1) {
        await button.click();
    }
}

test('transactions on non-permitted envelopes render masked amounts', async ({
    page,
}) => {
    await openLedgerAtOctober(page);

    const row = page.locator('tbody tr').filter({ hasText: 'Secret savings' });
    await expect(row).toHaveCount(1);

    // The envelope metadata (name, colour chip) is visible…
    await expect(row).toContainText('Secret savings');

    // …but the amount and remaining allocated are masked with an em dash.
    const cells = row.locator('td');
    await expect(cells.nth(2)).toHaveText('—'); // Amount
    await expect(cells.nth(3)).toHaveText('—'); // Remaining

    // The raw amount never leaks into the row.
    const rowText = await row.textContent();
    expect(rowText).not.toContain('4,321');
    expect(rowText).not.toContain('4321');
});

test('permitted transactions still show their amounts', async ({ page }) => {
    await openLedgerAtOctober(page);

    // Control: the partner's transaction on Food (contributor there) is
    // visible with its amount and computed remaining.
    const row = page.locator('tbody tr').filter({ hasText: 'Food' });
    await expect(row).toHaveCount(1);
    await expect(row.locator('td').nth(2)).toHaveText('($250)');
    await expect(row.locator('td').nth(3)).toHaveText('$550');
});
