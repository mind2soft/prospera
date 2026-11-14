import { execSync } from 'node:child_process';
import { expect, type Page, type Route, test } from '@playwright/test';

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
    // Settle on a page without API reads, so request counts only see the
    // page under test.
    await page.goto('/privacy');
}

/** Fails matching GET requests with `status`, counting them. */
async function failGets(
    page: Page,
    pattern: RegExp,
    status: number,
): Promise<{ count: () => number }> {
    let count = 0;

    await page.route(pattern, async (route: Route) => {
        if (route.request().method() !== 'GET') {
            await route.fallback();
            return;
        }

        count++;
        await route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Server Error' }),
        });
    });

    return { count: () => count };
}

const WIDGETS = /\/api\/dashboard\/\d+\/widgets$/;
const LEDGERS = /\/api\/ledgers\?/;
const TRANSACTIONS = /\/api\/ledgers\/\d+\/transactions\?/;
const BUDGETS = /\/api\/budgets\?/;
const BUDGET_DETAIL = /\/api\/budgets\/\d+$/;

test.beforeAll(() => {
    // One budget, one ledger with transactions, and one saved widget.
    execSync('npm run e2e:seed -- seed-dashboard.php', { cwd: '.' });
    execSync('npm run e2e:seed -- seed-dashboard-xss.php', { cwd: '.' });
});

test('a failed dashboard load shows an error with retry and never saves over the layout', async ({
    page,
}) => {
    await login(page);

    const widgets = await failGets(page, WIDGETS, 500);
    const writes: string[] = [];
    page.on('request', (request) => {
        if (WIDGETS.test(request.url()) && request.method() !== 'GET') {
            writes.push(request.method());
        }
    });

    await page.goto('/dashboard');

    await expect(
        page.getByText('Your dashboard could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });
    // Idempotent reads are retried before giving up (1 + 2 retries).
    expect(widgets.count()).toBe(3);
    // Never the empty state — it would invite rebuilding the layout.
    await expect(page.getByText('Make it yours')).toHaveCount(0);
    // Adding is blocked until the canonical layout has loaded.
    await expect(
        page.getByRole('button', { name: 'Add widget' }),
    ).toBeDisabled();

    // Recovery: the retry loads the saved layout, untouched.
    await page.unroute(WIDGETS);
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.locator('[data-widget-card]')).toHaveCount(1, {
        timeout: 15_000,
    });
    await expect(
        page.getByRole('button', { name: 'Add widget' }),
    ).toBeEnabled();
    expect(writes).toEqual([]);
});

test('client errors are not retried', async ({ page }) => {
    await login(page);

    const widgets = await failGets(page, WIDGETS, 403);
    await page.goto('/dashboard');

    await expect(
        page.getByText('Your dashboard could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });
    expect(widgets.count()).toBe(1);
});

test('a failed ledger list shows an error, not the "no ledger yet" state', async ({
    page,
}) => {
    await login(page);

    await failGets(page, LEDGERS, 500);
    await page.goto('/ledger');

    await expect(
        page.getByText('Your ledgers could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        page.getByText(
            /Set your finish line|Your ledger opens after|No ledger yet/,
        ),
    ).toHaveCount(0);

    await page.unroute(LEDGERS);
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(
        page.getByText('Your ledgers could not be loaded.'),
    ).toHaveCount(0, { timeout: 15_000 });
    await expect(page.getByRole('table')).toBeVisible();
});

test('failed transactions show an error, not "no transactions"', async ({
    page,
}) => {
    await login(page);

    await failGets(page, TRANSACTIONS, 500);
    await page.goto('/ledger');

    await expect(
        page.getByText('Transactions could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('No transactions this month.')).toHaveCount(0);

    await page.unroute(TRANSACTIONS);
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.getByText('Food').first()).toBeVisible({
        timeout: 15_000,
    });
    await expect(
        page.getByText('Transactions could not be loaded.'),
    ).toHaveCount(0);
});

test('failed budget reads show errors, not the empty state', async ({
    page,
}) => {
    await login(page);

    await failGets(page, BUDGETS, 500);
    await page.goto('/budget');

    await expect(
        page.getByText('Your budgets could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(
        page.getByText(/Start a new budget|Your first budget is|No budget yet/),
    ).toHaveCount(0);

    // The list recovers; the selected budget's detail now fails.
    await page.unroute(BUDGETS);
    await failGets(page, BUDGET_DETAIL, 500);
    await page.getByRole('button', { name: 'Retry' }).click();

    await expect(
        page.getByText('This budget could not be loaded.'),
    ).toBeVisible({ timeout: 15_000 });

    await page.unroute(BUDGET_DETAIL);
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(
        page.getByText('This budget could not be loaded.'),
    ).toHaveCount(0, { timeout: 15_000 });
    await expect(page.getByText('Mortgage').first()).toBeVisible();
});
