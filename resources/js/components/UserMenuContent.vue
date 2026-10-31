<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { edit as editProfiles } from '@/routes/profiles';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

const { profiles, profileId, select } = useCurrentProfile();

const menuProfiles = computed(() =>
    [...profiles.value].sort((a, b) => Number(b.is_owner) - Number(a.is_owner)),
);
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger class="gap-2">
                <Icon name="group" class="size-4" aria-hidden="true" />
                Profiles
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent class="w-64">
                <p
                    v-if="profiles.length === 0"
                    class="px-2 py-1.5 text-sm text-muted-foreground"
                >
                    Loading…
                </p>
                <template v-else>
                    <DropdownMenuItem
                        v-for="profile in menuProfiles"
                        :key="profile.id"
                        class="gap-2"
                        @click="select(profile.id)"
                    >
                        <Icon
                            :name="profile.is_owner ? 'person' : 'group'"
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            profile.name
                        }}</span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                            :class="
                                profile.is_owner
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ profile.is_owner ? 'Owned' : 'Shared' }}
                        </span>
                        <Icon
                            v-if="profile.id === profileId"
                            name="check"
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                            data-test="current-profile-check"
                        />
                    </DropdownMenuItem>
                </template>
                <DropdownMenuSeparator />
                <DropdownMenuItem :as-child="true">
                    <Link
                        class="block w-full cursor-pointer"
                        :href="editProfiles()"
                    >
                        <Icon
                            name="tune"
                            class="mr-2 size-4"
                            aria-hidden="true"
                        />
                        Manage profiles
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Icon name="settings" class="mr-2 size-4" aria-hidden="true" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <Icon name="logout" class="mr-2 size-4" aria-hidden="true" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
