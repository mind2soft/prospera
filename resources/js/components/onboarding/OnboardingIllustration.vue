<script setup lang="ts">
import { type Component, computed } from 'vue';
import Icon from '@/components/Icon.vue';
import BudgetDialIllustration from '@/components/onboarding/illustrations/BudgetDialIllustration.vue';
import EnvelopeFlowIllustration from '@/components/onboarding/illustrations/EnvelopeFlowIllustration.vue';
import LedgerPeriodIllustration from '@/components/onboarding/illustrations/LedgerPeriodIllustration.vue';
import TransactionFlowIllustration from '@/components/onboarding/illustrations/TransactionFlowIllustration.vue';
import type { IllustrationName } from '@/lib/helpGuides';

const props = defineProps<{
    name: IllustrationName;
    /** Dimmed, with a lock badge: the step it shows is not unlocked yet. */
    locked?: boolean;
}>();

const components: Record<IllustrationName, Component> = {
    envelopeFlow: EnvelopeFlowIllustration,
    ledgerPeriod: LedgerPeriodIllustration,
    transactionFlow: TransactionFlowIllustration,
    budgetDial: BudgetDialIllustration,
};

const illustration = computed(() => components[props.name]);
</script>

<template>
    <div class="relative">
        <component
            :is="illustration"
            :variant="locked ? 'locked' : 'default'"
        />
        <span
            v-if="locked"
            class="absolute -end-1 -top-1 grid size-8 place-items-center rounded-full border bg-card text-muted-foreground"
            aria-hidden="true"
        >
            <Icon name="lock" class="size-4" />
        </span>
    </div>
</template>
