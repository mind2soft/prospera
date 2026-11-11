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
