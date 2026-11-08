import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import * as compilerSfc from '@vue/compiler-sfc';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import typescriptClassic from 'typescript-classic';
import { defineConfig, lazyPlugins } from 'vite-plus';

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
