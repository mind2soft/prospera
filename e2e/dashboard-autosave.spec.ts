import { expect, type Page, type Route, test } from '@playwright/test';
import { login, seed } from './support';

/**
 * Dashboard autosave races (RES-R2-1, RES-R2-2): a layout save that is
 * still in flight when the user switches profile, or reorders a widget the
 * save is creating. The layout PUT is held by the test until released, so
 * the race is deterministic.
 */

const SAVE_URL = /\/api\/dashboard\/(\d+)\/widgets$/;

type HeldSave = { profileId: number; ids: (number | undefined)[] };

/** Holds every layout save until `release()`; records each payload. */
async function holdSaves(page: Page) {
    const sent: HeldSave[] = [];
    let open: () => void = () => {};
    const gate = new Promise<void>((resolve) => {
        open = resolve;
    });

    await page.route(SAVE_URL, async (route: Route) => {
        const request = route.request();

        if (request.method() !== 'PUT') {
            return route.fallback();
        }

        const payload = request.postDataJSON() as {
            widgets: { id?: number }[];
        };
        sent.push({
            profileId: Number(SAVE_URL.exec(request.url())?.[1]),
            ids: payload.widgets.map((w) => w.id),
        });

        await gate;
        await route.continue();
    });

    return {
        sent,
        release: () => open(),
    };
}

async function switchProfile(page: Page, name: RegExp): Promise<void> {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.getByRole('menuitem', { name: 'Profiles' }).click();
    const menu = page.getByRole('menu', { name: 'Profiles' });
    await menu.getByRole('menuitemradio', { name }).click();
}

const cards = (page: Page) => page.locator('[data-widget-card]');

test.beforeEach(() => {
    seed('seed-dashboard-profiles.php');
});

test('switching profile during a pending save keeps the other profile layout', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    await expect(cards(page)).toHaveCount(2, { timeout: 15_000 });
    await expect(cards(page).first()).toContainText('Home spending');

    // Cache the shared profile's layout too, then come back.
    await switchProfile(page, /Family/);
    await expect(cards(page).first()).toContainText('Family spending');
    await switchProfile(page, /Default profile/);
    await expect(cards(page).first()).toContainText('Home spending');

    const saves = await holdSaves(page);

    // A keyboard reorder saves immediately — the PUT is now held.
    await cards(page)
        .first()
        .getByRole('button', { name: 'Reorder Home spending' })
        .press('ArrowRight');
    await expect(cards(page).first()).toContainText('Home trend');
    await expect.poll(() => saves.sent.length).toBe(1);

    // Switch while the save is pending: the shared profile's own layout
    // shows, never an empty dashboard.
    await switchProfile(page, /Family/);
    await expect(cards(page)).toHaveCount(2);
    await expect(cards(page).first()).toContainText('Family spending');

    const settled = page.waitForResponse(
        (r) => r.request().method() === 'PUT' && SAVE_URL.test(r.url()),
    );
    saves.release();
    await settled;

    await expect(cards(page)).toHaveCount(2);
    await expect(page.getByText('Make it yours')).toBeHidden();

    // Editing the shared profile now saves its full layout (both existing
    // widgets kept, plus the new one) — never a layout missing its widgets.
    const familySave = page.waitForResponse(
        (r) =>
            r.request().method() === 'PUT' &&
            SAVE_URL.test(r.url()) &&
            r.request().postDataJSON()?.widgets?.length === 3,
    );
    await page.getByRole('button', { name: 'Add widget' }).click();
    await page
        .getByRole('menuitem', { name: 'Envelope spending' })
        .click({ force: true });
    await expect(cards(page)).toHaveCount(3);
    expect((await familySave).ok()).toBe(true);

    const familyId = saves.sent[saves.sent.length - 1].profileId;
    const homeId = saves.sent[0].profileId;
    expect(familyId).not.toBe(homeId);
    for (const save of saves.sent.filter((s) => s.profileId === familyId)) {
        expect(save.ids.filter((id) => id !== undefined)).toHaveLength(2);
    }

    // Both layouts survived on the server.
    await page.reload();
    await expect(cards(page)).toHaveCount(3, { timeout: 15_000 });
    await switchProfile(page, /Default profile/);
    await expect(cards(page)).toHaveCount(2);
    await expect(cards(page).first()).toContainText('Home trend');
});

