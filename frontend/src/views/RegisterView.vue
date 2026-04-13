<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-register"
  >
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute -top-24 left-1/4 w-[28rem] h-[28rem] bg-brand/6 dark:bg-brand/12 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute -bottom-24 right-1/4 w-[28rem] h-[28rem] bg-brand/4 dark:bg-brand/8 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>

    <div class="absolute top-6 right-6 flex items-center gap-2">
      <button
        class="h-9 px-3 rounded-lg icon-ghost text-xs font-medium"
        data-testid="btn-language-toggle"
        @click="cycleLanguage"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        class="h-9 w-9 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="
          themeStore.theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'
        "
        data-testid="btn-theme-toggle"
        @click="toggleTheme"
      >
        <SunIcon v-if="themeStore.theme.value === 'dark'" class="w-4 h-4" />
        <MoonIcon v-else class="w-4 h-4" />
      </button>
    </div>

    <div class="w-full max-w-sm auth-card-enter">
      <!-- Logo + Title -->
      <div class="text-center mb-10">
        <img :src="logoSrc" alt="synaplan" class="h-10 mx-auto mb-5 auth-logo-enter" />
        <h1 class="text-2xl font-bold txt-primary auth-title-enter">
          {{ $t('auth.register') }}
        </h1>
      </div>

      <div class="auth-form-enter">
        <!-- Success State -->
        <div
          v-if="registrationSuccess"
          class="text-center py-6"
          data-testid="section-registration-success"
        >
          <div class="success-icon-wrapper mb-5">
            <div class="success-icon-ring"></div>
            <Icon
              icon="mdi:check-circle"
              class="w-14 h-14 text-green-500 dark:text-green-400 relative z-10"
            />
          </div>
          <h3 class="text-xl font-bold txt-primary mb-2">{{ $t('auth.registrationSuccess') }}</h3>
          <p class="txt-secondary text-sm leading-relaxed mb-6 max-w-xs mx-auto">
            {{ $t('auth.registrationSuccessDesc') }}
          </p>
          <button
            class="w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/25 active:scale-[0.98]"
            data-testid="btn-goto-login"
            @click="router.push({ name: 'login', query: { registered: 'true' } })"
          >
            {{ $t('auth.backToLogin') }}
          </button>
        </div>

        <!-- Registration Form -->
        <template v-else>
          <!-- Social login first -->
          <div
            v-if="socialProviders.length > 0"
            class="space-y-2.5 mb-6"
            data-testid="section-social-login"
          >
            <button
              v-for="provider in socialProviders"
              :key="provider.id"
              type="button"
              class="flex items-center justify-center gap-2.5 w-full py-2.5 rounded-xl text-sm font-medium surface-chip txt-primary ring-1 ring-black/[0.06] dark:ring-white/[0.06] hover:ring-black/[0.12] dark:hover:ring-white/[0.12] hover:shadow-sm transition-all duration-200 active:scale-[0.98]"
              :data-testid="`btn-social-${provider.id}`"
              @click="handleSocialLogin(provider.id)"
            >
              <svg v-if="provider.id === 'google'" class="w-4.5 h-4.5" viewBox="0 0 24 24">
                <path
                  fill="#4285F4"
                  d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                />
                <path
                  fill="#34A853"
                  d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                />
                <path
                  fill="#FBBC05"
                  d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                />
                <path
                  fill="#EA4335"
                  d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                />
              </svg>
              <svg
                v-else-if="provider.id === 'github'"
                class="w-4.5 h-4.5"
                viewBox="0 0 24 24"
                fill="currentColor"
              >
                <path
                  d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.17 6.839 9.49.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.463-1.11-1.463-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.831.092-.646.35-1.086.636-1.336-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.578.688.48C19.138 20.167 22 16.418 22 12c0-5.523-4.477-10-10-10z"
                />
              </svg>
              <Icon
                v-else-if="provider.id === 'keycloak'"
                icon="mdi:key-variant"
                class="w-4.5 h-4.5"
              />
              <span>{{ provider.name }}</span>
            </button>
          </div>

          <!-- Divider -->
          <div v-if="socialProviders.length > 0" class="relative my-6">
            <div class="absolute inset-0 flex items-center">
              <div class="w-full border-t border-gray-200/60 dark:border-gray-700/40"></div>
            </div>
            <div class="relative flex justify-center">
              <span class="px-3 bg-light-bg dark:bg-dark-bg text-xs txt-secondary">
                {{ $t('auth.orContinueWith') }}
              </span>
            </div>
          </div>

          <!-- Form -->
          <form class="space-y-3" data-testid="form-register" @submit.prevent="handleRegister">
            <!-- Full Name -->
            <div class="relative" data-testid="field-full-name">
              <Icon
                icon="mdi:account-outline"
                class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 txt-secondary pointer-events-none"
              />
              <input
                id="fullName"
                v-model="fullName"
                type="text"
                required
                class="auth-input pl-10"
                :placeholder="$t('auth.fullName')"
                data-testid="input-full-name"
              />
            </div>

            <!-- Email -->
            <div class="relative" data-testid="field-email">
              <Icon
                icon="mdi:email-outline"
                class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 txt-secondary pointer-events-none"
              />
              <input
                id="email"
                v-model="email"
                type="email"
                required
                class="auth-input pl-10"
                :class="{ 'ring-2 ring-red-500/60': emailError }"
                :placeholder="$t('auth.email')"
                data-testid="input-email"
                @blur="emailError = !validateEmail(email) && email ? 'Invalid email format' : ''"
              />
            </div>
            <p v-if="emailError" class="text-xs text-red-500 dark:text-red-400 -mt-1 pl-1">
              {{ emailError }}
            </p>

            <!-- Password -->
            <div class="relative" data-testid="field-password">
              <Icon
                icon="mdi:lock-outline"
                class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 txt-secondary pointer-events-none"
              />
              <input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                required
                class="auth-input pl-10 pr-10"
                :class="{ 'ring-2 ring-red-500/60': passwordErrors.length > 0 }"
                :placeholder="$t('auth.password')"
                data-testid="input-password"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded-md txt-secondary hover:txt-primary transition-colors"
                data-testid="btn-toggle-password"
                @click="showPassword = !showPassword"
              >
                <EyeSlashIcon v-if="showPassword" class="w-4 h-4" />
                <EyeIcon v-else class="w-4 h-4" />
              </button>
            </div>
            <div v-if="passwordErrors.length > 0" class="-mt-1 pl-1 space-y-0.5">
              <p
                v-for="err in passwordErrors"
                :key="err"
                class="text-xs text-red-500 dark:text-red-400"
              >
                {{ err }}
              </p>
            </div>

            <!-- Confirm Password -->
            <div class="relative" data-testid="field-confirm-password">
              <Icon
                icon="mdi:lock-check-outline"
                class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 txt-secondary pointer-events-none"
              />
              <input
                id="confirmPassword"
                v-model="confirmPassword"
                :type="showConfirmPassword ? 'text' : 'password'"
                required
                class="auth-input pl-10 pr-10"
                :placeholder="$t('auth.confirmPassword')"
                data-testid="input-confirm-password"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded-md txt-secondary hover:txt-primary transition-colors"
                data-testid="btn-toggle-confirm-password"
                @click="showConfirmPassword = !showConfirmPassword"
              >
                <EyeSlashIcon v-if="showConfirmPassword" class="w-4 h-4" />
                <EyeIcon v-else class="w-4 h-4" />
              </button>
            </div>

            <!-- Mismatch -->
            <p
              v-if="password && confirmPassword && password !== confirmPassword"
              class="text-xs text-amber-600 dark:text-amber-400 pl-1"
              data-testid="alert-password-mismatch"
            >
              {{ $t('auth.passwordMismatch') }}
            </p>

            <!-- Error -->
            <div
              v-if="error"
              class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200/60 dark:border-red-800/40 auth-banner-enter"
              data-testid="alert-register-error"
            >
              <p class="text-sm text-red-600 dark:text-red-300">{{ error }}</p>
            </div>

            <!-- Submit -->
            <button
              type="submit"
              class="w-full py-3 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-brand/25 active:scale-[0.98] disabled:opacity-50 disabled:pointer-events-none"
              :disabled="
                loading || (!!password && !!confirmPassword && password !== confirmPassword)
              "
              data-testid="btn-register"
            >
              <span v-if="loading" class="flex items-center justify-center gap-2">
                <span
                  class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"
                />
                {{ $t('auth.signingUp') }}
              </span>
              <span v-else>{{ $t('auth.signUp') }}</span>
            </button>
          </form>

          <!-- Terms -->
          <p class="mt-4 text-center text-[11px] txt-secondary leading-relaxed opacity-70">
            {{ $t('auth.termsAgree') }}
            <a
              href="https://www.synaplan.com/de/imprint"
              target="_blank"
              rel="noopener noreferrer"
              class="text-brand hover:underline underline-offset-2"
              >{{ $t('auth.termsOfService') }}</a
            >
            {{ $t('auth.and') }}
            <a
              href="https://www.synaplan.com/de/privacy-policy"
              target="_blank"
              rel="noopener noreferrer"
              class="text-brand hover:underline underline-offset-2"
              >{{ $t('auth.privacyPolicy') }}</a
            >
          </p>

          <!-- Switch to login -->
          <p class="mt-5 text-center text-sm txt-secondary">
            {{ $t('auth.hasAccount') }}
            <router-link
              to="/login"
              class="font-semibold text-brand hover:underline underline-offset-2"
              data-testid="link-go-login"
            >
              {{ $t('auth.signIn') }}
            </router-link>
          </p>
        </template>
      </div>

      <!-- Footer -->
      <p class="mt-8 text-center text-[11px] txt-secondary opacity-60">
        <a
          :href="config.billing.enabled ? 'https://www.synaplan.com' : 'https://www.synaplan.com'"
          class="hover:underline underline-offset-2 hover:opacity-100 transition-opacity"
          target="_blank"
          rel="noopener noreferrer"
          :data-testid="config.billing.enabled ? 'link-homepage' : 'link-powered-by'"
        >
          {{ config.billing.enabled ? $t('auth.backToHomepage') : $t('auth.poweredBySynaplan') }}
        </a>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useTheme } from '../composables/useTheme'
