<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import * as widgetsApi from '@/services/api/widgetsApi'
import type { AIModel, Capability } from '@/types/ai-models'

interface Props {
  widgetId: string
  models: Partial<Record<Capability, AIModel[]>>
  loadingModels: boolean
}

const props = defineProps<Props>()

const { t } = useI18n()
const { success, error: showError } = useNotification()
const dialog = useDialog()

const promptText = ref('')
const originalText = ref('')
const modelId = ref(-1)
const originalModelId = ref(-1)
const isDefault = ref(true)
const loading = ref(false)

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
  const ordered: Capability[] = ['CHAT', 'ANALYZE', 'PIC2TEXT']

  ordered.forEach((cap) => {
    if (props.models[cap]?.length) {
      groups.push({ label: capabilityLabels[cap] || cap, models: props.models[cap]!, capability: cap })
    }
  })
  return groups
})

const loadPrompt = async () => {
  loading.value = true
  try {
    const data = await widgetsApi.getSummaryPrompt(props.widgetId)
    promptText.value = data.prompt
    originalText.value = data.prompt
    modelId.value = data.modelId
    originalModelId.value = data.modelId
    isDefault.value = data.isDefault
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err)
    showError(message || t('widgets.advancedConfig.summaryPrompt.loadError'))
  } finally {
    loading.value = false
  }
}

const save = async () => {
  const promptChanged = promptText.value !== originalText.value
  const modelChanged = modelId.value !== originalModelId.value
  if (!promptChanged && !modelChanged) return

  await widgetsApi.updateSummaryPrompt(props.widgetId, promptText.value, modelId.value)
  originalText.value = promptText.value
  originalModelId.value = modelId.value
  isDefault.value = false
}

const resetPrompt = async () => {
  const confirmed = await dialog.confirm({
    title: t('widgets.advancedConfig.summaryPrompt.resetConfirmTitle'),
    message: t('widgets.advancedConfig.summaryPrompt.resetConfirmMessage'),
    confirmText: t('widgets.advancedConfig.summaryPrompt.resetToDefault'),
    cancelText: t('common.cancel'),
    danger: true,
  })

  if (!confirmed) return

  try {
    await widgetsApi.resetSummaryPrompt(props.widgetId)
    await loadPrompt()
    success(t('widgets.advancedConfig.summaryPrompt.resetSuccess'))
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err)
    showError(message || t('widgets.advancedConfig.summaryPrompt.saveError'))
  }
}

defineExpose({ save })

onMounted(loadPrompt)
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div>
      <div class="flex items-center gap-3 mb-2">
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <Icon icon="heroicons:chart-bar-square" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('widgets.advancedConfig.summaryPrompt.title') }}
        </h3>
        <span
          v-if="!loading"
          class="px-2 py-0.5 rounded-full text-xs font-medium"
          :class="isDefault
            ? 'bg-[var(--brand)]/10 text-[var(--brand)]'
            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'"
        >
          {{ isDefault
            ? $t('widgets.advancedConfig.summaryPrompt.defaultBadge')
            : $t('widgets.advancedConfig.summaryPrompt.customBadge')
          }}
        </span>
      </div>
      <p class="text-sm txt-secondary">
        {{ $t('widgets.advancedConfig.summaryPrompt.description') }}
      </p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <Icon icon="heroicons:arrow-path" class="w-6 h-6 txt-secondary animate-spin" />
    </div>

    <template v-else>
      <!-- AI Model Selection -->
      <div>
        <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
          <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
          {{ $t('widgets.advancedConfig.summaryPrompt.modelLabel') }}
        </label>
        <select
          v-model="modelId"
          class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        >
          <option :value="-1">
            ✨ {{ $t('widgets.advancedConfig.summaryPrompt.modelAutomatic') }}
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
                :value="model.id"
              >
                {{ model.name }} ({{ model.service }})
              </option>
            </optgroup>
          </template>
          <option v-if="loadingModels" disabled>Loading models...</option>
        </select>
        <p class="text-xs txt-secondary mt-1">
          {{ $t('widgets.advancedConfig.summaryPrompt.modelHelp') }}
        </p>
      </div>

      <!-- Placeholder Info -->
      <div class="p-4 rounded-lg bg-[var(--brand)]/5 border border-[var(--brand)]/20">
        <p class="text-sm font-medium text-[var(--brand)] mb-2 flex items-center gap-2">
          <Icon icon="heroicons:information-circle" class="w-4 h-4" />
          {{ $t('widgets.advancedConfig.summaryPrompt.placeholderInfo') }}
        </p>
        <div class="space-y-1.5">
          <div class="flex items-start gap-2 text-xs">
            <code
              class="px-1.5 py-0.5 rounded bg-[var(--brand)]/10 text-[var(--brand)] font-mono whitespace-nowrap"
              v-text="'{{CONVERSATIONS}}'"
            />
            <span class="txt-secondary">
              {{ $t('widgets.advancedConfig.summaryPrompt.placeholderConversations') }}
            </span>
          </div>
          <div class="flex items-start gap-2 text-xs">
            <code
              class="px-1.5 py-0.5 rounded bg-[var(--brand)]/10 text-[var(--brand)] font-mono whitespace-nowrap"
              v-text="'{{SYSTEM_PROMPT}}'"
            />
            <span class="txt-secondary">
              {{ $t('widgets.advancedConfig.summaryPrompt.placeholderSystemPrompt') }}
            </span>
          </div>
        </div>
      </div>

      <!-- Prompt Editor -->
      <textarea
        v-model="promptText"
        rows="18"
        class="w-full px-4 py-3 surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y font-mono rounded-lg"
        :disabled="loading"
      />

      <!-- Reset to Default -->
      <div v-if="!isDefault" class="flex items-center">
        <button
          class="px-4 py-2.5 rounded-lg text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-500/10 border border-red-500/30 transition-colors flex items-center gap-2"
          @click="resetPrompt"
        >
          <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
          {{ $t('widgets.advancedConfig.summaryPrompt.resetToDefault') }}
        </button>
      </div>
    </template>
  </div>
</template>
