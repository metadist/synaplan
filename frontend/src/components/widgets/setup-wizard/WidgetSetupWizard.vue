<template>
  <Teleport to="#app">
    <div
      class="modal-overlay fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-widget-wizard"
      @click.self="handleClose"
    >
      <div
        class="modal-panel surface-card rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl flex flex-col max-h-[90vh]"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between flex-shrink-0"
        >
          <h2 class="text-lg font-semibold txt-primary">
            {{ $t('widgets.createWizard.title') }}
          </h2>
          <button
            class="w-9 h-9 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
          </button>
        </div>

        <!-- Stepper -->
        <div class="px-6 pt-4 flex-shrink-0" data-testid="wizard-stepper">
          <div class="flex items-center">
            <template v-for="(step, index) in steps" :key="step.id">
              <button
                type="button"
                class="flex items-center gap-2 min-w-0"
                :disabled="index >= currentStep || creating"
                :class="index < currentStep && !creating ? 'cursor-pointer' : 'cursor-default'"
                @click="index < currentStep && !creating && (currentStep = index)"
              >
                <span
                  :class="[
                    'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 transition-colors',
                    index < currentStep
                      ? 'bg-[var(--brand)] text-white'
                      : index === currentStep
                        ? 'bg-[var(--brand-alpha-light)] txt-brand ring-2 ring-[var(--brand)]'
                        : 'surface-chip txt-secondary',
                  ]"
                >
                  <Icon v-if="index < currentStep" icon="heroicons:check" class="w-4 h-4" />
                  <template v-else>{{ index + 1 }}</template>
                </span>
                <span
                  :class="[
                    'text-xs font-medium hidden sm:block truncate',
                    index === currentStep ? 'txt-primary' : 'txt-secondary',
                  ]"
                >
                  {{ step.label }}
                </span>
              </button>
              <span
                v-if="index < steps.length - 1"
                class="flex-1 h-px mx-2 sm:mx-3"
                :class="
                  index < currentStep
                    ? 'bg-[var(--brand)]'
                    : 'bg-light-border/40 dark:bg-dark-border/30'
                "
              />
            </template>
          </div>
          <p class="text-xs txt-secondary mt-2 sm:hidden">
            {{
              $t('widgets.createWizard.stepCounter', {
                current: currentStep + 1,
                total: steps.length,
              })
            }}
            — {{ steps[currentStep].label }}
          </p>
        </div>

        <!-- Step content -->
        <div class="p-6 overflow-y-auto flex-1 min-h-0 scroll-thin">
          <StepBasics
            v-if="currentStep === 0"
            v-model:name="name"
            v-model:website-url="websiteUrl"
          />
          <StepAppearance
            v-else-if="currentStep === 1"
            v-model:primary-color="primaryColor"
            v-model:icon-color="iconColor"
            v-model:default-theme="defaultTheme"
            v-model:position="position"
            :widget-name="name"
          />
          <StepKnowledge
            v-else-if="currentStep === 2"
            v-model:uploads="uploads"
            v-model:linked-files="linkedFiles"
          />
          <StepOptions
            v-else
            v-model:auto-message="autoMessage"
            v-model:auto-open="autoOpen"
            v-model:allow-file-upload="allowFileUpload"
          />

          <!-- Error message -->
          <div
            v-if="errorMessage"
            class="mt-4 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm flex items-start gap-2"
          >
            <Icon icon="heroicons:exclamation-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" />
            <span>{{ errorMessage }}</span>
          </div>
        </div>

        <!-- Footer -->
        <div
          class="px-6 py-4 border-t border-light-border/30 dark:border-dark-border/20 flex-shrink-0 space-y-3"
        >
          <div class="flex items-center justify-between gap-3">
            <button
              v-if="currentStep === 0"
              type="button"
              class="px-4 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium text-sm"
              data-testid="btn-cancel"
              @click="handleClose"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              v-else
              type="button"
              class="px-4 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium text-sm flex items-center gap-1.5"
              :disabled="creating"
              data-testid="btn-wizard-back"
              @click="currentStep--"
            >
              <Icon icon="heroicons:arrow-left" class="w-4 h-4" />
              {{ $t('widgets.createWizard.back') }}
            </button>

            <button
              v-if="currentStep < steps.length - 1"
              type="button"
              :disabled="!canProceed"
              class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
              data-testid="btn-wizard-next"
              @click="currentStep++"
            >
              {{ $t('widgets.createWizard.next') }}
              <Icon icon="heroicons:arrow-right" class="w-4 h-4" />
            </button>
            <button
              v-else
              type="button"
              :disabled="creating"
              class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
              data-testid="btn-create"
              @click="handleCreate"
            >
              <Icon v-if="creating" icon="heroicons:arrow-path" class="w-4 h-4 animate-spin" />
              <Icon v-else icon="heroicons:rocket-launch" class="w-4 h-4" />
              {{ creating ? creatingLabel : $t('widgets.createWizard.create') }}
            </button>
          </div>
          <p class="text-xs txt-secondary text-center">
            {{ $t('widgets.createWizard.footerHint') }}
          </p>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useEscapeKey } from '@/composables/useEscapeKey'
