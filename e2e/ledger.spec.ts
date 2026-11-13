import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

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
        // The menu is still playing its closing animation; wait for it to
        // fully unmount before reopening, or the next click races the
        // detach and never stabilizes.
        await expect(menu).toBeHidden();
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

    // The calendar points to the most recent day of the ledger's period:
    // today while it is open (2026-01-01..2026-12-31), otherwise a clamped
    // bound (issue #2).
    const isoOf = (d: Date) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const todayIso = isoOf(new Date());
    const expectedDay =
        todayIso < '2026-01-01'
            ? '2026-01-01'
            : todayIso > '2026-12-31'
              ? '2026-12-31'
              : todayIso;
    await expect(page.getByTestId(`calendar-day-${expectedDay}`)).toHaveClass(
        /bg-primary/,
    );

    const prevBtn = page.getByRole('button', { name: 'Previous month' });
    const nextBtn = page.getByRole('button', { name: 'Next month' });

    // The calendar opens on the most recent in-period month, which moves as
    // time passes; navigate explicitly to the month holding the seeded
    // transactions (October 2026).
    const monthDiff = (fromIso: string, toIso: string): number => {
        const [fy, fm] = fromIso.split('-').map(Number);
        const [ty, tm] = toIso.split('-').map(Number);
        return (ty - fy) * 12 + (tm - fm);
    };
    const steps = monthDiff(todayIso, '2026-10-22');
    const navButton = steps <= 0 ? prevBtn : nextBtn;
    for (let i = 0; i < Math.abs(steps); i += 1) {
        await navButton.click();
    }

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
    await addedRow
        .getByRole('button', { name: /^Delete transaction: Food, Oct 22$/ })
        .click();
    // Deleting is irreversible → a confirmation names the entry first.
    const confirmDelete = page.getByRole('dialog');
    await expect(confirmDelete).toContainText('Food · October 22, 2026 · $35');
    await confirmDelete
        .getByRole('button', { name: 'Delete transaction' })
        .click();
    await expect(page.getByText('Transaction deleted.')).toBeVisible();
    await expect(confirmDelete).toBeHidden();
    // The deleted row's button is gone: focus lands on the table, not <body>.
    await expect(
        page.getByRole('region', { name: 'Transactions' }),
    ).toBeFocused();
    await expect(newRows).toHaveCount(3);
    await expect(foodRow).toContainText('680');

    // --- month navigation -----------------------------------------------------
    await prevBtn.click();
    const sepRows = page.locator('tbody tr');
    await expect(sepRows.first().getByText('Sep 10')).toBeVisible();
    await expect(sepRows).toHaveCount(1);
    console.log(
        'SEPTEMBER ROW:',
        (await sepRows.first().textContent())?.replace(/\s+/g, ' ').trim(),
    );
    await page.screenshot({ path: SHOT('04-month-september'), fullPage: true });

    await nextBtn.click();
    await expect(page.locator('tbody tr')).toHaveCount(3);

    // --- calendar bounds -------------------------------------------------------
    // The seeded ledger spans 2026-01-01..2026-12-31: navigation is disabled
    // outside of the period and out-of-period days cannot be selected.
    for (let i = 0; i < 9; i += 1) {
        await prevBtn.click();
    }
    await expect(prevBtn).toBeDisabled();
    await expect(nextBtn).toBeEnabled();
    await expect(page.getByTestId('calendar-day-2025-12-31')).toBeDisabled();
    await expect(page.getByTestId('calendar-day-2026-01-01')).toBeEnabled();

    for (let i = 0; i < 11; i += 1) {
        await nextBtn.click();
    }
    await expect(nextBtn).toBeDisabled();
    await expect(prevBtn).toBeEnabled();
    await expect(page.getByTestId('calendar-day-2026-12-31')).toBeEnabled();
    await expect(page.getByTestId('calendar-day-2027-01-01')).toBeDisabled();

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
    const currentYear = new Date().getFullYear();
    const currentMonth = new Intl.DateTimeFormat('en-US', {
        month: 'long',
    }).format(new Date());
    const defaults = {
        name: await page.getByLabel('Name').inputValue(),
        startMonth: await page
            .getByRole('combobox', { name: 'Month' })
            .nth(0)
            .textContent(),
        startYear: await page
            .getByRole('combobox', { name: 'Year' })
            .nth(0)
            .textContent(),
        endMonth: await page
            .getByRole('combobox', { name: 'Month' })
            .nth(1)
            .textContent(),
        endYear: await page
            .getByRole('combobox', { name: 'Year' })
            .nth(1)
            .textContent(),
    };
    console.log('CREATE DEFAULTS:', JSON.stringify(defaults));

    const startYear = page.getByRole('combobox', { name: 'Year' }).nth(0);
    await startYear.click();
    await expect(
        page.getByRole('option', {
            name: String(currentYear - 7),
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('option', {
            name: String(currentYear + 3),
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('option', {
            name: String(currentYear - 8),
            exact: true,
        }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('option', {
            name: String(currentYear + 4),
            exact: true,
        }),
    ).toHaveCount(0);
    await page
        .getByRole('option', { name: String(currentYear + 1), exact: true })
        .click();
    await expect(
        page.getByRole('combobox', { name: 'Year' }).nth(1),
    ).toContainText(String(currentYear + 1));
    const endYear = page.getByRole('combobox', { name: 'Year' }).nth(1);
    await endYear.click();
    await page
        .getByRole('option', { name: String(currentYear - 1), exact: true })
        .click();
    await expect(startYear).toContainText(String(currentYear - 1));
    await startYear.click();
    await page
        .getByRole('option', { name: String(currentYear), exact: true })
        .click();
    await expect(endYear).toContainText(String(currentYear));

    const endMonth = page.getByRole('combobox', { name: 'Month' }).nth(1);
    await endMonth.click();
    await page.getByRole('option', { name: 'December', exact: true }).click();

    expect(defaults.name).toBe(String(currentYear));
    expect(defaults.startMonth?.trim()).toBe(currentMonth);
    expect(defaults.startYear?.trim()).toBe(String(currentYear));
    expect(defaults.endMonth?.trim()).toBe('December');
    expect(defaults.endYear?.trim()).toBe(String(currentYear));

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
