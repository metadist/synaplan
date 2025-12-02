<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden" data-testid="page-register">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"></div>
      <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"></div>
    </div>
    <div class="absolute top-6 right-6 flex items-center gap-4">
      <button
        @click="cycleLanguage"
        class="h-10 px-4 rounded-lg icon-ghost text-sm font-medium"
        data-testid="btn-language-toggle"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        @click="toggleTheme"
        class="h-10 w-10 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="themeStore.theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
        data-testid="btn-theme-toggle"
      >
        <SunIcon v-if="themeStore.theme.value === 'dark'" class="w-5 h-5" />
        <MoonIcon v-else class="w-5 h-5" />
      </button>
    </div>

    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <img
          :src="logoSrc"
          alt="synaplan"
          class="h-12 mx-auto mb-6"
        />
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('auth.register') }}</h1>
        <p class="txt-secondary">{{ $t('welcome') }}</p>
      </div>

      <div class="surface-card p-8" data-testid="section-register-card">
        <!-- Success State (shown after registration) -->
        <div v-if="registrationSuccess" class="text-center space-y-6" data-testid="section-registration-success">
          <div class="success-icon-container">
            <svg class="success-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h3 class="text-2xl font-bold txt-primary">{{ $t('auth.registrationSuccess') }}</h3>
          <p class="txt-secondary text-sm leading-relaxed">{{ $t('auth.registrationSuccessDesc') }}</p>
          
          <div class="pt-4 space-y-3">
            <Button
              @click="router.push('/login')"
              class="w-full btn-primary py-3 rounded-lg font-medium"
              data-testid="btn-goto-login"
            >
              {{ $t('auth.backToLogin') }}
            </Button>
          </div>
        </div>

        <!-- Registration Form -->
        <form v-else @submit.prevent="handleRegister" class="space-y-5" data-testid="form-register">
          <div data-testid="field-full-name">
            <label for="fullName" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.fullName') }}
            </label>
            <input
              id="fullName"
              v-model="fullName"
              type="text"
              required
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :placeholder="$t('auth.fullName')"
              data-testid="input-full-name"
            />
          </div>

          <div data-testid="field-email">
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
              @blur="emailError = !validateEmail(email) && email ? 'Invalid email format' : ''"
              data-testid="input-email"
            />
            <p v-if="emailError" class="text-sm text-red-600 dark:text-red-400 mt-1">{{ emailError }}</p>
          </div>

          <div data-testid="field-password">
            <label for="password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.password') }}
            </label>
            <input
              id="password"
              v-model="password"
              type="password"
              required
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :class="{ 'ring-2 ring-red-500': passwordErrors.length > 0 }"
              :placeholder="$t('auth.password')"
              data-testid="input-password"
            />
            <div v-if="passwordErrors.length > 0" class="mt-2 space-y-1">
              <p v-for="err in passwordErrors" :key="err" class="text-xs text-red-600 dark:text-red-400">• {{ err }}</p>
            </div>
          </div>

          <div data-testid="field-confirm-password">
            <label for="confirmPassword" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.confirmPassword') }}
            </label>
            <input
              id="confirmPassword"
              v-model="confirmPassword"
              type="password"
              required
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :placeholder="$t('auth.confirmPassword')"
              data-testid="input-confirm-password"
            />
          </div>

          <!-- Error Message -->
          <div v-if="error" class="notification notification--error" data-testid="alert-register-error">
            <div class="notification-icon">
              <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.662 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <p class="notification-text">{{ error }}</p>
          </div>

          <!-- Password Mismatch Warning -->
          <div v-if="password && confirmPassword && password !== confirmPassword" class="notification notification--warning" data-testid="alert-password-mismatch">
            <div class="notification-icon">
              <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.662 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <p class="notification-text">{{ $t('auth.passwordMismatch') }}</p>
          </div>

          <Button
            type="submit"
            class="w-full btn-primary py-3 rounded-lg font-medium"
            :disabled="loading || password !== confirmPassword"
            data-testid="btn-register"
          >
            <span v-if="loading">{{ $t('auth.signingUp') }}</span>
            <span v-else>{{ $t('auth.signUp') }}</span>
          </Button>
        </form>

        <div class="relative my-6">
          <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t" style="border-color: rgba(0,0,0,.06);"></div>
          </div>
          <div class="relative flex justify-center text-xs">
            <span class="px-2 surface-card txt-secondary">
              {{ $t('auth.orContinueWith') }}
            </span>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3" data-testid="section-social-login">
          <button
            @click="handleSocialLogin('google')"
            type="button"
            class="flex items-center justify-center px-4 py-3 rounded-lg surface-chip txt-secondary hover-surface transition-all duration-200"
            data-testid="btn-social-google"
          >
            <svg class="w-5 h-5" viewBox="0 0 24 24">
              <path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
              <path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
          </button>

          <button
            @click="handleSocialLogin('github')"
            type="button"
            class="flex items-center justify-center px-4 py-3 rounded-lg surface-chip txt-secondary hover-surface transition-all duration-200"
            data-testid="btn-social-github"
          >
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.17 6.839 9.49.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.463-1.11-1.463-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.831.092-.646.35-1.086.636-1.336-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.578.688.48C19.138 20.167 22 16.418 22 12c0-5.523-4.477-10-10-10z"/>
            </svg>
          </button>

          <button
            @click="handleSocialLogin('facebook')"
            type="button"
            class="flex items-center justify-center px-4 py-3 rounded-lg surface-chip txt-secondary hover-surface transition-all duration-200"
            data-testid="btn-social-facebook"
          >
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
          </button>
        </div>

        <p class="mt-6 text-center text-xs txt-secondary leading-relaxed">
          {{ $t('auth.termsAgree') }}
          <a href="#" class="transition-colors" style="color: var(--brand)">{{ $t('auth.termsOfService') }}</a>
          {{ $t('auth.and') }}
          <a href="#" class="transition-colors" style="color: var(--brand)">{{ $t('auth.privacyPolicy') }}</a>
        </p>

        <p class="mt-4 text-center text-sm txt-secondary">
          {{ $t('auth.hasAccount') }}
          <router-link
            to="/login"
            class="font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded" style="color: var(--brand)"
            data-testid="link-go-login"
          >
            {{ $t('auth.signIn') }}
          </router-link>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useAuth } from '../composables/useAuth'
