<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import Heading from '@/components/Heading.vue';
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
import LedgerSelector from '@/components/Ledger/LedgerSelector.vue';
import MonthCalendar from '@/components/Ledger/MonthCalendar.vue';
import TransactionTable from '@/components/Ledger/TransactionTable.vue';
import AddTransactionForm from '@/components/Ledger/AddTransactionForm.vue';
import CreateLedgerDialog from '@/components/Ledger/CreateLedgerDialog.vue';
import ResourceShareDrawer from '@/components/share/ResourceShareDrawer.vue';
import {
    useAddTransaction,
    useCloseLedger,
    useCreateLedger,
    useDeleteLedger,
    useDeleteTransaction,
    useLedgerShares,
    useLedgerTransactions,
    useLedgers,
    useRemoveLedgerShare,
    useReopenLedger,
    useSetLedgerShare,
    useTransferLedgerOwnership,
} from '@/composables/ledgerQueries';
import { budgetKeys, useBudget, useBudgets } from '@/composables/budgetQueries';
import { useEnvelopeOptions } from '@/composables/envelopeQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { ApiError } from '@/lib/api';
import { ledger as ledgerRoute } from '@/routes';
import type {
    AddTransactionInput,
    CreateLedgerInput,
    PickerEnvelope,
} from '@/lib/ledgerApi';
import type { ResourcePermission } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ledger', href: ledgerRoute() }],
    },
});

// --- state -----------------------------------------------------------------
const isoToday = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const viewMonth = ref(isoToday().slice(0, 8) + '01'); // first day of current month
const selectedDate = ref(isoToday());

const monthRange = computed(() => {
    const [year, month] = viewMonth.value.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const last = new Date(year, month, 0);
    const iso = (d: Date) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return { from: iso(first), to: iso(last) };
});

const onSelectDate = (date: string) => {
    selectedDate.value = date;
    if (date.slice(0, 8) !== viewMonth.value.slice(0, 8)) {
        viewMonth.value = date.slice(0, 8) + '01';
    }
};

const navigateMonth = (direction: 'prev' | 'next') => {
    const [year, month] = viewMonth.value.split('-').map(Number);
    const date = new Date(year, month - 1 + (direction === 'next' ? 1 : -1), 1);
    const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
    viewMonth.value = iso;
    if (selectedDate.value.slice(0, 8) !== iso.slice(0, 8)) {
        selectedDate.value =
            isoToday().slice(0, 8) === iso.slice(0, 8) ? isoToday() : iso;
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

const transactionsQuery = useLedgerTransactions(
    ledgerId,
    computed(() => monthRange.value.from),
    computed(() => monthRange.value.to),
);
const transactions = computed(() => transactionsQuery.data.value ?? []);

// --- budget context --------------------------------------------------------
const budgetsQuery = useBudgets();
const latestPublished = computed(() => {
    const published = (budgetsQuery.data.value ?? [])
        .filter((b) => b.published_at !== null)
        .sort(
            (a, b) =>
                new Date(b.published_at!).getTime() -
                new Date(a.published_at!).getTime(),
        );
    return published[0] ?? null;
});

const budgetQuery = useBudget(
    computed(() => latestPublished.value?.id ?? null),
);
const budget = computed(() => budgetQuery.data.value ?? null);

/**
 * Budget data is loading — banners and the entry form stay hidden until the
 * state is known (no "no published budget" flash).
 */
const budgetLoading = computed(
    () => budgetsQuery.isLoading.value || budgetQuery.isLoading.value,
);

const currency = computed(() => budget.value?.currency ?? '');

const envelopesQuery = useEnvelopeOptions();

/**
 * The transaction picker: envelopes from the published budget on which the
 * current user is owner or contributor (docs/ledger.md — adding a
 * transaction).
 */
const pickerEnvelopes = computed<PickerEnvelope[]>(() => {
    const budgetEnvelopeIds = new Set(
        (budget.value?.envelopes ?? []).map((e) => e.envelope_id),
    );
    return (envelopesQuery.data.value ?? [])
        .filter(
            (option) =>
                budgetEnvelopeIds.has(option.id) &&
                (option.permission === 'owner' ||
                    option.permission === 'contributor'),
        )
        .map((option) => ({
            envelope_id: option.id,
            name: option.name,
            color: option.color,
            direction: option.direction,
        }));
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
    () =>
        !isClosed.value &&
        budget.value !== null &&
        ledger.value !== null &&
        mayAddTransactions.value,
);

// --- mutations -------------------------------------------------------------
const { currentProfile } = useCurrentProfile();
const isProfileOwner = computed(() => currentProfile.value?.is_owner === true);

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
            onSuccess: () => toast.success('Access updated.'),
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
            onSuccess: () => toast.success('Access revoked.'),
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
            onSuccess: () => toast.success('Ownership transferred.'),
            onError: fail,
        },
    );
};

const fail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : 'Something went wrong.');
};

const showCreate = ref(false);
const showDelete = ref(false);

const onCreate = (input: Omit<CreateLedgerInput, 'profile_id'>) => {
    createLedger.mutate(input, {
        onSuccess: (data) => {
            selectedLedgerId.value = data.ledger.id;
            toast.success('Ledger created.');
        },
        onError: fail,
    });
};

const onClose = () => {
    if (ledgerId.value === null) {
        return;
    }
    closeLedger.mutate(ledgerId.value, {
        onSuccess: () => toast.success('Ledger closed.'),
        onError: fail,
    });
};

