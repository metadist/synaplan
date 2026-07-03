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
    <div class="relative z-30 flex items-center justify-between px-6 pt-2">
      <!-- Language picker: a dropdown so the user can jump straight to their
           language instead of cycling through all of them. -->
      <div ref="languageMenuRef" class="relative">
        <button
          class="h-9 pl-2.5 pr-2 rounded-lg surface-card ring-1 ring-black/[0.06] dark:ring-white/[0.1] shadow-sm txt-primary text-sm font-medium inline-flex items-center gap-1.5 transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.05]"
          data-testid="btn-language-toggle"
          :aria-expanded="languageMenuOpen"
          aria-haspopup="listbox"
          @click="languageMenuOpen = !languageMenuOpen"
        >
          <span aria-hidden="true">{{ currentLanguageOption.flag }}</span>
          <span>{{ currentLanguageOption.label }}</span>
          <Icon
            :icon="languageMenuOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'"
            class="w-4 h-4 txt-secondary"
            aria-hidden="true"
          />
        </button>
        <Transition name="lang-menu">
          <ul
            v-if="languageMenuOpen"
            class="absolute left-0 mt-2 w-44 rounded-xl surface-elevated ring-1 ring-black/[0.06] dark:ring-white/[0.1] shadow-lg overflow-hidden py-1"
            role="listbox"
            data-testid="menu-language"
          >
            <li v-for="lang in languages" :key="lang.value">
              <button
                class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-left transition-colors hover:bg-black/[0.04] dark:hover:bg-white/[0.06]"
                :class="
                  lang.value === currentLanguage ? 'txt-primary font-semibold' : 'txt-secondary'
                "
                role="option"
                :aria-selected="lang.value === currentLanguage"
                :data-testid="`btn-language-${lang.value}`"
                @click="selectLanguage(lang.value)"
              >
                <span class="text-base" aria-hidden="true">{{ lang.flag }}</span>
                <span class="flex-1">{{ lang.label }}</span>
                <Icon
                  v-if="lang.value === currentLanguage"
                  icon="mdi:check"
                  class="w-4 h-4 text-brand"
                  aria-hidden="true"
                />
              </button>
            </li>
          </ul>
        </Transition>
      </div>
      <button
        class="h-9 px-3 rounded-lg surface-card ring-1 ring-black/[0.06] dark:ring-white/[0.1] shadow-sm text-sm font-medium txt-primary transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.05]"
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import OnboardingWelcomeStep from '@/components/onboarding/OnboardingWelcomeStep.vue'
import OnboardingServerStep from '@/components/onboarding/OnboardingServerStep.vue'
import OnboardingPlansStep from '@/components/onboarding/OnboardingPlansStep.vue'
import { markOnboardingCompleted, consumeOnboardingResumeStep } from '@/composables/useOnboarding'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'

const router = useRouter()
const { locale } = useI18n()

const totalSteps = 3

const languages = [
  { value: 'de', label: 'Deutsch', flag: '🇩🇪' },
  { value: 'en', label: 'English', flag: '🇬🇧' },
  { value: 'es', label: 'Español', flag: '🇪🇸' },
  { value: 'tr', label: 'Türkçe', flag: '🇹🇷' },
]

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
const currentLanguageOption = computed(
  () => languages.find((lang) => lang.value === locale.value) ?? languages[1]
)

const languageMenuOpen = ref(false)
const languageMenuRef = ref<HTMLElement | null>(null)

const selectLanguage = (value: string) => {
  locale.value = value
  localStorage.setItem('language', value)
  languageMenuOpen.value = false
}

const handleClickOutsideLanguageMenu = (event: MouseEvent) => {
  if (!languageMenuOpen.value) return
  if (languageMenuRef.value && !languageMenuRef.value.contains(event.target as Node)) {
    languageMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutsideLanguageMenu)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutsideLanguageMenu)
})

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

/* Language dropdown menu: subtle scale + fade. */
.lang-menu-enter-active {
  transition:
    opacity 0.18s ease-out,
    transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
.lang-menu-leave-active {
  transition:
    opacity 0.12s ease-in,
    transform 0.12s ease-in;
}
.lang-menu-enter-from,
.lang-menu-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.98);
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
  .onboarding-dot,
  .lang-menu-enter-active,
  .lang-menu-leave-active {
    transition: none;
  }
  .onb-step-enter-from,
  .onb-step-leave-to {
    transform: none;
  }
}
</style>
