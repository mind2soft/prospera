<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import AddTransactionForm from '@/components/Ledger/AddTransactionForm.vue';
import CreateLedgerDialog from '@/components/Ledger/CreateLedgerDialog.vue';
import LedgerSelector from '@/components/Ledger/LedgerSelector.vue';
import MonthCalendar from '@/components/Ledger/MonthCalendar.vue';
import TransactionTable from '@/components/Ledger/TransactionTable.vue';
import LedgerLoadingScreen from '@/components/Loading/LedgerLoadingScreen.vue';
import QueryErrorState from '@/components/QueryErrorState.vue';
import ShareDrawer from '@/components/share/ShareDrawer.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useBudgets } from '@/composables/budgetQueries';
import { useEnvelopeOptions } from '@/composables/envelopeQueries';
import {
    useAddTransaction,
    useCloseLedger,
    useCreateLedger,
    useDeleteLedger,
    useDeleteTransaction,
    useLedgerShares,
    useLedgers,
    useLedgerTransactions,
    useRemoveLedgerShare,
    useReopenLedger,
    useSetLedgerShare,
    useTransferLedgerOwnership,
} from '@/composables/ledgerQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLocale } from '@/composables/useLocale';
import { errorMessage } from '@/lib/api';
import { formatMoney } from '@/lib/budget';
import { formatCalendarDate, parseMonth } from '@/lib/calendar';
import type {
    AddTransactionInput,
    CreateLedgerInput,
    PickerEnvelope,
} from '@/lib/ledgerApi';
import { envelopes as envelopesRoute, ledger as ledgerRoute } from '@/routes';
import type { ResourcePermission, Transaction } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ledger.title', href: ledgerRoute() }],
    },
});

const { t } = useI18n();
const { locale } = useLocale();

// --- state -----------------------------------------------------------------
const isoToday = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const viewMonth = ref(`${isoToday().slice(0, 8)}01`); // first day of current month
const selectedDate = ref(isoToday());

/** Clamp `date` into the inclusive [min, max] ISO-date period. */
const clampDate = (date: string, min?: string, max?: string): string => {
    let clamped = date;
    if (min !== undefined && clamped < min) {
        clamped = min;
    }
    if (max !== undefined && clamped > max) {
        clamped = max;
    }
    return clamped;
};

const monthRange = computed(() => {
    const [year, month] = parseMonth(viewMonth.value);
    const first = new Date(year, month - 1, 1);
    const last = new Date(year, month, 0);
    const iso = (d: Date) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return { from: iso(first), to: iso(last) };
});

const onSelectDate = (date: string) => {
    selectedDate.value = date;
    if (date.slice(0, 8) !== viewMonth.value.slice(0, 8)) {
        viewMonth.value = `${date.slice(0, 8)}01`;
    }
};

const navigateMonth = (direction: 'prev' | 'next') => {
    const [year, month] = parseMonth(viewMonth.value);
    const date = new Date(year, month - 1 + (direction === 'next' ? 1 : -1), 1);
    const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
    viewMonth.value = iso;
    if (selectedDate.value.slice(0, 8) !== iso.slice(0, 8)) {
        const candidate =
            isoToday().slice(0, 8) === iso.slice(0, 8) ? isoToday() : iso;
        selectedDate.value = ledger.value
            ? clampDate(
                  candidate,
                  ledger.value.start_date,
                  ledger.value.end_date,
              )
            : candidate;
    }
};

// --- ledgers ---------------------------------------------------------------
const ledgersQuery = useLedgers();
const ledgers = computed(() => ledgersQuery.data.value ?? []);

const selectedLedgerId = ref<number | null>(null);
const ledgerId = computed(
    () => selectedLedgerId.value ?? ledgers.value[0]?.id ?? null,
);

watch(
    ledgers,
    (list) => {
        if (
            selectedLedgerId.value !== null &&
            list.some((l) => l.id === selectedLedgerId.value)
        ) {
            return;
        }
        selectedLedgerId.value = list[0]?.id ?? null;
    },
    { immediate: true },
);

const ledger = computed(
    () => ledgers.value.find((l) => l.id === ledgerId.value) ?? null,
);
const isClosed = computed(() => ledger.value?.status === 'closed');
const ledgerPermission = computed(() => ledger.value?.permission ?? null);

// Point the calendar at the most recent day of the selected ledger's period:
// today while the ledger is open, its first day when it opens in the future,
// and its last day once it has ended (issue #2).
watch(
    ledgerId,
    (id) => {
        const l = ledger.value;
        if (id === null || !l) {
            return;
        }
        selectedDate.value = clampDate(isoToday(), l.start_date, l.end_date);
        viewMonth.value = `${selectedDate.value.slice(0, 8)}01`;
    },
    { immediate: true },
);

