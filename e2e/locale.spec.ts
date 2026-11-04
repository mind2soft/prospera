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

async function openLocaleSelect(page: Page) {
    await page.getByRole('combobox').click();
    const options = page.getByRole('option');
    await options.first().waitFor({ state: 'visible' });
    return options;
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the interface stays in English when the browser requests an unavailable locale', async ({
    browser,
}) => {
    const context = await browser.newContext({ locale: 'fr-FR' });
    const page = await context.newPage();

    await login(page);
    await page.goto('/settings/account');

    // fr-FR has no translation bundle yet: fall back to English.
    await expect(
        page.getByRole('heading', { name: 'Account', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Preferred language')).toBeVisible();

    await context.close();
});

test('the preferred locale is saved, restored, and reset to automatic', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/account');

    await openLocaleSelect(page);
    await page.getByRole('option', { name: 'English', exact: true }).click();
    await page.locator('[data-test="update-profile-button"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/settings/account'));

    // The choice persists across a reload...
    await page.reload();
    const options = await openLocaleSelect(page);
    await expect(options.filter({ hasText: /^English$/ })).toHaveAttribute(
        'data-state',
        'checked',
    );

    // ...and can be reset to follow the browser again.
    await page
        .getByRole('option', { name: 'Automatic (browser default)' })
        .click();
    await page.locator('[data-test="update-profile-button"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/settings/account'));

    await page.reload();
    await openLocaleSelect(page);
    await expect(
        page.getByRole('option', {
            name: 'Automatic (browser default)',
        }),
    ).toHaveAttribute('data-state', 'checked');
});
