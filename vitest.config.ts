import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        // The save-queue coordinator and its tests are dependency-free
        // (injected transport and clock), so a plain Node environment keeps
        // the unit tests fast and deterministic.
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
