<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden" data-testid="page-login">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"></div>
      <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"></div>
    </div>
    <div class="absolute top-6 right-6 flex items-center gap-4" data-testid="section-controls">
      <button
        @click="cycleLanguage"
        class="h-10 px-4 rounded-lg icon-ghost text-sm font-medium"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        @click="toggleTheme"
        class="h-10 w-10 rounded-lg icon-ghost flex items-center justify-center"
        :aria-label="themeStore.theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
      >
        <SunIcon v-if="themeStore.theme.value === 'dark'" class="w-5 h-5" />
        <MoonIcon v-else class="w-5 h-5" />
      </button>
    </div>

    <div class="w-full max-w-md" data-testid="section-card">
      <div class="text-center mb-8" data-testid="section-header">
        <img
          :src="logoSrc"
          alt="synaplan"
          class="h-12 mx-auto mb-6"
        />
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('auth.login') }}</h1>
        <p class="txt-secondary">{{ $t('welcome') }}</p>
      </div>

      <div class="surface-card p-8" data-testid="section-form">
        <form @submit.prevent="handleLogin" class="space-y-5" data-testid="comp-login-form">
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
              @blur="emailError = !validateEmail(email) && email ? 'Invalid email format' : ''"
              data-testid="input-email"
            />
            <p v-if="emailError" class="text-sm text-red-600 dark:text-red-400 mt-1">{{ emailError }}</p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.password') }}
            </label>
            <input
              id="password"
              v-model="password"
              type="password"
              required
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :placeholder="$t('auth.password')"
              data-testid="input-password"
            />
          </div>

          <div class="flex items-center justify-end">
            <router-link
              to="/forgot-password"
              class="text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded" style="color: var(--brand)"
              data-testid="link-forgot"
            >
              {{ $t('auth.forgotPassword') }}
            </router-link>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
            <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
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
            <div class="w-full border-t" style="border-color: rgba(0,0,0,.06);"></div>
          </div>
          <div class="relative flex justify-center text-xs">
            <span class="px-2 surface-card txt-secondary">
              {{ $t('auth.orContinueWith') }}
            </span>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3" data-testid="section-social">
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

       <p class="mt-6 text-center text-sm txt-secondary">
         {{ $t('auth.noAccount') }}
         <router-link
           to="/register"
           class="font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded" style="color: var(--brand)"
           data-testid="link-signup"
         >
           {{ $t('auth.signUp') }}
         </router-link>
       </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useAuth } from '../composables/useAuth'
import { useRecaptcha } from '../composables/useRecaptcha'
import { validateEmail } from '../composables/usePasswordValidation'
import Button from '../components/Button.vue'

const router = useRouter()
const route = useRoute()
const { locale } = useI18n()
const themeStore = useTheme()
const { getToken: getReCaptchaToken } = useRecaptcha()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const email = ref('')
const password = ref('')

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

const { login, error: authError, loading, clearError } = useAuth()
const emailError = ref('')
const sessionExpiredMessage = ref('')

// Computed error to show either auth error or session expired message
const error = computed(() => sessionExpiredMessage.value || authError.value)

// Check for session expiration on mount
onMounted(() => {
  const reason = route.query.reason as string
  if (reason === 'session_expired') {
    sessionExpiredMessage.value = 'Your session has expired. Please login again.'
    // Remove query parameter from URL without reloading
    router.replace({ query: {} })
  }
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
    const redirect = router.currentRoute.value.query.redirect as string || '/'
    router.push(redirect)
  }
}

const handleSocialLogin = (provider: string) => {
  console.log('Social login not yet implemented:', provider)
  // TODO: Implement social login
}
</script>
