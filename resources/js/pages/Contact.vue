<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GithubIcon from '@/components/GithubIcon.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useMutation } from '@/composables/query';
import { ApiError, api } from '@/lib/api';
import { dashboard } from '@/routes';
import { store } from '@/routes/contact';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const githubRepoUrl = computed(() => page.props.githubRepoUrl);
const isSignedIn = computed(() => Boolean(user.value));

const MESSAGE_MAX = 4000;

const form = ref({
    email: (page.props.auth?.user?.email as string | undefined) ?? '',
    subject: '',
    message: '',
});

const genericError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const { mutate: submit, isPending: isLoading } = useMutation({
    mutationFn: async () => {
        genericError.value = null;
        fieldErrors.value = {};

        return api<{ redirect: string }>(store.url(), {
            method: 'POST',
            body: form.value,
        });
    },
    onSuccess: (data) => {
        router.visit(data.redirect);
    },
    onError: (error) => {
        if (error instanceof ApiError) {
            // Field errors go under their input; anything else
            // (cooldown, throttling, missing IP, server errors) shows
            // as an alert.
            const fieldKeys = new Set(['email', 'subject', 'message']);
            fieldErrors.value = Object.fromEntries(
                Object.entries(error.errors).filter(([key]) =>
                    fieldKeys.has(key),
                ),
            );
            const other = Object.entries(error.errors)
                .filter(([key]) => !fieldKeys.has(key))
                .flatMap(([, messages]) => messages);
            genericError.value =
                other[0] ??
                (Object.keys(fieldErrors.value).length
                    ? null
                    : error.firstError);
        } else {
            genericError.value = error instanceof Error ? error.message : null;
        }
    },
});

// The progress bar is a visual cue for the message length: under 2000
// characters is green, then yellow, orange, and full red at the limit.
const messageLength = computed(() => form.value.message.length);

const messageBarClass = computed(() => {
    if (messageLength.value >= MESSAGE_MAX) return 'bg-red-500';
    if (messageLength.value >= 3000) return 'bg-orange-500';
    if (messageLength.value >= 2000) return 'bg-yellow-500';
    return 'bg-emerald-500';
});
</script>

<template>
    <Head :title="$t('contact.title')" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <!-- Navigation -->
        <header class="border-b">
            <nav
                class="mx-auto flex h-16 max-w-3xl items-center justify-between px-6"
            >
                <Link
                    :href="isSignedIn ? dashboard() : '/'"
                    class="flex items-center gap-2.5"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand to-emerald-700 text-white shadow-sm shadow-brand/30"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span class="font-display text-2xl tracking-tight"
                        >Prospera</span
                    >
                </Link>
                <a
                    :href="githubRepoUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    :title="$t('contact.tooltip')"
                    :aria-label="$t('contact.tooltip')"
                >
                    <GithubIcon class="size-5" />
                </a>
            </nav>
        </header>

        <main class="flex flex-1 items-start justify-center px-6 py-12">
            <div class="w-full max-w-xl">
                <h1 class="font-display text-3xl tracking-tight">
                    {{ $t('contact.title') }}
                </h1>
                <p class="mt-2 text-muted-foreground">
                    {{ $t('contact.description') }}
                    <a
                        :href="githubRepoUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-brand underline-offset-2 hover:underline"
                        >{{
                            $t('contact.githubLink')
                        }}</a
                    >{{ $t('contact.githubNotice') }}
                </p>

                <div v-if="genericError" class="mt-6" data-test="contact-error">
                    <Alert variant="destructive">
                        <AlertDescription>
                            {{ genericError }}
                        </AlertDescription>
                    </Alert>
                </div>

                <form
                    class="mt-6 space-y-5"
                    :aria-busy="isLoading"
                    @submit.prevent="submit()"
                >
                    <div class="grid gap-2">
                        <Label for="contact-email">{{
                            $t('common.fields.email')
                        }}</Label>
                        <Input
                            id="contact-email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            required
                            :readonly="isSignedIn"
                            :disabled="isLoading"
                            data-test="contact-email"
                        />
                        <InputError :message="fieldErrors.email?.[0]" />
                        <p
                            v-if="isSignedIn"
                            class="text-xs text-muted-foreground"
                        >
                            {{ $t('contact.emailReadonly') }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-baseline justify-between">
                            <Label for="contact-subject">{{
                                $t('contact.subject')
                            }}</Label>
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                {{ form.subject.length }}
                                / 100
                            </span>
                        </div>
                        <Input
                            id="contact-subject"
                            v-model="form.subject"
                            type="text"
                            name="subject"
                            required
                            maxlength="100"
                            :disabled="isLoading"
                            data-test="contact-subject"
                        />
                        <InputError :message="fieldErrors.subject?.[0]" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-baseline justify-between">
                            <Label for="contact-message">{{
                                $t('contact.message')
                            }}</Label>
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                {{ messageLength }}
                                / {{ MESSAGE_MAX }}
                            </span>
                        </div>
                        <textarea
                            id="contact-message"
                            v-model="form.message"
                            name="message"
                            required
                            rows="8"
                            maxlength="4000"
                            class="flex field-sizing-content min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="isLoading"
                            data-test="contact-message"
                        />
                        <!-- Thin, colorized progress bar: visual cue for the
                             approaching limit (green < 2000, yellow < 3000,
                             orange < 4000, red at the limit). -->
                        <div
                            class="h-1 overflow-hidden rounded-full bg-muted"
                            data-test="contact-message-bar"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="messageBarClass"
                                :style="{
                                    width: `${Math.min((messageLength / MESSAGE_MAX) * 100, 100)}%`,
                                }"
                            />
                        </div>
                        <InputError :message="fieldErrors.message?.[0]" />
                    </div>

                    <Button
                        type="submit"
                        class="w-full sm:w-auto"
                        :disabled="isLoading"
                        data-test="contact-submit"
                    >
                        <Spinner v-if="isLoading" />
                        {{ $t('contact.submit') }}
                    </Button>
                </form>
            </div>
        </main>
    </div>
</template>
