import { expect, type Page, test } from '@playwright/test';
import { login, seed } from './support';

test.beforeEach(() => seed('seed-schedule-api.php'));

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const token = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    expect(token).toBeDefined();
    return {
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(token?.value ?? ''),
    };
}

async function schedulesUrl(page: Page): Promise<string> {
    const response = await page.request.get('/api/envelopes');
    expect(response.ok()).toBe(true);
    const { envelopes } = await response.json();
    const envelope = envelopes.find(
        (row: { name: string }) => row.name === 'Schedule API Rent',
    );
    expect(envelope).toBeDefined();
    return `/api/envelopes/${envelope.id}/schedules`;
}

test('an owner creates, previews, edits and deletes a schedule definition through the session API', async ({
    page,
}) => {
    await login(page);
    const url = await schedulesUrl(page);
    const headers = await csrfHeaders(page);
    const created = await page.request.post(url, {
        headers,
        data: {
            period: 'monthly',
            start_date: '2090-01-01',
            day_of_month: 31,
            amount: 12345,
        },
    });
    expect(created.status()).toBe(201);
    const { schedule } = await created.json();
    expect(schedule.amount).toBe(-12345);
    expect(schedule.next_occurrences).toEqual([
        '2090-01-31',
        '2090-02-28',
        '2090-03-31',
        '2090-04-30',
        '2090-05-31',
    ]);
    await page.reload();
    const edited = await page.request.patch(`${url}/${schedule.id}`, {
        headers: await csrfHeaders(page),
        data: {
            period: 'weekly',
            weekdays: [4, 1],
            amount: 25000,
            reminder_days: 0,
        },
    });
    expect(edited.status()).toBe(200);
    expect((await edited.json()).schedule).toMatchObject({
        amount: -25000,
        weekdays: [1, 4],
        day_of_month: null,
        reminder_days: 0,
    });
    const listed = await page.request.get(url);
    expect((await listed.json()).schedules).toHaveLength(1);
    const deleted = await page.request.delete(`${url}/${schedule.id}`, {
        headers: await csrfHeaders(page),
    });
    expect(deleted.status()).toBe(204);
    expect((await (await page.request.get(url)).json()).schedules).toEqual([]);
});

test('a profile co-member cannot read schedules or bypass owner checks with an invalid payload', async ({
    page,
}) => {
    await login(page, 'schedule-member@example.com');
    const url = await schedulesUrl(page);
    expect((await page.request.get(url)).status()).toBe(403);
    const response = await page.request.post(url, {
        headers: await csrfHeaders(page),
        data: { period: 'invalid' },
    });
    expect(response.status()).toBe(403);
    expect(await response.text()).not.toContain('amount');
});

test('schedule writes require CSRF protection', async ({ page }) => {
    await login(page);
    const url = await schedulesUrl(page);
    const response = await page.request.post(url, {
        headers: { Accept: 'application/json' },
        data: { period: 'once', start_date: '2090-01-01', amount: 1 },
    });
    expect(response.status()).toBe(419);
    expect((await (await page.request.get(url)).json()).schedules).toEqual([]);
});
