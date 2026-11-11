import { expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeEach(() => {
    seed('seed-dashboard.php');
});

const cards = (page: Page) => page.locator('[data-widget-card]');

async function addWidget(page: Page, name: string, count: number) {
    const trigger = page.getByRole('button', { name: 'Add widget' });
    if ((await trigger.count()) === 0) {
        await page.getByRole('button', { name: 'Add a widget' }).click();
    } else {
        await trigger.click();
    }
    // The item closes the menu as it adds the widget (see dashboard.spec).
    await page.getByRole('menuitem', { name }).click({ force: true });
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(cards(page)).toHaveCount(count, { timeout: 15_000 });
}

/** Waits for the debounced/immediate layout save to land. */
const layoutSaved = (page: Page) =>
    page.waitForResponse(
        (r) =>
            r.request().method() === 'PUT' &&
            /\/api\/dashboard\/\d+\/widgets$/.test(r.url()) &&
            r.ok(),
    );

test('widgets can be reordered from the keyboard, with an announcement', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    let saved = layoutSaved(page);
    await addWidget(page, 'Envelope spending', 1);
    await addWidget(page, 'Radial', 2);
    await saved;

    const handle = cards(page)
        .last()
        .getByRole('button', { name: 'Reorder Radial' });
    await expect(handle).toHaveAccessibleDescription(/arrow keys/);

    saved = layoutSaved(page);
    await handle.focus();
    await page.keyboard.press('ArrowLeft');
    await expect(
        cards(page).first().getByRole('heading', { name: 'Radial' }),
    ).toBeVisible();
    await expect(page.getByTestId('reorder-announcement')).toHaveText(
        'Radial moved to position 1 of 2.',
    );
    // Focus follows the moved widget.
    await expect(
        cards(page).first().getByRole('button', { name: 'Reorder Radial' }),
    ).toBeFocused();
    await saved;

    await page.reload();
    await expect(
        cards(page).first().getByRole('heading', { name: 'Radial' }),
    ).toBeVisible({ timeout: 15_000 });
});

test('the widget settings offer move earlier/later (single pointer)', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    let saved = layoutSaved(page);
    await addWidget(page, 'Envelope spending', 1);
    await addWidget(page, 'Radial', 2);
    await saved;

    await cards(page)
        .first()
        .getByRole('button', { name: 'Widget settings' })
        .click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByTestId('widget-position')).toHaveText(
        'Position 1 of 2',
    );
    await expect(
        sheet.getByRole('button', { name: 'Move earlier' }),
    ).toBeDisabled();

    saved = layoutSaved(page);
    await sheet.getByRole('button', { name: 'Move later' }).click();
    await expect(sheet.getByTestId('widget-position')).toHaveText(
        'Position 2 of 2',
    );
    await saved;
    await sheet.getByRole('button', { name: 'Done' }).click();
    await expect(sheet).toBeHidden();
    await expect(
        cards(page).last().getByRole('heading', { name: 'Envelope spending' }),
    ).toBeVisible();
});

test('widget data requests carry the browser time zone', async ({
    browser,
}) => {
    const context = await browser.newContext({ timezoneId: 'Asia/Tokyo' });
    const page = await context.newPage();
    await login(page);
    await page.goto('/dashboard');

    const request = page.waitForRequest((r) =>
        /\/widgets\/\d+\/data/.test(r.url()),
    );
    const saved = layoutSaved(page);
    await addWidget(page, 'Envelope spending', 1);
    await saved;
    expect(new URL((await request).url()).searchParams.get('timezone')).toBe(
        'Asia/Tokyo',
    );
    await context.close();
});

test('charts have a text equivalent with their amounts', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');
    let saved = layoutSaved(page);
    await addWidget(page, 'Income & expenses history', 1);
    await saved;

    const card = cards(page).first();
    await card.getByRole('button', { name: 'Widget settings' }).click();
    const sheet = page.getByRole('dialog');
    saved = layoutSaved(page);
    await sheet.getByRole('combobox', { name: 'Add envelope' }).first().click();
    await page.getByRole('option', { name: 'Paychecks' }).click();
    await saved;
    await sheet.getByRole('button', { name: 'Done' }).click();
    await expect(sheet).toBeHidden();

    // The data table behind "Show data" lists each bucket's amounts.
    const data = card.getByTestId('history-data');
    await data.getByText('Show data').click();
    const table = data.getByRole('table');
    await expect(
        table.getByRole('columnheader', { name: 'Revenue' }),
    ).toBeVisible();
    await expect(table.getByRole('row')).not.toHaveCount(1);
    await expect(table).toContainText('$');
});

test('the radial legend lists each amount next to its share', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    let saved = layoutSaved(page);
    await addWidget(page, 'Radial', 1);
    await saved;

    const card = cards(page).first();
    await card.getByRole('button', { name: 'Widget settings' }).click();
    const sheet = page.getByRole('dialog');
    for (const name of ['Paychecks', 'Food']) {
        saved = layoutSaved(page);
        await sheet
            .getByRole('combobox', { name: 'Add envelope' })
            .first()
            .click();
        await page.getByRole('option', { name }).click();
        await saved;
    }
    await sheet.getByRole('button', { name: 'Done' }).click();
    await expect(sheet).toBeHidden();

    await expect(
        card.getByRole('listitem').filter({ hasText: 'Food' }),
    ).toHaveText(/Food\s*\$[\d,]+\s*·\s*[\d.]+%/, { timeout: 15_000 });
});
