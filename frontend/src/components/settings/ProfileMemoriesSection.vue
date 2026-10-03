<template>
  <fieldset :disabled="!profileLoaded" class="border-0 p-0 m-0 min-w-0 disabled:opacity-60">
    <section
      id="memories"
      class="surface-card rounded-lg p-6 scroll-mt-6 transition-all duration-500"
      :class="{ 'ring-4 ring-brand-500/50 shadow-2xl': shouldHighlight }"
      data-testid="section-memories-settings"
    >
      <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
        <Icon icon="mdi:brain" class="w-5 h-5" />
        {{ $t('profile.memories.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-6">
        {{ $t('profile.memories.subtitle') }}
      </p>

      <div
        class="flex items-start justify-between gap-4 p-4 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20"
      >
        <div class="min-w-0">
          <p class="txt-primary font-medium">
            {{ $t('profile.memories.toggleLabel') }}
          </p>
          <p class="txt-secondary text-sm mt-1">
            {{
              formData.memoriesEnabled
                ? $t('profile.memories.enabledHint')
                : $t('profile.memories.disabledHint')
            }}
          </p>
        </div>

        <label class="relative inline-flex items-center cursor-pointer select-none">
          <input v-model="formData.memoriesEnabled" type="checkbox" class="sr-only" />
          <div
            class="w-11 h-6 rounded-full transition-colors"
            :class="formData.memoriesEnabled ? 'bg-[var(--brand)]' : 'bg-gray-300 dark:bg-gray-700'"
          />
          <div
            class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white transition-transform shadow"
            :class="formData.memoriesEnabled ? 'translate-x-5' : 'translate-x-0'"
          />
        </label>
      </div>
    </section>
  </fieldset>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { formData, profileLoaded, shouldHighlight } = injectProfileSettings()
</script>