import { useAuth } from '../composables/useAuth'
import { useRecaptcha } from '../composables/useRecaptcha'
import { usePasswordValidation, validateEmail } from '../composables/usePasswordValidation'
import { useConfigStore } from '@/stores/config'

const router = useRouter()
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

const fullName = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)
const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
  const languages = ['de', 'en', 'es', 'tr']
  const currentIndex = languages.indexOf(locale.value)
  locale.value = languages[(currentIndex + 1) % languages.length]
  localStorage.setItem('language', locale.value)
}

const toggleTheme = () => {
  const themes: ('light' | 'dark' | 'system')[] = ['light', 'dark', 'system']
  const currentIndex = themes.indexOf(themeStore.theme.value)
  themeStore.setTheme(themes[(currentIndex + 1) % themes.length])
}

const { register, error, loading, clearError } = useAuth()
const passwordErrors = ref<string[]>([])
const emailError = ref('')
const registrationSuccess = ref(false)

interface SocialProvider {
  id: string
  name: string
  enabled: boolean
  icon: string
}

const socialProviders = ref<SocialProvider[]>([])

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

onMounted(() => {
  loadSocialProviders()
})

const handleRegister = async () => {
  clearError()
  passwordErrors.value = []
  emailError.value = ''

  if (!validateEmail(email.value)) {
    emailError.value = 'Invalid email format'
    return
  }

  const validation = usePasswordValidation(password.value)
  if (!validation.isValid) {
    passwordErrors.value = validation.errors
    return
  }

  if (password.value !== confirmPassword.value) return

  const recaptchaToken = await getReCaptchaToken('register')
  const success = await register(email.value, password.value, recaptchaToken)
  if (success) registrationSuccess.value = true
}

