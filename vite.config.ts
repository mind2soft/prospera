import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import * as compilerSfc from '@vue/compiler-sfc';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import typescriptClassic from 'typescript-classic';
import type { Plugin } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';
import { MATERIAL_SYMBOLS } from './resources/js/lib/materialSymbols';

// TypeScript 7 is the native compiler: its npm package ships no classic JS API
// (no `ts.sys`), which @vue/compiler-sfc requires to resolve types imported by
// `defineProps<...>`. Plug the compiler's TypeScript loader to the official
// classic-API bridge (`typescript-classic`, the aliased `@typescript/typescript6`
// wrapper re-exporting the full typescript@6 API), and pass this compiler
// instance to the Vue plugin so it never falls back to `vue/compiler-sfc`,
// whose `register-ts` loader resolves to the API-less typescript@7. The
// project dependency itself stays `typescript@^7` (the native compiler).
//
// @see scripts/vue-tsc-compat.cjs (same approach for vue-tsc)
// @see package.json "typescript-classic"
compilerSfc.registerTS(() => typescriptClassic);

/**
 * Serves `virtual:material-symbols`: only the Material Symbols the app uses
 * (resources/js/lib/materialSymbols.ts), picked from the full Iconify
 * collection at build time — the full set is ~8 MB of JS. Fails the build
 * when a listed icon does not exist in the collection.
 */
function materialSymbols(): Plugin {
    const id = 'virtual:material-symbols';
    const resolvedId = `\0${id}`;

    return {
        name: 'prospera:material-symbols',
        resolveId: (source) => (source === id ? resolvedId : undefined),
        load(loadId) {
            if (loadId !== resolvedId) {
                return undefined;
            }

            const path = createRequire(import.meta.url).resolve(
                '@iconify-json/material-symbols/icons.json',
            );
            const collection = JSON.parse(readFileSync(path, 'utf8')) as {
                prefix: string;
                width?: number;
                height?: number;
                icons: Record<string, unknown>;
            };

            const missing = MATERIAL_SYMBOLS.filter(
                (name) => !(name in collection.icons),
            );
            if (missing.length > 0) {
                this.error(
                    `Unknown Material Symbols icon(s): ${missing.join(', ')}`,
                );
            }

            const subset = {
                prefix: collection.prefix,
                width: collection.width,
                height: collection.height,
                icons: Object.fromEntries(
                    MATERIAL_SYMBOLS.map((name) => [
                        name,
                        collection.icons[name],
                    ]),
                ),
            };

            return `export default ${JSON.stringify(subset)};`;
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            compiler: compilerSfc,
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        materialSymbols(),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    staged: {
        '*.php': 'vendor/bin/pint',
        '*.{css,json,jsonc,js,cjs,mjs,ts,cts,mts,vue}': 'biome format --write',
    },
});
