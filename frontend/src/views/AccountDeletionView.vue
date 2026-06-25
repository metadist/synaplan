<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-account-deletion"
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

    <div class="w-full max-w-2xl" data-testid="section-card">
      <div class="text-center mb-8" data-testid="section-header">
        <img :src="logoSrc" :alt="config.branding.name" class="h-12 mx-auto mb-6" />
      </div>

      <div class="surface-card p-8" data-testid="section-content">
        <div class="text-center mb-8">
          <div
            class="w-16 h-16 mx-auto mb-5 rounded-full bg-red-500/10 flex items-center justify-center"
          >
            <Icon
              icon="mdi:account-remove-outline"
              class="w-9 h-9 text-red-600 dark:text-red-400"
            />
          </div>
          <h1 class="text-2xl font-bold txt-primary mb-2">
            {{ $t('accountDeletion.title', { brand: config.branding.name }) }}
          </h1>
          <p class="txt-secondary">{{ $t('accountDeletion.intro') }}</p>
        </div>

        <section class="mb-8">
          <h2 class="text-lg font-semibold txt-primary mb-3">
            {{ $t('accountDeletion.howTitle') }}
          </h2>
          <ol class="space-y-3">
            <li
              v-for="(step, index) in steps"
              :key="index"
              class="flex items-start gap-3 txt-secondary text-sm"
            >
              <span
                class="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 text-[var(--brand)] text-xs font-semibold flex items-center justify-center"
              >
                {{ index + 1 }}
              </span>
              <span>{{ step }}</span>
            </li>
          </ol>
          <div class="mt-5 flex flex-wrap gap-3">
            <RouterLink
              to="/profile"
              class="btn-primary px-4 py-2.5 rounded-lg font-medium text-sm"
              data-testid="link-profile-delete"
            >
              {{ $t('accountDeletion.goToProfile') }}
            </RouterLink>
            <RouterLink
              to="/login"
              class="px-4 py-2.5 rounded-lg font-medium text-sm surface-chip txt-primary"
              data-testid="link-login"
            >
              {{ $t('accountDeletion.signIn') }}
            </RouterLink>
          </div>
        </section>

        <section class="mb-8">
          <h2 class="text-lg font-semibold txt-primary mb-3">
            {{ $t('accountDeletion.dataTitle') }}
          </h2>
          <ul class="space-y-2 text-sm txt-secondary">
            <li v-for="(item, index) in dataItems" :key="index" class="flex items-start gap-2">
              <Icon
                icon="mdi:close-circle-outline"
                class="w-4 h-4 flex-shrink-0 mt-0.5 text-red-500"
              />
              <span>{{ item }}</span>
            </li>
          </ul>
          <p class="mt-3 text-xs txt-secondary">{{ $t('accountDeletion.retention') }}</p>
        </section>

        <section class="surface-chip rounded-lg p-4">
          <h2 class="text-base font-semibold txt-primary mb-2">
            {{ $t('accountDeletion.noAccessTitle') }}
          </h2>
          <p class="text-sm txt-secondary">
            {{ $t('accountDeletion.noAccessDesc') }}
          </p>
          <div class="mt-3 flex flex-wrap gap-4 text-sm">
            <a
              :href="config.branding.homepageUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
            >
              <Icon icon="mdi:web" class="w-4 h-4" />
              {{ $t('accountDeletion.visitWebsite') }}
            </a>
            <a
              :href="config.branding.privacyUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-2 txt-primary hover:text-[var(--brand)]"
            >
              <Icon icon="mdi:shield-account-outline" class="w-4 h-4" />
              {{ $t('accountDeletion.privacyPolicy') }}
            </a>
          </div>
        </section>
      </div>

      <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
        <BrandAttribution
          link-class="hover:underline opacity-75 hover:opacity-100 transition-opacity font-medium"
        />
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { SunIcon, MoonIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '@/composables/useTheme'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useConfigStore } from '@/stores/config'
import BrandAttribution from '@/components/BrandAttribution.vue'

const { t, locale, tm } = useI18n()
const themeStore = useTheme()
const config = useConfigStore()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const { logoSrc } = useBrandLogo(isDark)

const currentLanguage = computed(() => locale.value)

const steps = computed(() =>
  (tm('accountDeletion.steps') as string[]).map((_, i) => t(`accountDeletion.steps.${i}`))
)
const dataItems = computed(() =>
  (tm('accountDeletion.dataItems') as string[]).map((_, i) => t(`accountDeletion.dataItems.${i}`))
)

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
</script>
