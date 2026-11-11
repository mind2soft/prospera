import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeEach(() => {
    seed('seed.php');
});

test('amount edits report their autosave status', async ({ page }) => {
    await login(page);
    await page.goto('/budget');
    const amount = page.getByRole('spinbutton', { name: 'Food amount' });
    const status = page.getByTestId('budget-save-status');
    await expect(status).toHaveAttribute('role', 'status');

    await amount.fill('850');
    await expect(status).toHaveText('Saving…');
    await expect(status).toHaveText('All changes saved');

    // The saved amount survives a reload.
    await page.reload();
    await expect(
        page.getByRole('spinbutton', { name: 'Food amount' }),
    ).toHaveValue('850');
});

test('a failed save is reported as not saved', async ({ page }) => {
    await login(page);
    await page.goto('/budget');
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, (route) =>
        route.request().method() === 'PATCH'
            ? route.fulfill({
                  status: 500,
                  contentType: 'application/json',
                  body: JSON.stringify({ message: 'Server unavailable.' }),
              })
            : route.fallback(),
    );

    await page.getByRole('spinbutton', { name: 'Food amount' }).fill('900');
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'A change could not be saved.',
    );
});

test('an invalid amount is explained inline instead of silently reverted', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    const amount = page.getByRole('spinbutton', { name: 'Food amount' });

    await amount.fill('0');
    await amount.blur();
    await expect(amount).toHaveValue('0');
    await expect(amount).toHaveAttribute('aria-invalid', 'true');
    await expect(amount).toHaveAccessibleDescription(
        'Not saved: enter a whole amount of 1 or more.',
    );

    // Escape restores the saved amount.
    await amount.focus();
    await amount.press('Escape');
    await expect(amount).toHaveValue('800');
    await expect(amount).not.toHaveAttribute('aria-invalid', 'true');
});