const onReopen = () => {
    if (ledgerId.value === null) {
        return;
    }
    reopenLedger.mutate(ledgerId.value, {
        onSuccess: () => toast.success('Ledger reopened.'),
        onError: fail,
    });
};

const onDelete = () => {
    if (ledgerId.value === null) {
        return;
    }
    const id = ledgerId.value;
    showDelete.value = false;
    deleteLedger.mutate(id, {
        onSuccess: () => toast.success('Ledger deleted.'),
        onError: fail,
    });
};

const onAddTransaction = (input: AddTransactionInput) => {
    if (ledgerId.value === null) {
        return;
    }
    addTransaction.mutate(
        { input },
        {
            onSuccess: () => toast.success('Transaction added.'),
            onError: fail,
        },
    );
};

const pendingTransactionId = ref<number | null>(null);

const onRemoveTransaction = (transactionId: number) => {
    pendingTransactionId.value = transactionId;
    deleteTransaction.mutate(
        { transactionId },
        {
            onSettled: () => {
                pendingTransactionId.value = null;
            },
            onError: fail,
        },
    );
};
</script>

<template>
    <Head title="Ledger" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <Heading
            title="Ledger"
            description="A yearly financial record of everything that comes in and everything that goes out."
        />

        <div
            v-if="ledgersQuery.isLoading.value"
            class="flex justify-center py-20"
        >
            <Icon
                name="progress-activity"
                class="size-6 animate-spin text-muted-foreground"
                aria-hidden="true"
            />
        </div>

        <!-- No ledgers yet -->
        <Card
            v-else-if="ledgers.length === 0"
            class="relative overflow-hidden border-sidebar-border/70 dark:border-sidebar-border"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -top-24 left-1/2 h-64 w-[36rem] -translate-x-1/2 rounded-full bg-primary/10 blur-3xl"
                aria-hidden="true"
            />

            <CardContent
                class="relative flex flex-col items-center px-6 py-16 text-center sm:py-20"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 shadow-inner shadow-primary/10"
                >
                    <Icon
                        name="receipt-long"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h3
                    class="mt-6 bg-gradient-to-r from-foreground via-foreground to-primary bg-clip-text text-3xl font-semibold tracking-tight text-transparent sm:text-4xl"
                >
                    Create your first ledger
                </h3>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    A ledger keeps a yearly financial record of your
                    transactions. Create one to start logging expenses and
                    revenues.
                </p>

                <Button
                    v-if="isProfileOwner"
                    size="lg"
                    class="mt-8 gap-2 shadow-lg shadow-primary/25"
                    @click="showCreate = true"
                >
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    Create ledger
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    Only the profile owner can create ledgers.
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

            <div class="grid items-start gap-6 lg:grid-cols-[auto_1fr]">
                <div class="sticky top-4 lg:top-8">
                    <MonthCalendar
                        :selected-date="selectedDate"
                        :view-month="viewMonth"
                        @select-date="onSelectDate"
                        @navigate-month="navigateMonth"
                    />
                </div>

                <div class="flex flex-col gap-4">
                    <Card v-if="isClosed" class="border-amber-500/30">
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="lock"
                                class="size-5 shrink-0 text-amber-500"
                                aria-hidden="true"
                            />
                            This ledger is closed and read-only. Reopen it from
                            the actions menu to record transactions.
                        </CardContent>
                    </Card>

                    <Card
                        v-else-if="!budgetLoading && budget === null"
                        class="border-amber-500/30"
                    >
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="info"
                                class="size-5 shrink-0 text-amber-500"
                                aria-hidden="true"
                            />
                            A published budget is required before recording
                            transactions.
                        </CardContent>
                    </Card>

                    <Card
                        v-else-if="!budgetLoading && !canAddTransaction"
                        class="border-amber-500/30"
                    >
                        <CardContent
                            class="flex items-center gap-3 px-4 py-3 text-sm"
                        >
                            <Icon
                                name="info"
                                class="size-5 shrink-0 text-amber-500"
                                aria-hidden="true"
                            />
                            You don't have permission to record transactions in
                            this ledger.
                        </CardContent>
                    </Card>

                    <Card v-else>
                        <CardHeader>
                            <CardTitle class="text-base"
                                >New transaction</CardTitle
                            >
                            <CardDescription>
                                Pick a day on the calendar, select an envelope
                                and enter the amount.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AddTransactionForm
                                :envelopes="pickerEnvelopes"
                                :currency="currency"
                                :selected-date="selectedDate"
                                @confirm="onAddTransaction"
                            />
                        </CardContent>
                    </Card>

                    <TransactionTable
                        :transactions="transactions"
                        :currency="currency"
                        :current-user-id="meId"
                        :permission="ledgerPermission"
                        :open="!isClosed"
                        :pending-id="pendingTransactionId"
                        @remove="onRemoveTransaction"
                    />
                </div>
            </div>
        </template>

        <CreateLedgerDialog v-model:open="showCreate" @confirm="onCreate" />

        <ResourceShareDrawer
            v-if="ledgerId !== null"
            :open="showShare"
            title="Share ledger"
            :members="shareMembers"
            :current-user-id="meId"
            :can-manage="ledgerPermission === 'owner'"
            @update:open="showShare = $event"
            @set-permission="onSetSharePermission"
            @revoke="onRevokeShare"
            @transfer="onTransferOwnership"
        />

        <Dialog :open="showDelete" @update:open="showDelete = false">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete this ledger?</DialogTitle>
                    <DialogDescription>
                        This permanently removes the ledger and ALL of its
                        transactions. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="showDelete = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="deleteLedger.isLoading.value"
                        @click="onDelete"
                        >Delete ledger</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
