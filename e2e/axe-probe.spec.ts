import AxeBuilder from '@axe-core/playwright';
import { test } from '@playwright/test';
import { login, seed } from './support';

test.beforeAll(() => seed('seed.php'));

for (const path of [
    '/dashboard',
    '/budget',
    '/ledger',
    '/envelopes',
    '/settings/profiles',
    '/settings/account',
    '/login',
]) {
    test(`probe ${path}`, async ({ page }) => {
        if (path !== '/login') await login(page);
        await page.goto(path);
        await page.waitForTimeout(1500);
        const r = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        for (const v of r.violations) {
            console.log(
                path,
                v.id,
                v.impact,
                v.nodes.length,
                v.nodes
                    .slice(0, 4)
                    .map(
                        (n) =>
                            n.target.join(' ') +
                            ' :: ' +
                            (n.failureSummary ?? '')
                                .split('\n')
                                .slice(1, 2)
                                .join(''),
                    )
                    .join(' | '),
            );
        }
    });
}
