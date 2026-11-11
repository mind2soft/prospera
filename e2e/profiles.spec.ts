import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

const PROFILE_KEY = 'prospera.current_profile_id';

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

async function openProfilesMenu(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    const trigger = page.getByRole('menuitem', { name: 'Profiles' });
    await trigger.click();
    const menu = page.getByRole('menu', { name: 'Profiles' });
    await menu.waitFor({ state: 'visible' });
    return menu;
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed-profiles.php', { cwd: '.' });
});

test('the user menu lists owned and shared profiles with the current one checked', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    // Generous timeout: the first hit may compile fresh Vite modules.
    await expect(page.getByText('Paychecks').first()).toBeVisible({
        timeout: 15_000,
    });

    const menu = await openProfilesMenu(page);

    const defaultItem = menu.getByRole('menuitemradio', {
        name: /Default profile/,
    });
    await expect(defaultItem).toContainText('Owned');

    const familyItem = menu.getByRole('menuitemradio', { name: /Family/ });
    await expect(familyItem).toContainText('Shared');

    // The owned profile is selected by default → only it is checked (and
    // carries the check mark).
    await expect(defaultItem).toHaveAttribute('aria-checked', 'true');
    await expect(familyItem).toHaveAttribute('aria-checked', 'false');
    await expect(
        defaultItem.locator('[data-test="current-profile-check"]'),
    ).toHaveCount(1);
    await expect(
        familyItem.locator('[data-test="current-profile-check"]'),
    ).toHaveCount(0);
});

test('switching profiles scopes the data and persists across reloads', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    // Generous timeout: the first hit may compile fresh Vite modules.
    await expect(page.getByText('Paychecks').first()).toBeVisible({
        timeout: 15_000,
    });

    const storedBefore = await page.evaluate(
        (key) => localStorage.getItem(key),
        PROFILE_KEY,
    );
    expect(storedBefore).toBeTruthy();

    const menu = await openProfilesMenu(page);
    await menu.getByRole('menuitemradio', { name: /Family/ }).click();

    // The shared profile has no budgets → empty state.
    await expect(page.getByText('Create your first budget')).toBeVisible();

    const storedAfter = await page.evaluate(
        (key) => localStorage.getItem(key),
        PROFILE_KEY,
    );
    expect(storedAfter).toBeTruthy();
    expect(storedAfter).not.toBe(storedBefore);

    // The selection survives a full reload (resolved from localStorage).
    await page.reload();
    await expect(page.getByText('Create your first budget')).toBeVisible();
    expect(
        await page.evaluate((key) => localStorage.getItem(key), PROFILE_KEY),
    ).toBe(storedAfter);

    // Switch back to the owned profile.
    const menu2 = await openProfilesMenu(page);
    await menu2.getByRole('menuitemradio', { name: /Default profile/ }).click();
    await expect(page.getByText('Paychecks').first()).toBeVisible();
    expect(
        await page.evaluate((key) => localStorage.getItem(key), PROFILE_KEY),
    ).toBe(storedBefore);
});

test('the user can set a shared profile as their default from settings', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    // Generous timeout: the first hit may compile fresh Vite modules.
    await expect(page.getByText('Paychecks').first()).toBeVisible({
        timeout: 15_000,
    });

    // The switcher marks the seeded default profile with a star, also
    // stated in text for assistive tech.
    const menu = await openProfilesMenu(page);
    const defaultItem = menu.getByRole('menuitemradio', {
        name: /Default profile/,
    });
    const familyItem = menu.getByRole('menuitemradio', { name: /Family/ });
    await expect(
        defaultItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(1);
    await expect(defaultItem).toHaveAccessibleName(/\(default\)/);
    await expect(
        familyItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');

    // Settings → Profiles offers the set-default action on the shared one.
    await page.goto('/settings/profiles');
    const familyCard = page
        .locator('[data-test="profile-disclosure"]')
        .filter({ hasText: 'Family' })
        .locator('..');
    await familyCard.locator('[data-test="set-default-button"]').click();
    await expect(
        familyCard.locator('[data-test="default-profile-badge"]'),
    ).toHaveCount(1);

    // The server-side default survives a wipe of the locally stored current
    // profile: with no stored id, the shared profile is selected on boot.
    await page.goto('/budget');
    await page.evaluate((key) => localStorage.removeItem(key), PROFILE_KEY);
    await page.reload();
    await expect(page.getByText('Create your first budget')).toBeVisible();
    const after = await openProfilesMenu(page);
    await expect(
        after
            .getByRole('menuitemradio', { name: /Family/ })
            .locator('[data-test="default-profile-star"]'),
    ).toHaveCount(1);
    await page.keyboard.press('Escape');

    // Restore the default to the owned profile: later specs seed their data
    // into the user's default profile and expect the suite's baseline state
    // (data lives in the owned "Default profile").
    await page.goto('/settings/profiles');
    const ownedCard = page
        .locator('[data-test="profile-disclosure"]')
        .filter({ hasText: 'Default profile' })
        .locator('..');
    await ownedCard.locator('[data-test="set-default-button"]').click();
    await expect(
        ownedCard.locator('[data-test="default-profile-badge"]'),
    ).toHaveCount(1);
});
