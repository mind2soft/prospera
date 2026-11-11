/**
 * The heading and document title (translation keys) of the register page,
 * which doubles as the signed-in invite confirmation (guests only reach
 * plain registration). One place for both: the page's layout option cannot
 * read the component's state (pages/auth/Register.vue).
 */
export function registerHeading(
    signedIn: boolean,
    hasInvite: boolean,
): { title: string; description: string; documentTitle: string } {
    if (!signedIn) {
        return {
            title: 'auth.register.layoutTitle',
            description: 'auth.register.layoutDescription',
            documentTitle: 'auth.register.title',
        };
    }

    return hasInvite
        ? {
              title: 'auth.invite.title',
              description: 'auth.invite.description',
              documentTitle: 'auth.invite.title',
          }
        : {
              title: 'auth.register.inviteUnavailable',
              description: '',
              documentTitle: 'auth.register.inviteUnavailable',
          };
}
