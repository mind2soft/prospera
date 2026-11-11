import { expect, test } from '@playwright/test';
import { t } from './i18n';
import { openProfilePanel } from './profiles-support';
import { login, seed } from './support';

// Creating and revoking an invite in Settings → Profiles. Controls are
// located by their (catalog-sourced) accessible names.

test.beforeAll(() => {
    seed('seed-profile-invites.php');
});

test('the owner creates an invite, then revokes it', async ({ page }) => {
    await login(page);
    await page.goto('/settings/profiles');

    const panel = await openProfilePanel(page, 'Household');

    await panel
        .getByLabel(t('settings.profiles.inviteSomeone'))
        .fill('newcomer@example.com');
    await panel
        .getByRole('button', {
            name: t('settings.profiles.invite'),
            exact: true,
        })
        .click();

    await expect(
        page.getByText(t('settings.profiles.inviteCreatedToast')),
    ).toBeVisible();
    const invite = panel
        .locator('[data-test="pending-invite"]')
        .filter({ hasText: 'newcomer@example.com' });
    await expect(invite).toBeVisible();
    await expect(
        invite.getByRole('button', {
            name: t('settings.profiles.copyInviteLink'),
        }),
    ).toBeVisible();

    // The invite is stored: it is still pending after a reload.
    await page.reload();
    await openProfilePanel(page, 'Household');
    await expect(invite).toBeVisible();

    await invite
        .getByRole('button', { name: t('settings.profiles.revokeInvite') })
        .click();
    await expect(
        page.getByText(t('settings.profiles.inviteRevokedToast')),
    ).toBeVisible();
    await expect(invite).toHaveCount(0);

    await page.reload();
    await openProfilePanel(page, 'Household');
    await expect(panel.getByText(t('settings.profiles.members'))).toBeVisible();
    await expect(invite).toHaveCount(0);
});
