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
          <div class="flex items-center gap-2 flex-wrap">
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
          <div v-if="emailKeyword && personalEmailAddress" class="mt-3 p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
            <p class="text-sm txt-primary">
              <CheckCircleIcon class="w-5 h-5 text-blue-500 inline mr-2" />
              <i18n-t keypath="config.inbound.yourEmailAddress" tag="span">
                <template #email>
                  <span class="font-medium font-mono text-blue-600 dark:text-blue-400">{{ personalEmailAddress }}</span>
                </template>
              </i18n-t>
            </p>
          </div>
          <div v-else class="mt-3 p-3 bg-orange-500/10 border border-orange-500/30 rounded-lg">
            <p class="text-sm txt-primary">
              <i18n-t keypath="config.inbound.noKeywordSet" />
            </p>
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
      
      <div class="p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
        <p class="text-sm txt-primary mb-3">
          {{ $t('config.inbound.apiDocumentationInfo') }}
        </p>
        <router-link
          to="/config/api-documentation"
          class="btn-primary inline-flex items-center gap-2"
        >
          <CommandLineIcon class="w-4 h-4" />
          {{ $t('config.inbound.viewApiDocumentation') }}
        </router-link>
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
  CheckCircleIcon
} from '@heroicons/vue/24/outline'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import {
  mockWhatsAppChannels,
  mockEmailChannels,
  mockAPIConfig,
  emailKeywordBase,
  emailKeywordDomain
} from '@/mocks/config'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useNotification } from '@/composables/useNotification'
import { profileApi } from '@/services/api/profileApi'

const { success, error } = useNotification()

const formData = ref({
  whatsappChannels: mockWhatsAppChannels,
  emailChannels: mockEmailChannels,
  apiConfig: mockAPIConfig,
  emailKeyword: '',
  personalEmailAddress: ''
})

const originalData = ref({
  whatsappChannels: mockWhatsAppChannels,
  emailChannels: mockEmailChannels,
  apiConfig: mockAPIConfig,
  emailKeyword: '',
  personalEmailAddress: ''
})

// Computed refs for template access
const whatsappChannels = computed(() => formData.value.whatsappChannels)
const emailChannels = computed(() => formData.value.emailChannels)
const apiConfig = computed(() => formData.value.apiConfig)
const emailKeyword = computed({
  get: () => formData.value.emailKeyword,
  set: (val: string) => formData.value.emailKeyword = val
})
const personalEmailAddress = computed(() => formData.value.personalEmailAddress)

const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData,
  originalData
)

let cleanupGuard: (() => void) | undefined

const loadEmailKeyword = async () => {
  try {
    const response = await profileApi.getEmailKeyword()
    if (response.success) {
      formData.value.emailKeyword = response.keyword || ''
      formData.value.personalEmailAddress = response.emailAddress
      originalData.value.emailKeyword = response.keyword || ''
      originalData.value.personalEmailAddress = response.emailAddress
    }
  } catch (err: any) {
    console.error('Failed to load email keyword:', err)
    // Don't show error on initial load, just log it
  }
}

onMounted(async () => {
  cleanupGuard = setupNavigationGuard()
  await loadEmailKeyword()
})

onUnmounted(() => {
  cleanupGuard?.()
})

const handleSave = saveChanges(async () => {
  try {
    // Save email keyword
    const keywordToSave = formData.value.emailKeyword.trim()
    const response = await profileApi.setEmailKeyword(keywordToSave)
    
    if (response.success) {
      formData.value.emailKeyword = response.keyword || ''
      formData.value.personalEmailAddress = response.emailAddress
      originalData.value.emailKeyword = response.keyword || ''
      originalData.value.personalEmailAddress = response.emailAddress
      success('Email keyword saved successfully')
    }
  } catch (err: any) {
    const errorMessage = err?.response?.data?.error || err?.message || 'Failed to save email keyword'
    error(errorMessage)
    throw err // Re-throw to prevent hasUnsavedChanges from being cleared
  }
})

const handleDiscard = () => {
  discardChanges()
}
</script>

