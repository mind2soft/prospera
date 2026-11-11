const HTML_ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/**
 * Escapes a value for interpolation into an HTML string (text or quoted
 * attribute). Every value interpolated into markup that is later assigned to
 * `innerHTML` — chart tooltips in particular, which Unovis renders with
 * `.html()` — must go through this: envelope and group names are
 * user-provided and shared across users.
 */
export function escapeHtml(value: unknown): string {
    return String(value ?? '').replace(
        /[&<>"']/g,
        (char) => HTML_ESCAPES[char] ?? char,
    );
}
