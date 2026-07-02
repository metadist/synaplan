<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex flex-col relative overflow-hidden"
    :style="{ paddingTop: 'calc(env(safe-area-inset-top, 0px) + 1rem)' }"
    data-testid="page-onboarding"
  >
    <!-- Ambient background (same family as the auth pages) -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute -top-24 left-1/4 w-[28rem] h-[28rem] bg-brand/6 dark:bg-brand/12 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute -bottom-24 right-1/4 w-[28rem] h-[28rem] bg-brand/4 dark:bg-brand/8 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>

    <!-- Top controls: language + skip -->
    <div class="relative z-20 flex items-center justify-between px-6 pt-2">
      <button
        class="h-9 px-3 rounded-lg icon-ghost text-xs font-medium"
        data-testid="btn-language-toggle"
        @click="cycleLanguage"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        class="h-9 px-3 rounded-lg icon-ghost text-sm font-medium txt-secondary"
        data-testid="btn-skip-onboarding"
        @click="skip"
      >
        {{ $t('onboarding.skip') }}
      </button>
    </div>

    <!-- Step content. Steps are designed to fit a phone viewport without
         scrolling; min-h-0 + overflow-y-auto is only the fail-safe so nothing
         can ever be clipped unreachable on very small screens (the root has
         overflow-hidden for the ambient blobs). m-auto centers when the
         content is shorter than the viewport. -->
    <div
      class="relative z-10 flex-1 min-h-0 overflow-y-auto flex flex-col px-6 py-4"
      :style="directionVars"
    >
      <div class="m-auto w-full flex flex-col items-center">
        <!-- CAUTION: every step component MUST render exactly ONE root
             element (no comments before/around the template root!) — a
             fragment root silently breaks mode="out-in": the next step is
             never inserted and the area goes blank (vuejs/core#6656).
             Slide direction comes from the CSS vars set on the container. -->
        <Transition name="onb-step" mode="out-in">
          <OnboardingWelcomeStep v-if="step === 1" key="welcome" @next="goTo(2)" />
          <OnboardingServerStep
            v-else-if="step === 2"
            key="server"
            @next="goTo(3)"
            @back="goTo(1)"
          />
          <OnboardingPlansStep
            v-else
            key="plans"
            @back="goTo(2)"
            @guest="finishAsGuest"
            @login="finishToLogin"
            @register="finishToRegister()"
            @select-plan="finishToRegister"
          />
        </Transition>
      </div>
    </div>

    <!-- Progress dots -->
    <div
      class="relative z-10 flex items-center justify-center gap-2 pb-4"
      :style="{ paddingBottom: 'calc(env(safe-area-inset-bottom, 0px) + 1.25rem)' }"
      data-testid="section-progress"
    >
      <button
        v-for="s in totalSteps"
        :key="s"
        class="onboarding-dot"
        :class="{ 'onboarding-dot--active': s === step }"
        :aria-label="$t('onboarding.stepLabel', { step: s, total: totalSteps })"
        :aria-current="s === step ? 'step' : undefined"
        :data-testid="`btn-step-${s}`"
        @click="goTo(s)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding): native-only first-run flow.
 *
 * Three steps, each skippable at any time (2026 onboarding best practice:
 * minimal friction before first value, no forced sign-up):
 *   1. Welcome — what the app can do (one screen, no feature tour)
 *   2. Server — default server preselected, expert affordance for own servers
 *   3. Start — guest chat (primary), plans (optional purchase), sign in
 *
 * The router guard only sends true first-run native users here; finishing or
 * skipping persists completion so the flow never shows again.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import OnboardingWelcomeStep from '@/components/onboarding/OnboardingWelcomeStep.vue'
import OnboardingServerStep from '@/components/onboarding/OnboardingServerStep.vue'
import OnboardingPlansStep from '@/components/onboarding/OnboardingPlansStep.vue'
import { markOnboardingCompleted, consumeOnboardingResumeStep } from '@/composables/useOnboarding'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'

const router = useRouter()
const { locale } = useI18n()

const totalSteps = 3

// A server switch in step 2 reloads the WebView; resume where the user was.
const step = ref(consumeOnboardingResumeStep() ?? 1)
const direction = ref<'forward' | 'back'>('forward')

/**
 * Slide direction for the (constant-name) step transition. Forward: the new
 * step slides in from the right while the old one leaves to the left; back is
 * mirrored. Kept as CSS custom properties because a dynamic Transition `name`
 * breaks `mode="out-in"` (see template comment).
 */
const directionVars = computed(() =>
  direction.value === 'back'
    ? { '--onb-enter-x': '-28px', '--onb-leave-x': '20px' }
    : { '--onb-enter-x': '28px', '--onb-leave-x': '-20px' }
)

const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
  const languages = ['de', 'en', 'es', 'tr']
  const currentIndex = languages.indexOf(locale.value)
  locale.value = languages[(currentIndex + 1) % languages.length]
  localStorage.setItem('language', locale.value)
}

function goTo(target: number) {
  if (target === step.value || target < 1 || target > totalSteps) {
    return
  }
  direction.value = target > step.value ? 'forward' : 'back'
  step.value = target
}

function skip() {
  markOnboardingCompleted()
  router.replace('/')
}

function finishAsGuest() {
  markOnboardingCompleted()
  router.replace('/')
}

function finishToLogin() {
  markOnboardingCompleted()
  router.replace({ name: 'login' })
}

/**
 * Register first, buy after: a selected plan is remembered as a pending
 * post-login redirect to the subscription page, where the purchase runs
 * through the existing native IAP path (never Stripe web checkout in the app).
 */
function finishToRegister(planId?: string) {
  markOnboardingCompleted()
  if (planId) {
    setPendingRedirect('/subscription')
    router.replace({ name: 'register', query: { redirect: '/subscription' } })
    return
  }
  router.replace({ name: 'register' })
}
</script>

<style scoped>
/* Step transition: horizontal slide + fade, same easing family as the auth
   enter animations (cubic-bezier(0.16, 1, 0.3, 1)). One constant name; the
   slide direction comes from --onb-enter-x / --onb-leave-x set inline. */
.onb-step-enter-active {
  transition:
    opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1),
    transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.onb-step-leave-active {
  transition:
    opacity 0.2s ease-in,
    transform 0.2s ease-in;
}
.onb-step-enter-from {
  opacity: 0;
  transform: translateX(var(--onb-enter-x, 28px));
}
.onb-step-leave-to {
  opacity: 0;
  transform: translateX(var(--onb-leave-x, -20px));
}

/* Progress dots: the active dot stretches into a pill. */
.onboarding-dot {
  height: 8px;
  width: 8px;
  border-radius: 9999px;
  background-color: color-mix(in srgb, var(--brand) 25%, transparent);
  transition:
    width 0.3s cubic-bezier(0.16, 1, 0.3, 1),
    background-color 0.3s ease;
}
.onboarding-dot--active {
  width: 24px;
  background-color: var(--brand);
}

/* Accessibility: neutralize all motion under the OS setting. */
@media (prefers-reduced-motion: reduce) {
  .onb-step-enter-active,
  .onb-step-leave-active,
  .onboarding-dot {
    transition: none;
  }
  .onb-step-enter-from,
  .onb-step-leave-to {
    transform: none;
  }
}
</style>
