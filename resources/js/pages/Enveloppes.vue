<script setup lang="ts">
import { Head, router, usePage, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/EnveloppeController';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import EnveloppeShareDrawer from '@/components/Enveloppes/EnveloppeShareDrawer.vue';
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
import { enveloppes } from '@/routes';
import type { Enveloppe } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Enveloppes',
                href: enveloppes(),
            },
        ],
    },
});

type Props = {
    enveloppes?: Enveloppe[];
};

const props = withDefaults(defineProps<Props>(), {
    enveloppes: () => [],
});

const meId = computed(() => usePage().props.auth.user.id);

const palette = [
    { name: 'Indigo', value: '#6366f1' },
    { name: 'Blue', value: '#3b82f6' },
    { name: 'Sky', value: '#0ea5e9' },
    { name: 'Teal', value: '#14b8a6' },
    { name: 'Emerald', value: '#10b981' },
    { name: 'Amber', value: '#f59e0b' },
    { name: 'Orange', value: '#f97316' },
    { name: 'Rose', value: '#f43f5e' },
    { name: 'Violet', value: '#8b5cf6' },
    { name: 'Slate', value: '#64748b' },
];

const defaults = {
    name: '',
    type: 'expense' as 'expense' | 'revenue',
    color: '#6366f1',
};

const form = useForm(defaults);

const dialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const toDelete = ref<Enveloppe | null>(null);
const editing = ref<Enveloppe | null>(null);
const shareTargetId = ref<number | null>(null);

const expenseEnveloppes = computed(() =>
    props.enveloppes.filter((c) => c.direction === 'expense'),
);
const revenueEnvelopes = computed(() =>
    props.enveloppes.filter((c) => c.direction === 'revenue'),
);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.defaults({ ...defaults });
    dialogOpen.value = true;
};

const openEdit = (enveloppe: Enveloppe) => {
    editing.value = enveloppe;
    form.defaults({ ...defaults });
    form.reset();
    form.name = enveloppe.name;
    form.type = enveloppe.direction;
    form.color = enveloppe.color;
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

const confirmDelete = (enveloppe: Enveloppe) => {
    toDelete.value = enveloppe;
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
            toast.error(firstPageError() ?? 'Could not delete the envelope.');
        },
    });
};

const shareTarget = computed(
    () => props.enveloppes.find((c) => c.id === shareTargetId.value) ?? null,
);

const roleLabel: Record<string, string> = {
    owner: 'Owned',
    contributor: 'Can edit',
    viewer: 'Can view',
    none: 'Shared',
};
</script>

<template>
    <Head title="Enveloppes" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Enveloppes"
                description="Give every euro, dollar or franc a home — the classic envelope system, minus the paper."
            />
            <Button
                v-if="props.enveloppes.length"
                class="gap-2"
                @click="openCreate"
            >
                <Icon name="add" class="size-5" aria-hidden="true" />
                New envelope
            </Button>
        </div>

        <!-- Empty state -->
        <Card
            v-if="!props.enveloppes.length"
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
                        name="category"
                        class="size-8 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <h3
                    class="mt-6 bg-gradient-to-r from-foreground via-foreground to-primary bg-clip-text text-3xl font-semibold tracking-tight text-transparent sm:text-4xl"
                >
                    Create your first envelope
                </h3>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-balance text-muted-foreground sm:text-base"
                >
                    An envelope for housing, one for groceries, one for fun —
                    envelopes are how Prospera turns a pile of transactions into
                    a clear picture of where your money flows.
                </p>

                <Button
                    size="lg"
                    class="mt-8 gap-2 shadow-lg shadow-primary/25"
                    @click="openCreate"
                >
                    <Icon name="add" class="size-5" aria-hidden="true" />
                    Create envelope
                </Button>
            </CardContent>
        </Card>

        <!-- Enveloppe groups -->
        <div v-else class="space-y-8">
            <section
                v-for="group in [
                    {
                        type: 'expense',
                        title: 'Expenses',
                        empty: 'No expense envelopes yet.',
                    },
                    {
                        type: 'revenue',
                        title: 'Revenue',
                        empty: 'No revenue envelopes yet.',
                    },
                ]"
                :key="group.type"
                class="space-y-4"
            >
                <div class="flex items-center gap-3">
                    <h3
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ group.title }}
                    </h3>
                    <div
                        class="h-px flex-1 bg-gradient-to-r from-border to-transparent"
                    />
                </div>

                <div
                    v-if="
                        group.type === 'expense'
                            ? expenseEnveloppes.length
                            : revenueEnvelopes.length
                    "
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Card
                        v-for="enveloppe in group.type === 'expense'
                            ? expenseEnveloppes
                            : revenueEnvelopes"
                        :key="enveloppe.id"
                        class="group border-sidebar-border/70 py-0 transition-all hover:border-primary/30 hover:shadow-md dark:border-sidebar-border"
                    >
                        <CardContent class="flex items-center gap-3 p-4">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl text-lg font-semibold"
                                :style="{
                                    backgroundColor: enveloppe.color + '1a',
                                    color: enveloppe.color,
                                }"
                            >
                                {{ enveloppe.name.charAt(0).toUpperCase() }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ enveloppe.name }}
                                </p>
                            </div>

                            <Badge
                                v-if="enveloppe.permission !== 'owner'"
                                variant="secondary"
                                class="shrink-0"
                            >
                                {{ roleLabel[enveloppe.permission] }}
                            </Badge>

                            <DropdownMenu
                                v-if="enveloppe.permission === 'owner'"
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 data-[state=open]:opacity-100"
                                        aria-label="Envelope options"
                                    >
                                        <Icon name="more-vert" class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        class="gap-2"
                                        @select="openEdit(enveloppe)"
                                    >
                                        <Icon
                                            name="edit"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="gap-2"
                                        @select="shareTargetId = enveloppe.id"
                                    >
                                        <Icon
                                            name="group"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Share
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="gap-2 text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                        @select="confirmDelete(enveloppe)"
                                    >
                                        <Icon
                                            name="delete"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Delete
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
                    {{ group.empty }}
                </p>
            </section>
        </div>

        <!-- Share drawer -->
        <EnveloppeShareDrawer
            v-if="shareTarget !== null"
            :enveloppe-id="shareTarget.id"
            :open="shareTargetId !== null"
            :can-manage="shareTarget.permission === 'owner'"
            :current-user-id="meId"
            @update:open="shareTargetId = null"
        />

        <!-- Create / Edit dialog -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? 'Edit envelope' : 'New envelope' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Fine-tune how this envelope looks and behaves.'
                                : 'A name, a direction, a color — that is all it takes.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="enveloppe-name">Name</Label>
                        <Input
                            id="enveloppe-name"
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Groceries"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Type</Label>
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
                                Expense
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
                                Revenue
                            </button>
                        </div>
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Color</Label>
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
                                :title="color.name"
                                :aria-label="color.name"
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
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Saving…'
                                    : editing
                                      ? 'Save changes'
                                      : 'Create envelope'
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
                    <DialogTitle>Delete envelope?</DialogTitle>
                    <DialogDescription>
                        <template v-if="toDelete">
                            “{{ toDelete.name }}” will be removed from your
                            envelopes. Existing transactions are not deleted.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="deleteDialogOpen = false">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="remove">
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
