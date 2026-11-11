import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Automated WCAG 2.2 A/AA checks (axe-core) on the main screens, in both
 * themes. Complements the focused keyboard/flow specs (a11y.spec.ts,
 * ledger-a11y.spec.ts, dashboard-a11y.spec.ts, …): axe catches regressions
 * in names, roles, contrast and structure.
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    expect(
        results.violations.map((v) => ({
            rule: v.id,
            nodes: v.nodes.map((n) => n.target.join(' ')),
        })),
        label,
    ).toEqual([]);
}

for (const scheme of ['light', 'dark'] as const) {
    test.describe(`${scheme} theme`, () => {
        test.use({ colorScheme: scheme });

        test('guest pages', async ({ page }) => {
            for (const path of ['/login', '/register', '/forgot-password']) {
                await page.goto(path);
                await expect(page.locator('h1')).toBeVisible();
                await expectNoViolations(page, `${scheme} ${path}`);
            }
        });

        test('budget, envelopes and settings', async ({ page }) => {
            seed('seed.php');
            await login(page);
            for (const [path, ready] of [
                ['/budget', 'Paychecks'],
                ['/envelopes', 'Food'],
                ['/settings/account', 'Preferred language'],
                ['/settings/profiles', 'Default profile'],
            ]) {
                await page.goto(path);
                await expect(page.getByText(ready).first()).toBeVisible();
                await expectNoViolations(page, `${scheme} ${path}`);
            }
        });

        test('ledger and dashboard', async ({ page }) => {
            seed('seed-ledger.php');
            await login(page);
            await page.goto('/ledger');
            await expect(page.getByTestId('month-calendar')).toBeVisible();
            await expectNoViolations(page, `${scheme} /ledger`);

            await page.goto('/dashboard');
            await expect(
                page.getByRole('heading', { name: 'Dashboard', level: 1 }),
            ).toBeVisible();
            await expectNoViolations(page, `${scheme} /dashboard`);

            // With widgets (and their settings sheet open).
            for (const name of ['Envelope spending', 'Radial']) {
                const add = page.getByRole('button', { name: 'Add widget' });
                if ((await add.count()) === 0) {
                    await page
                        .getByRole('button', { name: 'Add a widget' })
                        .click();
                } else {
                    await add.click();
                }
                await page
                    .getByRole('menuitem', { name })
                    .click({ force: true });
                await expect(page.getByRole('menu')).toBeHidden();
            }
            // (The dark run finds the light run's widgets too.)
            await expect(page.locator('[data-widget-card]')).not.toHaveCount(0);
            await expectNoViolations(page, `${scheme} /dashboard widgets`);
            await page
                .locator('[data-widget-card]')
                .first()
                .getByRole('button', { name: 'Widget settings' })
                .click();
            await expect(page.getByRole('dialog')).toBeVisible();
            await expectNoViolations(page, `${scheme} widget settings`);
        });
    });
}