test('an edit made while a save is in flight survives leaving the page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    await expect(cards(page)).toHaveCount(2, { timeout: 15_000 });

    const saves = await holdSaves(page);

    // A keyboard reorder saves immediately — the PUT is now held.
    await cards(page)
        .first()
        .getByRole('button', { name: 'Reorder Home spending' })
        .press('ArrowRight');
    await expect(cards(page).first()).toContainText('Home trend');
    await expect.poll(() => saves.sent.length).toBe(1);

    // Rename a widget while that save is pending (its debounce never
    // elapses: the user leaves first).
    await cards(page)
        .first()
        .getByRole('button', { name: 'Widget settings' })
        .click();
    await page.getByRole('textbox', { name: 'Title' }).fill('Renamed trend');
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();

    await page
        .locator('[data-sidebar="sidebar"]')
        .getByRole('link', { name: 'Envelopes' })
        .click();
    await page.waitForURL((url) => url.pathname === '/envelopes');

    // The queued rename goes out once the held save settles, even though
    // the dashboard is gone.
    const followUp = page.waitForResponse(
        (r) =>
            r.request().method() === 'PUT' &&
            SAVE_URL.test(r.url()) &&
            r
                .request()
                .postDataJSON()
                ?.widgets?.some(
                    (w: { title?: string }) => w.title === 'Renamed trend',
                ),
    );
    saves.release();
    expect((await followUp).ok()).toBe(true);

    await page.goto('/dashboard');
    await expect(cards(page)).toHaveCount(2, { timeout: 15_000 });
    await expect(cards(page).first()).toContainText('Renamed trend');
    await expect(cards(page).last()).toContainText('Home spending');
});

test('a debounced edit is saved when the page is reloaded', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    await expect(cards(page)).toHaveCount(2, { timeout: 15_000 });

    await cards(page)
        .first()
        .getByRole('button', { name: 'Widget settings' })
        .click();

    await page.getByRole('textbox', { name: 'Title' }).fill('Reloaded title');
    // Reload within the autosave debounce: the page flushes on `pagehide`.
    await page.reload();

    await expect(async () => {
        await page.reload();
        await expect(cards(page).first()).toContainText('Reloaded title', {
            timeout: 5_000,
        });
    }).toPass({ timeout: 20_000 });
});

test('reordering a widget while it is being created keeps unique ids', async ({
    page,
}) => {
    await login(page);
    await page.goto('/dashboard');
    await expect(cards(page)).toHaveCount(2, { timeout: 15_000 });

    const saves = await holdSaves(page);

    await page.getByRole('button', { name: 'Add widget' }).click();
    await page.getByRole('menuitem', { name: 'Radial' }).click({ force: true });
    await expect(cards(page)).toHaveCount(3);

    // The debounced save of the new widget is held in flight.
    await expect.poll(() => saves.sent.length).toBe(1);
    expect(saves.sent[0].ids[2]).toBeUndefined();

    // Move the new widget earlier while its creation is pending.
    await cards(page)
        .last()
        .getByRole('button', { name: 'Reorder Radial' })
        .press('ArrowLeft');
    await expect(cards(page).nth(1)).toContainText('Radial');

    const followUp = page.waitForResponse(
        (r) =>
            r.request().method() === 'PUT' &&
            SAVE_URL.test(r.url()) &&
            r.request().postDataJSON()?.widgets?.[1]?.type === 'radial',
    );
    saves.release();

    // The queued reorder carries the id assigned to the new widget, once.
    expect((await followUp).ok()).toBe(true);
    const ids = saves.sent[saves.sent.length - 1].ids;
    expect(ids.every((id) => id !== undefined)).toBe(true);
    expect(new Set(ids).size).toBe(3);

    await page.reload();
    await expect(cards(page)).toHaveCount(3, { timeout: 15_000 });
    await expect(cards(page).nth(1)).toContainText('Radial');
});