const transactionsQuery = useLedgerTransactions(
    ledgerId,
    computed(() => monthRange.value.from),
    computed(() => monthRange.value.to),
);
const transactions = computed(() => transactionsQuery.data.value ?? []);

// --- envelope context -------------------------------------------------------
const budgetsQuery = useBudgets();
const envelopesQuery = useEnvelopeOptions();

/**
 * The transaction picker: every envelope on which the current user is owner
 * or contributor (docs/ledger.md — adding a transaction). No budget is
 * required — budgets are only needed for reporting.
 */
const pickerEnvelopes = computed<PickerEnvelope[]>(() =>
    (envelopesQuery.data.value ?? [])
        .filter(
            (option) =>
                option.permission === 'owner' ||
                option.permission === 'contributor',
        )
        .map((option) => ({
            envelope_id: option.id,
            name: option.name,
            color: option.color,
            direction: option.direction,
        })),
);

/** The latest published budget's currency — budgets are for reporting only. */
const currency = computed(() => {
    const published = (budgetsQuery.data.value ?? [])
        .filter((b) => b.published_at !== null)
        .sort(
            (a, b) =>
                new Date(b.published_at ?? '').getTime() -
                new Date(a.published_at ?? '').getTime(),
        );
    return published[0]?.currency ?? '';
});

const meId = computed(() => usePage().props.auth.user.id);

const mayAddTransactions = computed(() => {
    const permission = ledgerPermission.value;
    if (permission === 'owner') {
        return true;
    }
    return permission === 'contributor' && pickerEnvelopes.value.length > 0;
});

const canAddTransaction = computed(
    () => !isClosed.value && ledger.value !== null && mayAddTransactions.value,
);

// --- mutations -------------------------------------------------------------
const {
    currentProfile,
    isPending: isPendingCurrentProfile,
    isError: isErrorCurrentProfile,
    refetch: refetchProfiles,
} = useCurrentProfile();
const isProfileOwner = computed(() => currentProfile.value?.is_owner === true);

// "No data yet, no error" — unlike `isLoading`, this state is identical
// between the SSR render and the client's hydration pass, so the skeleton
// and content branches cannot diverge (see composables/query.ts).
const waitingForData = computed(
    () =>
        !ledgersLoadFailed.value &&
        (isPendingCurrentProfile.value || ledgersQuery.isPending.value),
);

/** The ledger list failed to load (directly, or through the profile list). */
const ledgersLoadFailed = computed(
    () =>
        ledgersQuery.data.value === undefined &&
        (isErrorCurrentProfile.value || ledgersQuery.isError.value),
);

const retryLedgers = (): void => {
    void (isErrorCurrentProfile.value
        ? refetchProfiles()
        : ledgersQuery.refetch());
};

const createLedger = useCreateLedger();
const closeLedger = useCloseLedger();
const reopenLedger = useReopenLedger();
const deleteLedger = useDeleteLedger();
const addTransaction = useAddTransaction(ledgerId);
const deleteTransaction = useDeleteTransaction(ledgerId);
const setLedgerShare = useSetLedgerShare();
const removeLedgerShare = useRemoveLedgerShare();
const transferLedgerOwnership = useTransferLedgerOwnership();

// --- share drawer ----------------------------------------------------------
const showShare = ref(false);

const sharesQuery = useLedgerShares(
    ledgerId,
    computed(() => showShare.value),
);
const shareMembers = computed(() => sharesQuery.data.value ?? []);

const onSetSharePermission = (
    userId: number,
    permission: ResourcePermission,
) => {
    const id = ledgerId.value;
    if (id === null) {
        return;
    }
    setLedgerShare.mutate(
        { ledgerId: id, userId, permission },
        {
            onSuccess: () => toast.success(t('ledger.toast.accessUpdated')),
            onError: fail,
        },
    );
};

const onRevokeShare = (userId: number) => {
    const id = ledgerId.value;
    if (id === null) {
        return;
    }
    removeLedgerShare.mutate(
        { ledgerId: id, userId },
        {
            onSuccess: () => toast.success(t('ledger.toast.accessRevoked')),
            onError: fail,
        },
    );
};

const onTransferOwnership = (userId: number) => {
    const id = ledgerId.value;
    if (id === null) {
        return;
    }
    transferLedgerOwnership.mutate(
        { ledgerId: id, userId },
        {
            onSuccess: () =>
                toast.success(t('ledger.toast.ownershipTransferred')),
            onError: fail,
        },
    );
};

const fail = (e: unknown) => {
    toast.error(errorMessage(e, t('ledger.error.generic')));
};

const showCreate = ref(false);
const showDelete = ref(false);

