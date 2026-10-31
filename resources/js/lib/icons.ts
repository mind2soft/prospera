import { addCollection } from '@iconify/vue';

let loaded = false;

export function loadIconCollections(): void {
    if (loaded) {
        return;
    }
    loaded = true;

    void import('@iconify-json/material-symbols/icons.json').then((module) => {
        addCollection(module.default);
    });
}
