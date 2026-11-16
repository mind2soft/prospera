import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

const MASTER_LABEL = 'Enable email notifications';

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

async function visitNotifications(page: Page): Promise<void> {
    await page.goto('/settings/notifications', { timeout: 15_000 });
    await expect(page.getByText('Notification settings').first()).toBeVisible({
        timeout: 15_000,
    });
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('toggling notification settings persists across reloads', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    // Defaults: everything on (seed.php never saved settings for this user).
    const master = page.getByRole('switch', { name: MASTER_LABEL });
    await expect(master).toBeChecked();

    // Turn the master off and reload — the choice must survive.
    await master.click();
    await expect(master).not.toBeChecked();
    await visitNotifications(page);

    const reloadedMaster = page.getByRole('switch', {
        name: MASTER_LABEL,
    });
    await expect(reloadedMaster).not.toBeChecked();

    // Master off: the individual rows are semantically ignored — grayed
    // with an explicit hint.
    await expect(
        page.locator('[data-test="notifications-all-off"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="notifications-toggle-profile_invite"]'),
    ).toHaveClass(/opacity-60/);

    // Restore the defaults so other specs start from a clean state.
    await reloadedMaster.click();
    await expect(reloadedMaster).toBeChecked();
    await visitNotifications(page);
    await expect(
        page.getByRole('switch', { name: MASTER_LABEL }),
    ).toBeChecked();
});

test('a single toggle persists without touching the others', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    const invites = page.getByRole('switch', {
        name: 'Profile invitations',
    });
    const master = page.getByRole('switch', { name: MASTER_LABEL });
    await expect(invites).toBeChecked();
    await expect(master).toBeChecked();

    await invites.click();
    await expect(invites).not.toBeChecked();
    await expect(master).toBeChecked();

    await visitNotifications(page);
    await expect(
        page.getByRole('switch', { name: 'Profile invitations' }),
    ).not.toBeChecked();
    await expect(
        page.getByRole('switch', { name: MASTER_LABEL }),
    ).toBeChecked();

    // Restore the default.
    await page.getByRole('switch', { name: 'Profile invitations' }).click();
    await expect(
        page.getByRole('switch', { name: 'Profile invitations' }),
    ).toBeChecked();
});

test('notifications that are not implemented yet are disabled, marked "Coming soon"', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    const toggle = page.getByRole('switch', { name: 'Payment reminders' });
    await expect(toggle).toBeDisabled();
    await expect(toggle).not.toBeChecked();
    await expect(toggle).toHaveAccessibleDescription('Coming soon');

    await expect(
        page.locator('[data-test="notifications-coming-soon"]'),
    ).toHaveCount(1);

    // The implemented ones stay interactive.
    await expect(
        page.getByRole('switch', { name: 'Profile invitations' }),
    ).toBeEnabled();
    await expect(
        page.getByRole('switch', { name: 'Ledger expired' }),
    ).toBeEnabled();
    await expect(
        page.getByRole('switch', { name: 'Contributor transactions' }),
    ).toBeEnabled();
    await expect(
        page.getByRole('switch', { name: 'Envelope limit exceeded' }),
    ).toBeEnabled();
});

test('the Ledger expired toggle autosaves and persists across reloads', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    const expired = page.getByRole('switch', { name: 'Ledger expired' });
    await expect(expired).toBeEnabled();
    await expect(expired).toBeChecked();

    // Autosave on toggle — no save button; the choice must survive a reload.
    await expired.click();
    await expect(expired).not.toBeChecked();
    await expect(
        page.locator('[data-test="notifications-toggle-ledger_expired"]'),
    ).not.toHaveClass(/opacity-60/);

    await visitNotifications(page);
    const reloaded = page.getByRole('switch', { name: 'Ledger expired' });
    await expect(reloaded).not.toBeChecked();
    await expect(reloaded).toBeEnabled();

    // Restore the default so other specs start from a clean state.
    await reloaded.click();
    await expect(reloaded).toBeChecked();
});

test('the Contributor transactions toggle autosaves and persists across reloads', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    const contributor = page.getByRole('switch', {
        name: 'Contributor transactions',
    });
    await expect(contributor).toBeEnabled();
    await expect(contributor).toBeChecked();

    // Autosave on toggle — no save button; the choice must survive a reload.
    await contributor.click();
    await expect(contributor).not.toBeChecked();
    await expect(
        page.locator(
            '[data-test="notifications-toggle-contributor_transactions"]',
        ),
    ).not.toHaveClass(/opacity-60/);

    await visitNotifications(page);
    const reloaded = page.getByRole('switch', {
        name: 'Contributor transactions',
    });
    await expect(reloaded).not.toBeChecked();
    await expect(reloaded).toBeEnabled();

    // Restore the default so other specs start from a clean state.
    await reloaded.click();
    await expect(reloaded).toBeChecked();
});

test('the Envelope limit exceeded toggle autosaves and persists across reloads', async ({
    page,
}) => {
    await login(page);
    await visitNotifications(page);

    const envelopeLimit = page.getByRole('switch', {
        name: 'Envelope limit exceeded',
    });
    await expect(envelopeLimit).toBeEnabled();
    await expect(envelopeLimit).toBeChecked();

    // Autosave on toggle — no save button; the choice must survive a reload.
    await envelopeLimit.click();
    await expect(envelopeLimit).not.toBeChecked();
    await expect(
        page.locator(
            '[data-test="notifications-toggle-envelope_limit_exceeded"]',
        ),
    ).not.toHaveClass(/opacity-60/);

    await visitNotifications(page);
    const reloaded = page.getByRole('switch', {
        name: 'Envelope limit exceeded',
    });
    await expect(reloaded).not.toBeChecked();
    await expect(reloaded).toBeEnabled();

    // Restore the default so other specs start from a clean state.
    await reloaded.click();
    await expect(reloaded).toBeChecked();
});
