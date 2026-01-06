<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-forgot-password"
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
        <router-link to="/login" class="inline-block">
          <img :src="logoSrc" alt="synaplan" class="h-12 mx-auto mb-6" />
        </router-link>
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('auth.forgotPassword') }}</h1>
        <p class="txt-secondary">{{ $t('auth.forgotPasswordDesc') }}</p>
      </div>

      <div class="surface-card p-8" data-testid="section-form">
        <div v-if="!emailSent" data-testid="section-form-reset">
          <form
            class="space-y-5"
            data-testid="comp-forgot-form"
            @submit.prevent="handleResetPassword"
          >
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
                :placeholder="emailPlaceholder"
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

        <div v-else class="text-center space-y-6" data-testid="section-email-sent">
          <div class="success-icon-container">
            <svg
              class="success-icon"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
          </div>
          <h3 class="text-2xl font-bold txt-primary">{{ $t('auth.passwordResetSuccess') }}</h3>
          <p class="txt-secondary text-sm leading-relaxed">
            {{ $t('auth.passwordResetSuccessDesc') }}
          </p>
          <div class="pt-4 space-y-3">
            <Button
              class="w-full btn-secondary py-3 rounded-lg font-medium"
              data-testid="btn-resend"
              @click="emailSent = false"
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
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '@/composables/useTheme'
import { authApi } from '@/services/api'
import Button from '@/components/Button.vue'

const { locale, t } = useI18n()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => {
  const baseUrl = import.meta.env.BASE_URL || '/'
  return `${baseUrl}${isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg'}`
})

const emailPlaceholder = computed(() => {
  // Combine prefix and suffix with @ to avoid vue-i18n interpreting @ as linked message syntax
  return `${t('auth.emailPlaceholderPrefix')}@${t('auth.emailPlaceholderSuffix')}`
})

const email = ref('')
const emailSent = ref(false)
const isLoading = ref(false)

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

<style scoped>
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
