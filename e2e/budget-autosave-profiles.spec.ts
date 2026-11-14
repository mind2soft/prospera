import { expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Budget autosave across budgets (issue #79): envelopes belong to the user,
 * so the same envelope can be assigned to drafts in two profiles. Every
 * pending edit must be scoped to its (budget, envelope) identity — an edit
 * in one draft must never be cancelled, redirected or displayed by another
 * draft that carries the same envelope.
 */

const PATCH_URL = /\/api\/budgets\/(\d+)\/envelopes\/\d+$/;

type Save = { budgetId: number; amount: number };

/** Records every amount-save PATCH without holding it. */
async function recordSaves(page: Page): Promise<Save[]> {
    const saves: Save[] = [];
    await page.route(PATCH_URL, async (route) => {
        const request = route.request();
        if (request.method() !== 'PATCH') {
            return route.fallback();
        }
        saves.push({
            budgetId: Number(PATCH_URL.exec(request.url())?.[1]),
            amount: (request.postDataJSON() as { amount: number }).amount,
        });

        return route.continue();
    });

    return saves;
}

async function switchProfile(page: Page, name: RegExp): Promise<void> {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.getByRole('menuitem', { name: 'Profiles' }).click();
    const menu = page.getByRole('menu', { name: 'Profiles' });
    await menu.getByRole('menuitemradio', { name }).click();
}

const foodInput = (page: Page) =>
    page.getByRole('spinbutton', { name: 'Food amount' });

test.beforeEach(() => {
    seed('seed-budget-profiles.php');
});

test('editing the same envelope in two profiles saves both drafts', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    const table = page.getByRole('region', { name: 'Budget envelopes' });
    await expect(table).toBeVisible();

    // Cache both drafts first: the mix-up needs both budgets available
    // without a loading screen between them.
    await switchProfile(page, /Family/);
    await expect(table).toBeVisible();
    await switchProfile(page, /Default profile/);
    await expect(table).toBeVisible();

    const saves = await recordSaves(page);

    // Edit the default draft's Food, then leave it within the autosave
    // debounce: its pending edit must survive the switch.
    await foodInput(page).fill('100');
    await switchProfile(page, /Family/);

    // The family draft's own amount shows — never the text typed for the
    // other draft of the same envelope.
    await expect(foodInput(page)).toHaveValue('500');
    await foodInput(page).fill('200');
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'All changes saved',
    );

    // Both drafts' edits went out — the default draft's edit was not
    // cancelled by the family draft's.
    expect(saves.map((save) => save.amount).sort((a, b) => a - b)).toEqual([
        100, 200,
    ]);
    expect(new Set(saves.map((save) => save.budgetId)).size).toBe(2);

    // Each draft kept its own amount on the server.
    await page.reload();
    await expect(foodInput(page)).toHaveValue('200');
    await switchProfile(page, /Default profile/);
    await expect(foodInput(page)).toHaveValue('100');
});

test('an input never shows another budget draft unsaved text', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    const table = page.getByRole('region', { name: 'Budget envelopes' });
    await expect(table).toBeVisible();
    await switchProfile(page, /Family/);
    await expect(table).toBeVisible();
    await switchProfile(page, /Default profile/);
    await expect(table).toBeVisible();

    // An invalid edit is kept in the input only (it is not queued and the
    // budget's cached amount is untouched): it must not follow the user
    // into the other draft of the same envelope.
    await foodInput(page).fill('0');
    await switchProfile(page, /Family/);
    await expect(foodInput(page)).toHaveValue('500');
    await switchProfile(page, /Default profile/);
    await expect(foodInput(page)).toHaveValue('800');

    // A valid edit stays with its own budget: the family draft shows its
    // own amount, and the edit is still there when the user comes back.
    await foodInput(page).fill('123');
    await switchProfile(page, /Family/);
    await expect(foodInput(page)).toHaveValue('500');
    await switchProfile(page, /Default profile/);
    await expect(foodInput(page)).toHaveValue('123');
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'All changes saved',
    );
});

test('leaving the page saves a pending edit of a budget already left', async ({
    page,
}) => {
    // The fake clock keeps the debounced save from firing on its own: only
    // the flush on leaving the page can send it.
    await page.clock.install();
    await login(page);
    await page.goto('/budget');
    const table = page.getByRole('region', { name: 'Budget envelopes' });
    await expect(table).toBeVisible();
    await switchProfile(page, /Family/);
    await expect(table).toBeVisible();
    await switchProfile(page, /Default profile/);
    await expect(table).toBeVisible();

    // Keepalive requests dispatched while the page unloads are not always
    // visible to request interception, so the save is asserted through its
    // persisted effect instead.
    await page.clock.pauseAt(Date.now() + 60_000);
    await foodInput(page).fill('100');
    await switchProfile(page, /Family/);
    await expect(foodInput(page)).toHaveValue('500');

    // Reload while the default draft's edit is still debounced: the
    // pagehide flush must send it, although that budget is no longer
    // selected.
    await page.reload();
    await page.clock.resume();
    await expect(foodInput(page)).toHaveValue('500');

    // The flushed amount persisted on the server.
    await switchProfile(page, /Default profile/);
    await expect(foodInput(page)).toHaveValue('100');
});
