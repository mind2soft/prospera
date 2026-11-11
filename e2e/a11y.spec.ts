import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

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
