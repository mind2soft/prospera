<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Ledger } from '@/types';

const props = defineProps<{
    ledgers: Ledger[];
    selectedId: number | null;
    /** Permission of the selected ledger for the current user. */
    permission: string | null;
    /** Whether the current user can create ledgers in this profile. */
    canCreate?: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:selectedId', value: number): void;
    (e: 'close'): void;
    (e: 'reopen'): void;
    (e: 'create-next'): void;
    (e: 'share'): void;
    (e: 'remove'): void;
}>();

const { t } = useI18n();

const menuOpen = ref(false);

const selected = computed(
    () => props.ledgers.find((l) => l.id === props.selectedId) ?? null,
);
const isClosed = computed(() => selected.value?.status === 'closed');
const isOwner = computed(() => props.permission === 'owner');

const label = (ledger: Ledger): string => {
    const state =
        ledger.status === 'closed'
            ? t('ledger.status.closed')
            : t('ledger.status.open');
    return `${ledger.name} (${state})`;
};
</script>

<template>
    <div class="flex items-center justify-between gap-2 sm:gap-4">
        <Select
            :model-value="selectedId ?? undefined"
            :disabled="loading || ledgers.length < 2"
            @update:model-value="
                (value) => emit('update:selectedId', Number(value))
            "
        >
            <SelectTrigger
                class="w-full min-w-0 sm:w-64"
                :aria-label="$t('ledger.selector.selectLedger')"
            >
                <SelectValue>
                    <span
                        v-if="selected"
                        class="flex items-center gap-2"
                        data-testid="selected-ledger"
                    >
                        {{ selected.name }}
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                isClosed
                                    ? 'bg-muted text-muted-foreground'
                                    : 'bg-success/10 text-success'
                            "
                        >
                            <Icon
                                :name="
                                    isClosed ? 'lock' : 'check-circle-outline'
                                "
                                class="size-3"
                                aria-hidden="true"
                            />
                            {{
                                isClosed
                                    ? $t('ledger.status.closed')
                                    : $t('ledger.status.open')
                            }}
                        </span>
                    </span>
                    <span v-else>{{
                        loading
                            ? $t('common.loading')
                            : $t('ledger.selector.selectLedger')
                    }}</span>
                </SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-for="l in ledgers" :key="l.id" :value="l.id">
                    {{ label(l) }}
                </SelectItem>
            </SelectContent>
        </Select>

        <DropdownMenu v-model:open="menuOpen">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="rounded-xl"
                    :aria-label="$t('ledger.selector.actions')"
                >
                    <Icon name="more-vert" class="size-5" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <template v-if="isOwner">
                    <DropdownMenuItem
                        @click="
                            menuOpen = false;
                            if (isClosed) {
                                emit('reopen');
                            } else {
                                emit('close');
                            }
                        "
                    >
                        <Icon
                            :name="isClosed ? 'lock-open' : 'lock'"
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{
                            isClosed
                                ? $t('ledger.menu.reopen')
                                : $t('ledger.menu.close')
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        @click="
                            menuOpen = false;
                            emit('remove');
                        "
                    >
                        <Icon name="delete" class="size-4" aria-hidden="true" />
                        {{ $t('ledger.menu.delete') }}
                    </DropdownMenuItem>
                </template>
                <DropdownMenuSeparator
                    v-if="isClosed && canCreate && !isOwner"
                />
                <DropdownMenuItem
                    v-if="isClosed && canCreate"
                    @click="
                        menuOpen = false;
                        emit('create-next');
                    "
                >
                    <Icon
                        name="add"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ $t('ledger.menu.createNext') }}
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
                    {{ $t('ledger.menu.share') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
