import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const OWNER = {
    email: 'playwright@example.com',
    password: 'Password!234',
};
const PARTNER = {
    email: 'partner@example.com',
    password: 'Password!234',
};
const WAVERER = {
    email: 'waverer@example.com',
    password: 'Password!234',
};

const REGISTER_KEY = 'a'.repeat(40);
const ACCEPT_KEY = 'b'.repeat(40);
const DECLINE_KEY = 'c'.repeat(40);

async function logout(page: Page): Promise<void> {
    // Sessions are cookie-based; clearing cookies signs the context out.
    await page.context().clearCookies();
}

async function login(page: Page, account: typeof OWNER): Promise<void> {
    // Reset Fortify's login rate limiter (5/min per email+IP).
    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await logout(page);
    await page.goto('/login');
    await page
        .getByRole('textbox', { name: 'Email address' })
        .fill(account.email);
    await page
        .getByRole('textbox', { name: 'Password' })
        .fill(account.password);
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
    execSync('npm run e2e:seed -- seed-invite.php', { cwd: '.' });
});

test('a new user registers through the invite link and joins the profile', async ({
    page,
}) => {
    // Signed-out visitor opens the invite link.
    await page.goto(`/invites/${REGISTER_KEY}`);

    // Registration page with invite context: profile, inviter, locked email.
    await expect(
        page.getByText('Join “Default profile”', { exact: true }),
    ).toBeVisible();
    await expect(page.getByText(/Playwright Tester invited/)).toBeVisible();

    const email = page.getByRole('textbox', { name: 'Email address' });
    await expect(email).toHaveValue('invitee@example.com');
    await expect(email).toHaveAttribute('readonly', '');

    await page.getByRole('textbox', { name: 'Name' }).fill('Invitee Member');
    await page
        .getByRole('textbox', { name: 'Password', exact: true })
        .fill('Secret!Pass1');
    await page
        .getByRole('textbox', { name: 'Confirm password' })
        .fill('Secret!Pass1');
    await page.getByRole('button', { name: /Create account and join/ }).click();

    // Account created (Fortify routes new users to email verification).
    await page.waitForURL((url) => !url.pathname.endsWith('/register'));

    // Membership is granted on email verification: simulate the invitee
    // clicking the verification link (marks verified + fires Verified).
    execSync(
        `php artisan tinker --env=e2e --execute="\\$u = App\\Models\\User::where('email', 'invitee@example.com')->firstOrFail(); \\$u->markEmailAsVerified(); event(new Illuminate\\Auth\\Events\\Verified(\\$u));"`,
        { cwd: '.' },
    );

    // The invite was consumed and membership created: as the owner, the
    // invitee is now a member of the profile.
    await login(page, OWNER);
    const profiles = await page.request.get('/api/profiles');
    expect(profiles.ok()).toBeTruthy();
    const profileId = (await profiles.json()).profiles[0].id;
    const members = await page.request.get(
        `/api/profiles/${profileId}/members`,
    );
    expect(members.ok()).toBeTruthy();
    const memberEmails: string[] = (await members.json()).members.map(
        (m: { email: string }) => m.email,
    );
    expect(memberEmails).toContain('invitee@example.com');

    // The invite is consumed → no pending invite for that email remains.
    const invites = await page.request.get(
        `/api/profiles/${profileId}/invites`,
    );
    expect(invites.ok()).toBeTruthy();
    const pendingEmails: string[] = (await invites.json()).invites.map(
        (i: { email: string }) => i.email,
    );
    expect(pendingEmails).not.toContain('invitee@example.com');
});

test('a signed-in user can accept an invite from the link', async ({
    page,
}) => {
    await login(page, PARTNER);
    await page.goto(`/invites/${ACCEPT_KEY}`);

    // Accept confirmation: profile name + inviter, no registration form.
    await expect(
        page.getByText('Join “Default profile”', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Playwright Tester invited partner@example.com'),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Accept invite' }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Decline' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Name' })).toHaveCount(0);

    await page.getByRole('button', { name: 'Accept invite' }).click();

    await page.waitForURL((url) => url.pathname.endsWith('/dashboard'));

    // The joined profile shows up in the switcher as a shared profile.
    const menu = await openProfilesMenu(page);
    const item = menu.getByRole('menuitem', { name: /Default profile/ });
    await expect(item).toContainText('Shared');
});

test('declining an invite does not grant membership', async ({ page }) => {
    await login(page, WAVERER);
    await page.goto(`/invites/${DECLINE_KEY}`);

    await expect(
        page.getByText('Join “Default profile”', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Decline' }).click();

    await page.waitForURL((url) => url.pathname.endsWith('/dashboard'));

    // Still not a member.
    await login(page, OWNER);
    const profiles = await page.request.get('/api/profiles');
    const profileId = (await profiles.json()).profiles[0].id;
    const members = await page.request.get(
        `/api/profiles/${profileId}/members`,
    );
    const memberEmails: string[] = (await members.json()).members.map(
        (m: { email: string }) => m.email,
    );
    expect(memberEmails).not.toContain('waverer@example.com');
});

test('an expired invite link renders the explicit error state', async ({
    page,
}) => {
    await login(page, OWNER);
    await page.goto('/invites/not-a-real-invite-key');

    await expect(page.getByText('This invite is not available')).toBeVisible();
    await expect(
        page.getByText('The invite link is invalid or has expired.'),
    ).toBeVisible();
});
