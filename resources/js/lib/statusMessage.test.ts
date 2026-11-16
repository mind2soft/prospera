import { beforeEach, describe, expect, it, vi } from 'vitest';
import enCommon from '@/locales/en/common.json';
import statusErrorKeys from '@/locales/status-error-keys.json';

/**
 * `lib/api` checks `isClient` (typeof document !== 'undefined') at import
 * time and `@/i18n` pulls in Vue's runtime-dom, so the module is imported
 * fresh with a document stubbed — the plain Node environment has neither
 * (see api.test.ts). `createElement` keeps Vue's runtime-dom importable.
 */
vi.stubGlobal('document', {
    querySelector: () => null,
    createElement: () => ({}),
});

const errors = enCommon.common.errors;

/** Imports lib/api fresh, after the document stub above is in place. */
const importStatusMessage = async () =>
    (await import('@/lib/api')).statusMessage;

beforeEach(() => {
    vi.resetModules();
});

describe('statusMessage()', () => {
    it('maps the exact statuses of the shared source', async () => {
        const statusMessage = await importStatusMessage();

        for (const [status, key] of Object.entries(statusErrorKeys.exact)) {
            expect(statusMessage(Number(status))).toBe(
                errors[key as keyof typeof errors],
            );
        }
    });

    it('maps every unlisted server error to the server-error message', async () => {
        const statusMessage = await importStatusMessage();

        for (const status of [500, 502, 599]) {
            expect(statusMessage(status)).toBe(
                errors[statusErrorKeys.serverErrors as keyof typeof errors],
            );
        }
    });

    it('maps every other status to the fallback message', async () => {
        const statusMessage = await importStatusMessage();

        for (const status of [400, 409, 418, 422]) {
            expect(statusMessage(status)).toBe(
                errors[statusErrorKeys.fallback as keyof typeof errors],
            );
        }
    });

    it('lets an exact status take precedence over the catch-alls', async () => {
        const statusMessage = await importStatusMessage();

        // 503 is both an exact status and ≥ 500: the shared source's exact
        // mapping must win (maintenance unavailable, not "server error").
        expect(statusMessage(503)).toBe(
            errors[statusErrorKeys.exact[503] as keyof typeof errors],
        );
    });
});
