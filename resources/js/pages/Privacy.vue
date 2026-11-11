<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GithubIcon from '@/components/GithubIcon.vue';
import { useLocale } from '@/composables/useLocale';
import { useLocalizedDocument } from '@/composables/useLocalizedDocument';

/**
 * Public privacy policy page (issue #18). The long-form copy is NOT part of
 * the i18n JSON messages: it lives in per-locale markdown documents under
 * `resources/content/` (see docs/localization.md), loaded separately here and
 * rendered client-side through a sanitized renderer (marked + DOMPurify) —
 * user-visible HTML must be inert.
 */
const page = usePage();
const githubRepoUrl = computed(() => page.props.githubRepoUrl);

const { locale } = useLocale();
const { content, lastUpdated, isLoading, failed } =
    useLocalizedDocument('privacy');

const formattedDate = computed(() =>
    lastUpdated.value
        ? lastUpdated.value.toLocaleDateString(locale.value, {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
              // Front-matter dates are plain dates (parsed as UTC
              // midnight); format them back in UTC so the displayed
              // date matches the published one in every timezone.
              timeZone: 'UTC',
          })
        : null,
);

/**
 * Markdown → sanitized HTML. The policy content is first rendered by
 * `marked`, then passed through DOMPurify: every construct that is not
 * benign formatted text (scripts, event handlers, javascript: URLs, …) is
 * stripped, so even a tampered document stays inert.
 */
const html = computed(() => {
    if (!content.value) {
        return '';
    }

    return DOMPurify.sanitize(marked.parse(content.value, { async: false }), {
        USE_PROFILES: { html: true },
    });
});
</script>

<template>
    <Head :title="$t('privacy.title')" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <!-- Navigation -->
        <header class="border-b">
            <nav
                class="mx-auto flex h-16 max-w-3xl items-center justify-between px-6"
            >
                <Link href="/" class="flex items-center gap-2.5">
                    <span
                        class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand to-emerald-700 text-white shadow-sm shadow-brand/30"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span class="font-display text-2xl tracking-tight"
                        >Prospera</span
                    >
                </Link>
                <a
                    :href="githubRepoUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    :title="$t('contact.tooltip')"
                    :aria-label="$t('contact.tooltip')"
                >
                    <GithubIcon class="size-5" />
                </a>
            </nav>
        </header>

        <main class="flex flex-1 items-start justify-center px-6 py-12">
            <div class="w-full max-w-3xl" data-test="privacy-page">
                <h1 class="font-display text-3xl tracking-tight">
                    {{ $t('privacy.title') }}
                </h1>

                <div
                    v-if="formattedDate"
                    class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                >
                    <span data-test="privacy-last-updated">
                        {{ $t('privacy.lastUpdated', { date: formattedDate }) }}
                    </span>
                </div>

                <div
                    class="mt-4 flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-muted-foreground"
                    data-test="privacy-wip"
                >
                    {{ $t('privacy.wip') }}
                </div>

                <p class="mt-6 text-muted-foreground">
                    {{ $t('privacy.notice') }}
                </p>

                <!-- Loading state -->
                <div
                    v-if="isLoading"
                    class="mt-8 space-y-4"
                    data-test="privacy-loading"
                    aria-busy="true"
                >
                    <div
                        v-for="i in 6"
                        :key="i"
                        class="h-4 animate-pulse rounded bg-muted"
                        :class="i % 3 === 0 ? 'w-2/3' : 'w-full'"
                    />
                </div>

                <!-- Error state -->
                <div
                    v-else-if="failed"
                    class="mt-8 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    data-test="privacy-error"
                >
                    {{ $t('common.error') }}
                </div>

                <!-- Sanitized markdown content -->
                <article
                    v-else
                    class="privacy-markdown mt-8"
                    data-test="privacy-content"
                    v-html="html"
                />
            </div>
        </main>
    </div>
</template>

<style scoped>
/*
 * Long-form prose styling (no typography plugin): scoped to the rendered
 * markdown article, locale-agnostic, and consistent with the app's look.
 */
.privacy-markdown :deep(h2) {
    margin-top: 2.5rem;
    font-size: 1.5rem;
    line-height: 2rem;
    letter-spacing: -0.025em;
    font-weight: 600;
}

.privacy-markdown :deep(h3) {
    margin-top: 2rem;
    font-size: 1.125rem;
    font-weight: 600;
}

.privacy-markdown :deep(p) {
    margin-top: 1rem;
    line-height: 1.75;
}

.privacy-markdown :deep(ul) {
    margin-top: 1rem;
    padding-inline-start: 1.5rem;
    list-style: disc;
    line-height: 1.75;
}

.privacy-markdown :deep(ol) {
    margin-top: 1rem;
    padding-inline-start: 1.5rem;
    list-style: decimal;
    line-height: 1.75;
}

.privacy-markdown :deep(li + li) {
    margin-top: 0.375rem;
}

.privacy-markdown :deep(strong) {
    font-weight: 600;
    color: var(--foreground);
}

.privacy-markdown :deep(a) {
    font-weight: 500;
    color: var(--color-brand);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.privacy-markdown :deep(blockquote) {
    margin-top: 1rem;
    border-inline-start: 2px solid var(--border);
    padding-inline-start: 1rem;
    font-style: italic;
    color: var(--muted-foreground);
}

.privacy-markdown :deep(code) {
    font-size: 0.875em;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0.125rem 0.375rem;
}

.privacy-markdown :deep(hr) {
    margin-top: 2rem;
    border-color: var(--border);
}
</style>
