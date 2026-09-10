<template>
  <section class="surface-card p-4 space-y-3" :data-testid="`step-row-${index}`">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold txt-primary">
          {{ $t('workflows.stepN', { n: index + 1 }) }}
        </h3>
        <p class="text-xs txt-secondary">{{ title }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn-secondary px-3 py-1.5 rounded-lg text-sm font-medium"
          :disabled="index === 0"
          :aria-label="$t('workflows.moveUp')"
          @click="emit('move', -1)"
        >
          {{ $t('workflows.moveUp') }}
        </button>
        <button
          type="button"
          class="btn-secondary px-3 py-1.5 rounded-lg text-sm font-medium"
          :disabled="index >= total - 1"
          :aria-label="$t('workflows.moveDown')"
          @click="emit('move', 1)"
        >
          {{ $t('workflows.moveDown') }}
        </button>
        <button
          type="button"
          class="btn-danger px-3 py-1.5 rounded-lg text-sm font-medium"
          @click="emit('remove')"
        >
          {{ $t('workflows.deleteStep') }}
        </button>
      </div>
    </div>

    <StepInputsForm
      :step="step"
      :earlier-steps="earlierSteps"
      :tools="tools"
      @change="emit('change', $event)"
    />

    <label class="flex items-start gap-2 text-sm txt-primary">
      <input
        type="checkbox"
        class="mt-1 accent-[var(--brand)]"
        :checked="step.params.approval === 'approve'"
        data-testid="step-ask-before"
        @change="onAskBefore(($event.target as HTMLInputElement).checked)"
      />
      <span>
        {{ $t('workflows.askBefore') }}
        <span class="block text-xs txt-secondary mt-0.5">{{ $t('workflows.askBeforeHint') }}</span>
      </span>
    </label>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import StepInputsForm from './StepInputsForm.vue'
import type { AuthoredStep } from './stepTypes'
import type { RegistryTool } from '@/services/api/toolsApi'

const props = defineProps<{
  step: AuthoredStep
  index: number
  total: number
  earlierSteps: AuthoredStep[]
  tools: RegistryTool[]
}>()

const emit = defineEmits<{
  change: [step: AuthoredStep]
  move: [delta: number]
  remove: []
}>()

const { t, te } = useI18n()

const title = computed(() => {
  if (props.step.capability === 'tool_call') {
    const tool = typeof props.step.params.tool === 'string' ? props.step.params.tool : ''
    const match = props.tools.find((item) => item.name === tool)
    return match?.title || tool || t('workflows.kinds.tool_call')
  }
  const key = `workflows.kinds.${props.step.capability}`
  return te(key) ? t(key) : props.step.capability
})

const onAskBefore = (checked: boolean) => {
  const params = { ...props.step.params }
  if (checked) {
    params.approval = 'approve'
  } else {
    delete params.approval
  }
  emit('change', { ...props.step, params })
}
</script>
