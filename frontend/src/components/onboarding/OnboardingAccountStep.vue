<!-- NOTE: no comments before the root element — a comment node at template
     root breaks the parent <Transition mode="out-in"> (vuejs/core#6656). -->
<template>
  <div class="w-full max-w-sm text-center" data-testid="section-onboarding-account">
    <div
      class="mx-auto w-14 h-14 rounded-full bg-green-500/10 dark:bg-green-400/15 flex items-center justify-center onb-enter-1"
      aria-hidden="true"
    >
      <Icon icon="mdi:check-decagram" class="w-8 h-8 text-green-500 dark:text-green-400" />
    </div>

    <h1 class="mt-4 text-xl font-bold txt-primary onb-enter-2">
      {{ $t('onboarding.account.title') }}
    </h1>
    <p class="text-sm txt-secondary mt-1.5 onb-enter-2">
      {{ $t('onboarding.account.subtitle') }}
    </p>

    <div class="mt-6 space-y-2.5 text-left onb-enter-3">
      <!-- Providers first: one tap, verified e-mail, no verification detour.
           Apple leads on iOS (it is the native system sheet there). -->
      <button
        v-for="provider in orderedProviders"
        :key="provider.id"
        class="w-full py-3 rounded-xl surface-card ring-1 ring-black/[0.06] dark:ring-white/[0.1] font-semibold text-sm txt-primary inline-flex items-center justify-center gap-2 transition-all duration-200 hover:bg-black/[0.03] dark:hover:bg-white/[0.05] active:scale-[0.98] disabled:opacity-60 disabled:pointer-events-none"
        :data-testid="`btn-account-${provider.id}`"
        :disabled="busy"
        @click="continueWith(provider.id)"
      >
        <Icon :icon="providerIcon(provider)" class="w-4.5 h-4.5" aria-hidden="true" />
        {{ $t('onboarding.account.providerCta', { provider: provider.name }) }}
      </button>

      <button
        class="w-full py-3 rounded-xl btn-secondary font-medium text-sm transition-all duration-200 active:scale-[0.98] disabled:opacity-60"
        data-testid="btn-account-email"
        :disabled="busy"
        @click="emit('register')"
      >
        {{ $t('onboarding.account.emailCta') }}
      </button>
    </div>

    <p
      v-if="error"
      class="mt-3 text-xs text-red-500 dark:text-red-400"
      data-testid="text-account-error"
      role="alert"
    >
      {{ error }}
    </p>

    <p class="mt-5 text-sm txt-secondary onb-enter-4">
      {{ $t('onboarding.plans.loginHint') }}
      <button
        class="font-semibold text-brand hover:underline underline-offset-2"
        data-testid="btn-account-login"
        @click="emit('login')"
      >
        {{ $t('onboarding.plans.loginCta') }}
      </button>
    </p>

    <p class="mt-4 text-xs txt-secondary onb-enter-4">
      {{ $t('onboarding.account.note') }}
    </p>
  </div>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (first-run onboarding), page 3: create the account AFTER the
 * store purchase (purchase-first flow). The payment already succeeded against
 * the Apple ID / Google account; this step links it to a Synaplan account:
 *
 * - Social providers lead (Sign in with Apple first on iOS: one tap, verified
 *   e-mail, no verification detour). A successful in-place sign-in emits
 *   `authenticated`; the pending purchase is redeemed by the central post-auth
 *   hook (`redeemPendingIapPurchaseAfterAuth`).
 * - E-mail registration routes to `/register`; the redemption then happens on
 *   the first login (plugin re-delivery, post-auth hook).
 *
 * There is deliberately no skip here: an unredeemed purchase would strand the
 * payment (Android auto-refunds unacknowledged purchases after ~3 days). The
 * guest-chat reminder banner covers users who background the app anyway.
 */
import { computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { getNativePlatform } from '@/services/api/nativeRuntime'
import { useSocialAuth, type SocialProvider } from '@/composables/useSocialAuth'

const emit = defineEmits<{
  /** Session established in place (native provider flow) — leave onboarding. */
  authenticated: []
  /** E-mail path: continue on the register page. */
  register: []
  /** Existing account: continue on the login page. */
  login: []
}>()

const { providers, loadProviders, signInWith, error, busy } = useSocialAuth()

onMounted(() => {
  void loadProviders()
})

/** Apple first on iOS (native sheet, Guideline 4.8); server order otherwise. */
const orderedProviders = computed(() => {
  if ('ios' !== getNativePlatform()) return providers.value
  return [...providers.value].sort((a, b) => Number('apple' === b.id) - Number('apple' === a.id))
})

const PROVIDER_ICONS: Record<string, string> = {
  apple: 'mdi:apple',
  google: 'mdi:google',
  github: 'mdi:github',
  keycloak: 'mdi:shield-account',
}

function providerIcon(provider: SocialProvider): string {
  return PROVIDER_ICONS[provider.id] ?? 'mdi:login'
}

async function continueWith(providerId: string): Promise<void> {
  if (await signInWith(providerId)) {
    emit('authenticated')
  }
}
</script>

<style scoped>
/* Staggered enter (same family as the other onboarding steps). */
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

@media (prefers-reduced-motion: reduce) {
  .onb-enter-1,
  .onb-enter-2,
  .onb-enter-3,
  .onb-enter-4 {
    animation: none;
  }
}
</style>
