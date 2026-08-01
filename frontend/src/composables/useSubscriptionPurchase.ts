import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getErrorMessage } from '@/utils/errorMessage'
import { subscriptionApi, type SubscriptionPlan } from '@/services/api/subscriptionApi'
import { useAuthStore } from '@/stores/auth'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  getStorePrice,
  initNativeIap,
  isNativeIapAvailable,
  purchaseProduct,
  restoreNativePurchases,
} from '@/services/nativeIap'
import { formatPlanPrice } from '@/utils/formatPrice'

/** Levels that already carry a paid (or unlimited) entitlement. */
export const PAID_LEVELS = ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'] as const

/** Purchasable tiers, ordered from cheapest to most expensive. */
export const PLAN_HIERARCHY = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'] as const

interface PlanBenefit {
  key: string
  params?: Record<string, number>
}

/**
 * Localized benefits per tier, mirroring the server-side plan catalogue. The
 * API returns English-only `features`, so every surface must translate here
 * instead of printing them — otherwise a German user reads English bullets
 * next to German headings.
 *
 * The usage factors mirror the cost budgets of the reference deployment
 * (Free €1 / Pro €15 / Team €40 / Business €80 per month). Plans are
 * budget-based, so never list absolute message/image/video/storage counts
 * here — keep in sync with `SubscriptionController::PLAN_CATALOGUE`.
 *
 * `satisfies` pins the keys to known tiers: a typo would otherwise compile and
 * silently drop that tier back to the untranslated server list. The declared
 * index signature stays wide because the lookup key is a runtime plan id.
 */
const PLAN_BENEFITS: Record<string, PlanBenefit[]> = {
  PRO: [
    { key: 'usage', params: { factor: 15 } },
    { key: 'advancedModels' },
    { key: 'prioritySupport' },
  ],
  TEAM: [
    { key: 'everythingInPro' },
    { key: 'usage', params: { factor: 40 } },
    { key: 'teamCollaboration' },
    { key: 'customPrompts' },
    { key: 'apiAccess' },
  ],
  BUSINESS: [
    { key: 'everythingInTeam' },
    { key: 'usage', params: { factor: 80 } },
    { key: 'whiteLabel' },
    { key: 'dedicatedSupport' },
    { key: 'slaGuarantee' },
  ],
} satisfies Partial<Record<(typeof PLAN_HIERARCHY)[number], PlanBenefit[]>>

interface UseSubscriptionPurchaseOptions {
  /**
   * Invoked after the server-side entitlement may have changed (successful
   * purchase or restore), so the caller can refresh anything it renders from
   * the subscription status.
   */
  onEntitlementChange?: () => Promise<void> | void
}

/**
 * Shared purchase flow for every surface that sells a plan (the subscription
 * page and the paywall modal).
 *
 * MOBILE-APP SEAM (Epic 5.2 / 5.3): the channel split lives here exactly once.
 * The native shell buys through store IAP with server-side verification and
 * must never reach the Stripe web checkout (Apple 3.1.1 / Google Play); the web
 * keeps the Stripe redirect.
 */
