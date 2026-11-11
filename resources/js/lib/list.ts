/**
 * A copy of `items` with the element at `from` moved to `to` (both indexes
 * into the original list). Out-of-range indexes return an unchanged copy.
 */
export function moveItem<T>(
    items: readonly T[],
    from: number,
    to: number,
): T[] {
    const copy = [...items];

    if (from < 0 || from >= copy.length || to < 0 || to >= copy.length) {
        return copy;
    }

    const [moved] = copy.splice(from, 1);
    copy.splice(to, 0, moved);

    return copy;
}
