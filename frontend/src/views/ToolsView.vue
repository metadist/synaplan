<template>
  <MainLayout>
    <div class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin" data-testid="page-tools">
      <div class="max-w-[1400px] mx-auto w-full px-6 py-8">
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-semibold txt-primary mb-2">
            {{ getPageTitle() }}
          </h1>
          <p class="txt-secondary">
            {{ getPageDescription() }}
          </p>
        </div>

        <div v-if="currentPage === 'chat-widget'">
          <div v-if="!showWidgetEditor">
            <WidgetList
              :widgets="widgets"
              data-testid="comp-widget-list"
              @create="createWidget"
              @edit="editWidget"
              @delete="deleteWidget"
            />
          </div>
          <div v-else class="grid grid-cols-1 xl:grid-cols-5 gap-3 sm:gap-4 lg:gap-6">
            <div class="xl:col-span-2">
              <WidgetEditor
                v-model="currentWidgetConfig"
                :widget-id="currentWidgetId"
                :user-id="'152'"
                :show-code="!!currentWidgetId"
                data-testid="comp-widget-editor"
                @cancel="cancelEdit"
              />
            </div>

            <div
              v-if="showPreview"
              class="xl:col-span-3 xl:sticky xl:top-6 xl:h-fit"
              data-testid="section-widget-preview"
            >
              <div class="surface-card p-2 sm:p-4 lg:p-6">
                <div class="flex items-center justify-between mb-3 lg:mb-4">
                  <h3
                    class="text-base lg:text-lg font-semibold txt-primary flex items-center gap-2"
                  >
                    <EyeIcon class="w-4 h-4 lg:w-5 lg:h-5" />
                    Live Preview
                  </h3>
                  <button
                    class="lg:hidden w-8 h-8 rounded-lg icon-ghost flex items-center justify-center"
                    @click="togglePreview"
                  >
                    <XMarkIcon class="w-5 h-5" />
                  </button>
                </div>
                <p class="txt-secondary text-xs sm:text-sm mb-3 lg:mb-4">
                  {{
                    currentWidgetConfig.previewUrl
                      ? 'Live preview on your website'
                      : 'This is how the widget will appear on your website. Click the button to test it.'
                  }}
                </p>
                <div
                  class="relative border-2 border-light-border/30 dark:border-dark-border/20 rounded-xl overflow-hidden h-[600px] sm:h-[650px] lg:h-[700px] max-h-[85vh]"
                >
                  <iframe
                    v-if="currentWidgetConfig.previewUrl"
                    :src="currentWidgetConfig.previewUrl"
                    class="absolute inset-0 w-full h-full rounded-xl"
                    sandbox="allow-scripts allow-same-origin"
                  />
                  <div
                    v-else
                    class="absolute inset-0 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 rounded-xl"
                  >
                    <div
                      class="absolute inset-0 flex items-center justify-center txt-secondary text-sm"
                    >
                      <div class="text-center">
                        <GlobeAltIcon class="w-12 h-12 mx-auto mb-2 opacity-30" />
                        <p>Your Website</p>
                      </div>
                    </div>
                  </div>
                  <div
                    class="absolute inset-0 pointer-events-none rounded-xl scale-100 lg:scale-85"
                    style="transform-origin: center"
                  >
                    <div class="relative w-full h-full pointer-events-none">
                      <div class="pointer-events-auto">
                        <ChatWidget
                          widget-id="preview-widget"
                          :api-url="config.apiBaseUrl"
                          :primary-color="currentWidgetConfig.primaryColor"
                          :icon-color="currentWidgetConfig.iconColor"
                          :position="currentWidgetConfig.position"
                          :auto-open="false"
                          :auto-message="currentWidgetConfig.autoMessage"
                          :default-theme="currentWidgetConfig.defaultTheme || 'light'"
                          :is-preview="true"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="currentPage === 'doc-summary'">
          <SummaryConfiguration
            :is-generating="isGeneratingSummary"
            :current-model="currentChatModel"
            data-testid="comp-summary-config"
            @generate="handleGenerateSummary"
            @regenerate="handleRegenerateSummary"
            @show="showSummaryModal"
          />

          <!-- Summary Result Modal -->
          <SummaryResultModal
            :is-open="isSummaryModalOpen"
            :summary="summaryResult?.summary || null"
            :metadata="summaryResult?.metadata || null"
            :config="lastSummaryConfig"
            @close="closeSummaryModal"
          />
        </div>

        <div v-else-if="currentPage === 'mail-handler'">
          <div v-if="isLoadingMailHandlers" class="flex items-center justify-center py-12">
            <svg class="w-6 h-6 animate-spin txt-brand" fill="none" viewBox="0 0 24 24">
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              />
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
            <span class="ml-2 txt-secondary">Loading mail handlers...</span>
          </div>
          <MailHandlerList
            v-else-if="!showMailHandlerEditor"
            :handlers="mailHandlers"
            data-testid="comp-mail-handler-list"
            @create="createMailHandler"
            @edit="editMailHandler"
            @delete="deleteMailHandler"
            @bulk-update-status="bulkUpdateHandlerStatus"
            @bulk-delete="bulkDeleteHandlers"
          />
          <MailHandlerConfiguration
            v-else
            :handler="currentMailHandler"
            :handler-id="currentMailHandlerId"
            data-testid="comp-mail-handler-config"
            @save="saveMailHandler"
            @cancel="cancelMailHandlerEdit"
          />
        </div>
      </div>
    </div>

    <UnsavedChangesBar
      v-if="showWidgetEditor && currentPage === 'chat-widget'"
      :show="hasWidgetChanges"
      :show-preview="!!currentWidgetId"
      data-testid="bar-widget-unsaved"
      @save="saveWidget"
      @discard="discardChanges"
      @preview="togglePreview"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import MainLayout from '@/components/MainLayout.vue'
