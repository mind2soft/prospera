declare module 'virtual:material-symbols' {
    import type { IconifyJSON } from '@iconify/vue';

    /** Build-time subset of Material Symbols (see lib/materialSymbols.ts). */
    const collection: IconifyJSON;
    export default collection;
}
