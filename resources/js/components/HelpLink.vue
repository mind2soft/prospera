<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';

defineProps<{ topic: 'envelopes' | 'ledgers' | 'transactions' | 'budgets' }>();
</script>

<template>
    <Link
        :href="`/help/${topic}`"
        class="inline-flex size-10 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        :aria-label="$t(`onboarding.help.${topic}.title`)"
    >
        <CircleHelp class="size-5" aria-hidden="true" />
    </Link>
</template>
