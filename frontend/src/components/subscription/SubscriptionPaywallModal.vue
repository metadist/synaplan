<template>
  <Teleport to="body">
    <Transition name="paywall-fade">
      <div
        v-if="isOpen"
        data-testid="subscription-paywall"
        class="fixed inset-0 z-[110] flex justify-center"
        :class="isNative ? 'items-stretch' : 'items-center p-4'"
        role="dialog"
        aria-modal="true"
        aria-labelledby="paywall-title"
        aria-describedby="paywall-subtitle"
      >
        <div
          v-if="!isNative"
          class="absolute inset-0 bg-black/60 backdrop-blur-sm"
          data-testid="paywall-backdrop"
          @click="close"
        />

        <div
          class="relative w-full flex flex-col animate-paywall-enter"
          :class="
            isNative
              ? 'bg-app h-full paywall-native-insets'
              : 'surface-card rounded-2xl shadow-2xl max-w-5xl max-h-[90vh] overflow-hidden'
          "
        >
          <!-- Absolute so it stays put while plans scroll, without a full-width
               header bar that would paint over the scroll content (Apple 3.1.2). -->
          <button
            ref="closeButtonRef"
            class="paywall-close absolute top-3 right-3 z-10 w-9 h-9 rounded-xl txt-primary flex items-center justify-center hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
            data-testid="paywall-close"
            :aria-label="$t('common.close')"
            @click="close"
          >
            <Icon icon="mdi:close" class="w-5 h-5" />
          </button>

          <div
            class="paywall-scroll flex-1 overflow-y-auto scroll-thin px-5 md:px-8 pt-8 pb-8 md:pb-10"
          >
            <!-- Header -->
            <div class="text-center max-w-2xl mx-auto mb-8">
              <div
                class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center bg-[var(--brand)]/10"
              >
                <Icon icon="mdi:rocket-launch-outline" class="w-7 h-7 text-[var(--brand)]" />
              </div>
              <h2 id="paywall-title" class="text-2xl md:text-3xl font-bold txt-primary mb-2">
                {{ $t(`paywall.title.${reason}`, { brand: config.branding.name }) }}
              </h2>
              <p id="paywall-subtitle" class="txt-secondary text-sm md:text-base">
                {{ $t(`paywall.subtitle.${reason}`) }}
              </p>
            </div>

            <!-- Loading (also before the request starts, so the empty state
                 below can never flash while the catalogue is still on its way) -->
            <div
              v-if="loadingPlans || !catalogueSettled"
              class="py-12 text-center"
              data-testid="paywall-loading"
            >
              <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
            </div>

            <!-- Plans -->
            <div v-else-if="offeredPlans.length > 0" class="grid gap-5" :class="planGridClass">
              <article
                v-for="plan in offeredPlans"
                :key="plan.id"
                class="relative rounded-2xl border flex flex-col overflow-hidden"
                :class="plan.id === 'TEAM' ? 'border-2' : ''"
                :style="cardStyle(plan.id)"
                data-testid="paywall-plan-card"
                :data-plan-id="plan.id"
              >
                <div
                  v-if="plan.id === 'TEAM'"
                  class="px-3 py-1 text-xs font-bold uppercase tracking-wide text-center"
                  :style="accentBannerStyle(plan.id)"
                  data-testid="paywall-recommended"
                >
                  {{ $t('paywall.recommended') }}
                </div>

                <div class="p-6 flex flex-col flex-1">
                  <h3 class="text-xl font-bold mb-1" :style="{ color: planInk(plan.id) }">
                    {{ planName(plan) }}
                  </h3>
                  <p v-if="taglineFor(plan)" class="text-xs txt-secondary mb-4">
                    {{ taglineFor(plan) }}
                  </p>

                  <div class="flex items-baseline gap-1 mb-5">
                    <span class="text-3xl font-bold txt-primary">{{ displayPrice(plan) }}</span>
                    <span class="txt-secondary text-sm"
                      >/{{ $t(`subscription.per${capitalize(plan.interval)}`) }}</span
                    >
                  </div>

                  <ul class="space-y-2.5 mb-6 flex-1">
                    <li
                      v-for="(benefit, index) in planBenefits(plan)"
                      :key="index"
                      class="flex items-start gap-2.5 text-sm txt-secondary"
                    >
                      <Icon
                        icon="mdi:check-circle"
                        class="w-4.5 h-4.5 flex-shrink-0 mt-0.5"
                        :style="{ color: planAccent(plan.id) }"
                      />
                      <span>{{ benefit }}</span>
                    </li>
                  </ul>

                  <button
                    class="w-full py-3 rounded-xl font-semibold text-sm transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed"
                    :style="ctaStyle(plan.id)"
                    :disabled="isProcessing"
                    :data-testid="`paywall-select-${plan.id.toLowerCase()}`"
                    @click="choosePlan(plan.id)"
                  >
                    {{
                      isProcessing
                        ? $t('subscription.processing')
                        : $t('paywall.choosePlan', { plan: planName(plan) })
                    }}
                  </button>
                </div>
              </article>
            </div>

            <!-- Nothing purchasable: the parent is told via `unavailable` and
                 normally swaps in its own modal, so this is only the safety net
                 for a surface that ignores the event. -->
            <p
              v-else
              class="py-10 text-center text-sm txt-secondary"
              data-testid="paywall-unavailable"
            >
              {{ $t('paywall.unavailable') }}
            </p>

            <!-- Footer -->
            <div class="max-w-2xl mx-auto mt-8 space-y-4 text-center">
              <p v-if="isGuest" class="text-sm txt-secondary" data-testid="paywall-guest-note">
                {{ $t('paywall.guestNote') }}
              </p>

              <div class="flex flex-col gap-2">
                <button
                  v-if="isGuest"
                  class="w-full md:w-auto md:mx-auto md:px-8 py-3 rounded-xl btn-secondary text-sm font-medium"
                  data-testid="paywall-login"
                  @click="goToLogin"
                >
                  {{ $t('paywall.login') }}
                </button>

                <button
                  v-if="isNative"
                  class="text-sm txt-secondary hover:txt-primary transition-colors py-1"
                  data-testid="paywall-restore"
                  :disabled="isProcessing"
                  @click="restorePurchases"
                >
                  {{ $t('subscription.native.restoreButton') }}
                </button>

                <button
                  class="text-sm txt-secondary hover:txt-primary transition-colors py-1"
                  data-testid="paywall-dismiss"
                  @click="close"
                >
                  {{ $t('paywall.maybeLater') }}
                </button>
              </div>

              <p class="text-xs txt-tertiary leading-relaxed">
                {{ isNative ? $t('paywall.renewalNoteStore') : $t('paywall.renewalNoteWeb') }}
              </p>

              <p class="text-xs txt-tertiary">
                <a
                  :href="config.branding.termsUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-brand hover:underline underline-offset-2"
                  >{{ $t('auth.termsOfService') }}</a
                >
                <span class="mx-2">·</span>
                <a
                  :href="config.branding.privacyUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-brand hover:underline underline-offset-2"
                  >{{ $t('auth.privacyPolicy') }}</a
                >
              </p>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { SubscriptionPlan } from '@/services/api/subscriptionApi'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { useSubscriptionPurchase } from '@/composables/useSubscriptionPurchase'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'
