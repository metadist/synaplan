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

/**
 * True when the store actually knows this product and offers it for sale —
 * i.e. a purchase started now can reach the payment sheet. False before
 * `initNativeIap()` completed, when the billing plugin is missing, or when the
 * store has no catalogue for the product (e.g. a dev build launched via
 * `cap run`, where Xcode's StoreKit configuration file is not applied).
 */
export function isStoreProductReady(productId: string | null | undefined): boolean {
  const cdv = getCdvPurchase()
  if (!cdv || !productId) return false
  return undefined !== cdv.store.get(productId, storePlatform(cdv))?.getOffer()
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

async function handleApproved(transaction: CdvTransaction): Promise<void> {
  const platform: 'apple' | 'google' = 'android' === getNativePlatform() ? 'google' : 'apple'
  const productId = transaction.products[0]?.id ?? ''
  const receipt = extractReceipt(transaction, platform)

  if (!receipt) {
    resolvePending({ status: 'error', code: 'verification_failed' })
    return
  }

  try {
    const result = await subscriptionApi.verifyIapPurchase({ platform, receipt, productId })

    if (result.granted || result.pending) {
      // Server accepted (entitlement granted now, or deferred → webhook).
      // Acknowledge with the store so it stops re-delivering the transaction —
      // on Android an unacknowledged purchase is auto-refunded after 3 days.
      await transaction.finish()
    }

    if (result.granted) {
      resolvePending({ status: 'granted', tier: result.tier ?? '' })
    } else if (result.pending) {
      resolvePending({ status: 'pending' })
    } else {
      resolvePending({ status: 'error', code: 'verification_failed' })
    }
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : String(error)
    // 409 = receipt owned by another account / other channel owns the sub.
    const isConflict = error instanceof ApiError && 409 === error.status
    resolvePending({
      status: 'error',
      code: isConflict ? 'ownership_conflict' : 'verification_failed',
      message,
    })
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

  const offer = cdv.store.get(productId, storePlatform(cdv))?.getOffer()
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
