<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import Currency from '@/components/Currency.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import type { Transaction } from '@/types';

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
}>();

const emit = defineEmits<{
    (e: 'remove', transactionId: number): void;
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

const dayLabel = (eventAt: string): string => {
    const [year, month, day] = eventAt.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
};

const stampLabel = (createdAt: string): string =>
    new Date(createdAt).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
</script>

<template>
    <div class="overflow-hidden rounded-lg border">
        <table class="w-full text-sm">
            <thead>
                <tr
                    class="border-b bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <th class="px-4 py-2.5 font-medium">Day</th>
                    <th class="px-4 py-2.5 font-medium">Envelope</th>
                    <th class="px-4 py-2.5 text-right font-medium">Amount</th>
                    <th class="px-4 py-2.5 text-right font-medium">
                        Remaining
                    </th>
                    <th class="px-4 py-2.5 font-medium">User</th>
                    <th v-if="canDelete" class="w-12 px-4 py-2.5">
                        <span class="sr-only">Actions</span>
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
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: `${transaction.color}22`,
                                color: transaction.color,
                            }"
                        >
                            <Icon
                                :name="
                                    transaction.direction === 'revenue'
                                        ? 'north'
                                        : 'south'
                                "
                                class="size-3"
                                aria-hidden="true"
                            />
                            {{ transaction.name }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <span
                            v-if="transaction.masked"
                            class="text-muted-foreground"
                            >—</span
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
                            :amount="remainingFor(transaction)!"
                            :currency="currency"
                        />
                    </td>
                    <td class="px-4 py-2.5">
                        <TooltipProvider :delay-duration="0">
                            <Tooltip v-if="transaction.user.id !== null">
                                <TooltipTrigger as-child>
                                    <Avatar class="size-7">
                                        <AvatarFallback class="text-[10px]">
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
                                        Recorded
                                        {{ stampLabel(transaction.created_at) }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                            <span
                                v-else
                                class="text-xs text-muted-foreground italic"
                                >Former member</span
                            >
                        </TooltipProvider>
                    </td>
                    <td v-if="canDelete" class="px-4 py-2.5 text-right">
                        <Button
                            v-if="mayDelete(transaction)"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:bg-destructive hover:text-destructive"
                            aria-label="Remove transaction"
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
                <tr v-if="transactions.length === 0">
                    <td
                        :colspan="canDelete ? 6 : 5"
                        class="px-4 py-8 text-center text-muted-foreground"
                    >
                        No transactions this month.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
