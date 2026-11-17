/**
 * Whether the current user may delete a ledger row. Schedule-generated rows
 * are system-managed: nobody deletes them, the ledger owner included (the
 * server rejects it too).
 */
export function canDeleteTransaction(
    transaction: { scheduled?: boolean; user: { id: number | null } },
    permission: string | null,
    currentUserId: number,
    open: boolean,
): boolean {
    if (!open || transaction.scheduled) return false;
    return (
        permission === 'owner' ||
        (permission === 'contributor' &&
            transaction.user.id !== null &&
            transaction.user.id === currentUserId)
    );
}
