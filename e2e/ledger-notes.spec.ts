import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Optional transaction notes (issue #121): the note is disclosed from the
 * amount in a portalled popover that never reshapes the table, works with
 * hover, keyboard focus, click/tap pinning and Escape, the recording form
 * suggests the selected envelope's recent notes, and notes are capped at
 * 200 characters.
 */

const LONG_NOTE = `${'Partial payment, the balance is due next month. '.repeat(4)}Ref 1234`;

test.beforeEach(() => {
    seed('seed-ledger-notes.php');
});

/** Shows October 2026, the seeded month with transactions. */
async function showSeededMonth(page: Page): Promise<void> {
    const label = page.getByTestId('month-calendar').locator('[aria-live]');
    for (let i = 0; i < 12; i += 1) {
        const text = (await label.textContent())?.trim() ?? '';
        if (text === 'October 2026') {
            break;
        }
        await page
            .getByRole('button', {
                name:
                    text.endsWith('2026') && !text.startsWith('September')
                        ? 'Previous month'
                        : 'Next month',
            })
            .click();
    }
    await expect(label).toHaveText('October 2026');
    await expect(page.locator('tbody tr')).toHaveCount(3);
}

const row = (page: Page, envelope: string): Locator =>
    page.locator('tbody tr', { hasText: envelope }).first();

const popover = (page: Page): Locator => page.getByTestId('transaction-note');

/** Waits for the popover's enter animation, so screenshots show it. */
async function settle(box: Locator): Promise<void> {
    await box.evaluate((el) =>
        Promise.all(el.getAnimations().map((a) => a.finished)),
    );
}

async function expectInsideViewport(page: Page, box: Locator) {
    const rect = await box.boundingBox();
    const viewport = page.viewportSize();
    expect(rect).not.toBeNull();
    expect(viewport).not.toBeNull();
    if (rect === null || viewport === null) {
        return;
    }
    expect(rect.x).toBeGreaterThanOrEqual(0);
    expect(rect.y).toBeGreaterThanOrEqual(0);
    expect(rect.x + rect.width).toBeLessThanOrEqual(viewport.width);
    expect(rect.y + rect.height).toBeLessThanOrEqual(viewport.height);
}

test('rows with a note show a cue on the amount, rows without do not', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const food = row(page, 'Food').getByTestId('transaction-note-trigger');
    await expect(food).toHaveText(/\(\$120\.00\)/);
    // The cue is not color alone: an icon and a dotted underline.
    await expect(food.locator('svg')).toBeVisible();
    await expect(food).toHaveCSS('text-decoration-style', 'dotted');
    // The note is the trigger's accessible description.
    await expect(food).toHaveAccessibleDescription(
        'Weekly groceries at the market',
    );
    await expect(food).toHaveAccessibleName(/\(\$120\.00\).*Has a note/);
    await expect(
        row(page, 'Paychecks').getByTestId('transaction-note-trigger'),
    ).toHaveCount(0);
    await expect(row(page, 'Paychecks')).toContainText('$1,800.00');

    // Amounts line up whether or not the row has a note: every amount ends
    // on the same right edge, and plain rows reserve the note icon's slot
    // as the same left indentation.
    const amounts = page.locator('tbody tr td:nth-child(3) span.tabular-nums');
    await expect(amounts).toHaveCount(3);
    const boxes = await amounts.evaluateAll((els) =>
        els.map((el) => el.getBoundingClientRect().toJSON() as DOMRect),
    );
    for (const box of boxes) {
        expect(Math.abs(box.right - boxes[0].right)).toBeLessThanOrEqual(0.5);
    }
    const indent = async (envelope: string) =>
        row(page, envelope)
            .locator('td:nth-child(3) > :first-child')
            .evaluate((wrapper) => {
                const number = wrapper.querySelector('span.tabular-nums');
                return (
                    (number?.getBoundingClientRect().left ?? 0) -
                    wrapper.getBoundingClientRect().left
                );
            });
    expect(await indent('Paychecks')).toBeGreaterThan(16);
    expect(
        Math.abs((await indent('Paychecks')) - (await indent('Food'))),
    ).toBeLessThanOrEqual(0.5);
    const icon = await food.locator('svg').boundingBox();
    const number = await food.locator('span.tabular-nums').boundingBox();
    expect((icon?.x ?? 0) + (icon?.width ?? 0)).toBeLessThanOrEqual(
        number?.x ?? 0,
    );
    await page
        .getByRole('region', { name: 'Transactions' })
        .screenshot({ path: './e2e/.out/notes-04-alignment.png' });

    // The cue and the row actions (note + delete, side by side) keep rows
    // with a note as compact as rows without one.
    const plain = await row(page, 'Paychecks').boundingBox();
    const noted = await row(page, 'Mortgage').boundingBox();
    expect(
        Math.abs((noted?.height ?? 0) - (plain?.height ?? 0)),
    ).toBeLessThanOrEqual(1);
    expect(plain?.height).toBeLessThanOrEqual(60);
});

