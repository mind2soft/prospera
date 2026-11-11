import { expect, test } from '@playwright/test';
import { t } from './i18n';
import { login, PASSWORD, seed } from './support';

// Deleting one's own account from Settings → Account.

const EMAIL = 'leaving@example.com';

test.beforeAll(() => {
    seed('seed-account-deletion.php');
});

test('a user deletes their account with their password', async ({ page }) => {
    await login(page, EMAIL);
    await page.goto('/settings/account');

    await page.locator('[data-test="delete-user-button"]').click();
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByText(t('settings.deleteUser.confirmTitle')),
    ).toBeVisible();

    // A wrong password keeps the account.
    await dialog.locator('input[name="password"]').fill('not-my-password');
    await dialog.locator('[data-test="confirm-delete-user-button"]').click();
    await expect(dialog.getByText('The password is incorrect.')).toBeVisible();
    await expect(page).toHaveURL(/\/settings\/account$/);

    await dialog.locator('input[name="password"]').fill(PASSWORD);
    await dialog.locator('[data-test="confirm-delete-user-button"]').click();

    // Signed out, back on the landing page.
    await page.waitForURL((url) => url.pathname === '/');
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);

    // The account is gone: its credentials no longer sign in.
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('[data-test="login-button"]').click();
    await expect(
        page.getByText('These credentials do not match our records.'),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
});
