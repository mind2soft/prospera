<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    useCreateInvite,
    useCreateProfile,
    useDeleteProfile,
    usePendingInvites,
    useProfileMembers,
    useProfiles,
    useRemoveMember,
    useRenameProfile,
    useRevokeInvite,
    useTransferProfileOwnership,
} from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { ApiError } from '@/lib/api';
import { edit as editProfiles } from '@/routes/profiles';
import type { FinancialProfile, ProfileInvite, ProfileMember } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Profiles', href: editProfiles() }],
    },
});

// --- profiles ----------------------------------------------------------------
const meId = computed(() => usePage().props.auth.user.id);

const {
    profiles,
    profileId: currentProfileId,
    select,
    isLoading,
} = useCurrentProfile();

const createProfile = useCreateProfile();
const renameProfile = useRenameProfile();
const deleteProfile = useDeleteProfile();
const removeMember = useRemoveMember();
const transferOwnership = useTransferProfileOwnership();
const createInvite = useCreateInvite();
const revokeInvite = useRevokeInvite();

const fail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : 'Something went wrong.');
};

const expandedId = ref<number | null>(null);

const toggleExpanded = (profile: FinancialProfile) => {
    expandedId.value = expandedId.value === profile.id ? null : profile.id;
};

// Auto-expand the current profile on first load.
watch(
    profiles,
    (list) => {
        if (expandedId.value === null && list.length > 0) {
            expandedId.value = currentProfileId.value ?? list[0].id;
        }
    },
    { immediate: true },
);

// --- rename (auto-save) ------------------------------------------------------
const renamingId = ref<number | null>(null);
const renameValue = ref('');

const startRename = (profile: FinancialProfile) => {
    renamingId.value = profile.id;
    renameValue.value = profile.name;
};

const commitRename = (profile: FinancialProfile) => {
    const name = renameValue.value.trim();
    renamingId.value = null;
    if (name === '' || name === profile.name) {
        return;
    }
    renameProfile.mutate(
        { profileId: profile.id, name },
        {
            onSuccess: () => toast.success('Profile renamed.'),
            onError: fail,
        },
    );
};

const cancelRename = () => {
    renamingId.value = null;
};

// --- create ------------------------------------------------------------------
const showCreate = ref(false);
const newName = ref('');
const newNameError = ref<string | undefined>(undefined);

const onCreate = () => {
    const name = newName.value.trim();
    if (name === '') {
        return;
    }
    createProfile.mutate(name, {
        onSuccess: () => {
            showCreate.value = false;
            newName.value = '';
            toast.success('Profile created.');
        },
        onError: (e) => {
            newNameError.value =
                e instanceof ApiError ? e.firstError : undefined;
            fail(e);
        },
    });
};

// --- delete ------------------------------------------------------------------
const deleteTarget = ref<FinancialProfile | null>(null);

const onDelete = () => {
    const target = deleteTarget.value;
    if (target === null) {
        return;
    }
    deleteProfile.mutate(target.id, {
        onSuccess: () => {
            deleteTarget.value = null;
            toast.success('Profile deleted.');
        },
        onError: (e) => {
            deleteTarget.value = null;
            fail(e);
        },
    });
};

// --- members -----------------------------------------------------------------
const expandedProfile = computed(
    () => profiles.value.find((p) => p.id === expandedId.value) ?? null,
);

const membersQuery = useProfileMembers(
    computed(() => (expandedId.value !== null ? expandedId.value : null)),
);
const members = computed(() => membersQuery.data.value ?? []);

const removeTarget = ref<ProfileMember | null>(null);
const leaveTarget = ref<ProfileMember | null>(null);

const confirmRemove = () => {
    const target = removeTarget.value;
    const profile = expandedProfile.value;
    if (target === null || profile === null) {
        return;
    }
    removeMember.mutate(
        { profileId: profile.id, userId: target.id },
        {
            onSuccess: () => {
                removeTarget.value = null;
                toast.success(`${target.name} was removed from the profile.`);
            },
            onError: (e) => {
                removeTarget.value = null;
                fail(e);
            },
        },
    );
};

