<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { savedTasksApi, type SavedTask, type SavedTaskRun } from '@/services/api/savedTasksApi'

const props = defineProps<{
  task: SavedTask
}>()

const emit = defineEmits<{
  updated: [task: SavedTask]
}>()

const { t, te, locale } = useI18n()
const router = useRouter()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const running = ref(false)
const showRuns = ref(false)
const showAdvanced = ref(false)
const runs = ref<SavedTaskRun[]>([])
const retention = ref('')
const scheduleKind = ref('off')
const scheduleAt = ref('07:00')
const scheduleTz = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Berlin')

watch(
  () => props.task,
  (task) => {
    if (task.triggerType === 'schedule' && task.triggerConfig) {
      const kind = task.triggerConfig.kind
      scheduleKind.value = typeof kind === 'string' ? kind : 'off'
      const at = task.triggerConfig.at
      if (typeof at === 'string') scheduleAt.value = at
      const tz = task.triggerConfig.tz
      if (typeof tz === 'string') scheduleTz.value = tz
    } else {
      scheduleKind.value = 'off'
    }
  },
  { immediate: true }
)

/**
 * Translates one summary part code (e.g. when=daily → "every day at 07:00").
 * Unknown codes (older/newer backend) fall back to a safe default so the card
 * never renders a raw i18n key.
 */
const summaryPart = (group: string, code: string, fallback: string): string => {
  const key = `config.savedTasks.summary.${group}.${code || fallback}`
  return t(te(key) ? key : `config.savedTasks.summary.${group}.${fallback}`, {
    at: props.task.summary.params.at ?? '',
    tz: props.task.summary.params.tz ?? '',
    minutes: props.task.summary.params.minutes ?? '',
  })
}

const summaryText = computed(() => {
  const params = props.task.summary.params
  const when = summaryPart('when', params.when, 'manual')
  if (props.task.summary.key === 'config.savedTasks.summary.template') {
    return t('config.savedTasks.summary.template', {
      when,
      reads: summaryPart('reads', params.reads, 'instruction'),
      saves: summaryPart('saves', params.saves, 'reply'),
    })
  }
  return t('config.savedTasks.summary.simple', { when })
})

/** ISO timestamps from the API become locale-formatted dates on the card. */
const formatWhen = (iso: string): string => {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    date
  )
}

const lastRunLine = computed(() => {
  if (running.value) return t('config.savedTasks.state.running')
  if (props.task.autoPaused) return t('config.savedTasks.state.autoPaused')
  if (!props.task.enabled) return t('config.savedTasks.state.off')
  if (!props.task.lastRunAt) {
    return props.task.nextRunAt
      ? t('config.savedTasks.state.scheduled', { when: formatWhen(props.task.nextRunAt) })
      : t('config.savedTasks.state.neverRun')
  }
  return t('config.savedTasks.state.lastRun', { when: formatWhen(props.task.lastRunAt) })
})

const onToggle = async () => {
  try {
    emit('updated', await savedTasksApi.update(props.task.id, { enabled: !props.task.enabled }))
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  }
}

const onRunNow = async () => {
  const message = await dialog.prompt({
    title: t('config.savedTasks.runNow'),
    message: t('config.savedTasks.runNowHint'),
    confirmText: t('config.savedTasks.runNow'),
  })
  if (!message || !message.trim()) return
  running.value = true
  try {
    const result = await savedTasksApi.run(props.task.id, message.trim())
    emit('updated', result.task)
    if (result.run.status === 'failed') {
      showError(result.run.error || t('config.savedTasks.runFailed'))
    } else {
      success(t('config.savedTasks.runStarted'))
      if (result.task.chatId) {
        await router.push({ path: '/', query: { chat: String(result.task.chatId) } })
      }
    }
  } catch {
    showError(t('config.savedTasks.runFailed'))
  } finally {
    running.value = false
  }
}

const onSchedule = async () => {
  try {
    if (scheduleKind.value === 'off') {
      emit(
        'updated',
        await savedTasksApi.update(props.task.id, {
          triggerType: 'manual',
          triggerConfig: null,
        })
      )
      return
    }
    const triggerConfig: Record<string, unknown> = {
      kind: scheduleKind.value,
      tz: scheduleTz.value,
    }
    if (scheduleKind.value === 'interval') {
      triggerConfig.every_minutes = 60
    } else {
      triggerConfig.at = scheduleAt.value
      if (scheduleKind.value === 'weekly') {
        triggerConfig.days = [1, 2, 3, 4, 5]
      }
    }
    emit(
      'updated',
      await savedTasksApi.update(props.task.id, {
        triggerType: 'schedule',
        triggerConfig,
        allowUnattended: true,
      })
    )
    success(t('config.savedTasks.scheduleSaved'))
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  }
}

