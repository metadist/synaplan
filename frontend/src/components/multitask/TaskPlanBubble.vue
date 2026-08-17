<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import type { TaskPlanState } from '@/stores/history'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { isSavedTasksEnabled } from '@/composables/useSavedTasksFeature'
import { promptsApi } from '@/services/api/promptsApi'
import { savedTasksApi } from '@/services/api/savedTasksApi'
import TaskCard from '@/components/multitask/TaskCard.vue'
import ExecutedPlanGraph from '@/components/multitask/ExecutedPlanGraph.vue'

const props = defineProps<{
  plan: TaskPlanState
  /** The user message that produced this plan — used when saving a task. */
  scheduleSource?: string
  guest?: boolean
}>()

const emit = defineEmits<{
  /** Bubbled from a failed TaskCard: retry that step with another model. */
  retryTask: [payload: { prompt: string; modelId: number }]
  /** Bubbled from a running TaskCard: stop that media step. */
  cancelTask: [nodeId: string]
}>()

const { t, locale } = useI18n()
const router = useRouter()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const doneCount = computed(() => props.plan.cards.filter((c) => c.state === 'done').length)
const showGraph = ref(false)
const canShowGraph = computed(() => props.plan.cards.length > 1)
const saving = ref(false)

const canSchedule = computed(
  () =>
    isSavedTasksEnabled() &&
    !props.guest &&
    !props.plan.active &&
    props.plan.cards.some((card) => card.state === 'done')
)

const onSchedule = async () => {
  if (saving.value) return
  const name = await dialog.prompt({
    title: t('config.savedTasks.scheduleThis'),
    message: t('config.savedTasks.scheduleThisHint'),
    confirmText: t('common.save'),
  })
  if (!name || !name.trim()) return

  const instruction = (props.scheduleSource ?? '').trim() || name.trim()
  saving.value = true
  try {
    const prompt = await promptsApi.createPrompt({
      topic: `saved-${Date.now()}`,
      shortDescription: name.trim(),
      prompt: instruction,
      language: locale.value || 'en',
      metadata: { tool_files: true, tool_mcp: false },
    })
    await savedTasksApi.create(prompt.id, name.trim())
    success(t('config.savedTasks.scheduledFromChat'))
    await router.push('/channels/tasks')
  } catch {
    showError(t('config.savedTasks.createFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="task-plan space-y-2" data-testid="task-plan">
    <div class="flex items-center gap-2 text-xs txt-muted">
      <Icon icon="mdi:sitemap-outline" class="w-4 h-4" />
      <span>{{ $t('taskPlan.title') }}</span>
      <span class="font-medium">· {{ doneCount }}/{{ plan.cards.length }}</span>
      <button
        v-if="canSchedule"
        type="button"
        class="icon-ghost inline-flex items-center p-1 rounded-lg"
        :disabled="saving"
        data-testid="btn-schedule-plan"
        :aria-label="$t('taskPlan.scheduleThis')"
        :title="$t('taskPlan.scheduleThis')"
        @click="onSchedule"
      >
        <Icon icon="heroicons:clock" class="w-4 h-4" />
      </button>
    </div>

    <TaskCard
      v-for="card in plan.cards"
      :key="card.nodeId"
      :card="card"
      @retry="emit('retryTask', $event)"
      @cancel="emit('cancelTask', $event)"
    />

    <button
      v-if="canShowGraph"
      type="button"
      class="text-xs txt-secondary hover:txt-primary"
      data-testid="btn-toggle-plan-graph"
      @click="showGraph = !showGraph"
    >
      {{ showGraph ? $t('taskPlan.hideSteps') : $t('taskPlan.showSteps') }}
    </button>
    <ExecutedPlanGraph v-if="showGraph && canShowGraph" :plan="plan" />
  </div>
</template>
