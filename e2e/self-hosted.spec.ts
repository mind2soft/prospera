import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeAll(() => {
    seed('seed-ledger.php');
});

test('pages load fonts and icons from the app only, within the CSP', async ({
    page,
}) => {
    const foreign: string[] = [];
    const cspErrors: string[] = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (
            !['localhost', '127.0.0.1'].includes(url.hostname) &&
            url.protocol.startsWith('http')
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

    expect(foreign).toEqual([]);
    expect(cspErrors).toEqual([]);
});
