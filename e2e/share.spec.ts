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

async function openShareDrawer(page: Page) {
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    const drawer = page.getByRole('dialog');
    await drawer.waitFor({ state: 'visible' });
    return drawer;
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed-share.php', { cwd: '.' });
});

test('the share drawer lists profile members with their effective permission', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    const drawer = await openShareDrawer(page);

    // The owner row: no permission select, plain "Owner" label.
    const ownerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'playwright@example.com' });
    await expect(ownerRow).toContainText('Owner');
    await expect(ownerRow.getByRole('combobox')).toHaveCount(0);

    // The partner row: an explicit contributor (grantable via the select).
    const partnerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'partner@example.com' });
    await expect(partnerRow.getByRole('combobox')).toHaveCount(1);
    await expect(partnerRow.getByRole('combobox')).toContainText('Can edit');
    await expect(
        partnerRow.getByRole('button', { name: 'Revoke access' }),
    ).toBeVisible();
});

test('the resource owner can change a member permission from the drawer', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    const drawer = await openShareDrawer(page);
    const partnerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'partner@example.com' });
    const select = partnerRow.getByRole('combobox');

    await select.click();
    await page.getByRole('option', { name: 'Can view' }).click();

    await expect(page.getByText('Access updated.')).toBeVisible();
    await expect(drawer.getByRole('combobox').first()).toContainText(
        'Can view',
    );
});

test('transferring ownership asks for confirmation and demotes the previous owner', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    const drawer = await openShareDrawer(page);

    // Choose the partner as the new owner and ask for confirmation.
    const transferSelect = drawer.getByRole('combobox').nth(1);
    await transferSelect.click();
    await page.getByRole('option', { name: 'Partner Member' }).click();
    await drawer.getByRole('button', { name: 'Transfer' }).click();

    // Confirmation dialog before the irreversible change.
    const confirm = page.getByRole('dialog');
    await expect(confirm.getByText('Transfer ownership?')).toBeVisible();
    await expect(
        confirm.getByText(/Partner Member will become the owner/),
    ).toBeVisible();

    await confirm.getByRole('button', { name: 'Transfer' }).click();

    await expect(page.getByText('Ownership transferred.')).toBeVisible();

    // The partner is now the owner; the previous owner is a contributor.
    const partnerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'partner@example.com' });
    await expect(partnerRow).toContainText('Owner');

    const ownerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'playwright@example.com' });
    await expect(ownerRow).toContainText('Can edit');
});

test('the envelope share drawer grants, updates and revokes access', async ({
    page,
}) => {
    await login(page);
    await page.goto('/envelopes');
    await expect(page.getByText('Food').first()).toBeVisible();

    // Open the Food envelope's action menu and its share drawer.
    const foodCard = page
        .locator('div.group')
        .filter({ hasText: 'Food' })
        .first();
    await foodCard.getByRole('button', { name: 'Envelope options' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    const drawer = page.getByRole('dialog');
    await drawer.waitFor({ state: 'visible' });

    // The owner row is always listed, labeled "Owner", without controls.
    const ownerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'playwright@example.com' });
    await expect(ownerRow).toContainText('Owner');
    await expect(ownerRow.getByRole('combobox')).toHaveCount(0);
    await expect(
        ownerRow.getByRole('button', { name: 'Revoke access' }),
    ).toHaveCount(0);

    // Grant access to the partner from the profile circle.
    await drawer.getByRole('combobox').first().click();
    await page.getByRole('option', { name: 'Partner Member' }).click();
    await drawer.getByRole('button', { name: 'Add' }).click();
    await expect(page.getByText('Access granted.')).toBeVisible();

    const partnerRow = drawer
        .getByRole('listitem')
        .filter({ hasText: 'partner@example.com' });
    await expect(partnerRow.getByRole('combobox')).toContainText('Can edit');
    await expect(
        partnerRow.getByRole('button', { name: 'Revoke access' }),
    ).toBeVisible();

    // Change the granted permission.
    await partnerRow.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Can view' }).click();
    await expect(page.getByText('Access updated.')).toBeVisible();
    await expect(partnerRow.getByRole('combobox')).toContainText('Can view');

    // Revoke — the row disappears, the owner row remains.
    await partnerRow.getByRole('button', { name: 'Revoke access' }).click();
    await expect(page.getByText('Access revoked.')).toBeVisible();
    await expect(
        drawer.getByRole('listitem').filter({ hasText: 'partner@example.com' }),
    ).toHaveCount(0);
    await expect(ownerRow).toBeVisible();
});