const onCreate = (input: Omit<CreateLedgerInput, 'profile_id'>) => {
    createLedger.mutate(input, {
        onSuccess: (data) => {
            showCreate.value = false;
            selectedLedgerId.value = data.ledger.id;
            toast.success(t('ledger.toast.ledgerCreated'));
        },
        onError: fail,
    });
};

const onClose = () => {
    if (ledgerId.value === null) {
        return;
    }
    closeLedger.mutate(ledgerId.value, {
        onSuccess: () => toast.success(t('ledger.toast.ledgerClosed')),
        onError: fail,
    });
};

const onReopen = () => {
    if (ledgerId.value === null) {
        return;
    }
    reopenLedger.mutate(ledgerId.value, {
        onSuccess: () => toast.success(t('ledger.toast.ledgerReopened')),
        onError: fail,
    });
};

const onDelete = () => {
    if (ledgerId.value === null) {
        return;
    }
    const id = ledgerId.value;
    deleteLedger.mutate(id, {
        onSuccess: () => {
            showDelete.value = false;
            toast.success(t('ledger.toast.ledgerDeleted'));
        },
        onError: fail,
    });
};

const addTransactionForm =
    useTemplateRef<InstanceType<typeof AddTransactionForm>>(
        'addTransactionForm',
    );

const onAddTransaction = (input: AddTransactionInput) => {
    if (ledgerId.value === null) {
        return;
    }
    addTransaction.mutate(
        { input },
        {
            onSuccess: () => {
                addTransactionForm.value?.reset();
                toast.success(t('ledger.toast.transactionAdded'));
            },
            onError: fail,
        },
    );
};

const pendingTransactionId = ref<number | null>(null);

// Deleting a transaction cannot be undone: confirm it, naming the entry.
const transactionToDelete = ref<Transaction | null>(null);
const confirmingTransactionDelete = ref(false);

const onRemoveTransaction = (transactionId: number) => {
    transactionToDelete.value =
        transactions.value.find((tx) => tx.id === transactionId) ?? null;
    confirmingTransactionDelete.value = transactionToDelete.value !== null;
};

