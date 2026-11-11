import { execSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

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

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('the login form follows the DOM tab order', async ({ page }) => {
    await page.goto('/login');

    // No positive tabindex overrides the reading order (WCAG 2.4.3).
    const positive = await page
        .locator('[tabindex]')
        .evaluateAll(
            (nodes) =>
                nodes.filter((n) => Number(n.getAttribute('tabindex')) > 0)
                    .length,
        );
    expect(positive).toBe(0);

    const email = page.getByRole('textbox', { name: 'Email address' });
    await expect(email).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(
        page.getByRole('link', { name: 'Forgot your password?' }),
    ).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeFocused();
});

test('envelope options stay visible while focused', async ({ page }) => {
    await login(page);
    await page.goto('/envelopes');

    const options = page.getByRole('button', {
        name: 'Envelope options for Food',
    });
    await options.focus();
    await expect(options).toBeFocused();
    await expect(options).toHaveCSS('opacity', '1');
});

test('reduced motion removes interface animations', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();
    await login(page);
    await page.goto('/budget');

    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(sheet).toHaveCSS('animation-duration', '1e-05s');

    // The sheet still closes (its exit animation ends immediately).
    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();

    await context.close();
});

test('every main page has a single top-level heading', async ({ page }) => {
    await login(page);
    for (const [path, title] of [
        ['/dashboard', 'Dashboard'],
        ['/budget', 'Budget'],
        ['/ledger', 'Ledger'],
        ['/envelopes', 'Envelopes'],
        ['/settings/profiles', 'Profiles'],
    ]) {
        await page.goto(path);
        const h1 = page.getByRole('heading', { level: 1 });
        await expect(h1).toHaveCount(1);
        await expect(h1).toHaveText(title);
    }
});

test('empty-state headlines are solid text, not gradient-clipped', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');

    const headline = page.getByRole('heading', {
        name: 'Create your first ledger',
    });
    await expect(headline).toBeVisible();
    await expect(headline).not.toHaveCSS('color', 'rgba(0, 0, 0, 0)');
    await expect(headline).toHaveCSS('background-image', 'none');
});

test('the budget table meets contrast in both themes', async ({ page }) => {
    await login(page);
    for (const appearance of ['light', 'dark'] as const) {
        // The default "system" appearance follows the color scheme.
        await page.emulateMedia({ colorScheme: appearance });
        await page.goto('/budget');
        expect(
            await page
                .locator('html')
                .evaluate((html) => html.classList.contains('dark')),
        ).toBe(appearance === 'dark');
        await expect(page.getByText('Left over')).toBeVisible();
        const results = await new AxeBuilder({ page })
            .include('table')
            .withRules(['color-contrast'])
            .analyze();
        expect(results.violations, appearance).toEqual([]);
    }
});

const NAME_RULES = [
    'label',
    'button-name',
    'select-name',
    'aria-input-field-name',
    'aria-command-name',
];

async function expectNamedControls(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withRules(NAME_RULES)
        .analyze();
    expect(
        results.violations.map((v) => [v.id, v.nodes.map((n) => n.target)]),
        label,
    ).toEqual([]);
}

test('budget form controls have accessible names', async ({ page }) => {
    await login(page);
    await page.goto('/budget');
    await expect(
        page.getByRole('combobox', { name: 'Select budget' }),
    ).toBeVisible();
    await expect(
        page.getByRole('spinbutton', { name: 'Paychecks amount' }),
    ).toBeVisible();
    await expectNamedControls(page, 'budget page');

    await page.getByRole('button', { name: 'Add envelope' }).click();
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByRole('combobox', { name: 'Envelope' }),
    ).toBeVisible();
    await expect(dialog.getByRole('combobox', { name: 'Type' })).toBeVisible();
    await expect(
        dialog.getByRole('spinbutton', { name: 'Amount' }),
    ).toBeVisible();
    await expectNamedControls(page, 'add envelope dialog');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Share' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expectNamedControls(page, 'share drawer');
});
