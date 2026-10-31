<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import type { ResourceMember, ResourcePermission } from '@/types';

const props = defineProps<{
    open: boolean;
    title: string;
    /** Effective permission rows (implicit viewer included). */
    members: ResourceMember[];
    currentUserId: number;
    /** True when the current user owns the resource. */
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'set-permission', userId: number, permission: ResourcePermission): void;
    (e: 'revoke', userId: number): void;
    (e: 'transfer', userId: number): void;
}>();

const permissionLabels: Record<ResourcePermission, string> = {
    owner: 'Owner',
    contributor: 'Can edit',
    viewer: 'Can view',
};

const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);

const transferCandidates = computed(() =>
    props.members.filter(
        (member) =>
            member.permission !== 'owner' &&
            member.user_id !== props.currentUserId,
    ),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            transferTarget.value = null;
            confirmingTransfer.value = false;
        }
    },
);

const onPermissionChange = (
    member: ResourceMember,
    permission: string,
): void => {
    if (!props.canManage || member.permission === 'owner') {
        return;
    }
    emit('set-permission', member.user_id, permission as ResourcePermission);
};

const askTransfer = (): void => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = (): void => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    emit('transfer', transferTarget.value);
    transferTarget.value = null;
};
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent class="flex flex-col gap-6 overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription
                    >Members of the profile this resource belongs
                    to.</SheetDescription
                >
            </SheetHeader>

            <p
                v-if="members.length === 0"
                class="py-4 text-sm text-muted-foreground"
            >
                Loading…
            </p>

            <div v-else class="space-y-1">
                <div
                    v-for="member in members"
                    :key="member.user_id"
                    class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50"
                >
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium uppercase"
                    >
                        {{ member.name.slice(0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ member.name }}
                            <span
                                v-if="member.user_id === currentUserId"
                                class="text-xs font-normal text-muted-foreground"
                                >(you)</span
                            >
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>
                    <Select
                        v-if="canManage && member.permission !== 'owner'"
                        :model-value="member.permission"
                        @update:model-value="
                            (value) => onPermissionChange(member, String(value))
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
                        >{{ permissionLabels[member.permission] }}</span
                    >
                    <Button
                        v-if="canManage && member.permission !== 'owner'"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground hover:text-destructive"
                        aria-label="Revoke access"
                        @click="emit('revoke', member.user_id)"
                    >
                        <Icon name="close" class="size-4" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <template v-if="canManage">
                <Separator />

                <div class="space-y-2">
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
                                    v-for="member in transferCandidates"
                                    :key="member.user_id"
                                    :value="member.user_id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="transferTarget === null"
                            class="gap-2"
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
                                members.find(
                                    (m) => m.user_id === transferTarget,
                                )?.name
                            }}
                            will become the owner of this
                            {{ title.toLowerCase().replace(/^share /, '') }} and
                            get full control over it. You will become a
                            contributor.
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
