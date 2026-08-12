<template>
  <div class="flex flex-col md:flex-row gap-6">
    <!-- Controls -->
    <div class="flex-1 space-y-5">
      <!-- Primary Color -->
      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('widgets.createWizard.appearance.colorLabel') }}
        </label>
        <div class="flex items-center gap-3">
          <input
            v-model="primaryColor"
            type="color"
            class="w-12 h-10 rounded-lg cursor-pointer border border-light-border/30 dark:border-dark-border/20 bg-transparent"
            data-testid="input-primary-color"
          />
          <input
            v-model="primaryColor"
            type="text"
            class="w-28 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          />
        </div>
      </div>

      <!-- Icon Color -->
      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('widgets.createWizard.appearance.iconColorLabel') }}
        </label>
        <div class="flex items-center gap-3">
          <input
            v-model="iconColor"
            type="color"
            class="w-12 h-10 rounded-lg cursor-pointer border border-light-border/30 dark:border-dark-border/20 bg-transparent"
            data-testid="input-icon-color"
          />
          <input
            v-model="iconColor"
            type="text"
            class="w-28 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          />
        </div>
      </div>

      <!-- Theme -->
      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('widgets.createWizard.appearance.themeLabel') }}
        </label>
        <div class="flex gap-2">
          <button
            v-for="theme in ['light', 'dark'] as const"
            :key="theme"
            type="button"
            :class="[
              'flex-1 px-4 py-2.5 rounded-lg border text-sm font-medium transition-colors',
              defaultTheme === theme
                ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)] txt-brand'
                : 'border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary',
            ]"
            @click="defaultTheme = theme"
          >
            {{
              theme === 'light'
                ? $t('widgets.createWizard.appearance.themeLight')
                : $t('widgets.createWizard.appearance.themeDark')
            }}
          </button>
        </div>
      </div>

      <!-- Position -->
      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('widgets.createWizard.appearance.positionLabel') }}
        </label>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="option in positionOptions"
            :key="option.value"
            type="button"
            :class="[
              'px-3 py-2 rounded-lg border text-xs font-medium transition-colors',
              position === option.value
                ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)] txt-brand'
                : 'border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary',
            ]"
            @click="position = option.value"
          >
            {{ option.label }}
          </button>
        </div>
      </div>
    </div>

    <!-- Live preview -->
    <div class="md:w-64 flex-shrink-0">
      <p class="text-sm font-medium txt-primary mb-2">
        {{ $t('widgets.createWizard.appearance.previewLabel') }}
      </p>
      <div
        class="relative h-64 rounded-xl border border-light-border/30 dark:border-dark-border/20 overflow-hidden"
        :style="{ backgroundColor: defaultTheme === 'dark' ? '#1f2430' : '#f4f5f7' }"
      >
        <!-- Mini chat window -->
        <div
          class="absolute left-4 right-4 top-4 rounded-lg shadow-lg overflow-hidden"
          :style="{ backgroundColor: defaultTheme === 'dark' ? '#2a3040' : '#ffffff' }"
        >
          <div class="px-3 py-2 flex items-center gap-2" :style="{ backgroundColor: primaryColor }">
            <Icon
              icon="heroicons:chat-bubble-left-right"
              class="w-4 h-4"
              :style="{ color: iconColor }"
            />
            <span class="text-xs font-medium truncate" :style="{ color: iconColor }">
              {{ widgetName || $t('widgets.createWizard.appearance.previewTitle') }}
            </span>
          </div>
          <div class="p-3">
            <div
              class="rounded-lg px-3 py-2 text-xs max-w-[85%]"
              :style="{
                backgroundColor: defaultTheme === 'dark' ? '#3a4152' : '#eef0f3',
                color: defaultTheme === 'dark' ? '#e5e9f0' : '#39404e',
              }"
            >
              {{ $t('widgets.createWizard.appearance.previewGreeting') }}
            </div>
          </div>
        </div>
        <!-- Launcher bubble positioned per selection -->
        <div
          class="absolute w-11 h-11 rounded-full shadow-xl flex items-center justify-center transition-all duration-300"
          :class="bubblePositionClass"
          :style="{ backgroundColor: primaryColor }"
        >
          <Icon
            icon="heroicons:chat-bubble-left-right"
            class="w-5 h-5"
            :style="{ color: iconColor }"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { WidgetConfig } from '@/services/api/widgetsApi'

type WidgetPosition = NonNullable<WidgetConfig['position']>

defineProps<{
  widgetName: string
}>()

const primaryColor = defineModel<string>('primaryColor', { required: true })
const iconColor = defineModel<string>('iconColor', { required: true })
const defaultTheme = defineModel<'light' | 'dark'>('defaultTheme', { required: true })
const position = defineModel<WidgetPosition>('position', { required: true })

const { t } = useI18n()

const positionOptions = computed((): { value: WidgetPosition; label: string }[] => [
  { value: 'bottom-right', label: t('widgets.createWizard.appearance.positions.bottomRight') },
  { value: 'bottom-left', label: t('widgets.createWizard.appearance.positions.bottomLeft') },
  { value: 'top-right', label: t('widgets.createWizard.appearance.positions.topRight') },
  { value: 'top-left', label: t('widgets.createWizard.appearance.positions.topLeft') },
])

const bubblePositionClass = computed(() => {
  switch (position.value) {
    case 'bottom-left':
      return 'bottom-3 left-3'
    case 'top-right':
      return 'top-3 right-3'
    case 'top-left':
      return 'top-3 left-3'
    default:
      return 'bottom-3 right-3'
  }
})
</script>
