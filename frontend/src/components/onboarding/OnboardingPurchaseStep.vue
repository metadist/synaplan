<!-- NOTE: no comments before the root element — a comment node at template
     root breaks the parent <Transition mode="out-in"> (vuejs/core#6656). -->
<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-purchase">
    <!-- Busy: subscription pre-check, then the store sheet -->
    <template v-if="'checking' === state || 'purchasing' === state">
      <div
        class="mx-auto w-14 h-14 rounded-full bg-brand/10 dark:bg-brand/20 flex items-center justify-center"
        aria-hidden="true"
      >
        <Icon icon="mdi:loading" class="w-8 h-8 text-brand animate-spin" />
      </div>
      <p class="mt-4 text-sm txt-secondary" data-testid="text-purchase-busy" role="status">
        {{
          'checking' === state
            ? $t('onboarding.purchase.checking')
            : $t('onboarding.purchase.purchasing')
        }}
      </p>
    </template>

    <!-- Pre-check hit: this account already has an active subscription.
         No store sheet is shown, nothing is charged. -->
    <template v-else-if="'already' === state">
      <div
        class="mx-auto w-14 h-14 rounded-full bg-green-500/10 dark:bg-green-400/15 flex items-center justify-center"
        aria-hidden="true"
      >
        <Icon icon="mdi:check-decagram" class="w-8 h-8 text-green-500 dark:text-green-400" />
      </div>
      <h1 class="mt-4 text-xl font-bold txt-primary">
        {{ $t('onboarding.purchase.alreadyTitle') }}
      </h1>
      <p class="text-sm txt-secondary mt-1.5" data-testid="text-purchase-already">
        {{ $t('onboarding.purchase.alreadyBody', { plan: existingPlanName }) }}
      </p>
      <div class="mt-6 space-y-2.5">
        <button
          class="w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 active:scale-[0.98]"
          data-testid="btn-purchase-to-chat"
          @click="emit('done')"
        >
          {{ $t('onboarding.purchase.toChat') }}
        </button>
        <button
          class="w-full py-2.5 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98]"
          data-testid="btn-purchase-manage"
          @click="emit('manage')"
        >
          {{ $t('onboarding.purchase.alreadyManage') }}
        </button>
      </div>
    </template>

    <!-- Deferred by the store (e.g. Ask to Buy): entitlement follows later. -->
    <template v-else-if="'pending' === state">
      <div
        class="mx-auto w-14 h-14 rounded-full bg-brand/10 dark:bg-brand/20 flex items-center justify-center"
        aria-hidden="true"
      >
        <Icon icon="mdi:clock-outline" class="w-8 h-8 text-brand" />
      </div>
      <h1 class="mt-4 text-xl font-bold txt-primary">
        {{ $t('onboarding.purchase.pendingTitle') }}
      </h1>
      <p class="text-sm txt-secondary mt-1.5" data-testid="text-purchase-pending">
        {{ $t('subscription.native.purchasePending') }}
      </p>
      <button
        class="mt-6 w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 active:scale-[0.98]"
        data-testid="btn-purchase-to-chat"
        @click="emit('done')"
      >
        {{ $t('onboarding.purchase.toChat') }}
      </button>
    </template>

    <!-- Cancelled / failed: retry or continue without buying ("later" —
         the purchase can be completed any time on the subscription page). -->
    <template v-else>
      <div
        class="mx-auto w-14 h-14 rounded-full bg-black/[0.04] dark:bg-white/[0.06] flex items-center justify-center"
        aria-hidden="true"
      >
        <Icon icon="mdi:cart-outline" class="w-8 h-8 txt-secondary" />
      </div>
      <h1 class="mt-4 text-xl font-bold txt-primary">
        {{ $t('onboarding.purchase.retryTitle') }}
      </h1>
      <p class="text-sm txt-secondary mt-1.5" data-testid="text-purchase-error" role="alert">
        {{ retryMessage }}
      </p>
      <div class="mt-6 space-y-2.5">
        <button
          class="w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 active:scale-[0.98]"
          data-testid="btn-purchase-retry"
          @click="run"
        >
          {{ $t('onboarding.purchase.retryCta') }}
        </button>
        <button
          class="w-full py-2.5 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98]"
          data-testid="btn-purchase-later"
          @click="emit('done')"
        >
          {{ $t('onboarding.purchase.later') }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), page 4: the terminal purchase step
 * of the auth-first flow. Only reachable authenticated.
 *
 * Order of operations (RevenueCat/Roku best practice for account-bound
 * subscriptions — catch the conflict BEFORE any money moves):
 *   1. Subscription pre-check: `GET /subscription/status`. An account that
 *      already has an ACTIVE subscription (any channel, any tier) never sees
 *      the store sheet — it gets the "already subscribed" screen with a path
 *      to the subscription page instead.
 *   2. Store purchase: `purchaseProduct()` runs authenticated, so the receipt
 *      is verified and finished immediately (no unlinked hold).
 *   3. Outcomes: granted → success toast, leave onboarding. Ask-to-Buy →
 *      pending screen. Cancelled/failed → retry + "later" (the purchase can
 *      be completed any time on the subscription page; nothing was charged).
 *
 * A failed pre-check also lands on retry — purchasing with an UNKNOWN account
 * state would reopen exactly the double-charge window this step closes.
 */
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import { initNativeIap, purchaseProduct } from '@/services/nativeIap'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{ productId: string }>()

const emit = defineEmits<{
  /** Purchase verified and granted — leave onboarding into the app. */
  purchased: []
  /** No (new) purchase: "later", pending approval, or already subscribed. */
  done: []
  /** Already subscribed — continue on the subscription page. */
  manage: []
}>()

const { t, te } = useI18n()
const { success } = useNotification()

type StepState = 'checking' | 'purchasing' | 'already' | 'pending' | 'retry'

const state = ref<StepState>('checking')
const retryMessage = ref('')

/** Tier the pre-check found, localized like the plans step when possible. */
const existingTier = ref('')
const existingPlanName = computed(() => {
  const key = `subscription.plans.${existingTier.value.toLowerCase()}`
  return te(key) ? t(key) : existingTier.value
})

/** Pre-check, then purchase. Also the retry entry point. */
async function run(): Promise<void> {
  state.value = 'checking'

  let alreadyActive: boolean
  try {
    const status = await subscriptionApi.getSubscriptionStatus()
    alreadyActive = status.active ?? status.hasSubscription
    existingTier.value = status.tier ?? status.plan ?? ''
  } catch {
    retryMessage.value = t('onboarding.purchase.checkFailed')
    state.value = 'retry'
    return
  }

  if (alreadyActive) {
    state.value = 'already'
    return
  }

  state.value = 'purchasing'
  // Idempotent: normally already initialized by the plans step; after an app
  // restart straight into this step it registers just the selected product.
  await initNativeIap([props.productId])
  const outcome = await purchaseProduct(props.productId)
  switch (outcome.status) {
    case 'granted':
      success(t('subscription.native.purchaseSuccess'))
      emit('purchased')
      break
    // `purchased_unlinked` cannot normally happen here (the step requires an
    // authenticated session) — if tokens vanished mid-flight, the pending
    // redemption mechanics cover it like any other deferred entitlement.
    case 'pending':
    case 'purchased_unlinked':
      state.value = 'pending'
      break
    case 'cancelled':
      retryMessage.value = t('onboarding.purchase.cancelledBody')
      state.value = 'retry'
      break
    case 'error':
      retryMessage.value = t(purchaseErrorKey(outcome.code))
      state.value = 'retry'
      break
  }
}

function purchaseErrorKey(code: string): string {
  if ('ownership_conflict' === code) return 'subscription.native.purchaseConflict'
  // `product_unknown` = the store catalogue did not load (e.g. store outage,
  // or a simulator run without the StoreKit test configuration) — that is a
  // "purchases unavailable" situation, not a failed charge.
  if ('not_available' === code || 'product_unknown' === code)
    return 'subscription.native.purchaseUnavailable'
  return 'subscription.native.purchaseFailed'
}

onMounted(run)
</script>
