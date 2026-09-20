/**
 * MOBILE-APP SEAM: post-auth redemption of a store purchase made while
 * signed out.
 *
 * The auth-first onboarding buys only after sign-in, but a store transaction
 * can still surface without a session (a restore, or a purchase from an older
 * app version) and is then held as `purchased_unlinked` in `nativeIap.ts`.
 * This hook runs after EVERY successful native authentication (password login,
 * system-browser OAuth, native Apple sheet) and links the held purchase to
 * the freshly authenticated account:
 *
 *   auth success → redeemPendingIapPurchase() → POST /api/v1/iap/verify
 *   → transaction.finish() → refreshUser() (the tier changed server-side)
 *
 * It is a cheap no-op unless a redemption is actually pending, so callers
 * can invoke it unconditionally (fire-and-forget).
 *
 * GUARD (auth-first flow, mirror of the onboarding purchase pre-check): an
 * account that ALREADY has an active subscription never gets the held
 * purchase auto-linked — that would double-bill the user or silently
 * overwrite/downgrade the existing entitlement. The user gets refund
 * guidance instead (Android auto-refunds unacknowledged purchases after a
 * few days; on iOS the subscription is managed/refunded via the Apple ID).
 */
import {
  dismissPendingIapRedemption,
  hasPendingIapRedemption,
  redeemPendingIapPurchase,
} from '@/services/nativeIap'
import { getNativePlatform, isNativeApp } from '@/services/api/nativeRuntime'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n'

/** Long-lived toast: refund guidance the user should actually get to read. */
const REFUND_GUIDANCE_DURATION_MS = 12000

export async function redeemPendingIapPurchaseAfterAuth(): Promise<void> {
  if (!isNativeApp() || !hasPendingIapRedemption()) {
    return
  }

  // Pre-check the account BEFORE touching the held receipt.
  try {
    const status = await subscriptionApi.getSubscriptionStatus()
    if (status.active ?? status.hasSubscription) {
      // Do not redeem, surface refund guidance, and drop the local pending
      // flag: the decision is made. The store transaction stays unfinished
      // (Android: auto-refund; iOS: managed via the Apple account), and a
      // deliberate restore on the subscription page remains available.
      dismissPendingIapRedemption()
      const { error } = useNotification()
      const key =
        'android' === getNativePlatform()
          ? 'subscription.native.redeemBlockedExistingAndroid'
          : 'subscription.native.redeemBlockedExistingIos'
      error(i18n.global.t(key), REFUND_GUIDANCE_DURATION_MS)
      return
    }
  } catch {
    // Unknown account state — redeeming blind would reopen the double-charge
    // window. Keep the redemption pending; the next auth retries the check.
    return
  }

  const outcome = await redeemPendingIapPurchase()
  if (null === outcome) {
    // Nothing held in memory — the redemption was handed off to the plugin's
    // asynchronous re-delivery (post-restart path). No user feedback yet; the
    // subscription page reflects the entitlement once the server granted it.
    return
  }

  const { success, error } = useNotification()
  const t = i18n.global.t

  switch (outcome.status) {
    case 'granted': {
      // The tier changed server-side AFTER the login response — refresh the
      // principal so `isPro` & friends flip everywhere. Dynamic import keeps
      // this module free of a static store dependency (the auth store calls
      // this hook).
      const { useAuthStore } = await import('@/stores/auth')
      await useAuthStore().refreshUser()
      success(t('subscription.native.purchaseSuccess'))
      break
    }
    case 'pending':
      success(t('subscription.native.purchasePending'))
      break
    case 'error':
      if ('ownership_conflict' === outcome.code) {
        error(t('subscription.native.purchaseConflict'))
      }
      // Other errors stay silent here: the transaction remains unfinished and
      // is retried via re-delivery / the subscription page's restore.
      break
    default:
      break
  }
}
