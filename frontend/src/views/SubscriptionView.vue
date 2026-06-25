<template>
  <MainLayout data-testid="page-subscription">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-6xl mx-auto space-y-8">
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
        </div>

        <!-- Main Content -->
        <template v-else>
          <!-- Header -->
          <div class="text-center mb-12">
            <h1 class="text-4xl font-bold txt-primary mb-3">
              {{
                hasActivePlan ? $t('subscription.manage.title') : $t('subscription.chooseYourPlan')
              }}
            </h1>
            <p class="txt-secondary text-lg">
              {{ hasActivePlan ? $t('subscription.manage.subtitle') : $t('subscription.subtitle') }}
            </p>
          </div>

          <!--
            Payment-failed warning (issue #856).
            Shown above section-current-plan when Stripe declined the last
            invoice. The user keeps access during Stripe's smart-retry
            window (typically ~3 weeks) but needs to update their card.
            CTA reuses the existing customer-portal flow.
          -->
          <div
            v-if="hasActivePlan && showPaymentFailedWarning && !isNative"
            data-testid="section-payment-failed"
            class="alert-warning max-w-2xl mx-auto mb-8"
            role="alert"
          >
            <div class="flex items-start gap-3">
              <Icon icon="mdi:alert-circle" class="w-6 h-6 flex-shrink-0" />
              <div class="flex-1">
                <p class="font-semibold alert-warning-text mb-1">
                  {{ $t('subscription.manage.paymentFailedTitle') }}
                </p>
                <p class="alert-warning-text text-sm mb-3">
                  {{ $t('subscription.manage.paymentFailedBody') }}
                </p>
                <button
                  :disabled="isProcessing || !stripeConfigured"
                  class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
                  data-testid="btn-fix-payment"
                  @click="openBillingPortal"
                >
                  <Icon
                    v-if="isProcessing"
                    icon="mdi:loading"
                    class="w-4 h-4 animate-spin inline mr-2"
                  />
                  {{ $t('subscription.manage.paymentFailedCta') }}
                </button>
              </div>
            </div>
          </div>

          <!-- Current Subscription Info (if active) -->
          <div
            v-if="hasActivePlan"
            data-testid="section-current-plan"
            class="surface-card rounded-xl p-6 max-w-2xl mx-auto mb-8"
          >
            <div class="flex items-center justify-between flex-wrap gap-4">
              <div class="flex items-center gap-4">
                <Icon icon="mdi:crown" class="w-10 h-10 text-yellow-500" />
                <div>
                  <div class="flex items-center gap-3 mb-1 flex-wrap">
                    <span class="text-lg font-bold txt-primary">{{
                      $t('subscription.manage.currentPlanLabel')
                    }}</span>
                    <span
                      data-testid="badge-current-level"
                      :class="getLevelBadgeClass(currentLevel || 'NEW')"
                      >{{ currentLevel }}</span
                    >
                    <span
                      v-if="subscriptionStatus?.hasSubscription"
                      data-testid="badge-subscription-status"
                      :class="getStatusBadgeClass(subscriptionStatus.status || 'active')"
                    >
                      {{ getStatusText(subscriptionStatus.status || 'active') }}
                    </span>
                  </div>
                  <p
                    v-if="subscriptionStatus?.cancelAt"
                    data-testid="text-cancel-date"
                    class="text-amber-500 text-sm font-medium"
                  >
                    <Icon icon="mdi:alert" class="w-4 h-4 inline mr-1" />
                    {{ $t('subscription.manage.cancelDate') }}:
                    {{ formatDate(subscriptionStatus.cancelAt) }}
                  </p>
                  <p
                    v-else-if="subscriptionStatus?.nextBilling && !showPaymentFailedWarning"
                    data-testid="text-next-billing"
                    class="txt-secondary text-sm"
                  >
                    {{ $t('subscription.manage.nextBilling') }}:
                    {{ formatDate(subscriptionStatus.nextBilling) }}
                  </p>
                  <p
                    v-if="isHighestPlan && !subscriptionStatus?.cancelAt"
                    class="txt-secondary text-sm mt-1"
                  >
                    {{ $t('subscription.highestPlan') }}
                  </p>
                </div>
              </div>
              <button
                v-if="subscriptionStatus?.hasSubscription"
                :disabled="isProcessing || (!isNative && !stripeConfigured)"
                class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-open-portal"
                @click="openBillingPortal"
              >
                <Icon
                  v-if="isProcessing"
                  icon="mdi:loading"
                  class="w-4 h-4 animate-spin inline mr-2"
                />
                {{ manageLabel }}
              </button>
            </div>
          </div>

          <!-- Stripe Not Configured Warning (web only — native buys via IAP) -->
          <div v-if="!stripeConfigured && !isNative" class="alert-warning max-w-2xl mx-auto mb-8">
            <div class="flex items-start gap-3">
              <Icon icon="mdi:alert-circle" class="w-6 h-6 flex-shrink-0" />
              <div>
                <p class="font-semibold alert-warning-text mb-2">
                  {{ $t('subscription.unavailable') }}
                </p>
                <p class="text-sm alert-warning-text">{{ $t('subscription.unavailableDesc') }}</p>
              </div>
            </div>
          </div>

          <!-- Plans Grid (native always shows them — purchase routes to IAP) -->
          <div v-if="stripeConfigured || isNative" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div
              v-for="plan in plans"
              :key="plan.id"
              class="surface-card rounded-xl p-8 flex flex-col transition-shadow"
              :class="[
                plan.id === 'TEAM' && !isCurrentPlan(plan.id)
                  ? 'border-2 border-[var(--brand)] relative hover:shadow-xl'
                  : '',
                isCurrentPlan(plan.id) ? 'ring-2 ring-green-500 relative' : 'hover:shadow-xl',
                isLowerPlan(plan.id) ? 'opacity-60' : '',
              ]"
              data-testid="card-plan"
            >
              <!-- Current Plan Badge -->
              <div
                v-if="isCurrentPlan(plan.id)"
                class="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-green-500 text-white px-4 py-1 rounded-full text-sm font-semibold shadow-lg"
              >
                {{ $t('subscription.currentPlan') }}
              </div>
              <!-- Recommended Badge (only if not current and not lower) -->
              <div
                v-else-if="plan.id === 'TEAM' && !isLowerPlan(plan.id)"
                class="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-1 rounded-full text-sm font-semibold shadow-lg"
              >
                {{ $t('subscription.recommended') }}
              </div>

              <!-- Plan Header -->
              <div class="mb-6">
                <h3 class="text-2xl font-bold txt-primary mb-2">
                  {{ $t(`subscription.plans.${plan.id.toLowerCase()}`) }}
                </h3>
                <div class="flex items-baseline gap-1">
                  <span class="text-4xl font-bold txt-primary">€{{ plan.price }}</span>
                  <span class="txt-secondary"
                    >/{{ $t(`subscription.per${capitalize(plan.interval)}`) }}</span
                  >
                </div>
              </div>

              <!-- Features List -->
              <ul class="space-y-3 mb-8 flex-grow">
                <li
                  v-for="(feature, index) in plan.features"
                  :key="index"
                  class="flex items-start gap-3"
                >
                  <Icon
                    icon="mdi:check-circle"
                    class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5"
                  />
                  <span class="txt-secondary text-sm">{{ feature }}</span>
                </li>
              </ul>

              <!-- CTA Button -->
              <button
                v-if="isCurrentPlan(plan.id)"
                disabled
                class="w-full py-3 rounded-lg font-semibold bg-green-500/20 text-green-600 dark:text-green-400 cursor-default"
              >
                <Icon icon="mdi:check" class="w-5 h-5 inline mr-2" />
                {{ $t('subscription.currentPlan') }}
              </button>
              <button
                v-else-if="isLowerPlan(plan.id)"
                disabled
                class="w-full py-3 rounded-lg font-semibold surface-chip txt-secondary cursor-not-allowed"
              >
                {{ $t('subscription.includedInCurrent') }}
              </button>
              <button
                v-else
                :disabled="isProcessing"
                :class="[
                  'w-full py-3 rounded-lg font-semibold transition-all',
                  plan.id === 'TEAM'
                    ? 'bg-gradient-to-r from-blue-600 to-purple-600 text-white hover:shadow-lg'
                    : 'btn-primary',
                ]"
                :data-testid="`btn-select-${plan.id.toLowerCase()}`"
                @click="selectPlan(plan.id)"
              >
                {{
                  isProcessing
                    ? $t('subscription.processing')
                    : hasActivePlan
                      ? $t('subscription.upgrade')
                      : $t('subscription.selectPlan')
                }}
              </button>
            </div>
          </div>

          <!--
            MOBILE-APP SEAM (Epic 9.4): store-managed purchases + anti-steering.
            Apple requires a restore-purchases path; both stores forbid steering
            to web checkout for digital goods, so the app exposes only native
            affordances and never a link to the web billing flow.
          -->
          <div
            v-if="isNative"
            data-testid="section-native-store"
            class="max-w-2xl mx-auto mt-8 text-center space-y-3"
          >
            <button
              :disabled="isProcessing"
              class="btn-secondary px-5 py-2.5 rounded-lg text-sm font-medium"
              data-testid="btn-restore-purchases"
              @click="restorePurchases"
            >
              {{ $t('subscription.native.restoreButton') }}
            </button>
            <p class="txt-secondary text-xs">{{ $t('subscription.native.storeNote') }}</p>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { getErrorMessage } from '@/utils/errorMessage'
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import {
  subscriptionApi,
  type SubscriptionPlan,
  type SubscriptionStatus,
} from '@/services/api/subscriptionApi'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { useDialog } from '@/composables/useDialog'
import { isNativeApp } from '@/services/api/nativeRuntime'
import MainLayout from '@/components/MainLayout.vue'

