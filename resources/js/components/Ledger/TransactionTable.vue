<!-- biome-ignore-all lint/a11y/noNoninteractiveTabindex: the table scroll region must be keyboard-focusable to scroll (WCAG 2.1.1) -->
<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import Currency from '@/components/Currency.vue';
import EnvelopeChip from '@/components/EnvelopeChip.vue';
import Icon from '@/components/Icon.vue';
import TransactionNote from '@/components/Ledger/TransactionNote.vue';
import TransactionNoteInput from '@/components/Ledger/TransactionNoteInput.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTransactionNoteSuggestions } from '@/composables/ledgerQueries';
import { getInitials } from '@/composables/useInitials';
import { useLocale } from '@/composables/useLocale';
import { formatCalendarDate } from '@/lib/calendar';
import { canDeleteTransaction } from '@/lib/transactionPermissions';
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
    notePending?: boolean;
    /** The month's transactions are still loading (no data yet). */
    loading?: boolean;
    /** The month's transactions failed to load — never shown as "no transactions". */
    error?: boolean;
    /** Extra guidance under the empty message (first-run setup). */
    emptyHint?: string;
}>();

const emit = defineEmits<{
    remove: [transactionId: number];
    'update-note': [transactionId: number, note: string | null];
    retry: [];
}>();

const editingTransaction = ref<Transaction | null>(null);
const noteDraft = ref('');

/** The edited row's envelope suggests its recent notes too. */
const editSuggestions = useTransactionNoteSuggestions(
    () => editingTransaction.value?.ledger_id ?? null,
    () => editingTransaction.value?.envelope_id ?? null,
);

const editNote = (transaction: Transaction) => {
    editingTransaction.value = transaction;
    noteDraft.value = transaction.note ?? '';
};

const saveNote = () => {
    if (editingTransaction.value === null || props.notePending) return;
    emit(
        'update-note',
        editingTransaction.value.id,
        noteDraft.value.trim() || null,
    );
    editingTransaction.value = null;
};

const canDelete = computed(
    () => (props.open ?? true) && props.permission !== null,
);

const mayDelete = (transaction: Transaction): boolean => {
    return canDeleteTransaction(
        transaction,
        props.permission,
        props.currentUserId,
        props.open ?? true,
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
                    <th v-if="canDelete" class="w-24 px-4 py-2.5">
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
                        <Badge
                            v-if="transaction.scheduled"
                            variant="secondary"
                            class="mt-1"
                            data-testid="scheduled-badge"
                        >
                            <Icon name="schedule" aria-hidden="true" />
                            {{ $t('ledger.table.scheduled') }}
                            <span class="sr-only">{{
                                $t('ledger.table.scheduleManaged')
                            }}</span>
                        </Badge>
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <span
                            v-if="transaction.masked"
                            class="px-1 text-muted-foreground"
                            ><span aria-hidden="true">—</span
                            ><span class="sr-only">{{
                                $t('ledger.table.amountHidden')
                            }}</span></span
                        >
                        <TransactionNote
                            v-else-if="transaction.note"
                            :note="transaction.note"
                        >
                            <Currency
                                :amount="transaction.amount ?? 0"
                                :currency="currency"
                            />
                        </TransactionNote>
                        <!-- Same box as the note trigger (padding + icon
                             slot), so every amount shares one right edge. -->
                        <span
                            v-else
                            class="inline-flex min-h-7 items-center gap-1 px-1"
                            data-testid="transaction-amount"
                        >
                            <span class="size-4 shrink-0" aria-hidden="true" />
                            <Currency
                                :amount="transaction.amount ?? 0"
                                :currency="currency"
                            />
                        </span>
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
                            <span
                                v-if="transaction.scheduled"
                                class="text-xs text-muted-foreground"
                                >{{
                                    $t('ledger.table.system')
                                }}</span
                            >
                            <Tooltip v-else-if="transaction.user.id !== null">
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
                    <td v-if="canDelete" class="px-4 py-2.5">
                        <div class="flex items-center justify-end gap-1">
                            <Button
                                v-if="
                                    mayDelete(transaction) &&
                                    !transaction.masked
                                "
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    $t(
                                        transaction.note
                                            ? 'ledger.table.editNoteFor'
                                            : 'ledger.table.writeNoteFor',
                                        {
                                            envelope: transaction.name,
                                            date: dayLabel(
                                                transaction.event_at,
                                            ),
                                        },
                                    )
                                "
                                :disabled="notePending"
                                @click="editNote(transaction)"
                            >
                                <Icon
                                    name="edit-note"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
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
                        </div>
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

    <Dialog
        :open="editingTransaction !== null"
        @update:open="(open) => !open && (editingTransaction = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('ledger.noteDialog.title') }}</DialogTitle>
                <DialogDescription>{{
                    $t('ledger.noteDialog.description')
                }}</DialogDescription>
            </DialogHeader>
            <TransactionNoteInput
                id="transaction-note-edit"
                v-model="noteDraft"
                :rows="3"
                :suggestions="editSuggestions.data.value ?? []"
            />
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="notePending || !noteDraft"
                    @click="noteDraft = ''"
                >
                    {{ $t('ledger.noteDialog.clear') }}
                </Button>
                <Button :disabled="notePending" @click="saveNote">
                    {{ $t('common.actions.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
