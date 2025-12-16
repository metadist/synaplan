<template>
  <!-- Fullscreen Modal Overlay -->
  <div
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
    data-testid="modal-widget-editor"
  >
    <div
      class="surface-card rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto shadow-2xl"
      data-testid="section-editor-shell"
    >
      <!-- Header -->
      <div
        class="sticky top-0 surface-card border-b border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-between"
        data-testid="section-header"
      >
        <div>
          <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:cog-6-tooth" class="w-6 h-6 text-[var(--brand)]" />
            {{ isEdit ? $t('widgets.editWidget') : $t('widgets.createWidget') }}
          </h2>
          <p class="text-sm txt-secondary mt-1">
            {{ isEdit ? widget?.name : $t('widgets.createDescription') }}
          </p>
        </div>
        <button
          class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center"
          :aria-label="$t('common.close')"
          data-testid="btn-close"
          @click="$emit('close')"
        >
          <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
        </button>
      </div>

      <!-- Content -->
      <div class="p-6 space-y-6" data-testid="section-content">
        <!-- Basic Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:document-text" class="w-5 h-5" />
            {{ $t('widgets.basicSettings') }}
          </h3>

          <!-- Widget Name -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.widgetName') }}
            </label>
            <input
              v-model="formData.name"
              type="text"
              :placeholder="$t('widgets.widgetNamePlaceholder')"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-widget-name"
            />
          </div>

          <!-- Task Prompt Selection (nur bei Create) -->
          <div v-if="!isEdit">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.taskPrompt') }}
            </label>
            <select
              v-model="formData.taskPromptTopic"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-task-prompt"
            >
              <option value="">{{ $t('widgets.selectTaskPrompt') }}</option>
              <option v-for="prompt in taskPrompts" :key="prompt.topic" :value="prompt.topic">
                {{ prompt.name }}
              </option>
            </select>
            <p class="text-xs txt-secondary mt-1.5">
              {{ $t('widgets.taskPromptHelp') }}
            </p>
          </div>

          <!-- Status (nur bei Edit) -->
          <div v-if="isEdit">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.status') }}
            </label>
            <div class="flex items-center gap-4">
              <label class="flex items-center gap-2 cursor-pointer">
                <input
                  v-model="formData.status"
                  type="radio"
                  value="active"
                  class="w-4 h-4 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                />
                <span class="text-sm txt-primary">{{ $t('widgets.active') }}</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer">
                <input
                  v-model="formData.status"
                  type="radio"
                  value="inactive"
                  class="w-4 h-4 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                />
                <span class="text-sm txt-primary">{{ $t('widgets.inactive') }}</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Appearance Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:paint-brush" class="w-5 h-5" />
            {{ $t('widgets.appearance') }}
          </h3>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Position -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.position') }}
              </label>
              <select
                v-model="formData.config.position"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-position"
              >
                <option value="bottom-right">{{ $t('widgets.bottomRight') }}</option>
                <option value="bottom-left">{{ $t('widgets.bottomLeft') }}</option>
                <option value="top-right">{{ $t('widgets.topRight') }}</option>
                <option value="top-left">{{ $t('widgets.topLeft') }}</option>
              </select>
            </div>

            <!-- Default Theme -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.defaultTheme') }}
              </label>
              <select
                v-model="formData.config.defaultTheme"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-theme"
              >
                <option value="light">{{ $t('widgets.light') }}</option>
                <option value="dark">{{ $t('widgets.dark') }}</option>
              </select>
            </div>

            <!-- Primary Color -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.primaryColor') }}
              </label>
              <input
                v-model="formData.config.primaryColor"
                type="color"
                class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                data-testid="input-primary-color"
              />
            </div>

            <!-- Icon Color -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.iconColor') }}
              </label>
              <input
                v-model="formData.config.iconColor"
                type="color"
                class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                data-testid="input-icon-color"
              />
            </div>
          </div>

          <!-- Button Icon Selection -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-3">
              {{ $t('widgets.buttonIcon') }}
            </label>

            <!-- Predefined Icons -->
            <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-4">
              <button
                v-for="icon in predefinedIcons"
                :key="icon.value"
                type="button"
                :class="[
                  'p-4 rounded-lg border-2 transition-all flex flex-col items-center gap-2',
                  formData.config.buttonIcon === icon.value && !formData.config.buttonIconUrl
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
                ]"
                :title="icon.label"
                data-testid="btn-icon"
                @click="selectIcon(icon.value)"
              >
                <div v-html="getIconPreview(icon.value)"></div>
                <span class="text-xs txt-secondary">{{ icon.label }}</span>
              </button>
            </div>

            <!-- Custom Icon Upload -->
            <div class="mt-4">
              <label class="block text-sm font-medium txt-secondary mb-2">
                {{ $t('widgets.customIcon') }}
              </label>
              <div class="flex gap-3">
                <input
                  ref="iconUploadInput"
                  type="file"
                  accept="image/svg+xml,image/png,image/jpeg,image/gif,image/webp"
                  class="hidden"
                  data-testid="input-icon-upload"
                  @change="handleIconUpload"
                />
                <button
                  type="button"
                  class="flex-1 px-4 py-2 border-2 border-dashed border-light-border/30 dark:border-dark-border/20 rounded-lg hover:border-[var(--brand)]/50 transition-colors txt-secondary hover:txt-primary flex items-center justify-center gap-2"
                  data-testid="btn-upload-icon"
                  @click="triggerIconUpload"
                >
                  <Icon icon="heroicons:arrow-up-tray" class="w-5 h-5" />
                  {{
                    formData.config.buttonIconUrl
                      ? $t('widgets.changeIcon')
                      : $t('widgets.uploadIcon')
                  }}
                </button>
                <button
                  v-if="formData.config.buttonIconUrl"
                  type="button"
                  class="px-4 py-2 bg-red-500/10 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-500/20 transition-colors"
                  data-testid="btn-remove-icon"
                  @click="removeCustomIcon"
                >
                  <Icon icon="heroicons:trash" class="w-5 h-5" />
                </button>
              </div>
              <div
                v-if="formData.config.buttonIconUrl"
                class="mt-3 p-3 bg-green-500/10 rounded-lg flex items-center gap-3"
              >
                <img
                  :src="formData.config.buttonIconUrl"
                  alt="Custom Icon"
                  class="w-10 h-10 object-contain"
                />
                <span class="text-sm text-green-700 dark:text-green-400">{{
                  $t('widgets.customIconActive')
                }}</span>
              </div>
              <p class="text-xs txt-secondary mt-2">{{ $t('widgets.customIconHint') }}</p>
            </div>
          </div>
        </div>

        <!-- Behavior Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:adjustments-horizontal" class="w-5 h-5" />
            {{ $t('widgets.behavior') }}
          </h3>

          <!-- Auto Open -->
          <div class="flex items-center justify-between p-4 surface-chip rounded-lg">
            <div>
              <p class="font-medium txt-primary">{{ $t('widgets.autoOpen') }}</p>
              <p class="text-xs txt-secondary mt-1">{{ $t('widgets.autoOpenHelp') }}</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input
                v-model="formData.config.autoOpen"
                type="checkbox"
                class="sr-only peer"
                data-testid="input-auto-open"
              />
              <div
                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"
              ></div>
            </label>
          </div>

          <!-- Auto Message -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.autoMessage') }}
            </label>
            <textarea
              v-model="formData.config.autoMessage"
              rows="2"
              :placeholder="$t('widgets.autoMessagePlaceholder')"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
              data-testid="input-auto-message"
            />
          </div>

          <div class="surface-chip rounded-lg p-4 space-y-3">
            <div class="flex items-center justify-between">
              <div>
                <p class="font-medium txt-primary">{{ $t('widgets.allowFileUpload') }}</p>
                <p class="text-xs txt-secondary mt-1">{{ $t('widgets.allowFileUploadHelp') }}</p>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input
                  v-model="formData.config.allowFileUpload"
                  type="checkbox"
                  class="sr-only peer"
                  data-testid="input-allow-upload"
                />
                <div
                  class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"
                ></div>
              </label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium txt-primary mb-1">
                  {{ $t('widgets.fileUploadLimit') }}
                </label>
                <input
                  v-model.number="formData.config.fileUploadLimit"
                  type="number"
                  min="0"
                  max="20"
                  :disabled="!formData.config.allowFileUpload"
                  class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
                  data-testid="input-file-limit"
                />
                <p class="text-xs txt-secondary mt-1.5">{{ $t('widgets.fileUploadLimitHelp') }}</p>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Message Limit -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.messageLimit') }}
              </label>
              <input
                v-model.number="formData.config.messageLimit"
                type="number"
                min="1"
                max="100"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-message-limit"
              />
              <p class="text-xs txt-secondary mt-1.5">{{ $t('widgets.messageLimitHelp') }}</p>
            </div>

            <!-- Max File Size -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.maxFileSize') }} (MB)
              </label>
              <input
                v-model.number="formData.config.maxFileSize"
                type="number"
                min="1"
                max="50"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-max-file-size"
              />
            </div>
          </div>
        </div>

        <!-- Allowed Domains -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:shield-check" class="w-5 h-5" />
            {{ $t('widgets.allowedDomainsTitle') }}
          </h3>
          <p class="text-sm txt-secondary">
            {{ $t('widgets.allowedDomainsHelp') }}
          </p>

          <div class="flex flex-col sm:flex-row gap-2">
            <input
              v-model="newAllowedDomain"
              type="text"
              :placeholder="$t('widgets.allowedDomainsPlaceholder')"
              class="flex-1 px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              autocomplete="off"
              @keydown.enter.prevent="addAllowedDomain"
            />
            <button
              class="btn-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
              @click="addAllowedDomain"
            >
              <Icon icon="heroicons:plus" class="w-4 h-4" />
              {{ $t('widgets.allowedDomainsAdd') }}
            </button>
          </div>

          <p v-if="allowedDomainError" class="text-xs text-red-500 dark:text-red-400">
            {{ allowedDomainError }}
          </p>

          <div v-if="allowedDomainsList.length > 0" class="flex flex-wrap gap-2">
            <span
              v-for="domain in allowedDomainsList"
              :key="domain"
              :class="[
                'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border transition-colors',
                isLocalTestingDomain(domain)
                  ? 'bg-red-500/10 text-red-600 dark:text-red-300 border-red-500/40'
                  : 'bg-[var(--brand-alpha-light)] txt-primary border-[var(--brand)]/20',
              ]"
              :title="isLocalTestingDomain(domain) ? $t('widgets.localhostTooltip') : undefined"
            >
              <Icon
                v-if="isLocalTestingDomain(domain)"
                icon="heroicons:exclamation-triangle"
                class="w-3.5 h-3.5 text-red-500 dark:text-red-300"
              />
              {{ domain }}
              <button
                class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                :aria-label="$t('widgets.removeDomain', { domain })"
                @click="removeAllowedDomain(domain)"
              >
                <Icon icon="heroicons:x-mark" class="w-3 h-3" />
              </button>
            </span>
          </div>
          <p v-else class="text-xs txt-secondary">
            {{ $t('widgets.allowedDomainsEmpty') }}
          </p>

          <div
            v-if="hasLocalTestingDomain"
            class="mt-3 p-3 rounded-lg border border-red-500/30 bg-red-500/10 flex items-start gap-2 text-red-600 dark:text-red-300"
          >
            <Icon icon="heroicons:shield-exclamation" class="w-5 h-5 flex-shrink-0 mt-0.5" />
            <div>
              <p class="text-sm font-semibold">
                {{ $t('widgets.localhostWarningTitle') }}
              </p>
              <p class="text-xs mt-1">
                {{ $t('widgets.localhostWarningDescription') }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div
        class="sticky bottom-0 surface-card border-t border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-end gap-3"
      >
        <button
          class="px-6 py-2.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary font-medium"
          @click="$emit('close')"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          :disabled="!canSave"
          class="px-6 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          @click="handleSave"
        >
          {{ isEdit ? $t('common.save') : $t('common.create') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Icon } from '@iconify/vue'
import type { Widget, WidgetConfig } from '@/services/api/widgetsApi'
import { promptsApi } from '@/services/api/promptsApi'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'

interface Props {
  widget?: Widget | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
  save: [data: any]
}>()

const isEdit = computed(() => !!props.widget)

const taskPrompts = ref<any[]>([])
const { t } = useI18n()
const { error, success } = useNotification()

const MAX_ALLOWED_DOMAINS = 20
const newAllowedDomain = ref('')
const allowedDomainError = ref<string | null>(null)

type WidgetEditorConfig = WidgetConfig & { allowedDomains: string[] }

const sanitizeDomainInput = (value: string): string | null => {
  if (!value) return null
  let normalized = value.trim().toLowerCase()
  if (!normalized) return null
  normalized = normalized.replace(/^https?:\/\//, '')
  normalized = normalized.replace(/^\/\//, '')
  const remainder = normalized.split(/[/?#]/)[0] || ''
  if (!remainder) return null
  const domainPattern = /^(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d+)?$/
  if (!domainPattern.test(remainder)) {
    return null
  }
  return remainder
}

const sanitizeDomainList = (domains: unknown): string[] => {
  if (!Array.isArray(domains)) {
    return []
  }
  const sanitized: string[] = []
  domains.forEach((value) => {
    if (typeof value !== 'string') return
    const normalized = sanitizeDomainInput(value)
    if (normalized && !sanitized.includes(normalized)) {
      sanitized.push(normalized)
    }
  })
  return sanitized
}

const pushAllowedDomain = (value: string) => {
  const sanitized = sanitizeDomainInput(value)
  if (!sanitized) return
  const current = formData.value.config.allowedDomains ?? []
  if (!current.includes(sanitized)) {
    formData.value.config.allowedDomains = [...current, sanitized]
  }
}

const addAllowedDomain = () => {
  allowedDomainError.value = null

  const current = formData.value.config.allowedDomains ?? []

  if (current.length >= MAX_ALLOWED_DOMAINS) {
    allowedDomainError.value = t('widgets.allowedDomainsLimit', { max: MAX_ALLOWED_DOMAINS })
    return
  }

  const sanitized = sanitizeDomainInput(newAllowedDomain.value)
  if (!sanitized) {
    allowedDomainError.value = t('widgets.invalidDomain')
    return
  }

  if (current.includes(sanitized)) {
    allowedDomainError.value = t('widgets.domainAlreadyAdded')
    return
  }

  formData.value.config.allowedDomains = [...current, sanitized]
  newAllowedDomain.value = ''
}

const removeAllowedDomain = (domain: string) => {
  const current = formData.value.config.allowedDomains ?? []
  formData.value.config.allowedDomains = current.filter((item) => item !== domain)
}

watch(newAllowedDomain, () => {
  if (allowedDomainError.value) {
    allowedDomainError.value = null
  }
})

// Icon Selection
const iconUploadInput = ref<HTMLInputElement | null>(null)

const predefinedIcons = [
  { value: 'chat', label: t('widgets.icons.chat') },
  { value: 'headset', label: t('widgets.icons.headset') },
  { value: 'help', label: t('widgets.icons.help') },
  { value: 'robot', label: t('widgets.icons.robot') },
  { value: 'message', label: t('widgets.icons.message') },
  { value: 'support', label: t('widgets.icons.support') },
]

const getIconPreview = (iconType: string): string => {
  const iconColor = formData.value.config.iconColor || '#ffffff'
  const icons: Record<string, string> = {
    chat: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>`,
    headset: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
      <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
    </svg>`,
    help: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <circle cx="12" cy="12" r="10"></circle>
      <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
      <line x1="12" y1="17" x2="12.01" y2="17"></line>
    </svg>`,
    robot: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <circle cx="12" cy="5" r="2"></circle>
      <path d="M12 7v4"></path>
      <line x1="8" y1="16" x2="8" y2="16"></line>
      <line x1="16" y1="16" x2="16" y2="16"></line>
    </svg>`,
    message: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
    </svg>`,
    support: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <circle cx="12" cy="12" r="10"></circle>
      <path d="M12 16v-4"></path>
      <path d="M12 8h.01"></path>
    </svg>`,
  }
  return icons[iconType] || icons.chat
}

const selectIcon = async (iconValue: string) => {
  formData.value.config.buttonIcon = iconValue
  formData.value.config.buttonIconUrl = undefined

  // If editing existing widget, update backend immediately
  if (isEdit.value && props.widget?.widgetId) {
    try {
      await widgetsApi.updateWidget(props.widget.widgetId, {
        config: {
          ...formData.value.config,
          buttonIcon: iconValue,
          buttonIconUrl: undefined,
        },
      })
    } catch (err: any) {
      console.error('Failed to update icon:', err)
      error('Failed to update icon')
    }
  }
}

const triggerIconUpload = () => {
  iconUploadInput.value?.click()
}

const handleIconUpload = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]

  if (!file) return

  // Validate file size (max 500KB)
  if (file.size > 500 * 1024) {
    error(t('widgets.iconTooLarge'))
    target.value = ''
    return
  }

  // Validate file type
  const validTypes = ['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp']
  if (!validTypes.includes(file.type)) {
    error(t('widgets.invalidIconType'))
    target.value = ''
    return
  }

  // Need widgetId for upload - only allow upload when editing
  if (!isEdit.value || !props.widget?.widgetId) {
    error('Please save the widget first before uploading a custom icon')
    target.value = ''
    return
  }

  try {
    // Upload icon using widget icon upload API
    const uploadResult = await widgetsApi.uploadWidgetIcon(props.widget.widgetId, file)

    if (uploadResult.success) {
      formData.value.config.buttonIconUrl = uploadResult.iconUrl
      formData.value.config.buttonIcon = 'custom'

      // Update widget config in backend without closing modal
      await widgetsApi.updateWidget(props.widget.widgetId, {
        config: {
          ...formData.value.config,
          buttonIconUrl: uploadResult.iconUrl,
          buttonIcon: 'custom',
        },
      })

      success(t('widgets.iconUploadSuccess') || 'Icon uploaded successfully')
    } else {
      error(t('widgets.iconUploadFailed') || 'Icon upload failed')
    }
  } catch (err: any) {
    console.error('Icon upload error:', err)
    error(err?.message || t('widgets.iconUploadFailed') || 'Icon upload failed')
  } finally {
    // Reset input
    target.value = ''
  }
}

const removeCustomIcon = async () => {
  if (!isEdit.value || !props.widget?.widgetId) {
    // Only clear locally if creating new widget
    formData.value.config.buttonIconUrl = undefined
    formData.value.config.buttonIcon = 'chat'
    if (iconUploadInput.value) {
      iconUploadInput.value.value = ''
    }
    return
  }

  try {
    // Update backend to remove custom icon
    await widgetsApi.updateWidget(props.widget.widgetId, {
      config: {
        ...formData.value.config,
        buttonIconUrl: undefined,
        buttonIcon: 'chat',
      },
    })

    // Update local state
    formData.value.config.buttonIconUrl = undefined
    formData.value.config.buttonIcon = 'chat'
    if (iconUploadInput.value) {
      iconUploadInput.value.value = ''
    }

    success('Custom icon removed successfully')
  } catch (err: any) {
    console.error('Failed to remove custom icon:', err)
    error('Failed to remove custom icon')
  }
}

const formData = ref<{
  name: string
  taskPromptTopic: string
  status: 'active' | 'inactive'
  config: WidgetEditorConfig & { buttonIcon?: string; buttonIconUrl?: string }
}>({
  name: '',
  taskPromptTopic: '',
  status: 'active',
  config: {
    position: 'bottom-right',
    primaryColor: '#007bff',
    iconColor: '#ffffff',
    buttonIcon: 'chat',
    defaultTheme: 'light',
    autoOpen: false,
    autoMessage: 'Hello! How can I help you today?',
    messageLimit: 50,
    maxFileSize: 10,
    allowFileUpload: false,
    fileUploadLimit: 3,
    allowedDomains: [],
  },
})

const applyWidgetToForm = (widget?: Widget | null) => {
  if (!widget) {
    formData.value = {
      name: '',
      taskPromptTopic: '',
      status: 'active',
      config: {
        position: 'bottom-right',
        primaryColor: '#007bff',
        iconColor: '#ffffff',
        defaultTheme: 'light',
        autoOpen: false,
        autoMessage: 'Hello! How can I help you today?',
        messageLimit: 50,
        maxFileSize: 10,
        allowFileUpload: false,
        fileUploadLimit: 3,
        allowedDomains: [],
      },
    }
    return
  }

  const config = widget.config ?? {}
  const combinedAllowed = Array.isArray(config.allowedDomains)
    ? config.allowedDomains
    : Array.isArray((widget as widgetsApi.Widget).allowedDomains)
      ? (widget as widgetsApi.Widget).allowedDomains
      : []

  formData.value = {
    name: widget.name ?? '',
    taskPromptTopic: widget.taskPromptTopic ?? '',
    status: (widget.status as 'active' | 'inactive') ?? 'active',
    config: {
      position: config.position || 'bottom-right',
      primaryColor: config.primaryColor || '#007bff',
      iconColor: config.iconColor || '#ffffff',
      buttonIcon: config.buttonIcon || 'chat',
      buttonIconUrl: config.buttonIconUrl,
      defaultTheme: config.defaultTheme || 'light',
      autoOpen: config.autoOpen || false,
      autoMessage: config.autoMessage || 'Hello! How can I help you today?',
      messageLimit: config.messageLimit || 50,
      maxFileSize: config.maxFileSize || 10,
      allowFileUpload: typeof config.allowFileUpload === 'boolean' ? config.allowFileUpload : false,
      fileUploadLimit: typeof config.fileUploadLimit === 'number' ? config.fileUploadLimit : 3,
      allowedDomains: sanitizeDomainList(combinedAllowed),
    },
  }
}

applyWidgetToForm(props.widget)

watch(
  () => props.widget,
  (widget) => {
    applyWidgetToForm(widget)
  }
)

const canSave = computed(() => {
  if (isEdit.value) {
    return formData.value.name.trim() !== ''
  }
  return formData.value.name.trim() !== '' && formData.value.taskPromptTopic !== ''
})

/**
 * Load task prompts
 */
const loadTaskPrompts = async () => {
  try {
    const prompts = await promptsApi.listPrompts()
    taskPrompts.value = prompts
  } catch (error) {
    console.error('Failed to load task prompts:', error)
  }
}

/**
 * Handle save
 */
const allowedDomainsList = computed(() => formData.value.config.allowedDomains ?? [])

const LOCAL_TEST_PATTERNS = ['localhost', '127.0.0.1']

const isLocalTestingDomain = (domain: string): boolean => {
  const value = domain.toLowerCase()
  return LOCAL_TEST_PATTERNS.some((pattern) => value === pattern || value.startsWith(`${pattern}:`))
}

const hasLocalTestingDomain = computed(() =>
  allowedDomainsList.value.some((domain) => isLocalTestingDomain(domain))
)

const handleSave = () => {
  if (!canSave.value) return

  const sanitizedDomains = allowedDomainsList.value
    .map((domain) => sanitizeDomainInput(domain))
    .filter((domain): domain is string => !!domain)
    .filter((domain, index, array) => array.indexOf(domain) === index)

  formData.value.config.allowedDomains = [...sanitizedDomains]

  const payloadConfig: WidgetConfig = {
    ...formData.value.config,
    allowedDomains: [...sanitizedDomains],
  }

  const data = isEdit.value
    ? {
        name: formData.value.name,
        config: payloadConfig,
        status: formData.value.status,
      }
    : {
        name: formData.value.name,
        taskPromptTopic: formData.value.taskPromptTopic,
        config: payloadConfig,
      }

  console.log('🔧 WidgetEditorModal handleSave:', {
    isEdit: isEdit.value,
    sanitizedDomains,
    payloadConfig,
    data,
  })

  emit('save', data)
}

onMounted(() => {
  if (isEdit.value) {
    if (props.widget) {
      widgetsApi
        .getWidget(props.widget.widgetId)
        .then((freshWidget) => {
          applyWidgetToForm(freshWidget)
        })
        .catch((error) => {
          console.error('Failed to load widget details:', error)
        })
    }
  } else {
    loadTaskPrompts()
    if (typeof window !== 'undefined' && window.location?.host) {
      pushAllowedDomain(window.location.host)
    }
  }
})
</script>
