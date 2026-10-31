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
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

async function openActionMenu(page: Page): Promise<void> {
    const menu = page.getByRole('menu');
    if (await menu.isVisible().catch(() => false)) {
        return;
    }
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await expect(menu).toBeVisible();
}

test('budget page audit', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error)));

    await login(page);
    await page.goto('/budget');
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    // --- selector row ------------------------------------------------------
    await page.screenshot({ path: SHOT('01-budget-draft'), fullPage: true });

    const selector = page.getByRole('combobox');
    const selectorText = await selector.textContent();
    console.log('SELECTOR:', selectorText?.trim());

    // action menu contents
    await openActionMenu(page);
    const menuItems = page.getByRole('menuitem');
    console.log('MENU (draft):', await menuItems.allTextContents());
    const deleteItem = page.getByRole('menuitem', { name: 'Delete' });
    console.log(
        'DELETE data-variant:',
        await deleteItem.getAttribute('data-variant'),
    );
    await page.keyboard.press('Escape');
    // Wait for the menu's close animation: focus returns to the trigger after
    // it finishes, which would otherwise steal it from the amount input below.
    await page.waitForTimeout(300);

    // --- bars: ordering & labels -------------------------------------------
    const barLabels = await page
        .locator("section:has(h3:text('Expenses')) li")
        .allTextContents();
    console.log('EXPENSE BAR LABELS:', barLabels);

    const barSegments = await page
        .locator("section:has(h3:text('Expenses')) .h-3 > div")
        .evaluateAll((nodes) =>
            nodes.map((n) => (n as HTMLElement).offsetWidth),
        );
    console.log('EXPENSE SEGMENT WIDTHS (px, largest→smallest):', barSegments);

    // --- envelope table -----------------------------------------------------
    const rows = page.locator('tbody tr');
    const rowCount = await rows.count();
    const tableData: string[][] = [];
    for (let i = 0; i < rowCount; i += 1) {
        tableData.push(
            (await rows.nth(i).textContent())?.trim().split(/\s+/) ?? [],
        );
    }
    console.log('TABLE ROWS:', JSON.stringify(tableData, null, 1));

    const variableNote = await page
        .getByText('Variable amounts are expected values')
        .isVisible();
    console.log('VARIABLE NOTE VISIBLE:', variableNote);

    // footer
    const footer = page.locator('tfoot');
    console.log(
        'FOOTER:',
        (await footer.textContent())?.replace(/\s+/g, ' ').trim(),
    );

    // editable amount?
    const amountInput = page.locator("tbody input[type='number']").first();
    console.log('AMOUNT EDITABLE (draft):', await amountInput.isVisible());

    // --- change an amount in draft mode (live, before any blur) -------------
    await amountInput.fill('');
    await amountInput.pressSequentially('4000', { delay: 60 });
    await expect(footer).toContainText('$2,570', { timeout: 10_000 });
    const footerText = (await footer.textContent())
        ?.replace(/\s+/g, ' ')
        .trim();
    console.log('FOOTER AFTER LIVE EDIT:', footerText);
    await amountInput.press('Enter');
    await page.screenshot({ path: SHOT('01b-amount-edited'), fullPage: true });

    // overspend -> accounting format + destructive color
    await amountInput.fill('100');
    await expect(footer).toContainText('($1,330)', { timeout: 10_000 });
    console.log(
        'ROW LEFT-TO-SPEND (overspend):',
        await page.getByText('($200)').isVisible(),
    );
    console.log(
        'FOOTER DESTRUCTIVE:',
        await footer.locator('.text-destructive').count(),
    );
    await page.screenshot({ path: SHOT('01c-overspend'), fullPage: true });

    // restore for the publish flow
    await amountInput.fill('4000');
    await expect(footer).toContainText('$2,570', { timeout: 10_000 });

    // --- publish flow -------------------------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Publish' }).click();
    await page.screenshot({ path: SHOT('02-publish-confirm') });
    const publishDialog = await page
        .getByText('Publish this budget?')
        .isVisible();
    console.log('PUBLISH CONFIRM:', publishDialog);
    await page.getByRole('button', { name: 'Publish' }).last().click();
    await expect(page.getByText('Budget published.')).toBeVisible();
    console.log(
        'SELECTOR AFTER PUBLISH:',
        (await page.getByRole('combobox').textContent())?.trim(),
    );
    await page.screenshot({ path: SHOT('03-published'), fullPage: true });
    console.log('MENU (published):');
    await openActionMenu(page);
    console.log(await page.getByRole('menuitem').allTextContents());
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // amount no longer editable when published?
    console.log(
        'AMOUNT EDITABLE (published):',
        await page.locator("tbody input[type='number']").first().isVisible(),
    );

    // --- new budget (clone) flow --------------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'New Budget' }).click();
    await page.screenshot({ path: SHOT('04-clone-confirm') });
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByText('Draft budget created.')).toBeVisible();
    await page.screenshot({ path: SHOT('05-cloned-draft'), fullPage: true });

    // --- share flow ----------------------------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Share' }).click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: SHOT('06-share-drawer') });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // --- delete flow: delete clone draft -------------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.screenshot({ path: SHOT('07-delete-confirm') });
    const deleteDialog = await page
        .getByText('Delete this budget?')
        .isVisible();
    const deleteVariant = await page
        .getByRole('button', { name: 'Delete budget' })
        .getAttribute('class');
    console.log('DELETE CONFIRM:', deleteDialog, 'BTN CLASS:', deleteVariant);
    await page.getByRole('button', { name: 'Delete budget' }).click();
    await expect(page.getByText('Budget deleted.')).toBeVisible();
    await page.screenshot({ path: SHOT('08-after-delete'), fullPage: true });

    // --- delete remaining budget -> empty state ------------------------------
    await openActionMenu(page);
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Delete budget' }).click();
    await expect(page.getByText('Budget deleted.')).toBeVisible();
    await page.screenshot({ path: SHOT('09-empty-state'), fullPage: true });
    console.log(
        'EMPTY STATE:',
        await page.getByText('Create your first budget').isVisible(),
    );
    console.log(
        'SELECTOR VISIBLE (empty):',
        await page.getByRole('combobox').isVisible(),
    );

    // --- recreate from empty state -------------------------------------------
    await page.getByRole('button', { name: 'Create budget' }).click();
    await page.screenshot({ path: SHOT('10-create-dialog') });
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByText('Draft budget created.')).toBeVisible();
    await page.screenshot({ path: SHOT('11-recreated-draft'), fullPage: true });

    console.log('CONSOLE ERRORS:', JSON.stringify(consoleErrors, null, 1));
});