import { useNotification } from '@/composables/useNotification'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi } from '@/services/api/promptsApi'
import StepBasics from './StepBasics.vue'
import StepAppearance from './StepAppearance.vue'
import StepKnowledge, { type LinkedFileRef } from './StepKnowledge.vue'
import StepOptions from './StepOptions.vue'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const emit = defineEmits<{
  close: []
  created: [widget: widgetsApi.Widget]
}>()

const { t } = useI18n()
const { error: showError } = useNotification()

// Step 1: basics
const name = ref('')
const websiteUrl = ref('')

// Step 2: appearance
const primaryColor = ref('#007bff')
const iconColor = ref('#ffffff')
const defaultTheme = ref<'light' | 'dark'>('light')
const position = ref<NonNullable<widgetsApi.WidgetConfig['position']>>('bottom-right')

// Step 3: data sources
const uploads = ref<File[]>([])
const linkedFiles = ref<LinkedFileRef[]>([])

// Step 4: options
const autoMessage = ref('')
const autoOpen = ref(false)
const allowFileUpload = ref(false)

const currentStep = ref(0)
const creating = ref(false)
const creationStage = ref<'widget' | 'design' | 'files'>('widget')
const errorMessage = ref<string | null>(null)

const steps = computed(() => [
  { id: 'basics', label: t('widgets.createWizard.steps.basics') },
  { id: 'appearance', label: t('widgets.createWizard.steps.appearance') },
  { id: 'knowledge', label: t('widgets.createWizard.steps.knowledge') },
  { id: 'options', label: t('widgets.createWizard.steps.options') },
])

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return name.value.trim().length >= 2 && websiteUrl.value.trim().length > 0
  }
  return true
})

const creatingLabel = computed(() => {
  if (creationStage.value === 'files') return t('widgets.createWizard.creatingFiles')
  if (creationStage.value === 'design') return t('widgets.createWizard.creatingDesign')
  return t('widgets.createWizard.creatingWidget')
})

const handleClose = () => {
  if (creating.value) return
  emit('close')
}

useEscapeKey(handleClose)

const handleCreate = async () => {
  if (creating.value) return
  creating.value = true
  errorMessage.value = null

  let widget: widgetsApi.Widget
  try {
    creationStage.value = 'widget'
    widget = await widgetsApi.quickCreateWidget({
      name: name.value.trim(),
      websiteUrl: websiteUrl.value.trim(),
    })
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : t('widgets.createWizard.createError')
    errorMessage.value = message
    showError(message)
    creating.value = false
    return
  }

  // The widget exists from here on. If a follow-up step fails, the user
  // still gets the success modal and can finish in the settings.
  try {
    creationStage.value = 'design'
    const config: widgetsApi.WidgetConfig = {
      ...widget.config,
      position: position.value,
      primaryColor: primaryColor.value,
      iconColor: iconColor.value,
      defaultTheme: defaultTheme.value,
      autoOpen: autoOpen.value,
      allowFileUpload: allowFileUpload.value,
    }
    if (autoMessage.value.trim()) {
      config.autoMessage = autoMessage.value.trim()
    }
    await widgetsApi.updateWidget(widget.widgetId, { config })
    widget.config = config

    if (uploads.value.length > 0 || linkedFiles.value.length > 0) {
      creationStage.value = 'files'
      // A widget on the shared default prompt cannot own knowledge-base
      // files — create its own prompt first, then attach the sources.
      const initialPrompt = `You are a helpful AI assistant for ${name.value.trim()}.

Your role is to assist visitors with their questions and provide helpful information.

Be friendly, professional, and concise in your responses.`
      const promptResult = await widgetsApi.generateWidgetPrompt(widget.widgetId, initialPrompt, [])
      widget.taskPromptTopic = promptResult.promptTopic

      for (const file of uploads.value) {
        await promptsApi.uploadPromptFile(promptResult.promptTopic, file)
      }
      for (const linked of linkedFiles.value) {
        await promptsApi.linkFileToPrompt(promptResult.promptTopic, linked.messageId)
      }
    }
  } catch (err: unknown) {
    console.error('Widget created, but finishing the setup failed:', err)
    showError(t('widgets.createWizard.finishError'))
  } finally {
    creating.value = false
  }

  emit('created', widget)
}
</script>
