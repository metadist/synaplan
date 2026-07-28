/**
 * MOBILE-APP SEAM (Epic 5.3): native in-app purchases via cordova-plugin-purchase.
 *
 * The plugin (and its StoreKit 2 companion) is bundled ONLY in the native shell
 * (`synaplan-apps`), so this module never imports it — it talks to the
 * `window.CdvPurchase` global with minimal structural types. On the web build
 * `isNativeIapAvailable()` is simply false and nothing else runs.
 *
 * Flow (server is the single source of truth, see MobilePurchaseController):
 *   order() → store "approved" → POST /api/v1/iap/verify (JWS / purchase token)
 *   → granted/pending → transaction.finish() → entitlement active.
 */

import { subscriptionApi } from '@/services/api/subscriptionApi'
import { ApiError } from '@/services/api/httpClient'
import { getNativePlatform, isNativeApp } from '@/services/api/nativeRuntime'
import { hasNativeTokens } from '@/services/api/nativeAuth'

// ---------------------------------------------------------------------------
// Minimal structural types for the CdvPurchase global (not an API response,
// so hand-written types are fine here — the plugin ships its own .d.ts but
// only inside the app repo).
// ---------------------------------------------------------------------------

interface CdvTransaction {
  products: Array<{ id: string }>
  state: string
  /** StoreKit 2 signed transaction (Apple, via the storekit2 companion plugin). */
  jwsRepresentation?: string
  /** Google Play purchase (Billing Library). */
  nativePurchase?: { purchaseToken?: string; orderId?: string }
  parentReceipt?: { purchaseToken?: string }
  finish(): Promise<void>
}

interface CdvOffer {
  order(): Promise<{ isError: boolean; code?: number; message?: string } | undefined>
}

interface CdvProduct {
  id: string
  /** Convenience pricing of the first offer phase, localized by the store. */
  pricing?: { price: string; currency?: string }
  getOffer(): CdvOffer | undefined
}

interface CdvStore {
  register(products: Array<{ id: string; type: string; platform: string }>): void
  initialize(platforms: string[]): Promise<unknown>
  update(): Promise<unknown>
  restorePurchases(): Promise<unknown>
  get(productId: string, platform?: string): CdvProduct | undefined
  when(): {
    approved(cb: (tr: CdvTransaction) => void): void
  }
  error(cb: (err: { code?: number; message?: string }) => void): void
}

interface CdvPurchaseGlobal {
  store: CdvStore
  Platform: { APPLE_APPSTORE: string; GOOGLE_PLAY: string }
  ProductType: { PAID_SUBSCRIPTION: string }
  ErrorCode: { PAYMENT_CANCELLED: number }
}

function getCdvPurchase(): CdvPurchaseGlobal | null {
  const cdv = (globalThis as { CdvPurchase?: CdvPurchaseGlobal }).CdvPurchase
  return cdv && cdv.store ? cdv : null
}

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

export type IapPurchaseOutcome =
  | { status: 'granted'; tier: string }
  /** Deferred by the store (e.g. Ask to Buy) — entitlement follows via webhook. */
  | { status: 'pending' }
  /**
   * Purchase-first onboarding: the store transaction succeeded while the user
   * was signed out. `/api/v1/iap/verify` requires a Bearer, so the transaction
   * is held UNFINISHED and redeemed after account creation / sign-in
   * ({@link redeemPendingIapPurchase}). The plugin re-delivers unfinished
   * transactions on every store initialization, so this survives restarts.
   */
  | { status: 'purchased_unlinked' }
  | { status: 'cancelled' }
  | {
      status: 'error'
      code:
        | 'not_available'
        | 'product_unknown'
        | 'verification_failed'
        | 'ownership_conflict'
        | 'store_error'
      message?: string
    }

/** True when running in the native shell AND the billing plugin is present. */
export function isNativeIapAvailable(): boolean {
  return isNativeApp() && null !== getCdvPurchase()
}

// ---------------------------------------------------------------------------
// Pending redemption (purchase-first onboarding)
// ---------------------------------------------------------------------------

/**
 * Marks that a store purchase completed while the user was signed out and
 * still has to be linked to an account. `localStorage` (not sessionStorage)
 * on purpose: the e-mail registration path forces the user out of the app to
 * verify their address, and the reminder banner + post-login redemption must
 * survive that restart. Cleared once the server accepts (or definitively
 * rejects) the receipt.
 */
const PENDING_REDEMPTION_KEY = 'synaplan.iapPendingRedemption'

/** True while a signed-out purchase is still waiting to be linked to an account. */
export function hasPendingIapRedemption(): boolean {
  try {
    return '1' === localStorage.getItem(PENDING_REDEMPTION_KEY)
  } catch {
    return false
  }
}

function markPendingIapRedemption(): void {
  try {
    localStorage.setItem(PENDING_REDEMPTION_KEY, '1')
  } catch {
    /* no-op: the in-memory transaction hold still covers the same session */
  }
}

