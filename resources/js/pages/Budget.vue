<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useQueryClient } from '@tanstack/vue-query';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import AddEnvelopeDialog from '@/components/Budget/AddEnvelopeDialog.vue';
import BudgetEnvelopeTable from '@/components/Budget/BudgetEnvelopeTable.vue';
import BudgetFlowBar from '@/components/Budget/BudgetFlowBar.vue';
import BudgetSelector from '@/components/Budget/BudgetSelector.vue';
import CloneBudgetDialog from '@/components/Budget/CloneBudgetDialog.vue';
import CreateBudgetDialog from '@/components/Budget/CreateBudgetDialog.vue';
import Heading from '@/components/Heading.vue';
import HelpLink from '@/components/HelpLink.vue';
import Icon from '@/components/Icon.vue';
import BudgetLoadingScreen from '@/components/Loading/BudgetLoadingScreen.vue';
import MilestoneDialog from '@/components/onboarding/MilestoneDialog.vue';
import OnboardingIllustration from '@/components/onboarding/OnboardingIllustration.vue';
import RequirementList from '@/components/onboarding/RequirementList.vue';
import SetupJourneyBar from '@/components/onboarding/SetupJourneyBar.vue';
import TeachingEmptyState from '@/components/onboarding/TeachingEmptyState.vue';
import QueryErrorState from '@/components/QueryErrorState.vue';
import ShareDrawer from '@/components/share/ShareDrawer.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { useOnboardingProgress } from '@/composables/onboardingQueries';
import { useProfileMembers } from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useFlushOnLeave } from '@/composables/useFlushOnLeave';
import { useLocale } from '@/composables/useLocale';
import { useOnboardingJourney } from '@/composables/useOnboardingJourney';
import { useOnceFlag } from '@/composables/useOnceFlag';
import { errorMessage } from '@/lib/api';
import { budgetMonthLabel } from '@/lib/budget';
import type { AddEnvelopeInput } from '@/lib/budgetApi';
import { type BudgetSaveStatus, budgetSaveQueue } from '@/lib/budgetSaveQueue';
import { currentMonth, parseMonth } from '@/lib/calendar';
import { stripDeepLink } from '@/lib/focusAnchor';
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
const onboardingQuery = useOnboardingProgress();
const onboarding = computed(() => onboardingQuery.data.value);
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

// --- first-run setup (docs/onboarding.md) ----------------------------------
const { journey } = useOnboardingJourney();
const profileKey = (name: string) => () =>
    currentProfile.value
        ? `prospera.onboarding.${name}.${currentProfile.value.id}`
        : null;
const budgetReadyMilestone = useOnceFlag(profileKey('budget-ready'));
const firstBudgetMilestone = useOnceFlag(profileKey('first-budget'));
const budgetReadyOpen = ref(false);
const firstBudgetOpen = ref(false);

/** The first budget was never created and nothing gates it any more. */
const firstBudgetReady = computed(
    () => journey.value?.current === 'budget' && journey.value.canCreateBudget,
);

// The 30 days may have passed while the user was away: celebrate on load.
watch(
    () => [firstBudgetReady.value, budgetReadyMilestone.loaded.value] as const,
    ([ready, loaded]) => {
        if (ready && loaded && !budgetReadyMilestone.seen.value) {
            budgetReadyMilestone.markSeen();
            budgetReadyOpen.value = true;
        }
    },
    { immediate: true },
);

const openCreateFromMilestone = (): void => {
    budgetReadyOpen.value = false;
    showCreate.value = true;
};

