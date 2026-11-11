import { nextTick } from 'vue';
import { isClient } from '@/lib/ssr';

function tokens(value: string | null): string[] {
    return (value ?? '').split(/\s+/).filter(Boolean);
}

/**
 * Marks a field invalid and points its `aria-describedby` at the error
 * message (or reverts both). Other description ids are kept.
 */
export function linkFieldError(
    fieldId: string,
    errorId: string,
    invalid: boolean,
): void {
    if (!isClient) {
        return;
    }

    const field = document.getElementById(fieldId);
    if (!field) {
        return;
    }

    const described = tokens(field.getAttribute('aria-describedby')).filter(
        (id) => id !== errorId,
    );

    if (invalid) {
        field.setAttribute('aria-invalid', 'true');
        described.push(errorId);
    } else {
        field.removeAttribute('aria-invalid');
    }

    if (described.length > 0) {
        field.setAttribute('aria-describedby', described.join(' '));
    } else {
        field.removeAttribute('aria-describedby');
    }
}

let focusScheduled = false;

/**
 * After a failed submit, moves focus to the first invalid field (in
 * document order) — once per batch of errors, and not when focus already
 * sits on an invalid field.
 */
export function focusFirstInvalidField(): void {
    if (!isClient || focusScheduled) {
        return;
    }

    focusScheduled = true;
    void nextTick().then(() => {
        focusScheduled = false;

        const active = document.activeElement;
        if (active?.getAttribute('aria-invalid') === 'true') {
            return;
        }

        document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    });
}
