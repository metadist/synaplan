<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
        <Cog6ToothIcon class="w-5 h-5" />
        {{ $t('widget.configuration') }}
      </h2>
      <button
        @click="$emit('cancel')"
        class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
        :aria-label="$t('widget.closeEditor')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 border-b border-light-border/30 dark:border-dark-border/20">
      <button
        v-for="tab in tabs"
        :key="tab"
        @click="activeTab = tab"
        :class="[
          'px-4 py-3 font-medium text-sm transition-colors relative',
          activeTab === tab
            ? 'txt-primary'
            : 'txt-secondary hover:txt-primary'
        ]"
      >
        {{ $t(`widget.tabs.${tab}`) }}
        <div
          v-if="activeTab === tab"
          class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--brand)]"
        ></div>
      </button>
    </div>

    <!-- Appearance Tab -->
    <div v-if="activeTab === 'appearance'" class="surface-card p-6">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.position') }}
          </label>
          <select
            v-model="config.position"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option v-for="pos in positions" :key="pos.value" :value="pos.value">
              {{ pos.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.position') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.defaultTheme') }}
          </label>
          <select
            v-model="config.defaultTheme"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option value="light">{{ $t('widget.options.lightMode') }}</option>
            <option value="dark">{{ $t('widget.options.darkMode') }}</option>
          </select>
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.defaultTheme') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.primaryColor') }}
          </label>
          <input
            v-model="config.primaryColor"
            type="color"
            class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
          />
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.primaryColor') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.iconColor') }}
          </label>
          <input
            v-model="config.iconColor"
            type="color"
            class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
          />
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.iconColor') }}</p>
        </div>
      </div>
    </div>

    <!-- Behavior Tab -->
    <div v-else-if="activeTab === 'behavior'" class="surface-card p-6 space-y-6">
      <div class="grid grid-cols-1 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.autoMessage') }}
          </label>
          <input
            v-model="config.autoMessage"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('widget.placeholders.autoMessage')"
          />
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.autoMessage') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.aiPrompt') }}
          </label>
          <select
            v-model="config.aiPrompt"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option v-for="prompt in aiPrompts" :key="prompt.value" :value="prompt.value">
              {{ prompt.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.aiPrompt') }}</p>
        </div>

        <div>
          <label class="flex items-center gap-2 cursor-pointer">
            <input
              v-model="config.autoOpen"
              type="checkbox"
              class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
            />
            <span class="text-sm txt-primary">{{ $t('widget.fields.autoOpen') }}</span>
          </label>
          <p class="text-xs txt-secondary mt-2 ml-7">{{ $t('widget.hints.autoOpen') }}</p>
        </div>
      </div>
    </div>

    <!-- Advanced Tab -->
    <div v-else-if="activeTab === 'advanced'" class="surface-card p-6 space-y-6">
      <div class="grid grid-cols-1 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.integrationType') }}
          </label>
          <select
            v-model="config.integrationType"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option v-for="type in integrationTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.integrationType') }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('widget.fields.previewUrl') }}
          </label>
          <input
            v-model="config.previewUrl"
            type="url"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            placeholder="https://example.com"
          />
          <p class="text-xs txt-secondary mt-1">{{ $t('widget.hints.previewUrl') }}</p>
        </div>
      </div>

      <!-- Embed Code (only if widget saved) -->
      <div v-if="showCode" class="border-t border-light-border/30 dark:border-dark-border/20 pt-6">
        <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
          <CodeBracketIcon class="w-5 h-5" />
          {{ $t('widget.embedCode') }}
        </h3>
        <div>
          <div class="relative">
            <pre class="bg-black/5 dark:bg-white/5 p-4 rounded-lg overflow-x-auto text-sm font-mono txt-primary">{{ embedCode }}</pre>
            <button
              @click="copyCode"
              class="absolute top-2 right-2 px-3 py-1 rounded bg-[var(--brand)] text-white text-xs hover:bg-[var(--brand-hover)] transition-colors"
            >
              {{ copied ? $t('widget.copied') : $t('widget.copyCode') }}
            </button>
          </div>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('widget.embedCodeHint') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Buttons are now in UnsavedChangesBar -->
    <div class="h-20"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Cog6ToothIcon, CodeBracketIcon } from '@heroicons/vue/24/outline'
import type { WidgetConfig } from '@/mocks/widgets'
import { integrationTypes, positions, aiPrompts, generateEmbedCode } from '@/mocks/widgets'

interface Props {
  modelValue: WidgetConfig
  widgetId?: string
  userId?: string
  showCode?: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: WidgetConfig]
  save: [config: WidgetConfig]
  cancel: []
  preview: []
}>()

const config = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

const tabs = ['appearance', 'behavior', 'advanced']
const activeTab = ref('appearance')
const copied = ref(false)

const embedCode = computed(() => {
  if (props.widgetId && props.userId) {
    return generateEmbedCode(props.widgetId, props.userId)
  }
  return '// Save widget to generate code'
})

const copyCode = async () => {
  try {
    await navigator.clipboard.writeText(embedCode.value)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}
</script>

