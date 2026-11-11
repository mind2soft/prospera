import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

const EVIL_ENVELOPE = '<img src=x onerror="window.__xss=1">';
const EVIL_GROUP = '<img src=y onerror="window.__xss=2">';

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
    execSync('npm run e2e:seed -- seed-dashboard.php', { cwd: '.' });
    execSync('npm run e2e:seed -- seed-dashboard-xss.php', { cwd: '.' });
});

test('chart tooltips render user-provided names as text, never markup', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');

    const card = page.locator('[data-widget-card]').first();
    // The chart is hidden from assistive tech (the legend is its text
    // equivalent), so it is located by test id rather than by role.
    // Both segments drawn (the pie animates in).
    await expect
        .poll(() => card.getByTestId('radial-chart').locator('path').count(), {
            timeout: 15_000,
        })
        .toBeGreaterThanOrEqual(2);

    // Sweep the pointer around the pie: the tooltip is rendered through
    // innerHTML, and each one must show the hovered row's name verbatim.
    const box = await card.getByTestId('radial-chart').boundingBox();
    expect(box).not.toBeNull();

    const cx = (box?.x ?? 0) + (box?.width ?? 0) / 2;
    const cy = (box?.y ?? 0) + (box?.height ?? 0) / 2;
    const radius = Math.min(box?.width ?? 0, box?.height ?? 0) * 0.3;
    const tooltipNames = new Set<string>();

    for (let step = 0; step < 16; step++) {
        const angle = (step / 16) * 2 * Math.PI;
        await page.mouse.move(
            cx + radius * Math.sin(angle),
            cy - radius * Math.cos(angle),
            { steps: 2 },
        );

        for (const name of await page.locator('strong').allTextContents()) {
            tooltipNames.add(name);
        }
    }

    expect([...tooltipNames]).toEqual(
        expect.arrayContaining([EVIL_ENVELOPE, EVIL_GROUP]),
    );

    // No injected element, no executed handler.
    await expect(page.locator('img[src="x"], img[src="y"]')).toHaveCount(0);
    expect(
        await page.evaluate(
            () => (window as unknown as { __xss?: number }).__xss,
        ),
    ).toBeUndefined();
});
