import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Expand a profile's disclosure in Settings → Profiles (the current profile
 * starts expanded) and return its panel.
 */
export async function openProfilePanel(
    page: Page,
    name: string,
): Promise<Locator> {
    const disclosure = page
        .locator('[data-test="profile-disclosure"]')
        .filter({ hasText: name });
    await expect(disclosure).toBeVisible();

    if ((await disclosure.getAttribute('aria-expanded')) !== 'true') {
        await disclosure.click();
    }

    await expect(disclosure).toHaveAttribute('aria-expanded', 'true');

    return page.locator(`#${await disclosure.getAttribute('aria-controls')}`);
}
