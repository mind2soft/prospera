<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useQueryClient } from '@tanstack/vue-query';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import AddEnvelopeDialog from '@/components/Budget/AddEnvelopeDialog.vue';
import BudgetEnvelopeTable from '@/components/Budget/BudgetEnvelopeTable.vue';
import BudgetFlowBar from '@/components/Budget/BudgetFlowBar.vue';
import BudgetSelector from '@/components/Budget/BudgetSelector.vue';
import CloneBudgetDialog from '@/components/Budget/CloneBudgetDialog.vue';
import CreateBudgetDialog from '@/components/Budget/CreateBudgetDialog.vue';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import BudgetLoadingScreen from '@/components/Loading/BudgetLoadingScreen.vue';
import QueryErrorState from '@/components/QueryErrorState.vue';
import ShareDrawer from '@/components/share/ShareDrawer.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
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
import { useProfileMembers } from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useFlushOnLeave } from '@/composables/useFlushOnLeave';
import { useLocale } from '@/composables/useLocale';
import { errorMessage } from '@/lib/api';
import { budgetMonthLabel } from '@/lib/budget';
import type { AddEnvelopeInput } from '@/lib/budgetApi';
import { currentMonth, parseMonth } from '@/lib/calendar';
import { budget as budgetRoute } from '@/routes';
import type { BudgetDetail, ResourcePermission } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'budget.title', href: budgetRoute() }],
    },
});

const { t } = useI18n();
const { locale } = useLocale();

const budgetsQuery = useBudgets();
/** Draft first, then published (each newest first). */
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
const {
    currentProfile,
    isPending: isPendingCurrentProfile,
    isError: isErrorCurrentProfile,
    refetch: refetchProfiles,
} = useCurrentProfile();
const isProfileOwner = computed(() => currentProfile.value?.is_owner === true);

/**
 * A draft can only be handed to the profile owner (docs/budget.md —
 * ownership): anyone else could neither publish nor delete it. Only the
 * budget's owner sees the transfer, so only they fetch the members.
 */
const profileMembersQuery = useProfileMembers(
    computed(() =>
        isDraft.value && isOwner.value
            ? (currentProfile.value?.id ?? null)
            : null,
    ),
);
const draftTransferTo = computed(() =>
    isDraft.value
        ? (profileMembersQuery.data.value ?? [])
              .filter((member) => member.is_owner)
              .map((member) => member.id)
        : undefined,
);

// "No data yet, no error" — unlike `isLoading`, this state is identical
// between the SSR render and the client's hydration pass, so the skeleton
// and content branches cannot diverge (see composables/query.ts).
const waitingForData = computed(
    () =>
        !budgetsLoadFailed.value &&
        (isPendingCurrentProfile.value || budgetsQuery.isPending.value),
);

/** The budget list failed to load (directly, or through the profile list). */
const budgetsLoadFailed = computed(
    () =>
        budgetsQuery.data.value === undefined &&
        (isErrorCurrentProfile.value || budgetsQuery.isError.value),
);

const retryBudgets = (): void => {
    void (isErrorCurrentProfile.value
        ? refetchProfiles()
        : budgetsQuery.refetch());
};

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
        createBudget.isPending.value ||
        publishBudget.isPending.value ||
        cloneBudget.isPending.value ||
        deleteBudget.isPending.value ||
        addEnvelope.isPending.value ||
        updateEnvelope.isPending.value ||
        removeEnvelope.isPending.value ||
        setShare.isPending.value ||
        revokeShare.isPending.value ||
        transferOwnership.isPending.value,
);

const fail = (e: unknown) => {
    toast.error(errorMessage(e, t('budget.toast.failed')));
};

// --- dialogs ---------------------------------------------------------------
const showCreate = ref(false);
const showClone = ref(false);
const showAddEnvelope = ref(false);
const showShare = ref(false);
// Publish and delete are confirmed in two separate dialogs, so a closing
// dialog never flips to the other one's copy during its exit animation.
const confirmingPublish = ref(false);
const confirmingDelete = ref(false);

/** The month the publish confirmation names, captured when it opens. */
const publishMonth = ref(currentMonth());

const askPublish = () => {
    publishMonth.value = currentMonth();
    confirmingPublish.value = true;
};

const publishMonthLabel = computed(() => {
    const [year, month] = parseMonth(publishMonth.value);
    return budgetMonthLabel(year, month, locale.value);
});

/**
 * Publishing a draft for a month that already has a published budget
 * replaces it (docs/budget.md — budget lifecycle).
 */
