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

test('publishing retries a settled failure and refuses while it stays unresolved', async ({
    page,
}) => {
    await login(page);
    await page.goto('/budget');

    // The autosave fails and settles before Publish is asked for (#87): the
    // flush must retry the dirty value, and publication must wait for it.
    let patches = 0;
    let failPatches = true;
    const publishPosts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && /\/publish$/.test(request.url())) {
            publishPosts.push(request.url());
        }
    });
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, (route) => {
        if (route.request().method() !== 'PATCH') {
            return route.fallback();
        }
        patches += 1;
        if (failPatches) {
            return route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server unavailable.' }),
            });
        }

        return route.continue();
    });

    const amount = page.getByRole('spinbutton', { name: 'Food amount' });
    await amount.fill('900');
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'A change could not be saved.',
    );

    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Publish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Publish this budget?' });
    await dialog.getByRole('button', { name: 'Publish' }).click();

    // The publish flush retried the 900 and it failed again: the dialog
    // stays open and nothing is published.
    await expect(dialog.getByRole('button', { name: 'Publish' })).toBeEnabled({
        timeout: 10_000,
    });
    expect(patches).toBe(2); // the settled autosave failure, then its retry
    expect(publishPosts).toHaveLength(0);

    // Connectivity restored: the retried save lands first, then the publish
    // goes out — without re-editing the amount.
    failPatches = false;
    await dialog.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByText('Budget published.')).toBeVisible();
    expect(patches).toBe(3);
    expect(publishPosts).toHaveLength(1);

    // The published budget carries the edited amount.
    await page.reload();
    await expect(
        page.getByRole('row', { name: /Food/ }).getByText('$900'),
    ).toBeVisible();
});

test('a save failing during the publish flush blocks publication', async ({
    page,
}) => {
    // A paused clock keeps the debounced autosave from firing before the
    // publish confirm: the save the flush sends is the one held below, and
    // the dialog button stays clickable until the flush starts (a save
    // already in flight would disable it — same technique as above).
    await page.clock.install();
    await login(page);
    await page.goto('/budget');

    const publishPosts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && /\/publish$/.test(request.url())) {
            publishPosts.push(request.url());
        }
    });

    // Hold the save the publish flush sends, so its failure settles while
    // the flush is waiting for it.
    let release: () => void = () => {};
    let failPatches = true;
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(/\/api\/budgets\/\d+\/envelopes\/\d+$/, async (route) => {
        if (route.request().method() !== 'PATCH') {
            return route.fallback();
        }
        await held;
        if (failPatches) {
            return route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server unavailable.' }),
            });
        }

        return route.continue();
    });

    await page.clock.pauseAt(Date.now() + 60_000);
    await page.getByRole('spinbutton', { name: 'Food amount' }).fill('900');
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Publish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Publish this budget?' });
    await dialog.getByRole('button', { name: 'Publish' }).click();
    await expect(
        dialog.getByRole('button', { name: 'Publish' }),
    ).toBeDisabled();

    // The in-flight save fails and settles during the flush: publication is
    // refused, no publish request is sent.
    release();
    await page.clock.resume();
    await expect(page.getByTestId('budget-save-status')).toHaveText(
        'A change could not be saved.',
    );
    await expect(dialog.getByRole('button', { name: 'Publish' })).toBeEnabled({
        timeout: 10_000,
    });
    expect(publishPosts).toHaveLength(0);

    // The retried save then lands and publication proceeds.
    failPatches = false;
    await dialog.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByText('Budget published.')).toBeVisible();
    expect(publishPosts).toHaveLength(1);

    await page.reload();
    await expect(
        page.getByRole('row', { name: /Food/ }).getByText('$900'),
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
