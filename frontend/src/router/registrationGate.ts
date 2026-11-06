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
