<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useQueryClient } from 'vue-query';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { profileKeys, useAcceptInvite } from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { ApiError } from '@/lib/api';
import { dashboard, login } from '@/routes';
import { store } from '@/routes/register';
import type { InviteContext } from '@/types';

const props = defineProps<{
    passwordRules: string;
    /** Invite context resolved by the backend from the link's invite key. */
    invite?: InviteContext | null;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});

/**
 * The raw invite key travels in the URL (either `/invites/{key}` or
 * `?invite_key={key}`) and is submitted with the registration payload so the
 * backend can consume it and grant the membership.
 */
const inviteKey = computed(() => {
    const url = new URL(window.location.href);
    const fromQuery = url.searchParams.get('invite_key');
    if (fromQuery) {
        return fromQuery;
    }
    const match = url.pathname.match(/^\/invites\/([^/]+)$/);
    return match?.[1] ?? '';
});

// --- signed-in accept flow (docs/shared-finances.md — invites) ---------------
const page = usePage();
const signedIn = computed(() => page.props.auth.user !== null);
/** An invalid/expired key renders the landing page without invite context. */
const onInviteLink = computed(() =>
    window.location.pathname.startsWith('/invites/'),
);

const acceptInvite = useAcceptInvite();
const queryClient = useQueryClient();
const accepting = ref(false);
const acceptError = ref<string | null>(null);
const { select } = useCurrentProfile();

const onAccept = () => {
    acceptError.value = null;
    accepting.value = true;
    acceptInvite.mutate(inviteKey.value, {
        onSuccess: async (data) => {
            // Refresh the profiles list so the joined profile can be selected.
            await queryClient.invalidateQueries({ queryKey: profileKeys.all });
            select(data.profile.id);
            toast.success(`You joined “${data.profile.name}”.`);
            router.get(dashboard());
        },
        onError: (e) => {
            accepting.value = false;
            acceptError.value =
                e instanceof ApiError
                    ? e.firstError
                    : 'Could not accept this invite.';
        },
    });
};

const onDecline = () => {
    router.get(dashboard());
};
</script>

<template>
    <Head title="Register" />

    <!-- Signed-in: accept (or decline) the invite. -->
    <Card v-if="signedIn && onInviteLink">
        <CardContent class="space-y-5 p-6">
            <template v-if="invite">
                <div class="flex items-start gap-3">
                    <Icon
                        name="group-add"
                        class="size-6 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div class="text-sm">
                        <p class="text-base font-medium">
                            Join “{{ invite.profile }}”
                        </p>
                        <p class="text-muted-foreground">
                            {{ invite.inviter }} invited
                            <span class="font-medium">{{ invite.email }}</span>
                            to this financial profile.
                        </p>
                    </div>
                </div>

                <InputError
                    v-if="acceptError"
                    :message="acceptError"
                    class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2"
                />

                <div class="flex items-center gap-2">
                    <Button
                        class="gap-2"
                        :disabled="accepting"
                        @click="onAccept"
                    >
                        <Spinner v-if="accepting" />
                        Accept invite
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="accepting"
                        @click="onDecline"
                        >Decline</Button
                    >
                </div>
            </template>
            <template v-else>
                <div class="flex items-start gap-3">
                    <Icon
                        name="error"
                        class="size-6 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <div class="text-sm">
                        <p class="text-base font-medium">
                            This invite is not available
                        </p>
                        <p class="text-muted-foreground">
                            The invite link is invalid or has expired.
                        </p>
                    </div>
                </div>
                <Button as-child>
                    <Link :href="dashboard()">Go to dashboard</Link>
                </Button>
            </template>
        </CardContent>
    </Card>

    <!-- Signed-out: register through the invite (email pre-filled and locked). -->
    <template v-else>
        <div
            v-if="invite"
            class="mb-2 flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3"
        >
            <Icon
                name="group-add"
                class="size-5 shrink-0 text-primary"
                aria-hidden="true"
            />
            <div class="text-sm">
                <p class="font-medium">Join “{{ invite.profile }}”</p>
                <p class="text-muted-foreground">
                    {{ invite.inviter }} invited
                    <span class="font-medium">{{ invite.email }}</span> to this
                    financial profile.
                </p>
            </div>
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input type="hidden" name="invite_key" :value="inviteKey" />

            <InputError
                v-if="errors.invite_key"
                :message="errors.invite_key"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2"
            />

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Full name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                        :default-value="invite?.email"
                        :readonly="invite !== null && invite !== undefined"
                        :class="
                            invite ? 'bg-muted/50 text-muted-foreground' : ''
                        "
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Confirm password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="5"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    {{
                        invite
                            ? `Create account and join “${invite.profile}”`
                            : 'Create account'
                    }}
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4"
                    :tabindex="6"
                    >Log in</TextLink
                >
            </div>
        </Form>
    </template>
</template>
