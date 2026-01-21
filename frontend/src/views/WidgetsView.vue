<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-widgets">
      <!-- Header -->
      <div
        class="px-4 lg:px-6 py-4 lg:py-5 border-b border-light-border/30 dark:border-dark-border/20 bg-chat"
        data-testid="section-header"
      >
        <div class="max-w-7xl mx-auto">
          <div
            class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 lg:gap-4"
          >
            <div>
              <h1
                class="text-xl lg:text-2xl font-semibold txt-primary flex items-center gap-2 lg:gap-3"
              >
                <Icon
                  icon="heroicons:chat-bubble-left-right"
                  class="w-6 h-6 lg:w-7 lg:h-7 txt-brand"
                />
                {{ $t('widgets.title') }}
              </h1>
              <p class="txt-secondary mt-1 text-xs lg:text-sm">{{ $t('widgets.subtitle') }}</p>
            </div>
            <button
              class="btn-primary px-4 lg:px-5 py-2 lg:py-2.5 rounded-lg transition-colors font-medium flex items-center gap-2 w-full sm:w-auto justify-center text-sm lg:text-base"
              data-testid="btn-create-widget"
              @click="startCreation"
            >
              <Icon icon="heroicons:plus" class="w-4 h-4 lg:w-5 lg:h-5" />
              {{ $t('widgets.createNew') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Content Area -->
      <div
        class="flex-1 overflow-y-auto px-4 lg:px-6 py-4 lg:py-6 scroll-thin"
        data-testid="section-widgets-content"
      >
        <div class="max-w-7xl mx-auto">
          <!-- Loading State -->
          <div
            v-if="loading"
            class="surface-card p-8 text-center"
            data-testid="state-widgets-loading"
          >
            <div
              class="animate-spin w-8 h-8 border-4 border-[var(--brand)] border-t-transparent rounded-full mx-auto mb-4"
            ></div>
            <p class="txt-secondary text-sm">{{ $t('common.loading') }}</p>
          </div>

          <!-- Empty State -->
          <div
            v-else-if="widgets.length === 0"
            class="surface-card p-8 lg:p-12 text-center"
            data-testid="state-widgets-empty"
          >
            <Icon
              icon="heroicons:chat-bubble-left-right"
              class="w-12 h-12 lg:w-16 lg:h-16 txt-secondary opacity-30 mx-auto mb-4"
            />
            <h3 class="text-lg lg:text-xl font-semibold txt-primary mb-2">
              {{ $t('widgets.emptyTitle') }}
            </h3>
            <p class="txt-secondary mb-6 text-sm">{{ $t('widgets.emptyDescription') }}</p>
            <button
              class="btn-primary px-4 lg:px-6 py-2.5 rounded-lg transition-colors font-medium inline-flex items-center gap-2 text-sm lg:text-base"
              data-testid="btn-create-first-widget"
              @click="startCreation"
            >
              <Icon icon="heroicons:plus" class="w-5 h-5" />
              {{ $t('widgets.createFirst') }}
            </button>
          </div>

          <!-- Widgets List -->
          <div
            v-else
            class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"
            data-testid="section-widget-cards"
          >
            <div
              v-for="widget in widgets"
              :key="widget.id"
              class="surface-card p-4 lg:p-5 hover:shadow-lg transition-shadow cursor-pointer group"
              data-testid="item-widget"
              @click="viewWidget(widget)"
            >
              <div class="flex items-start justify-between mb-4">
                <div class="flex-1 min-w-0 pr-2">
                  <h3
                    class="text-base lg:text-lg font-semibold txt-primary mb-1 group-hover:txt-brand transition-colors truncate"
                  >
                    {{ widget.name }}
                  </h3>
                  <p class="text-xs txt-secondary truncate">{{ widget.taskPromptTopic }}</p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <span
                    :class="[
                      'px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap',
                      widget.isActive
                        ? 'bg-green-500/10 text-green-600 dark:text-green-400'
                        : 'bg-red-500/10 text-red-600 dark:text-red-400',
                    ]"
                  >
                    {{ widget.isActive ? $t('widgets.active') : $t('widgets.inactive') }}
                  </span>
                </div>
              </div>

              <!-- Stats -->
              <div class="grid grid-cols-2 gap-3 mb-4 p-3 surface-chip rounded-lg">
                <div>
                  <p class="text-xs txt-secondary mb-1">{{ $t('widgets.activeSessions') }}</p>
                  <p class="text-lg font-bold txt-primary">
                    {{ widget.stats?.active_sessions ?? 0 }}
                  </p>
                </div>
                <div>
                  <p class="text-xs txt-secondary mb-1">{{ $t('widgets.totalMessages') }}</p>
                  <p class="text-lg font-bold txt-primary">
                    {{ widget.stats?.total_messages ?? 0 }}
                  </p>
                </div>
              </div>

              <!-- Quick Actions -->
              <div class="flex items-center gap-2" @click.stop>
                <button
                  class="flex-1 px-3 py-2 rounded-lg bg-[var(--brand-alpha-light)] txt-brand hover:bg-[var(--brand)]/20 transition-colors text-xs font-medium flex items-center justify-center gap-2"
                  data-testid="btn-widget-embed"
                  @click="showEmbed(widget)"
                >
                  <Icon icon="heroicons:code-bracket" class="w-4 h-4" />
                  <span class="hidden sm:inline">{{ $t('widgets.getCode') }}</span>
                  <span class="sm:hidden">Code</span>
                </button>
                <button
                  class="px-3 py-2 rounded-lg bg-green-500/10 hover:bg-green-500/20 transition-colors"
                  :title="$t('widgets.testChat')"
                  data-testid="btn-widget-test"
                  @click="openTestChat(widget)"
                >
                  <Icon icon="heroicons:play" class="w-4 h-4 text-green-600 dark:text-green-400" />
                </button>
                <button
                  class="px-3 py-2 rounded-lg bg-[var(--brand-alpha-light)] hover:bg-[var(--brand)]/20 transition-colors"
                  :title="$t('widgets.aiSetup')"
                  data-testid="btn-widget-ai-setup"
                  @click="startAiSetup(widget)"
                >
                  <Icon icon="heroicons:sparkles" class="w-4 h-4 txt-brand" />
                </button>
                <button
                  class="px-3 py-2 rounded-lg hover-surface transition-colors"
                  :title="$t('widgets.advancedConfigLabel')"
                  data-testid="btn-widget-advanced"
                  @click="openAdvancedConfig(widget)"
                >
                  <Icon icon="heroicons:cog-6-tooth" class="w-4 h-4 txt-secondary" />
                </button>
                <button
                  class="px-3 py-2 rounded-lg hover:bg-red-500/10 transition-colors"
                  :title="$t('widgets.delete')"
                  data-testid="btn-widget-delete"
                  @click="confirmDelete(widget)"
                >
                  <Icon icon="heroicons:trash" class="w-4 h-4 text-red-500 dark:text-red-400" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Simple Widget Form (Quick Create) -->
    <SimpleWidgetForm
      v-if="showSimpleForm"
      data-testid="comp-simple-form"
      @close="showSimpleForm = false"
      @created="handleWidgetCreated"
    />

    <!-- Success Modal (after creation) -->
    <WidgetSuccessModal
      v-if="successWidget"
      :widget="successWidget"
      data-testid="comp-success-modal"
      @close="successWidget = null"
      @start-ai-setup="startAiSetupFromSuccess"
      @open-advanced="openAdvancedFromSuccess"
      @test-widget="testWidgetFromSuccess"
    />

    <!-- AI Setup Chat Modal -->
    <SetupChatModal
      v-if="setupWidget"
      :widget="setupWidget"
      data-testid="comp-setup-chat"
      @close="setupWidget = null"
      @completed="handleSetupCompleted"
    />

    <!-- Advanced Config Modal -->
    <AdvancedWidgetConfig
      v-if="advancedWidget"
      :widget="advancedWidget"
      data-testid="comp-advanced-config"
      @close="advancedWidget = null"
      @saved="handleAdvancedSaved"
    />

    <!-- Legacy Edit Modal -->
    <WidgetEditorModal
      v-if="currentWidget"
      :widget="currentWidget"
      data-testid="comp-widget-editor-modal"
      @close="currentWidget = null"
      @save="handleSave"
    />

    <!-- Embed Code Dialog -->
    <EmbedCodeDialog
      v-if="showEmbedModal && embedWidget"
      :widget="embedWidget"
      :embed-code="embedCode"
      :legacy-embed-code="legacyEmbedCode"
      :wordpress-shortcode="wordpressShortcode"
      data-testid="comp-embed-dialog"
      @close="showEmbedModal = false"
    />

    <!-- Test Chat Overlay -->
    <Teleport to="body">
      <div
        v-if="testWidget"
        class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        data-testid="test-chat-overlay"
        @click.self="closeTestChat"
      >
        <div class="w-full max-w-md h-[600px] max-h-[80vh] rounded-2xl overflow-hidden shadow-2xl">
          <ChatWidget
            :widget-id="testWidget.widgetId"
            :api-url="apiUrl"
            :primary-color="testWidget.config?.primaryColor || '#007bff'"
            :icon-color="testWidget.config?.iconColor || '#ffffff'"
            :button-icon="testWidget.config?.buttonIcon || 'chat'"
            :button-icon-url="testWidget.config?.buttonIconUrl"
            :auto-message="testWidget.config?.autoMessage || ''"
            :message-limit="testWidget.config?.messageLimit || 50"
            :max-file-size="testWidget.config?.maxFileSize || 10"
            :default-theme="testWidget.config?.defaultTheme || 'light'"
            :widget-title="testWidget.name"
            :allow-file-upload="testWidget.config?.allowFileUpload || false"
            :file-upload-limit="testWidget.config?.fileUploadLimit || 3"
            :open-immediately="true"
            is-preview
            hide-button
            test-mode
            @close="closeTestChat"
          />
        </div>
      </div>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import SimpleWidgetForm from '@/components/widgets/SimpleWidgetForm.vue'
import WidgetSuccessModal from '@/components/widgets/WidgetSuccessModal.vue'
import SetupChatModal from '@/components/widgets/SetupChatModal.vue'
import AdvancedWidgetConfig from '@/components/widgets/AdvancedWidgetConfig.vue'
import WidgetEditorModal from '@/components/widgets/WidgetEditorModal.vue'
import EmbedCodeDialog from '@/components/widgets/EmbedCodeDialog.vue'
import ChatWidget from '@/components/widgets/ChatWidget.vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'

const { success, error } = useNotification()
const { confirm } = useDialog()
const { t } = useI18n()
const configStore = useConfigStore()

const loading = ref(false)
const widgets = ref<widgetsApi.Widget[]>([])
const showSimpleForm = ref(false)
const successWidget = ref<widgetsApi.Widget | null>(null)
const setupWidget = ref<widgetsApi.Widget | null>(null)
const advancedWidget = ref<widgetsApi.Widget | null>(null)
const currentWidget = ref<widgetsApi.Widget | null>(null)
const showEmbedModal = ref(false)
const embedWidget = ref<widgetsApi.Widget | null>(null)
const embedCode = ref('')
const legacyEmbedCode = ref('')
const wordpressShortcode = ref('')
const testWidget = ref<widgetsApi.Widget | null>(null)

const apiUrl = computed(() => configStore.apiBaseUrl || window.location.origin)

/**
 * Load widgets
 */
const loadWidgets = async () => {
  loading.value = true
  try {
    widgets.value = await widgetsApi.listWidgets()
  } catch (err: any) {
    error(err.message || 'Failed to load widgets')
  } finally {
    loading.value = false
  }
}

/**
 * Start widget creation (simple form)
 */
const startCreation = () => {
  showSimpleForm.value = true
}

/**
 * Handle widget created - show success modal
 */
const handleWidgetCreated = async (widget: widgetsApi.Widget) => {
  showSimpleForm.value = false
  successWidget.value = widget
  await loadWidgets()
  success(t('widgets.createSuccess'))
}

/**
 * Start AI setup from success modal
 */
const startAiSetupFromSuccess = () => {
  if (successWidget.value) {
    setupWidget.value = successWidget.value
    successWidget.value = null
  }
}

/**
 * Open advanced config from success modal
 */
const openAdvancedFromSuccess = () => {
  if (successWidget.value) {
    advancedWidget.value = successWidget.value
    successWidget.value = null
  }
}

/**
 * Test widget from success modal
 */
const testWidgetFromSuccess = () => {
  if (successWidget.value) {
    testWidget.value = successWidget.value
    successWidget.value = null // Close success modal
  }
}

/**
 * Start AI setup for existing widget
 */
const startAiSetup = (widget: widgetsApi.Widget) => {
  setupWidget.value = widget
}

/**
 * Open advanced config for existing widget
 */
const openAdvancedConfig = (widget: widgetsApi.Widget) => {
  advancedWidget.value = widget
}

/**
 * Handle AI setup completed
 */
const handleSetupCompleted = async () => {
  setupWidget.value = null
  await loadWidgets()
  success(t('widgets.setupComplete'))
}

/**
 * Handle advanced config saved
 */
const handleAdvancedSaved = async () => {
  advancedWidget.value = null
  await loadWidgets()
}

/**
 * View widget details
 */
const viewWidget = (widget: widgetsApi.Widget) => {
  showEmbed(widget)
}

/**
 * Open test chat
 */
const openTestChat = (widget: widgetsApi.Widget) => {
  testWidget.value = widget
}

/**
 * Close test chat
 */
const closeTestChat = () => {
  testWidget.value = null
}

/**
 * Handle save (legacy editor)
 */
const handleSave = async (data: any) => {
  try {
    if (currentWidget.value) {
      await widgetsApi.updateWidget(currentWidget.value.widgetId, data)
      success(t('widgets.updateSuccess'))
    }

    currentWidget.value = null
    await loadWidgets()
  } catch (err: any) {
    error(err.message || 'Failed to save widget')
  }
}

/**
 * Show embed code
 */
const showEmbed = async (widget: widgetsApi.Widget) => {
  try {
    const data = await widgetsApi.getEmbedCode(widget.widgetId)
    embedWidget.value = widget
    embedCode.value = data.embedCode
    legacyEmbedCode.value = data.legacyEmbedCode || ''
    wordpressShortcode.value = data.wordpressShortcode
    showEmbedModal.value = true
  } catch (err: any) {
    error(err.message || 'Failed to load embed code')
  }
}

/**
 * Confirm delete
 */
const confirmDelete = async (widget: widgetsApi.Widget) => {
  const confirmed = await confirm({
    title: t('widgets.deleteTitle'),
    message: t('widgets.deleteDescription', { name: widget.name }),
    confirmText: t('widgets.deleteConfirm'),
    cancelText: t('common.cancel'),
    danger: true,
  })

  if (confirmed) {
    try {
      await widgetsApi.deleteWidget(widget.widgetId)
      success(t('widgets.deleteSuccess'))
      await loadWidgets()
    } catch (err: any) {
      error(err.message || 'Failed to delete widget')
    }
  }
}

onMounted(() => {
  loadWidgets()
})
</script>
