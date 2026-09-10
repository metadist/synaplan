<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="modal-overlay fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 bg-black/50"
      data-testid="saved-task-steps-editor"
      @click.self="emit('close')"
    >
      <div
        class="modal-panel surface-card w-full max-w-3xl overflow-y-auto scroll-thin p-4 sm:p-6 space-y-4"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('workflows.stepsTitle')"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold txt-primary">{{ $t('workflows.stepsTitle') }}</h2>
            <p class="text-sm txt-secondary mt-1">{{ $t('workflows.stepsSubtitle') }}</p>
          </div>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            @click="emit('close')"
          >
            {{ $t('workflows.close') }}
          </button>
        </div>

        <p v-if="!steps.length" class="text-sm txt-secondary">{{ $t('workflows.empty') }}</p>

        <div class="space-y-3">
          <StepRow
            v-for="(step, index) in steps"
            :key="step.id"
            :step="step"
            :index="index"
            :total="steps.length"
            :earlier-steps="steps.slice(0, index)"
            :tools="tools"
            @change="onChange(index, $event)"
            @move="move(index, $event)"
            @remove="remove(index)"
          />
        </div>

        <StepPicker v-model="picked" :tools="tools" />
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-add-step"
          :disabled="!picked"
          @click="addPicked"
        >
          {{ $t('workflows.addStep') }}
        </button>

        <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-save-steps"
            :disabled="saving"
            @click="save"
          >
            {{ $t('workflows.saveSteps') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { savedTasksApi, type SavedTask } from '@/services/api/savedTasksApi'
import { toolsApi, type RegistryTool } from '@/services/api/toolsApi'
import StepPicker from './StepPicker.vue'
import StepRow from './StepRow.vue'
import {
  emptyStep,
  graphFromSteps,
  renumberSteps,
  stepsFromGraph,
  type AuthoredStep,
} from './stepTypes'

const props = defineProps<{
  open: boolean
  task: SavedTask
}>()

const emit = defineEmits<{
  close: []
  updated: [task: SavedTask]
}>()

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()
const steps = ref<AuthoredStep[]>([])
const tools = ref<RegistryTool[]>([])
const picked = ref('chat')
const saving = ref(false)
const error = ref('')

watch(
  () => [props.open, props.task.id] as const,
  async ([open]) => {
    if (!open) return
    steps.value = stepsFromGraph(props.task.graph)
    error.value = ''
    try {
      tools.value = await toolsApi.list()
    } catch {
      tools.value = []
    }
  },
  { immediate: true }
)

const capabilityFromPick = (key: string): { capability: string; tool?: string } => {
  if (key.startsWith('tool:')) {
    return { capability: 'tool_call', tool: key.slice(5) }
  }
  return { capability: key }
}

const addPicked = () => {
  const chosen = capabilityFromPick(picked.value)
  const previous = steps.value[steps.value.length - 1]
  const step = emptyStep(chosen.capability, steps.value.length, {
    previousStepId: previous?.id,
    triggerType: props.task.triggerType,
  })
  if (chosen.tool) step.params.tool = chosen.tool
  steps.value = [...steps.value, step]
}

const onChange = (index: number, step: AuthoredStep) => {
  const next = [...steps.value]
  next[index] = step
  steps.value = next
}

const move = (index: number, delta: number) => {
  const target = index + delta
  if (target < 0 || target >= steps.value.length) return
  const next = [...steps.value]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row)
  steps.value = renumberSteps(next)
}

const remove = async (index: number) => {
  const ok = await dialog.confirm({
    title: t('workflows.deleteStep'),
    message: t('workflows.deleteStepConfirm', { n: index + 1 }),
    danger: true,
  })
  if (!ok) return
  steps.value = renumberSteps(steps.value.filter((_, i) => i !== index))
}

const save = async () => {
  saving.value = true
  error.value = ''
  try {
    const updated = await savedTasksApi.update(props.task.id, {
      graph: graphFromSteps(steps.value, props.task.triggerType, props.task.graph),
    })
    success(t('workflows.saved'))
    emit('updated', updated)
    emit('close')
  } catch (err) {
    const message = err instanceof Error ? err.message : t('workflows.saveFailed')
    error.value = message
    showError(t('workflows.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>
