<script setup lang="ts">
import { ref } from 'vue'
import {
  getSetupPrompt,
  getSummaryPrompt,
  resetSetupPrompt,
  resetSummaryPrompt,
  updateSetupPrompt,
  updateSummaryPrompt,
} from '@/services/api/widgetsApi'
import type { AIModel, Capability } from '@/types/ai-models'
import WidgetAiPromptSection from './WidgetAiPromptSection.vue'

interface Props {
  widgetId: string
  models: Partial<Record<Capability, AIModel[]>>
  loadingModels: boolean
}

defineProps<Props>()

const summarySection = ref<InstanceType<typeof WidgetAiPromptSection> | null>(null)
const setupSection = ref<InstanceType<typeof WidgetAiPromptSection> | null>(null)

const summaryPlaceholders = [
  {
    key: '{{CONVERSATIONS}}',
    descriptionKey: 'widgets.advancedConfig.summaryPrompt.placeholderConversations',
  },
  {
    key: '{{SYSTEM_PROMPT}}',
    descriptionKey: 'widgets.advancedConfig.summaryPrompt.placeholderSystemPrompt',
  },
]

const save = async () => {
  await Promise.all([summarySection.value?.save(), setupSection.value?.save()])
}

defineExpose({ save })
</script>

<template>
  <div class="space-y-5">
    <!-- Summary Analysis Prompt -->
    <WidgetAiPromptSection
      ref="summarySection"
      icon="heroicons:chart-bar-square"
      title-key="widgets.advancedConfig.summaryPrompt.title"
      description-key="widgets.advancedConfig.summaryPrompt.description"
      model-help-key="widgets.advancedConfig.summaryPrompt.modelHelp"
      :models="models"
      :loading-models="loadingModels"
      :placeholders="summaryPlaceholders"
      :load-fn="() => getSummaryPrompt(widgetId)"
      :save-fn="(prompt, modelId) => updateSummaryPrompt(widgetId, prompt, modelId)"
      :reset-fn="() => resetSummaryPrompt(widgetId)"
    />

    <!-- Setup Interview Prompt — folded by default so it doesn't distract
         from the primary Prompt & Model configuration above. -->
    <WidgetAiPromptSection
      ref="setupSection"
      icon="heroicons:chat-bubble-bottom-center-text"
      title-key="widgets.advancedConfig.setupPrompt.title"
      description-key="widgets.advancedConfig.setupPrompt.description"
      model-help-key="widgets.advancedConfig.setupPrompt.modelHelp"
      :models="models"
      :loading-models="loadingModels"
      :initial-collapsed="true"
      :load-fn="() => getSetupPrompt(widgetId)"
      :save-fn="(prompt, modelId) => updateSetupPrompt(widgetId, prompt, modelId)"
      :reset-fn="() => resetSetupPrompt(widgetId)"
    />
  </div>
</template>
