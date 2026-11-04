<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutGrid } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { budget, dashboard, ledger, envelopes } from '@/routes';
import { materialSymbol } from '@/lib/materialIcon';
import type { NavItem } from '@/types';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('layout.nav.dashboard'),
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: t('layout.nav.budget'),
        href: budget(),
        icon: materialSymbol('savings'),
    },
    {
        title: t('layout.nav.ledger'),
        href: ledger(),
        icon: materialSymbol('receipt-long'),
    },
    {
        title: t('layout.nav.envelopes'),
        href: envelopes(),
        icon: materialSymbol('category'),
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