const handleSocialLogin = (provider: string) => {
  window.location.href = `${config.appBaseUrl}/api/v1/auth/${provider}/login`
}
</script>

<style scoped>
.auth-input {
  @apply w-full py-3 rounded-xl text-sm border-0;
  @apply surface-chip txt-primary placeholder:txt-secondary;
  @apply focus:outline-none focus:ring-2 focus:ring-brand/40;
  @apply transition-all duration-200;
  @apply hover:ring-1 hover:ring-black/[0.06] dark:hover:ring-white/[0.06];
}

@keyframes authCardEnter {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.auth-card-enter {
  animation: authCardEnter 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes authLogoEnter {
  from {
    opacity: 0;
    transform: scale(0.85);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
.auth-logo-enter {
  animation: authLogoEnter 0.4s ease-out 0.08s both;
}

@keyframes authTitleEnter {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.auth-title-enter {
  animation: authTitleEnter 0.4s ease-out 0.12s both;
}

@keyframes authFormEnter {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.auth-form-enter {
  animation: authFormEnter 0.45s ease-out 0.18s both;
}

@keyframes authBannerEnter {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.auth-banner-enter {
  animation: authBannerEnter 0.3s ease-out both;
}

@keyframes successPulse {
  0% {
    transform: scale(0.7);
    opacity: 0;
  }
  60% {
    transform: scale(1.08);
  }
  100% {
    transform: scale(1);
    opacity: 1;
  }
}
@keyframes successRing {
  0% {
    transform: scale(0.5);
    opacity: 0;
  }
  100% {
    transform: scale(1.6);
    opacity: 0;
  }
}
.success-icon-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto;
  animation: successPulse 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.success-icon-ring {
  position: absolute;
  inset: 0;
  border-radius: 9999px;
  border: 2px solid #22c55e;
  animation: successRing 0.8s ease-out 0.3s both;
}
</style>
