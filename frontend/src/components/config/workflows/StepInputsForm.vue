<template>
  <div class="space-y-3" data-testid="step-inputs">
    <div v-if="step.capability === 'tool_call'">
      <label class="text-sm font-medium txt-primary" :for="`${step.id}-tool`">
        {{ $t('workflows.pickTool') }}
      </label>
      <select
        :id="`${step.id}-tool`"
        class="mt-1 w-full"
        :class="STEP_FIELD_CLASS"
        :value="stringParam('tool')"
        @change="setParam('tool', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">{{ $t('workflows.pickTool') }}</option>
        <option v-for="tool in tools" :key="tool.name" :value="tool.name">
          {{ tool.title || tool.name }}
        </option>
      </select>
    </div>

    <div v-if="step.capability === 'outbound_webhook'" class="space-y-3">
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.webhookUrl') }}
        <input
          :class="STEP_FIELD_CLASS"
          :value="stringParam('url')"
          :placeholder="$t('workflows.webhookUrl')"
          @input="setParam('url', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.webhookSecret') }}
        <input
          :class="STEP_FIELD_CLASS"
          :value="stringParam('secret')"
          autocomplete="off"
          @input="setParam('secret', ($event.target as HTMLInputElement).value)"
        />
      </label>
    </div>

    <div v-if="step.capability === 'condition'" class="space-y-3">
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.condition') }}
        <select
          :class="STEP_FIELD_CLASS"
          :value="stringParam('operator') || 'not_empty'"
          @change="setParam('operator', ($event.target as HTMLSelectElement).value)"
        >
          <option value="not_empty">{{ $t('workflows.operator.not_empty') }}</option>
          <option value="equals">{{ $t('workflows.operator.equals') }}</option>
          <option value="contains">{{ $t('workflows.operator.contains') }}</option>
          <option value="matches">{{ $t('workflows.operator.matches') }}</option>
        </select>
      </label>
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.inputValue') }}
        <select
          :class="STEP_FIELD_CLASS"
          :value="inputSource('input')"
          @change="onSourceChange('input', ($event.target as HTMLSelectElement).value)"
        >
          <option value="literal">{{ $t('workflows.literal') }}</option>
          <option value="trigger">{{ $t('workflows.fromTrigger') }}</option>
          <option v-for="(earlier, index) in earlierSteps" :key="earlier.id" :value="earlier.id">
            {{ $t('workflows.fromStep', { n: index + 1 }) }}
          </option>
        </select>
      </label>
      <input
        v-if="inputSource('input') === 'literal'"
        :class="STEP_FIELD_CLASS"
        :value="inputLiteral('input')"
        @input="setLiteral('input', ($event.target as HTMLInputElement).value)"
      />
      <input
        v-if="stringParam('operator') === 'equals' || stringParam('operator') === 'contains'"
        :class="STEP_FIELD_CLASS"
        :value="stringParam('value')"
        :placeholder="$t('workflows.inputValue')"
        @input="setParam('value', ($event.target as HTMLInputElement).value)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { STEP_FIELD_CLASS, type AuthoredStep } from './stepTypes'
import type { RegistryTool } from '@/services/api/toolsApi'

const props = defineProps<{
  step: AuthoredStep
  earlierSteps: AuthoredStep[]
  tools: RegistryTool[]
}>()

const emit = defineEmits<{
  change: [step: AuthoredStep]
}>()

const stringParam = (key: string): string => {
  const value = props.step.params[key]
  return typeof value === 'string' ? value : ''
}

const inputs = (): Record<string, Record<string, unknown>> => {
  const raw = props.step.params.inputs
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  const out: Record<string, Record<string, unknown>> = {}
  for (const [key, spec] of Object.entries(raw as Record<string, unknown>)) {
    if (spec && typeof spec === 'object' && !Array.isArray(spec)) {
      out[key] = { ...(spec as Record<string, unknown>) }
    }
  }
  return out
}

const inputSource = (key: string): string => {
  const spec = inputs()[key]
  if (!spec) return 'literal'
  if (typeof spec.literal === 'string') return 'literal'
  if (spec.from === 'trigger') return 'trigger'
  return typeof spec.from === 'string' ? spec.from : 'literal'
}

const inputLiteral = (key: string): string => {
  const spec = inputs()[key]
  return typeof spec?.literal === 'string' ? spec.literal : ''
}

const patch = (params: Record<string, unknown>) => {
  emit('change', { ...props.step, params: { ...props.step.params, ...params } })
}

const setParam = (key: string, value: string) => {
  patch({ [key]: value })
}

const setInputs = (next: Record<string, Record<string, unknown>>) => {
  patch({ inputs: next })
}

const onSourceChange = (key: string, source: string) => {
  const next = inputs()
  if (source === 'literal') {
    next[key] = { literal: inputLiteral(key) }
  } else if (source === 'trigger') {
    next[key] = { from: 'trigger', field: 'body' }
  } else {
    next[key] = { from: source, field: 'text' }
  }
  setInputs(next)
}

const setLiteral = (key: string, value: string) => {
  const next = inputs()
  next[key] = { literal: value }
  setInputs(next)
}
</script>
