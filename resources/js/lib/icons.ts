import materialSymbols from 'virtual:material-symbols';
import { addCollection } from '@iconify/vue';

let loaded = false;

/**
 * Registers the bundled Material Symbols subset (see lib/materialSymbols.ts).
 * It is a few kilobytes, so it is registered synchronously at boot — icons
 * render on the first paint, SSR included, with no network request.
 */
export function loadIconCollections(): void {
    if (loaded) {
        return;
    }
    loaded = true;

    addCollection(materialSymbols);
}