export function useSubscriptionPurchase(options: UseSubscriptionPurchaseOptions = {}) {
  const { t, te } = useI18n()
  const authStore = useAuthStore()
  const dialog = useDialog()
  const { success } = useNotification()

  const isNative = isNativeApp()

  const plans = ref<SubscriptionPlan[]>([])
  const stripeConfigured = ref(true)
  const loadingPlans = ref(false)
  const isProcessing = ref(false)
  /** Set once the native store catalogue is loaded (native shell only). */
  const storePricesReady = ref(false)

  const currentLevel = computed(() => authStore.user?.level)
  const hasActivePlan = computed(() =>
    PAID_LEVELS.includes((currentLevel.value ?? '') as (typeof PAID_LEVELS)[number])
  )

  async function loadPlans(): Promise<void> {
    loadingPlans.value = true
    try {
      const response = await subscriptionApi.getPlans()
      plans.value = response.plans
      stripeConfigured.value = response.stripeConfigured
      void loadStorePrices(response.plans)
    } catch (error) {
      console.error('Failed to load plans:', error)
    } finally {
      loadingPlans.value = false
    }
  }

  /** Fetch the store's localized prices (native shell only, non-blocking). */
  async function loadStorePrices(loadedPlans: SubscriptionPlan[]): Promise<void> {
    if (!isNativeIapAvailable()) return
    const productIds = loadedPlans
      .map((plan) => plan.iapProductId)
      .filter((id): id is string => 'string' === typeof id && '' !== id)
    storePricesReady.value = await initNativeIap(productIds)
  }

  /**
   * Price shown to the user, channel-aware:
   * - Native app: the store's own localized price wins (that is what Apple/Google
   *   actually charge); until the catalogue is loaded, `appPrice` (web price plus
   *   the store-commission markup) is the fallback so the app never advertises
   *   the cheaper web price (anti-steering).
   * - Web: always the plain server-configured `price`.
   */
  function displayPrice(plan: SubscriptionPlan): string {
    if (isNative) {
      if (storePricesReady.value) {
        const storePrice = getStorePrice(plan.iapProductId)
        if (storePrice) return storePrice
      }
      return formatPlanPrice(plan.appPrice, plan.currency)
    }
    return formatPlanPrice(plan.price, plan.currency)
  }

  /**
   * The tier name falls back to what the server sent for a tier this build has
   * no copy for — `t()` would otherwise print the raw key path,
   * e.g. `subscription.plans.studio`, as the card heading.
   */
  function planName(plan: SubscriptionPlan): string {
    const key = `subscription.plans.${plan.id.toLowerCase()}`
    return te(key) ? t(key) : plan.name
  }

  /**
   * Falls back to the server's English list only for a tier this build has no
   * copy for — better an untranslated benefit than an empty card.
   */
  function planBenefits(plan: SubscriptionPlan): string[] {
    const mapped = PLAN_BENEFITS[plan.id]
    if (!mapped) return plan.features
    return mapped.map((benefit) => t(`subscription.features.${benefit.key}`, benefit.params ?? {}))
  }

  function isCurrentPlan(planId: string): boolean {
    return currentLevel.value === planId
  }

  /**
   * Whether `planId` ranks below the tier the user already has, which the
   * surfaces dim. A tier missing from {@link PLAN_HIERARCHY} — a new or custom
   * one from the backend — is deliberately not ranked: it stays fully offered
   * rather than being dimmed away as if it were a downgrade.
   */
  function isLowerPlan(planId: string): boolean {
    if (!currentLevel.value) return false
    const currentIndex = PLAN_HIERARCHY.indexOf(
      currentLevel.value as (typeof PLAN_HIERARCHY)[number]
    )
    const planIndex = PLAN_HIERARCHY.indexOf(planId as (typeof PLAN_HIERARCHY)[number])
    if (0 > currentIndex || 0 > planIndex) return false
    return planIndex < currentIndex
  }

  async function selectPlan(planId: string): Promise<void> {
    // MOBILE-APP SEAM (Epic 5.2): never open the Stripe web checkout in the app.
    if (isNative) {
      await startNativePurchase(planId)
      return
    }

    if (!stripeConfigured.value) {
      await dialog.alert({
        title: t('subscription.serviceNotAvailable'),
        message: t('subscription.serviceNotConfigured'),
      })
      return
    }

    isProcessing.value = true
    try {
      const response = await subscriptionApi.createCheckoutSession(planId)
      // Redirect to Stripe Checkout
      window.location.href = response.url
    } catch (error: unknown) {
      console.error('Failed to create checkout session:', error)

      if (
        getErrorMessage(error)?.includes('unavailable') ||
        getErrorMessage(error)?.includes('STRIPE_NOT_CONFIGURED')
      ) {
        await dialog.alert({
          title: t('subscription.serviceNotAvailable'),
          message: t('subscription.serviceNotConfigured'),
        })
      } else {
        await dialog.alert({
          title: t('common.error'),
          message: t('subscription.checkoutFailed'),
        })
      }

      isProcessing.value = false
    }
  }

  /**
   * MOBILE-APP SEAM (Epic 5.3): start a native in-app purchase for the selected
   * tier via the store billing plugin (StoreKit 2 / Play Billing). The purchase
   * is verified server-side (`/api/v1/iap/verify`) before any tier is granted;
   * this never falls back to the web checkout (Apple 3.1.1 / Google Play).
   */
  async function startNativePurchase(planId: string): Promise<void> {
    const plan = plans.value.find((p) => p.id === planId)
    const productId = plan?.iapProductId

    if (!productId || !isNativeIapAvailable()) {
      await dialog.alert({
        title: t('subscription.native.purchaseTitle'),
        message: t('subscription.native.purchaseUnavailable'),
      })
      return
    }

    isProcessing.value = true
    try {
      const outcome = await purchaseProduct(productId)

      switch (outcome.status) {
        case 'granted':
          await refreshEntitlement()
          success(t('subscription.native.purchaseSuccess'))
          break
        case 'pending':
          await dialog.alert({
            title: t('subscription.native.purchaseTitle'),
            message: t('subscription.native.purchasePending'),
          })
          break
        case 'cancelled':
          // The user closed the store sheet — no message needed.
          break
        case 'error': {
          const messageKey =
            'ownership_conflict' === outcome.code
              ? 'subscription.native.purchaseConflict'
              : 'not_available' === outcome.code
                ? 'subscription.native.purchaseUnavailable'
                : 'subscription.native.purchaseFailed'
          await dialog.alert({
            title: t('subscription.native.purchaseTitle'),
            message: t(messageKey),
          })
          break
        }
      }
    } finally {
      isProcessing.value = false
    }
  }

  /**
   * MOBILE-APP SEAM (Epic 9.4): Apple requires a "Restore Purchases" path so a
   * user who reinstalls or switches devices can recover an active subscription.
   * Restored transactions run through the same server-side verification as a
   * fresh purchase; the refreshed server status is the source of truth.
   */
  async function restorePurchases(): Promise<void> {
    isProcessing.value = true
    try {
      const ran = await restoreNativePurchases()
      if (!ran) {
        await dialog.alert({
          title: t('subscription.native.restoreTitle'),
          message: t('subscription.native.purchaseUnavailable'),
        })
        return
      }

      await refreshEntitlement()
      if (hasActivePlan.value) {
        success(t('subscription.native.restoreDone'))
      } else {
        await dialog.alert({
          title: t('subscription.native.restoreTitle'),
          message: t('subscription.native.restoreNone'),
        })
      }
    } finally {
      isProcessing.value = false
    }
  }

  async function refreshEntitlement(): Promise<void> {
    await Promise.all([
      authStore.refreshUser(),
      options.onEntitlementChange?.() ?? Promise.resolve(),
    ])
  }

  return {
    isNative,
    plans,
    stripeConfigured,
    storePricesReady,
    loadingPlans,
    isProcessing,
    currentLevel,
    hasActivePlan,
    loadPlans,
    displayPrice,
    planName,
    planBenefits,
    isCurrentPlan,
    isLowerPlan,
    selectPlan,
    restorePurchases,
  }
}
