<template>
  <div class="space-y-3" data-testid="step-inputs">
    <div v-if="step.capability === 'tool_call'" class="space-y-3">
      <div>
        <label class="text-sm font-medium txt-primary" :for="`${step.id}-tool`">
          {{ $t('workflows.pickTool') }}
        </label>
        <select
          :id="`${step.id}-tool`"
          :class="STEP_FIELD_CLASS"
          :value="stringParam('tool')"
          @change="setTool(($event.target as HTMLSelectElement).value)"
        >
          <option value="">{{ $t('workflows.pickTool') }}</option>
          <option v-for="tool in tools" :key="tool.name" :value="tool.name">
            {{ tool.title || tool.name }}
          </option>
        </select>
      </div>

      <template v-if="selectedTool">
        <p v-if="!toolArguments.length" class="text-xs txt-secondary">
          {{ $t('workflows.noArguments') }}
        </p>
        <div v-else class="space-y-3" data-testid="tool-arguments">
          <p class="text-sm font-medium txt-primary">{{ $t('workflows.arguments') }}</p>
          <div v-for="argument in toolArguments" :key="argument.name">
            <label class="block text-xs font-medium txt-secondary" :for="`${argument.name}-source`">
              {{ argument.name }}
              <span v-if="argument.required">· {{ $t('workflows.required') }}</span>
            </label>
            <InputSourcePicker
              :name="argument.name"
              :model-value="inputs()[argument.name]"
              :earlier-steps="earlierSteps"
              @update:model-value="setInput(argument.name, $event)"
            />
          </div>
        </div>
      </template>
    </div>

    <div v-if="step.capability === 'outbound_webhook'" class="space-y-3">
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.webhookUrl') }}
        <input
          :class="STEP_FIELD_CLASS"
          type="url"
          :value="stringParam('url')"
          placeholder="https://"
          @input="setParam('url', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <div>
        <label class="block text-sm font-medium txt-primary" for="result-source">
          {{ $t('workflows.whatToSend') }}
        </label>
        <InputSourcePicker
          name="result"
          :model-value="inputs().result"
          :earlier-steps="earlierSteps"
          @update:model-value="setInput('result', $event)"
        />
      </div>
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.webhookSecret') }}
        <input
          :class="STEP_FIELD_CLASS"
          type="password"
          :value="stringParam('secret')"
          :placeholder="secretConfigured ? $t('workflows.secretSet') : ''"
          autocomplete="new-password"
          data-testid="outbound-secret"
          @input="setSecret(($event.target as HTMLInputElement).value)"
        />
        <span v-if="secretConfigured" class="block text-xs txt-secondary mt-1 font-normal">
          {{ $t('workflows.secretSetHint') }}
        </span>
      </label>
    </div>

    <div v-if="step.capability === 'condition'" class="space-y-3">
      <label class="block text-sm font-medium txt-primary">
        {{ $t('workflows.condition') }}
        <select
          :class="STEP_FIELD_CLASS"
          :value="operator"
          @change="setParam('operator', ($event.target as HTMLSelectElement).value)"
        >
          <option value="not_empty">{{ $t('workflows.operator.not_empty') }}</option>
          <option value="equals">{{ $t('workflows.operator.equals') }}</option>
          <option value="contains">{{ $t('workflows.operator.contains') }}</option>
          <option value="matches">{{ $t('workflows.operator.matches') }}</option>
        </select>
      </label>
      <div>
        <label class="block text-sm font-medium txt-primary" for="input-source">
          {{ $t('workflows.inputValue') }}
        </label>
        <InputSourcePicker
          name="input"
          :model-value="inputs().input"
          :earlier-steps="earlierSteps"
          @update:model-value="setInput('input', $event)"
        />
      </div>
      <input
        v-if="operator !== 'not_empty'"
        :class="STEP_FIELD_CLASS"
        :value="stringParam('value')"
        :aria-label="$t('workflows.compareWith')"
        :placeholder="
          operator === 'matches' ? $t('workflows.patternPlaceholder') : $t('workflows.compareWith')
        "
        data-testid="condition-value"
        @input="setParam('value', ($event.target as HTMLInputElement).value)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import InputSourcePicker from './InputSourcePicker.vue'
import { STEP_FIELD_CLASS, toolArgumentNames, type AuthoredStep, type InputSpec } from './stepTypes'
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

const operator = computed(() => stringParam('operator') || 'not_empty')

// The server never returns a saved secret, only that one exists. Leaving the
// field untouched keeps it; typing replaces it; clearing the field removes it.
const secretConfigured = computed(
  () => props.step.params.secretConfigured === true && !('secret' in props.step.params)
)

const setSecret = (value: string) => {
  const params = { ...props.step.params }
  delete params.secretConfigured
  emit('change', { ...props.step, params: { ...params, secret: value } })
}

const selectedTool = computed(() => props.tools.find((tool) => tool.name === stringParam('tool')))

const toolArguments = computed(() => toolArgumentNames(selectedTool.value?.inputSchema))

const inputs = (): Record<string, InputSpec> => {
  const raw = props.step.params.inputs
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  const out: Record<string, InputSpec> = {}
  for (const [key, spec] of Object.entries(raw as Record<string, unknown>)) {
    if (spec && typeof spec === 'object' && !Array.isArray(spec)) {
      out[key] = { ...(spec as InputSpec) }
    }
  }
  return out
}

const patch = (params: Record<string, unknown>) => {
  emit('change', { ...props.step, params: { ...props.step.params, ...params } })
}

const setParam = (key: string, value: string) => {
  patch({ [key]: value })
}

// A different tool takes different arguments — start its mapping fresh.
const setTool = (name: string) => {
  patch({ tool: name, inputs: name === stringParam('tool') ? inputs() : {} })
}

const setInput = (key: string, spec: InputSpec) => {
  patch({ inputs: { ...inputs(), [key]: spec } })
}
</script>
