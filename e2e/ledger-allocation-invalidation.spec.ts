import { expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

// The seed derives its budget month and transaction date from the server
// clock (UTC): pin the browser — and this file's month label — to UTC so the
// Ledger page opens on the month the seed filled.
test.use({ timezoneId: 'UTC' });

const monthLabel = (): string =>
    new Intl.DateTimeFormat('en', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date());

const foodRow = (page: Page) => page.locator('tbody tr', { hasText: 'Food' });

/** The "Remaining" cell of the Food row (Day, Envelope, Amount, Remaining). */
const foodRemaining = (page: Page) => foodRow(page).locator('td').nth(3);

/**
 * The ledger report refetch the budget mutation's invalidation must trigger.
 * When the Ledger page is re-entered the cached report is still inside the
 * 30 s stale window, so a GET transactions request firing at all is the
 * invalidation's doing — asserting the request itself keeps the tests
 * independent of how long the budget flow took.
 */
const expectLedgerRefetch = (page: Page) =>
    page.waitForRequest(
        (r) =>
            r.method() === 'GET' &&
            /\/api\/ledgers\/\d+\/transactions/.test(r.url()),
        { timeout: 10_000 },
    );

const gotoBudget = async (page: Page) => {
    await page.getByRole('link', { name: 'Budget', exact: true }).click();
    await expect(
        page.getByRole('combobox', { name: 'Select budget' }),
    ).toBeVisible();
};

/**
 * Primes the ledger transaction cache (30 s app-wide staleTime) with the
 * seeded month: Food shows the published allocation minus the transaction,
 * 800 − 120 = 680.
 */
test.beforeEach(async ({ page }) => {
    seed('seed-ledger-invalidation.php');
    await login(page);
    await page.goto('/ledger');
    await expect(foodRow(page)).toBeVisible();
    await expect(foodRemaining(page)).toContainText('$680');
});

test('publishing a changed allocation refreshes the cached ledger report', async ({
    page,
}) => {
    // Publish the alternative draft (Food 500) from the Budget page, then
    // come straight back to the Ledger within the stale window.
    await gotoBudget(page);
    await page.getByRole('combobox', { name: 'Select budget' }).click();
    await page.getByRole('option', { name: 'New Budget (draft)' }).click();
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Publish' }).click();

    const dialog = page.getByRole('dialog', {
        name: 'Publish this budget?',
    });
    await expect(dialog.getByRole('alert')).toContainText(
        `The budget already published for ${monthLabel()} will be replaced`,
    );
    await dialog.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByText('Budget published.')).toBeVisible();

    const refetch = expectLedgerRefetch(page);
    await page.getByRole('link', { name: 'Ledger', exact: true }).click();
    await refetch;

    // The cached report must not keep showing the replaced budget's 800:
    // the new published allocation gives 500 − 120 = 380.
    await expect(foodRemaining(page)).toContainText('$380');
});

test('deleting the published budget clears cached remaining allocations', async ({
    page,
}) => {
    await gotoBudget(page);
    await page.getByRole('combobox', { name: 'Select budget' }).click();
    await page.getByRole('option', { name: monthLabel() }).click();
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Delete budget' }).click();

    await page
        .getByRole('dialog', { name: 'Delete this budget?' })
        .getByRole('button', { name: 'Delete budget' })
        .click();
    await expect(page.getByText('Budget deleted.')).toBeVisible();

    const refetch = expectLedgerRefetch(page);
    await page.getByRole('link', { name: 'Ledger', exact: true }).click();
    await refetch;

    // No published budget remains: the cached report must not keep the
    // deleted allocation — the month reports as unallocated.
    await expect(foodRemaining(page)).toHaveText('—');
});
