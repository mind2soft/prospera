<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GithubIcon from '@/components/GithubIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

const page = usePage();
const isSignedIn = computed(() => Boolean(page.props.auth?.user));
const githubRepoUrl = computed(() => page.props.githubRepoUrl);
</script>

<template>
    <Head :title="$t('contact.sent.title')" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b">
            <nav
                class="mx-auto flex h-16 max-w-3xl items-center justify-between px-6"
            >
                <Link
                    :href="isSignedIn ? dashboard() : '/'"
                    class="flex items-center gap-2.5"
                >
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

        <main class="flex flex-1 items-center justify-center px-6 py-12">
            <div class="w-full max-w-md text-center">
                <span
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-success/10 text-success"
                >
                    <svg
                        aria-hidden="true"
                        class="size-7"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        viewBox="0 0 24 24"
                    >
                        <path
                            d="m5 13 4 4L19 7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                <h1 class="mt-6 font-display text-3xl tracking-tight">
                    {{ $t('contact.sent.title') }}
                </h1>
                <p class="mt-3 text-pretty text-muted-foreground">
                    {{ $t('contact.sent.body') }}
                </p>
                <Button as-child class="mt-8">
                    <Link :href="isSignedIn ? dashboard() : '/'">
                        {{ $t('contact.sent.backHome') }}
                    </Link>
                </Button>
            </div>
        </main>
    </div>
</template>
