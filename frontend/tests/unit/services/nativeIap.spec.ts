import { describe, it, expect, vi, beforeEach } from 'vitest'

/**
 * MOBILE-APP SEAM (purchase-first onboarding): a store purchase approved while
 * SIGNED OUT must never hit `/api/v1/iap/verify` (Bearer-only, would 401) and
 * must never be finished — the transaction is held as a pending redemption and
 * linked to the account after authentication (`redeemPendingIapPurchase`).
 *
 * The module keeps state (init promise, held transactions), so every test
 * imports a fresh copy via `vi.resetModules()`.
 */

const mockIsNativeApp = vi.fn(() => true)
const mockGetNativePlatform = vi.fn(() => 'ios')
const mockHasNativeTokens = vi.fn(() => false)
const mockVerifyIapPurchase = vi.fn()
const mockGetPlans = vi.fn()

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => mockIsNativeApp(),
  getNativePlatform: () => mockGetNativePlatform(),
}))

vi.mock('@/services/api/nativeAuth', () => ({
  hasNativeTokens: () => mockHasNativeTokens(),
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    verifyIapPurchase: (...args: unknown[]) => mockVerifyIapPurchase(...args),
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  ApiError: class ApiError extends Error {
    public constructor(
      public readonly status: number,
      message: string
    ) {
      super(message)
    }
  },
}))

type ApprovedCallback = (transaction: Record<string, unknown>) => void

/** Minimal CdvPurchase global capturing the `approved` handler. */
function installCdvPurchase() {
  let approvedCb: ApprovedCallback = () => {}
  const offer = { order: vi.fn(async () => undefined) }
  const store = {
    register: vi.fn(),
    initialize: vi.fn(async () => undefined),
    update: vi.fn(async () => undefined),
    restorePurchases: vi.fn(async () => undefined),
    get: vi.fn(() => ({ id: 'pro.monthly', getOffer: () => offer })),
    when: () => ({
      approved: (cb: ApprovedCallback) => {
        approvedCb = cb
      },
    }),
    error: vi.fn(),
  }
  ;(globalThis as Record<string, unknown>).CdvPurchase = {
    store,
    Platform: { APPLE_APPSTORE: 'ios-appstore', GOOGLE_PLAY: 'android-playstore' },
    ProductType: { PAID_SUBSCRIPTION: 'paid subscription' },
    ErrorCode: { PAYMENT_CANCELLED: 6777006 },
  }
  return { store, offer, getApprovedCb: () => approvedCb }
}

function makeTransaction() {
  return {
    products: [{ id: 'pro.monthly' }],
    state: 'approved',
    jwsRepresentation: 'signed-jws',
    finish: vi.fn(async () => undefined),
  }
}

async function freshModule() {
  vi.resetModules()
  return import('@/services/nativeIap')
}

/**
 * `purchaseProduct` awaits the (already resolved) init promise before it arms
 * its resolver — yield a macrotask so the `approved` callback fired by a test
 * lands after that and the outcome is not lost.
 */
