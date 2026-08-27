/**
 * Auth pages that only make sense while signed out. A signed-in visitor who
 * types `/login` (or follows an old bookmark) belongs in the app, not on a
 * second sign-in form.
 */
export const GUEST_ONLY_ROUTE_NAMES = ['login', 'register'] as const

export function isGuestOnlyAuthRoute(routeName: string | symbol | null | undefined): boolean {
  return (
    'string' === typeof routeName &&
    (GUEST_ONLY_ROUTE_NAMES as readonly string[]).includes(routeName)
  )
}