const { t, te } = useI18n()
const { formatDateTime } = useDateFormat()
const router = useRouter()
const authStore = useAuthStore()
const config = useConfigStore()
const dialog = useDialog()
const plans = ref<SubscriptionPlan[]>([])
const loading = ref(false)
const isProcessing = ref(false)
const stripeConfigured = ref(true)
const subscriptionStatus = ref<SubscriptionStatus | null>(null)

/**
 * MOBILE-APP SEAM (Epic 5.2): inside the native shell the Stripe web checkout
 * and billing portal are forbidden (Apple 3.1.1 / Google Play). The app must
 * purchase via native IAP (wired in Epic 5.3) and manage via the store. This
 * flag flips the purchase/manage paths to the native ones and guarantees the
 * web `window.location.href` redirects can never fire in the app.
 */
const isNative = isNativeApp()

const currentLevel = computed(() => authStore.user?.level)
const hasActivePlan = computed(() => {
  return currentLevel.value && ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'].includes(currentLevel.value)
})

// Plan hierarchy for upgrade logic (ADMIN is special - unlimited, not a purchasable plan)
const planHierarchy = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN']

const isHighestPlan = computed(() => {
  return currentLevel.value === 'BUSINESS' || currentLevel.value === 'ADMIN'
})

// MOBILE-APP SEAM (Epic 9.4): in the app, "manage" means the store's own
// subscription settings (Apple/Google), never the Stripe billing portal.
const manageLabel = computed(() =>
  isNative ? t('subscription.native.manageInStore') : t('subscription.manage.openPortal')
)

