<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'settings.account',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const locales = computed(() => page.props.locales);

/**
 * An empty value means "follow the browser locales".
 */
const AUTOMATIC = 'automatic';

const locale = ref(user.value.locale ?? AUTOMATIC);

watch(user, (value) => {
    locale.value = value.locale ?? AUTOMATIC;
});

/**
 * The value submitted to the backend: an empty string is stored as a null
 * locale, i.e. "follow the browser locales".
 */
const submittedLocale = computed(() =>
    locale.value === AUTOMATIC ? '' : locale.value,
);
</script>

<template>
    <Head :title="$t('settings.account')" />

    <h1 class="sr-only">{{ $t('settings.account') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('settings.profile.title')"
            :description="$t('settings.profile.description')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ $t('settings.profile.name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('settings.profile.namePlaceholder')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('settings.profile.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="$t('settings.profile.emailPlaceholder')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="locale">{{ $t('settings.profile.locale') }}</Label>
                <Select v-model="locale">
                    <SelectTrigger id="locale" class="mt-1 w-full">
                        <SelectValue
                            :placeholder="
                                $t('settings.profile.localeAutomatic')
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="automatic">
                            {{ $t('settings.profile.localeAutomatic') }}
                        </SelectItem>
                        <SelectItem
                            v-for="(label, code) in locales"
                            :key="code"
                            :value="code"
                        >
                            {{ label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                    {{ $t('settings.profile.localeDescription') }}
                </p>
                <input type="hidden" name="locale" :value="submittedLocale" />
                <InputError class="mt-2" :message="errors.locale" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    {{ $t('settings.profile.emailUnverified') }}
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {{ $t('settings.profile.resendLink') }}
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    {{ $t('settings.profile.verificationSent') }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ $t('settings.profile.save') }}</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
