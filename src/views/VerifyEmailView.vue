<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"></div>
      <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"></div>
    </div>
    <div class="absolute top-6 right-6 flex items-center gap-4">
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

    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <router-link to="/login" class="inline-block">
          <img
            :src="logoSrc"
            alt="synaplan"
            class="h-12 mx-auto mb-6"
          />
        </router-link>
      </div>

      <div class="surface-card p-8 text-center">
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-[var(--brand)]/10 flex items-center justify-center">
          <EnvelopeIcon class="w-10 h-10" style="color: var(--brand)" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('auth.verifyEmail') }}</h1>
        <p class="txt-secondary mb-6">
          {{ $t('auth.verifyEmailDesc', { email: userEmail }) }}
        </p>

        <div class="space-y-4">
          <div class="p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg">
            <p class="text-sm txt-primary flex items-start gap-2">
              <InformationCircleIcon class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
              <span>{{ $t('auth.checkSpam') }}</span>
            </p>
          </div>

          <Button
            @click="handleResendEmail"
            :disabled="isResending || countdown > 0"
            class="w-full btn-secondary py-3 rounded-lg font-medium"
          >
            <span v-if="!isResending && countdown === 0">
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
              {{ $t('auth.resendIn', { seconds: countdown }) }}
            </span>
          </Button>

          <div class="flex items-center gap-2 text-sm txt-secondary">
            <span>{{ $t('auth.wrongEmail') }}</span>
            <button
              @click="handleChangeEmail"
              class="font-medium transition-colors" style="color: var(--brand)"
            >
              {{ $t('auth.changeEmail') }}
            </button>
          </div>
        </div>

        <div class="mt-8 pt-6 border-t border-light-border/30 dark:border-dark-border/20">
          <router-link
            to="/login"
            class="text-sm txt-secondary hover:txt-primary transition-colors inline-flex items-center gap-2"
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
const countdown = ref(60)
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

const startCountdown = () => {
  countdown.value = 60
  countdownInterval = window.setInterval(() => {
    countdown.value--
    if (countdown.value <= 0 && countdownInterval) {
      clearInterval(countdownInterval)
      countdownInterval = null
    }
  }, 1000)
}

const handleResendEmail = async () => {
  isResending.value = true
  try {
    await apiService.post('/auth/resend-verification', { email: userEmail.value })
    startCountdown()
  } catch (error) {
    console.error('Failed to resend email:', error)
  } finally {
    isResending.value = false
  }
}

const handleChangeEmail = () => {
  router.push('/register')
}

onMounted(() => {
  startCountdown()
})

onUnmounted(() => {
  if (countdownInterval) {
    clearInterval(countdownInterval)
  }
})
</script>

