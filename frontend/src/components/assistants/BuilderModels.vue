<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-models">
    <h2 class="txt-primary font-medium">{{ $t('assistants.models') }}</h2>
    <label v-for="slot in slots" :key="slot.key" class="block">
      <span class="txt-secondary text-sm">{{ slot.label }}</span>
      <select
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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

    <details class="space-y-3" data-testid="details-models-advanced">
      <summary class="txt-primary text-sm font-medium cursor-pointer">
        {{ $t('assistants.advancedSettings') }}
      </summary>
      <p class="txt-secondary text-sm">{{ $t('assistants.advancedSettingsHint') }}</p>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.creativity') }}</span>
        <input
          :value="parameters.temperature"
          type="number"
          min="0"
          max="2"
          step="0.1"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="input-temperature"
          @input="patchParam('temperature', Number(($event.target as HTMLInputElement).value))"
        />
      </label>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.answerLength') }}</span>
        <input
          :value="parameters.maxTokens"
          type="number"
          min="1"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="input-max-tokens"
          @input="patchParam('maxTokens', Number(($event.target as HTMLInputElement).value))"
        />
      </label>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.language') }}</span>
        <select
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :value="parameters.language"
          data-testid="select-language"
          @change="patchParam('language', ($event.target as HTMLSelectElement).value)"
        >
          <option value="auto">{{ $t('assistants.languageAuto') }}</option>
          <option v-for="option in languageOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.responseFormat') }}</span>
        <textarea
          :value="responseSchemaText"
          rows="4"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
          :placeholder="$t('assistants.responseFormatPlaceholder')"
          data-testid="input-response-schema"
          @input="onSchemaInput(($event.target as HTMLTextAreaElement).value)"
        />
        <span v-if="schemaError" class="block text-sm text-red-600 dark:text-red-400 mt-1">
          {{ schemaError }}
        </span>
      </label>
    </details>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAgentsStore } from '@/stores/agents'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { languageOptions } from '@/i18n'
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

const parameters = computed(() => store.current?.draft?.parameters ?? emptyAgentDraft().parameters)
const responseSchemaText = computed(() => {
  const schema = parameters.value.responseSchema
  return schema ? JSON.stringify(schema, null, 2) : ''
})
const schemaError = ref('')

function patchParam(
  key: 'temperature' | 'maxTokens' | 'language' | 'responseSchema',
  value: number | string | Record<string, unknown> | null
): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    parameters: { ...draft.parameters, [key]: value },
  }
  store.markDirty()
}

function onSchemaInput(raw: string): void {
  if (raw.trim() === '') {
    schemaError.value = ''
    patchParam('responseSchema', null)
    return
  }
  try {
    const parsed: unknown = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      schemaError.value = t('assistants.responseFormatInvalid')
      return
    }
    schemaError.value = ''
    patchParam('responseSchema', parsed as Record<string, unknown>)
  } catch {
    schemaError.value = t('assistants.responseFormatInvalid')
  }
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
