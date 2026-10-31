<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useQueryClient } from 'vue-query';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import BudgetSelector from '@/components/Budget/BudgetSelector.vue';
import BudgetFlowBar from '@/components/Budget/BudgetFlowBar.vue';
import BudgetEnvelopeTable from '@/components/Budget/BudgetEnvelopeTable.vue';
import CreateBudgetDialog from '@/components/Budget/CreateBudgetDialog.vue';
import CloneBudgetDialog from '@/components/Budget/CloneBudgetDialog.vue';
import AddEnvelopeDialog from '@/components/Budget/AddEnvelopeDialog.vue';
import ResourceShareDrawer from '@/components/share/ResourceShareDrawer.vue';
import {
    budgetKeys,
    useAddEnvelope,
    useBudget,
    useBudgets,
    useCloneBudget,
    useCreateBudget,
    useDeleteBudget,
    usePublishBudget,
    useRemoveBudgetShare,
    useRemoveEnvelope,
    useSetBudgetShare,
    useTransferBudgetOwnership,
    useUpdateEnvelope,
} from '@/composables/budgetQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { ApiError } from '@/lib/api';
import { budget as budgetRoute } from '@/routes';
import type { AddEnvelopeInput } from '@/lib/budgetApi';
import type { BudgetDetail, ResourcePermission } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Budget', href: budgetRoute() }],
    },
});

const budgetsQuery = useBudgets();
/** Draft first, then published, newest first (retired budgets are excluded server-side). */
const budgets = computed(() =>
    [...(budgetsQuery.data.value ?? [])].sort(
        (a, b) =>
            Number(a.published_at !== null) - Number(b.published_at !== null),
    ),
);

const currentUserId = computed(() => usePage().props.auth.user.id);

const selectedId = ref<number | null>(null);
const budgetId = computed(
    () => selectedId.value ?? budgets.value[0]?.id ?? null,
);

const budgetQuery = useBudget(budgetId);
const budget = computed(() => budgetQuery.data.value ?? null);

const max = computed(() => {
    const envelopes = budget.value?.envelopes ?? [];
    const total = (direction: 'revenue' | 'expense') =>
        envelopes
            .filter((e) => e.direction === direction)
            .reduce((sum, e) => sum + Math.abs(e.amount), 0);
    return Math.max(1, total('revenue'), total('expense'));
});

const isDraft = computed(
    () => budget.value !== null && budget.value.published_at === null,
);

const toSegments = (direction: 'revenue' | 'expense') =>
    (budget.value?.envelopes ?? [])
        .filter((e) => e.direction === direction)
        .map((e) => ({
            id: e.envelope_id,
            name: e.name,
            color: e.color,
            amount: Math.abs(e.amount),
        }));

const revenueSegments = computed(() => toSegments('revenue'));
const expenseSegments = computed(() => toSegments('expense'));
const isOwner = computed(() => budget.value?.permission === 'owner');
const canEdit = computed(() => isDraft.value && isOwner.value);

watch(
    budgets,
    (list) => {
        if (
            selectedId.value !== null &&
            list.some((b) => b.id === selectedId.value)
        ) {
            return;
        }
        selectedId.value = list[0]?.id ?? null;
    },
    { immediate: true },
);

// --- mutations -------------------------------------------------------------
const queryClient = useQueryClient();
const { currentProfile } = useCurrentProfile();
const isProfileOwner = computed(() => currentProfile.value?.is_owner === true);

const createBudget = useCreateBudget();
const publishBudget = usePublishBudget();
const cloneBudget = useCloneBudget();
const deleteBudget = useDeleteBudget();
const addEnvelope = useAddEnvelope();
const updateEnvelope = useUpdateEnvelope();
const removeEnvelope = useRemoveEnvelope();
const setShare = useSetBudgetShare();
const revokeShare = useRemoveBudgetShare();
const transferOwnership = useTransferBudgetOwnership();

const pendingEnvelopeId = ref<number | null>(null);
const working = computed(
    () =>
        createBudget.isLoading.value ||
        publishBudget.isLoading.value ||
        cloneBudget.isLoading.value ||
        deleteBudget.isLoading.value ||
        addEnvelope.isLoading.value ||
        updateEnvelope.isLoading.value ||
        removeEnvelope.isLoading.value ||
        setShare.isLoading.value ||
        revokeShare.isLoading.value ||
        transferOwnership.isLoading.value,
);

const fail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : 'Something went wrong.');
};

// --- dialogs ---------------------------------------------------------------
const showCreate = ref(false);
const showClone = ref(false);
const showAddEnvelope = ref(false);
const showShare = ref(false);
const confirmKind = ref<'publish' | 'delete' | null>(null);

/**
 * Publishing a draft for a month that already has a published budget
 * replaces it (docs/budget.md — budget lifecycle).
 */