import WidgetList from '@/components/widgets/WidgetList.vue'
import WidgetEditor from '@/components/widgets/WidgetEditor.vue'
import ChatWidget from '@/components/widgets/ChatWidget.vue'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import SummaryConfiguration from '@/components/summary/SummaryConfiguration.vue'
import SummaryResultModal from '@/components/summary/SummaryResultModal.vue'
import MailHandlerConfiguration from '@/components/mail/MailHandlerConfiguration.vue'
import MailHandlerList from '@/components/mail/MailHandlerList.vue'
import { EyeIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { Widget, WidgetConfig } from '@/mocks/widgets'
import { mockWidgets } from '@/mocks/widgets'
import type { SummaryConfig } from '@/mocks/summaries'
import type {
  MailConfig,
  Department,
  SavedMailHandler,
} from '@/services/api/inboundEmailHandlersApi'
import { inboundEmailHandlersApi } from '@/services/api/inboundEmailHandlersApi'
import * as summaryService from '@/services/summaryService'
import type { SummaryResponse } from '@/services/summaryService'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'

const route = useRoute()
const { t } = useI18n()
const config = useConfigStore()
const aiConfigStore = useAiConfigStore()
const { success, error: showError, warning: showWarning } = useNotification()
const dialog = useDialog()
const widgets = ref<Widget[]>(mockWidgets)
const showWidgetEditor = ref(false)
const showPreview = ref(false)
const currentWidgetId = ref<string>('')
const currentWidgetConfig = ref<WidgetConfig>({
  integrationType: 'floating-button',
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  position: 'bottom-right',
  autoMessage: 'Hello! How can I help you today?',
  autoOpen: false,
  aiPrompt: 'general',
  defaultTheme: 'light',
  previewUrl: '',
})
const originalWidgetConfig = ref<WidgetConfig | null>(null)

const mailHandlers = ref<SavedMailHandler[]>([])
const showMailHandlerEditor = ref(false)
const currentMailHandler = ref<SavedMailHandler | undefined>(undefined)
const currentMailHandlerId = ref<string>('')
const isLoadingMailHandlers = ref(false)

// Summary state
const isGeneratingSummary = ref(false)
const summaryResult = ref<SummaryResponse | null>(null)
const isSummaryModalOpen = ref(false)
const lastSummaryConfig = ref<SummaryConfig | null>(null)
const currentChatModel = ref<string | null>(null)

const hasWidgetChanges = computed(() => {
  if (!originalWidgetConfig.value || !showWidgetEditor.value) return false
  return JSON.stringify(currentWidgetConfig.value) !== JSON.stringify(originalWidgetConfig.value)
})

const currentPage = computed(() => {
  const path = route.path
  if (path.includes('chat-widget')) return 'chat-widget'
  if (path.includes('doc-summary')) return 'doc-summary'
  if (path.includes('mail-handler')) return 'mail-handler'
  return 'doc-summary'
})

// Load current chat model function (defined before watch)
const loadCurrentChatModel = async () => {
  try {
    // Load models and defaults if not already loaded
    if (Object.keys(aiConfigStore.models).length === 0) {
      await aiConfigStore.loadModels()
    }
    if (Object.keys(aiConfigStore.defaults).length === 0) {
      await aiConfigStore.loadDefaults()
    }

    // Get current CHAT model
    const chatModel = aiConfigStore.getCurrentModel('CHAT')
    if (chatModel) {
      currentChatModel.value = chatModel.name
    } else {
      currentChatModel.value = 'No default model'
    }
  } catch (error) {
    console.error('Failed to load current chat model:', error)
    currentChatModel.value = 'Failed to load'
  }
}

// Load mail handlers function (defined before watch)
const loadMailHandlers = async () => {
  isLoadingMailHandlers.value = true
  try {
    mailHandlers.value = await inboundEmailHandlersApi.list()
  } catch (error: any) {
    console.error('Failed to load mail handlers:', error)
    showError(error.message || 'Failed to load mail handlers')
  } finally {
    isLoadingMailHandlers.value = false
  }
}

// Watch for page change to doc-summary and load model
watch(
  currentPage,
  async (newPage) => {
    if (newPage === 'doc-summary' && !currentChatModel.value) {
      await loadCurrentChatModel()
    }
    if (newPage === 'mail-handler' && mailHandlers.value.length === 0) {
      await loadMailHandlers()
    }
  },
  { immediate: true }
)

const getPageTitle = () => {
  const titles: Record<string, string> = {
    'chat-widget': 'Chat Widget',
    'doc-summary': 'Doc Summary',
    'mail-handler': 'Mail Handler',
  }
  return titles[currentPage.value] || 'Agents'
}

const getPageDescription = () => {
  const descriptions: Record<string, string> = {
    'chat-widget': 'Create and manage chat widgets for your website',
    'doc-summary': 'Automatically summarize documents and extract key information',
    'mail-handler': 'Process and manage email communications automatically',
  }
  return descriptions[currentPage.value] || ''
}

const createWidget = () => {
  showWidgetEditor.value = true
  showPreview.value = true
  currentWidgetId.value = ''
  const newConfig: WidgetConfig = {
    integrationType: 'floating-button',
    primaryColor: '#007bff',
    iconColor: '#ffffff',
    position: 'bottom-right',
    autoMessage: 'Hello! How can I help you today?',
    autoOpen: false,
    aiPrompt: 'general',
    defaultTheme: 'light',
    previewUrl: '',
  }
  currentWidgetConfig.value = { ...newConfig }
  originalWidgetConfig.value = { ...newConfig }
}

const editWidget = (widget: Widget) => {
  showWidgetEditor.value = true
  showPreview.value = true
  currentWidgetId.value = widget.id
  const editConfig: WidgetConfig = {
    integrationType: widget.integrationType,
    primaryColor: widget.primaryColor,
    iconColor: widget.iconColor,
    position: widget.position,
    autoMessage: widget.autoMessage,
    autoOpen: widget.autoOpen,
    aiPrompt: widget.aiPrompt,
    defaultTheme: widget.defaultTheme || 'light',
    previewUrl: widget.previewUrl || '',
  }
  currentWidgetConfig.value = { ...editConfig }
  originalWidgetConfig.value = { ...editConfig }
}

const saveWidget = async () => {
  if (currentWidgetId.value) {
    const index = widgets.value.findIndex((w) => w.id === currentWidgetId.value)
    if (index > -1) {
      widgets.value[index] = {
        id: widgets.value[index].id,
        userId: widgets.value[index].userId,
        integrationType: currentWidgetConfig.value.integrationType,
        primaryColor: currentWidgetConfig.value.primaryColor,
        iconColor: currentWidgetConfig.value.iconColor,
        position: currentWidgetConfig.value.position,
        autoMessage: currentWidgetConfig.value.autoMessage,
        autoOpen: currentWidgetConfig.value.autoOpen,
        aiPrompt: currentWidgetConfig.value.aiPrompt,
        defaultTheme: currentWidgetConfig.value.defaultTheme,
        previewUrl: currentWidgetConfig.value.previewUrl,
        createdAt: widgets.value[index].createdAt,
        updatedAt: new Date(),
      }
    }
  } else {
    const newWidget: Widget = {
      id: String(Date.now()),
      userId: '152',
      integrationType: currentWidgetConfig.value.integrationType,
      primaryColor: currentWidgetConfig.value.primaryColor,
      iconColor: currentWidgetConfig.value.iconColor,
      position: currentWidgetConfig.value.position,
      autoMessage: currentWidgetConfig.value.autoMessage,
      autoOpen: currentWidgetConfig.value.autoOpen,
      aiPrompt: currentWidgetConfig.value.aiPrompt,
      defaultTheme: currentWidgetConfig.value.defaultTheme,
      previewUrl: currentWidgetConfig.value.previewUrl,
      createdAt: new Date(),
      updatedAt: new Date(),
    }
    widgets.value.push(newWidget)
    currentWidgetId.value = newWidget.id
  }

  // Update original config after successful save
  originalWidgetConfig.value = { ...currentWidgetConfig.value }
}

const deleteWidget = (widgetId: string) => {
  widgets.value = widgets.value.filter((w) => w.id !== widgetId)
}

const cancelEdit = () => {
  showWidgetEditor.value = false
  showPreview.value = false
  currentWidgetId.value = ''
  originalWidgetConfig.value = null
}

const discardChanges = () => {
  // Discard changes and close editor (like Discord's "Reset" button)
  cancelEdit()
}

const togglePreview = () => {
  showPreview.value = !showPreview.value
}

const handleGenerateSummary = async (text: string, config: SummaryConfig) => {
  isGeneratingSummary.value = true
  summaryResult.value = null
  lastSummaryConfig.value = config

  try {
    const response = await summaryService.generateSummary({
      text,
      summaryType: config.summaryType,
      length: config.length,
      customLength: config.customLength,
      outputLanguage: config.outputLanguage,
      focusAreas: config.focusAreas,
    })

    if (response.success && response.summary) {
      summaryResult.value = response
      success('Summary generated successfully!')
      // Automatically open modal after generation
      isSummaryModalOpen.value = true
    } else {
      showError(response.error || 'Failed to generate summary')
    }
  } catch (err: any) {
    console.error('Summary generation error:', err)
    showError(err.message || 'Failed to generate summary')
  } finally {
    isGeneratingSummary.value = false
  }
}

const handleRegenerateSummary = async (text: string, config: SummaryConfig) => {
  // Same as generate but doesn't change the text state
  await handleGenerateSummary(text, config)
}

const showSummaryModal = () => {
  if (summaryResult.value) {
    isSummaryModalOpen.value = true
  }
}

const closeSummaryModal = () => {
  isSummaryModalOpen.value = false
}

const createMailHandler = () => {
  showMailHandlerEditor.value = true
  currentMailHandler.value = undefined
  currentMailHandlerId.value = ''
}

const editMailHandler = (handler: SavedMailHandler) => {
  showMailHandlerEditor.value = true
  currentMailHandler.value = handler
  currentMailHandlerId.value = handler.id
}

const saveMailHandler = async (
  name: string,
  config: MailConfig,
  departments: Department[],
  smtpConfig: any,
  emailFilter: any,
  isActive: boolean
) => {
  try {
    // Validate that SMTP config is provided
    if (
      !smtpConfig ||
      !smtpConfig.smtpServer ||
      !smtpConfig.smtpUsername ||
      !smtpConfig.smtpPassword
    ) {
      showError('SMTP configuration is required for email forwarding')
      return
    }

    const payload: any = {
      name,
      mailServer: config.mailServer,
      port: config.port,
      protocol: config.protocol,
      security: config.security,
      username: config.username,
      checkInterval: config.checkInterval,
      deleteAfter: config.deleteAfter,
      departments,
      status: isActive ? 'active' : 'inactive',
      // SMTP credentials (required)
      smtpServer: smtpConfig.smtpServer,
      smtpPort: smtpConfig.smtpPort,
      smtpSecurity: smtpConfig.smtpSecurity,
      smtpUsername: smtpConfig.smtpUsername,
      // Email filter settings
      emailFilterMode: emailFilter.mode || 'new',
      emailFilterFromDate: emailFilter.fromDate || null,
    }

    // Only include passwords if they were changed (not the masked value)
    // For CREATE: passwords are required
    // For UPDATE: only send if changed (not '••••••••')
    if (!currentMailHandlerId.value) {
      // Creating new handler - passwords are required
      if (!config.password || config.password === '••••••••') {
        showError('IMAP/POP3 password is required for new handlers')
        return
      }
      if (!smtpConfig.smtpPassword || smtpConfig.smtpPassword === '••••••••') {
        showError('SMTP password is required for new handlers')
        return
      }
      payload.password = config.password
      payload.smtpPassword = smtpConfig.smtpPassword
    } else {
      // Updating existing handler - only send passwords if they were changed
      if (config.password && config.password !== '••••••••') {
        payload.password = config.password
      }
      if (smtpConfig.smtpPassword && smtpConfig.smtpPassword !== '••••••••') {
        payload.smtpPassword = smtpConfig.smtpPassword
      }
    }

    let savedHandlerId = currentMailHandlerId.value

    if (currentMailHandlerId.value) {
      // Update existing
      const updated = await inboundEmailHandlersApi.update(currentMailHandlerId.value, payload)

      const index = mailHandlers.value.findIndex((h) => h.id === currentMailHandlerId.value)
      if (index > -1) {
        mailHandlers.value[index] = updated
      }
      success('Mail handler updated successfully!')
    } else {
      // Create new
      const newHandler = await inboundEmailHandlersApi.create(payload)
      mailHandlers.value.push(newHandler)
      savedHandlerId = newHandler.id
      success('Mail handler created successfully!')
    }

    // Automatic connection test after save
    try {
      const testResult = await inboundEmailHandlersApi.testConnection(savedHandlerId)

      if (testResult.success) {
        // Refresh handler list to get updated status
        await loadMailHandlers()
      } else {
        showWarning(t('mail.connectionTestWarning') + ': ' + testResult.message)
      }
    } catch (error: any) {
      console.error('Connection test failed:', error)
      showWarning(t('mail.handlerSavedTestFailed'))
    }

    cancelMailHandlerEdit()
  } catch (error: any) {
    console.error('Failed to save mail handler:', error)
    showError(error.message || 'Failed to save mail handler')
  }
}

const deleteMailHandler = async (handlerId: string) => {
  const handler = mailHandlers.value.find((h) => h.id === handlerId)

  const confirmed = await dialog.confirm({
    title: t('mail.deleteHandlerConfirmTitle'),
    message: t('mail.deleteHandlerConfirmMessage', { name: handler?.name || 'this handler' }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })

  if (!confirmed) {
    return
  }

  try {
    await inboundEmailHandlersApi.delete(handlerId)
    mailHandlers.value = mailHandlers.value.filter((h) => h.id !== handlerId)
    success(t('mail.handlerDeleted'))
  } catch (error: any) {
    console.error('Failed to delete mail handler:', error)
    showError(error.message || t('mail.deleteFailed'))
  }
}

const cancelMailHandlerEdit = () => {
  showMailHandlerEditor.value = false
  currentMailHandler.value = undefined
  currentMailHandlerId.value = ''
}

const bulkUpdateHandlerStatus = async (handlerIds: string[], status: 'active' | 'inactive') => {
  try {
    await inboundEmailHandlersApi.bulkUpdateStatus(handlerIds, status)

    // Update local state
    mailHandlers.value = mailHandlers.value.map((handler) => {
      if (handlerIds.includes(handler.id)) {
        return { ...handler, status }
      }
      return handler
    })

    success(t('mail.bulkUpdateSuccess', { count: handlerIds.length }))
  } catch (error: any) {
    console.error('Failed to bulk update handlers:', error)
    showError(error.message || t('mail.bulkUpdateFailed'))
  }
}

const bulkDeleteHandlers = async (handlerIds: string[]) => {
  const confirmed = await dialog.confirm({
    title: t('mail.bulkDeleteConfirmTitle'),
    message: t('mail.bulkDeleteConfirmMessage', { count: handlerIds.length }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })

  if (!confirmed) {
    return
  }

  try {
    await inboundEmailHandlersApi.bulkDelete(handlerIds)

    // Remove from local state
    mailHandlers.value = mailHandlers.value.filter((h) => !handlerIds.includes(h.id))

    success(t('mail.bulkDeleteSuccess', { count: handlerIds.length }))
  } catch (error: any) {
    console.error('Failed to bulk delete handlers:', error)
    showError(error.message || t('mail.bulkDeleteFailed'))
  }
}
</script>
