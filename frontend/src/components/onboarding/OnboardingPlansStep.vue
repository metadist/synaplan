<!-- NOTE: no comments before the root element — a comment node at template
     root breaks the parent <Transition mode="out-in"> (vuejs/core#6656). -->
<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-plans">
    <h1 class="text-xl font-bold txt-primary onb-enter-1">
      {{ $t('onboarding.plans.title') }}
    </h1>
    <p class="text-sm txt-secondary mt-1.5 onb-enter-2">
      {{ $t('onboarding.plans.subtitle') }}
    </p>

    <!-- Paid plans in focus (compact selector): the tiers are selectable rows,
         one is pre-selected + badged, the SELECTED plan's benefits render once
         below, and a single primary CTA continues. Hidden when no purchase
         channel is configured (fail-safe) — the guest / sign-in path remains. -->
    <template v-if="plans.length > 0">
      <div class="mt-5 space-y-2 text-left">
        <button
          v-for="(plan, index) in plans"
          :key="plan.id"
          class="w-full px-3.5 py-2 rounded-2xl surface-card ring-1 flex items-center justify-between gap-3 text-left transition-all duration-200 active:scale-[0.99]"
          :class="[
            `onb-enter-${3 + Math.min(index, 2)}`,
            selectedPlanId === plan.id
              ? 'ring-2 ring-brand'
              : 'ring-black/[0.04] dark:ring-white/[0.05]',
          ]"
          :data-testid="`btn-plan-${plan.id.toLowerCase()}`"
          :aria-pressed="selectedPlanId === plan.id"
          @click="selectedPlanId = plan.id"
        >
          <span class="min-w-0 flex items-center gap-2">
            <span
              class="w-4 h-4 rounded-full border-2 flex-shrink-0 flex items-center justify-center"
              :class="
                selectedPlanId === plan.id
                  ? 'border-brand bg-brand'
                  : 'border-black/20 dark:border-white/25'
              "
            >
              <Icon v-if="selectedPlanId === plan.id" icon="mdi:check" class="w-3 h-3 text-white" />
            </span>
            <span class="text-sm font-semibold txt-primary">{{ planName(plan) }}</span>
            <span
              v-if="plan.id === popularPlanId"
              class="text-[10px] font-semibold uppercase tracking-wide text-brand bg-brand/10 dark:bg-brand/20 px-1.5 py-0.5 rounded-md flex-shrink-0"
            >
              {{ $t('onboarding.plans.popular') }}
            </span>
          </span>
          <span class="text-xs txt-secondary whitespace-nowrap flex-shrink-0">
            <span class="text-sm font-bold txt-primary">{{ displayPrice(plan) }}</span>
            /{{ intervalLabel(plan.interval) }}
          </span>
        </button>
      </div>

      <!-- What the selected plan includes -->
      <div
        class="mt-2.5 px-3.5 py-2.5 rounded-2xl bg-brand/[0.04] dark:bg-brand/10 text-left onb-enter-6"
        data-testid="section-plan-features"
      >
        <ul class="space-y-1">
          <li v-for="feature in selectedPlanFeatures" :key="feature" class="flex items-start gap-2">
            <Icon icon="mdi:check-circle" class="w-3.5 h-3.5 text-green-500 flex-shrink-0 mt-0.5" />
            <span class="text-xs txt-secondary leading-snug">{{ feature }}</span>
          </li>
        </ul>
      </div>

      <button
        class="mt-4 w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98] onb-enter-6"
        data-testid="btn-plan-continue"
        @click="selectedPlanId && emit('select-plan', selectedPlanId)"
      >
        {{ $t('onboarding.plans.continueWith', { plan: selectedPlanName }) }}
      </button>

      <!-- Quiet secondary actions: never wall the app behind a purchase. -->
      <div class="mt-4 flex flex-col items-center gap-1.5 onb-enter-6">
        <button
          class="text-sm font-medium txt-secondary hover:txt-primary transition-colors"
          data-testid="btn-try-guest"
          @click="emit('guest')"
        >
          {{ $t('onboarding.plans.guestCta') }}
        </button>
        <p class="text-sm txt-secondary">
          {{ $t('onboarding.plans.loginHint') }}
          <button
            class="font-semibold text-brand hover:underline underline-offset-2"
            data-testid="btn-login"
            @click="emit('login')"
          >
            {{ $t('onboarding.plans.loginCta') }}
          </button>
        </p>
      </div>
    </template>

    <!-- Fallback (no purchase channel configured): guest-first, still compact. -->
    <template v-else>
      <div class="mt-6 space-y-2.5 text-left onb-enter-3">
        <button
          class="w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98]"
          data-testid="btn-try-guest"
          @click="emit('guest')"
        >
          {{ $t('onboarding.plans.guestCta') }}
        </button>
        <button
          class="w-full py-2.5 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98]"
          data-testid="btn-create-account"
          @click="emit('register')"
        >
          {{ $t('onboarding.plans.registerCta') }}
        </button>
        <p class="text-sm txt-secondary text-center pt-1">
          {{ $t('onboarding.plans.loginHint') }}
          <button
            class="font-semibold text-brand hover:underline underline-offset-2"
            data-testid="btn-login"
            @click="emit('login')"
          >
            {{ $t('onboarding.plans.loginCta') }}
          </button>
        </p>
      </div>
    </template>

    <button
      class="mt-5 w-full py-1.5 text-sm font-medium txt-secondary hover:txt-primary transition-colors onb-enter-6"
      data-testid="btn-plans-back"
      @click="emit('back')"
    >
      {{ $t('onboarding.back') }}
    </button>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), page 2: choose a plan.
 *
 * The paid plans lead (compact selector: one tier pre-selected + badged, the
 * selected tier's benefits shown once, a single "continue with" CTA). The guest
 * chat and sign-in stay available as quiet secondary actions — the app is never
 * walled behind a purchase (Apple/Google policy and onboarding best practice).
 * Selecting a plan routes through register/login into the subscription page,
 * where the purchase uses the native IAP path (never Stripe web checkout in the
 * app).
 *
 * The catalogue request is deliberately NOT gated on the runtime-config billing
 * flag: that flag reads a cached config whose fetch has a 2s abort timeout, so
 * on a cold app start it can still be its default `false` while billing is
 * actually enabled. The public plans endpoint is authoritative itself: plans
 * render only when it returns them AND a purchase channel is configured (Stripe
 * or IAP). On failure the fetch retries once, then the step degrades to guest /
 * sign-in / register.
 */
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { subscriptionApi, type SubscriptionPlan } from '@/services/api/subscriptionApi'
import { formatPlanPrice } from '@/utils/formatPrice'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { getStorePrice, initNativeIap, isNativeIapAvailable } from '@/services/nativeIap'

const emit = defineEmits<{
  back: []
  guest: []
  login: []
  register: []
  'select-plan': [planId: string]
}>()

const { t, te } = useI18n()

const plans = ref<SubscriptionPlan[]>([])

/**
 * Selector state (compact paywall pattern): the entry plan is pre-selected and
 * badged as popular, its benefits render once below the plan rows.
 */
const selectedPlanId = ref<string | null>(null)
const popularPlanId = computed(() => plans.value[0]?.id ?? null)

const selectedPlan = computed(
  () => plans.value.find((plan) => plan.id === selectedPlanId.value) ?? null
)
const selectedPlanName = computed(() => (selectedPlan.value ? planName(selectedPlan.value) : ''))

/** Max 4 bullets so the step always fits a phone viewport without scrolling. */
const selectedPlanFeatures = computed(() => selectedPlan.value?.features.slice(0, 4) ?? [])

function planName(plan: SubscriptionPlan): string {
  const key = `subscription.plans.${plan.id.toLowerCase()}`
  return te(key) ? t(key) : plan.name
}

/** Set once the native store catalogue is loaded (native shell only). */
const storePricesReady = ref(false)

/**
 * Price shown to the user, channel-aware:
 * - Native app: the store's own localized price wins (that is what Apple/Google
 *   actually charge); until the catalogue is loaded, `appPrice` (web price plus
 *   the store-commission markup) is the fallback so the app never advertises
 *   the cheaper web price (anti-steering).
 * - Web: always the plain server-configured `price`.
 */
function displayPrice(plan: SubscriptionPlan): string {
  if (isNativeApp()) {
    if (storePricesReady.value) {
      const storePrice = getStorePrice(plan.iapProductId)
      if (storePrice) return storePrice
    }
    return formatPlanPrice(plan.appPrice, plan.currency)
  }
  return formatPlanPrice(plan.price, plan.currency)
}

function intervalLabel(interval: string): string {
  const key = `subscription.per${interval.charAt(0).toUpperCase()}${interval.slice(1)}`
  return te(key) ? t(key) : interval
}

async function loadPlans(): Promise<boolean> {
  try {
    const response = await subscriptionApi.getPlans()
    // Only offer plans that can actually be bought somewhere (a server with
    // billing disabled reports no configured purchase channel).
    if (!response.stripeConfigured && !response.iapConfigured) {
      plans.value = []
      return true
    }
    plans.value = response.plans
    selectedPlanId.value = response.plans[0]?.id ?? null
    void loadStorePrices(response.plans)
    return true
  } catch {
    return false
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

onMounted(async () => {
  if (await loadPlans()) {
    return
  }
  // One retry shortly after a failed cold-start request (e.g. the WebView's
  // first TLS handshake timing out); after that the step degrades fail-safe
  // to guest / sign-in / register.
  await new Promise((resolve) => setTimeout(resolve, 1500))
  await loadPlans()
})
</script>

<style scoped>
/* Staggered enter (same family as the auth pages). */
@keyframes onbEnter {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.onb-enter-1 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.05s both;
}
.onb-enter-2 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.12s both;
}
.onb-enter-3 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.18s both;
}
.onb-enter-4 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.24s both;
}
.onb-enter-5 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both;
}
.onb-enter-6 {
  animation: onbEnter 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.36s both;
}

@media (prefers-reduced-motion: reduce) {
  .onb-enter-1,
  .onb-enter-2,
  .onb-enter-3,
  .onb-enter-4,
  .onb-enter-5,
  .onb-enter-6 {
    animation: none;
  }
}
</style>
