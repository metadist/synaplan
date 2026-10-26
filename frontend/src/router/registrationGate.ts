import type { LocationQuery, RouteLocationRaw } from 'vue-router'

/** Routes a pending-payment account may open. Checkout must be able to return. */
const PENDING_PAYMENT_OPEN = new Set([
  'subscription',
  'subscription-success',
  'subscription-cancel',
  'login',
])

/**
 * An account waiting for payment can open the plan page, the two checkout
 * return routes, and login. Everywhere else they go back to the plan page.
 */
export function pendingPaymentHold(
  accountStatus: string | undefined,
  routeName: string | symbol | null | undefined
): 'pay' | null {
  if ('pending_payment' !== accountStatus) return null

  return PENDING_PAYMENT_OPEN.has(String(routeName ?? '')) ? null : 'pay'
}

/**
 * `/register` must be unreachable when self-registration is off, not just hidden
 * from the login page. Bookmarks, invitation links and old emails still land
 * here; send them to login and keep any deep-link query so they are not lost.
 */
export function resolveRegistrationRedirect(
  registrationEnabled: boolean,
  query: LocationQuery = {}
): RouteLocationRaw | null {
  if (registrationEnabled) {
    return null
  }

  return { name: 'login', query }
}
