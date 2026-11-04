import { execSync } from 'node:child_process';
import { expect, type Page, type Route, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

// The skeleton screens only render while the first API call is in flight, so
// each spec delays the page's data endpoint to make the state observable.
const DELAY_MS = 750;

const delay = async (route: Route): Promise<void> => {
    await new Promise((resolve) => setTimeout(resolve, DELAY_MS));
    await route.continue();
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
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('budget page shows a skeleton while budgets load', async ({ page }) => {
    await login(page);

    await page.route(/\/api\/budgets\?/, delay);
    await page.goto('/budget');

    const skeleton = page.getByTestId('budget-loading');
    await expect(skeleton).toBeVisible();

    // The skeleton is replaced by the loaded page (not by an empty page).
    await expect(skeleton).not.toBeVisible();
    await expect(page.getByText('Paychecks').first()).toBeVisible();
});

test('ledger page shows a skeleton while ledgers load', async ({ page }) => {
    await login(page);

    await page.route(/\/api\/ledgers\?/, delay);
    await page.goto('/ledger');

    const skeleton = page.getByTestId('ledger-loading');
    await expect(skeleton).toBeVisible();

    // The skeleton is replaced by the loaded page (seed.php creates no
    // ledgers, so the empty state is the expected content here).
    await expect(skeleton).not.toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Create ledger' }),
    ).toBeVisible();
});