const replaceWarning = computed(() => {
    if (budget.value === null) {
        return false;
    }
    const [year, month] = parseMonth(publishMonth.value);
    return budgets.value.some(
        (b) =>
            b.id !== budget.value?.id &&
            b.published_at !== null &&
            b.effective_year === year &&
            b.effective_month === month,
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
            onSuccess: () => toast.success(t('budget.toast.accessUpdated')),
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
            onSuccess: () => toast.success(t('budget.toast.accessRevoked')),
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
            onSuccess: () =>
                toast.success(t('budget.toast.ownershipTransferred')),
            onError: fail,
        },
    );
};

/** Waiting for pending amount saves before publishing. */
const flushingBeforePublish = ref(false);

const onPublish = async () => {
    const id = selectedId.value ?? budget.value?.id;
    if (id === undefined) {
        return;
    }

    // A published budget is read-only: an amount edit still debounced or in
    // flight would be rejected after publish and silently lost.
    flushingBeforePublish.value = true;
    let saved: boolean;
    try {
        saved = await flushAmountSaves();
    } finally {
        flushingBeforePublish.value = false;
    }

    if (!saved) {
        // The failed save was reported; publishing now would publish the
        // previous amount.
        return;
    }

    publishBudget.mutate(
        { id, month: publishMonth.value },
        {
            onSuccess: (data) => {
                confirmingPublish.value = false;
                selectedId.value = data.budget.id;
                toast.success(t('budget.toast.published'));
            },
            onError: fail,
        },
    );
};

const onDelete = () => {
    const id = selectedId.value ?? budget.value?.id;
    if (id === undefined) {
        return;
    }
    deleteBudget.mutate(id, {
        onSuccess: () => {
            confirmingDelete.value = false;
            toast.success(t('budget.toast.deleted'));
        },
        onError: fail,
    });
};

