import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

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