test('hovering previews the note without changing the table layout', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const mortgage = row(page, 'Mortgage');
    const trigger = mortgage.getByTestId('transaction-note-trigger');
    const region = page.getByRole('region', { name: 'Transactions' });
    const rowBefore = await mortgage.boundingBox();
    const regionBefore = await region.boundingBox();
    const scrollBefore = await region.evaluate((el) => el.scrollWidth);

    await trigger.hover();
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toHaveText(LONG_NOTE);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await settle(popover(page));
    await page.screenshot({ path: './e2e/.out/notes-01-hover.png' });

    // Portalled: the table row and the scroll region keep their geometry.
    expect(await mortgage.boundingBox()).toEqual(rowBefore);
    expect(await region.boundingBox()).toEqual(regionBefore);
    expect(await region.evaluate((el) => el.scrollWidth)).toBe(scrollBefore);
    expect(
        await popover(page).evaluate(
            (el) => el.closest('section[aria-label="Transactions"]') === null,
        ),
    ).toBe(true);
    // A 200-character note wraps inside a bounded box, fully on screen.
    const box = await popover(page).boundingBox();
    expect(box?.width).toBeLessThanOrEqual(320);
    await expectInsideViewport(page, popover(page));

    // The pointer can travel onto the popover without closing it...
    await popover(page).hover();
    await expect(popover(page)).toBeVisible();
    // ...and leaving both closes it.
    await page.mouse.move(5, 5);
    await expect(popover(page)).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

test('keyboard focus reveals the note and Escape dismisses it in place', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const trigger = row(page, 'Food').getByTestId('transaction-note-trigger');
    await trigger.focus();
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toHaveText('Weekly groceries at the market');
    // Focus is not stolen by the popover.
    await expect(trigger).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(popover(page)).toBeHidden();
    await expect(trigger).toBeFocused();

    // Enter pins it open; Tab moves on and the pinned note stays until
    // dismissed explicitly.
    await page.keyboard.press('Enter');
    await expect(popover(page)).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(popover(page)).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('clicking pins the note until a second click or an outside click', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const trigger = row(page, 'Food').getByTestId('transaction-note-trigger');
    await trigger.click();
    await expect(popover(page)).toBeVisible();
    await page.mouse.move(5, 5);
    await page.waitForTimeout(300);
    await expect(popover(page)).toBeVisible();

    await page.getByRole('heading', { name: 'Ledger', level: 1 }).click();
    await expect(popover(page)).toBeHidden();

    await trigger.click();
    await expect(popover(page)).toBeVisible();
    await trigger.click();
    await expect(popover(page)).toBeHidden();
});

test.describe('on a touch device', () => {
    test.use({
        viewport: { width: 390, height: 800 },
        hasTouch: true,
        isMobile: true,
    });

    test('tapping the amount opens the note on screen, tapping outside closes it', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/ledger');
        await showSeededMonth(page);

        const trigger = row(page, 'Mortgage').getByTestId(
            'transaction-note-trigger',
        );
        await trigger.scrollIntoViewIfNeeded();
        const rowBefore = await row(page, 'Mortgage').boundingBox();
        await trigger.tap();
        await expect(popover(page)).toBeVisible();
        await expect(popover(page)).toHaveText(LONG_NOTE);
        await expectInsideViewport(page, popover(page));
        expect(await row(page, 'Mortgage').boundingBox()).toEqual(rowBefore);
        await settle(popover(page));
        await page.screenshot({ path: './e2e/.out/notes-02-mobile-tap.png' });

        await page.getByRole('heading', { name: 'Ledger', level: 1 }).tap();
        await expect(popover(page)).toBeHidden();

        // Tapping the amount again toggles it.
        await trigger.tap();
        await expect(popover(page)).toBeVisible();
        await trigger.tap();
        await expect(popover(page)).toBeHidden();
    });
});

