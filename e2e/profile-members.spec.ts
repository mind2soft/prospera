import { expect, type Page, test } from '@playwright/test';
import { t } from './i18n';
import { openProfilePanel } from './profiles-support';
import { login, seed } from './support';

// Leaving a profile and removing a member, from Settings → Profiles.
// Controls are located by their (catalog-sourced) accessible names.

test.beforeEach(() => {
    seed('seed-profile-members.php');
});

async function openProfile(page: Page, name: string) {
    await page.goto('/settings/profiles');

    return openProfilePanel(page, name);
}

test('the owner removes a member after confirming', async ({ page }) => {
    await login(page);
    const panel = await openProfile(page, 'Household');
    await expect(panel.getByText('partner@example.com')).toBeVisible();

    await panel
        .getByRole('button', { name: t('settings.profiles.removeMember') })
        .click();

    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByText(t('settings.profiles.removeConfirmTitle')),
    ).toBeVisible();
    await dialog
        .getByRole('button', { name: t('common.actions.remove'), exact: true })
        .click();

    await expect(
        page.getByText(
            t('settings.profiles.memberRemovedToast', {
                name: 'Partner Member',
            }),
        ),
    ).toBeVisible();
    await expect(panel.getByText('partner@example.com')).toHaveCount(0);

    // The removal is server-side: it holds after a reload.
    const reloaded = await openProfile(page, 'Household');
    await expect(reloaded.getByText('playwright@example.com')).toBeVisible();
    await expect(reloaded.getByText('partner@example.com')).toHaveCount(0);
});

test('a collaborator leaves a shared profile after confirming', async ({
    page,
}) => {
    await login(page);
    const panel = await openProfile(page, 'Family');

    await panel
        .getByRole('button', {
            name: t('settings.profiles.leave'),
            exact: true,
        })
        .click();

    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByText(t('settings.profiles.leaveConfirmTitle')),
    ).toBeVisible();
    await dialog
        .getByRole('button', { name: t('settings.profiles.leaveProfile') })
        .click();

    await expect(
        page.getByText(t('settings.profiles.leftToast', { name: 'Family' })),
    ).toBeVisible();

    await page.reload();
    const disclosures = page.locator('[data-test="profile-disclosure"]');
    await expect(disclosures.filter({ hasText: 'Household' })).toBeVisible();
    await expect(disclosures.filter({ hasText: 'Family' })).toHaveCount(0);
});