import { useRecaptcha } from '../composables/useRecaptcha'
import { usePasswordValidation, validateEmail } from '../composables/usePasswordValidation'
import Button from '../components/Button.vue'

const router = useRouter()
const { locale } = useI18n()
const themeStore = useTheme()
const { getToken: getReCaptchaToken } = useRecaptcha()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const fullName = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')

const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
  const languages = ['en', 'de', 'tr']
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

const { register, error, loading, clearError } = useAuth()
const passwordErrors = ref<string[]>([])
const emailError = ref('')
const registrationSuccess = ref(false)

const handleRegister = async () => {
  clearError()
  passwordErrors.value = []
  emailError.value = ''

  // Validate email
  if (!validateEmail(email.value)) {
    emailError.value = 'Invalid email format'
    return
  }

  // Validate password
  const validation = usePasswordValidation(password.value)
  if (!validation.isValid) {
    passwordErrors.value = validation.errors
    return
  }

  // Check password match
  if (password.value !== confirmPassword.value) {
    return
  }
  
  // Get reCAPTCHA token (empty string if disabled)
  const recaptchaToken = await getReCaptchaToken('register')
  
  const success = await register(email.value, password.value, recaptchaToken)
  
  if (success) {
    registrationSuccess.value = true
  }
}

const handleSocialLogin = (provider: string) => {
  console.log('Social login not yet implemented:', provider)
  // TODO: Implement social login
}
</script>

<style scoped>
/* Notification styles using colors from style.css */
.notification {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem;
  border-radius: 0.75rem;
  border: 1px solid;
  animation: slideInDown 0.3s ease-out;
}

.notification-icon {
  flex-shrink: 0;
  width: 1.25rem;
  height: 1.25rem;
  margin-top: 0.125rem;
}

.notification-text {
  flex: 1;
  font-size: 0.875rem;
  line-height: 1.5;
}

/* Error notification */
.notification--error {
  background: rgba(220, 38, 38, 0.05);
  border-color: rgba(220, 38, 38, 0.2);
}
.dark .notification--error {
  background: rgba(239, 68, 68, 0.1);
  border-color: rgba(239, 68, 68, 0.25);
}

.notification--error .notification-icon {
  color: #dc2626;
}
.dark .notification--error .notification-icon {
  color: #f87171;
}

.notification--error .notification-text {
  color: #991b1b;
}
.dark .notification--error .notification-text {
  color: #fca5a5;
}

/* Warning notification */
.notification--warning {
  background: rgba(234, 179, 8, 0.05);
  border-color: rgba(234, 179, 8, 0.2);
}
.dark .notification--warning {
  background: rgba(250, 204, 21, 0.1);
  border-color: rgba(250, 204, 21, 0.25);
}

.notification--warning .notification-icon {
  color: #ca8a04;
}
.dark .notification--warning .notification-icon {
  color: #fbbf24;
}

.notification--warning .notification-text {
  color: #854d0e;
}
.dark .notification--warning .notification-text {
  color: #fde047;
}

/* Success icon container */
.success-icon-container {
  width: 4rem;
  height: 4rem;
  margin: 0 auto;
  border-radius: 50%;
  background: rgba(22, 163, 74, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: scaleIn 0.4s ease-out;
}
.dark .success-icon-container {
  background: rgba(34, 197, 94, 0.15);
}

.success-icon {
  width: 2rem;
  height: 2rem;
  color: #16a34a;
}
.dark .success-icon {
  color: #4ade80;
}

@keyframes slideInDown {
  from {
    opacity: 0;
    transform: translateY(-8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.8);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
