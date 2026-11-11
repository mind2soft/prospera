<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/EnvelopeController';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import ShareDrawer from '@/components/share/ShareDrawer.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    useEnvelopeShares,
    useRemoveEnvelopeShare,
    useSetEnvelopeShare,
    useTransferEnvelopeOwnership,
} from '@/composables/envelopeQueries';
import { useProfileCircle } from '@/composables/profileQueries';
import { ApiError } from '@/lib/api';
import { envelopes as envelopesRoute } from '@/routes';
import type { Envelope, ResourcePermission } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'envelopes.title',
                href: envelopesRoute(),
            },
        ],
    },
});

const { t } = useI18n();

type Props = {
    envelopes?: Envelope[];
};

const props = withDefaults(defineProps<Props>(), {
    envelopes: () => [],
});

const meId = computed(() => usePage().props.auth.user.id);

const palette = [
    { name: 'envelopes.colors.indigo', value: '#6366f1' },
    { name: 'envelopes.colors.blue', value: '#3b82f6' },
    { name: 'envelopes.colors.sky', value: '#0ea5e9' },
    { name: 'envelopes.colors.teal', value: '#14b8a6' },
    { name: 'envelopes.colors.emerald', value: '#10b981' },
    { name: 'envelopes.colors.amber', value: '#f59e0b' },
    { name: 'envelopes.colors.orange', value: '#f97316' },
    { name: 'envelopes.colors.rose', value: '#f43f5e' },
    { name: 'envelopes.colors.violet', value: '#8b5cf6' },
    { name: 'envelopes.colors.slate', value: '#64748b' },
];

const defaults = {
    name: '',
    type: 'expense' as 'expense' | 'revenue',
    color: '#6366f1',
};

const form = useForm(defaults);

const dialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const toDelete = ref<Envelope | null>(null);
const editing = ref<Envelope | null>(null);
const shareTargetId = ref<number | null>(null);

const expenseEnvelopes = computed(() =>
    props.envelopes.filter((c) => c.direction === 'expense'),
);
const revenueEnvelopes = computed(() =>
    props.envelopes.filter((c) => c.direction === 'revenue'),
);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.defaults({ ...defaults });
    dialogOpen.value = true;
};

const openEdit = (envelope: Envelope) => {
    editing.value = envelope;
    form.defaults({ ...defaults });
    form.reset();
    form.name = envelope.name;
    form.type = envelope.direction;
    form.color = envelope.color;
    dialogOpen.value = true;
};

/**
 * Validation failures come back as Inertia errors — surface them as a toast
 * alongside the inline field errors so destructive/mutation actions never
 * fail silently.
 */
const failForm = () => {
    const first = Object.values(form.errors)[0];
    if (first) {
        toast.error(first);
    }
};

const firstPageError = (): string | undefined => {
    const errors = usePage().props.errors as
        | Record<string, string | string[]>
        | undefined;
    const first = Object.values(errors ?? {})[0];
    return Array.isArray(first) ? first[0] : first;
};

const save = () => {
    if (editing.value) {
        form.put(update.url(editing.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
            },
            onError: failForm,
        });
    } else {
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.reset();
            },
            onError: failForm,
        });
    }
};

const confirmDelete = (envelope: Envelope) => {
    toDelete.value = envelope;
    deleteDialogOpen.value = true;
};

const remove = () => {
    if (!toDelete.value) {
        return;
    }
    router.delete(destroy.url(toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
            toDelete.value = null;
        },
        onError: () => {
            toast.error(firstPageError() ?? t('envelopes.delete.failed'));
        },
    });
};

const shareTarget = computed(
    () => props.envelopes.find((c) => c.id === shareTargetId.value) ?? null,
);

// --- share drawer (mutations live here — the drawer is presentational) -----
const sharesQuery = useEnvelopeShares(computed(() => shareTargetId.value));
const circleQuery = useProfileCircle(
    computed(() => shareTargetId.value !== null),
);

const setShare = useSetEnvelopeShare();
const removeShare = useRemoveEnvelopeShare();
const transferOwnership = useTransferEnvelopeOwnership();

const shareMembers = computed(() => sharesQuery.data.value ?? []);

const shareFail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : t('share.toast.failed'));
};

const onSetSharePermission = (
    userId: number,
    permission: ResourcePermission,
) => {
    const id = shareTargetId.value;
    if (id === null) {
        return;
    }
    setShare.mutate(
        { envelopeId: id, userId, permission },
        {
            onSuccess: () => toast.success(t('share.toast.accessUpdated')),
            onError: shareFail,
        },
    );
};

