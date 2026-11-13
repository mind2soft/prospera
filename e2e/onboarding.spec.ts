import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { login } from './support';

test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed.php', { cwd: '.' });
});

test('getting-started guide and help links are available from the app', async ({
    page,
}) => {
    await login(page);
    await page.goto('/onboarding');

    await expect(
        page.getByRole('heading', { name: 'A clear start with your money' }),
    ).toBeVisible();

    for (const [topic, heading] of [
        ['envelopes', 'Create and manage envelopes'],
        ['ledgers', 'Create and manage ledgers'],
        ['transactions', 'Create and manage transactions'],
        ['budgets', 'Create and manage budgets'],
    ] as const) {
        await page.goto(`/help/${topic}`);
        await expect(
            page.getByRole('heading', { name: heading }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Back to getting started' }),
        ).toBeVisible();
    }

    await page.goto('/budget');
    await page.getByRole('link', { name: 'Create and manage budgets' }).click();
    await expect(page).toHaveURL(/\/help\/budgets$/);

    await page.getByRole('button', { name: /Playwright Tester/ }).click();
    await page
        .getByRole('menuitem', { name: 'Getting started and help' })
        .click();
    await expect(page).toHaveURL(/\/onboarding$/);
});
