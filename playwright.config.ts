import { defineConfig } from '@playwright/test';

// E2E environment isolation
// -------------------------
// The suite NEVER touches the developer's database. `npm run e2e` boots the
// app through e2e/serve.sh, which:
//   1. derives .env.e2e from .env once (same APP_KEY/APP_URL → identical
//      signed links and Fortify behaviour) with DB_DATABASE=database/e2e.sqlite;
//   2. refuses to start if port 8000 is taken by a dev `php artisan serve`
//      (stop the dev server before running the suite);
//   3. runs `migrate:fresh` against the e2e database, then serves on :8000.
// Specs seed through `npm run e2e:seed -- <seed>.php` (e2e/seed.sh), which
// runs the seed with `--env=e2e` against the same isolated database.

export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    // Every spec's beforeAll reseeds the one shared e2e database
    // (e2e/seed*.php), so parallel workers would destroy each other's fixture
    // state — run all specs serially in a single worker.
    workers: 1,
    webServer: {
        command: 'bash e2e/serve.sh',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: false,
        timeout: 60_000,
    },
    use: {
        baseURL: 'http://localhost:8000',
        trace: 'off',
        screenshot: 'only-on-failure',
        viewport: { width: 1440, height: 900 },
    },
    outputDir: './e2e/.out',
});
