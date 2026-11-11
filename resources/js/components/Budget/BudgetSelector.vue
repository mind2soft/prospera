<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/composables/useLocale';
import { budgetLabel } from '@/lib/budget';
import type { Budget } from '@/types';

const props = defineProps<{
    budgets: Budget[];
    selectedId: number | null;
    permission: string | null;
    /** Whether the user owns the current financial profile. */
    isProfileOwner: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:selectedId', value: number): void;
    (e: 'publish'): void;
    (e: 'new-budget'): void;
    (e: 'share'): void;
    (e: 'remove'): void;
}>();

const menuOpen = ref(false);

const { t } = useI18n();
const { locale } = useLocale();
const label = (budget: Budget): string =>
    budgetLabel(budget, locale.value, t('budget.label.draft'));

const selected = computed(
    () => props.budgets.find((b) => b.id === props.selectedId) ?? null,
);
const isDraft = computed(() => selected.value?.published_at === null);
</script>

<template>
    <div class="flex items-center justify-between gap-2 sm:gap-4">
        <Select
            :model-value="selectedId ?? undefined"
            :disabled="loading || budgets.length < 2"
            @update:model-value="
                (value) => emit('update:selectedId', Number(value))
            "
        >
            <SelectTrigger
                class="w-full min-w-0 sm:w-56"
                :aria-label="$t('budget.selector.selectBudget')"
            >
                <SelectValue>
                    {{
                        selected
                            ? label(selected)
                            : loading
                              ? $t('common.loading')
                              : $t('budget.selector.selectBudget')
                    }}
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-for="b in budgets" :key="b.id" :value="b.id">
                    {{ label(b) }}
                </SelectItem>
            </SelectContent>
        </Select>

        <DropdownMenu v-model:open="menuOpen">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="rounded-xl"
                    :aria-label="$t('budget.selector.actions')"
                >
                    <Icon name="more-vert" class="size-5" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem
                    v-if="isDraft && isProfileOwner && permission === 'owner'"
                    @click="
                        menuOpen = false;
                        emit('publish');
                    "
                >
                    <Icon
                        name="publish"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ $t('budget.selector.publish') }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="!isDraft && isProfileOwner"
                    @click="
                        menuOpen = false;
                        emit('new-budget');
                    "
                >
                    <Icon
                        name="add"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ $t('budget.selector.newBudget') }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    @click="
                        menuOpen = false;
                        emit('share');
                    "
                >
                    <Icon
                        name="group"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ $t('budget.selector.share') }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="permission === 'owner'"
                    variant="destructive"
                    @click="
                        menuOpen = false;
                        emit('remove');
                    "
                >
                    <Icon name="delete" class="size-4" aria-hidden="true" />
                    {{ $t('common.actions.delete') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
