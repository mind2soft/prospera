import { execSync } from 'node:child_process';
import type { Page } from '@playwright/test';

export const PASSWORD = 'Password!234';

/** Seeds the isolated e2e database (see e2e/seed.sh). */
export function seed(...files: string[]): void {
    for (const file of files) {
        execSync(`npm run e2e:seed -- ${file}`, { cwd: '.' });
    }
}

export async function login(
    page: Page,
    email = 'playwright@example.com',
): Promise<void> {
    // Reset Fortify's login rate limiter (5/min per email+IP).
    execSync('php artisan cache:clear --env=e2e', { cwd: '.' });
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    // Let the landing page (usually the dashboard) finish its API calls: a
    // request of it still being served after the spec's next navigation
    // would consume a one-time session flash meant for that page.
    await page.waitForLoadState('networkidle');
}