import type { PaywallReason } from '@/composables/usePaywallPrompt'

/**
 * Full-screen (app) / centered (web) upgrade sheet shown when a trial or a
 * monthly quota runs out, and as a periodic reminder.
 *
 * MOBILE-APP SEAM: in the native shell this is a store-facing purchase surface.
 * Prices come from the store catalogue, purchases go through IAP with
 * server-side verification, "Restore Purchases" is reachable here (Apple 3.1.1),
 * and there is never a link to a web checkout (anti-steering). The X and the
 * dismiss button always leave the app usable (Apple 3.1.2).
 */
const props = defineProps<{
  isOpen: boolean
  reason: PaywallReason
}>()

const emit = defineEmits<{
  close: []
  /**
   * The catalogue settled with nothing this user can buy here (request failed,
   * every tier above them deactivated). The caller — which opened the paywall
   * instead of an informational modal — can fall back to that modal rather than
   * leaving a blocked user in front of an empty sheet.
   */
  unavailable: []
}>()

const { t, te } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const config = useConfigStore()

const {
  isNative,
  plans,
  loadingPlans,
  isProcessing,
  currentLevel,
  loadPlans,
  displayPrice,
  planName,
  planBenefits,
  isCurrentPlan,
  isLowerPlan,
  selectPlan,
  restorePurchases,
} = useSubscriptionPurchase()

const closeButtonRef = ref<HTMLButtonElement | null>(null)
let previouslyFocused: HTMLElement | null = null

/** False until the plan request has come back once — see the loading branch. */
const catalogueSettled = ref(false)

const isGuest = computed(() => !authStore.isAuthenticated)

/**
 * Only tiers that are an actual step up. A user already on a plan never sees
 * their own or a cheaper one, so the sheet always offers something to gain.
 */
const offeredPlans = computed(() =>
  plans.value.filter((plan) => !isCurrentPlan(plan.id) && !isLowerPlan(plan.id))
)

