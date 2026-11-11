import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Light-theme contrast of quiet controls axe cannot measure (icons are
 * non-text content, WCAG 1.4.11 → 3:1) and of the dashboard's quiet text
 * (UX-R2-5), plus the appearance switch's selected state (UX-R2-6).
 */

test.use({ colorScheme: 'light' });

/**
 * Contrast ratio of an element's text/icon color against the nearest
 * opaque background, both resolved to sRGB by painting them on a canvas
 * (the computed values are oklch/color-mix, translucent ones included).
 */
async function contrastOf(locator: Locator): Promise<number> {
    return locator.evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });

        if (context === null) {
            throw new Error('No 2D context');
        }

        const paint = (colors: string[]): number[] => {
            context.clearRect(0, 0, 1, 1);
            for (const color of colors) {
                context.fillStyle = color;
                context.fillRect(0, 0, 1, 1);
            }

            return Array.from(context.getImageData(0, 0, 1, 1).data);
        };

        let backdrop: Element | null = element;
        let background = 'rgb(255, 255, 255)';
        while (backdrop !== null) {
            const color = getComputedStyle(backdrop).backgroundColor;
            if (paint([color])[3] === 255) {
                background = color;
                break;
            }
            backdrop = backdrop.parentElement;
        }

        const luminance = ([r, g, b]: number[]): number => {
            const [R, G, B] = [r, g, b].map((v) => {
                const c = v / 255;
                return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * R + 0.7152 * G + 0.0722 * B;
        };

        const fg = luminance(
            paint([background, getComputedStyle(element).color]),
        );
        const bg = luminance(paint([background]));

        return (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);
    });
}

async function addSpendingWidget(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Add a widget' }).click();
    await page
        .getByRole('menuitem', { name: 'Envelope spending' })
        .click({ force: true });
    await page
        .locator('[data-widget-card]')
        .getByRole('button', { name: 'Widget settings' })
        .click();
    await page.getByRole('combobox', { name: 'Add envelope' }).first().click();
    // Not allocated on any budget → "No budget covers this period".
    await page.getByRole('option', { name: 'Fun money' }).click();
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
}

test('dashboard widget controls and quiet text meet contrast in light mode', async ({
    page,
}) => {
    seed('seed-dashboard.php');
    await login(page);
    await page.goto('/dashboard');
    await addSpendingWidget(page);

    const card = page.locator('[data-widget-card]').first();
    const noBudget = card.getByText('No budget covers this period');
    await expect(noBudget).toBeVisible({ timeout: 15_000 });

    // Icons: ≥ 3:1 (the handle is the keyboard reorder control).
    const handle = card.getByRole('button', { name: /^Reorder / });
    expect(await contrastOf(handle)).toBeGreaterThanOrEqual(3);
    const settings = card.getByRole('button', { name: 'Widget settings' });
    await card.hover();
    expect(await contrastOf(settings)).toBeGreaterThanOrEqual(3);

    // Text: ≥ 4.5:1 (measured here and by axe).
    expect(await contrastOf(noBudget)).toBeGreaterThanOrEqual(4.5);
    const results = await new AxeBuilder({ page })
        .include('[data-widget-card]')
        .withRules(['color-contrast'])
        .analyze();
    expect(results.violations).toEqual([]);
});

test('the appearance switch exposes the selected theme', async ({ page }) => {
    seed('seed.php');
    await login(page);
    await page.goto('/settings/appearance');

    const group = page.getByRole('radiogroup', { name: 'Theme' });
    const light = group.getByRole('radio', { name: 'Light' });
    const dark = group.getByRole('radio', { name: 'Dark' });
    const system = group.getByRole('radio', { name: 'System' });
    await expect(system).toBeChecked();

    // Unselected options keep readable text contrast.
    expect(await contrastOf(light)).toBeGreaterThanOrEqual(4.5);

    await dark.click();
    await expect(dark).toBeChecked();
    await expect(system).not.toBeChecked();
    await expect(page.locator('html')).toHaveClass(/dark/);

    // Keyboard: arrow keys move between the options, Space selects.
    await dark.press('ArrowLeft');
    await expect(light).toBeFocused();
    await page.keyboard.press('Space');
    await expect(light).toBeChecked();
    await expect(page.locator('html')).not.toHaveClass(/dark/);

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(results.violations).toEqual([]);

    // Leave the suite's default (system) theme.
    await system.click();
    await expect(system).toBeChecked();
});
