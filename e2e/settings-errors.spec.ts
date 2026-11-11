import { expect, type Page, type Route, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Failed reads in Settings → Profiles and the share drawer show an error
 * with a retry (UX-R2-4) — never an empty list that reads as "no profiles",
 * "no members" or "not shared with anyone".
 */

/** Fails matching GETs with a 500 until `restore()`. */
async function failGets(page: Page, pattern: RegExp) {
    let failing = true;

    await page.route(pattern, async (route: Route) => {
        if (route.request().method() !== 'GET' || !failing) {
            await route.fallback();
            return;
        }

        await route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Server Error' }),
        });
    });

    return {
        restore: () => {
            failing = false;
        },
    };
}

test.beforeAll(() => {
    seed('seed-profiles.php');
});

test('a failed profile list load offers a retry', async ({ page }) => {
    await login(page);
    const profiles = await failGets(page, /\/api\/profiles$/);

    await page.goto('/settings/profiles');

    const error = page.locator('[data-test="query-error"]').filter({
        hasText: 'Your profiles could not be loaded.',
    });
    await expect(error).toBeVisible({ timeout: 15_000 });

    profiles.restore();
    await error.getByRole('button', { name: 'Retry' }).click();
    await expect(page.locator('[data-test="profile-disclosure"]')).toHaveCount(
        2,
    );
});

test('a failed members load offers a retry', async ({ page }) => {
    await login(page);
    const members = await failGets(page, /\/api\/profiles\/\d+\/members$/);

    await page.goto('/settings/profiles');

    const error = page.locator('[data-test="query-error"]').filter({
        hasText: 'The members of this profile could not be loaded.',
    });
    await expect(error).toBeVisible({ timeout: 15_000 });

    members.restore();
    await error.getByRole('button', { name: 'Retry' }).click();
    await expect(
        page
            .locator('[id^="profile-panel-"]')
            .getByText('playwright@example.com'),
    ).toBeVisible();
});

test('a failed share list load offers a retry instead of an empty list', async ({
    page,
}) => {
    await login(page);
    const shares = await failGets(page, /\/api\/envelopes\/\d+\/shares$/);

    await page.goto('/envelopes');
    await page
        .getByRole('button', { name: 'Envelope options for Food' })
        .click();
    await page.getByRole('menuitem', { name: 'Share' }).click();

    const drawer = page.getByRole('dialog');
    const error = drawer.locator('[data-test="query-error"]');
    await expect(error).toContainText(
        'The people with access could not be loaded.',
        { timeout: 15_000 },
    );

    shares.restore();
    await error.getByRole('button', { name: 'Retry' }).click();
    await expect(drawer.getByText('playwright@example.com')).toBeVisible();
});

test('a refused invite explains why next to the email field', async ({
    page,
}) => {
    await login(page);
    const message =
        'This address already has a pending invite awaiting verification.';
    await page.route(/\/api\/profiles\/\d+\/invites$/, (route) =>
        route.request().method() === 'POST'
            ? route.fulfill({
                  status: 422,
                  contentType: 'application/json',
                  body: JSON.stringify({
                      message,
                      errors: { email: [message] },
                  }),
              })
            : route.fallback(),
    );

    await page.goto('/settings/profiles');
    const email = page.getByRole('textbox', { name: 'Invite someone' });
    await email.fill('reserved@example.com');
    await page.getByRole('button', { name: 'Invite', exact: true }).click();

    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAccessibleDescription(message);
    await expect(email).toBeFocused();
});
