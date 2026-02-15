<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-login"
  >
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>
    <div class="absolute top-6 right-6 flex items-center gap-4" data-testid="section-controls">
      <button class="h-10 px-4 rounded-lg icon-ghost text-sm font-medium" @click="cycleLanguage">
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        class="h-10 w-10 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="
          themeStore.theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'
        "
        @click="toggleTheme"
      >
        <SunIcon v-if="themeStore.theme.value === 'dark'" class="w-5 h-5" />
        <MoonIcon v-else class="w-5 h-5" />
      </button>
    </div>

    <div class="w-full max-w-md" data-testid="section-card">
      <div class="text-center mb-8" data-testid="section-header">
        <img :src="logoSrc" alt="synaplan" class="h-12 mx-auto mb-6" />
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('auth.login') }}</h1>
        <p class="txt-secondary">{{ $t('welcome') }}</p>
      </div>

      <div class="surface-card p-8" data-testid="section-form">
        <form class="space-y-5" data-testid="comp-login-form" @submit.prevent="handleLogin">
          <div>
            <label for="email" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.email') }}
            </label>
            <input
              id="email"
              v-model="email"
              type="email"
              required
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :class="{ 'ring-2 ring-red-500': emailError }"
              :placeholder="$t('auth.email')"
              data-testid="input-email"
              @blur="emailError = !validateEmail(email) && email ? 'Invalid email format' : ''"
            />
            <p v-if="emailError" class="text-sm text-red-600 dark:text-red-400 mt-1">
              {{ emailError }}
            </p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.password') }}
            </label>
            <div class="relative">
              <input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                required
                class="w-full px-4 py-3 pr-12 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
                :placeholder="$t('auth.password')"
                data-testid="input-password"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded txt-secondary hover:txt-primary transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :aria-label="showPassword ? $t('auth.hidePassword') : $t('auth.showPassword')"
                data-testid="btn-toggle-password"
                @click="showPassword = !showPassword"
              >
                <EyeSlashIcon v-if="showPassword" class="w-5 h-5" />
                <EyeIcon v-else class="w-5 h-5" />
              </button>
            </div>
          </div>

          <div class="flex items-center justify-end">
            <router-link
              to="/forgot-password"
              class="text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded"
              style="color: var(--brand)"
              data-testid="link-forgot"
            >
              {{ $t('auth.forgotPassword') }}
            </router-link>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="alert-error">
            <p class="text-sm alert-error-text">{{ error }}</p>
          </div>

          <Button
            type="submit"
            class="w-full btn-primary py-3 rounded-lg font-medium"
            :disabled="loading"
            data-testid="btn-login"
          >
            <span v-if="loading">{{ $t('auth.signingIn') || 'Signing in...' }}</span>
            <span v-else>{{ $t('auth.signIn') }}</span>
          </Button>
        </form>

        <div class="relative my-6" data-testid="section-divider">
          <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t" style="border-color: rgba(0, 0, 0, 0.06)"></div>
          </div>
          <div class="relative flex justify-center text-xs">
            <span class="px-2 surface-card txt-secondary">
              {{ $t('auth.orContinueWith') }}
            </span>
          </div>
        </div>

        <div
          v-if="socialProviders.length > 0"
          :class="`grid gap-3`"
          :style="`grid-template-columns: repeat(${socialProviders.length}, 1fr)`"
          data-testid="section-social"
        >
          <button
            v-for="provider in socialProviders"
            :key="provider.id"
            type="button"
            class="flex items-center justify-center px-4 py-3 rounded-lg surface-chip txt-secondary hover-surface transition-all duration-200"
            :data-testid="`btn-social-${provider.id}`"
            :title="`Login with ${provider.name}`"
            @click="handleSocialLogin(provider.id)"
          >
            <!-- Google Icon -->
            <svg v-if="provider.id === 'google'" class="w-5 h-5" viewBox="0 0 24 24">
              <path
                fill="currentColor"
                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
              />
              <path
                fill="currentColor"
                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
              />
              <path
                fill="currentColor"
                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
              />
              <path
                fill="currentColor"
                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
              />
            </svg>

            <!-- GitHub Icon -->
            <svg
              v-else-if="provider.id === 'github'"
              class="w-5 h-5"
              viewBox="0 0 24 24"
              fill="currentColor"
            >
              <path
                d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.17 6.839 9.49.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.463-1.11-1.463-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.831.092-.646.35-1.086.636-1.336-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.578.688.48C19.138 20.167 22 16.418 22 12c0-5.523-4.477-10-10-10z"
              />
            </svg>

            <!-- Keycloak Icon (Key) -->
            <svg
              v-else-if="provider.id === 'keycloak'"
              class="w-5 h-5"
              viewBox="0 0 24 24"
              fill="currentColor"
            >
              <path
                d="M7 14C5.9 14 5 13.1 5 12S5.9 10 7 10 9 10.9 9 12 8.1 14 7 14M12.65 10C11.83 7.67 9.61 6 7 6C3.69 6 1 8.69 1 12S3.69 18 7 18C9.61 18 11.83 16.33 12.65 14H17V18H21V14H23V10H12.65Z"
              />
            </svg>
          </button>
        </div>

        <p v-if="socialProviders.length === 0" class="mt-4 text-center text-sm txt-secondary">
          {{ $t('auth.noSocialProviders') }}
        </p>

        <p class="mt-6 text-center text-sm txt-secondary">
          {{ $t('auth.noAccount') }}
          <router-link
            to="/register"
            class="font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded"
            style="color: var(--brand)"
            data-testid="link-signup"
          >
            {{ $t('auth.signUp') }}
          </router-link>
        </p>
      </div>

      <!-- Back to homepage link -->
      <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
        <a
          href="https://www.synaplan.com"
          class="hover:underline"
          target="_blank"
          rel="noopener noreferrer"
          data-testid="link-homepage"
        >
          {{ $t('auth.backToHomepage') }}
        </a>
      </p>
    </div>

    <!-- GDPR Cookie Consent Banner -->
    <CookieConsent @consent="handleCookieConsent" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useAuth } from '../composables/useAuth'