const onResume = async () => {
  try {
    emit('updated', await savedTasksApi.resume(props.task.id))
    success(t('config.savedTasks.resumed'))
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  }
}

const loadRuns = async () => {
  showRuns.value = !showRuns.value
  if (!showRuns.value) return
  try {
    const result = await savedTasksApi.runs(props.task.id)
    runs.value = result.runs
    retention.value = result.retention
  } catch {
    showError(t('config.savedTasks.runsFailed'))
  }
}
</script>

<template>
  <section class="surface-card p-4 space-y-3" data-testid="saved-task-card">
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-semibold txt-primary">{{ task.name }}</h3>
      <label class="flex items-center gap-2 text-sm txt-secondary">
        <input
          type="checkbox"
          class="accent-[var(--brand)]"
          :checked="task.enabled"
          data-testid="saved-task-enabled"
          @change="onToggle"
        />
        {{ task.enabled ? $t('config.savedTasks.on') : $t('config.savedTasks.off') }}
      </label>
    </div>

    <p
      v-if="task.instructionPreview"
      class="text-sm txt-secondary italic truncate"
      :title="task.instructionPreview"
      data-testid="saved-task-preview"
    >
      “{{ task.instructionPreview }}”
    </p>
    <p class="text-sm txt-primary">{{ summaryText }}</p>
    <p class="text-xs txt-secondary" data-testid="saved-task-last-run">{{ lastRunLine }}</p>

    <div
      v-if="task.autoPaused"
      class="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-sm"
      data-testid="saved-task-auto-pause"
    >
      <p class="font-medium text-amber-800 dark:text-amber-200">
        {{ $t('config.savedTasks.autoPauseTitle') }}
      </p>
      <p class="text-amber-800/80 dark:text-amber-200/80 mt-1">
        {{ $t('config.savedTasks.autoPauseBody') }}
      </p>
      <button
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium mt-2"
        @click="onResume"
      >
        {{ $t('config.savedTasks.resume') }}
      </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium"
        :disabled="running"
        data-testid="btn-run-now"
        @click="onRunNow"
      >
        {{ $t('config.savedTasks.runNow') }}
      </button>
      <select
        v-model="scheduleKind"
        class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="saved-task-schedule"
        :disabled="!task.enabled"
        @change="onSchedule"
      >
        <option value="off">{{ $t('config.savedTasks.schedule.off') }}</option>
        <option value="interval">{{ $t('config.savedTasks.schedule.hourly') }}</option>
        <option value="daily">{{ $t('config.savedTasks.schedule.daily') }}</option>
        <option value="weekly">{{ $t('config.savedTasks.schedule.weekdays') }}</option>
      </select>
      <input
        v-if="scheduleKind === 'daily' || scheduleKind === 'weekly'"
        v-model="scheduleAt"
        type="time"
        class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        @change="onSchedule"
      />
      <span class="text-xs txt-secondary">{{ scheduleTz }}</span>
    </div>

    <button
      type="button"
      class="text-xs txt-secondary hover:txt-primary transition-colors"
      data-testid="btn-view-runs"
      @click="loadRuns"
    >
      {{ showRuns ? $t('config.savedTasks.hideRuns') : $t('config.savedTasks.viewRuns') }}
    </button>
    <ul v-if="showRuns" class="space-y-2 text-sm" data-testid="saved-task-runs">
      <li v-for="run in runs" :key="run.id" class="border-b border-light-border/20 pb-2">
        <span class="font-medium txt-primary">{{ run.status }}</span>
        <span class="txt-secondary"> · {{ run.trigger }}</span>
        <p v-if="run.error" class="txt-secondary mt-1">{{ run.error }}</p>
      </li>
      <li class="text-xs txt-secondary">{{ retention }}</li>
    </ul>

    <button
      type="button"
      class="text-xs txt-secondary hover:txt-primary transition-colors"
      data-testid="btn-advanced-steps"
      @click="showAdvanced = !showAdvanced"
    >
      {{ $t('config.savedTasks.advancedSteps') }}
    </button>
    <p v-if="showAdvanced" class="text-xs txt-secondary">
      {{ $t('config.savedTasks.advancedHint') }}
    </p>
  </section>
</template>
