<script setup lang="ts">
import Icon from '@/components/Icon.vue';

defineProps<{
    /** `done: null` — a static requirement, no live status (read-only). */
    items: { label: string; value?: string; done: boolean | null }[];
}>();
</script>

<template>
    <ul class="grid gap-2">
        <li
            v-for="item in items"
            :key="item.label"
            class="flex items-start gap-2.5 text-sm"
        >
            <Icon
                :name="
                    item.done === null
                        ? 'flag-outline'
                        : item.done
                          ? 'check-circle'
                          : 'radio-button-unchecked'
                "
                class="mt-0.5 size-5 shrink-0"
                :class="item.done ? 'text-success' : 'text-muted-foreground'"
                aria-hidden="true"
            />
            <span class="flex-1">
                {{ item.label }}
                <span v-if="item.done !== null" class="sr-only"
                    >—
                    {{
                        item.done
                            ? $t('onboarding.requirements.done')
                            : $t('onboarding.requirements.notYet')
                    }}</span
                >
            </span>
            <span
                v-if="item.value"
                class="shrink-0 text-end text-muted-foreground tabular-nums"
                >{{
                    item.value
                }}</span
            >
        </li>
    </ul>
</template>