/**
 * Surface the dunning warning whenever the backend tells us either that
 * the last invoice was declined (`paymentFailed === true`, set on
 * `invoice.payment_failed`) OR that the subscription has already
 * transitioned into Stripe's `past_due` window. Issue #856 asks for both
 * — the flag persists across the smart-retry window and the status
 * persists on Stripe's side, so reading both keeps the warning visible
 * for the entire period the user might lose access.
 */
const showPaymentFailedWarning = computed(() => {
  if (!subscriptionStatus.value) return false
  if (subscriptionStatus.value.paymentFailed === true) return true
  return subscriptionStatus.value.status === 'past_due'
})

function isCurrentPlan(planId: string): boolean {
  return currentLevel.value === planId
}

function isLowerPlan(planId: string): boolean {
  if (!currentLevel.value) return false
  const currentIndex = planHierarchy.indexOf(currentLevel.value)
  const planIndex = planHierarchy.indexOf(planId)
  return planIndex < currentIndex
}

async function loadPlans() {
  loading.value = true
  try {
    const response = await subscriptionApi.getPlans()
    plans.value = response.plans
    stripeConfigured.value = response.stripeConfigured
  } catch (error) {
    console.error('Failed to load plans:', error)
  } finally {
    loading.value = false
  }
}

async function loadSubscriptionStatus() {
  try {
    subscriptionStatus.value = await subscriptionApi.getSubscriptionStatus()
  } catch (error) {
    console.error('Failed to load subscription status:', error)
  }
}

