export const CHANGE_PASSWORD_ROUTE = 'change-password'

/**
 * Decides what a navigation must do about an account that still carries a
 * deployment-generated password.
 *
 * - `force`: send the user to the change page; the backend rejects every other
 *   API route anyway, so no other view can render anything useful.
 * - `release`: the change page is open to somebody who does not need it — send
 *   them back to their normal entry point.
 * - `null`: nothing to do.
 *
 * Public pages are exempt: they need no session, so blocking them would only
 * make shared links break for a signed-in admin.
 */
export function resolveForcedPasswordChange(navigation: {
  authenticated: boolean
  mustChangePassword: boolean
  isPublicRoute: boolean
  routeName: string | symbol | null | undefined
}): 'force' | 'release' | null {
  const pending = navigation.authenticated && navigation.mustChangePassword
  const onChangePage = CHANGE_PASSWORD_ROUTE === navigation.routeName

  if (pending) {
    return navigation.isPublicRoute || onChangePage ? null : 'force'
  }

  return onChangePage ? 'release' : null
}
