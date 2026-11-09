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

    const defaultItem = menu.getByRole('menuitem', {
        name: /Default profile/,
    });
    await expect(defaultItem).toContainText('Owned');

    const familyItem = menu.getByRole('menuitem', { name: /Family/ });
    await expect(familyItem).toContainText('Shared');

    // The owned profile is selected by default → only it carries the check.
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
    await menu.getByRole('menuitem', { name: /Family/ }).click();

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
    await menu2.getByRole('menuitem', { name: /Default profile/ }).click();
    await expect(page.getByText('Paychecks').first()).toBeVisible();
    expect(
        await page.evaluate((key) => localStorage.getItem(key), PROFILE_KEY),
    ).toBe(storedBefore);
});

test('the user can set a shared profile as their default from the switcher', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    // Generous timeout: the first hit may compile fresh Vite modules.
    await expect(page.getByText('Paychecks').first()).toBeVisible({
        timeout: 15_000,
    });

    const menu = await openProfilesMenu(page);
    const defaultItem = menu.getByRole('menuitem', {
        name: /Default profile/,
    });
    const familyItem = menu.getByRole('menuitem', { name: /Family/ });

    // The seeded default profile carries the star marker; the shared one
    // offers the set-default action instead.
    await expect(
        defaultItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(1);
    await expect(
        familyItem.locator('[data-test="set-default-button"]'),
    ).toHaveCount(1);

    // Selecting the action marks the shared profile as the user's default.
    // The button stops propagation, so the menu stays open and the reactive
    // update is observable in place: the star moves to the shared profile.
    await familyItem.locator('[data-test="set-default-button"]').click();
    await expect(
        familyItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(1);
    await expect(
        defaultItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(0);

    // The server-side default survives a wipe of the locally stored current
    // profile: with no stored id, the shared profile is selected on boot.
    await page.evaluate((key) => localStorage.removeItem(key), PROFILE_KEY);
    await page.reload();
    await expect(page.getByText('Create your first budget')).toBeVisible();

    // Restore the default to the owned profile: later specs seed their data
    // into the user's default profile and expect the suite's baseline state
    // (data lives in the owned "Default profile").
    const restored = await openProfilesMenu(page);
    const ownedItem = restored.getByRole('menuitem', {
        name: /Default profile/,
    });
    await ownedItem.locator('[data-test="set-default-button"]').click();
    await expect(
        ownedItem.locator('[data-test="default-profile-star"]'),
    ).toHaveCount(1);
});
