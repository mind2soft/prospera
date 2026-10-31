import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { FunctionalComponent } from 'vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: FunctionalComponent;
    isActive?: boolean;
};
