import { expect, type Page, test } from '@playwright/test';
import { t } from './i18n';
import { login, seed } from './support';

// Envelope management: create, edit, and the integrity guards on an
// envelope a budget uses (no deletion, no direction change). Controls are
// located by their (catalog-sourced) accessible names.

test.beforeEach(() => {
    seed('seed-envelopes.php');
});

function card(page: Page, name: string) {
    return page
        .getByRole('region', { name: t('envelopes.groups.expenses') })
        .or(page.getByRole('region', { name: t('envelopes.groups.revenue') }))
        .getByText(name, { exact: true });
}

async function openMenu(page: Page, name: string) {
    await page
        .getByRole('button', { name: t('envelopes.optionsFor', { name }) })
        .click();
}

test('a user creates an envelope, then renames it', async ({ page }) => {
    await login(page);
    await page.goto('/envelopes');

    await page
        .getByRole('button', { name: t('envelopes.newEnvelope') })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(t('common.fields.name')).fill('Groceries');
    await dialog
        .getByRole('radio', { name: t('envelopes.form.expense') })
        .click();
    await dialog
        .getByRole('radio', { name: t('envelopes.colors.emerald') })
        .click();
    await dialog
        .getByRole('button', { name: t('envelopes.form.create') })
        .click();

    await expect(page.getByText('Envelope created.')).toBeVisible();
    await expect(card(page, 'Groceries')).toBeVisible();

    await openMenu(page, 'Groceries');
    await page
        .getByRole('menuitem', { name: t('common.actions.edit') })
        .click();
    await expect(dialog.getByLabel(t('common.fields.name'))).toHaveValue(
        'Groceries',
    );
    await dialog.getByLabel(t('common.fields.name')).fill('Food & groceries');
    await dialog
        .getByRole('button', { name: t('envelopes.form.saveChanges') })
        .click();

    await expect(page.getByText('Envelope updated.')).toBeVisible();
    await page.reload();
    await expect(card(page, 'Food & groceries')).toBeVisible();
    await expect(card(page, 'Groceries')).toHaveCount(0);
});

test('an envelope used by a budget cannot be deleted', async ({ page }) => {
    await login(page);
    await page.goto('/envelopes');

    await openMenu(page, 'Rent');
    await page
        .getByRole('menuitem', { name: t('envelopes.menu.delete') })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(t('envelopes.delete.title'))).toBeVisible();
    await dialog
        .getByRole('button', { name: t('envelopes.delete.confirm') })
        .click();

    await expect(
        page.getByText(
            'This envelope is used in a budget and cannot be deleted.',
        ),
    ).toBeVisible();
    await page.reload();
    await expect(card(page, 'Rent')).toBeVisible();
});

test('the direction of an envelope used by a budget is locked', async ({
    page,
}) => {
    await login(page);
    await page.goto('/envelopes');

    await openMenu(page, 'Rent');
    await page
        .getByRole('menuitem', { name: t('common.actions.edit') })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog
        .getByRole('radio', { name: t('envelopes.form.revenue') })
        .click();
    await dialog
        .getByRole('button', { name: t('envelopes.form.saveChanges') })
        .click();

    const error =
        'This envelope is already used by a budget or transactions: its direction cannot be changed.';
    // The inline field error, next to the direction picker.
    await expect(dialog.getByText(error)).toBeVisible();

    await page.reload();
    await expect(
        page
            .getByRole('region', { name: t('envelopes.groups.expenses') })
            .getByText('Rent', { exact: true }),
    ).toBeVisible();
});
