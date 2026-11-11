import { readFileSync } from 'node:fs';

/**
 * The English UI copy, read from the app's own locale catalogs
 * (resources/js/locales/en): specs locate controls by their accessible
 * name without hard-coding it, so a copy tweak does not break them — only
 * a renamed key does.
 */
const catalogs = new Map<string, unknown>();

export function t(
    key: string,
    params: Record<string, string | number> = {},
): string {
    const file = key.split('.')[0];

    if (!catalogs.has(file)) {
        catalogs.set(
            file,
            JSON.parse(
                readFileSync(`resources/js/locales/en/${file}.json`, 'utf8'),
            ),
        );
    }

    let node = catalogs.get(file);

    for (const part of key.split('.')) {
        node = (node as Record<string, unknown> | undefined)?.[part];
    }

    if (typeof node !== 'string') {
        throw new Error(`Unknown message key: ${key}`);
    }

    // vue-i18n syntax: {'@'} literals and {name} placeholders.
    return node
        .replace(/\{'([^']*)'\}/g, '$1')
        .replace(/\{(\w+)\}/g, (match, name: string) =>
            name in params ? String(params[name]) : match,
        );
}
