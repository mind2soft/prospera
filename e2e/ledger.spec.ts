import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

const SHOT = (name: string) => `./e2e/.out/${name}.png`;

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
    execSync('npm run e2e:seed -- seed-ledger.php', { cwd: '.' });
});

async function openActionMenu(page: Page): Promise<void> {
    const menu = page.getByRole('menu');
    if (await menu.isVisible().catch(() => false)) {
        return;
    }
    await page.getByRole('button', { name: 'Ledger actions' }).click();
    await expect(menu).toBeVisible();
}

test('ledger page audit', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error)));

    await login(page);
    await page.goto('/ledger');
    await expect(page.getByText('Food').first()).toBeVisible();

    // --- selector row -------------------------------------------------------
    await page.screenshot({ path: SHOT('01-ledger-open'), fullPage: true });

    const selected = page.getByTestId('selected-ledger');
    const selectedText = await selected.textContent();
    console.log('SELECTOR:', selectedText?.trim());

    await openActionMenu(page);
    console.log(
        'MENU (open):',
        await page.getByRole('menuitem').allTextContents(),
    );
    await page.keyboard.press('Escape');

    // --- transactions table (October 2026, seeded) --------------------------
    const rows = page.locator('tbody tr');
    await expect(rows).toHaveCount(3);
    for (let i = 0; i < 3; i += 1) {
        console.log(
            `ROW ${i}:`,
            (await rows.nth(i).textContent())?.replace(/\s+/g, ' ').trim(),
        );
    }
    await page.screenshot({ path: SHOT('02-ledger-table'), fullPage: true });

    // remaining column: Paychecks 200, Food 680, Mortgage 50
    const remaining = await page
        .locator('tbody tr td:nth-child(4)')
        .allTextContents();
    console.log('REMAINING:', remaining);

    // --- add a transaction ---------------------------------------------------
    const dateBefore = await page.getByTestId('event-date').textContent();
    console.log('EVENT DATE DEFAULT:', dateBefore?.trim());

    await page.getByRole('combobox').nth(1).click();
    await page.getByRole('option', { name: /Food/ }).click();
    await page.getByPlaceholder('e.g. 42').fill('35');
    await page.getByTestId('calendar-day-2026-10-22').click();
    await page.getByRole('button', { name: 'Add' }).click();

    const newRows = page.locator('tbody tr');
    await expect(newRows).toHaveCount(4);
    const foodRow = page.locator('tbody tr', { hasText: 'Food' }).first();
    await expect(foodRow).toContainText('645'); // 680 - 35
    await page.screenshot({
        path: SHOT('03-transaction-added'),
        fullPage: true,
    });
    console.log('AFTER ADD ROWS:', await newRows.count());

    // --- delete the added transaction ----------------------------------------
    const addedRow = page.locator('tbody tr', { hasText: '($35)' }).first();
    await addedRow.getByRole('button', { name: 'Remove transaction' }).click();
    await expect(newRows).toHaveCount(3);
    await expect(foodRow).toContainText('680');

    // --- month navigation -----------------------------------------------------
    await page.getByRole('button', { name: 'Previous month' }).click();
    const sepRows = page.locator('tbody tr');
    await expect(sepRows.first().getByText('Sep 10')).toBeVisible();
    await expect(sepRows).toHaveCount(1);
    console.log(
        'SEPTEMBER ROW:',
        (await sepRows.first().textContent())?.replace(/\s+/g, ' ').trim(),
    );
    await page.screenshot({ path: SHOT('04-month-september'), fullPage: true });

    await page.getByRole('button', { name: 'Next month' }).click();
    await expect(page.locator('tbody tr')).toHaveCount(3);

    // --- close / reopen --------------------------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Close' }).click();
    await expect(
        page.getByText('This ledger is closed and read-only'),
    ).toBeVisible();
    await expect(page.getByTestId('selected-ledger')).toContainText('Closed');
    await expect(
        page.locator(
            "tbody input, tbody button[aria-label='Remove transaction']",
        ),
    ).toHaveCount(0);
    await page.screenshot({ path: SHOT('05-ledger-closed'), fullPage: true });

    await openActionMenu(page);
    console.log(
        'MENU (closed):',
        await page.getByRole('menuitem').allTextContents(),
    );
    await page.getByRole('menuitem', { name: 'Reopen' }).click();
    await expect(
        page.getByText('This ledger is closed and read-only'),
    ).toBeHidden();
    await expect(page.getByTestId('selected-ledger')).toContainText('Open');

    // --- delete flow ------------------------------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.screenshot({ path: SHOT('06-ledger-delete-confirm') });
    await expect(page.getByText('Delete this ledger?')).toBeVisible();
    await expect(
        page.getByText(/removes the ledger and ALL of its transactions/),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();

    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Delete ledger' }).click();
    await expect(page.getByText('Create your first ledger')).toBeVisible();
    await page.screenshot({
        path: SHOT('07-ledger-empty-state'),
        fullPage: true,
    });

    // --- create a new ledger from the empty state --------------------------------
    await page.getByRole('button', { name: 'Create ledger' }).click();
    await page.screenshot({ path: SHOT('08-create-ledger-dialog') });
    await expect(page.getByRole('dialog')).toBeVisible();
    const defaults = {
        name: await page.getByLabel('Name').inputValue(),
        start: await page.getByLabel('Starting date').inputValue(),
        end: await page.getByLabel('End date').inputValue(),
    };
    console.log('CREATE DEFAULTS:', JSON.stringify(defaults));
    await page.getByLabel('Starting balance').fill('2500');
    await page.getByRole('button', { name: 'Create ledger' }).click();
    await expect(page.getByText('Ledger created.')).toBeVisible();
    await expect(page.getByTestId('selected-ledger')).toContainText('2026');
    await expect(page.getByText('New transaction')).toBeVisible();
    await page.screenshot({
        path: SHOT('09-recreated-ledger'),
        fullPage: true,
    });

    console.log('CONSOLE ERRORS:', JSON.stringify(consoleErrors));
    expect(consoleErrors).toEqual([]);
});