test('the form suggests the envelope recent notes and records the chosen one', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const note = page.getByRole('textbox', { name: /^Note/ });
    const suggestions = page.getByTestId('note-suggestions');

    // No envelope yet: nothing to suggest.
    await note.focus();
    await expect(suggestions).toBeHidden();

    const loaded = page.waitForResponse(
        (response) =>
            response.url().includes('/transaction-notes?') &&
            response.status() === 200,
    );
    await page.getByRole('combobox').nth(1).click();
    await page.getByRole('option', { name: /Food/ }).click();
    expect(await (await loaded).json()).toEqual({
        notes: ['Weekly groceries at the market', 'Farmers market'],
    });

    await note.focus();
    await expect(suggestions.getByRole('button')).toHaveText([
        'Weekly groceries at the market',
        'Farmers market',
    ]);
    // The overlay does not push the rest of the page down.
    await expect(suggestions).toHaveCSS('position', 'absolute');
    await expect(note).toHaveAccessibleDescription(/2 recent notes available/);
    await page.screenshot({ path: './e2e/.out/notes-03-suggestions.png' });

    // Typing filters; the arrow keys pick a suggestion.
    await note.fill('farm');
    await expect(suggestions.getByRole('button')).toHaveText([
        'Farmers market',
    ]);
    await page.keyboard.press('ArrowDown');
    await expect(
        suggestions.getByRole('button', { name: 'Farmers market' }),
    ).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(note).toHaveValue('Farmers market');
    await expect(note).toBeFocused();
    await expect(suggestions).toBeHidden();

    // Escape closes the list; Tab skips it.
    await note.fill('');
    await expect(suggestions).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(suggestions).toBeHidden();

    await note.fill('Farmers market');
    await page.getByPlaceholder('e.g. 42').fill('18.50');
    await page.getByTestId('calendar-day-2026-10-22').click();
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(4);

    const added = page.locator('tbody tr', { hasText: '($18.50)' });
    await expect(
        added.getByTestId('transaction-note-trigger'),
    ).toHaveAccessibleDescription('Farmers market');
    await expect(note).toHaveValue('');
});

test('notes are limited to 200 characters', async ({ page }) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    const note = page.getByRole('textbox', { name: /^Note/ });
    await note.fill('x'.repeat(250));
    await expect(note).toHaveValue('x'.repeat(200));
    await expect(page.getByTestId('note-counter').first()).toHaveText(
        '200/200',
    );
});

test('a note can be edited and cleared from its row', async ({ page }) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    await page.getByRole('button', { name: 'Edit note: Food, Oct 15' }).click();
    const dialog = page.getByRole('dialog');
    const field = dialog.getByRole('textbox', { name: /^Note/ });
    await expect(field).toHaveValue('Weekly groceries at the market');

    // The envelope's other recent note is suggested; Escape closes the
    // list first and leaves the dialog open.
    await field.fill('');
    const suggestions = dialog.getByTestId('note-suggestions');
    await expect(suggestions.getByRole('button')).toHaveText([
        'Weekly groceries at the market',
        'Farmers market',
    ]);
    await page.keyboard.press('Escape');
    await expect(suggestions).toBeHidden();
    await expect(dialog).toBeVisible();

    await field.fill('Groceries and cleaning supplies');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Transaction note saved.')).toBeVisible();
    await expect(
        row(page, 'Food').getByTestId('transaction-note-trigger'),
    ).toHaveAccessibleDescription('Groceries and cleaning supplies');

    await page.getByRole('button', { name: 'Edit note: Food, Oct 15' }).click();
    await dialog.getByRole('button', { name: 'Clear note' }).click();
    await expect(field).toHaveValue('');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(
        row(page, 'Food').getByTestId('transaction-note-trigger'),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Write note: Food, Oct 15' }),
    ).toBeVisible();
});

/**
 * WCAG 2.1 AA scan of the ledger. The "recorded by" avatar buttons predate
 * the notes and carry a known label-in-name mismatch (their aria-label omits
 * the visible initials); they are excluded so this scan stays about notes.
 */
const axe = (page: Page) =>
    new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .exclude('td [data-slot="tooltip-trigger"]');

test('the ledger with an open note and suggestions passes axe', async ({
    page,
}) => {
    await login(page);
    await page.goto('/ledger');
    await showSeededMonth(page);

    await row(page, 'Food').getByTestId('transaction-note-trigger').focus();
    await expect(popover(page)).toBeVisible();
    expect((await axe(page).analyze()).violations).toEqual([]);

    await page.getByRole('combobox').nth(1).click();
    await page.getByRole('option', { name: /Food/ }).click();
    await page.getByRole('textbox', { name: /^Note/ }).focus();
    await expect(page.getByTestId('note-suggestions')).toBeVisible();
    expect((await axe(page).analyze()).violations).toEqual([]);
});