const onCreate = (currency: string) => {
    createBudget.mutate(currency, {
        onSuccess: (data) => {
            showCreate.value = false;
            selectedId.value = data.budget.id;
            toast.success(t('budget.toast.draftCreated'));
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
                showClone.value = false;
                selectedId.value = data.budget.id;
                toast.success(t('budget.toast.draftCreated'));
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
                toast.success(t('budget.toast.envelopeAdded'));
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

/**
 * Autosave status shown next to the table: debounced or in-flight edits are
 * "saving", a failed one is "error" until the next successful save.
 */
const saveStatus = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const inFlightSaves = ref(0);

watch(budgetId, () => {
    saveStatus.value = 'idle';
});

const settleSaveStatus = () => {
    if (pendingSaves.size === 0 && inFlightSaves.value === 0) {
        if (saveStatus.value === 'saving') {
            saveStatus.value = 'saved';
        }
    }
};

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

    saveStatus.value = 'saving';
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

/** In-flight amount saves, so publishing can wait for them. */
const inFlightRequests = new Set<Promise<boolean>>();

const saveAmount = (
    envelopeId: number,
    budgetIdValue: number,
    amount: number,
) => {
    pendingSaves.delete(envelopeId);
    inFlightSaves.value += 1;
    // `mutateAsync`, not `mutate` callbacks: those only fire for the latest
    // of several concurrent calls, which would leave a save counted forever.
    const request: Promise<boolean> = updateEnvelope
        .mutateAsync({ budgetId: budgetIdValue, envelopeId, amount })
        .then(
            () => true,
            (error: unknown) => {
                saveStatus.value = 'error';
                fail(error);
                void queryClient.invalidateQueries({
                    queryKey: budgetKeys.detail(budgetIdValue),
                });

                return false;
            },
        )
        .finally(() => {
            inFlightSaves.value -= 1;
            inFlightRequests.delete(request);
            settleSaveStatus();
        });
    inFlightRequests.add(request);
};

/**
 * Sends every debounced amount edit now and waits for all saves to land;
 * false when one of them failed.
 */
const flushAmountSaves = async (): Promise<boolean> => {
    for (const [envelopeId, pending] of pendingSaves) {
        clearTimeout(pending.timer);
        saveAmount(envelopeId, pending.budgetId, pending.amount);
    }
    const results = await Promise.all([...inFlightRequests]);

    return results.every(Boolean);
};

// Leaving the page (or unloading it) sends the debounced edits right away.
useFlushOnLeave(() => {
    void flushAmountSaves();
});

const envelopeTable =
    useTemplateRef<InstanceType<typeof BudgetEnvelopeTable>>('envelopeTable');

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
            onSuccess: async () => {
                toast.success(t('budget.toast.envelopeRemoved'));
                // The row's remove button is gone: keep focus in the table.
                await nextTick();
                envelopeTable.value?.focus();
            },
            onError: fail,
        },
    );
};
</script>

<template>
    <Head :title="$t('budget.title')" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <Heading
            :title="$t('budget.title')"
            :description="$t('budget.description')"
        />

        <BudgetLoadingScreen v-if="waitingForData" />

        <QueryErrorState
            v-else-if="budgetsLoadFailed"
            :message="$t('budget.loadError')"
            :retrying="budgetsQuery.isFetching.value"
            @retry="retryBudgets"
        />

        <!-- No budgets yet -->
        <Card
            v-else-if="budgets.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border"
        >
            <CardContent
                class="flex flex-col items-center px-6 py-16 text-center sm:py-20"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-2xl bg-primary/10"
                >
                    <Icon
                        name="savings"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h2
                    class="mt-6 font-display text-3xl leading-9 tracking-tight text-foreground"
                >
                    {{ $t('budget.empty.title') }}
                </h2>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    {{ $t('budget.empty.description') }}
                </p>

                <Button
                    v-if="isProfileOwner"
                    size="lg"
                    class="mt-8 gap-2"
                    @click="showCreate = true"
                >
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    {{ $t('budget.empty.create') }}
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    {{ $t('budget.empty.onlyOwner') }}
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
                @publish="askPublish"
                @new-budget="showClone = true"
                @share="showShare = true"
                @remove="confirmingDelete = true"
            />

            <BudgetLoadingScreen
                v-if="budgetQuery.isPending.value && budget === null"
                :selector="false"
            />

            <QueryErrorState
                v-else-if="budgetQuery.isError.value && budget === null"
                :message="$t('budget.detailLoadError')"
                :retrying="budgetQuery.isFetching.value"
                @retry="budgetQuery.refetch()"
            />

            <template v-else-if="budget">
                <BudgetFlowBar
                    :title="$t('budget.flow.revenues')"
                    :segments="revenueSegments"
                    :max="max"
                    :currency="budget.currency"
                />
                <BudgetFlowBar
                    :title="$t('budget.flow.expenses')"
                    :segments="expenseSegments"
                    :max="max"
                    :currency="budget.currency"
                />

                <BudgetEnvelopeTable
                    ref="envelopeTable"
                    :envelopes="budget.envelopes"
                    :currency="budget.currency"
                    :editable="canEdit"
                    :pending-id="pendingEnvelopeId"
                    :save-status="saveStatus"
                    @add="showAddEnvelope = true"
                    @update-amount="onEditAmount"
                    @remove="onRemoveEnvelope"
                />
            </template>
        </template>

        <CreateBudgetDialog
            v-model:open="showCreate"
            :pending="createBudget.isPending.value"
            @confirm="onCreate"
        />

        <CloneBudgetDialog
            v-model:open="showClone"
            :currency="budget?.currency"
            :pending="cloneBudget.isPending.value"
            @confirm="onClone"
        />

        <AddEnvelopeDialog
            v-model:open="showAddEnvelope"
            :excluded-ids="budget?.envelopes.map((e) => e.envelope_id) ?? []"
            :pending="addEnvelope.isPending.value"
            @confirm="onAddEnvelope"
        />

        <ShareDrawer
            v-if="budgetId !== null && budget !== null"
            :open="showShare"
            :title="$t('budget.share.title')"
            :transfer-resource="$t('budget.share.resource')"
            :members="budget.shares"
            :transfer-to="draftTransferTo"
            :transfer-restriction="
                isDraft ? $t('budget.share.draftTransferNote') : undefined
            "
            :current-user-id="currentUserId"
            :can-manage="isOwner"
            @update:open="showShare = $event"
            @set-permission="onSetSharePermission"
            @revoke="onRevokeShare"
            @transfer="onTransferOwnership"
        />

        <Dialog v-model:open="confirmingPublish">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('budget.confirm.publishTitle', {
                            month: publishMonthLabel,
                        })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('budget.confirm.publishDescription', {
                                month: publishMonthLabel,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
                <Alert v-if="replaceWarning" variant="destructive">
                    <Icon name="warning" class="size-4" aria-hidden="true" />
                    <AlertDescription>
                        {{
                            $t('budget.confirm.replaceWarning', {
                                month: publishMonthLabel,
                            })
                        }}
                    </AlertDescription>
                </Alert>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="confirmingPublish = false"
                        >{{
                            $t('common.actions.cancel')
                        }}</Button
                    >
                    <Button
                        :disabled="working || flushingBeforePublish"
                        @click="onPublish"
                    >
                        <Spinner
                            v-if="
                                publishBudget.isPending.value ||
                                flushingBeforePublish
                            "
                        />
                        {{ $t('budget.confirm.publishButton') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="confirmingDelete">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('budget.confirm.deleteTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ $t('budget.confirm.deleteDescription') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmingDelete = false">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="working"
                        @click="onDelete"
                    >
                        <Spinner v-if="deleteBudget.isPending.value" />
                        {{ $t('budget.confirm.deleteButton') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
