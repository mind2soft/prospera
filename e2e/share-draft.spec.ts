import { expect, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * A draft budget can only be handed to the profile owner (WF-R2-1): the
 * Share drawer offers no other transfer target and says why.
 */

test.beforeAll(() => {
    seed('seed-share.php');
});

test('a draft budget is not offered for transfer to collaborators', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'New Budget' }).click();
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByText('Draft budget created.')).toBeVisible();

    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByText('Partner Member')).toBeVisible();

    // The partner is a collaborator: no transfer control, an explanation.
    await expect(
        drawer.locator('[data-test="transfer-restriction"]'),
    ).toHaveText(
        'A draft budget can only be handed to the profile owner. Publish it first to transfer it to someone else.',
    );
    await expect(drawer.getByText('Transfer ownership')).toHaveCount(0);
});
