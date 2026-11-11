<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppearance } from '@/composables/useAppearance';

const { t } = useI18n();
const { appearance, updateAppearance } = useAppearance();
const labelId = useId();

const onSelect = (value: unknown): void => {
    if (value === 'light' || value === 'dark' || value === 'system') {
        updateAppearance(value);
    }
};

const tabs = computed(
    () =>
        [
            {
                value: 'light',
                Icon: Sun,
                label: t('settings.appearance.light'),
            },
            { value: 'dark', Icon: Moon, label: t('settings.appearance.dark') },
            {
                value: 'system',
                Icon: Monitor,
                label: t('settings.appearance.system'),
            },
        ] as const,
);
</script>

<template>
    <div class="grid gap-2">
        <span :id="labelId" class="text-sm font-medium">
            {{ $t('settings.appearance.theme') }}
        </span>
        <!-- A single choice among three: radio semantics expose the selected
             option (not only its styling). -->
        <RadioGroupRoot
            :model-value="appearance"
            :aria-labelledby="labelId"
            orientation="horizontal"
            class="inline-flex w-fit flex-wrap gap-1 rounded-lg bg-muted p-1"
            @update:model-value="onSelect"
        >
            <RadioGroupItem
                v-for="{ value, Icon, label } in tabs"
                :key="value"
                :value="value"
                class="flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background data-[state=checked]:bg-background data-[state=checked]:text-foreground data-[state=checked]:shadow-xs data-[state=checked]:ring-1 data-[state=checked]:ring-border"
            >
                <component :is="Icon" class="-ml-1 size-4" aria-hidden="true" />
                {{ label }}
            </RadioGroupItem>
        </RadioGroupRoot>
    </div>
</template>
