import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

// Browser-level signals that a page did NOT render correctly:
//   - uncaught exceptions (broken JS, crashed render functions)
//   - console errors of any kind
//   - Vue dev warnings about hydration mismatches or i18n message
//     compilation (only emitted when assets were built in dev mode, but
//     free to keep watching for)
const RENDER_NOISE = [
    /\[Vue warn\]/,
    /Hydration .* mismatch/i,
    /Message compilation error/,
    /Invalid linked format/,
];

type Violation = { kind: 'console' | 'pageerror'; text: string };

function watchForRenderErrors(page: Page): Violation[] {
    const violations: Violation[] = [];

    page.on('console', (msg) => {
        if (
            msg.type() === 'error' ||
            RENDER_NOISE.some((re) => re.test(msg.text()))
        ) {
            violations.push({ kind: 'console', text: msg.text() });
        }
    });
    page.on('pageerror', (error) => {
        violations.push({ kind: 'pageerror', text: String(error) });
    });

    return violations;
}

async function expectNoViolations(
    violations: Violation[],
    pageLabel: string,
): Promise<void> {
    const summary = violations.map((v) => `[${v.kind}] ${v.text}`).join('\n\n');

    expect(summary, `${pageLabel} rendered with browser errors`).toBe('');
}

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

async function visit(
    page: Page,
    path: string,
    landmark: string,
): Promise<void> {
    // Generous timeout: the first hit may compile fresh Vite modules.
    await page.goto(path, { timeout: 15_000 });
    await expect(page.getByText(landmark).first()).toBeVisible({
        timeout: 15_000,
    });

    // Skeletons must never outlive the settled page: a lingering skeleton
    // means the page's data queries resolved to something the template
    // does not handle.
    await expect(page.locator('[data-slot="skeleton"]').first()).toBeHidden();
}

// `settings/security` sits behind Laravel's password-confirmation gate, so a
// fresh session is first bounced to the confirm screen.
async function confirmPassword(page: Page): Promise<void> {
    await page.waitForURL((url) =>
        url.pathname.endsWith('/user/confirm-password'),
    );
    await page.getByLabel('Password', { exact: true }).fill(LOGIN.password);
    await page.locator('[data-test="confirm-password-button"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/settings/security'));
}

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the welcome page renders for guests', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await visit(page, '/', 'Give every dollar');
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();

    await expectNoViolations(violations, 'Welcome (guest)');
});

test('the dashboard renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/dashboard', 'Dashboard');

    // Material Symbols come from the bundled build-time subset: they render
    // as inline SVG (an unregistered icon renders nothing).
    await expect(
        page.locator('svg.iconify--material-symbols').first(),
    ).toBeVisible();

    await expectNoViolations(violations, 'Dashboard');
});

test('the budget page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/budget', 'Budget');

    // seed.php creates one draft budget with envelopes — the loaded state,
    // not the empty state, must render.
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    await expectNoViolations(violations, 'Budget');
});

test('the ledger page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/ledger', 'Ledger');

    // seed.php creates no ledgers — the empty state must render.
    await expect(
        page.getByRole('button', { name: 'Create ledger' }),
    ).toBeVisible();

    await expectNoViolations(violations, 'Ledger');
});

test('the envelopes page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/envelopes', 'Envelopes');

    // seed.php creates personal envelopes — the loaded state, not the empty
    // state, must render.
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    await expectNoViolations(violations, 'Envelopes');
});

test('the account settings page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/settings/account', 'Account');
    await expect(page.getByText('Preferred language')).toBeVisible();

    await expectNoViolations(violations, 'Settings › Account');
});

test('the profiles settings page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/settings/profiles', 'Profiles');

    // Owned profile membership (seed.php creates the default profile).
    await expect(page.getByText('Owner').first()).toBeVisible();

    // Regression guard: the invite e-mail placeholder contains an "@".
    // vue-i18n treats "@" as a linked-message marker, so an unescaped
    // literal ("person@example.com") throws and tears down the whole card
    // — see "Message compilation error: Invalid linked format".
    await expect(page.getByPlaceholder('person@example.com')).toBeVisible();

    await expectNoViolations(violations, 'Settings › Profiles');
});

test('the appearance settings page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/settings/appearance', 'Appearance settings');

    await expectNoViolations(violations, 'Settings › Appearance');
});

test('the notifications settings page renders', async ({ page }) => {
    const violations = watchForRenderErrors(page);

    await login(page);
    await visit(page, '/settings/notifications', 'Notification settings');

    // The master switch and every toggle row are rendered (all defaults).
    await expect(
        page.getByRole('switch', { name: 'Enable email notifications' }),
    ).toBeChecked();
    for (const toggle of [
        'Payment reminders',
        'Ledger expired',
        'Contributor transactions',
        'Envelope limit exceeded',
    ]) {
        await expect(page.getByRole('switch', { name: toggle })).toBeChecked();
    }

    await expectNoViolations(violations, 'Settings › Notifications');
});

test('the security settings page renders after password confirmation', async ({
    page,
}) => {
    const violations = watchForRenderErrors(page);

    await login(page);

    // The confirmation bounce itself must render cleanly too.
    await page.goto('/settings/security', { timeout: 15_000 });
    await confirmPassword(page);

    // Generous timeout: the first hit may compile fresh Vite modules.
    await expect(page.getByText('Security settings').first()).toBeVisible({
        timeout: 15_000,
    });

    await expectNoViolations(violations, 'Settings › Security');
});
