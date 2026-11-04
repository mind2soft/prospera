/**
 * Environment probe shared by the client and SSR bundles.
 *
 * Evaluated once per bundle: `true` in the browser bundle, `false` in the
 * Inertia SSR bundle (Node has no `document`). Because both bundles are
 * built ahead of time, the value is stable for the lifetime of the app in
 * each environment — import it instead of re-checking `document` inline, so
 * client/SSR behaviour stays consistent.
 */
export const isClient = typeof document !== 'undefined';
