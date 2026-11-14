import { expect, type Page, test } from '@playwright/test';
import { t } from './i18n';
import { login, seed } from './support';

const PROFILE_KEY = 'prospera.current_profile_id';

async function selectProfile(page: Page, name: RegExp): Promise<void> {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.getByRole('menuitem', { name: 'Profiles' }).click();
    const menu = page.getByRole('menu', { name: 'Profiles' });
    await menu.getByRole('menuitemradio', { name }).click();
}

test.describe('a brand-new account', () => {
    test.beforeEach(() => {
        seed('seed-onboarding.php');
    });

    test('lands on getting started, with the route and the field guides', async ({
        page,
    }) => {
        await login(page);
        await expect(page).toHaveURL(/\/onboarding$/);

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: t('onboarding.hero.notStarted.title'),
            }),
        ).toBeVisible();

        // The route: step 1 is current, the others wait their turn.
        const route = page.getByRole('list').filter({
            has: page.getByRole('heading', {
                name: new RegExp(t('onboarding.route.stages.envelopes.title')),
            }),
        });
        await expect(route.locator('[aria-current="step"]')).toContainText(
            t('onboarding.route.stages.envelopes.title'),
        );
        await expect(
            page.getByRole('group', {
                name: t('onboarding.slots.expenseLabel', { have: 0, need: 3 }),
            }),
        ).toBeVisible();

        // The four field guides, in order.
        const guides = page.locator('#guides').getByRole('link');
        await expect(guides).toHaveCount(4);
        for (const [index, topic] of [
            'envelopes',
            'ledgers',
            'transactions',
            'budgets',
        ].entries()) {
            await expect(guides.nth(index)).toContainText(
                t(`help.${topic}.title`),
            );
        }

        // The hero's primary action opens the envelope dialog, prefilled.
        await page
            .getByRole('link', { name: t('onboarding.hero.notStarted.cta') })
            .click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('radio', { name: 'Revenue' }),
        ).toBeChecked();
        // The deep link is consumed: a reload doesn't reopen the dialog.
        await expect(page).toHaveURL(/\/envelopes$/);
    });

    test('the envelopes page teaches the first step and tracks it', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/envelopes');

        await expect(
            page.getByRole('heading', { name: t('envelopes.empty.title') }),
        ).toBeVisible();
        const bar = page.locator('[data-test="setup-journey-bar"]');
        await expect(bar).toBeVisible();
        await expect(bar).toContainText('Step 1 of 4');

        // A starter chip opens the dialog with its name and direction.
        await page
            .getByRole('button', {
                name: t('onboarding.starter.add', {
                    name: t('onboarding.starter.revenue.paycheck'),
                }),
            })
            .click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByLabel('Name')).toHaveValue(
            t('onboarding.starter.revenue.paycheck'),
        );
        await expect(
            dialog.getByRole('radio', { name: 'Revenue' }),
        ).toBeChecked();
        await dialog
            .getByRole('button', { name: t('envelopes.form.create') })
            .click();
        await expect(dialog).toBeHidden();

        // Once envelopes exist, the bar shows the slots and the starter chips.
        await expect(
            bar.getByRole('group', {
                name: t('onboarding.slots.revenueLabel', { have: 1, need: 1 }),
            }),
        ).toBeVisible();
        for (const key of ['housing', 'groceries', 'gas']) {
            await bar
                .getByRole('button', {
                    name: t('onboarding.starter.add', {
                        name: t(`onboarding.starter.expense.${key}`),
                    }),
                })
                .click();
            const create = page.getByRole('dialog', {
                name: t('envelopes.dialog.newTitle'),
            });
            await create
                .getByRole('button', { name: t('envelopes.form.create') })
                .click();
            await expect(create).toBeHidden();
        }

        // Step 1, stamped: the milestone offers the ledger next.
        const milestone = page.getByRole('dialog', {
            name: t('onboarding.milestones.envelopesReady.title'),
        });
        await expect(milestone).toBeVisible();
        await expect(
            milestone.getByRole('link', {
                name: t('onboarding.milestones.envelopesReady.primary'),
            }),
        ).toBeFocused();
        await milestone
            .getByRole('link', {
                name: t('onboarding.milestones.envelopesReady.primary'),
            })
            .click();

        // /ledger?create=1 opens the create dialog.
        await expect(
            page.getByRole('dialog', { name: t('ledger.createDialog.title') }),
        ).toBeVisible();
    });

    test('/envelopes?new=expense&name=… opens a prefilled dialog', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/envelopes?new=expense&name=Groceries');

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByLabel('Name')).toHaveValue('Groceries');
        await expect(
            dialog.getByRole('radio', { name: 'Expense' }),
        ).toBeChecked();
        await expect(page).toHaveURL(/\/envelopes$/);
    });

    test('the ledger and budget pages explain what unlocks them', async ({
        page,
    }) => {
        await login(page);

        await page.goto('/ledger');
        await expect(
            page.getByRole('heading', { name: t('ledger.empty.lockedTitle') }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: t('ledger.actions.createLedger'),
            }),
        ).toHaveCount(0);

        await page.goto('/budget');
        await expect(
            page.getByRole('heading', { name: t('budget.empty.lockedTitle') }),
        ).toBeVisible();
        // No disabled "Create budget" button: the next move is a link.
        await expect(
            page.getByRole('button', { name: t('budget.empty.create') }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('link', { name: t('budget.empty.lockedAction') }),
        ).toHaveAttribute('href', '/ledger#add-transaction');
    });

    test('members of a shared profile see a read-only overview', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/onboarding');
        await selectProfile(page, /Family/);

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: t('onboarding.hero.readOnly.title'),
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: t('onboarding.pledge.sign') }),
        ).toHaveCount(0);

        // No owner-only progress on the profile's pages either.
        await page.goto('/envelopes');
        await expect(
            page.locator('[data-test="setup-journey-bar"]'),
        ).toHaveCount(0);
        await page.goto('/budget');
        await expect(
            page.getByRole('heading', {
                name: t('budget.empty.onlyOwnerTitle'),
            }),
        ).toBeVisible();

        await page.evaluate((key) => localStorage.removeItem(key), PROFILE_KEY);
    });

    test('the pledge is signed and remembered in this browser', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/onboarding');

        const sign = page.getByRole('button', {
            name: t('onboarding.pledge.sign'),
        });
        await expect(sign).toBeDisabled();
        await page.getByLabel(t('onboarding.pledge.items.record')).check();
        await sign.click();

        await expect(
            page.getByText(t('onboarding.pledge.savedHere')),
        ).toBeVisible();
        await page.reload();
        await expect(
            page.getByRole('button', { name: t('onboarding.pledge.change') }),
        ).toBeVisible();
    });
});