async function selectPlan(planId: string) {
  // MOBILE-APP SEAM (Epic 5.2): never open the Stripe web checkout in the app.
  // Native purchases go through IAP (Epic 5.3 wires the store billing plugin here).
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

    // Show user-friendly error
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
 * tier via the store billing plugin. Until that plugin is wired, this never
 * falls back to the web checkout — it only informs the user. The selected plan
 * is surfaced so the message is concrete.
 */
async function startNativePurchase(planId: string) {
  await dialog.alert({
    title: t('subscription.native.purchaseTitle'),
    message: t('subscription.native.purchaseComingSoon', { plan: planId }),
  })
}

/**
 * MOBILE-APP SEAM (Epic 9.4): Apple requires a "Restore Purchases" path so a
 * user who reinstalls or switches devices can recover an active subscription.
 * The store plugin's restore flow is wired on-device in Epic 5.3; until then we
 * surface a clear message and never fall back to the web billing flow.
 */
async function restorePurchases() {
  await dialog.alert({
    title: t('subscription.native.restoreTitle'),
    message: t('subscription.native.restoreComingSoon'),
  })
}

async function openBillingPortal() {
  // MOBILE-APP SEAM (Epic 5.2): the Stripe billing portal is a web redirect and
  // is not allowed in the app. Send the user to the store's subscription settings.
  if (isNative) {
    const url = subscriptionStatus.value?.manageUrl
    if (url) {
      window.open(url, '_blank')
    }
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
    const response = await subscriptionApi.createPortalSession()
    // Redirect to Stripe Customer Portal
    window.location.href = response.url
  } catch (error: unknown) {
    console.error('Failed to open billing portal:', error)
    await dialog.alert({
      title: t('common.error'),
      message: t('subscription.checkoutFailed'),
    })
    isProcessing.value = false
  }
}

function getLevelBadgeClass(level: string): string {
  const classes: Record<string, string> = {
    NEW: 'badge-level badge-new',
    PRO: 'badge-level badge-pro',
    TEAM: 'badge-level badge-team',
    BUSINESS: 'badge-level badge-business',
    ADMIN: 'badge-level badge-admin',
  }
  return classes[level] || classes['NEW']
}

function getStatusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    active: 'badge-level badge-business',
    canceled: 'badge-level badge-new',
    past_due: 'badge-level badge-admin',
    trialing: 'badge-level badge-pro',
  }
  return classes[status] || classes['active']
}

/**
 * Map a Stripe subscription status (`active`, `past_due`,
 * `incomplete_expired`, …) to the camelCase i18n key the locale files use
 * (`statusActive`, `statusPastDue`, `statusIncompleteExpired`).
 *
 * Pre-fix this used `capitalize()` which only uppercases the first letter
 * and produced broken keys like `statusPast_due` for snake_case statuses
 * (issue #856). Splitting on `_` and PascalCasing the parts is enough —
 * Stripe's status enum has no other separator characters.
 *
 * Per Copilot review on PR #931, the previous `t(...) || status` fallback
 * never fired because vue-i18n's `t()` returns the key path itself when
 * a translation is missing (a truthy string), so we'd render
 * `subscription.manage.statusFooBar` instead of falling through to the
 * raw `foo_bar`. Use `te()` to check key existence before `t()`.
 */
function getStatusText(status: string): string {
  const camelKey = status
    .split('_')
    .map((part, index) => (0 === index ? part : capitalize(part)))
    .join('')
  const i18nKey = `subscription.manage.status${capitalize(camelKey)}`

  return te(i18nKey) ? t(i18nKey) : status
}

function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1)
}

function formatDate(timestamp: string | number): string {
  try {
    const date = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp)
    return formatDateTime(date)
  } catch {
    return String(timestamp)
  }
}

onMounted(async () => {
  if (!config.billing.enabled) {
    router.push('/')
    return
  }
  await loadPlans()
  await loadSubscriptionStatus()
})
</script>
