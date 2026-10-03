<template>
  <section id="appearance" class="space-y-6 scroll-mt-6">
    <div class="surface-card p-6" data-testid="section-language-settings">
      <h2 class="text-lg font-semibold txt-primary mb-2">
        {{ $t('settings.language.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-4">
        {{ $t('settings.language.description') }}
      </p>

      <div
        class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3"
        data-testid="grid-language-options"
      >
        <button
          v-for="lang in languages"
          :key="lang.value"
          type="button"
          :class="[
            'p-4 rounded-lg border-2 transition-all flex flex-col items-center gap-2',
            selectedLanguage === lang.value
              ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
              : 'border-light-border/30 dark:border-dark-border/20 hover-surface',
          ]"
          :data-testid="`btn-language-${lang.value}`"
          :data-language="lang.value"
          @click="selectLanguage(lang.value)"
        >
          <span class="text-2xl" aria-hidden="true">{{ lang.flag }}</span>
          <span class="text-sm font-medium txt-primary">{{ lang.label }}</span>
        </button>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-theme-settings">
      <h2 class="text-lg font-semibold txt-primary mb-2">{{ $t('settings.theme.title') }}</h2>
      <p class="txt-secondary text-sm mb-4">{{ $t('settings.theme.description') }}</p>

      <div class="grid grid-cols-3 gap-3">
        <button
          type="button"
          :class="[
            'p-4 rounded-lg border-2 transition-all',
            theme === 'light'
              ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
              : 'border-light-border/30 dark:border-dark-border/20 hover-surface',
          ]"
          data-testid="btn-theme-light"
          @click="setTheme('light')"
        >
          <SunIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
          <div class="text-sm font-medium txt-primary text-center">
            {{ $t('settings.theme.light') }}
          </div>
        </button>

        <button
          type="button"
          :class="[
            'p-4 rounded-lg border-2 transition-all',
            theme === 'dark'
              ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
              : 'border-light-border/30 dark:border-dark-border/20 hover-surface',
          ]"
          data-testid="btn-theme-dark"
          @click="setTheme('dark')"
        >
          <MoonIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
          <div class="text-sm font-medium txt-primary text-center">
            {{ $t('settings.theme.dark') }}
          </div>
        </button>

        <button
          type="button"
          :class="[
            'p-4 rounded-lg border-2 transition-all',
            theme === 'system'
              ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
              : 'border-light-border/30 dark:border-dark-border/20 hover-surface',
          ]"
          data-testid="btn-theme-system"
          @click="setTheme('system')"
        >
          <ComputerDesktopIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
          <div class="text-sm font-medium txt-primary text-center">
            {{ $t('settings.theme.system') }}
          </div>
        </button>
      </div>
    </div>

    <ProfileTimezoneField v-if="account" v-show="!profileLoadFailed" />
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { languageOptions, setLocale, type SupportedLanguage } from '@/i18n'
import { useTheme } from '@/composables/useTheme'
import { injectProfileSettingsOptional } from '@/composables/useProfileSettings'
import ProfileTimezoneField from '@/components/settings/ProfileTimezoneField.vue'
import { SunIcon, MoonIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const account = injectProfileSettingsOptional()
const profileLoadFailed = computed(() => account?.profileLoadFailed.value ?? false)
const { theme, setTheme } = useTheme()
const { locale } = useI18n()

const languages = languageOptions
const selectedLanguage = computed(() => locale.value)

const selectLanguage = async (value: string) => {
  await setLocale(value as SupportedLanguage)
}
</script>
