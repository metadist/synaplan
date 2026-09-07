<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-models">
    <h2 class="txt-primary font-medium">{{ $t('assistants.models') }}</h2>
    <label v-for="slot in slots" :key="slot.key" class="block">
      <span class="txt-secondary text-sm">{{ slot.label }}</span>
      <select
        class="mt-1 w-full"
        :value="modelKey(slot.key) ?? ''"
        :data-testid="`select-model-${slot.key}`"
        @change="patch(slot.key, ($event.target as HTMLSelectElement).value)"
      >
        <option value="">{{ $t('assistants.useDefault') }}</option>
        <option v-for="option in slot.options" :key="option.key" :value="option.key">
          {{ option.label }}
        </option>
      </select>
    </label>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAgentsStore } from '@/stores/agents'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import type { AIModel, Capability } from '@/types/ai-models'

const { t } = useI18n()
const store = useAgentsStore()
const aiConfig = useAiConfigStore()

const slotDefs: { key: 'chat' | 'vision' | 'vectorize'; cap: Capability; labelKey: string }[] = [
  { key: 'chat', cap: 'CHAT', labelKey: 'assistants.modelChat' },
  { key: 'vision', cap: 'PIC2TEXT', labelKey: 'assistants.modelVision' },
  { key: 'vectorize', cap: 'VECTORIZE', labelKey: 'assistants.modelVectorize' },
]

function catalogKey(model: AIModel): string {
  const service = model.service.toLowerCase()
  const provider = (model.providerId ?? '').toLowerCase().replaceAll(':', '-')
  const tag = (model.tag ?? 'chat').toLowerCase()
  return `${service}:${provider}:${tag}`
}

const slots = computed(() =>
  slotDefs.map((slot) => ({
    key: slot.key,
    label: t(slot.labelKey),
    options: (aiConfig.models[slot.cap] ?? []).map((model) => ({
      key: catalogKey(model),
      label: model.name || model.providerId,
    })),
  }))
)

function modelKey(slot: 'chat' | 'vision' | 'vectorize'): string | null {
  const value = store.current?.draft?.models[slot]
  return typeof value === 'string' && value !== '' ? value : null
}

function patch(slot: 'chat' | 'vision' | 'vectorize', value: string): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    models: { ...draft.models, [slot]: value === '' ? null : value },
  }
  store.markDirty()
}

onMounted(() => {
  if (Object.keys(aiConfig.models).length === 0) {
    void aiConfig.loadModels()
  }
})
</script>
