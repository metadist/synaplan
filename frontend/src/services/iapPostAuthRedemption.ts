/**
 * MOBILE-APP SEAM: post-auth redemption of a purchase-first store purchase.
 *
 * Purchase-first onboarding lets the store transaction complete while the
 * user is still signed out (`purchased_unlinked` in `nativeIap.ts`). This
 * hook runs after EVERY successful native authentication (password login,
 * system-browser OAuth, native Apple sheet) and links the held purchase to
 * the freshly authenticated account:
 *
 *   auth success → redeemPendingIapPurchase() → POST /api/v1/iap/verify
 *   → transaction.finish() → refreshUser() (the tier changed server-side)
 *
 * It is a cheap no-op unless a redemption is actually pending, so callers
 * can invoke it unconditionally (fire-and-forget).
 */
import { hasPendingIapRedemption, redeemPendingIapPurchase } from '@/services/nativeIap'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n'

export async function redeemPendingIapPurchaseAfterAuth(): Promise<void> {
  if (!isNativeApp() || !hasPendingIapRedemption()) {
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
