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

    <div class="mt-5 space-y-2.5 text-left">
      <!-- Guest option first: value before sign-up (never force an account) -->
      <div
        class="p-3.5 rounded-2xl surface-card ring-1 ring-brand/30 dark:ring-brand/40 onb-enter-3"
        data-testid="card-guest"
      >
        <div class="flex items-start gap-3">
          <div
            class="w-9 h-9 rounded-xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center flex-shrink-0"
          >
            <Icon icon="mdi:message-flash-outline" class="w-5 h-5 text-brand" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold txt-primary">
              {{ $t('onboarding.plans.guestTitle') }}
            </p>
            <p class="text-xs txt-secondary mt-0.5">{{ $t('onboarding.plans.guestDesc') }}</p>
          </div>
        </div>
        <button
          class="mt-2.5 w-full py-2 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/20 active:scale-[0.98]"
          data-testid="btn-try-guest"
          @click="emit('guest')"
        >
          {{ $t('onboarding.plans.guestCta') }}
        </button>
      </div>

      <!-- Paid plans: a compact selector (2026 paywall pattern — one plan
           pre-selected with a "popular" badge, features of the SELECTED plan
           shown once below, single CTA). Keeps a constant height so the whole
           step fits the viewport. Hidden entirely when unavailable — fail-safe. -->
      <template v-if="plans.length > 0">
        <button
          v-for="(plan, index) in plans"
          :key="plan.id"
          class="w-full px-3.5 py-2 rounded-2xl surface-card ring-1 flex items-center justify-between gap-3 text-left transition-all duration-200 active:scale-[0.99]"
          :class="[
            `onb-enter-${4 + Math.min(index, 2)}`,
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
            <span class="text-sm font-bold txt-primary">€{{ plan.price }}</span>
            /{{ intervalLabel(plan.interval) }}
          </span>
        </button>

        <!-- What the selected plan includes -->
        <div
          class="px-3.5 py-2.5 rounded-2xl bg-brand/[0.04] dark:bg-brand/10 onb-enter-6"
          data-testid="section-plan-features"
        >
          <ul class="space-y-1">
            <li
              v-for="feature in selectedPlanFeatures"
              :key="feature"
              class="flex items-start gap-2"
            >
              <Icon
                icon="mdi:check-circle"
                class="w-3.5 h-3.5 text-green-500 flex-shrink-0 mt-0.5"
              />
              <span class="text-xs txt-secondary leading-snug">{{ feature }}</span>
            </li>
          </ul>
        </div>

        <button
          class="w-full py-2 rounded-xl btn-secondary font-semibold text-sm transition-all duration-200 active:scale-[0.98] onb-enter-6"
          data-testid="btn-plan-continue"
          @click="selectedPlanId && emit('select-plan', selectedPlanId)"
        >
          {{ $t('onboarding.plans.continueWith', { plan: selectedPlanName }) }}
        </button>
        <p class="text-[11px] txt-secondary text-center px-2 leading-snug onb-enter-6">
          {{ $t('onboarding.plans.signUpNote') }}
        </p>
      </template>
    </div>

    <div class="mt-5 space-y-1 onb-enter-6">
      <button
        class="w-full py-1.5 text-sm font-medium text-brand hover:underline underline-offset-2"
        data-testid="btn-create-account"
        @click="emit('register')"
      >
        {{ $t('onboarding.plans.registerCta') }}
      </button>
      <p class="text-sm txt-secondary py-1">
        {{ $t('onboarding.plans.loginHint') }}
        <button
          class="font-semibold text-brand hover:underline underline-offset-2"
          data-testid="btn-login"
          @click="emit('login')"
        >
          {{ $t('onboarding.plans.loginCta') }}
        </button>
      </p>
      <button
        class="w-full py-1.5 text-sm font-medium txt-secondary hover:txt-primary transition-colors"
        data-testid="btn-plans-back"
        @click="emit('back')"
      >
        {{ $t('onboarding.back') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), step 3: how to start.
 *
 * The guest chat is the primary, always-available option (2026 best practice:
 * experience value before creating an account; Apple/Google also forbid
 * walling the app behind a purchase). The paid plans are shown from the
 * public `GET /api/v1/subscription/plans` so an interested user can buy right
 * away — selecting one routes through register/login into the subscription
 * page, where the purchase uses the existing native IAP path (never the
 * Stripe web checkout inside the app).
 *
 * The catalogue request is deliberately NOT gated on the runtime-config
 * billing flag: that flag reads a cached config whose fetch has a 2s abort
 * timeout, so on a cold app start it can still be its default `false` while
 * billing is actually enabled — which silently hid the plans. The public
 * plans endpoint is authoritative itself: plans render only when it returns
 * them AND a purchase channel is configured (Stripe or IAP). On failure the
 * fetch retries once, then the step degrades to guest / sign-in / register.
 */
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { subscriptionApi, type SubscriptionPlan } from '@/services/api/subscriptionApi'

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
 * Selector state (2026 paywall pattern): the entry plan is pre-selected and
 * badged as popular, its benefits render once below the plan rows — constant
 * height, no per-card feature lists, single explicit CTA.
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
    return true
  } catch {
    return false
  }
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