const onGrantShare = (userId: number, permission: ResourcePermission) => {
    const id = shareTargetId.value;
    if (id === null) {
        return;
    }
    setShare.mutate(
        { envelopeId: id, userId, permission },
        {
            onSuccess: () => toast.success(t('share.toast.accessGranted')),
            onError: shareFail,
        },
    );
};

const onRevokeShare = (userId: number) => {
    const id = shareTargetId.value;
    if (id === null) {
        return;
    }
    removeShare.mutate(
        { envelopeId: id, userId },
        {
            onSuccess: () => toast.success(t('share.toast.accessRevoked')),
            onError: shareFail,
        },
    );
};

const onTransferOwnership = (userId: number) => {
    const id = shareTargetId.value;
    if (id === null) {
        return;
    }
    transferOwnership.mutate(
        { envelopeId: id, userId },
        {
            onSuccess: () => {
                // Envelope ownership changed — the Inertia props (and with
                // them `canManage`) are stale until reload; close the drawer.
                shareTargetId.value = null;
                toast.success(t('share.toast.ownershipTransferred'));
            },
            onError: shareFail,
        },
    );
};

const roleLabel: Record<string, string> = {
    owner: 'envelopes.role.owner',
    contributor: 'envelopes.role.contributor',
    viewer: 'envelopes.role.viewer',
    none: 'envelopes.role.none',
};
</script>

