<template>
  <div class="space-y-6" data-testid="page-config-inbound">
    <div class="mb-8" data-testid="section-header">
      <h1 class="text-2xl font-semibold txt-primary mb-2">
        {{ $t('config.inbound.title') }}
      </h1>
      <p class="txt-secondary">
        {{ $t('config.inbound.description') }}
      </p>
    </div>

    <div class="surface-card p-6" data-testid="section-whatsapp">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <DevicePhoneMobileIcon class="w-5 h-5 text-green-500" />
        {{ $t('config.inbound.whatsappChannels') }}
      </h3>
      
      <div class="space-y-3">
        <div
          v-for="channel in whatsappChannels"
          :key="channel.id"
          class="flex items-center justify-between p-3 surface-chip rounded-lg border border-light-border/30 dark:border-dark-border/20"
          data-testid="item-whatsapp-channel"
        >
          <div class="flex items-center gap-3">
            <DevicePhoneMobileIcon class="w-5 h-5 text-green-500" />
            <span class="txt-primary font-medium">{{ channel.number }}</span>
            <span class="pill pill--active text-xs">{{ channel.handling }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-email">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <EnvelopeIcon class="w-5 h-5 text-blue-500" />
        {{ $t('config.inbound.emailChannels') }}
      </h3>

      <div class="space-y-4">
        <div
          v-for="channel in emailChannels"
          :key="channel.id"
          class="flex items-center justify-between p-3 surface-chip rounded-lg border border-light-border/30 dark:border-dark-border/20"
          data-testid="item-email-channel"
        >
          <div class="flex items-center gap-3">
            <EnvelopeIcon class="w-5 h-5 text-blue-500" />
            <span class="txt-primary font-medium">{{ channel.email }}</span>
            <span class="pill pill--active text-xs">{{ channel.handling }}</span>
          </div>
        </div>

        <div class="mt-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
          <p class="text-sm txt-secondary mb-3">
            {{ $t('config.inbound.addKeyword') }}
          </p>
          <div class="flex items-center gap-2">
            <span class="txt-primary">{{ emailKeywordBase }}</span>
            <input
              v-model="emailKeyword"
              type="text"
              class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] max-w-xs"
              :placeholder="$t('config.inbound.keywordPlaceholder')"
              data-testid="input-email-keyword"
            />
            <span class="txt-primary">{{ emailKeywordDomain }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-api">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <CommandLineIcon class="w-5 h-5 text-purple-500" />
        {{ $t('config.inbound.apiChannel') }}
      </h3>

      <p class="txt-secondary mb-4">
        {{ $t('config.inbound.apiDescription') }}
      </p>
      <div class="bg-black/90 dark:bg-black/50 rounded-lg p-4 font-mono text-sm text-green-400">
        {{ apiConfig.endpoint }}
      </div>

      <p class="txt-secondary mt-4 mb-2">
        {{ $t('config.inbound.exampleCurl') }}
      </p>
      <div class="bg-black/90 dark:bg-black/50 rounded-lg p-4 font-mono text-xs text-green-400 overflow-x-auto">
        <pre>curl -X POST {{ apiConfig.endpoint }} \
-H "Authorization: Bearer YOUR_API_KEY" \
-H "Content-Type: application/json" \
-d '{"number": "1234567890", "message": "Hello, world!"}'</pre>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-widget">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <GlobeAltIcon class="w-5 h-5 text-cyan-500" />
        {{ $t('config.inbound.webWidget') }}
      </h3>

      <p class="txt-secondary mb-4">
        {{ $t('config.inbound.widgetDescription') }}
      </p>

      <div class="flex items-center gap-3">
        <input
          v-model="widgetDomain"
          type="text"
          class="flex-1 px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('config.inbound.domainPlaceholder')"
          data-testid="input-widget-domain"
        />
        <button
          @click="toggleWidget"
          :class="[
            'px-6 py-2 rounded-lg transition-colors flex items-center gap-2',
            widgetConfig.isActive 
              ? 'bg-red-500 text-white hover:bg-red-600' 
              : 'btn-primary'
          ]"
          data-testid="btn-widget-toggle"
        >
          <component :is="widgetConfig.isActive ? XMarkIcon : CheckIcon" class="w-5 h-5" />
          {{ widgetConfig.isActive ? $t('config.inbound.widgetActive') : $t('config.inbound.activateWidget') }}
        </button>
      </div>

      <div
        v-if="widgetConfig.isActive"
        class="mt-4 p-4 bg-cyan-500/10 border border-cyan-500/30 rounded-lg"
        data-testid="section-widget-status"
      >
        <p class="text-sm txt-primary">
          <CheckCircleIcon class="w-5 h-5 text-cyan-500 inline mr-2" />
          Widget is active for: <span class="font-medium">{{ widgetDomain }}</span>
        </p>
      </div>
    </div>

    <div class="h-20"></div>

    <UnsavedChangesBar
      :show="hasUnsavedChanges"
      @save="handleSave"
      @discard="handleDiscard"
      data-testid="comp-unsaved-bar"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import {
  DevicePhoneMobileIcon,
  EnvelopeIcon,
  CommandLineIcon,
  GlobeAltIcon,
  CheckIcon,
  XMarkIcon,
  CheckCircleIcon
} from '@heroicons/vue/24/outline'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import {
  mockWhatsAppChannels,
  mockEmailChannels,
  mockAPIConfig,
  mockWidgetConfig,
  emailKeywordBase,
  emailKeywordDomain
} from '@/mocks/config'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const formData = ref({
  whatsappChannels: mockWhatsAppChannels,
  emailChannels: mockEmailChannels,
  apiConfig: mockAPIConfig,
  widgetConfig: { ...mockWidgetConfig },
  emailKeyword: '',
  widgetDomain: ''
})

const originalData = ref({
  whatsappChannels: mockWhatsAppChannels,
  emailChannels: mockEmailChannels,
  apiConfig: mockAPIConfig,
  widgetConfig: { ...mockWidgetConfig },
  emailKeyword: '',
  widgetDomain: ''
})

// Computed refs for template access
const whatsappChannels = computed(() => formData.value.whatsappChannels)
const emailChannels = computed(() => formData.value.emailChannels)
const apiConfig = computed(() => formData.value.apiConfig)
const widgetConfig = computed(() => formData.value.widgetConfig)
const emailKeyword = computed({
  get: () => formData.value.emailKeyword,
  set: (val) => formData.value.emailKeyword = val
})
const widgetDomain = computed({
  get: () => formData.value.widgetDomain,
  set: (val) => formData.value.widgetDomain = val
})

const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData,
  originalData
)

let cleanupGuard: (() => void) | undefined

onMounted(() => {
  cleanupGuard = setupNavigationGuard()
})

onUnmounted(() => {
  cleanupGuard?.()
})

const toggleWidget = () => {
  if (!formData.value.widgetConfig.isActive && formData.value.widgetDomain.trim()) {
    formData.value.widgetConfig.isActive = true
    formData.value.widgetConfig.activeDomain = formData.value.widgetDomain
  } else {
    formData.value.widgetConfig.isActive = false
    formData.value.widgetConfig.activeDomain = ''
    formData.value.widgetDomain = ''
  }
}

const handleSave = saveChanges(() => {
  // API call would go here
  console.log('Save inbound configuration', formData.value)
})

const handleDiscard = () => {
  discardChanges()
}
</script>

