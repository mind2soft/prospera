import { expect, test } from '@playwright/test';
import { login, seed } from './support';

// A zone far from UTC: the "current month" is the user's.
test.use({ timezoneId: 'Pacific/Auckland' });

test.beforeAll(() => {
    seed('seed.php');
});

const monthIn = (timeZone: string) => {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(new Date());
    const part = (type: string) => parts.find((p) => p.type === type)?.value;
    return `${part('year')}-${part('month')}`;
};

const monthLabelIn = (timeZone: string) =>
    new Intl.DateTimeFormat('en', {
        timeZone,
        month: 'long',
        year: 'numeric',
    }).format(new Date());

async function publish(page: import('@playwright/test').Page) {
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'Publish' }).click();
    return page.getByRole('dialog', { name: 'Publish this budget?' });
}

test('publishing names the month, sends it with the time zone and warns before replacing', async ({
    page,
}) => {
    const month = monthIn('Pacific/Auckland');
    const label = monthLabelIn('Pacific/Auckland');

    await login(page);
    await page.goto('/budget');
    await expect(page.getByText('Paychecks').first()).toBeVisible();

    // First publish: nothing to replace.
    let dialog = await publish(page);
    await expect(dialog).toContainText(`for ${label}`);
    await expect(dialog.getByRole('alert')).toHaveCount(0);

    const request = page.waitForRequest(
        (r) => r.method() === 'POST' && /\/publish$/.test(r.url()),
    );
    await dialog.getByRole('button', { name: 'Publish' }).click();
    expect((await request).postDataJSON()).toEqual({
        month,
        timezone: 'Pacific/Auckland',
    });
    await expect(page.getByText('Budget published.')).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByRole('combobox', { name: 'Select budget' }),
    ).toContainText(label);

    // A revision of the same month replaces the published budget: the
    // confirmation says so, naming the month.
    await page.getByRole('button', { name: 'Budget actions' }).click();
    await page.getByRole('menuitem', { name: 'New Budget' }).click();
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByText('Draft budget created.')).toBeVisible();

    dialog = await publish(page);
    await expect(dialog.getByRole('alert')).toContainText(
        `The budget already published for ${label} will be replaced`,
    );

    // Cancelling closes the publish dialog without flipping to other copy.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Delete this budget?')).toHaveCount(0);
});