test.describe('midway through the 30-day challenge', () => {
    test.beforeEach(() => {
        seed('seed-onboarding-challenge.php');
    });

    test('the ledger shows the challenge and focuses the transaction form', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/ledger#add-transaction');

        const bar = page.locator('[data-test="setup-journey-bar"]');
        await expect(bar).toContainText('Step 3 of 4');
        await expect(bar.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '12',
        );
        await expect(bar).toContainText('2 of 4 envelopes have a transaction');
        await expect(page.locator('#transaction-envelope')).toBeFocused();
    });

    test('help guides show live progress and step through in order', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/help/transactions');

        await expect(
            page.getByRole('heading', {
                level: 1,
                name: t('help.transactions.title'),
            }),
        ).toBeVisible();
        await expect(page.getByText('Day 13 of 30').first()).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: new RegExp(t('help.transactions.challenge.cta')),
            }),
        ).toHaveAttribute('href', '/ledger#add-transaction');

        for (const topic of ['envelopes', 'ledgers', 'budgets']) {
            await page.goto(`/help/${topic}`);
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: t(`help.${topic}.title`),
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', {
                    name: t('help.common.backToStart'),
                    exact: true,
                }),
            ).toBeVisible();
        }

        // The help index lands on the field guides.
        await page.goto('/help');
        await expect(page).toHaveURL(/\/onboarding#guides$/);
    });

    test('page help links and the user menu lead to the guides', async ({
        page,
    }) => {
        await login(page);

        await page.goto('/budget');
        await page
            .getByRole('link', { name: t('help.budgets.linkLabel') })
            .click();
        await expect(page).toHaveURL(/\/help\/budgets$/);

        await page.goto('/envelopes');
        await page
            .getByRole('link', { name: t('help.envelopes.linkLabel') })
            .click();
        await expect(page).toHaveURL(/\/help\/envelopes$/);

        await page.getByRole('button', { name: /Playwright Tester/ }).click();
        await page
            .getByRole('menuitem', { name: 'Getting started and help' })
            .click();
        await expect(page).toHaveURL(/\/onboarding$/);
    });

    test('the dashboard shows the setup route until the first budget', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/dashboard');

        const journey = page.locator('[data-test="dashboard-journey"]');
        await expect(journey).toBeVisible();
        await expect(journey.locator('[aria-current="step"]')).toContainText(
            t('onboarding.route.stages.challenge.title'),
        );
    });
});