<template>
    <Head :title="$t('envelopes.title')" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                :title="$t('envelopes.title')"
                :description="$t('envelopes.description')"
            />
            <Button
                v-if="props.envelopes.length"
                class="gap-2"
                @click="openCreate"
            >
                <Icon name="add" class="size-5" aria-hidden="true" />
                {{ $t('envelopes.newEnvelope') }}
            </Button>
        </div>

        <!-- Empty state -->
        <Card
            v-if="!props.envelopes.length"
            class="border-sidebar-border/70 dark:border-sidebar-border"
        >
            <CardContent
                class="flex flex-col items-center px-6 py-16 text-center sm:py-20"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-2xl bg-primary/10"
                >
                    <Icon
                        name="category"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h2
                    class="mt-6 font-display text-3xl leading-9 tracking-tight text-foreground"
                >
                    {{ $t('envelopes.empty.title') }}
                </h2>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    {{ $t('envelopes.empty.description') }}
                </p>

                <Button size="lg" class="mt-8 gap-2" @click="openCreate">
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    {{ $t('envelopes.empty.create') }}
                </Button>
            </CardContent>
        </Card>

        <!-- Envelope groups -->
        <div v-else class="space-y-8">
            <section
                v-for="group in [
                    {
                        type: 'expense',
                        title: 'envelopes.groups.expenses',
                        empty: 'envelopes.groups.expensesEmpty',
                    },
                    {
                        type: 'revenue',
                        title: 'envelopes.groups.revenue',
                        empty: 'envelopes.groups.revenueEmpty',
                    },
                ]"
                :key="group.type"
                class="space-y-4"
            >
                <div class="flex items-center gap-3">
                    <h2 class="text-sm font-medium text-muted-foreground">
                        {{ $t(group.title) }}
                    </h2>
                    <div
                        class="h-px flex-1 bg-linear-to-r from-border to-transparent"
                    />
                </div>

                <div
                    v-if="
                        group.type === 'expense'
                            ? expenseEnvelopes.length
                            : revenueEnvelopes.length
                    "
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Card
                        v-for="envelope in group.type === 'expense'
                            ? expenseEnvelopes
                            : revenueEnvelopes"
                        :key="envelope.id"
                        class="group border-sidebar-border/70 py-0 transition-all hover:border-primary/30 hover:shadow-md dark:border-sidebar-border"
                    >
                        <CardContent class="flex items-center gap-3 p-4">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl border-2 text-lg font-semibold text-foreground"
                                :style="{
                                    backgroundColor: `${envelope.color}1a`,
                                    borderColor: envelope.color,
                                }"
                                aria-hidden="true"
                            >
                                {{ envelope.name.charAt(0).toUpperCase() }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ envelope.name }}
                                </p>
                            </div>

                            <Badge
                                v-if="envelope.permission !== 'owner'"
                                variant="secondary"
                                class="shrink-0"
                            >
                                {{ $t(roleLabel[envelope.permission]) }}
                            </Badge>

                            <DropdownMenu
                                v-if="envelope.permission === 'owner'"
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 text-muted-foreground opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100 pointer-coarse:opacity-100"
                                        :aria-label="
                                            $t('envelopes.optionsFor', {
                                                name: envelope.name,
                                            })
                                        "
                                    >
                                        <Icon
                                            name="more-vert"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        class="gap-2"
                                        @select="openEdit(envelope)"
                                    >
                                        <Icon
                                            name="edit"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ $t('common.actions.edit') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="gap-2"
                                        @select="shareTargetId = envelope.id"
                                    >
                                        <Icon
                                            name="group"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ $t('envelopes.menu.share') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        variant="destructive"
                                        class="gap-2"
                                        @select="confirmDelete(envelope)"
                                    >
                                        <Icon
                                            name="delete"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ $t('common.actions.delete') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </CardContent>
                    </Card>
                </div>

                <p
                    v-else
                    class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    {{ $t(group.empty) }}
                </p>
            </section>
        </div>

        <!-- Share drawer -->
        <ShareDrawer
            v-if="shareTarget !== null"
            :open="shareTargetId !== null"
            :title="$t('share.envelope.title')"
            :description="$t('share.envelope.description')"
            :transfer-resource="$t('share.envelope.resource')"
            :members="shareMembers"
            :loading="sharesQuery.isPending.value"
            :current-user-id="meId"
            :can-manage="shareTarget.permission === 'owner'"
            :profile-circle="circleQuery.data.value ?? []"
            @update:open="shareTargetId = null"
            @set-permission="onSetSharePermission"
            @grant="onGrantShare"
            @revoke="onRevokeShare"
            @transfer="onTransferOwnership"
        />

        <!-- Create / Edit dialog -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editing
                                ? $t('envelopes.dialog.editTitle')
                                : $t('envelopes.dialog.newTitle')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? $t('envelopes.dialog.editDescription')
                                : $t('envelopes.dialog.newDescription')
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="envelope-name">{{
                            $t('common.fields.name')
                        }}</Label>
                        <Input
                            id="envelope-name"
                            v-model="form.name"
                            type="text"
                            :placeholder="$t('envelopes.form.namePlaceholder')"
                            autocomplete="off"
                        />
                        <InputError
                            for="envelope-name"
                            :message="form.errors.name"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label>{{ $t('envelopes.form.type') }}</Label>
                        <div
                            class="grid grid-cols-2 gap-1 rounded-xl border border-border bg-muted/40 p-1"
                        >
                            <button
                                type="button"
                                class="flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all"
                                :class="
                                    form.type === 'expense'
                                        ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="form.type = 'expense'"
                            >
                                <Icon
                                    name="south-west"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ $t('envelopes.form.expense') }}
                            </button>
                            <button
                                type="button"
                                class="flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all"
                                :class="
                                    form.type === 'revenue'
                                        ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="form.type = 'revenue'"
                            >
                                <Icon
                                    name="north-east"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ $t('envelopes.form.revenue') }}
                            </button>
                        </div>
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label>{{ $t('envelopes.form.color') }}</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in palette"
                                :key="color.value"
                                type="button"
                                class="size-8 rounded-full transition-transform hover:scale-110"
                                :class="
                                    form.color === color.value
                                        ? 'ring-2 ring-ring ring-offset-2 ring-offset-background'
                                        : 'ring-1 ring-border'
                                "
                                :style="{ backgroundColor: color.value }"
                                :title="$t(color.name)"
                                :aria-label="$t(color.name)"
                                @click="form.color = color.value"
                            />
                        </div>
                        <InputError :message="form.errors.color" />
                    </div>

                    <DialogFooter class="gap-2 pt-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="dialogOpen = false"
                        >
                            {{ $t('common.actions.cancel') }}
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? $t('envelopes.form.saving')
                                    : editing
                                      ? $t('envelopes.form.saveChanges')
                                      : $t('envelopes.form.create')
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete confirmation dialog -->
        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('envelopes.delete.title')
                    }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="toDelete">
                            {{
                                $t('envelopes.delete.description', {
                                    name: toDelete.name,
                                })
                            }}
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="deleteDialogOpen = false">
                        {{ $t('common.actions.cancel') }}
                    </Button>
                    <Button variant="destructive" @click="remove">
                        {{ $t('common.actions.delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
