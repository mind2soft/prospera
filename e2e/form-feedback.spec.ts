import { expect, test } from '@playwright/test';
import { login, seed } from './support';

const failOnce = (status = 500) => {
    let failed = false;
    return async (route: import('@playwright/test').Route) => {
        if (route.request().method() !== 'POST' || failed) {
            await route.fallback();
            return;
        }
        failed = true;
        await route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Server unavailable.' }),
        });
    };
};

test('a failed transaction keeps the entered amount', async ({ page }) => {
    seed('seed-ledger.php');
    await login(page);
    await page.goto('/ledger');
    await page.route(/\/api\/ledgers\/\d+\/transactions$/, failOnce());

    await page.getByRole('combobox', { name: 'Envelope' }).click();
    await page.getByRole('option', { name: /Food/ }).click();
    const amount = page.getByRole('spinbutton', { name: 'Amount' });
    await amount.fill('35');
    await page.getByRole('button', { name: 'Add' }).click();

    await expect(page.getByText('Server unavailable.')).toBeVisible();
    await expect(amount).toHaveValue('35');

    // Retrying succeeds and only then clears the amount.
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page.getByText('Transaction added.')).toBeVisible();
    await expect(amount).toHaveValue('');
});

test('the create-ledger dialog stays open with its values on failure', async ({
    page,
}) => {
    // No ledger yet: the empty state offers to create one.
    seed('seed.php');
    await login(page);
    await page.goto('/ledger');
    await page.route(/\/api\/ledgers$/, failOnce());

    await page.getByRole('button', { name: 'Create ledger' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox', { name: 'Name' }).fill('Holidays');
    await dialog
        .getByRole('spinbutton', { name: 'Starting balance' })
        .fill('250');
    await dialog.getByRole('button', { name: 'Create ledger' }).click();

    await expect(page.getByText('Server unavailable.')).toBeVisible();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: 'Name' })).toHaveValue(
        'Holidays',
    );

    await dialog.getByRole('button', { name: 'Create ledger' }).click();
    await expect(page.getByText('Ledger created.')).toBeVisible();
    await expect(dialog).toBeHidden();
});