const confirmLeave = () => {
    const target = leaveTarget.value;
    const profile = expandedProfile.value;
    if (target === null || profile === null) {
        return;
    }
    removeMember.mutate(
        { profileId: profile.id, userId: target.id },
        {
            onSuccess: () => {
                leaveTarget.value = null;
                toast.success(`You left "${profile.name}".`);
            },
            onError: (e) => {
                leaveTarget.value = null;
                fail(e);
            },
        },
    );
};

// --- transfer ownership ------------------------------------------------------
const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);

const transferCandidates = computed(() =>
    members.value.filter((m) => !m.is_owner),
);

const askTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = () => {
    const profile = expandedProfile.value;
    if (profile === null || transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    transferOwnership.mutate(
        { profileId: profile.id, userId: transferTarget.value },
        {
            onSuccess: () => {
                transferTarget.value = null;
                toast.success(
                    'Ownership transferred. You are now a collaborator.',
                );
            },
            onError: (e) => {
                transferTarget.value = null;
                fail(e);
            },
        },
    );
};

// --- invites -----------------------------------------------------------------
/**
 * Pending invites come from GET /api/profiles/{profile}/invites (owner only),
 * so they survive reloads. The shareable link (raw key) is only returned
 * when the invite is created, so copy links are kept per invite id for the
 * current session.
 */
const invitesQuery = usePendingInvites(
    computed(() => (expandedId.value !== null ? expandedId.value : null)),
    computed(() => expandedProfile.value?.is_owner ?? false),
);
const pendingInvites = computed(() => invitesQuery.data.value ?? []);

const inviteUrls = ref<Record<number, string>>({});

const inviteEmail = ref('');
const inviteError = ref<string | undefined>(undefined);
const copiedInviteId = ref<number | null>(null);

const onInvite = () => {
    const profile = expandedProfile.value;
    const email = inviteEmail.value.trim();
    if (profile === null || email === '') {
        return;
    }
    inviteError.value = undefined;
    createInvite.mutate(
        { profileId: profile.id, email },
        {
            onSuccess: (data) => {
                inviteEmail.value = '';
                inviteUrls.value = {
                    ...inviteUrls.value,
                    [data.invite.id]: data.url,
                };
                toast.success('Invite created — copy the link to share it.');
            },
            onError: (e) => {
                inviteError.value =
                    e instanceof ApiError ? e.firstError : undefined;
                fail(e);
            },
        },
    );
};

const copyInvite = async (invite: ProfileInvite) => {
    const url = inviteUrls.value[invite.id];
    if (url === undefined) {
        return;
    }
    try {
        await navigator.clipboard.writeText(`${window.location.origin}${url}`);
        copiedInviteId.value = invite.id;
        setTimeout(() => (copiedInviteId.value = null), 2000);
    } catch {
        toast.error('Could not copy the link.');
    }
};

const revoke = (invite: ProfileInvite) => {
    const profile = expandedProfile.value;
    if (profile === null) {
        return;
    }
    revokeInvite.mutate(
        { profileId: profile.id, inviteId: invite.id },
        {
            onSuccess: () => toast.success('Invite revoked.'),
            onError: (e) => fail(e),
        },
    );
};

const expiryLabel = (expiresAt: string): string =>
    new Date(expiresAt).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

const joinedLabel = (joinedAt: string): string =>
    new Date(joinedAt).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
</script>

<template>
    <Head title="Profiles" />

    <div class="flex flex-col space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                variant="small"
                title="Profiles"
                description="Group people, then share budgets and ledgers within a profile"
            />
            <Button class="gap-2" @click="showCreate = true">
                <Icon name="add" class="size-4" aria-hidden="true" />
                New profile
            </Button>
        </div>

        <p v-if="isLoading" class="space-y-2">
            <Skeleton class="h-16 w-full" />
            <Skeleton class="h-16 w-full" />
        </p>

        <div v-else class="space-y-3">
            <Card
                v-for="profile in profiles"
                :key="profile.id"
                class="gap-0 overflow-hidden border-sidebar-border/70 py-0 transition-colors dark:border-sidebar-border"
                :class="
                    profile.id === currentProfileId ? 'border-primary/40' : ''
                "
            >
                <CardContent class="p-0">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3.5 text-left"
                        @click="toggleExpanded(profile)"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl"
                            :class="
                                profile.is_owner
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <Icon
                                :name="profile.is_owner ? 'person' : 'group'"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <template v-if="renamingId === profile.id">
                                    <Input
                                        v-model="renameValue"
                                        v-focus
                                        class="h-7 max-w-56"
                                        @click.stop
                                        @keydown.enter.prevent="
                                            commitRename(profile)
                                        "
                                        @keydown.escape="cancelRename"
                                        @blur="commitRename(profile)"
                                    />
                                </template>
                                <template v-else>
                                    <span class="truncate text-sm font-medium">
                                        {{ profile.name }}
                                    </span>
                                </template>
                                <Badge
                                    v-if="profile.is_owner"
                                    variant="default"
                                    class="shrink-0"
                                    >Owned</Badge
                                >
                                <Badge
                                    v-else
                                    variant="secondary"
                                    class="shrink-0"
                                    >Shared</Badge
                                >
                                <Badge
                                    v-if="profile.id === currentProfileId"
                                    variant="outline"
                                    class="shrink-0"
                                    >Current</Badge
                                >
                            </span>
                        </span>
                        <Button
                            v-if="profile.is_owner"
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            aria-label="Rename profile"
                            @click.stop="startRename(profile)"
                        >
                            <Icon
                                name="edit"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </Button>
                        <Button
                            v-if="profile.id !== currentProfileId"
                            variant="ghost"
                            size="sm"
                            class="shrink-0 text-xs"
                            @click.stop="select(profile.id)"
                        >
                            Switch to
                        </Button>
                        <Icon
                            name="expand-more"
                            class="size-5 shrink-0 text-muted-foreground transition-transform"
                            :class="
                                expandedId === profile.id ? 'rotate-180' : ''
                            "
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-if="expandedId === profile.id"
                        class="space-y-6 border-t px-4 pt-4 pb-5"
                    >
                        <!-- Members -->
                        <div class="space-y-2">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Members
                            </p>
                            <p
                                v-if="membersQuery.isLoading.value"
                                class="text-sm text-muted-foreground"
                            >
                                Loading…
                            </p>
                            <div v-else class="space-y-1">
                                <div
                                    v-for="member in members"
                                    :key="member.id"
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
                                                v-if="member.id === meId"
                                                class="text-xs font-normal text-muted-foreground"
                                                >(you)</span
                                            >
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ member.email }} · joined
                                            {{ joinedLabel(member.joined_at) }}
                                        </p>
                                    </div>
                                    <Badge
                                        :variant="
                                            member.is_owner
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        class="shrink-0"
                                    >
                                        {{
                                            member.is_owner
                                                ? 'Owner'
                                                : 'Collaborator'
                                        }}
                                    </Badge>
                                    <Button
                                        v-if="
                                            profile.is_owner && !member.is_owner
                                        "
                                        variant="ghost"
                                        size="icon-sm"
                                        class="shrink-0 text-muted-foreground hover:text-destructive"
                                        aria-label="Remove member"
                                        @click="removeTarget = member"
                                    >
                                        <Icon
                                            name="person-remove"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                    <Button
                                        v-else-if="
                                            member.id === meId &&
                                            !member.is_owner
                                        "
                                        variant="ghost"
                                        size="sm"
                                        class="shrink-0 text-xs text-muted-foreground hover:text-destructive"
                                        @click="leaveTarget = member"
                                    >
                                        Leave
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- Owner-only management -->
                        <template v-if="profile.is_owner">
                            <div class="space-y-2">
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Invite someone
                                </p>
                                <form
                                    class="flex items-start gap-2"
                                    @submit.prevent="onInvite"
                                >
                                    <div class="flex-1 space-y-1">
                                        <Input
                                            v-model="inviteEmail"
                                            type="email"
                                            required
                                            placeholder="person@example.com"
                                        />
                                        <InputError :message="inviteError" />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        class="gap-2"
                                        :disabled="createInvite.isLoading.value"
                                    >
                                        <Icon
                                            name="person-add"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Invite
                                    </Button>
                                </form>
                                <p class="text-xs text-muted-foreground">
                                    The invite link expires after 48 hours and
                                    only works for this email address.
                                </p>

                                <div
                                    v-for="invite in pendingInvites"
                                    :key="invite.id"
                                    class="flex items-center gap-2 rounded-md border px-3 py-2"
                                    data-test="pending-invite"
                                >
                                    <Icon
                                        name="link"
                                        class="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm">
                                            {{ invite.email }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Expires
                                            {{ expiryLabel(invite.expires_at) }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="inviteUrls[invite.id]"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="shrink-0"
                                        aria-label="Copy invite link"
                                        @click="copyInvite(invite)"
                                    >
                                        <Icon
                                            :name="
                                                copiedInviteId === invite.id
                                                    ? 'check'
                                                    : 'content-copy'
                                            "
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="shrink-0 text-muted-foreground hover:text-destructive"
                                        aria-label="Revoke invite"
                                        :disabled="revokeInvite.isLoading.value"
                                        @click="revoke(invite)"
                                    >
                                        <Icon
                                            name="close"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-if="transferCandidates.length > 0"
                                class="space-y-2"
                            >
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Transfer ownership
                                </p>
                                <div class="flex items-center gap-2">
                                    <Select
                                        :model-value="
                                            transferTarget ?? undefined
                                        "
                                        @update:model-value="
                                            (value) =>
                                                (transferTarget = Number(value))
                                        "
                                    >
                                        <SelectTrigger class="flex-1">
                                            <SelectValue
                                                placeholder="Choose a collaborator"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="member in transferCandidates"
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
                                        @click="askTransfer"
                                    >
                                        Transfer
                                    </Button>
                                </div>
                            </div>

                            <!-- The Default profile cannot be deleted (renamable only). -->
                            <div
                                v-if="!profile.is_default"
                                class="space-y-2 border-t pt-4"
                            >
                                <p class="text-xs font-medium text-destructive">
                                    Danger zone
                                </p>
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <p class="text-sm text-muted-foreground">
                                        Deletes this profile. Blocked while it
                                        still has budgets or ledgers.
                                    </p>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        class="shrink-0 gap-2"
                                        @click="deleteTarget = profile"
                                    >
                                        <Icon
                                            name="delete"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Delete profile
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Create profile -->
        <Dialog :open="showCreate" @update:open="showCreate = false">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>New profile</DialogTitle>
                    <DialogDescription>
                        A profile groups people — budgets and ledgers are shared
                        within it.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="profile-name">Name</Label>
                    <Input
                        id="profile-name"
                        v-model="newName"
                        v-focus
                        placeholder="e.g. Family"
                        @keydown.enter.prevent="onCreate"
                    />
                    <InputError :message="newNameError" />
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="showCreate = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="
                            newName.trim() === '' ||
                            createProfile.isLoading.value
                        "
                        @click="onCreate"
                        >Create profile</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete profile -->
        <Dialog
            :open="deleteTarget !== null"
            @update:open="deleteTarget = null"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete this profile?</DialogTitle>
                    <DialogDescription>
                        “{{ deleteTarget?.name }}” will be permanently deleted.
                        This is blocked while the profile still has budgets or
                        ledgers.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleteTarget = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="deleteProfile.isLoading.value"
                        @click="onDelete"
                        >Delete profile</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Remove member -->
        <Dialog
            :open="removeTarget !== null"
            @update:open="removeTarget = null"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Remove this member?</DialogTitle>
                    <DialogDescription>
                        {{ removeTarget?.name }} loses access to the profile's
                        budgets and ledgers. Resources they own in the profile
                        transfer to you.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="removeTarget = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="removeMember.isLoading.value"
                        @click="confirmRemove"
                        >Remove</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Leave profile -->
        <Dialog :open="leaveTarget !== null" @update:open="leaveTarget = null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Leave this profile?</DialogTitle>
                    <DialogDescription>
                        You will lose access to “{{ expandedProfile?.name }}”.
                        Resources you own in the profile transfer to the profile
                        owner.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="leaveTarget = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="removeMember.isLoading.value"
                        @click="confirmLeave"
                        >Leave profile</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Transfer ownership -->
        <Dialog
            :open="confirmingTransfer"
            @update:open="confirmingTransfer = false"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Transfer profile ownership?</DialogTitle>
                    <DialogDescription>
                        {{ members.find((m) => m.id === transferTarget)?.name }}
                        becomes the owner of “{{ expandedProfile?.name }}”. You
                        become a collaborator.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmingTransfer = false"
                        >Cancel</Button
                    >
                    <Button @click="confirmTransfer">Transfer</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