function tick(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('nativeIap — purchase-first pending redemption', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    mockIsNativeApp.mockReturnValue(true)
    mockHasNativeTokens.mockReturnValue(false)
  })

  it('holds a signed-out purchase unfinished and resolves purchased_unlinked', async () => {
    const { store, getApprovedCb } = installCdvPurchase()
    const iap = await freshModule()

    await iap.initNativeIap(['pro.monthly'])
    expect(store.register).toHaveBeenCalled()

    const purchase = iap.purchaseProduct('pro.monthly')
    await tick()
    const transaction = makeTransaction()
    getApprovedCb()(transaction)

    await expect(purchase).resolves.toEqual({ status: 'purchased_unlinked' })
    // Bearer-only endpoint must not be called signed-out; the transaction
    // stays unfinished so the store re-delivers it after a restart.
    expect(mockVerifyIapPurchase).not.toHaveBeenCalled()
    expect(transaction.finish).not.toHaveBeenCalled()
    expect(iap.hasPendingIapRedemption()).toBe(true)
  })

  it('redeems the held transaction after authentication (verify → finish → flag cleared)', async () => {
    const { getApprovedCb } = installCdvPurchase()
    const iap = await freshModule()

    await iap.initNativeIap(['pro.monthly'])
    const purchase = iap.purchaseProduct('pro.monthly')
    await tick()
    const transaction = makeTransaction()
    getApprovedCb()(transaction)
    await purchase

    mockHasNativeTokens.mockReturnValue(true)
    mockVerifyIapPurchase.mockResolvedValue({ granted: true, pending: false, tier: 'PRO' })

    const outcome = await iap.redeemPendingIapPurchase()

    expect(outcome).toEqual({ status: 'granted', tier: 'PRO' })
    expect(mockVerifyIapPurchase).toHaveBeenCalledWith({
      platform: 'apple',
      receipt: 'signed-jws',
      productId: 'pro.monthly',
    })
    expect(transaction.finish).toHaveBeenCalled()
    expect(iap.hasPendingIapRedemption()).toBe(false)
  })

  it('is a no-op without a pending redemption or without auth', async () => {
    installCdvPurchase()
    const iap = await freshModule()

    // Authenticated, but nothing pending.
    mockHasNativeTokens.mockReturnValue(true)
    await expect(iap.redeemPendingIapPurchase()).resolves.toBeNull()

    // Pending, but still signed out.
    localStorage.setItem('synaplan.iapPendingRedemption', '1')
    mockHasNativeTokens.mockReturnValue(false)
    await expect(iap.redeemPendingIapPurchase()).resolves.toBeNull()
    expect(mockVerifyIapPurchase).not.toHaveBeenCalled()
  })

  it('clears the reminder on an ownership conflict (retrying cannot succeed)', async () => {
    const { getApprovedCb } = installCdvPurchase()
    const iap = await freshModule()
    const { ApiError } = await import('@/services/api/httpClient')

    await iap.initNativeIap(['pro.monthly'])
    const purchase = iap.purchaseProduct('pro.monthly')
    await tick()
    const transaction = makeTransaction()
    getApprovedCb()(transaction)
    await purchase

    mockHasNativeTokens.mockReturnValue(true)
    mockVerifyIapPurchase.mockRejectedValue(new ApiError(409, 'owned by another account'))

    const outcome = await iap.redeemPendingIapPurchase()

    expect(outcome).toMatchObject({ status: 'error', code: 'ownership_conflict' })
    expect(transaction.finish).not.toHaveBeenCalled()
    expect(iap.hasPendingIapRedemption()).toBe(false)
  })

  it('after a restart, initializes from the plan catalogue and hands off to store re-delivery', async () => {
    const { store } = installCdvPurchase()
    const iap = await freshModule()

    // Restart: the flag survived (localStorage), the transaction did not.
    localStorage.setItem('synaplan.iapPendingRedemption', '1')
    mockHasNativeTokens.mockReturnValue(true)
    mockGetPlans.mockResolvedValue({
      plans: [{ id: 'PRO', iapProductId: 'pro.monthly' }],
      stripeConfigured: false,
      iapConfigured: true,
    })

    const outcome = await iap.redeemPendingIapPurchase()

    // Handed off to the plugin's asynchronous re-delivery — no sync outcome.
    expect(outcome).toBeNull()
    expect(mockGetPlans).toHaveBeenCalled()
    expect(store.register).toHaveBeenCalledWith([
      { id: 'pro.monthly', type: 'paid subscription', platform: 'ios-appstore' },
    ])
    expect(store.initialize).toHaveBeenCalled()
    expect(store.restorePurchases).toHaveBeenCalled()
  })

  it('verifies immediately when a purchase is approved with an authenticated session', async () => {
    const { getApprovedCb } = installCdvPurchase()
    const iap = await freshModule()
    mockHasNativeTokens.mockReturnValue(true)
    mockVerifyIapPurchase.mockResolvedValue({ granted: true, pending: false, tier: 'PRO' })

    await iap.initNativeIap(['pro.monthly'])
    const purchase = iap.purchaseProduct('pro.monthly')
    await tick()
    const transaction = makeTransaction()
    getApprovedCb()(transaction)

    await expect(purchase).resolves.toEqual({ status: 'granted', tier: 'PRO' })
    expect(transaction.finish).toHaveBeenCalled()
    expect(iap.hasPendingIapRedemption()).toBe(false)
  })
})
