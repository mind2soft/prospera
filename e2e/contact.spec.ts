import { execSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';

const LOGIN = {
    email: 'playwright@example.com',
    password: 'Password!234',
};

const COOLDOWN_ERROR =
    'You already contacted us recently. Please wait before submitting again.';

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

// Workers are pinned to 1 globally (playwright.config.ts) and this spec's
// tests build on each other (a submission starts the cooldown), so keep
// them in one file with the default sequential order.
test.beforeAll(() => {
    execSync('npm run e2e:seed -- seed-contact.php', { cwd: '.' });
});

test('the landing page links the contact page and GitHub', async ({ page }) => {
    await page.goto('/');

    // GitHub icon button with its stable tooltip, to the left of the
    // sign-in / dashboard button.
    const githubButton = page.getByTitle('Follow us on Github!');
    await expect(githubButton).toBeVisible();

    const contactSection = page.locator('[data-test="contact-section"]');
    await expect(contactSection).toBeVisible();

    await contactSection.locator('[data-test="contact-cta"]').click();
    await expect(page).toHaveURL(/\/contact$/);
});

test('guest flow: validation errors then successful submission', async ({
    page,
}) => {
    await page.goto('/contact');

    const subject = page.locator('[data-test="contact-subject"]');
    const message = page.locator('[data-test="contact-message"]');
    const submit = page.locator('[data-test="contact-submit"]');

    // An invalid email keeps the user on the form with a field error.
    // (`a@b` passes the browser's native `type=email` check but is rejected
    // server-side, so the server's field error is exercised.)
    await page.locator('[data-test="contact-email"]').fill('a@b');
    await subject.fill('A question');
    await message.fill('Hello, I have a question about envelopes.');
    await submit.click();

    await expect(
        page.getByText('Please enter a valid email address.'),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/contact$/);

    // A valid submission is queued and redirects to the success page.
    await page
        .locator('[data-test="contact-email"]')
        .fill('playwright@example.com');
    await expect(
        page.locator('[data-test="contact-message-bar"]'),
    ).toBeVisible();
    await submit.click();

    await expect(page).toHaveURL(/\/contact\/sent$/);
    await expect(page.getByText('Message sent')).toBeVisible();
});

test('a second submission from the same IP is rejected by the cooldown', async ({
    page,
}) => {
    await page.goto('/contact');

    await page
        .locator('[data-test="contact-email"]')
        .fill('playwright@example.com');
    await page.locator('[data-test="contact-subject"]').fill('Too soon');
    await page
        .locator('[data-test="contact-message"]')
        .fill('Trying again right away.');
    await page.locator('[data-test="contact-submit"]').click();

    await expect(page.locator('[data-test="contact-error"]')).toContainText(
        COOLDOWN_ERROR,
    );
    await expect(page).toHaveURL(/\/contact$/);
});

test('signed-in flow: the account email is read-only', async ({ page }) => {
    await login(page);
    await page.goto('/contact');

    const email = page.locator('[data-test="contact-email"]');
    await expect(email).toHaveValue(LOGIN.email);
    await expect(email).toHaveAttribute('readonly');
});
