<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden" data-testid="page-email-verified">
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
      </div>

      <div class="surface-card p-8 text-center" data-testid="section-content">
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-500/10 flex items-center justify-center animate-scale-in">
          <CheckCircleIcon class="w-12 h-12 text-green-500" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('auth.emailVerified') }}</h1>
        <p class="txt-secondary mb-8">
          {{ $t('auth.emailVerifiedDesc') }}
        </p>

        <div class="space-y-3">
          <Button
            @click="handleContinue"
            class="w-full btn-primary py-3 rounded-lg font-medium"
            data-testid="btn-continue"
          >
            {{ $t('auth.continueToApp') }}
          </Button>

          <p class="text-sm txt-secondary">
            {{ $t('auth.redirectIn', { seconds: countdown }) }}
          </p>
        </div>

        <div class="mt-8 pt-6 border-t border-light-border/30 dark:border-dark-border/20">
          <div class="flex items-center justify-center gap-2 text-sm txt-secondary">
            <CheckCircleIcon class="w-4 h-4 text-green-500" />
            <span>{{ $t('auth.accountReady') }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
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

const countdown = ref(5)
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

const handleContinue = () => {
  router.push('/login')
}

onMounted(() => {
  countdownInterval = window.setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      if (countdownInterval) {
        clearInterval(countdownInterval)
      }
      handleContinue()
    }
  }, 1000)
})

onUnmounted(() => {
  if (countdownInterval) {
    clearInterval(countdownInterval)
  }
})
</script>

<style scoped>
@keyframes scale-in {
  0% {
    transform: scale(0);
    opacity: 0;
  }
  50% {
    transform: scale(1.1);
  }
  100% {
    transform: scale(1);
    opacity: 1;
  }
}

.animate-scale-in {
  animation: scale-in 0.5s ease-out;
}
</style>

