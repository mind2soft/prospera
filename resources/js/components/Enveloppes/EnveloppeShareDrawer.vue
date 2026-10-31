<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useProfileCircle } from '@/composables/profileQueries';
import {
    useEnveloppeShares,
    useRemoveEnveloppeShare,
    useSetEnveloppeShare,
    useTransferEnveloppeOwnership,
} from '@/composables/enveloppeQueries';
import { ApiError } from '@/lib/api';
import type { ResourceMember, ResourcePermission } from '@/types';

const props = defineProps<{
    enveloppeId: number;
    open: boolean;
    /** True when the current user owns the enveloppe. */
    canManage: boolean;
    currentUserId: number;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const sharesQuery = useEnveloppeShares(
    computed(() => (props.open ? props.enveloppeId : null)),
);
const circleQuery = useProfileCircle(computed(() => props.open));

const setShare = useSetEnveloppeShare();
const removeShare = useRemoveEnveloppeShare();
const transferOwnership = useTransferEnveloppeOwnership();

const fail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : 'Something went wrong.');
};

const shares = computed(() => sharesQuery.data.value ?? []);

const circle = computed(() => circleQuery.data.value ?? []);

/** Grantable people: profile-circle members without an explicit row yet. */
const candidates = computed(() => {
    const shared = new Set(shares.value.map((s) => s.user_id));
    return circle.value.filter((member) => !shared.has(member.id));
});

// --- add someone ------------------------------------------------------------
const newUserId = ref<number | null>(null);
const newPermission = ref<'contributor' | 'viewer'>('contributor');

const add = () => {
    if (newUserId.value === null) {
        return;
    }
    setShare.mutate(
        {
            enveloppeId: props.enveloppeId,
            userId: newUserId.value,
            permission: newPermission.value,
        },
        {
            onSuccess: () => {
                newUserId.value = null;
                toast.success('Access granted.');
            },
            onError: fail,
        },
    );
};

// --- change / revoke --------------------------------------------------------
const onPermissionChange = (share: ResourceMember, permission: string) => {
    if (!props.canManage) {
        return;
    }
    setShare.mutate(
        {
            enveloppeId: props.enveloppeId,
            userId: share.user_id,
            permission: permission as ResourcePermission,
        },
        {
            onSuccess: () => toast.success('Access updated.'),
            onError: fail,
        },
    );
};

const onRevoke = (share: ResourceMember) => {
    removeShare.mutate(
        { enveloppeId: props.enveloppeId, userId: share.user_id },
        {
            onSuccess: () => toast.success('Access revoked.'),
            onError: fail,
        },
    );
};

// --- transfer ---------------------------------------------------------------
const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);

const askTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    transferOwnership.mutate(
        { enveloppeId: props.enveloppeId, userId: transferTarget.value },
        {
            onSuccess: () => {
                transferTarget.value = null;
                toast.success('Ownership transferred.');
                emit('update:open', false);
            },
            onError: (e) => {
                transferTarget.value = null;
                fail(e);
            },
        },
    );
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            newUserId.value = null;
            newPermission.value = 'contributor';
            transferTarget.value = null;
            confirmingTransfer.value = false;
        }
    },
);
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent class="flex flex-col gap-6 overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>Share enveloppe</SheetTitle>
                <SheetDescription
                    >People who share a profile with you.</SheetDescription
                >
            </SheetHeader>

            <p
                v-if="sharesQuery.isLoading.value"
                class="py-4 text-sm text-muted-foreground"
            >
                Loading…
            </p>
            <p
                v-else-if="shares.length === 0"
                class="py-4 text-sm text-muted-foreground"
            >
                Not shared with anyone yet.
            </p>
            <div v-else class="space-y-1">
                <div
                    v-for="share in shares"
                    :key="share.user_id"
                    class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50"
                >
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium uppercase"
                    >
                        {{ share.name.slice(0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ share.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ share.email }}
                        </p>
                    </div>
                    <Select
                        v-if="canManage"
                        :model-value="share.permission"
                        @update:model-value="
                            (value) => onPermissionChange(share, String(value))
                        "
                    >
                        <SelectTrigger size="sm" class="w-28 shrink-0">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="contributor"
                                >Can edit</SelectItem
                            >
                            <SelectItem value="viewer">Can view</SelectItem>
                        </SelectContent>
                    </Select>
                    <span
                        v-else
                        class="shrink-0 text-xs font-medium text-muted-foreground"
                        >{{
                            share.permission === 'contributor'
                                ? 'Can edit'
                                : 'Can view'
                        }}</span
                    >
                    <Button
                        v-if="canManage"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground hover:text-destructive"
                        aria-label="Revoke access"
                        @click="onRevoke(share)"
                    >
                        <Icon name="close" class="size-4" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <template v-if="canManage">
                <Separator />

                <div class="space-y-2">
                    <p class="text-sm font-medium">Grant access</p>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="newUserId ?? undefined"
                            @update:model-value="
                                (value) => (newUserId = Number(value))
                            "
                        >
                            <SelectTrigger class="flex-1">
                                <SelectValue placeholder="Choose a member" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in candidates"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="newPermission">
                            <SelectTrigger size="sm" class="w-28 shrink-0">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="contributor"
                                    >Can edit</SelectItem
                                >
                                <SelectItem value="viewer">Can view</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="newUserId === null"
                            class="shrink-0 gap-2"
                            @click="add"
                        >
                            <Icon
                                name="add"
                                class="size-4"
                                aria-hidden="true"
                            />
                            Add
                        </Button>
                    </div>
                </div>

                <div
                    v-if="
                        circle.filter((m) => m.id !== currentUserId).length > 0
                    "
                    class="space-y-2"
                >
                    <p class="text-sm font-medium">Transfer ownership</p>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="transferTarget ?? undefined"
                            @update:model-value="
                                (value) => (transferTarget = Number(value))
                            "
                        >
                            <SelectTrigger class="flex-1">
                                <SelectValue placeholder="Choose a member" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in circle.filter(
                                        (m) => m.id !== currentUserId,
                                    )"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="transferTarget === null"
                            class="shrink-0 gap-2"
                            @click="askTransfer"
                        >
                            <Icon
                                name="swap-horiz"
                                class="size-4"
                                aria-hidden="true"
                            />
                            Transfer
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The new owner gets full control; you become a
                        contributor.
                    </p>
                </div>
            </template>

            <Dialog
                :open="confirmingTransfer"
                @update:open="confirmingTransfer = false"
            >
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Transfer ownership?</DialogTitle>
                        <DialogDescription>
                            {{
                                circle.find((m) => m.id === transferTarget)
                                    ?.name
                            }}
                            will become the owner of this enveloppe and get full
                            control over it. You will become a contributor.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            @click="confirmingTransfer = false"
                            >Cancel</Button
                        >
                        <Button @click="confirmTransfer">Transfer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SheetContent>
    </Sheet>
</template>
