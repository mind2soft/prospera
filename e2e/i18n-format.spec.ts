import { execSync } from 'node:child_process';
import { type Browser, expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

async function login(page: Page): Promise<void> {
    // Reset Fortify's login rate limiter (5/min per email+IP).
    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(LOGIN.email);
    await page.locator('input[name="password"]').fill(LOGIN.password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

/** A French-speaking browser with an English OS number format. */
async function frenchPage(browser: Browser) {
    const context = await browser.newContext({ locale: 'fr-FR' });
    return { context, page: await context.newPage() };
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the document language follows the app locale', async ({ browser }) => {
    const { context, page } = await frenchPage(browser);
    await login(page);
    await page.goto('/budget');

    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');

    await context.close();
});

test('budget labels and money use the app locale', async ({ browser }) => {
    const { context, page } = await frenchPage(browser);
    await login(page);
    await page.goto('/budget');

    // The draft label is translated...
    await expect(page.getByRole('combobox')).toContainText(
        'Nouveau budget (brouillon)',
    );

    // ...and amounts use French grouping with a trailing currency symbol
    // ("1 700 $"), not the browser's or Node's default locale.
    await expect(page.getByText(/^1\s700\s\$$/).first()).toBeVisible();

    await context.close();
});

test('budget envelope types are translated', async ({ browser }) => {
    const { context, page } = await frenchPage(browser);
    await login(page);
    await page.goto('/budget');

    const row = page.getByRole('row', { name: /Mortgage/ });
    await expect(row.getByRole('cell').nth(1)).toHaveText('Fixe');

    await context.close();
});

test('shared controls have translated accessible names', async ({
    browser,
}) => {
    const { context, page } = await frenchPage(browser);
    await login(page);
    await page.goto('/budget');

    await expect(
        page.getByRole('button', {
            name: 'Afficher ou masquer la barre latérale',
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('navigation', { name: 'Fil d’Ariane' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Actions du budget' }).click();
    await page.getByRole('menuitem', { name: 'Partager' }).click();
    await expect(
        page.getByRole('dialog').getByRole('button', { name: 'Fermer' }),
    ).toBeVisible();

    await context.close();
});
