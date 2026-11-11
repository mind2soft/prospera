import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';
import { login, PASSWORD, seed } from './support';

/**
 * The invite landing / confirmation page's states beyond the happy paths
 * (e2e/invite.spec.ts): flashed errors on the auth layout, the signed-in
 * confirmation's title, returning members, email mismatches, unverified
 * accounts and API errors without a message.
 */

const RETURNING_KEY = 'g'.repeat(40);
const UNVERIFIED_KEY = 'h'.repeat(40);
const OTHER_KEY = 'i'.repeat(40);
const EXPIRED_KEY = 'j'.repeat(40);

const ACCEPT_API = /\/api\/invites\/[^/]+\/accept$/;

async function signOut(page: Page): Promise<void> {
    await page.context().clearCookies();
}

test.beforeAll(() => {
    seed('seed-invite-states.php');
});

test('an expired invite link explains itself to a signed-out visitor', async ({
    page,
}) => {
    await page.goto(`/invites/${EXPIRED_KEY}`);

    // The flashed reason reaches the toaster on the auth layout…
    await expect(page.getByText('This invite has expired.')).toBeVisible();
    // …and the page states it inline as well (toasts are transient).
    const notice = page.locator('[data-test="invite-unavailable"]');
    await expect(notice).toContainText('This invite is not available');
    await expect(notice).toContainText('You can still create an account');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Create an account' }),
    ).toBeVisible();
});

test('a signed-out member who logs in from the invite comes back to it', async ({
    page,
}) => {
    await signOut(page);
    await page.goto(`/invites/${RETURNING_KEY}`);
    await expect(
        page.getByText('Join “Default profile”', { exact: true }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname === '/login');

    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await page
        .getByRole('textbox', { name: 'Email address' })
        .fill('returning@example.com');
    await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();

    // Back on the invitation — now its confirmation, titled as such.
    await page.waitForURL(
        (url) => url.pathname === `/invites/${RETURNING_KEY}`,
    );
    await expect(
        page.getByRole('heading', { level: 1, name: 'Join a shared profile' }),
    ).toBeVisible();
    await expect(page).toHaveTitle(/^Join a shared profile/);
    await expect(
        page.getByRole('heading', { level: 2, name: 'Join “Default profile”' }),
    ).toBeVisible();

    // An error without a message (e.g. the invite was revoked meanwhile)
    // still says what happened.
    await page.route(ACCEPT_API, (route) =>
        route.fulfill({
            status: 404,
            contentType: 'application/json',
            body: JSON.stringify({ message: '' }),
        }),
    );
    await page.getByRole('button', { name: 'Accept invite' }).click();
    await expect(
        page.getByText('This item could not be found.', { exact: false }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Accept invite' }),
    ).toBeEnabled();
    await page.unroute(ACCEPT_API);

    // The success toast survives the visit to the dashboard.
    await page.getByRole('button', { name: 'Accept invite' }).click();
    await page.waitForURL((url) => url.pathname === '/dashboard');
    await expect(page.getByText('You joined “Default profile”.')).toBeVisible();
});

test('an invite issued to another address says so instead of offering Accept', async ({
    page,
}) => {
    await login(page, 'returning@example.com');
    await page.goto(`/invites/${OTHER_KEY}/accept`);
    await page.waitForURL((url) => url.pathname === `/invites/${OTHER_KEY}`);

    await expect(
        page.getByText('This invite was issued to another email address.'),
    ).toBeVisible();
    const mismatch = page.locator('[data-test="invite-mismatch"]');
    await expect(mismatch).toContainText(
        'It was sent to someone-else@example.com, but you are signed in as returning@example.com.',
    );
    await expect(
        page.getByRole('button', { name: 'Accept invite' }),
    ).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
});

test('an unverified account is asked to verify before accepting', async ({
    page,
}) => {
    await login(page, 'unverified@example.com');
    await page.goto(`/invites/${UNVERIFIED_KEY}`);

    await expect(
        page.locator('[data-test="invite-verify-first"]'),
    ).toContainText('Verify your email address first');
    await expect(
        page.getByRole('button', { name: 'Accept invite' }),
    ).toHaveCount(0);

    await page.getByRole('link', { name: 'Verify my email address' }).click();
    await page.waitForURL((url) => url.pathname === '/email/verify');

    // A mistyped address can be fixed (or the account deleted) from there.
    await page
        .getByRole('link', { name: 'Update or delete your account' })
        .click();
    await page.waitForURL((url) => url.pathname === '/settings/account');
    await expect(
        page.getByRole('button', { name: 'Delete account' }),
    ).toBeVisible();
});

test('declining an invite asks for confirmation first', async ({ page }) => {
    seed('seed-invite-states.php');
    await login(page, 'returning@example.com');
    await page.goto(`/invites/${RETURNING_KEY}`);

    await page.getByRole('button', { name: 'Decline' }).click();
    const dialog = page.getByRole('dialog', {
        name: 'Decline the invitation to “Default profile”?',
    });
    await expect(dialog).toContainText(
        'Playwright Tester will need to invite you again',
    );

    // Keeping the invite leaves it untouched.
    await dialog.getByRole('button', { name: 'Keep invite' }).click();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Accept invite' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Decline' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Decline invite' })
        .click();
    await page.waitForURL((url) => url.pathname === '/dashboard');
    await expect(page.getByText('Invite declined.')).toBeVisible();
});
