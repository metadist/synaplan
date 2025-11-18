<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden" data-testid="page-forgot-password">
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
        <router-link to="/login" class="inline-block">
          <img
            :src="logoSrc"
            alt="synaplan"
            class="h-12 mx-auto mb-6"
          />
        </router-link>
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('auth.forgotPassword') }}</h1>
        <p class="txt-secondary">{{ $t('auth.forgotPasswordDesc') }}</p>
      </div>

      <div class="surface-card p-8" data-testid="section-form">
        <div v-if="!emailSent" data-testid="section-form-reset">
          <form @submit.prevent="handleResetPassword" class="space-y-5" data-testid="comp-forgot-form">
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
                :placeholder="$t('auth.emailPlaceholder')"
                data-testid="input-email"
              />
            </div>

            <Button
              type="submit"
              class="w-full btn-primary py-3 rounded-lg font-medium"
              :disabled="isLoading"
              data-testid="btn-send"
            >
              <span v-if="!isLoading">{{ $t('auth.sendResetLink') }}</span>
              <span v-else class="flex items-center justify-center gap-2">
                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ $t('auth.sending') }}
              </span>
            </Button>
          </form>

          <div class="mt-6 text-center">
            <router-link
              to="/login"
              class="text-sm txt-secondary hover:txt-primary transition-colors inline-flex items-center gap-2"
              data-testid="link-back"
            >
              <ArrowLeftIcon class="w-4 h-4" />
              {{ $t('auth.backToLogin') }}
            </router-link>
          </div>
        </div>

        <div v-else class="text-center space-y-4" data-testid="section-email-sent">
          <div class="w-16 h-16 mx-auto rounded-full bg-green-500/10 flex items-center justify-center">
            <CheckCircleIcon class="w-8 h-8 text-green-500" />
          </div>
          <h3 class="text-xl font-semibold txt-primary">{{ $t('auth.emailSent') }}</h3>
          <p class="txt-secondary">{{ $t('auth.emailSentDesc', { email: email }) }}</p>
          <div class="pt-4">
            <Button
              @click="emailSent = false"
              class="btn-secondary py-2 px-6 rounded-lg font-medium"
              data-testid="btn-resend"
            >
              {{ $t('auth.resendEmail') }}
            </Button>
          </div>
          <div class="mt-6">
            <router-link
              to="/login"
              class="text-sm txt-secondary hover:txt-primary transition-colors inline-flex items-center gap-2"
              data-testid="link-login"
            >
              <ArrowLeftIcon class="w-4 h-4" />
              {{ $t('auth.backToLogin') }}
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, ArrowLeftIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { authApi } from '@/services/api'
import Button from '../components/Button.vue'

const router = useRouter()
const { locale } = useI18n()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const email = ref('')
const emailSent = ref(false)
const isLoading = ref(false)

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

const handleResetPassword = async () => {
  if (!email.value) return
  
  isLoading.value = true
  try {
    await authApi.forgotPassword(email.value)
    emailSent.value = true
  } catch (error) {
    console.error('Password reset failed:', error)
  } finally {
    isLoading.value = false
  }
}
</script>

