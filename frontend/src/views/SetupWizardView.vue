<template>
  <div
    class="min-h-dvh bg-light-bg dark:bg-dark-bg flex flex-col relative overflow-y-auto overflow-x-hidden px-4 py-6"
    :style="{
      paddingTop: 'calc(env(safe-area-inset-top, 0px) + 3.5rem)',
      paddingBottom:
        'calc(env(safe-area-inset-bottom, 0px) + var(--keyboard-inset-height, 0px) + 1.5rem)',
    }"
    data-testid="page-setup"
  >
    <div
      class="absolute right-4 flex items-center gap-2 z-20"
      style="top: calc(env(safe-area-inset-top, 0px) + 1.25rem)"
    >
      <!--
        MOBILE-APP SEAM (server switching): the emergency exit. Without it an app
        pointed at a fresh server would be stuck in that server's wizard with no
        way back to a working one. Web builds have the address bar for this.
      -->
      <button
        v-if="serverSwitchAvailable"
        type="button"
        class="h-9 w-9 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="$t('setup.switchServer')"
        :title="$t('setup.switchServer')"
        data-testid="setup-switch-server"
        @click="openNativeServerOverlay()"
      >
        <ServerIcon class="w-4 h-4" />
      </button>
      <!--
        The wizard is the first screen an installation ever shows, so the
        language has to be reachable before anything is filled in — there is no
        account yet whose profile could carry the preference.
      -->
      <button
        type="button"
        class="h-9 px-3 rounded-lg icon-ghost text-xs font-medium"
        :aria-label="$t('setup.switchLanguage')"
        :title="$t('setup.switchLanguage')"
        data-testid="setup-switch-language"
        @click="cycleLanguage"
      >
        {{ locale.toUpperCase() }}
      </button>
      <button
        type="button"
        class="h-9 w-9 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="$t('setup.toggleTheme')"
        :title="$t('setup.toggleTheme')"
        data-testid="setup-toggle-theme"
        @click="toggleTheme"
      >
        <SunIcon v-if="isDark" class="w-4 h-4" />
        <MoonIcon v-else class="w-4 h-4" />
      </button>
    </div>

    <div class="relative z-10 m-auto w-full max-w-md flex flex-col gap-5">
      <header class="text-center">
        <div class="w-10 h-[3px] bg-brand rounded-full mx-auto mb-4"></div>
        <h1 class="text-2xl font-bold txt-primary">
          {{ $t('setup.title', { brand: brandName }) }}
        </h1>
        <p v-if="subtitle" class="text-sm txt-secondary mt-1.5" data-testid="setup-subtitle">
          {{ subtitle }}
        </p>
      </header>

      <ol
        v-if="'done' !== step"
        class="flex items-center gap-2"
        :aria-label="$t('setup.progressLabel')"
        data-testid="setup-progress"
      >
        <li
          v-for="(id, index) in STEPS"
          :key="id"
          class="h-1.5 flex-1 rounded-full"
          :class="index <= currentIndex ? 'bg-brand' : 'bg-light-border/40 dark:bg-dark-border/30'"
        >
          <span class="sr-only">{{ $t(`setup.steps.${id}`) }}</span>
        </li>
      </ol>

      <div class="surface-card rounded-xl p-5 sm:p-6">
        <p v-if="loading" class="text-sm txt-secondary" data-testid="setup-loading">
          {{ $t('setup.loading') }}
        </p>

        <!--
          The API is authoritative about the installation's state, and it says
          this instance is already set up. Reached when the runtime config the
          SPA booted with has gone stale.
        -->
        <div v-else-if="alreadyDone" class="flex flex-col gap-3" data-testid="setup-already-done">
          <h2 class="text-lg font-semibold txt-primary">{{ $t('setup.alreadyDone.title') }}</h2>
          <p class="text-sm txt-secondary">{{ $t('setup.alreadyDone.description') }}</p>
          <router-link
            to="/login"
            class="btn-primary w-full py-2.5 rounded-lg text-sm font-semibold text-center"
            data-testid="setup-goto-login"
          >
            {{ $t('setup.alreadyDone.cta') }}
          </router-link>
        </div>

        <p
          v-else-if="loadError"
          class="text-sm text-[var(--status-error-text)] bg-[var(--status-error-muted)] rounded-lg px-3 py-2"
          data-testid="setup-load-error"
        >
          {{ loadError }}
        </p>

        <SetupAdminStep v-else-if="'admin' === step" @created="afterAdminCreated" @stale="load" />

        <SetupProviderStep v-else-if="'provider' === step" @next="step = 'access'" />

        <SetupAccessStep
          v-else-if="'access' === step"
          :registration-locked="access.registrationLocked"
          :guest-chat-locked="access.guestChatLocked"
          :mailer-configured="mailerConfigured"
          :initial-registration-enabled="access.registrationEnabled"
          :initial-guest-chat-enabled="access.guestChatEnabled"
          @completed="step = 'done'"
        />

        <SetupDoneStep v-else />
      </div>

      <p
        v-if="showLockdownHint"
        class="text-xs txt-secondary text-center"
        data-testid="setup-footer-hint"
      >
        {{ $t('setup.footerHint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * First-run setup of the INSTALLATION.
 *
 * Only reachable while the instance has no administrator: the router's setup gate
 * forces every other route here in that state, and pushes this route away
 * otherwise. Everything the wizard needs is either public (`/api/v1/setup/*`) or
 * runs as the administrator that step 1 created and signed in.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { MoonIcon, ServerIcon, SunIcon } from '@heroicons/vue/24/outline'
import { supportedLanguages } from '@/i18n'
import { useTheme } from '@/composables/useTheme'
import { useSetupState } from '@/composables/useSetupState'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import {
  isNativeServerControlAvailable,
  openNativeServerOverlay,
} from '@/services/api/nativeServer'
import SetupAdminStep from '@/components/setup/SetupAdminStep.vue'
import SetupProviderStep from '@/components/setup/SetupProviderStep.vue'
import SetupAccessStep from '@/components/setup/SetupAccessStep.vue'
import SetupDoneStep from '@/components/setup/SetupDoneStep.vue'

const STEPS = ['admin', 'provider', 'access'] as const
type Step = (typeof STEPS)[number] | 'done'

const { isDark, setTheme } = useTheme()
const { locale, t } = useI18n()
const { state, loading, error: loadError, load } = useSetupState()

const toggleTheme = () => setTheme(isDark.value ? 'light' : 'dark')

function cycleLanguage(): void {
  const next = (supportedLanguages.indexOf(locale.value as never) + 1) % supportedLanguages.length
  locale.value = supportedLanguages[next]
  localStorage.setItem('language', locale.value)
}

const step = ref<Step>('admin')
const serverSwitchAvailable = isNativeServerControlAvailable()
const brandName = computed(() => useConfigStore().branding.name)

const currentIndex = computed(() => STEPS.indexOf(step.value as (typeof STEPS)[number]))

const alreadyDone = computed(
  () => null !== state.value && !state.value.wizardRequired && 'admin' === step.value
)

/**
 * "This server has no administrator yet" is only true on the first step — it
 * contradicts both the later steps (the administrator exists by then) and the
 * already-set-up card. Past step 1 the position in the flow is the useful thing
 * to say, and the completion screen speaks for itself.
 */
const subtitle = computed(() => {
  if (alreadyDone.value || 'done' === step.value) {
    return ''
  }
  if ('admin' === step.value) {
    return t('setup.subtitle')
  }

  return t('setup.subtitleProgress', { current: currentIndex.value + 1, total: STEPS.length })
})

/**
 * The lockdown is over once the access policy is stored, so promising it on the
 * completion screen would be a lie — as it would be on an instance that was
 * already set up before this page loaded.
 */
const showLockdownHint = computed(() => 'done' !== step.value && !alreadyDone.value)

const mailerConfigured = computed(() => true === state.value?.mailerConfigured)

const DEFAULT_ACCESS = {
  registrationEnabled: true,
  guestChatEnabled: true,
  registrationLocked: false,
  guestChatLocked: false,
}

const access = computed(() => state.value?.access ?? DEFAULT_ACCESS)

/**
 * Step 1 already signed the new administrator in via auth cookies (or native
 * Bearer tokens). Adopting that session into Pinia before refreshing means the
 * completion screen navigates as a signed-in user even if /auth/me is still
 * briefly closed by the setup lockdown.
 */
async function afterAdminCreated(): Promise<void> {
  step.value = 'provider'
  const auth = useAuthStore()
  auth.adoptCurrentSession()
  await auth.refreshUser()
}

onMounted(load)
</script>
