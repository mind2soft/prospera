import { type Browser, expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Chart details beyond the dashboard flow: the donut tooltip formats its
 * share in the active locale (UX-R2-9), and charts honor
 * `prefers-reduced-motion` (UX-R2-12) — Unovis animates with d3
 * transitions, out of reach of the CSS reduced-motion rule.
 */

test.beforeAll(() => {
    // A radial widget over two rows.
    seed('seed-dashboard.php', 'seed-dashboard-xss.php');
});

async function openDashboard(
    browser: Browser,
    options: { locale?: string; reducedMotion?: 'reduce' | 'no-preference' },
) {
    const context = await browser.newContext(options);
    const page = await context.newPage();
    await login(page);
    await page.goto('/dashboard');

    return { context, page };
}

const chart = (page: Page) =>
    page.locator('[data-widget-card]').first().getByTestId('radial-chart');

/** Whether any chart segment has a d3 transition scheduled or running. */
const segmentsAnimating = (page: Page): Promise<boolean> =>
    chart(page)
        .locator('path')
        .evaluateAll((paths) =>
            paths.some(
                (path) =>
                    (path as unknown as { __transition?: object })
                        .__transition !== undefined,
            ),
        );

const segmentsDrawn = (page: Page) =>
    expect
        .poll(() => chart(page).locator('path').count(), { timeout: 15_000 })
        .toBeGreaterThanOrEqual(2);

test('the donut tooltip formats its share in the active locale', async ({
    browser,
}) => {
    const { context, page } = await openDashboard(browser, {
        locale: 'fr-FR',
    });
    await segmentsDrawn(page);

    const box = await chart(page).boundingBox();
    expect(box).not.toBeNull();
    const cx = (box?.x ?? 0) + (box?.width ?? 0) / 2;
    const cy = (box?.y ?? 0) + (box?.height ?? 0) / 2;
    const radius = Math.min(box?.width ?? 0, box?.height ?? 0) * 0.3;

    // Sweep around the donut until a segment tooltip shows.
    const shares = new Set<string>();
    for (let step = 0; step < 16 && shares.size === 0; step++) {
        const angle = (step / 16) * 2 * Math.PI;
        await page.mouse.move(
            cx + radius * Math.sin(angle),
            cy - radius * Math.cos(angle),
            { steps: 2 },
        );
        for (const text of await page
            .locator('strong + br + span')
            .allTextContents()) {
            const share = /\(([^)]*%)\)/.exec(text)?.[1];
            if (share !== undefined) {
                shares.add(share);
            }
        }
    }

    expect(shares.size).toBeGreaterThan(0);
    for (const share of shares) {
        // French percent format: decimal comma and a (narrow) space before %.
        expect(share).toMatch(/^\d+(,\d)?\s%$/u);
    }

    await context.close();
});

test('charts skip their animations when reduced motion is preferred', async ({
    browser,
}) => {
    const still = await openDashboard(browser, { reducedMotion: 'reduce' });
    await segmentsDrawn(still.page);
    expect(await segmentsAnimating(still.page)).toBe(false);
    await still.context.close();
});
