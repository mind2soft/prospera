import { expect, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeEach(() => {
    seed('seed.php');
});

test('amount edits report their autosave status', async ({ page }) => {
    await login(page);
    await page.goto('/budget');
    const amount = page.getByRole('spinbutton', { name: 'Food amount' });
    const status = page.getByTestId('budget-save-status');
    await expect(status).toHaveAttribute('role', 'status');

    await amount.fill('850');
    await expect(status).toHaveText('Saving…');
    await expect(status).toHaveText('All changes saved');

    // The saved amount survives a reload.
    await page.reload();
    await expect(
        page.getByRole('spinbutton', { name: 'Food amount' }),
    ).toHaveValue('850');
});

test('an amount edit is saved when the page is reloaded', async ({ page }) => {
    await login(page);
    await page.goto('/budget');

    // Reload within the autosave debounce: the page flushes on `pagehide`.
    await page.getByRole('spinbutton', { name: 'Food amount' }).fill('875');
    await page.reload();

    await expect(async () => {
        await page.reload();
        await expect(
            page.getByRole('spinbutton', { name: 'Food amount' }),
        ).toHaveValue('875', { timeout: 5_000 });
    }).toPass({ timeout: 20_000 });
});

test('a failed save is reported as not saved', async ({ page }) => {
    await login(page);
    await page.goto('/budget');
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, (route) =>
        route.request().method() === 'PATCH'
            ? route.fulfill({
                  status: 500,
                  contentType: 'application/json',
                  body: JSON.stringify({ message: 'Server unavailable.' }),
              })
            : route.fallback(),
    );

    await page.getByRole('spinbutton', { name: 'Food amount' }).fill('900');
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'A change could not be saved.',
    );
});

test('an invalid amount is explained inline instead of silently reverted', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');
    const amount = page.getByRole('spinbutton', { name: 'Food amount' });

    await amount.fill('0');
    await amount.blur();
    await expect(amount).toHaveValue('0');
    await expect(amount).toHaveAttribute('aria-invalid', 'true');
    await expect(amount).toHaveAccessibleDescription(
        'Not saved: enter a whole amount of 1 or more.',
    );

    // Escape restores the saved amount.
    await amount.focus();
    await amount.press('Escape');
    await expect(amount).toHaveValue('800');
    await expect(amount).not.toHaveAttribute('aria-invalid', 'true');
});

test('a newer edit is sent after an older save in flight, never before it', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');

    // Hold the first save in flight while the user types a newer amount.
    const bodies: { amount: number }[] = [];
    let releaseFirst: () => void = () => {};
    const firstHeld = new Promise<void>((resolve) => {
        releaseFirst = resolve;
    });
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, async (route) => {
        if (route.request().method() !== 'PATCH') {
            return route.fallback();
        }
        bodies.push(route.request().postDataJSON() as { amount: number });
        if (bodies.length === 1) {
            await firstHeld;
        }

        return route.continue();
    });

    const amount = page.getByRole('spinbutton', { name: 'Food amount' });
    await amount.fill('300');
    // The autosave debounce elapses: the first save is captured and held.
    await page.waitForTimeout(700);
    expect(bodies).toHaveLength(1);

    // A newer edit is queued. A full debounce window passes while the older
    // save is still in flight: no second request may start (an unserialized
    // implementation would have sent — and completed — the 400 here).
    await amount.fill('400');
    await page.waitForTimeout(700);
    expect(bodies).toHaveLength(1);

    releaseFirst();
    await page.waitForTimeout(700);
    expect(bodies.map((body) => body.amount)).toEqual([300, 400]);

    // The newer amount is what actually persisted.
    await page.reload();
    await expect(
        page.getByRole('spinbutton', { name: 'Food amount' }),
    ).toHaveValue('400');
});

test('publishing waits for a pending amount edit to be saved', async ({
    page,
}) => {
    // A fake clock (paused below) keeps the debounced save from firing on
    // its own: the publish is asked for inside the debounce window.
    await page.clock.install();
    await login(page);
    await page.goto('/budget');

    // Hold the amount save in flight until the publish has been asked for.
    const order: string[] = [];
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, async (route) => {
        if (route.request().method() !== 'PATCH') {
            return route.fallback();
        }
        await held;
        await route.continue();
        order.push('amount saved');
    });
    page.on('request', (request) => {
        if (request.method() === 'POST' && /\/publish$/.test(request.url())) {
            order.push('publish sent');
        }
    });

    // Edit, then publish right away (inside the autosave debounce).
    await page.clock.pauseAt(Date.now() + 60_000);
    await page.getByRole('spinbutton', { name: 'Food amount' }).fill('950');
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Publish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Publish this budget?' });
    await dialog.getByRole('button', { name: 'Publish' }).click();

    // Confirm waits (busy) while the edit is saving; nothing is published.
    await expect(
        dialog.getByRole('button', { name: 'Publish' }),
    ).toBeDisabled();
    expect(order).toEqual([]);

    release();
    await page.clock.resume();
    await expect(page.getByText('Budget published.')).toBeVisible();
    expect(order).toEqual(['amount saved', 'publish sent']);

    // The published budget carries the edited amount.
    await page.reload();
    await expect(
        page.getByRole('row', { name: /Food/ }).getByText('$950'),
    ).toBeVisible();
});

test('removing an envelope keeps keyboard focus in the table', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');

    await page
        .getByRole('button', { name: 'Remove Food from the budget' })
        .click();
    await expect(page.getByText('Envelope removed.')).toBeVisible();
    // The row's button is gone: focus lands on the table, not <body>.
    await expect(
        page.getByRole('region', { name: 'Budget envelopes' }),
    ).toBeFocused();
});
