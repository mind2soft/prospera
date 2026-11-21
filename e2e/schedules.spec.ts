import { expect, type Page, test } from '@playwright/test';
import { t, tc } from './i18n';
import { drainQueue, login, seed } from './support';

// Owner-only schedule management on the Envelopes page (issue #15,
// docs/scheduled-transactions.md): the drawer creates, edits and deletes a
// schedule, and the generated rows follow in the open ledger once the
// queued reconciliation has run. The fixture's budget is published for the
// current month, so dates are derived from today (the suite runs in UTC).

test.beforeEach(() => seed('seed-schedules.php'));

const today = new Date();
const year = today.getFullYear();
const month = today.getMonth();
const iso = (day: number) =>
    `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
const S = 'envelopes.schedules.summary';
const monthly = (day: number) => t(`${S}.monthly`, { day });
const affected = tc('envelopes.schedules.affected', 1);
const mediumDate = (date: Date) =>
    date.toLocaleDateString('en', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
const shortDate = (date: Date) =>
    date.toLocaleDateString('en', { month: 'short', day: 'numeric' });
/** English name of an ISO weekday (2024-01-01 is a Monday). */
const weekday = (iso: number) =>
    new Date(2024, 0, iso).toLocaleDateString('en', { weekday: 'long' });

async function openSchedules(page: Page) {
    await page.goto('/envelopes');
    await page
        .getByRole('button', {
            name: t('envelopes.optionsFor', { name: 'Rent' }),
        })
        .click();
    await page
        .getByRole('menuitem', { name: t('envelopes.menu.schedules') })
        .click();
    const drawer = page.getByRole('dialog', {
        name: t('envelopes.schedules.drawer.title'),
    });
    await expect(drawer).toBeVisible();

    return drawer;
}

function scheduledRows(page: Page) {
    return page
        .locator('tbody tr')
        .filter({ has: page.getByTestId('scheduled-badge') });
}

test('an owner creates, edits and deletes a schedule; the open ledger follows', async ({
    page,
}) => {
    await login(page);
    let drawer = await openSchedules(page);
    await expect(
        drawer.getByText(t('envelopes.schedules.drawer.empty')),
    ).toBeVisible();

    // --- create ---------------------------------------------------------
    await drawer
        .getByRole('button', { name: t('envelopes.schedules.drawer.add') })
        .click();
    const create = page.getByRole('dialog', {
        name: t('envelopes.schedules.dialog.newTitle'),
    });
    const amount = create.getByLabel(t('envelopes.schedules.dialog.amount'));

    // Client-side validation names the problem and focuses the field.
    await create
        .getByRole('button', { name: t('envelopes.schedules.dialog.create') })
        .click();
    await expect(
        create.getByText(t('envelopes.schedules.validation.amount')),
    ).toBeVisible();
    await expect(amount).toBeFocused();

    await amount.fill('45.50');
    // The day of the month follows the start date until it is edited.
    await create
        .getByLabel(t('envelopes.schedules.dialog.startDate'))
        .fill(iso(1));
    await expect(
        create.getByLabel(t('envelopes.schedules.dialog.dayOfMonth')),
    ).toHaveValue('1');
    await create
        .getByRole('button', { name: t('envelopes.schedules.dialog.create') })
        .click();
    await expect(
        page.getByText(t('envelopes.schedules.toast.created')),
    ).toBeVisible();
    await expect(create).toBeHidden();

    const row = drawer.getByTestId('schedule-row');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(monthly(1));
    await expect(row).toContainText(
        t(`${S}.from`, { start: mediumDate(new Date(year, month, 1)) }),
    );
    await expect(row).toContainText(t(`${S}.noReminder`));
    await expect(row).toContainText('($45.50)');

    drainQueue();
    await page.goto('/ledger');
    await expect(scheduledRows(page)).toHaveCount(1);
    await expect(scheduledRows(page)).toContainText('Rent');
    await expect(scheduledRows(page)).toContainText(
        shortDate(new Date(year, month, 1)),
    );
    await expect(scheduledRows(page)).toContainText('($45.50)');

    // --- edit -----------------------------------------------------------
    drawer = await openSchedules(page);
    await drawer
        .getByRole('button', {
            name: t('envelopes.schedules.drawer.edit', {
                summary: monthly(1),
            }),
        })
        .click();
    const edit = page.getByRole('dialog', {
        name: t('envelopes.schedules.dialog.editTitle'),
    });
    await expect(edit.getByTestId('schedule-affected')).toHaveText(affected);
    await expect(
        edit.getByLabel(t('envelopes.schedules.dialog.amount')),
    ).toHaveValue('45.50');
    await edit.getByLabel(t('envelopes.schedules.dialog.amount')).fill('60');
    await edit
        .getByLabel(t('envelopes.schedules.dialog.dayOfMonth'))
        .fill('15');
    await edit
        .getByRole('combobox', {
            name: t('envelopes.schedules.dialog.reminder'),
        })
        .click();
    await page
        .getByRole('option', {
            name: t('envelopes.schedules.reminders.sameDay'),
        })
        .click();
    await edit
        .getByRole('button', { name: t('envelopes.schedules.dialog.save') })
        .click();
    await expect(
        page.getByText(t('envelopes.schedules.toast.updated')),
    ).toBeVisible();
    await expect(row).toContainText(monthly(15));
    await expect(row).toContainText(t(`${S}.remindSameDay`));
    await expect(row).toContainText('($60.00)');

    drainQueue();
    await page.goto('/ledger');
    await expect(scheduledRows(page)).toHaveCount(1);
    await expect(scheduledRows(page)).toContainText(
        shortDate(new Date(year, month, 15)),
    );
    await expect(scheduledRows(page)).toContainText('($60.00)');
    await expect(scheduledRows(page)).not.toContainText('($45.50)');

    // --- delete ---------------------------------------------------------
    drawer = await openSchedules(page);
    await drawer
        .getByRole('button', {
            name: t('envelopes.schedules.drawer.delete', {
                summary: monthly(15),
            }),
        })
        .click();
    const confirm = page.getByRole('dialog', {
        name: t('envelopes.schedules.delete.title'),
    });
    await expect(confirm.getByTestId('schedule-affected')).toHaveText(affected);
    await confirm
        .getByRole('button', { name: t('envelopes.schedules.delete.confirm') })
        .click();
    await expect(
        page.getByText(t('envelopes.schedules.toast.deleted')),
    ).toBeVisible();
    await expect(
        drawer.getByText(t('envelopes.schedules.drawer.empty')),
    ).toBeVisible();
    await expect(
        drawer.getByRole('button', {
            name: t('envelopes.schedules.drawer.add'),
        }),
    ).toBeFocused();

    // Deletion removes the open-ledger rows synchronously.
    // The fixture has no manual rows: the loaded month is empty.
    await page.goto('/ledger');
    await expect(page.getByText(t('ledger.table.empty'))).toBeVisible();
    await expect(scheduledRows(page)).toHaveCount(0);
});

test('weekly schedules need a weekday and summarize the chosen days', async ({
    page,
}) => {
    await login(page);
    const drawer = await openSchedules(page);
    await drawer
        .getByRole('button', { name: t('envelopes.schedules.drawer.add') })
        .click();
    const create = page.getByRole('dialog', {
        name: t('envelopes.schedules.dialog.newTitle'),
    });
    await create.getByLabel(t('envelopes.schedules.dialog.amount')).fill('12');
    await create
        .getByRole('combobox', { name: t('envelopes.schedules.dialog.period') })
        .click();
    await page
        .getByRole('option', {
            name: t('envelopes.schedules.periods.biweekly'),
        })
        .click();
    await create
        .getByLabel(t('envelopes.schedules.dialog.startDate'))
        .fill('2090-03-01');

    // The start date's weekday (Wed Mar 1, 2090) is preselected: clear it.
    const weekdays = create.getByRole('group', {
        name: t('envelopes.schedules.dialog.weekdays'),
    });
    await expect(weekdays.locator('[data-state="on"]')).toHaveCount(1);
    const wednesday = weekdays.getByRole('button', { name: weekday(3) });
    await expect(wednesday).toHaveAttribute('data-state', 'on');
    await wednesday.click();
    await create
        .getByRole('button', { name: t('envelopes.schedules.dialog.create') })
        .click();
    await expect(
        create.getByText(t('envelopes.schedules.validation.weekdays')),
    ).toBeVisible();

    // Bi-weekly anchors each weekday on its first date (Thu Mar 2, Mon Mar 6).
    await weekdays.getByRole('button', { name: weekday(1) }).click();
    await weekdays.getByRole('button', { name: weekday(4) }).click();
    await expect(
        create.getByText(t('envelopes.schedules.validation.weekdays')),
    ).toBeHidden();
    await create
        .getByRole('button', { name: t('envelopes.schedules.dialog.create') })
        .click();
    await expect(create).toBeHidden();

    const row = drawer.getByTestId('schedule-row');
    await expect(row).toContainText(
        t(`${S}.biweekly`, { days: 'Mon and Thu' }),
    );
    await expect(row).toContainText(
        t(`${S}.from`, { start: mediumDate(new Date(2090, 2, 1)) }),
    );
    await expect(row).toContainText(
        t(`${S}.upcoming`, {
            dates: [2, 6, 16, 20, 30]
                .map((day) => shortDate(new Date(2090, 2, day)))
                .join(', '),
        }),
    );
});

test('non-owners get no Schedules menu item', async ({ page }) => {
    await login(page, 'schedule-member@example.com');
    await page.goto('/envelopes');
    await expect(
        page
            .getByRole('region', { name: t('envelopes.groups.expenses') })
            .getByText('Rent', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', {
            name: t('envelopes.optionsFor', { name: 'Rent' }),
        }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('menuitem', { name: t('envelopes.menu.schedules') }),
    ).toHaveCount(0);
});
