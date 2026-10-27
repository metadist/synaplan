<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-verify-email"
  >
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>
    <div class="absolute top-6 right-6 flex items-center gap-4">
      <button
        class="h-10 px-4 rounded-xl icon-ghost text-sm font-medium"
        data-testid="btn-language-toggle"
        @click="cycleLanguage"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        class="h-10 w-10 rounded-xl icon-ghost flex items-center justify-center"
        :aria-label="$t('appearance.cycle')"
        data-testid="btn-theme-toggle"
        @click="toggleTheme"
      >
        <SunIcon v-if="themeStore.isDark?.value" class="w-5 h-5" />
        <MoonIcon v-else class="w-5 h-5" />
      </button>
    </div>

    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <router-link to="/login" class="inline-block" data-testid="link-back-login">
          <img :src="logoSrc" :alt="config.branding.name" class="h-12 mx-auto mb-6" />
        </router-link>
      </div>

      <div class="surface-card p-8 text-center" data-testid="section-verify-card">
        <div
          class="w-20 h-20 mx-auto mb-6 rounded-full bg-[var(--brand)]/10 flex items-center justify-center"
        >
          <EnvelopeIcon class="w-10 h-10" style="color: var(--brand)" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('auth.verifyEmail') }}</h1>
        <p v-if="!mailUnavailable && hasEmail" class="txt-secondary mb-6">
          {{ $t('auth.verifyEmailDesc', { email: userEmail }) }}
        </p>
        <p
          v-else-if="!mailUnavailable"
          class="txt-secondary mb-6"
          data-testid="text-verify-no-email"
        >
          {{ $t('auth.resendInvalid') }}
        </p>

        <div class="space-y-4">
          <div v-if="mailUnavailable" class="alert-warning" data-testid="alert-resend-unconfigured">
            <p class="text-sm alert-warning-text">{{ $t('auth.resendNoMail') }}</p>
          </div>

          <div v-if="canResend" class="alert-info" data-testid="info-check-spam">
            <p class="text-sm alert-info-text flex items-start gap-2">
              <InformationCircleIcon class="w-5 h-5 alert-info-icon flex-shrink-0 mt-0.5" />
              <span>{{ $t('auth.checkSpam') }}</span>
            </p>
          </div>

          <!-- Success Message -->
          <div v-if="successMessage" class="alert-success" data-testid="alert-resend-success">
            <p class="text-sm alert-success-text flex items-start gap-2">
              <svg
                class="w-5 h-5 flex-shrink-0 mt-0.5"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                ></path>
              </svg>
              <span>{{ successMessage }}</span>
            </p>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="alert-error" data-testid="alert-resend-error">
            <p class="text-sm alert-error-text flex items-start gap-2">
              <svg
                class="w-5 h-5 flex-shrink-0 mt-0.5"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                ></path>
              </svg>
              <span>{{ error }}</span>
            </p>
          </div>

          <!-- Remaining Attempts Info -->
          <div
            v-if="canResend && remainingAttempts > 0 && remainingAttempts < 5"
            class="alert-warning"
            data-testid="text-remaining-attempts"
          >
            <p class="text-xs alert-warning-text text-center">
              {{
                $t('auth.resendAttemptsRemaining', { count: remainingAttempts }, remainingAttempts)
              }}
            </p>
          </div>

          <Button
            v-if="canResend"
            :disabled="isResending || countdown > 0 || remainingAttempts <= 0"
            class="w-full btn-secondary py-3 rounded-xl font-medium"
            data-testid="btn-resend-email"
            @click="handleResendEmail"
          >
            <span v-if="remainingAttempts <= 0">{{ $t('auth.resendNoAttempts') }}</span>
            <span v-else-if="!isResending && countdown === 0">
              {{ $t('auth.resendEmail') }}
            </span>
            <span v-else-if="isResending" class="flex items-center justify-center gap-2">
              <svg
                class="animate-spin h-5 w-5"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              {{ $t('auth.sending') }}
            </span>
            <span v-else>
              {{ $t('auth.resendIn', { seconds: countdown }) }}
            </span>
          </Button>

          <div
            class="flex items-center gap-2 text-sm txt-secondary"
            data-testid="section-change-email"
          >
            <span>{{ $t('auth.wrongEmail') }}</span>
            <button
              class="font-medium transition-colors"
              style="color: var(--brand)"
              data-testid="btn-change-email"
              @click="handleChangeEmail"
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
import { ref, computed, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { cycleLocale } from '@/i18n'
import {
  SunIcon,
  MoonIcon,
  EnvelopeIcon,
  ArrowLeftIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useConfigStore } from '@/stores/config'
import { resendVerificationEmail } from '@/services/api/verificationApi'
import { ApiError } from '@/services/api/httpClient'
import { isMailerConfigured } from '@/utils/mailerConfigured'
import Button from '../components/Button.vue'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const themeStore = useTheme()
const config = useConfigStore()
const { logoSrc } = useBrandLogo(themeStore.isDark)

const userEmail = ref(typeof route.query.email === 'string' ? route.query.email.trim() : '')
const isResending = ref(false)
const countdown = ref(0)
const remainingAttempts = ref(5)
const cooldownMinutes = ref(2)
const error = ref('')
const successMessage = ref('')
const mailUnavailable = ref(!isMailerConfigured())
let countdownInterval: number | null = null

const hasEmail = computed(() => userEmail.value !== '')
const canResend = computed(() => hasEmail.value && !mailUnavailable.value)

const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
  void cycleLocale()
}

const toggleTheme = () => themeStore.cycleTheme()

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
    const response = await resendVerificationEmail(userEmail.value)
    if (response.mailDelivered === false) {
      mailUnavailable.value = true
      return
    }

    successMessage.value = t('auth.resendSent')
    if (typeof response.remainingAttempts === 'number') {
      remainingAttempts.value = response.remainingAttempts
    }
    if (typeof response.cooldownMinutes === 'number') {
      cooldownMinutes.value = response.cooldownMinutes
      startCountdown(response.cooldownMinutes * 60)
    }
  } catch (err: unknown) {
    error.value = resendErrorMessage(err)
    if (!(err instanceof ApiError) || err.status !== 429) {
      return
    }
    const details = err.details ?? {}
    if (details.maxAttemptsReached === true) {
      remainingAttempts.value = 0
      return
    }
    if (typeof details.waitSeconds === 'number') {
      startCountdown(details.waitSeconds)
    }
    if (typeof details.remainingAttempts === 'number') {
      remainingAttempts.value = details.remainingAttempts
    }
  } finally {
    isResending.value = false
  }
}

const resendErrorMessage = (err: unknown): string => {
  if (!(err instanceof ApiError)) {
    return t('auth.resendFailed')
  }
  if (err.status === 429 && err.details?.maxAttemptsReached === true) {
    return t('auth.resendMaxAttempts')
  }
  if (err.status === 429) {
    return t('auth.resendCooldown')
  }
  if (err.status === 400) {
    return t('auth.resendInvalid')
  }
  return t('auth.resendFailed')
}

const handleChangeEmail = () => {
  router.push('/register')
}

onUnmounted(() => {
  if (countdownInterval) {
    clearInterval(countdownInterval)
  }
})
</script>
