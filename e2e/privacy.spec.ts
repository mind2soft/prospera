import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Privacy policy page (issue #18): public, localized (English fallback),
// rendered client-side from sanitized markdown. The page needs no fixture —
// the beforeAll seed only follows the per-spec e2e database convention.
test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the privacy page is reachable logged out and renders prose, not raw markdown', async ({
    page,
}) => {
    await page.goto('/privacy');

    await expect(page).toHaveURL(/\/privacy$/);
    await expect(
        page.getByRole('heading', { name: 'Privacy Policy', level: 1 }),
    ).toBeVisible();

    // The page shows a localized "Last updated" date and the WIP notice.
    await expect(
        page.locator('[data-test="privacy-last-updated"]'),
    ).toContainText('Last updated');
    await expect(page.locator('[data-test="privacy-wip"]')).toBeVisible();

    const content = page.locator('[data-test="privacy-content"]');

    // Rendered as prose: headings become elements, not literal `##` markers.
    await expect(
        content.getByRole('heading', { name: 'What is collected', level: 2 }),
    ).toBeVisible();
    const body = await content.innerHTML();
    expect(body).not.toContain('##');

    // Sanitization: whatever the document contains, no script or inline
    // event handler may reach the rendered article (the renderer must keep
    // the HTML inert).
    expect(body).not.toContain('<script');
    expect(body).not.toContain('onerror=');
    expect(await content.locator('script, iframe, object, embed').count()).toBe(
        0,
    );
});

test('the landing page footer links the privacy page', async ({ page }) => {
    await page.goto('/');

    await page.locator('[data-test="privacy-link"]').click();
    await expect(page).toHaveURL(/\/privacy$/);
    await expect(
        page.getByRole('heading', { name: 'Privacy Policy', level: 1 }),
    ).toBeVisible();
});

test('the registration page links the privacy policy', async ({ page }) => {
    await page.goto('/register');

    await page.locator('[data-test="privacy-link"]').click();
    await expect(page).toHaveURL(/\/privacy$/);
});

test('the login page links the privacy policy', async ({ page }) => {
    await page.goto('/login');

    await page.locator('[data-test="privacy-link"]').click();
    await expect(page).toHaveURL(/\/privacy$/);
});

test('the privacy page falls back to English for an untranslated locale', async ({
    browser,
}) => {
    // de-DE has no privacy.md translation yet: the English file is the
    // fallback (same rule as the i18n JSON bundles).
    const context = await browser.newContext({ locale: 'de-DE' });
    const page = await context.newPage();

    await page.goto('/privacy');
    await expect(
        page.getByRole('heading', { name: 'Privacy Policy', level: 1 }),
    ).toBeVisible();

    await context.close();
});
