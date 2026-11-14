<!-- biome-ignore-all lint/a11y/noNoninteractiveTabindex: the table scroll region must be keyboard-focusable to scroll (WCAG 2.1.1) -->
<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import Currency from '@/components/Currency.vue';
import EnvelopeChip from '@/components/EnvelopeChip.vue';
import Icon from '@/components/Icon.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { useLocale } from '@/composables/useLocale';
import { formatCalendarDate } from '@/lib/calendar';
import type { Transaction } from '@/types';

const { locale } = useLocale();

const props = defineProps<{
    transactions: Transaction[];
    currency: string;
    /** Id of the signed-in user (own-entry deletion for contributors). */
    currentUserId: number;
    /**
     * Permission of the current user on the ledger: the owner can delete any
     * transaction, a contributor only their own entries, a viewer none.
     */
    permission: string | null;
    /** True while the ledger is open (deletion is impossible when closed). */
    open?: boolean;
    pendingId?: number | null;
    /** The month's transactions are still loading (no data yet). */
    loading?: boolean;
    /** The month's transactions failed to load — never shown as "no transactions". */
    error?: boolean;
    /** Extra guidance under the empty message (first-run setup). */
    emptyHint?: string;
}>();

const emit = defineEmits<{
    remove: [transactionId: number];
    retry: [];
}>();

const canDelete = computed(
    () => (props.open ?? true) && props.permission !== null,
);

const mayDelete = (transaction: Transaction): boolean => {
    if (!canDelete.value) {
        return false;
    }
    if (props.permission === 'owner') {
        return true;
    }
    return (
        props.permission === 'contributor' &&
        transaction.user.id !== null &&
        transaction.user.id === props.currentUserId
    );
};

/**
 * The server computes "remaining allocated" for the displayed month against
 * that month's published budget (null when masked or unallocated).
 * Expense rows are flipped so the value reads as "money left".
 */
const remainingFor = (transaction: Transaction): number | null => {
    const remaining = transaction.remaining_allocated;
    if (transaction.masked || remaining === null || remaining === undefined) {
        return null;
    }
    return transaction.direction === 'expense' ? -remaining : remaining;
};

const dayLabel = (eventAt: string): string =>
    formatCalendarDate(eventAt, locale.value, {
        month: 'short',
        day: 'numeric',
    });

const stampLabel = (createdAt: string): string =>
    new Date(createdAt).toLocaleString(locale.value, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

const region = useTemplateRef<HTMLElement>('region');

/**
 * Focuses the table region — the stable target once a row's delete action
 * disappears with its row (focus would otherwise drop to the page body).
 */
defineExpose({
    focus: () => region.value?.focus(),
    /** Whether the element is the table region or inside it. */
    contains: (element: Element | null): boolean =>
        element !== null && (region.value?.contains(element) ?? false),
});
</script>

<template>
    <!-- A labelled, focusable scroll region: on narrow screens the columns
         (including the delete action) scroll instead of being clipped. -->
    <section
        ref="region"
        class="overflow-x-auto rounded-lg border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        :aria-label="$t('ledger.table.label')"
        tabindex="0"
    >
        <table class="w-full min-w-xl text-sm">
            <thead>
                <tr
                    class="border-b bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <th class="px-4 py-2.5 font-medium">
                        {{ $t('ledger.table.day') }}
                    </th>
                    <th class="px-4 py-2.5 font-medium">
                        {{ $t('ledger.table.envelope') }}
                    </th>
                    <th class="px-4 py-2.5 text-right font-medium">
                        {{ $t('ledger.table.amount') }}
                    </th>
                    <th class="px-4 py-2.5 text-right font-medium">
                        {{ $t('ledger.table.remaining') }}
                    </th>
                    <th class="px-4 py-2.5 font-medium">
                        {{ $t('ledger.table.user') }}
                    </th>
                    <th v-if="canDelete" class="w-12 px-4 py-2.5">
                        <span class="sr-only">{{
                            $t('ledger.table.actions')
                        }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="transaction in transactions"
                    :key="transaction.id"
                    class="border-b last:border-0"
                >
                    <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">
                        {{ dayLabel(transaction.event_at) }}
                    </td>
                    <td class="px-4 py-2.5">
                        <EnvelopeChip
                            :name="transaction.name"
                            :color="transaction.color"
                            :direction="transaction.direction"
                        />
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <span
                            v-if="transaction.masked"
                            class="text-muted-foreground"
                            ><span aria-hidden="true">—</span
                            ><span class="sr-only">{{
                                $t('ledger.table.amountHidden')
                            }}</span></span
                        >
                        <Currency
                            v-else
                            :amount="transaction.amount ?? 0"
                            :currency="currency"
                        />
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <span
                            v-if="remainingFor(transaction) === null"
                            class="text-muted-foreground"
                            >—</span
                        >
                        <Currency
                            v-else
                            :amount="remainingFor(transaction) ?? 0"
                            :currency="currency"
                        />
                    </td>
                    <td class="px-4 py-2.5">
                        <TooltipProvider :delay-duration="0">
                            <Tooltip v-if="transaction.user.id !== null">
                                <TooltipTrigger
                                    class="rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                                    :aria-label="
                                        $t('ledger.table.recordedBy', {
                                            name: transaction.user.name,
                                            date: stampLabel(
                                                transaction.created_at,
                                            ),
                                        })
                                    "
                                >
                                    <Avatar class="size-7" aria-hidden="true">
                                        <AvatarFallback class="text-xs">
                                            {{
                                                getInitials(
                                                    transaction.user.name,
                                                )
                                            }}
                                        </AvatarFallback>
                                    </Avatar>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p class="font-medium">
                                        {{ transaction.user.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ $t('ledger.table.recorded') }}
                                        {{ stampLabel(transaction.created_at) }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                            <span
                                v-else
                                class="text-xs text-muted-foreground italic"
                                >{{
                                    $t('ledger.table.formerMember')
                                }}</span
                            >
                        </TooltipProvider>
                    </td>
                    <td v-if="canDelete" class="px-4 py-2.5 text-right">
                        <Button
                            v-if="mayDelete(transaction)"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="
                                $t('ledger.table.deleteTransactionFor', {
                                    envelope: transaction.name,
                                    date: dayLabel(transaction.event_at),
                                })
                            "
                            :disabled="pendingId === transaction.id"
                            @click="emit('remove', transaction.id)"
                        >
                            <Icon
                                name="delete"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </Button>
                    </td>
                </tr>
                <tr v-if="error">
                    <td
                        :colspan="canDelete ? 6 : 5"
                        class="px-4 py-8 text-center"
                        role="alert"
                    >
                        <span class="block text-muted-foreground">
                            {{ $t('ledger.table.error') }}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            class="mt-3"
                            @click="emit('retry')"
                        >
                            {{ $t('common.actions.retry') }}
                        </Button>
                    </td>
                </tr>
                <tr v-else-if="loading">
                    <td
                        :colspan="canDelete ? 6 : 5"
                        class="px-4 py-8 text-center text-muted-foreground"
                        role="status"
                    >
                        {{ $t('ledger.table.loading') }}
                    </td>
                </tr>
                <tr v-else-if="transactions.length === 0">
                    <td
                        :colspan="canDelete ? 6 : 5"
                        class="px-4 py-8 text-center text-muted-foreground"
                    >
                        {{ $t('ledger.table.empty') }}
                        <p
                            v-if="emptyHint"
                            class="mt-1 text-sm text-foreground"
                        >
                            {{ emptyHint }}
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
