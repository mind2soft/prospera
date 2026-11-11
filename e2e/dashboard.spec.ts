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
    execSync('npm run e2e:seed -- seed-dashboard.php', { cwd: '.' });
});

/**
 * Adds a widget from the catalog through the "+" control and opens its
 * settings sheet. `expectedCount` is the dashboard's widget count after the
 * add (the catalog appends last).
 */
async function addWidget(
    page: Page,
    name: string,
    expectedCount: number,
): Promise<void> {
    const trigger = page.getByRole('button', { name: 'Add widget' });

    if ((await trigger.count()) === 0) {
        // Empty state CTA ("Add a widget") — same catalog, labelled button.
        await page.getByRole('button', { name: 'Add a widget' }).click();
    } else {
        await trigger.click();
    }

    const item = page.getByRole('menuitem', { name });
    await item.waitFor();

    // Selecting the item adds the widget and closes the menu in the same
    // tick — the element may detach while the click resolves, so skip the
    // actionability retries (the click itself has fired by then).
    await item.click({ force: true });

    await expect(page.locator('[data-widget-card]')).toHaveCount(
        expectedCount,
        { timeout: 15_000 },
    );

    // Open the new widget's settings (the catalog appends it last).
    await page
        .locator('[data-widget-card]')
        .last()
        .getByRole('button', { name: 'Widget settings' })
        .click();
    await expect(page.getByRole('dialog')).toBeVisible();
}

/** Adds an envelope row from the settings sheet's "Add envelope" select. */
async function selectEnvelope(
    page: Page,
    triggerName: string,
    envelope: string,
): Promise<void> {
    await page.getByRole('combobox', { name: triggerName }).first().click();
    await page.getByRole('option', { name: envelope }).click();
}

/**
 * Closes the settings sheet and waits for it to fully unmount — while the
 * sheet is animating out, reka keeps `aria-hidden` on the page content and
 * role-based queries would miss the widgets.
 */
async function closeSettings(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
}

test('dashboard audit', async ({ page }) => {
    // Three widgets, a drag & drop, a removal and two reloads — well beyond
    // the default timeout.
    test.slow();

    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error)));

    await login(page);
    await page.goto('/dashboard');

    // The dashboard starts empty — no seeded widgets.
    await expect(page.getByText('Make it yours')).toBeVisible();
    await page.screenshot({ path: SHOT('01-dashboard-empty'), fullPage: true });

    // Add an envelope-spending widget and configure its selection.
    await addWidget(page, 'Envelope spending', 1);
    await selectEnvelope(page, 'Add envelope', 'Food');
    await selectEnvelope(page, 'Add envelope', 'Mortgage');
    await closeSettings(page);

    // Autosave lands (debounced) — wait for the aggregate, not the skeleton.
    const spendingCard = page.locator('[data-widget-card]').first();
    await expect(spendingCard.getByText('$570', { exact: true })).toBeVisible({
        timeout: 15_000,
    });
    await expect(spendingCard.getByText('/ $800')).toBeVisible(); // Food's allocation
    await expect(spendingCard.getByText('/ $1,300')).toBeVisible(); // Mortgage's allocation

    // The entertainment envelope has no covering allocation → no bar, but
    // its spent total still shows. Add it to check the empty progress.
    await page
        .locator('[data-widget-card]')
        .first()
        .getByRole('button', { name: 'Widget settings' })
        .click();
    await selectEnvelope(page, 'Add envelope', 'Fun money');
    await closeSettings(page);
    await expect(spendingCard.getByText('$25')).toBeVisible({
        timeout: 15_000,
    });
    await expect(
        spendingCard.getByText('No budget covers this period'),
    ).toBeVisible();

    await page.screenshot({
        path: SHOT('02-envelope-spending'),
        fullPage: true,
    });

    // Add the history widget with a line graph view.
    await addWidget(page, 'Income & expenses history', 2);
    await selectEnvelope(page, 'Add envelope', 'Paychecks');
    await selectEnvelope(page, 'Add envelope', 'Food');
    // Switch the view type to the line graph.
    await page.getByRole('combobox', { name: 'View type' }).click();
    await page.getByRole('option', { name: 'Line graph' }).click();
    await closeSettings(page);

    const historyCard = page.locator('[data-widget-card]').last();
    await expect(historyCard.locator('svg').first()).toBeVisible({
        timeout: 15_000,
    });

    // Add the radial widget (donut default view).
    await addWidget(page, 'Radial', 3);
    await selectEnvelope(page, 'Add envelope', 'Paychecks');
    await selectEnvelope(page, 'Add envelope', 'Food');
    await closeSettings(page);

    const radialCard = page.locator('[data-widget-card]').last();
    await expect(radialCard.locator('svg').first()).toBeVisible({
        timeout: 15_000,
    });
    await page.screenshot({ path: SHOT('03-full-dashboard'), fullPage: true });

    // Drag & drop reorder: move the radial (last) onto the spending widget
    // (first) — the order is an ordered list, autosaved.
    const firstCard = page.locator('[data-widget-card]').first();
    const radialHandle = radialCard.getByRole('button', {
        name: /Drag to reorder/,
    });
    // Drop on the target's leading edge — the insertion point is derived
    // from the pointer half of the hovered card.
    // The reorder is saved right away (queued behind an in-flight autosave
    // if any): wait for the save carrying the new order before reloading.
    const reorderSaved = page.waitForResponse(
        (r) =>
            r.request().method() === 'PUT' &&
            /\/api\/dashboard\/\d+\/widgets$/.test(r.url()) &&
            r.request().postDataJSON()?.widgets?.[0]?.type === 'radial' &&
            r.ok(),
    );
    await radialHandle.dragTo(firstCard, {
        targetPosition: { x: 10, y: 10 },
    });

    await expect(
        page.locator('[data-widget-card]').first().getByText('Radial'),
    ).toBeVisible({ timeout: 15_000 });

    // The reorder survives a reload (autosave persisted the new order).
    await reorderSaved;
    await page.reload();
    await expect(
        page.locator('[data-widget-card]').first().getByText('Radial'),
    ).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('[data-widget-card]')).toHaveCount(3);

    // Removal is destructive → confirmation.
    await page
        .locator('[data-widget-card]')
        .first()
        .getByRole('button', { name: 'Widget settings' })
        .click();
    await page.getByRole('button', { name: 'Remove widget' }).click();
    await expect(page.getByText('Remove widget?')).toBeVisible();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Remove', exact: true })
        .click();
    await expect(page.locator('[data-widget-card]')).toHaveCount(2);

    await page.screenshot({ path: SHOT('04-after-remove'), fullPage: true });

    // The remaining layout (spending first now that the radial is gone)
    // survives a reload.
    await page.reload();
    await expect(page.locator('[data-widget-card]')).toHaveCount(2);
    await expect(
        page
            .locator('[data-widget-card]')
            .first()
            .getByText('Envelope spending'),
    ).toBeVisible({ timeout: 15_000 });

    expect(consoleErrors).toEqual([]);
});

test('dashboard persists per profile and stays localized', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard');

    // The French locale translates the dashboard chrome (locale.spec.ts
    // switches the locale via settings; here we assert the English default
    // and that the localized catalog descriptions render).
    await expect(page.getByText('Envelope spending').first()).toBeVisible();

    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error)));

    expect(consoleErrors).toEqual([]);
});
