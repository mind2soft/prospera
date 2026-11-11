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
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the login form follows the DOM tab order', async ({ page }) => {
    await page.goto('/login');

    // No positive tabindex overrides the reading order (WCAG 2.4.3).
    const positive = await page
        .locator('[tabindex]')
        .evaluateAll(
            (nodes) =>
                nodes.filter((n) => Number(n.getAttribute('tabindex')) > 0)
                    .length,
        );
    expect(positive).toBe(0);

    const email = page.getByRole('textbox', { name: 'Email address' });
    await expect(email).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(
        page.getByRole('link', { name: 'Forgot your password?' }),
    ).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeFocused();
});

test('envelope options stay visible while focused', async ({ page }) => {
    await login(page);
    await page.goto('/envelopes');

    const options = page.getByRole('button', {
        name: 'Envelope options for Food',
    });
    await options.focus();
    await expect(options).toBeFocused();
    await expect(options).toHaveCSS('opacity', '1');
});

test('reduced motion removes interface animations', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();
    await login(page);
    await page.goto('/budget');

    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(sheet).toHaveCSS('animation-duration', '1e-05s');

    // The sheet still closes (its exit animation ends immediately).
    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();

    await context.close();
});

test('every main page has a single top-level heading', async ({ page }) => {
    await login(page);
    for (const [path, title] of [
        ['/dashboard', 'Dashboard'],
        ['/budget', 'Budget'],
        ['/ledger', 'Ledger'],
        ['/envelopes', 'Envelopes'],
        ['/settings/profiles', 'Profiles'],
    ]) {
        await page.goto(path);
        const h1 = page.getByRole('heading', { level: 1 });
        await expect(h1).toHaveCount(1);
        await expect(h1).toHaveText(title);
    }
});

test('empty-state headlines are solid text, not gradient-clipped', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');

    const headline = page.getByRole('heading', {
        name: 'Create your first ledger',
    });
    await expect(headline).toBeVisible();
    await expect(headline).not.toHaveCSS('color', 'rgba(0, 0, 0, 0)');
    await expect(headline).toHaveCSS('background-image', 'none');
});
