<template>
  <div class="space-y-2" :data-testid="`input-source-${name}`">
    <select
      :id="`${name}-source`"
      :class="STEP_FIELD_CLASS"
      :aria-label="$t('workflows.inputSource')"
      :value="source"
      @change="onSourceChange(($event.target as HTMLSelectElement).value)"
    >
      <option value="literal">{{ $t('workflows.literal') }}</option>
      <option value="trigger">{{ $t('workflows.fromTrigger') }}</option>
      <option v-for="(earlier, index) in earlierSteps" :key="earlier.id" :value="earlier.id">
        {{ $t('workflows.fromStep', { n: index + 1 }) }}
      </option>
    </select>
    <input
      v-if="source === 'literal'"
      :class="STEP_FIELD_CLASS"
      :value="literal"
      :aria-label="$t('workflows.inputValue')"
      :placeholder="$t('workflows.inputValue')"
      @input="setLiteral(($event.target as HTMLInputElement).value)"
    />
    <template v-else>
      <input
        :class="STEP_FIELD_CLASS"
        :value="field"
        :aria-label="$t('workflows.field')"
        :placeholder="source === 'trigger' ? 'title' : 'text'"
        @input="setField(($event.target as HTMLInputElement).value)"
      />
      <p class="text-xs txt-secondary leading-relaxed">
        {{
          source === 'trigger' ? $t('workflows.fieldHintTrigger') : $t('workflows.fieldHintStep')
        }}
      </p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { STEP_FIELD_CLASS, type AuthoredStep, type InputSpec } from './stepTypes'

const props = defineProps<{
  /** Input name; also used for test ids and the select id. */
  name: string
  modelValue: InputSpec | undefined
  earlierSteps: AuthoredStep[]
}>()

const emit = defineEmits<{
  'update:modelValue': [spec: InputSpec]
}>()

const spec = computed<InputSpec>(() => props.modelValue ?? { literal: '' })

const source = computed(() => {
  if ('literal' in spec.value) return 'literal'
  const from = spec.value.from
  if (from === 'trigger') return 'trigger'
  return typeof from === 'string' && props.earlierSteps.some((step) => step.id === from)
    ? from
    : 'literal'
})

const literal = computed(() => (typeof spec.value.literal === 'string' ? spec.value.literal : ''))

const field = computed(() => (typeof spec.value.field === 'string' ? spec.value.field : ''))

const onSourceChange = (next: string) => {
  if (next === 'literal') {
    emit('update:modelValue', { literal: literal.value })
  } else if (next === 'trigger') {
    emit('update:modelValue', { from: 'trigger', field: '' })
  } else {
    emit('update:modelValue', { from: next, field: 'text' })
  }
}

const setLiteral = (value: string) => {
  emit('update:modelValue', { literal: value })
}

const setField = (value: string) => {
  emit('update:modelValue', { from: source.value, field: value })
}
</script>
