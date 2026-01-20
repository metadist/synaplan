<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-advanced-config"
      @click.self="handleClose"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col"
        data-testid="section-config-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between flex-shrink-0"
        >
          <div>
            <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:cog-6-tooth" class="w-6 h-6 txt-brand" />
              {{ $t('widgets.advancedConfig.title') }}
            </h2>
            <p class="text-sm txt-secondary mt-1">
              {{ widget.name }}
            </p>
          </div>
          <button
            class="w-10 h-10 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
          </button>
        </div>

        <!-- Tabs -->
        <div class="px-6 border-b border-light-border/30 dark:border-dark-border/20 flex-shrink-0">
          <div class="flex gap-1">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              :class="[
                'px-4 py-3 font-medium text-sm transition-colors relative',
                activeTab === tab.id ? 'txt-primary' : 'txt-secondary hover:txt-primary',
              ]"
              data-testid="btn-tab"
              @click="activeTab = tab.id"
            >
              <span class="flex items-center gap-2">
                <Icon :icon="tab.icon" class="w-4 h-4" />
                {{ $t(tab.labelKey) }}
              </span>
              <div
                v-if="activeTab === tab.id"
                class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--brand)]"
              ></div>
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto scroll-thin p-6">
          <!-- Branding Tab -->
          <div v-if="activeTab === 'branding'" class="space-y-6" data-testid="section-branding">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.position') }}
                </label>
                <select
                  v-model="config.position"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-position"
                >
                  <option value="bottom-right">{{ $t('widgets.bottomRight') }}</option>
                  <option value="bottom-left">{{ $t('widgets.bottomLeft') }}</option>
                  <option value="top-right">{{ $t('widgets.topRight') }}</option>
                  <option value="top-left">{{ $t('widgets.topLeft') }}</option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.theme') }}
                </label>
                <select
                  v-model="config.defaultTheme"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-theme"
                >
                  <option value="light">{{ $t('widgets.light') }}</option>
                  <option value="dark">{{ $t('widgets.dark') }}</option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.primaryColor') }}
                </label>
                <div class="flex items-center gap-3">
                  <input
                    v-model="config.primaryColor"
                    type="color"
                    class="w-12 h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-primary-color"
                  />
                  <input
                    v-model="config.primaryColor"
                    type="text"
                    class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono text-sm"
                  />
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.iconColor') }}
                </label>
                <div class="flex items-center gap-3">
                  <input
                    v-model="config.iconColor"
                    type="color"
                    class="w-12 h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-icon-color"
                  />
                  <input
                    v-model="config.iconColor"
                    type="text"
                    class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono text-sm"
                  />
                </div>
              </div>
            </div>
          </div>

          <!-- Behavior Tab -->
          <div
            v-else-if="activeTab === 'behavior'"
            class="space-y-6"
            data-testid="section-behavior"
          >
            <div class="surface-chip p-4 rounded-lg flex items-center justify-between">
              <div>
                <p class="font-medium txt-primary">{{ $t('widgets.advancedConfig.autoOpen') }}</p>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.autoOpenHelp') }}
                </p>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="config.autoOpen" type="checkbox" class="sr-only peer" />
                <div
                  class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                ></div>
              </label>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.advancedConfig.autoMessage') }}
              </label>
              <textarea
                v-model="config.autoMessage"
                rows="3"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
                data-testid="input-auto-message"
              ></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.messageLimit') }}
                </label>
                <input
                  v-model.number="config.messageLimit"
                  type="number"
                  min="1"
                  max="100"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-message-limit"
                />
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.messageLimitHelp') }}
                </p>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.maxFileSize') }}
                </label>
                <input
                  v-model.number="config.maxFileSize"
                  type="number"
                  min="1"
                  max="50"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-max-file-size"
                />
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.maxFileSizeHelp') }}
                </p>
              </div>
            </div>

            <div class="surface-chip p-4 rounded-lg space-y-4">
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium txt-primary">
                    {{ $t('widgets.advancedConfig.allowFileUpload') }}
                  </p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.allowFileUploadHelp') }}
                  </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="config.allowFileUpload" type="checkbox" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                  ></div>
                </label>
              </div>

              <div v-if="config.allowFileUpload">
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.fileUploadLimit') }}
                </label>
                <input
                  v-model.number="config.fileUploadLimit"
                  type="number"
                  min="0"
                  max="20"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-file-limit"
                />
              </div>
            </div>
          </div>

          <!-- Security Tab -->
          <div
            v-else-if="activeTab === 'security'"
            class="space-y-6"
            data-testid="section-security"
          >
            <div class="surface-chip p-4 rounded-lg space-y-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-medium txt-primary">
                    {{ $t('widgets.advancedConfig.allowedDomains') }}
                  </p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.allowedDomainsHelp') }}
                  </p>
                </div>
                <Icon icon="heroicons:shield-check" class="w-8 h-8 txt-secondary opacity-60" />
              </div>

              <div class="flex gap-2">
                <input
                  v-model="newDomain"
                  type="text"
                  placeholder="example.com"
                  class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-domain"
                  @keydown.enter.prevent="addDomain"
                />
                <button
                  class="btn-primary px-4 py-2.5 rounded-lg font-medium flex items-center gap-2"
                  data-testid="btn-add-domain"
                  @click="addDomain"
                >
                  <Icon icon="heroicons:plus" class="w-4 h-4" />
                  {{ $t('common.add') }}
                </button>
              </div>

              <div v-if="config.allowedDomains?.length" class="flex flex-wrap gap-2">
                <span
                  v-for="domain in config.allowedDomains"
                  :key="domain"
                  class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium bg-[var(--brand-alpha-light)] txt-primary border border-[var(--brand)]/20"
                >
                  {{ domain }}
                  <button
                    class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10"
                    @click="removeDomain(domain)"
                  >
                    <Icon icon="heroicons:x-mark" class="w-3 h-3" />
                  </button>
                </span>
              </div>
              <p v-else class="text-xs txt-secondary">
                {{ $t('widgets.advancedConfig.noDomainsYet') }}
              </p>
            </div>
          </div>

          <!-- AI Assistant Tab -->
          <div
            v-else-if="activeTab === 'assistant'"
            class="space-y-6"
            data-testid="section-assistant"
          >
            <!-- Loading State -->
            <div v-if="promptLoading" class="flex items-center justify-center py-12">
              <Icon icon="heroicons:arrow-path" class="w-8 h-8 txt-secondary animate-spin" />
            </div>

            <!-- Error State -->
            <div
              v-else-if="promptError"
              class="p-4 rounded-lg bg-red-500/10 border border-red-500/30"
            >
              <p class="text-sm text-red-600 dark:text-red-400">{{ promptError }}</p>
            </div>

            <!-- Prompt Editor -->
            <template v-else>
              <!-- Prompt Name -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:tag" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.promptName') }}
                </label>
                <input
                  v-model="promptData.name"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-prompt-name"
                />
              </div>

              <!-- Selection Rules -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:funnel" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.selectionRules') }}
                </label>
                <textarea
                  v-model="promptData.rules"
                  rows="2"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
                  :placeholder="$t('widgets.advancedConfig.selectionRulesPlaceholder')"
                  data-testid="input-selection-rules"
                ></textarea>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.selectionRulesHelp') }}
                </p>
              </div>

              <!-- AI Model Selection -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.aiModel') }}
                </label>
                <select
                  v-model="promptData.aiModel"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-ai-model"
                >
                  <option
                    value="AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]"
                  >
                    ✨ {{ $t('widgets.advancedConfig.automated') }}
                  </option>
                  <template v-if="!loadingModels && groupedModels.length > 0">
                    <optgroup
                      v-for="group in groupedModels"
                      :key="group.capability"
                      :label="group.label"
                    >
                      <option
                        v-for="model in group.models"
                        :key="model.id"
                        :value="`${model.name} (${model.service})`"
                      >
                        {{ model.name }} ({{ model.service }})
                        <template v-if="model.rating">⭐ {{ model.rating.toFixed(1) }}</template>
                      </option>
                    </optgroup>
                  </template>
                  <option v-if="loadingModels" disabled>Loading models...</option>
                </select>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.aiModelHelp') }}
                </p>
              </div>

              <!-- Available Tools -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-3 flex items-center gap-2">
                  <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.availableTools') }}
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <label
                    v-for="tool in availableTools"
                    :key="tool.value"
                    class="flex items-center gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:bg-[var(--brand)]/5 transition-colors"
                    data-testid="item-tool"
                  >
                    <input
                      v-model="promptData.availableTools"
                      type="checkbox"
                      :value="tool.value"
                      class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                    />
                    <Icon :icon="tool.icon" class="w-5 h-5 txt-secondary" />
                    <span class="text-sm txt-primary">{{ tool.label }}</span>
                  </label>
                </div>
              </div>

              <!-- Prompt Content -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:document-text" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.promptContent') }}
                </label>
                <textarea
                  v-model="promptData.content"
                  rows="12"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y font-mono text-sm"
                  :placeholder="$t('widgets.advancedConfig.promptContentPlaceholder')"
                  data-testid="input-prompt-content"
                ></textarea>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.promptContentHelp') }}
                </p>
              </div>
            </template>
          </div>
        </div>

        <!-- Footer -->
        <div
          class="px-6 py-4 border-t border-light-border/30 dark:border-dark-border/20 flex items-center justify-end gap-3 flex-shrink-0"
        >
          <button
            class="px-5 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium"
            data-testid="btn-cancel"
            @click="handleClose"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            :disabled="saving"
            class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            data-testid="btn-save"
            @click="handleSave"
          >
            <Icon v-if="saving" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
            <Icon v-else icon="heroicons:check" class="w-5 h-5" />
            {{ saving ? $t('common.saving') : $t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi } from '@/services/api/promptsApi'
import { configApi } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  widget: widgetsApi.Widget
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

// Check if widget has a custom prompt (not the default)
const hasCustomPrompt = computed(() => {
  const topic = props.widget.taskPromptTopic
  return topic && topic !== 'widget-default' && topic.startsWith('widget-')
})

const tabs = computed(() => {
  const baseTabs = [
    {
      id: 'branding',
      icon: 'heroicons:paint-brush',
      labelKey: 'widgets.advancedConfig.tabs.branding',
    },
    {
      id: 'behavior',
      icon: 'heroicons:adjustments-horizontal',
      labelKey: 'widgets.advancedConfig.tabs.behavior',
    },
    {
      id: 'security',
      icon: 'heroicons:shield-check',
      labelKey: 'widgets.advancedConfig.tabs.security',
    },
  ]

  // Only show AI Assistant tab if widget has a custom prompt
  if (hasCustomPrompt.value) {
    baseTabs.push({
      id: 'assistant',
      icon: 'heroicons:sparkles',
      labelKey: 'widgets.advancedConfig.tabs.assistant',
    })
  }

  return baseTabs
})

const activeTab = ref('branding')
const saving = ref(false)
const newDomain = ref('')

// Widget config
const config = reactive<widgetsApi.WidgetConfig>({
  position: 'bottom-right',
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  defaultTheme: 'light',
  autoOpen: false,
  autoMessage: '',
  messageLimit: 50,
  maxFileSize: 10,
  allowFileUpload: false,
  fileUploadLimit: 3,
  allowedDomains: [],
})

// Prompt config for AI Assistant tab
const promptData = reactive({
  id: 0,
  topic: '',
  name: '',
  rules: '',
  aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
  availableTools: [] as string[],
  content: '',
})
const promptLoading = ref(false)
const promptError = ref<string | null>(null)

// AI Models
const allModels = ref<Partial<Record<Capability, AIModel[]>>>({})
const loadingModels = ref(false)

// Available tools
const availableTools = [
  { value: 'internet-search', label: 'Internet Search', icon: 'heroicons:magnifying-glass' },
  { value: 'files-search', label: 'Files Search', icon: 'heroicons:document-magnifying-glass' },
  { value: 'url-screenshot', label: 'URL Screenshot', icon: 'heroicons:camera' },
]

// Group models by capability for dropdown
const groupedModels = computed(() => {
  const groups: { label: string; models: AIModel[]; capability: Capability }[] = []

  const capabilityLabels: Record<Capability, string> = {
    CHAT: 'Chat & General AI',
    SORT: 'Message Sorting',
    TEXT2PIC: 'Image Generation',
    TEXT2VID: 'Video Generation',
    TEXT2SOUND: 'Text-to-Speech',
    SOUND2TEXT: 'Speech-to-Text',
    PIC2TEXT: 'Vision (Image Analysis)',
    VECTORIZE: 'Embedding / RAG',
    ANALYZE: 'File Analysis',
  }

  const orderedCapabilities: Capability[] = [
    'CHAT',
    'TEXT2PIC',
    'TEXT2VID',
    'TEXT2SOUND',
    'SOUND2TEXT',
    'PIC2TEXT',
    'ANALYZE',
    'VECTORIZE',
    'SORT',
  ]

  orderedCapabilities.forEach((capability) => {
    if (allModels.value[capability] && allModels.value[capability].length > 0) {
      groups.push({
        label: capabilityLabels[capability] || capability,
        models: allModels.value[capability],
        capability,
      })
    }
  })

  return groups
})

const handleClose = () => {
  emit('close')
}

const addDomain = () => {
  const domain = newDomain.value.trim().toLowerCase()
  if (!domain) return

  if (!config.allowedDomains) {
    config.allowedDomains = []
  }

  if (!config.allowedDomains.includes(domain)) {
    config.allowedDomains.push(domain)
  }

  newDomain.value = ''
}

const removeDomain = (domain: string) => {
  if (config.allowedDomains) {
    config.allowedDomains = config.allowedDomains.filter((d) => d !== domain)
  }
}

const handleSave = async () => {
  saving.value = true
  try {
    // Save widget config
    await widgetsApi.updateWidget(props.widget.widgetId, { config })

    // Save prompt if on assistant tab and has custom prompt
    if (activeTab.value === 'assistant' && hasCustomPrompt.value && promptData.id) {
      await savePromptData()
    }

    success(t('widgets.advancedConfig.saveSuccess'))
    emit('saved')
  } catch (err: any) {
    console.error('Failed to save config:', err)
    showError(err.message || t('widgets.advancedConfig.saveError'))
  } finally {
    saving.value = false
  }
}

const loadAIModels = async () => {
  loadingModels.value = true
  try {
    const response = await configApi.getModels()
    if (response.success) {
      allModels.value = response.models
    }
  } catch (err: any) {
    console.error('Failed to load AI models:', err)
  } finally {
    loadingModels.value = false
  }
}

const loadPromptData = async () => {
  if (!hasCustomPrompt.value) return

  promptLoading.value = true
  promptError.value = null

  try {
    const prompts = await promptsApi.getPrompts('en')
    const prompt = prompts.find((p) => p.topic === props.widget.taskPromptTopic)

    if (prompt) {
      const metadata = prompt.metadata || {}

      // Determine AI Model string from metadata.aiModel (ID)
      let aiModelString =
        'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
      if (metadata.aiModel && metadata.aiModel > 0) {
        for (const models of Object.values(allModels.value)) {
          if (models) {
            const foundModel = models.find((m: AIModel) => m.id === metadata.aiModel)
            if (foundModel) {
              aiModelString = `${foundModel.name} (${foundModel.service})`
              break
            }
          }
        }
      }

      // Parse available tools from metadata
      const tools: string[] = []
      if (metadata.tool_internet_search) tools.push('internet-search')
      if (metadata.tool_files_search) tools.push('files-search')
      if (metadata.tool_url_screenshot) tools.push('url-screenshot')

      Object.assign(promptData, {
        id: prompt.id,
        topic: prompt.topic,
        name: prompt.shortDescription || prompt.name,
        rules: prompt.selectionRules || '',
        aiModel: aiModelString,
        availableTools: tools,
        content: prompt.prompt,
      })
    }
  } catch (err: any) {
    console.error('Failed to load prompt:', err)
    promptError.value = err.message || 'Failed to load prompt data'
  } finally {
    promptLoading.value = false
  }
}

const savePromptData = async () => {
  if (!promptData.id) return

  // Build metadata object
  const metadata: Record<string, any> = {}

  // Parse AI Model from dropdown string back to ID
  if (
    promptData.aiModel !==
    'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
  ) {
    for (const models of Object.values(allModels.value)) {
      if (models) {
        const foundModel = models.find(
          (m: AIModel) => `${m.name} (${m.service})` === promptData.aiModel
        )
        if (foundModel) {
          metadata.aiModel = foundModel.id
          break
        }
      }
    }
  } else {
    metadata.aiModel = -1
  }

  // Set tool flags
  metadata.tool_internet_search = promptData.availableTools.includes('internet-search')
  metadata.tool_files_search = promptData.availableTools.includes('files-search')
  metadata.tool_url_screenshot = promptData.availableTools.includes('url-screenshot')

  await promptsApi.updatePrompt(promptData.id, {
    shortDescription: promptData.name,
    prompt: promptData.content,
    selectionRules: promptData.rules || null,
    metadata,
  })
}

onMounted(async () => {
  // Load current config from widget
  const widgetConfig = props.widget.config || {}
  Object.assign(config, {
    position: widgetConfig.position || 'bottom-right',
    primaryColor: widgetConfig.primaryColor || '#007bff',
    iconColor: widgetConfig.iconColor || '#ffffff',
    defaultTheme: widgetConfig.defaultTheme || 'light',
    autoOpen: widgetConfig.autoOpen || false,
    autoMessage: widgetConfig.autoMessage || '',
    messageLimit: widgetConfig.messageLimit || 50,
    maxFileSize: widgetConfig.maxFileSize || 10,
    allowFileUpload: widgetConfig.allowFileUpload || false,
    fileUploadLimit: widgetConfig.fileUploadLimit || 3,
    allowedDomains: widgetConfig.allowedDomains || props.widget.allowedDomains || [],
  })

  // Load AI models and prompt data if has custom prompt
  if (hasCustomPrompt.value) {
    await loadAIModels()
    await loadPromptData()
  }
})
</script>