const replaceWarning = computed(() => {
    if (confirmKind.value !== 'publish' || budget.value === null) {
        return false;
    }
    const now = new Date();
    return budgets.value.some(
        (b) =>
            b.id !== budget.value?.id &&
            b.published_at !== null &&
            b.effective_year === now.getFullYear() &&
            b.effective_month === now.getMonth() + 1,
    );
});

const onSetSharePermission = (
    userId: number,
    permission: ResourcePermission,
) => {
    const id = budgetId.value;
    if (id === null) {
        return;
    }
    setShare.mutate(
        { budgetId: id, userId, permission },
        {
            onSuccess: () => toast.success('Access updated.'),
            onError: fail,
        },
    );
};

const onRevokeShare = (userId: number) => {
    const id = budgetId.value;
    if (id === null) {
        return;
    }
    revokeShare.mutate(
        { budgetId: id, userId },
        {
            onSuccess: () => toast.success('Access revoked.'),
            onError: fail,
        },
    );
};

const onTransferOwnership = (userId: number) => {
    const id = budgetId.value;
    if (id === null) {
        return;
    }
    transferOwnership.mutate(
        { budgetId: id, userId },
        {
            onSuccess: () => toast.success('Ownership transferred.'),
            onError: fail,
        },
    );
};

const onConfirm = () => {
    const id = selectedId.value ?? budget.value?.id;
    if (id === undefined) {
        return;
    }
    const kind = confirmKind.value;
    confirmKind.value = null;

    if (kind === 'publish') {
        publishBudget.mutate(id, {
            onSuccess: (data) => {
                selectedId.value = data.budget.id;
                toast.success('Budget published.');
            },
            onError: fail,
        });
    } else if (kind === 'delete') {
        deleteBudget.mutate(id, {
            onSuccess: () => {
                toast.success('Budget deleted.');
            },
            onError: fail,
        });
    }
};

const onCreate = (currency: string) => {
    createBudget.mutate(currency, {
        onSuccess: (data) => {
            selectedId.value = data.budget.id;
            toast.success('Draft budget created.');
        },
        onError: fail,
    });
};

const onClone = (currency: string) => {
    const id = selectedId.value ?? budget.value?.id;
    if (id === undefined) {
        return;
    }
    cloneBudget.mutate(
        { id, currency },
        {
            onSuccess: (data) => {
                selectedId.value = data.budget.id;
                toast.success('Draft budget created.');
            },
            onError: fail,
        },
    );
};

const onAddEnvelope = (input: AddEnvelopeInput) => {
    const id = budgetId.value;
    if (id === null) {
        return;
    }
    addEnvelope.mutate(
        { budgetId: id, input },
        {
            onSuccess: () => {
                showAddEnvelope.value = false;
                toast.success('Envelope added.');
            },
            onError: fail,
        },
    );
};

/**
 * Live amount editing: every valid change is applied to the budget in the
 * query cache immediately (bars, table and totals update as you type), then
 * persisted with a debounce — changes save automatically, no save buttons.
 */
const SAVE_DELAY_MS = 600;

const pendingSaves = new Map<
    number,
    { timer: ReturnType<typeof setTimeout>; budgetId: number; amount: number }
>();

const onEditAmount = (envelopeId: number, amount: number) => {
    const id = budgetId.value;
    const current = budget.value;
    if (id === null || current === null) {
        return;
    }

    queryClient.setQueryData<{ budget: BudgetDetail }>(budgetKeys.detail(id), {
        budget: {
            ...current,
            envelopes: current.envelopes.map((e) =>
                e.envelope_id === envelopeId
                    ? {
                          ...e,
                          amount: e.direction === 'expense' ? -amount : amount,
                      }
                    : e,
            ),
        },
    });

    const existing = pendingSaves.get(envelopeId);
    if (existing !== undefined) {
        clearTimeout(existing.timer);
    }
    pendingSaves.set(envelopeId, {
        timer: setTimeout(
            () => saveAmount(envelopeId, id, amount),
            SAVE_DELAY_MS,
        ),
        budgetId: id,
        amount,
    });
};

const saveAmount = (
    envelopeId: number,
    budgetIdValue: number,
    amount: number,
) => {
    pendingSaves.delete(envelopeId);
    updateEnvelope.mutate(
        { budgetId: budgetIdValue, envelopeId, amount },
        {
            onError: (error) => {
                fail(error);
                void queryClient.invalidateQueries(
                    budgetKeys.detail(budgetIdValue),
                );
            },
        },
    );
};

onBeforeUnmount(() => {
    for (const [envelopeId, pending] of pendingSaves) {
        clearTimeout(pending.timer);
        saveAmount(envelopeId, pending.budgetId, pending.amount);
    }
    pendingSaves.clear();
});

