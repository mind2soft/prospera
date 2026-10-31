<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpenText,
    ChartPie,
    Mail,
    Banknote,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const isSignedIn = computed(() => Boolean(page.props.auth?.user));

// Illustrative figures for the hero preview — not real data.
const envelopes = [
    { name: 'Housing', spent: 1450, planned: 1500, color: 'bg-sky-500' },
    { name: 'Groceries', spent: 412, planned: 650, color: 'bg-emerald-500' },
    { name: 'Transport', spent: 96, planned: 220, color: 'bg-amber-500' },
    { name: 'Fun', spent: 138, planned: 150, color: 'bg-rose-500' },
];

const money = (n: number) =>
    n.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

const features = [
    {
        icon: ChartPie,
        title: 'Budget before you spend',
        body: 'Plan each month by splitting your income into envelopes. Fixed bills and flexible spending, side by side.',
    },
    {
        icon: BookOpenText,
        title: 'A ledger that stays simple',
        body: 'Log expenses and revenues one line at a time. No double entry, no chart of accounts — just what happened.',
    },
    {
        icon: Mail,
        title: 'Envelopes you can see',
        body: 'Color-coded envelopes show at a glance what is left. When one is empty, you know it is time to stop.',
    },
    {
        icon: Users,
        title: 'Finances, shared',
        body: 'Invite a partner or family into a shared profile, with owner, contributor and viewer roles per resource.',
    },
];
</script>

<template>
    <Head title="The envelope system, reimagined" />

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
                        class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand to-emerald-700 text-white shadow-sm shadow-brand/30"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span class="font-display text-2xl tracking-tight"
                        >Prospera</span
                    >
                </Link>

                <div class="flex items-center gap-2 text-sm">
                    <Link
                        v-if="isSignedIn"
                        :href="dashboard()"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full bg-primary px-4 font-medium text-primary-foreground transition hover:opacity-90"
                    >
                        Open dashboard
                        <ArrowRight class="size-4" />
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex h-9 items-center rounded-full px-4 font-medium text-muted-foreground transition hover:text-foreground"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex h-9 items-center rounded-full bg-primary px-4 font-medium text-primary-foreground transition hover:opacity-90"
                        >
                            Get started
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
                        The envelope system, reimagined
                    </p>
                    <h1
                        class="mt-6 font-display text-5xl leading-[1.05] tracking-tight text-balance sm:text-6xl lg:text-7xl"
                    >
                        Give every dollar
                        <em
                            class="bg-gradient-to-r from-brand to-teal-500 bg-clip-text pr-1 text-transparent"
                            >a purpose.</em
                        >
                    </h1>
                    <p
                        class="mx-auto mt-6 max-w-lg text-lg text-pretty text-muted-foreground lg:mx-0"
                    >
                        Prospera puts your money into envelopes — one per
                        purpose — so you always know what is left to spend.
                        Calm, clear budgeting without the spreadsheet.
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
                                    ? 'Open dashboard'
                                    : 'Start budgeting — free'
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
                            I already have an account
                        </Link>
                    </div>
                </div>

                <!-- Product preview -->
                <div
                    class="relative mx-auto w-full max-w-md"
                    aria-hidden="true"
                >
                    <div
                        class="absolute -inset-6 -z-10 rounded-[2rem] bg-gradient-to-br from-brand/25 via-transparent to-sky-400/20 blur-2xl"
                    />
                    <div
                        class="rounded-2xl border bg-card/90 p-6 shadow-2xl ring-1 shadow-black/5 ring-black/[0.02] backdrop-blur"
                    >
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm text-muted-foreground"
                                >October</span
                            >
                            <span
                                class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand"
                                >On track</span
                            >
                        </div>
                        <p class="mt-3 text-sm text-muted-foreground">
                            Left to spend
                        </p>
                        <p
                            class="font-display text-5xl tracking-tight tabular-nums"
                        >
                            {{ money(4200 - 2096) }}
                        </p>

                        <ul class="mt-6 space-y-4 pb-6">
                            <li v-for="e in envelopes" :key="e.name">
                                <div
                                    class="mb-1.5 flex items-center justify-between text-sm"
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="e.color"
                                        />
                                        {{ e.name }}
                                    </span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                    >
                                        {{ money(e.planned - e.spent) }} left
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
                            <p class="font-medium">Paycheck received</p>
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
                        Everything a household budget needs.
                        <span class="text-muted-foreground"
                            >Nothing an accountant does.</span
                        >
                    </h2>

                    <div
                        class="mt-14 grid gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div
                            v-for="f in features"
                            :key="f.title"
                            class="bg-background p-7 transition hover:bg-card"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand"
                            >
                                <component :is="f.icon" class="size-5" />
                            </span>
                            <h3 class="mt-5 font-medium">{{ f.title }}</h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ f.body }}
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
                            Know where your money goes — before it goes.
                        </h2>
                        <Link
                            :href="isSignedIn ? dashboard() : register()"
                            class="group mt-10 inline-flex h-12 items-center gap-2 rounded-full bg-background px-6 text-sm font-medium text-foreground transition hover:-translate-y-px"
                        >
                            {{
                                isSignedIn
                                    ? 'Open dashboard'
                                    : 'Create your free account'
                            }}
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-0.5"
                            />
                        </Link>
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
                <span
                    >&copy; {{ new Date().getFullYear() }} Prospera. Money,
                    envelopes, clarity.</span
                >
            </div>
        </footer>
    </div>
</template>
