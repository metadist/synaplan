<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-logged-out"
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
      </div>

      <div class="surface-card p-8 text-center" data-testid="section-content">
        <div
          class="w-20 h-20 mx-auto mb-6 rounded-full bg-primary/10 flex items-center justify-center animate-scale-in"
        >
          <ArrowRightOnRectangleIcon class="w-12 h-12" style="color: var(--brand)" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('auth.loggedOutTitle') }}</h1>
        <p class="txt-secondary mb-8">
          {{ $t('auth.loggedOutDesc') }}
        </p>

        <Button
          class="w-full btn-primary py-3 rounded-lg font-medium"
          data-testid="btn-login-again"
          @click="handleLoginAgain"
        >
          {{ $t('auth.loginAgain') }}
        </Button>
      </div>

      <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
        <a
          href="https://www.synaplan.com"
          class="hover:underline opacity-75 hover:opacity-100 transition-opacity"
          target="_blank"
          rel="noopener noreferrer"
          data-testid="link-powered-by"
        >
          {{ $t('auth.poweredBySynaplan') }}
        </a>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SunIcon, MoonIcon, ArrowRightOnRectangleIcon } from '@heroicons/vue/24/outline'
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

const logoSrc = computed(
  () => `${import.meta.env.BASE_URL}${isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg'}`
)

const currentLanguage = computed(() => locale.value)

const cycleLanguage = () => {
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

const handleLoginAgain = () => {
  router.push('/login')
}
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