const transactionSummary = computed(() => {
    const tx = transactionToDelete.value;
    if (tx === null) {
        return '';
    }
    const date = formatCalendarDate(tx.event_at, locale.value, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
    // A masked amount is never revealed, not even in the confirmation.
    return tx.masked || tx.amount === null
        ? t('ledger.deleteTransaction.summaryMasked', {
              envelope: tx.name,
              date,
          })
        : t('ledger.deleteTransaction.summary', {
              envelope: tx.name,
              date,
              amount: formatMoney(tx.amount, currency.value, locale.value),
          });
});

const transactionTable =
    useTemplateRef<InstanceType<typeof TransactionTable>>('transactionTable');
/** The deleted row's trash button is gone: focus returns to the table. */
let refocusTable = false;

const onDeleteDialogCloseFocus = (event: Event) => {
    if (refocusTable) {
        refocusTable = false;
        event.preventDefault();
        transactionTable.value?.focus();
    }
};

const confirmRemoveTransaction = () => {
    const tx = transactionToDelete.value;
    if (tx === null) {
        return;
    }
    pendingTransactionId.value = tx.id;
    deleteTransaction.mutate(
        { transactionId: tx.id },
        {
            onSuccess: () => {
                refocusTable = true;
                confirmingTransactionDelete.value = false;
                toast.success(t('ledger.toast.transactionDeleted'));
            },
            onSettled: () => {
                pendingTransactionId.value = null;
            },
            onError: fail,
        },
    );
};
</script>

<template>
    <Head :title="$t('ledger.title')" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <Heading
            :title="$t('ledger.title')"
            :description="$t('ledger.description')"
        />

        <LedgerLoadingScreen v-if="waitingForData" />

        <QueryErrorState
            v-else-if="ledgersLoadFailed"
            :message="$t('ledger.loadError')"
            :retrying="ledgersQuery.isFetching.value"
            @retry="retryLedgers"
        />

        <!-- No ledgers yet -->
        <Card
            v-else-if="ledgers.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border"
        >
            <CardContent
                class="flex flex-col items-center px-6 py-16 text-center sm:py-20"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-2xl bg-primary/10"
                >
                    <Icon
                        name="receipt-long"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h2
                    class="mt-6 font-display text-3xl leading-9 tracking-tight text-foreground"
                >
                    {{ $t('ledger.empty.title') }}
                </h2>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    {{ $t('ledger.empty.description') }}
                </p>

                <Button
                    v-if="isProfileOwner"
                    size="lg"
                    class="mt-8 gap-2"
                    @click="showCreate = true"
                >
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    {{ $t('ledger.actions.createLedger') }}
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    {{ $t('ledger.empty.onlyOwner') }}
                </p>
            </CardContent>
        </Card>

        <template v-else>
            <LedgerSelector
                :ledgers="ledgers"
                :selected-id="selectedLedgerId"
                :permission="ledgerPermission"
                :loading="ledgersQuery.isLoading.value"
                @update:selected-id="selectedLedgerId = $event"
                @close="onClose"
                @reopen="onReopen"
                @share="showShare = true"
                @remove="showDelete = true"
            />

            <div
                class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 lg:grid-cols-[auto_minmax(0,1fr)]"
            >
                <div class="lg:sticky lg:top-8">
                    <MonthCalendar
                        :selected-date="selectedDate"
                        :view-month="viewMonth"
                        :min-date="ledger?.start_date"
                        :max-date="ledger?.end_date"
                        @select-date="onSelectDate"
                        @navigate-month="navigateMonth"
                    />
                </div>

                <div class="flex flex-col gap-4">
                    <Card v-if="isClosed" class="border-warning/40">
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="lock"
                                class="size-5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            {{ $t('ledger.closedNotice') }}
                        </CardContent>
                    </Card>

                    <Card
                        v-else-if="
                            !envelopesQuery.isPending.value &&
                            pickerEnvelopes.length === 0
                        "
                        class="border-warning/40"
                    >
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="info"
                                class="size-5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <span>
                                {{ $t('ledger.envelopesNotice.required') }}
                                <Link
                                    :href="envelopesRoute()"
                                    class="font-medium text-primary underline underline-offset-4"
                                    >{{
                                        $t('ledger.envelopesNotice.manage')
                                    }}</Link
                                >
                            </span>
                        </CardContent>
                    </Card>

                    <Card
                        v-else-if="
                            !envelopesQuery.isPending.value &&
                            !canAddTransaction
                        "
                        class="border-warning/40"
                    >
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="info"
                                class="size-5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            {{ $t('ledger.permissionNotice') }}
                        </CardContent>
                    </Card>

                    <Card v-else>
                        <CardHeader>
                            <CardTitle class="text-base">{{
                                $t('ledger.newTransaction.title')
                            }}</CardTitle>
                            <CardDescription>
                                {{ $t('ledger.newTransaction.description') }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AddTransactionForm
                                ref="addTransactionForm"
                                :envelopes="pickerEnvelopes"
                                :pending="addTransaction.isPending.value"
                                :currency="currency"
                                :selected-date="selectedDate"
                                @confirm="onAddTransaction"
                            />
                        </CardContent>
                    </Card>

                    <TransactionTable
                        ref="transactionTable"
                        :transactions="transactions"
                        :currency="currency"
                        :current-user-id="meId"
                        :permission="ledgerPermission"
                        :open="!isClosed"
                        :pending-id="pendingTransactionId"
                        :loading="transactionsQuery.isPending.value"
                        :error="
                            transactionsQuery.isError.value &&
                            transactionsQuery.data.value === undefined
                        "
                        @remove="onRemoveTransaction"
                        @retry="transactionsQuery.refetch()"
                    />
                </div>
            </div>
        </template>

        <CreateLedgerDialog
            v-model:open="showCreate"
            :pending="createLedger.isPending.value"
            @confirm="onCreate"
        />

        <ShareDrawer
            v-if="ledgerId !== null"
            :open="showShare"
            :title="$t('ledger.share.title')"
            :transfer-resource="$t('ledger.share.resource')"
            :members="shareMembers"
            :loading="sharesQuery.isPending.value"
            :error="sharesQuery.isError.value"
            :retrying="sharesQuery.isFetching.value"
            @retry="sharesQuery.refetch()"
            :current-user-id="meId"
            :can-manage="ledgerPermission === 'owner'"
            @update:open="showShare = $event"
            @set-permission="onSetSharePermission"
            @revoke="onRevokeShare"
            @transfer="onTransferOwnership"
        />

        <Dialog
            :open="confirmingTransactionDelete"
            @update:open="confirmingTransactionDelete = $event"
        >
            <DialogContent
                class="sm:max-w-md"
                @close-auto-focus="onDeleteDialogCloseFocus"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('ledger.deleteTransaction.title')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ transactionSummary }}
                        {{ $t('ledger.deleteTransaction.description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="confirmingTransactionDelete = false"
                        >{{
                            $t('common.actions.cancel')
                        }}</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="deleteTransaction.isPending.value"
                        @click="confirmRemoveTransaction"
                    >
                        <Spinner v-if="deleteTransaction.isPending.value" />
                        {{ $t('ledger.deleteTransaction.confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="showDelete" @update:open="showDelete = false">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ $t('ledger.delete.title') }}</DialogTitle>
                    <DialogDescription>
                        {{ $t('ledger.delete.description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="showDelete = false">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="deleteLedger.isPending.value"
                        @click="onDelete"
                        >{{
                            $t('ledger.delete.confirm')
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
