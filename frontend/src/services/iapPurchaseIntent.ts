/**
 * MOBILE-APP SEAM: the purchase intent picked in the native onboarding,
 * persisted across the register / login round trip.
 *
 * The auth-first onboarding flow lets the user pick a plan BEFORE they have
 * an account. The e-mail paths (register with verification, password login)
 * can involve leaving the app, a WebView re-creation (native server switch)
 * or a full restart — all of which destroy sessionStorage, so the pending
 * `/subscription` redirect alone is not enough to carry the intent.
 *
 * This stores the picked plan in `localStorage` (survives restarts) with a
 * TTL. After ANY successful authentication the auth views fall back to the
 * subscription page while an intent is present, and the subscription page
 * continues the purchase automatically — after the same server-side
 * subscription pre-check as the onboarding purchase step, so an account
 * that already has an active subscription is never charged.
 *
 * Cleared when the purchase concludes (granted / already subscribed), when
 * the user deliberately opts out ("later", skip), or after the TTL.
 */

const PURCHASE_INTENT_KEY = 'synaplan.iapPurchaseIntent'

/** Long enough for an e-mail-verification round trip, short enough that a
 *  stale pick from last week never surprises the user with a store sheet. */
const TTL_MS = 24 * 60 * 60 * 1000

export interface IapPurchaseIntent {
  planId: string
  productId: string
}

interface StoredIntent extends IapPurchaseIntent {
  expiresAt: number
}

export function setPurchaseIntent(intent: IapPurchaseIntent): void {
  try {
    const stored: StoredIntent = { ...intent, expiresAt: Date.now() + TTL_MS }
    localStorage.setItem(PURCHASE_INTENT_KEY, JSON.stringify(stored))
  } catch {
    // Storage unavailable — the same-session flow still works without it.
  }
}

/** Read the intent WITHOUT clearing it (route-target decisions). */
export function peekPurchaseIntent(): IapPurchaseIntent | null {
  try {
    const raw = localStorage.getItem(PURCHASE_INTENT_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<StoredIntent>
    if (
      'string' !== typeof parsed.planId ||
      'string' !== typeof parsed.productId ||
      'number' !== typeof parsed.expiresAt ||
      parsed.expiresAt < Date.now()
    ) {
      clearPurchaseIntent()
      return null
    }
    return { planId: parsed.planId, productId: parsed.productId }
  } catch {
    clearPurchaseIntent()
    return null
  }
}

/** Read and clear in one shot — used when the intent is acted upon. */
export function consumePurchaseIntent(): IapPurchaseIntent | null {
  const intent = peekPurchaseIntent()
  if (intent) clearPurchaseIntent()
  return intent
}

export function clearPurchaseIntent(): void {
  try {
    localStorage.removeItem(PURCHASE_INTENT_KEY)
  } catch {
    // no-op
  }
}

/**
 * Default post-auth route for the auth views (login / register / OAuth
 * callback): while a purchase intent is pending, continue on the
 * subscription page instead of the chat — that is where the purchase is
 * resumed. An explicit `?redirect=` or pending auth redirect always wins;
 * this is only the last fallback (and the one that survives a restart).
 */
export function postAuthTargetPath(): string {
  return peekPurchaseIntent() ? '/subscription' : '/'
}
