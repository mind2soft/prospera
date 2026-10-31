<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -top-48 left-1/2 h-[36rem] w-[60rem] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,oklch(0.85_0.12_165/0.35),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,oklch(0.45_0.12_165/0.3),transparent)]"
        />

        <div class="relative w-full max-w-sm">
            <Link
                :href="home()"
                class="mx-auto mb-8 flex w-fit items-center gap-2.5"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-gradient-to-br from-brand to-emerald-700 text-white shadow-sm shadow-brand/30"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span class="font-display text-2xl tracking-tight"
                    >Prospera</span
                >
            </Link>

            <div
                class="rounded-2xl border bg-card p-8 shadow-xl shadow-black/[0.04]"
            >
                <div class="mb-8 space-y-1.5 text-center">
                    <h1 class="font-display text-3xl tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm text-balance text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