function clearPendingIapRedemption(): void {
  try {
    localStorage.removeItem(PENDING_REDEMPTION_KEY)
  } catch {
    /* no-op */
  }
}

/**
 * Drop the pending-redemption flag WITHOUT touching the held transaction.
 * Used by the post-auth guard when the signed-in account already has an
 * active subscription: the purchase is deliberately NOT linked (refund
 * guidance is shown instead), and the unfinished store transaction is left
 * to the store's own lifecycle (Android auto-refunds unacknowledged
 * purchases; iOS is managed via the Apple ID). A later deliberate restore
 * can still pick it up.
 */
export function dismissPendingIapRedemption(): void {
  clearPendingIapRedemption()
  unlinkedTransactions = []
}

/**
 * Transactions approved by the store while signed out, held unfinished until
 * the user authenticates (same-session fast path — after a restart the plugin
 * re-delivers them through `initialize()` instead).
 */
let unlinkedTransactions: CdvTransaction[] = []

// ---------------------------------------------------------------------------
// Initialization
// ---------------------------------------------------------------------------

let initPromise: Promise<boolean> | null = null

/** Resolver of the purchase currently awaited by the UI (single-flight). */
let pendingResolve: ((outcome: IapPurchaseOutcome) => void) | null = null

function storePlatform(cdv: CdvPurchaseGlobal): string {
  return 'android' === getNativePlatform() ? cdv.Platform.GOOGLE_PLAY : cdv.Platform.APPLE_APPSTORE
}

/**
 * Register the store products and connect to the native billing service.
 * Idempotent — safe to call from every view that needs prices or purchases.
 * Product IDs come from the server's plan catalogue (`iapProductId`).
 */
export function initNativeIap(productIds: string[]): Promise<boolean> {
  if (initPromise) return initPromise

  initPromise = (async () => {
    const cdv = getCdvPurchase()
    if (!cdv || 0 === productIds.length) return false

    const platform = storePlatform(cdv)
    cdv.store.register(
      productIds.map((id) => ({ id, type: cdv.ProductType.PAID_SUBSCRIPTION, platform }))
    )

    // A purchase can be approved outside an active order() call too (renewal,
    // restore, Ask-to-Buy approval) — always verify server-side, then finish.
    cdv.store.when().approved((transaction) => {
      void handleApproved(transaction)
    })

    cdv.store.error((err) => {
      if (pendingResolve && err.code !== cdv.ErrorCode.PAYMENT_CANCELLED) {
        resolvePending({ status: 'error', code: 'store_error', message: err.message })
      }
    })

    try {
      await cdv.store.initialize([platform])
      return true
    } catch {
      return false
    }
  })()

  return initPromise
}

/**
 * Localized price string straight from the store (e.g. "€19,99"). This is what
 * MUST be shown in the app — store prices are set per-territory in App Store
 * Connect / Play Console and can differ from the server's display price.
 * Null until the catalogue is loaded or when the product is not configured.
 */
export function getStorePrice(productId: string | null | undefined): string | null {
  const cdv = getCdvPurchase()
  if (!cdv || !productId) return null
  return cdv.store.get(productId, storePlatform(cdv))?.pricing?.price ?? null
}

// ---------------------------------------------------------------------------
// Purchase & restore
// ---------------------------------------------------------------------------

function resolvePending(outcome: IapPurchaseOutcome): void {
  const resolve = pendingResolve
  pendingResolve = null
  if (resolve) resolve(outcome)
}

function extractReceipt(transaction: CdvTransaction, platform: 'apple' | 'google'): string | null {
  if ('apple' === platform) {
    // StoreKit 2 JWS — required by the backend. Without the storekit2
    // companion plugin this is absent and verification cannot succeed.
    return transaction.jwsRepresentation ?? null
  }
  return (
    transaction.nativePurchase?.purchaseToken ?? transaction.parentReceipt?.purchaseToken ?? null
  )
}

async function handleApproved(transaction: CdvTransaction): Promise<IapPurchaseOutcome> {
  const platform: 'apple' | 'google' = 'android' === getNativePlatform() ? 'google' : 'apple'
  const productId = transaction.products[0]?.id ?? ''
  const receipt = extractReceipt(transaction, platform)

  if (!receipt) {
    const outcome: IapPurchaseOutcome = { status: 'error', code: 'verification_failed' }
    resolvePending(outcome)
    return outcome
  }

  // Purchase-first onboarding: the store sheet needs no app account, but
  // `/api/v1/iap/verify` does. Hold the transaction UNFINISHED (the plugin
  // re-delivers it on the next initialize) and let the post-auth redemption
  // hook verify + finish it once the user has an account.
  if (!hasNativeTokens()) {
    unlinkedTransactions.push(transaction)
    markPendingIapRedemption()
    const outcome: IapPurchaseOutcome = { status: 'purchased_unlinked' }
    resolvePending(outcome)
    return outcome
  }

  try {
    const result = await subscriptionApi.verifyIapPurchase({ platform, receipt, productId })

    if (result.granted || result.pending) {
      // Server accepted (entitlement granted now, or deferred → webhook).
      // Acknowledge with the store so it stops re-delivering the transaction —
      // on Android an unacknowledged purchase is auto-refunded after 3 days.
      await transaction.finish()
      clearPendingIapRedemption()
    }

    let outcome: IapPurchaseOutcome
    if (result.granted) {
      outcome = { status: 'granted', tier: result.tier ?? '' }
    } else if (result.pending) {
      outcome = { status: 'pending' }
    } else {
      outcome = { status: 'error', code: 'verification_failed' }
    }
    resolvePending(outcome)
    return outcome
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : String(error)
    // 409 = receipt owned by another account / other channel owns the sub.
    const isConflict = error instanceof ApiError && 409 === error.status
    if (isConflict) {
      // Retrying with the same account can never succeed — stop reminding.
      clearPendingIapRedemption()
    }
    const outcome: IapPurchaseOutcome = {
      status: 'error',
      code: isConflict ? 'ownership_conflict' : 'verification_failed',
      message,
    }
    resolvePending(outcome)
    return outcome
  }
}

