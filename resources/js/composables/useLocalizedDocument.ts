import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watchEffect } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { FALLBACK_LOCALE } from '@/i18n';

/**
 * Long-form markdown content (e.g. the privacy policy — see
 * docs/localization.md), one file per locale under `resources/content/`
 * (`<name>.<locale>.md`). English is the source of truth: locales without a
 * translation fall back to the English file.
 *
 * The files are bundled as separate lazy chunks (`import.meta.glob`, not
 * eager) so the app bundle does not carry the copy, and they are rendered
 * client-side with a sanitized renderer — user-visible HTML must be inert.
 */

const FRONT_MATTER = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?/;

const modules = import.meta.glob<string>('../../content/*.md', {
    query: '?raw',
    import: 'default',
});

/**
 * `resources/content/<name>.<locale>.md` → the module loader for it.
 */
function contentModules(name: string): Record<string, () => Promise<string>> {
    const prefix = `../../content/${name}.`;
    const suffix = '.md';

    return Object.fromEntries(
        Object.entries(modules).filter(
            ([path]) => path.startsWith(prefix) && path.endsWith(suffix),
        ),
    );
}

export type LocalizedDocument = {
    /** Markdown body, front matter stripped; null while loading. */
    content: Ref<string | null>;
    /** The document's `last-updated` front-matter date, if present. */
    lastUpdated: Ref<Date | null>;
    /** The locale the content was resolved in (after the fallback). */
    resolvedLocale: ComputedRef<string>;
    isLoading: Ref<boolean>;
    /** True when the document files could not be loaded or parsed. */
    failed: Ref<boolean>;
};

/**
 * Load a localized markdown document for the active locale, falling back to
 * the English file. Returns reactive state that follows locale switches.
 */
export function useLocalizedDocument(name: string): LocalizedDocument {
    const { locale } = useLocale();

    const content = ref<string | null>(null);
    const lastUpdated = ref<Date | null>(null);
    const isLoading = ref(true);
    const failed = ref(false);

    const files = contentModules(name);

    /** Locales a file exists for, first by exact, then primary subtag. */
    const resolvedLocale = computed(() => {
        const available = Object.keys(files).map((path) =>
            path.slice(prefixLength(name), -3),
        );
        const pick = (tag: string) =>
            available.find(
                (candidate) => candidate.toLowerCase() === tag.toLowerCase(),
            ) ??
            available.find(
                (candidate) =>
                    candidate.toLowerCase() === tag.toLowerCase().split('-')[0],
            );

        return pick(locale.value) ?? pick(FALLBACK_LOCALE) ?? available[0];
    });

    let request = 0;

    watchEffect(async () => {
        const path = `../../content/${name}.${resolvedLocale.value}.md`;
        const load = files[path];

        if (!load) {
            isLoading.value = false;
            failed.value = true;

            return;
        }

        const token = ++request;
        isLoading.value = true;
        failed.value = false;

        try {
            const raw = await load();

            if (token !== request) {
                return; // superseded by a newer locale switch
            }

            const match = raw.match(FRONT_MATTER);
            const body = match ? raw.slice(match[0].length) : raw;

            const updated = match
                ? match[1].match(/^last-updated:\s*(.+)$/m)?.[1]?.trim()
                : undefined;
            const date = updated ? new Date(updated) : null;

            content.value = body;
            lastUpdated.value =
                date && !Number.isNaN(date.getTime()) ? date : null;
            isLoading.value = false;
        } catch {
            if (token !== request) {
                return;
            }

            failed.value = true;
            content.value = null;
            isLoading.value = false;
        }
    });

    return { content, lastUpdated, resolvedLocale, isLoading, failed };
}

function prefixLength(name: string): number {
    return `../../content/${name}.`.length;
}
