import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeAll(() => {
    seed('seed-ledger.php');
});

/** The only third-party origins the CSP allows (Courier Prime, currency only). */
const ALLOWED_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

test('pages load fonts and icons from the app and the currency font origins only', async ({
    page,
}) => {
    const foreign: string[] = [];
    const cspErrors: string[] = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (
            !['localhost', '127.0.0.1'].includes(url.hostname) &&
            url.protocol.startsWith('http') &&
            !ALLOWED_FONT_HOSTS.includes(url.hostname)
        ) {
            foreign.push(request.url());
        }
    });
    page.on('console', (message) => {
        if (/Content Security Policy/i.test(message.text())) {
            cspErrors.push(message.text());
        }
    });

    await login(page);
    for (const path of ['/dashboard', '/budget', '/ledger', '/envelopes']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
    }

    // The interface and display faces are the bundled ones.
    expect(
        await page.evaluate(async () => {
            await document.fonts.ready;
            const loaded = (family: string) =>
                [...document.fonts].some(
                    (face) =>
                        face.family.replace(/["']/g, '') === family &&
                        face.status === 'loaded',
                );
            return [loaded('Geist Variable'), loaded('Neuton')];
        }),
    ).toEqual([true, true]);
    // Icons render from the bundled subset (an svg, not an empty span).
    await expect(page.locator('svg.iconify').first()).toBeVisible();

    // Currency labels use the currency role (its declared family starts with
    // Courier Prime, with a monospace fallback while the font loads).
    await page.goto('/ledger');
    // The ledger opens on the current month, which may hold no seeded
    // transactions; navigate to the seeded month (October 2026) first.
    const monthDiff = (fromIso: string, toIso: string): number => {
        const [fromYear, fromMonth] = fromIso.split('-').map(Number);
        const [toYear, toMonth] = toIso.split('-').map(Number);
        return (toYear - fromYear) * 12 + (toMonth - fromMonth);
    };
    const steps = monthDiff(
        new Date().toISOString().slice(0, 10),
        '2026-10-22',
    );
    const navButton = page.getByRole('button', {
        name: steps <= 0 ? 'Previous month' : 'Next month',
    });
    for (let i = 0; i < Math.abs(steps); i += 1) {
        await navButton.click();
    }
    const amount = page
        .locator('[data-testid="transaction-amount"] .font-currency')
        .first();
    await expect(amount).toBeVisible();
    expect(
        await amount.evaluate(
            (element) => getComputedStyle(element).fontFamily,
        ),
    ).toContain('Courier Prime');

    expect(foreign).toEqual([]);
    expect(cspErrors).toEqual([]);
});