/**
 * Start the native purchase flow for a store product and wait for the outcome
 * (store sheet → approval → server verification → finish).
 */
export async function purchaseProduct(productId: string): Promise<IapPurchaseOutcome> {
  const cdv = getCdvPurchase()
  if (!cdv || !initPromise) {
    return { status: 'error', code: 'not_available' }
  }
  await initPromise

  let offer = cdv.store.get(productId, storePlatform(cdv))?.getOffer()
  if (!offer) {
    // The store catalogue may not have loaded yet (slow store connection on a
    // cold start) — refresh once before giving up.
    try {
      await cdv.store.update()
    } catch {
      /* fall through to the product_unknown outcome */
    }
    offer = cdv.store.get(productId, storePlatform(cdv))?.getOffer()
  }
  if (!offer) {
    return { status: 'error', code: 'product_unknown' }
  }

  return new Promise<IapPurchaseOutcome>((resolve) => {
    pendingResolve = resolve

    void offer.order().then((result) => {
      if (result && result.isError) {
        if (result.code === cdv.ErrorCode.PAYMENT_CANCELLED) {
          resolvePending({ status: 'cancelled' })
        } else {
          resolvePending({ status: 'error', code: 'store_error', message: result.message })
        }
      }
      // No error → wait for the approved → verified path to resolve.
    })
  })
}

/**
 * Re-deliver existing purchases (Apple requirement: a visible "Restore
 * Purchases" affordance). Approved transactions flow through the same
 * server-side verification as a fresh purchase (redeem is idempotent).
 *
 * Returns true when the restore ran; the caller should re-fetch the
 * subscription status from the server afterwards — the server is the source
 * of truth on whether an entitlement was (re-)activated.
 */
export async function restoreNativePurchases(): Promise<boolean> {
  const cdv = getCdvPurchase()
  if (!cdv || !initPromise) {
    return false
  }
  await initPromise

  try {
    await cdv.store.restorePurchases()
    return true
  } catch {
    return false
  }
}

/**
 * Redeem a purchase that was completed while signed out (purchase-first
 * onboarding), now that the user has an account. Call after every successful
 * native authentication — it is a cheap no-op unless a redemption is pending.
 *
 * Returns the verification outcome when it ran synchronously (same-session
 * fast path: the approved transaction is still held in memory), or `null`
 * when there was nothing to redeem or the redemption was handed off to the
 * plugin's asynchronous re-delivery (post-restart path).
 */
export async function redeemPendingIapPurchase(): Promise<IapPurchaseOutcome | null> {
  if (!isNativeApp() || !hasNativeTokens() || !hasPendingIapRedemption()) {
    return null
  }

  // Same-session fast path: the transactions approved while signed out are
  // still in memory — verify + finish them directly.
  if (unlinkedTransactions.length > 0) {
    const pending = unlinkedTransactions
    unlinkedTransactions = []
    let outcome: IapPurchaseOutcome | null = null
    for (const transaction of pending) {
      outcome = await handleApproved(transaction)
    }
    return outcome
  }

  // Post-restart path (e.g. the e-mail verification detour killed the app):
  // initializing the store makes the plugin re-deliver every unfinished
  // transaction through the `approved` handler, which now runs authenticated
  // and verifies + finishes it. `restorePurchases()` additionally re-syncs
  // with the store as a safety net.
  const cdv = getCdvPurchase()
  if (!cdv) {
    return null
  }
  if (!initPromise) {
    try {
      const { plans } = await subscriptionApi.getPlans()
      const productIds = plans
        .map((plan) => plan.iapProductId)
        .filter((id): id is string => 'string' === typeof id && '' !== id)
      if (!(await initNativeIap(productIds))) {
        return null
      }
    } catch {
      return null
    }
  } else {
    await initPromise
  }

  try {
    await cdv.store.restorePurchases()
  } catch {
    /* best-effort — the SubscriptionView restore affordance remains */
  }
  return null
}
