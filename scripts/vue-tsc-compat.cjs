/**
 * Vue Language Tools (vue-tsc / @volar) still run on the classic TypeScript
 * JS API (`ts.sys`, the comparable `lib/tsc` entry point, ...), which the
 * native TypeScript 7 npm package no longer ships: `typescript@^7` exposes
 * only a version string plus the NAPI "unstable" API. This shim therefore
 * routes every `typescript*` module import made from within the `vue-tsc`
 * package (or its @volar dependencies) to the official classic-API bridge,
 * `@typescript/typescript6` — installed here as `typescript-classic`, whose
 * entry points simply re-export the full `typescript@6` API (`@typescript/old`).
 *
 * The project's TypeScript dependency itself stays `typescript@^7` (the
 * native compiler): only vue-tsc's runtime falls back to the bridge, so
 * type checking keeps working until @volar adopts the TypeScript 7 API.
 *
 * The same compatibility approach is used at build time: `vite.config.ts`
 * hands @vue/compiler-sfc the classic API through its `registerTS()` hook.
 *
 * @see vite.config.ts
 * @see package.json "typescript-classic"
 * @see https://github.com/mind2soft/prospera/pull/32
 */
const Module = require('node:module');

const CLASSIC_PACKAGE = 'typescript-classic';

const resolveFilename = Module._resolveFilename;
Module._resolveFilename = (request, parent, ...args) => {
    if (
        typeof request === 'string' &&
        request.startsWith('typescript') &&
        parent?.filename?.includes(
            `${require('node:path').sep}vue-tsc${require('node:path').sep}`,
        )
    ) {
        request = CLASSIC_PACKAGE + request.slice('typescript'.length);
    }
    return resolveFilename.call(Module, request, parent, ...args);
};

require('vue-tsc/bin/vue-tsc');