// Deep link: ?create=1 opens the create dialog once creation is allowed.
const pendingCreate = ref(false);
onMounted(() => {
    pendingCreate.value = /[?&]create=1(?:&|#|$)/.test(usePage().url);
    if (pendingCreate.value) {
        stripDeepLink();
    }
});
watch(
    () =>
        [
            pendingCreate.value,
            isProfileOwner.value,
            onboarding.value?.can_create_budget === true ||
                budgets.value.length > 0,
        ] as const,
    ([pending, owner, allowed]) => {
        if (pending && owner && allowed) {
            pendingCreate.value = false;
            // The budget-ready milestone would only repeat what the dialog does.
            if (!budgetReadyMilestone.seen.value) {
                budgetReadyMilestone.markSeen();
            }
            budgetReadyOpen.value = false;
            showCreate.value = true;
        }
    },
);

const publishMonthName = computed(() => {
    const [year, month] = parseMonth(currentMonth());
    return budgetMonthLabel(year, month, locale.value);
});

const onCreate = (currency: string) => {
    const wasFirst =
        journey.value !== null && journey.value.current === 'budget';
    createBudget.mutate(currency, {
        onSuccess: (data) => {
            showCreate.value = false;
            selectedId.value = data.budget.id;
            toast.success(t('budget.toast.draftCreated'));
            if (wasFirst && !firstBudgetMilestone.seen.value) {
                firstBudgetMilestone.markSeen();
                firstBudgetOpen.value = true;
            }
        },
        onError: fail,
    });
};

const budgetRequirements = computed(() => {
    const c = journey.value?.challenge;
    if (!c) {
        return [];
    }
    return [
        {
            label: t('onboarding.requirements.history'),
            value: t('onboarding.requirements.historyValue', { days: c.days }),
            done: c.days >= c.total,
        },
        {
            label: t('onboarding.requirements.envelopes'),
            value: t(
                'onboarding.requirements.envelopesValue',
                { covered: c.covered, n: c.totalEnvelopes },
                c.totalEnvelopes,
            ),
            done: c.missing === 0 && c.totalEnvelopes > 0,
        },
    ];
});

const unlockDate = computed(() => {
    const date = journey.value?.challenge.unlockDate;
    return date
        ? new Intl.DateTimeFormat(locale.value, {
              month: 'long',
              day: 'numeric',
          }).format(date)
        : null;
});

/** Which empty state to teach with (owner, before any budget). */
const emptyState = computed(() => {
    if (!isProfileOwner.value) {
        return 'notOwner';
    }
    const progress = onboarding.value;
    if (progress === undefined) {
        // Progress failed to load: the server still enforces the gate.
        return onboardingQuery.isError.value ? 'again' : 'pending';
    }
    if (progress.first_budget_created) {
        return 'again';
    }
    return progress.can_create_budget ? 'ready' : 'locked';
});

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
 *
 * Saving goes through the module-level save queue (`lib/budgetSaveQueue.ts`):
 * one request per `(budget, envelope)` at a time, the newest amount sent
 * next, failures retained until re-saved — publishing waits for it.
 */
const SAVE_DELAY_MS = 600;

/** Autosave status shown next to the table. */
const saveStatus = ref<BudgetSaveStatus>('idle');

watch(budgetId, () => {
    saveStatus.value = budgetSaveQueue.status(budgetId.value ?? 0);
});

budgetSaveQueue.subscribe(() => {
    saveStatus.value = budgetSaveQueue.status(budgetId.value ?? 0);
});

/** The revision each assignment was last known to have (optimistic cache included). */
const revisionFromCache = (id: number, envelopeId: number): number =>
    queryClient
        .getQueryData<{ budget: BudgetDetail }>(budgetKeys.detail(id))
        ?.budget.envelopes.find((e) => e.envelope_id === envelopeId)
        ?.revision ?? 0;

budgetSaveQueue.configure({
    transport: async ({ budgetId, envelopeId, amount, revision }) => {
        try {
            const { envelope } = await updateEnvelope.mutateAsync({
                budgetId,
                envelopeId,
                amount,
                expectedRevision: revision,
            });

            return envelope.revision;
        } catch (error) {
            fail(error);
            // Re-read the assignment: a conflict must not keep queueing
            // against the revision that was refused.
            void queryClient
                .invalidateQueries({ queryKey: budgetKeys.detail(budgetId) })
                .then(() => {
                    const fresh = queryClient.getQueryData<{
                        budget: BudgetDetail;
                    }>(budgetKeys.detail(budgetId));
                    if (fresh) {
                        budgetSaveQueue.syncRevisions(
                            budgetId,
                            fresh.budget.envelopes,
                        );
                    }
                });
            throw error;
        }
    },
    revisionProvider: revisionFromCache,
    delayMs: SAVE_DELAY_MS,
});

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
    budgetSaveQueue.queue(id, envelopeId, amount);
};

/**
 * Sends every debounced amount edit now and waits for all saves to land;
 * false when one of them failed.
 */
