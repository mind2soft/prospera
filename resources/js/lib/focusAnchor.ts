import { router } from '@inertiajs/vue3';

/**
 * Scrolls to `#id` and focuses its first form field (deep links such as
 * `/ledger#add-transaction`). No smooth scroll under reduced motion.
 */
export function focusAnchor(id: string): boolean {
    if (typeof document === 'undefined') {
        return false;
    }
    const target = document.getElementById(id);
    if (target === null) {
        return false;
    }

    const reduce = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    target.scrollIntoView({
        block: 'center',
        behavior: reduce ? 'auto' : 'smooth',
    });
    const field = target.querySelector<HTMLElement>(
        'button, input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );
    (field ?? target).focus({ preventScroll: true });

    return true;
}

/**
 * Removes the deep-link query/hash so a refresh doesn't replay it — through
 * Inertia, so its own history entry (page URL) is updated too.
 */
export function stripDeepLink(): void {
    if (typeof window === 'undefined') {
        return;
    }
    router.replace({
        url: window.location.pathname,
        preserveState: true,
        preserveScroll: true,
    });
}