import { useRecaptcha } from '../composables/useRecaptcha'
import { validateEmail } from '../composables/usePasswordValidation'
import { useGoogleTag } from '../composables/useGoogleTag'
import Button from '../components/Button.vue'
import CookieConsent from '../components/CookieConsent.vue'
import { type CookieConsent as CookieConsentType } from '../composables/useCookieConsent'
import { useConfigStore } from '@/stores/config'

const router = useRouter()
const route = useRoute()
const { locale } = useI18n()
const themeStore = useTheme()
const { getToken: getReCaptchaToken } = useRecaptcha()
const config = useConfigStore()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(
  () => `${import.meta.env.BASE_URL}${isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg'}`
)

const email = ref('')
const password = ref('')
const showPassword = ref(false)

const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
  // Alphabetical order: DE, EN, ES, TR (EN is default)
  const languages = ['de', 'en', 'es', 'tr']
  const currentIndex = languages.indexOf(locale.value)
  const nextIndex = (currentIndex + 1) % languages.length
  locale.value = languages[nextIndex]
  localStorage.setItem('language', languages[nextIndex])
}

const toggleTheme = () => {
  const themes: ('light' | 'dark' | 'system')[] = ['light', 'dark', 'system']
  const currentIndex = themes.indexOf(themeStore.theme.value)
  const nextTheme = themes[(currentIndex + 1) % themes.length]
  themeStore.setTheme(nextTheme)
}

const { login, error: authError, loading, clearError } = useAuth()
const emailError = ref('')
const sessionExpiredMessage = ref('')

// Google Tag tracking (only injects if enabled, configured, AND user consented - GDPR)
const { injectGoogleTag } = useGoogleTag()

// Handle cookie consent - inject Google Tag only after user accepts
const handleCookieConsent = (consent: CookieConsentType) => {
  if (consent.analytics) {
    injectGoogleTag()
  }
}

// Computed error to show either auth error or session expired message
const error = computed(() => sessionExpiredMessage.value || authError.value)

// Social login providers
interface SocialProvider {
  id: string
  name: string
  enabled: boolean
  icon: string
}

const socialProviders = ref<SocialProvider[]>([])

// Load available social providers
const loadSocialProviders = async () => {
  try {
    const response = await fetch(`${config.appBaseUrl}/api/v1/auth/providers`)
    const data = await response.json()
    socialProviders.value = data.providers || []
  } catch (e) {
    console.error('Failed to load social providers:', e)
    socialProviders.value = []
  }
}

// Check for session expiration on mount
onMounted(() => {
  const reason = route.query.reason as string
  if (reason === 'session_expired') {
    sessionExpiredMessage.value = 'Your session has expired. Please login again.'
    // Remove query parameter from URL without reloading
    router.replace({ query: {} })
  }

  // Load social providers
  loadSocialProviders()
})

const handleLogin = async () => {
  clearError()
  emailError.value = ''
  sessionExpiredMessage.value = ''

  // Validate email
  if (!validateEmail(email.value)) {
    emailError.value = 'Invalid email format'
    return
  }

  if (!password.value) {
    return
  }

  // Get reCAPTCHA token (empty string if disabled)
  const recaptchaToken = await getReCaptchaToken('login')

  const success = await login(email.value, password.value, recaptchaToken)

  if (success) {
    const redirect = (router.currentRoute.value.query.redirect as string) || '/'
    router.push(redirect)
  }
}

const handleSocialLogin = (provider: string) => {
  // Redirect to backend OAuth endpoint
  const oauthUrl = `${config.appBaseUrl}/api/v1/auth/${provider}/login`

  console.log(`Redirecting to ${provider} OAuth:`, oauthUrl)

  // Full page redirect to OAuth provider
  window.location.href = oauthUrl
}
</script>
