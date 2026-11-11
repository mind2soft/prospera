import { createInertiaApp } from '@inertiajs/vue3';
import { VueQueryPlugin } from '@tanstack/vue-query';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeLocale } from '@/composables/useLocale';
import { i18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { loadIconCollections } from '@/lib/icons';

const appName = import.meta.env.VITE_APP_NAME || 'Prospera';

loadIconCollections();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Public, self-contained pages (no app shell).
            case name === 'Welcome':
            case name === 'Contact':
            case name === 'ContactSent':
            case name === 'Privacy':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.use(VueQueryPlugin, {
            queryClientConfig: {
                defaultOptions: {
                    queries: {
                        retry: false,
                        staleTime: 30_000,
                        refetchOnWindowFocus: false,
                    },
                    mutations: {
                        retry: false,
                    },
                },
            },
        });
        app.use(i18n);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will resolve the active locale (user preference, else browser
// locales) on page load and keep it in sync...
initializeLocale();

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
