<template>
  <!-- Fullscreen Modal Overlay -->
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" data-testid="modal-widget-editor">
    <div class="surface-card rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto shadow-2xl" data-testid="section-editor-shell">
      <!-- Header -->
      <div class="sticky top-0 surface-card border-b border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-between" data-testid="section-header">
        <div>
          <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:cog-6-tooth" class="w-6 h-6 text-[var(--brand)]" />
            {{ isEdit ? $t('widgets.editWidget') : $t('widgets.createWidget') }}
          </h2>
          <p class="text-sm txt-secondary mt-1">{{ isEdit ? widget?.name : $t('widgets.createDescription') }}</p>
        </div>
        <button
          @click="$emit('close')"
          class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center"
          :aria-label="$t('common.close')"
          data-testid="btn-close"
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
              <option
                v-for="prompt in taskPrompts"
                :key="prompt.topic"
                :value="prompt.topic"
              >
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
              <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"></div>
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
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"></div>
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
              @keydown.enter.prevent="addAllowedDomain"
              autocomplete="off"
            />
            <button
              @click="addAllowedDomain"
              class="btn-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
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
                  : 'bg-[var(--brand-alpha-light)] txt-primary border-[var(--brand)]/20'
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
                @click="removeAllowedDomain(domain)"
                class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                :aria-label="$t('widgets.removeDomain', { domain })"
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
      <div class="sticky bottom-0 surface-card border-t border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-end gap-3">
        <button
          @click="$emit('close')"
          class="px-6 py-2.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary font-medium"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          @click="handleSave"
          :disabled="!canSave"
          class="px-6 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
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
  formData.value.config.allowedDomains = current.filter(item => item !== domain)
}

watch(newAllowedDomain, () => {
  if (allowedDomainError.value) {
    allowedDomainError.value = null
  }
})

const formData = ref<{
  name: string
  taskPromptTopic: string
  status: 'active' | 'inactive'
  config: WidgetEditorConfig
}>({
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
    allowedDomains: []
  }
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
        allowedDomains: []
      }
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
      defaultTheme: config.defaultTheme || 'light',
      autoOpen: config.autoOpen || false,
      autoMessage: config.autoMessage || 'Hello! How can I help you today?',
      messageLimit: config.messageLimit || 50,
      maxFileSize: config.maxFileSize || 10,
      allowFileUpload: typeof config.allowFileUpload === 'boolean' ? config.allowFileUpload : false,
      fileUploadLimit: typeof config.fileUploadLimit === 'number' ? config.fileUploadLimit : 3,
      allowedDomains: sanitizeDomainList(combinedAllowed)
    }
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
    allowedDomains: [...sanitizedDomains]
  }

  const data = isEdit.value
    ? {
        name: formData.value.name,
        config: payloadConfig,
        status: formData.value.status
      }
    : {
        name: formData.value.name,
        taskPromptTopic: formData.value.taskPromptTopic,
        config: payloadConfig
      }

  console.log('🔧 WidgetEditorModal handleSave:', {
    isEdit: isEdit.value,
    sanitizedDomains,
    payloadConfig,
    data
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
