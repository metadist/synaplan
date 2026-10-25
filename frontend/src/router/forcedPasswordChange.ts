import { pendingPaymentHold } from '@/router/registrationGate'

export const CHANGE_PASSWORD_ROUTE = 'change-password'

/**
 * Decides what a navigation must do about an account that cannot use the app yet.
 *
 * - `pay`: the account is waiting for payment. The plan page and the checkout
 *   return routes stay open; every other route goes back to the plan page.
 *   This lives here because the router guard is already at its size limit.
 * - `force`: send the user to the change page; the backend rejects every other
 *   API route anyway, so no other view can render anything useful.
 * - `release`: the change page is open to somebody who does not need it — send
 *   them back to their normal entry point.
 * - `null`: nothing to do.
 *
 * Public pages are exempt from the password rule: they need no session, so
 * blocking them would only make shared links break for a signed-in admin.
 * A pending payment is not exempt — the person can pay, or sign in again.
 */
export function resolveForcedPasswordChange(navigation: {
  authenticated: boolean
  mustChangePassword: boolean
  isPublicRoute: boolean
  routeName: string | symbol | null | undefined
  accountStatus?: string
}): 'force' | 'release' | 'pay' | null {
  const pay = pendingPaymentHold(navigation.accountStatus, navigation.routeName)
  if (null !== pay) return pay

  const pending = navigation.authenticated && navigation.mustChangePassword
  const onChangePage = CHANGE_PASSWORD_ROUTE === navigation.routeName

  if (pending) {
    return navigation.isPublicRoute || onChangePage ? null : 'force'
  }

  return onChangePage ? 'release' : null
}