const flushAmountSaves = async (): Promise<boolean> =>
    budgetSaveQueue.flush(budgetId.value ?? undefined);

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
        >
            <template #title-after>
                <HelpLink topic="budgets" />
            </template>
        </Heading>

        <SetupJourneyBar
            v-if="!waitingForData && !budgetsLoadFailed"
            page="budget"
            :suppress-body="budgets.length === 0"
        />

        <BudgetLoadingScreen v-if="waitingForData" />

        <QueryErrorState
            v-else-if="budgetsLoadFailed"
            :message="$t('budget.loadError')"
            :retrying="budgetsQuery.isFetching.value"
            @retry="retryBudgets"
        />

        <!-- No budgets yet: what unlocks the first one, or how to start. -->
        <TeachingEmptyState
            v-else-if="budgets.length === 0 && emptyState === 'locked'"
            :title="$t('budget.empty.lockedTitle')"
            :description="$t('budget.empty.lockedDescription')"
        >
            <template #illustration>
                <OnboardingIllustration name="budgetDial" locked />
            </template>
            <template #body>
                <div class="grid gap-3">
                    <RequirementList :items="budgetRequirements" />
                    <p v-if="unlockDate" class="text-sm text-muted-foreground">
                        {{
                            $t('onboarding.route.unlocksAround', {
                                date: unlockDate,
                            })
                        }}
                    </p>
                </div>
            </template>
            <template #actions>
                <Button as-child class="gap-2">
                    <Link href="/ledger#add-transaction">
                        {{ $t('budget.empty.lockedAction') }}
                        <Icon
                            name="arrow-forward"
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                    </Link>
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/help/budgets">{{
                        $t('budget.empty.guide')
                    }}</Link>
                </Button>
            </template>
        </TeachingEmptyState>

        <TeachingEmptyState
            v-else-if="budgets.length === 0 && emptyState === 'notOwner'"
            :title="$t('budget.empty.onlyOwnerTitle')"
            :description="$t('budget.empty.onlyOwner')"
        >
            <template #illustration>
                <OnboardingIllustration name="budgetDial" />
            </template>
            <template #actions>
                <Button variant="ghost" as-child>
                    <Link href="/help/budgets">{{
                        $t('budget.empty.guide')
                    }}</Link>
                </Button>
            </template>
        </TeachingEmptyState>

        <TeachingEmptyState
            v-else-if="budgets.length === 0"
            :title="
                emptyState === 'ready'
                    ? $t('budget.empty.readyTitle')
                    : $t('budget.empty.title')
            "
            :description="
                emptyState === 'ready'
                    ? $t('budget.empty.readyDescription')
                    : $t('budget.empty.description')
            "
        >
            <template #illustration>
                <OnboardingIllustration name="budgetDial" />
            </template>
            <template #actions>
                <Button
                    v-if="emptyState !== 'pending'"
                    class="gap-2"
                    @click="showCreate = true"
                >
                    <Icon name="add" class="size-4" aria-hidden="true" />
                    {{
                        emptyState === 'ready'
                            ? $t('budget.empty.readyAction')
                            : $t('budget.empty.create')
                    }}
                </Button>
                <p v-else class="text-sm text-muted-foreground" role="status">
                    {{ $t('common.loading') }}
                </p>
                <Button variant="ghost" as-child>
                    <Link href="/help/budgets">{{
                        $t('budget.empty.guide')
                    }}</Link>
                </Button>
            </template>
        </TeachingEmptyState>

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

        <MilestoneDialog
            v-model:open="budgetReadyOpen"
            icon="event-available"
            :title="$t('onboarding.milestones.budgetReady.title')"
            :description="$t('onboarding.milestones.budgetReady.description')"
            :secondary-label="$t('onboarding.milestones.budgetReady.secondary')"
            secondary-href="/ledger"
            :primary-label="$t('onboarding.milestones.budgetReady.primary')"
            :journey="journey"
            @primary="openCreateFromMilestone"
        />

        <MilestoneDialog
            v-model:open="firstBudgetOpen"
            icon="flag"
            :title="$t('onboarding.milestones.firstBudget.title')"
            :description="
                $t('onboarding.milestones.firstBudget.description', {
                    month: publishMonthName,
                })
            "
            :secondary-label="$t('onboarding.milestones.firstBudget.secondary')"
            secondary-href="/help/budgets"
            :primary-label="$t('onboarding.milestones.firstBudget.primary')"
            @primary="firstBudgetOpen = false"
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
