import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeAll(() => {
    seed('seed-profiles.php');
});

test('profile cards are disclosures with separate, reachable actions', async ({
    page,
}) => {
    await login(page);
    await page.goto('/settings/profiles');

    const disclosures = page.locator('[data-test="profile-disclosure"]');
    await expect(disclosures).toHaveCount(2);

    // No interactive content nested inside the disclosure button.
    const results = await new AxeBuilder({ page })
        .withRules(['nested-interactive'])
        .analyze();
    expect(results.violations).toEqual([]);

    const family = disclosures.filter({ hasText: 'Family' });
    await expect(family).toHaveAttribute('aria-expanded', 'false');
    await family.focus();
    await page.keyboard.press('Enter');
    await expect(family).toHaveAttribute('aria-expanded', 'true');
    const panel = page.locator(
        `#${await family.getAttribute('aria-controls')}`,
    );
    await expect(panel).toBeVisible();

    // The actions next to the disclosure are their own tab stops.
    const own = disclosures.filter({ hasText: 'Default profile' });
    await own.focus();
    await page.keyboard.press('Tab');
    await expect(
        page.getByRole('button', { name: 'Rename profile' }),
    ).toBeFocused();
    await page.keyboard.press('Enter');
    const rename = page.getByRole('textbox', { name: 'Rename profile' });
    await expect(rename).toBeFocused();
    await rename.fill('Household');
    await rename.press('Enter');
    await expect(disclosures.filter({ hasText: 'Household' })).toBeVisible();
});