const onRemoveEnvelope = (envelopeId: number) => {
    const id = budgetId.value;
    if (id === null) {
        return;
    }
    pendingEnvelopeId.value = envelopeId;
    removeEnvelope.mutate(
        { budgetId: id, envelopeId },
        {
            onSettled: () => {
                pendingEnvelopeId.value = null;
            },
            onSuccess: () => toast.success('Envelope removed.'),
            onError: fail,
        },
    );
};
</script>

<template>
    <Head title="Budget" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <Heading
            title="Budget"
            description="Plan where your money goes before it goes anywhere."
        />

        <div
            v-if="budgetsQuery.isLoading.value"
            class="flex justify-center py-20"
        >
            <Icon
                name="progress-activity"
                class="size-6 animate-spin text-muted-foreground"
                aria-hidden="true"
            />
        </div>

        <!-- No budgets yet -->
        <Card
            v-else-if="budgets.length === 0"
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
                        name="savings"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h3
                    class="mt-6 bg-gradient-to-r from-foreground via-foreground to-primary bg-clip-text text-3xl font-semibold tracking-tight text-transparent sm:text-4xl"
                >
                    Create your first budget
                </h3>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    Start with a draft: pick your currency, add your envelopes,
                    then publish when it feels right.
                </p>

                <Button
                    v-if="isProfileOwner"
                    size="lg"
                    class="mt-8 gap-2 shadow-lg shadow-primary/25"
                    @click="showCreate = true"
                >
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    Create budget
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    Only the profile owner can create budgets.
                </p>
            </CardContent>
        </Card>

        <template v-else>
            <BudgetSelector
                :budgets="budgets"
                :selected-id="selectedId"
                :permission="budget?.permission ?? null"
                :is-profile-owner="isProfileOwner"
                :loading="budgetsQuery.isLoading.value"
                @update:selected-id="selectedId = $event"
                @publish="confirmKind = 'publish'"
                @new-budget="showClone = true"
                @share="showShare = true"
                @remove="confirmKind = 'delete'"
            />

            <div
                v-if="budgetQuery.isLoading.value && budget === null"
                class="flex justify-center py-20"
            >
                <Icon
                    name="progress-activity"
                    class="size-6 animate-spin text-muted-foreground"
                    aria-hidden="true"
                />
            </div>

            <template v-else-if="budget">
                <BudgetFlowBar
                    title="Revenues"
                    :segments="revenueSegments"
                    :max="max"
                    :currency="budget.currency"
                />
                <BudgetFlowBar
                    title="Expenses"
                    :segments="expenseSegments"
                    :max="max"
                    :currency="budget.currency"
                />

                <BudgetEnvelopeTable
                    :envelopes="budget.envelopes"
                    :currency="budget.currency"
                    :editable="canEdit"
                    :pending-id="pendingEnvelopeId"
                    @add="showAddEnvelope = true"
                    @update-amount="onEditAmount"
                    @remove="onRemoveEnvelope"
                />
            </template>
        </template>

        <CreateBudgetDialog v-model:open="showCreate" @confirm="onCreate" />

        <CloneBudgetDialog
            v-model:open="showClone"
            :currency="budget?.currency"
            @confirm="onClone"
        />

        <AddEnvelopeDialog
            v-model:open="showAddEnvelope"
            :excluded-ids="budget?.envelopes.map((e) => e.envelope_id) ?? []"
            @confirm="onAddEnvelope"
        />

        <ResourceShareDrawer
            v-if="budgetId !== null && budget !== null"
            :open="showShare"
            title="Share budget"
            :members="budget.shares"
            :current-user-id="currentUserId"
            :can-manage="budget.permission === 'owner'"
            @update:open="showShare = $event"
            @set-permission="onSetSharePermission"
            @revoke="onRevokeShare"
            @transfer="onTransferOwnership"
        />

        <Dialog :open="confirmKind !== null" @update:open="confirmKind = null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        confirmKind === 'publish'
                            ? 'Publish this budget?'
                            : 'Delete this budget?'
                    }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="confirmKind === 'publish'">
                            Publishing stamps this budget for the current month
                            and puts it in read-only mode. This cannot be
                            undone.
                            <template v-if="replaceWarning">
                                An existing published budget for this month will
                                be replaced.
                            </template>
                        </template>
                        <template v-else>
                            This permanently removes the budget with all of its
                            envelope assignments and shares.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmKind = null"
                        >Cancel</Button
                    >
                    <Button
                        :variant="
                            confirmKind === 'delete' ? 'destructive' : 'default'
                        "
                        :disabled="working"
                        @click="onConfirm"
                    >
                        {{
                            confirmKind === 'publish'
                                ? 'Publish'
                                : 'Delete budget'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