/**
 * Static Tailwind classes (the JIT compiler cannot see an interpolated
 * `md:grid-cols-${n}`), so the column count is picked from a fixed map.
 */
const planGridClass = computed(() => {
  if (offeredPlans.value.length >= 3) return 'md:grid-cols-3'
  if (2 === offeredPlans.value.length) return 'md:grid-cols-2'
  return 'max-w-md mx-auto'
})

/**
 * The tagline falls back to nothing for a tier this build has no copy for —
 * `t()` would otherwise print the raw key path, e.g.
 * `paywall.plans.studio.tagline`, right into the card.
 */
function taglineFor(plan: SubscriptionPlan): string {
  const key = `paywall.plans.${plan.id.toLowerCase()}.tagline`
  return te(key) ? t(key) : ''
}

function planToken(planId: string, suffix: string): string {
  return `var(--plan-${planId.toLowerCase()}-${suffix}, var(--brand))`
}

function planAccent(planId: string): string {
  return planToken(planId, 'accent')
}

function planInk(planId: string): string {
  return planToken(planId, 'ink')
}

function cardStyle(planId: string): Record<string, string> {
  return {
    background: `var(--plan-${planId.toLowerCase()}-soft, var(--bg-card))`,
    borderColor: planAccent(planId),
  }
}

function accentBannerStyle(planId: string): Record<string, string> {
  return { background: planAccent(planId), color: 'var(--plan-on-accent)' }
}

function ctaStyle(planId: string): Record<string, string> {
  return { background: planAccent(planId), color: 'var(--plan-on-accent)' }
}

function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1)
}

/**
 * A guest has no account to attach an entitlement to, so the purchase starts
 * after sign-up: the picked tier travels in the pending redirect and the
 * subscription page resumes it. Signed-in users buy right here.
 */
async function choosePlan(planId: string): Promise<void> {
  if (isGuest.value) {
    setPendingRedirect(`/subscription?plan=${planId}`)
    close()
    await router.push({ path: '/register', query: { redirect: `/subscription?plan=${planId}` } })
    return
  }
  await selectPlan(planId)
}

async function goToLogin(): Promise<void> {
  close()
  await router.push('/login')
}

function close(): void {
  emit('close')
}

function handleKeydown(event: KeyboardEvent): void {
  if ('Escape' === event.key && props.isOpen) close()
}

onMounted(() => document.addEventListener('keydown', handleKeydown))
onUnmounted(() => document.removeEventListener('keydown', handleKeydown))

/**
 * Load the catalogue on open and move focus into the sheet, so a keyboard or
 * screen-reader user starts at the dismiss control instead of somewhere on the
 * chat behind the overlay. Focus goes back where it came from on close.
 */
async function onOpen(): Promise<void> {
  previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
  await nextTick()
  closeButtonRef.value?.focus()

  if (0 === plans.value.length) await loadPlans()
  catalogueSettled.value = true
  if (props.isOpen && 0 === offeredPlans.value.length) emit('unavailable')
}

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      void onOpen()
      return
    }
    previouslyFocused?.focus()
    previouslyFocused = null
  },
  { immediate: true }
)

/**
 * A completed purchase (or a restore that recovered one) changes the tier, and
 * the user came here from a blocked chat — so the sheet gets out of the way
 * instead of advertising the next tier up on top of it.
 */
watch(currentLevel, (level, previous) => {
  if (props.isOpen && level !== previous) close()
})
</script>

<style scoped>
/* Safe-area lives on the floating close button and the scroll padding, not on
   a full-width header strip — otherwise that strip paints a solid bar across
   the top. Declared as a class rather than an inline style because Safari drops
   `env()` when it is assigned through the CSSOM. */
.paywall-native-insets {
  padding-bottom: env(safe-area-inset-bottom, 0px);
}
.paywall-native-insets .paywall-close {
  top: calc(env(safe-area-inset-top, 0px) + 0.75rem);
}
.paywall-native-insets .paywall-scroll {
  padding-top: calc(env(safe-area-inset-top, 0px) + 2.5rem);
}

.paywall-fade-enter-active,
.paywall-fade-leave-active {
  transition: opacity 0.25s ease;
}
.paywall-fade-enter-from,
.paywall-fade-leave-to {
  opacity: 0;
}

@keyframes paywall-enter {
  from {
    opacity: 0;
    transform: translateY(16px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
.animate-paywall-enter {
  animation: paywall-enter 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Accessibility: neutralize all motion under the OS setting. */
@media (prefers-reduced-motion: reduce) {
  .paywall-fade-enter-active,
  .paywall-fade-leave-active {
    transition: none;
  }
  .animate-paywall-enter {
    animation: none;
  }
}
</style>
