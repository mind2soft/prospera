<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    BookOpenText,
    ChartPie,
    Mail,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GithubIcon from '@/components/GithubIcon.vue';
import { useLocale } from '@/composables/useLocale';
import { dashboard, login, privacy, register } from '@/routes';
import { show as contact } from '@/routes/contact';

const page = usePage();
const isSignedIn = computed(() => Boolean(page.props.auth?.user));
const githubRepoUrl = computed(() => page.props.githubRepoUrl);
const { locale } = useLocale();

// Illustrative figures for the hero preview — not real data.
const envelopes = [
    {
        nameKey: 'welcome.envelopes.housing',
        spent: 1450,
        planned: 1500,
        color: 'bg-sky-500',
    },
    {
        nameKey: 'welcome.envelopes.groceries',
        spent: 412,
        planned: 650,
        color: 'bg-emerald-500',
    },
    {
        nameKey: 'welcome.envelopes.transport',
        spent: 96,
        planned: 220,
        color: 'bg-amber-500',
    },
    {
        nameKey: 'welcome.envelopes.fun',
        spent: 138,
        planned: 150,
        color: 'bg-rose-500',
    },
];

const money = (n: number) =>
    n.toLocaleString(locale.value, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

const features = [
    {
        icon: ChartPie,
        titleKey: 'welcome.features.budgetFirst.title',
        bodyKey: 'welcome.features.budgetFirst.body',
    },
    {
        icon: BookOpenText,
        titleKey: 'welcome.features.simpleLedger.title',
        bodyKey: 'welcome.features.simpleLedger.body',
    },
    {
        icon: Mail,
        titleKey: 'welcome.features.visibleEnvelopes.title',
        bodyKey: 'welcome.features.visibleEnvelopes.body',
    },
    {
        icon: Users,
        titleKey: 'welcome.features.shared.title',
        bodyKey: 'welcome.features.shared.body',
    },
];
</script>

<template>
    <Head :title="$t('welcome.title')" />

    <div
        class="relative min-h-screen overflow-hidden bg-background text-foreground antialiased"
    >
        <!-- Ambient background -->
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div
                class="absolute -top-40 left-1/2 h-[42rem] w-[72rem] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,oklch(0.85_0.12_165/0.45),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,oklch(0.45_0.12_165/0.35),transparent)]"
            />
            <div
                class="absolute inset-0 [background-image:linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_top,black_20%,transparent_70%)] [background-size:56px_56px] opacity-50"
            />
        </div>

        <!-- Navigation -->
        <header
            class="sticky top-0 z-20 border-b border-transparent backdrop-blur-md supports-[backdrop-filter]:bg-background/60"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
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

                <div class="flex items-center gap-2 text-sm">
                    <a
                        :href="githubRepoUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        :title="$t('contact.tooltip')"
                        :aria-label="$t('contact.tooltip')"
                        data-test="github-button"
                    >
                        <GithubIcon class="size-5" />
                    </a>
                    <Link
                        v-if="isSignedIn"
                        :href="dashboard()"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full bg-primary px-4 font-medium text-primary-foreground transition hover:opacity-90"
                    >
                        {{ $t('welcome.nav.openDashboard') }}
                        <ArrowRight class="size-4" />
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex h-9 items-center rounded-full px-4 font-medium text-muted-foreground transition hover:text-foreground"
                        >
                            {{ $t('auth.login.submit') }}
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex h-9 items-center rounded-full bg-primary px-4 font-medium text-primary-foreground transition hover:opacity-90"
                        >
                            {{ $t('welcome.nav.getStarted') }}
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <main class="relative">
            <!-- Hero -->
            <section
                class="mx-auto grid max-w-6xl items-center gap-16 px-6 pt-16 pb-24 lg:grid-cols-[1.1fr_1fr] lg:pt-24"
            >
                <div class="text-center lg:text-left">
                    <p
                        class="inline-flex items-center gap-2 rounded-full border bg-background/70 px-3 py-1 text-xs font-medium text-muted-foreground shadow-xs backdrop-blur"
                    >
                        <span class="size-1.5 rounded-full bg-brand" />
                        {{ $t('welcome.hero.badge') }}
                    </p>
                    <h1
                        class="mt-6 font-display text-5xl leading-[1.05] tracking-tight text-balance sm:text-6xl lg:text-7xl"
                    >
                        {{ $t('welcome.hero.title') }}
                        <em
                            class="bg-linear-to-r from-brand to-teal-500 bg-clip-text pr-1 text-transparent"
                            >{{
                                $t('welcome.hero.emphasis')
                            }}</em
                        >
                    </h1>
                    <p
                        class="mx-auto mt-6 max-w-lg text-lg text-pretty text-muted-foreground lg:mx-0"
                    >
                        {{ $t('welcome.hero.description') }}
                    </p>
                    <div
                        class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row lg:justify-start"
                    >
                        <Link
                            :href="isSignedIn ? dashboard() : register()"
                            class="group inline-flex h-12 items-center gap-2 rounded-full bg-primary px-6 text-sm font-medium text-primary-foreground shadow-lg shadow-primary/20 transition hover:-translate-y-px hover:shadow-xl"
                        >
                            {{
                                isSignedIn
                                    ? $t('welcome.nav.openDashboard')
                                    : $t('welcome.hero.cta')
                            }}
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-0.5"
                            />
                        </Link>
                        <Link
                            v-if="!isSignedIn"
                            :href="login()"
                            class="inline-flex h-12 items-center rounded-full px-6 text-sm font-medium text-muted-foreground transition hover:text-foreground"
                        >
                            {{ $t('welcome.hero.existingAccount') }}
                        </Link>
                    </div>
                </div>

                <!-- Product preview -->
                <div
                    class="relative mx-auto w-full max-w-md"
                    aria-hidden="true"
                >
                    <div
                        class="absolute -inset-6 -z-10 rounded-[2rem] bg-linear-to-br from-brand/25 via-transparent to-sky-400/20 blur-2xl"
                    />
                    <div
                        class="rounded-2xl border bg-card/90 p-6 shadow-2xl ring-1 shadow-black/5 ring-black/[0.02] backdrop-blur"
                    >
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm text-muted-foreground">{{
                                $t('welcome.preview.month')
                            }}</span>
                            <span
                                class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand"
                                >{{
                                    $t('welcome.preview.onTrack')
                                }}</span
                            >
                        </div>
                        <p class="mt-3 text-sm text-muted-foreground">
                            {{ $t('welcome.preview.leftToSpend') }}
                        </p>
                        <p
                            class="font-display text-5xl tracking-tight tabular-nums"
                        >
                            {{ money(4200 - 2096) }}
                        </p>

                        <ul class="mt-6 space-y-4 pb-6">
                            <li v-for="e in envelopes" :key="e.nameKey">
                                <div
                                    class="mb-1.5 flex items-center justify-between text-sm"
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="e.color"
                                        />
                                        {{ $t(e.nameKey) }}
                                    </span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            $t('welcome.preview.left', {
                                                amount: money(
                                                    e.planned - e.spent,
                                                ),
                                            })
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="e.color"
                                        :style="{
                                            width: `${(e.spent / e.planned) * 100}%`,
                                        }"
                                    />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div
                        class="absolute -bottom-10 -left-8 hidden items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-xl sm:flex"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600"
                        >
                            <Banknote class="size-5" />
                        </span>
                        <div class="text-sm leading-tight">
                            <p class="font-medium">
                                {{ $t('welcome.preview.paycheckReceived') }}
                            </p>
                            <p class="text-muted-foreground tabular-nums">
                                +{{ money(2100) }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section class="border-t bg-muted/30">
                <div class="mx-auto max-w-6xl px-6 py-24">
                    <h2
                        class="max-w-2xl font-display text-4xl tracking-tight text-balance sm:text-5xl"
                    >
                        {{ $t('welcome.features.title') }}
                        <span class="text-muted-foreground">{{
                            $t('welcome.features.subtitle')
                        }}</span>
                    </h2>

                    <div
                        class="mt-14 grid gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div
                            v-for="f in features"
                            :key="f.titleKey"
                            class="bg-background p-7 transition hover:bg-card"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand"
                            >
                                <component :is="f.icon" class="size-5" />
                            </span>
                            <h3 class="mt-5 font-medium">
                                {{ $t(f.titleKey) }}
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ $t(f.bodyKey) }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Closing call to action -->
            <section class="mx-auto max-w-6xl px-6 py-24">
                <div
                    class="relative overflow-hidden rounded-3xl bg-primary px-8 py-16 text-center text-primary-foreground sm:px-16"
                >
                    <div
                        aria-hidden="true"
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,oklch(0.6_0.13_165/0.5),transparent_60%)]"
                    />
                    <div class="relative">
                        <h2
                            class="font-display text-4xl tracking-tight text-balance sm:text-5xl"
                        >
                            {{ $t('welcome.closing.title') }}
                        </h2>
                        <Link
                            :href="isSignedIn ? dashboard() : register()"
                            class="group mt-10 inline-flex h-12 items-center gap-2 rounded-full bg-background px-6 text-sm font-medium text-foreground transition hover:-translate-y-px"
                        >
                            {{
                                isSignedIn
                                    ? $t('welcome.nav.openDashboard')
                                    : $t('welcome.closing.cta')
                            }}
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-0.5"
                            />
                        </Link>
                    </div>
                </div>
            </section>
            <!-- Contact & open source -->
            <section
                class="mx-auto max-w-6xl px-6 pb-24"
                data-test="contact-section"
            >
                <div
                    class="grid gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-2"
                >
                    <div class="bg-background p-8">
                        <span
                            class="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand"
                        >
                            <Mail class="size-5" />
                        </span>
                        <h3 class="mt-5 font-display text-xl tracking-tight">
                            {{ $t('contact.landing.title') }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ $t('contact.landing.body') }}
                        </p>
                        <Link
                            :href="contact()"
                            class="mt-5 inline-flex h-9 items-center gap-1.5 rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground transition hover:opacity-90"
                            data-test="contact-cta"
                        >
                            {{ $t('contact.landing.cta') }}
                            <ArrowRight class="size-4" />
                        </Link>
                    </div>
                    <div class="bg-background p-8">
                        <span
                            class="flex size-10 items-center justify-center rounded-lg bg-muted text-foreground"
                        >
                            <GithubIcon class="size-5" />
                        </span>
                        <h3 class="mt-5 font-display text-xl tracking-tight">
                            {{ $t('contact.openSource.title') }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ $t('contact.openSource.body') }}
                        </p>
                        <a
                            :href="githubRepoUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-5 inline-flex h-9 items-center gap-1.5 rounded-full border px-4 text-sm font-medium transition hover:bg-muted"
                            data-test="github-link"
                        >
                            <GithubIcon class="size-4" />
                            {{ $t('contact.openSource.cta') }}
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="relative border-t">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-8 text-sm text-muted-foreground sm:flex-row"
            >
                <span class="font-display text-lg text-foreground"
                    >Prospera</span
                >
                <div class="flex items-center gap-4">
                    <Link
                        :href="contact()"
                        class="transition hover:text-foreground"
                        >{{
                            $t('contact.landing.cta')
                        }}</Link
                    >
                    <Link
                        :href="privacy()"
                        class="transition hover:text-foreground"
                        data-test="privacy-link"
                        >{{
                            $t('privacy.link')
                        }}</Link
                    >
                    <a
                        :href="githubRepoUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="transition hover:text-foreground"
                        >GitHub</a
                    >
                </div>
                <span
                    >&copy; {{ new Date().getFullYear() }}
                    {{ $t('welcome.footer.tagline') }}</span
                >
            </div>
        </footer>
    </div>
</template>
