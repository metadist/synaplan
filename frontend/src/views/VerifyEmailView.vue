<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden" data-testid="page-verify-email">
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
        <router-link to="/login" class="inline-block" data-testid="link-back-login">
          <img
            :src="logoSrc"
            alt="synaplan"
            class="h-12 mx-auto mb-6"
          />
        </router-link>
      </div>

      <div class="surface-card p-8 text-center" data-testid="section-verify-card">
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-[var(--brand)]/10 flex items-center justify-center">
          <EnvelopeIcon class="w-10 h-10" style="color: var(--brand)" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('auth.verifyEmail') }}</h1>
        <p class="txt-secondary mb-6">
          {{ $t('auth.verifyEmailDesc', { email: userEmail }) }}
        </p>

        <div class="space-y-4">
          <div class="p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg" data-testid="info-check-spam">
            <p class="text-sm txt-primary flex items-start gap-2">
              <InformationCircleIcon class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
              <span>{{ $t('auth.checkSpam') }}</span>
            </p>
          </div>

          <!-- Success Message -->
          <div v-if="successMessage" class="p-4 bg-green-500/10 border border-green-500/20 rounded-lg" data-testid="alert-resend-success">
            <p class="text-sm text-green-600 dark:text-green-400 flex items-start gap-2">
              <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
              <span>{{ successMessage }}</span>
            </p>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="p-4 bg-red-500/10 border border-red-500/20 rounded-lg" data-testid="alert-resend-error">
            <p class="text-sm text-red-600 dark:text-red-400 flex items-start gap-2">
              <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
              <span>{{ error }}</span>
            </p>
          </div>

          <!-- Remaining Attempts Info -->
          <div v-if="remainingAttempts < 5" class="p-3 bg-yellow-500/10 border border-yellow-500/20 rounded-lg" data-testid="text-remaining-attempts">
            <p class="text-xs txt-secondary text-center">
              {{ remainingAttempts }} attempt{{ remainingAttempts !== 1 ? 's' : '' }} remaining
            </p>
          </div>

          <Button
            @click="handleResendEmail"
            :disabled="isResending || countdown > 0 || remainingAttempts <= 0"
            class="w-full btn-secondary py-3 rounded-lg font-medium"
            data-testid="btn-resend-email"
          >
            <span v-if="remainingAttempts <= 0">
              Maximum attempts reached
            </span>
            <span v-else-if="!isResending && countdown === 0">
              {{ $t('auth.resendEmail') }}
            </span>
            <span v-else-if="isResending" class="flex items-center justify-center gap-2">
              <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('auth.sending') }}
            </span>
            <span v-else>
              Wait {{ Math.floor(countdown / 60) }}:{{ String(countdown % 60).padStart(2, '0') }}
            </span>
          </Button>

          <div class="flex items-center gap-2 text-sm txt-secondary" data-testid="section-change-email">
            <span>{{ $t('auth.wrongEmail') }}</span>
            <button
              @click="handleChangeEmail"
              class="font-medium transition-colors" style="color: var(--brand)"
              data-testid="btn-change-email"
            >
              {{ $t('auth.changeEmail') }}
            </button>
          </div>
        </div>

        <div class="mt-8 pt-6 border-t border-light-border/30 dark:border-dark-border/20">
          <router-link
            to="/login"
            class="text-sm txt-secondary hover:txt-primary transition-colors inline-flex items-center gap-2"
            data-testid="link-footer-login"
          >
            <ArrowLeftIcon class="w-4 h-4" />
            {{ $t('auth.backToLogin') }}
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, EnvelopeIcon, ArrowLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { authApi } from '@/services/api'
import Button from '../components/Button.vue'

const route = useRoute()
const router = useRouter()
const { locale } = useI18n()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const userEmail = ref(route.query.email as string || 'your@email.com')
const isResending = ref(false)
const countdown = ref(0)
const remainingAttempts = ref(5)
const cooldownMinutes = ref(2)
const error = ref('')
const successMessage = ref('')
let countdownInterval: number | null = null

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

const startCountdown = (seconds: number) => {
  countdown.value = seconds
  if (countdownInterval) {
    clearInterval(countdownInterval)
  }
  countdownInterval = window.setInterval(() => {
    countdown.value--
    if (countdown.value <= 0 && countdownInterval) {
      clearInterval(countdownInterval)
      countdownInterval = null
    }
  }, 1000)
}

const handleResendEmail = async () => {
  error.value = ''
  successMessage.value = ''
  isResending.value = true
  
  try {
    const response = await authApi.resendVerification(userEmail.value)
    
    successMessage.value = response.message || 'Verification email sent!'
    
    if (response.remainingAttempts !== undefined) {
      remainingAttempts.value = response.remainingAttempts
    }
    
    if (response.cooldownMinutes !== undefined) {
      cooldownMinutes.value = response.cooldownMinutes
      startCountdown(response.cooldownMinutes * 60)
    }
  } catch (err: any) {
    console.error('Failed to resend email:', err)
    
    if (err.response?.status === 429) {
      // Rate limit error
      const data = err.response.data
      error.value = data.message || data.error || 'Please wait before requesting another email'
      
      if (data.waitSeconds) {
        startCountdown(data.waitSeconds)
      }
      
      if (data.remainingAttempts !== undefined) {
        remainingAttempts.value = data.remainingAttempts
      }
      
      if (data.maxAttemptsReached) {
        error.value = 'Maximum attempts reached. Please contact support.'
        remainingAttempts.value = 0
      }
    } else if (err.response?.status === 500) {
      // Technical error (e.g., mail server issues)
      error.value = err.response?.data?.message || 'A technical error occurred. Please try again later.'
    } else if (err.response?.status === 400) {
      // Validation error
      error.value = err.response?.data?.error || err.response?.data?.message || 'Invalid request'
    } else {
      // Generic error
      error.value = err.response?.data?.message || err.response?.data?.error || 'Failed to send email. Please try again.'
    }
  } finally {
    isResending.value = false
  }
}

const handleChangeEmail = () => {
  router.push('/register')
}

onMounted(() => {
  // Don't auto-start countdown, user must click resend first
})

onUnmounted(() => {
  if (countdownInterval) {
    clearInterval(countdownInterval)
  }
})
</script>
