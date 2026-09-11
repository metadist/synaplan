<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { isWorkflowsBuilderEnabled } from '@/composables/useWorkflowsFeature'
import ShareDialog from '@/components/iam/ShareDialog.vue'
import SharedResourceBanner from '@/components/iam/SharedResourceBanner.vue'
import SavedTaskStepsEditor from '@/components/config/workflows/SavedTaskStepsEditor.vue'
import { savedTasksApi, type SavedTask, type SavedTaskRun } from '@/services/api/savedTasksApi'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { ApiError } from '@/services/api/httpClient'
import type { ShareVia } from '@/utils/shareCopy'

const props = defineProps<{
  task: SavedTask
  sharedView?: boolean
  ownerName?: string
  sharedVia?: ShareVia | null
  permission?: string | null
}>()

const emit = defineEmits<{
  updated: [task: SavedTask]
  copied: [task: SavedTask]
  deleted: [id: number]
}>()

const { t, te, locale } = useI18n()
const router = useRouter()
const { success, error: showError } = useNotification()
const dialog = useDialog()
const iamSharingEnabled = computed(() => isIamSharingEnabled())
const workflowsEnabled = computed(() => isWorkflowsBuilderEnabled())
const iamShareOpen = ref(false)
const stepsOpen = ref(false)
const copying = ref(false)
const hmacSaving = ref(false)

const running = ref(false)
const showRuns = ref(false)
const showAdvanced = ref(false)
const runs = ref<SavedTaskRun[]>([])
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
    } else if (task.triggerType === 'webhook') {
      scheduleKind.value = 'webhook'
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

/**
 * Runs the task immediately with its stored instruction — no dialog. The
 * backend falls back to the saved prompt when no message is sent, so the
 * user never has to re-type what the task already knows.
 */
const onRunNow = async () => {
  running.value = true
  try {
    const result = await savedTasksApi.run(props.task.id)
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

const webhookToken = computed(() => {
  const token = props.task.triggerConfig?.token
  return typeof token === 'string' ? token : ''
})

const webhookUrl = computed(() => {
  if (!webhookToken.value) return ''
  const base = getApiBaseUrl().replace(/\/$/, '')
  return `${base}/api/v1/webhooks/saved-tasks/${webhookToken.value}`
})

const hmacConfigured = computed(() => props.task.triggerConfig?.hmacConfigured === true)

// The server mints the shared secret and returns it exactly once; keep it only
// in memory until the user leaves or turns the signature off.
const revealedSecret = ref('')
watch(hmacConfigured, (on) => {
  if (!on) revealedSecret.value = ''
})

const copyToClipboard = async (value: string, doneMessage: string) => {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    success(doneMessage)
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  }
}

const copyWebhookUrl = () => copyToClipboard(webhookUrl.value, t('workflows.urlCopied'))
const copyWebhookSecret = () => copyToClipboard(revealedSecret.value, t('workflows.secretCopied'))

const regenerateWebhook = async () => {
  const ok = await dialog.confirm({
    title: t('workflows.regenerate'),
    message: t('workflows.regenerateConfirm'),
    danger: true,
  })
  if (!ok) return
  try {
    emit(
      'updated',
      await savedTasksApi.update(props.task.id, {
        triggerType: 'webhook',
        regenerateWebhookToken: true,
      })
    )
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  }
}

const onHmacToggle = async () => {
  hmacSaving.value = true
  try {
    const { webhookSecret, ...updated } = await savedTasksApi.update(props.task.id, {
      triggerType: 'webhook',
      hmacEnabled: !hmacConfigured.value,
    })
    // Show it here, once; the list state never carries the secret.
    revealedSecret.value = webhookSecret ?? ''
    emit('updated', updated)
  } catch {
    showError(t('config.savedTasks.updateFailed'))
  } finally {
    hmacSaving.value = false
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
    if (scheduleKind.value === 'webhook') {
      emit(
        'updated',
        await savedTasksApi.update(props.task.id, {
          triggerType: 'webhook',
        })
      )
      success(t('config.savedTasks.scheduleSaved'))
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
    runs.value = (await savedTasksApi.runs(props.task.id)).runs
  } catch {
    showError(t('config.savedTasks.runsFailed'))
  }
}

/** Localizes a run status/trigger code; unknown codes render as-is. */
const runLabel = (group: 'runStatus' | 'runTrigger', code: string): string => {
  const key = `config.savedTasks.${group}.${code}`
  return te(key) ? t(key) : code
}

/** Opens the task's chat — every run appends its reply (text, images, files) there. */
const openResults = () => {
  if (!props.task.chatId) return
  void router.push({ path: '/', query: { chat: String(props.task.chatId) } })
}

const onDelete = async () => {
  const ok = await dialog.confirm({
    title: t('config.savedTasks.delete'),
    message: t('config.savedTasks.deleteConfirm', { name: props.task.name }),
    confirmText: t('config.savedTasks.delete'),
    danger: true,
  })
  if (!ok) return
  try {
    await savedTasksApi.remove(props.task.id)
    success(t('config.savedTasks.deleteSuccess'))
    emit('deleted', props.task.id)
  } catch {
    showError(t('config.savedTasks.deleteFailed'))
  }
}

const onRunCopy = async () => {
  const ok = await dialog.confirm({
    title: t('iam.runCopy'),
    message: t('iam.runCopyConfirm'),
  })
  if (!ok) return
  copying.value = true
  try {
    emit('copied', await savedTasksApi.copy(props.task.id))
    success(t('iam.runCopy'))
  } catch (err) {
    const message = err instanceof ApiError ? err.message : ''
    showError(
      message === 'iam.assistantNotShared'
        ? t('iam.assistantNotShared')
        : t('config.savedTasks.runFailed')
    )
  } finally {
    copying.value = false
  }
}
</script>

<template>
  <section class="surface-card p-4 space-y-3" data-testid="saved-task-card">
    <div class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <h3 class="font-semibold txt-primary">{{ task.name }}</h3>
        <p v-if="sharedView && ownerName && !sharedVia" class="text-xs txt-secondary">
          {{ $t('iam.sharedBy', { name: ownerName }) }}
        </p>
      </div>
      <label v-if="!sharedView" class="flex items-center gap-2 text-sm txt-secondary">
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

    <SharedResourceBanner
      v-if="sharedView"
      kind="saved_task"
      :owner-name="ownerName ?? null"
      :shared-via="sharedVia"
      :permission="permission"
    />

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

    <button
      v-if="task.waitingApprovalCount > 0"
      type="button"
      class="pill text-sm font-medium"
      data-testid="saved-task-waiting-approval"
      @click="router.push({ path: '/channels/approvals', query: { task: String(task.id) } })"
    >
      {{ $t('config.savedTasks.waitingApproval') }}
      <span class="txt-secondary">{{ task.waitingApprovalCount }}</span>
    </button>

    <div v-if="task.autoPaused" class="alert-warning text-sm" data-testid="saved-task-auto-pause">
      <p class="alert-warning-text">
        {{ $t('config.savedTasks.autoPauseTitle') }}
      </p>
      <p class="alert-warning-text mt-1 font-normal">
        {{ $t('config.savedTasks.autoPauseBody') }}
      </p>
      <button
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-medium mt-2"
        @click="onResume"
      >
        {{ $t('config.savedTasks.resume') }}
      </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button
        v-if="sharedView"
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="copying"
        data-testid="btn-run-copy"
        @click="onRunCopy"
      >
        {{ $t('iam.runCopy') }}
      </button>
      <button
        v-else
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="running"
        data-testid="btn-run-now"
        @click="onRunNow"
      >
        {{ $t('config.savedTasks.runNow') }}
      </button>
      <button
        v-if="iamSharingEnabled && !sharedView"
        type="button"
        class="btn-secondary inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-share-saved-task"
        @click="iamShareOpen = true"
      >
        {{ $t('iam.share') }}
      </button>
      <button
        v-if="!sharedView"
        type="button"
        class="btn-danger inline-flex items-center px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-delete-saved-task"
        @click="onDelete"
      >
        {{ $t('config.savedTasks.delete') }}
      </button>
      <select
        v-if="!sharedView"
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
        <option v-if="workflowsEnabled || scheduleKind === 'webhook'" value="webhook">
          {{ $t('config.savedTasks.schedule.webhook') }}
        </option>
      </select>
      <input
        v-if="!sharedView && (scheduleKind === 'daily' || scheduleKind === 'weekly')"
        v-model="scheduleAt"
        type="time"
        class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        @change="onSchedule"
      />
      <span class="text-xs txt-secondary">{{ scheduleTz }}</span>
    </div>

    <div
      v-if="!sharedView"
      class="flex flex-wrap items-center gap-3 pt-3 border-t border-light-border/20 dark:border-dark-border/20"
    >
      <button
        v-if="task.chatId"
        type="button"
        class="btn-secondary inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium"
        data-testid="btn-show-results"
        @click="openResults"
      >
        {{ $t('config.savedTasks.showResults') }}
      </button>
      <button
        type="button"
        class="btn-secondary inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium"
        data-testid="btn-view-runs"
        @click="loadRuns"
      >
        {{ showRuns ? $t('config.savedTasks.hideRuns') : $t('config.savedTasks.viewRuns') }}
      </button>
      <button
        v-if="workflowsEnabled && !sharedView"
        type="button"
        class="btn-secondary inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium"
        data-testid="btn-saved-task-steps"
        @click="stepsOpen = true"
      >
        {{ $t('workflows.steps') }}
      </button>
      <button
        v-else-if="!workflowsEnabled"
        type="button"
        class="btn-secondary inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium"
        data-testid="btn-advanced-steps"
        @click="showAdvanced = !showAdvanced"
      >
        {{ $t('config.savedTasks.advancedSteps') }}
      </button>
    </div>

    <ul v-if="showRuns" class="space-y-2 text-sm" data-testid="saved-task-runs">
      <li
        v-for="run in runs"
        :key="run.id"
        class="flex items-start justify-between gap-3 border-b border-light-border/20 dark:border-dark-border/20 pb-2"
      >
        <div class="min-w-0">
          <span class="font-medium txt-primary">{{ runLabel('runStatus', run.status) }}</span>
          <span class="txt-secondary"> · {{ runLabel('runTrigger', run.trigger) }}</span>
          <p v-if="run.error" class="txt-secondary mt-1">{{ run.error }}</p>
        </div>
        <span v-if="run.started" class="text-xs txt-secondary whitespace-nowrap">
          {{ formatWhen(run.started) }}
        </span>
      </li>
      <li v-if="runs.length" class="text-xs txt-secondary">
        {{ $t('config.savedTasks.runsRetention') }}
      </li>
      <li v-else class="text-xs txt-secondary">{{ $t('config.savedTasks.runsEmpty') }}</li>
    </ul>

    <p v-if="showAdvanced && !workflowsEnabled" class="text-xs txt-secondary">
      {{ $t('config.savedTasks.advancedHint') }}
    </p>

    <div
      v-if="workflowsEnabled && task.triggerType === 'webhook' && !sharedView"
      class="surface-card p-4 space-y-3"
      data-testid="saved-task-webhook"
    >
      <p class="text-sm font-medium txt-primary">{{ $t('workflows.webhookCardTitle') }}</p>
      <p class="text-xs txt-secondary">{{ $t('workflows.webhookCardHint') }}</p>
      <input
        class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :value="webhookUrl"
        :aria-label="$t('workflows.webhookCardTitle')"
        readonly
        data-testid="saved-task-webhook-url"
      />
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-copy-webhook-url"
          @click="copyWebhookUrl"
        >
          {{ $t('workflows.copyUrl') }}
        </button>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-regenerate-webhook"
          @click="regenerateWebhook"
        >
          {{ $t('workflows.regenerate') }}
        </button>
      </div>
      <label class="flex items-start gap-2 text-sm txt-primary">
        <input
          type="checkbox"
          class="mt-1 accent-[var(--brand)]"
          :checked="hmacConfigured"
          :disabled="hmacSaving"
          data-testid="saved-task-webhook-hmac"
          @change="onHmacToggle"
        />
        <span>
          {{ $t('workflows.hmacEnable') }}
          <span class="block text-xs txt-secondary mt-0.5">
            {{ hmacConfigured ? $t('workflows.hmacOn') : $t('workflows.hmacHint') }}
          </span>
        </span>
      </label>
      <div
        v-if="revealedSecret"
        class="alert-warning text-sm space-y-2"
        data-testid="saved-task-webhook-secret"
      >
        <p class="alert-warning-text">{{ $t('workflows.secretRevealTitle') }}</p>
        <p class="alert-warning-text font-normal">{{ $t('workflows.secretRevealHint') }}</p>
        <input
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :value="revealedSecret"
          :aria-label="$t('workflows.secretRevealTitle')"
          readonly
          data-testid="saved-task-webhook-secret-value"
        />
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-copy-webhook-secret"
          @click="copyWebhookSecret"
        >
          {{ $t('workflows.copySecret') }}
        </button>
      </div>
    </div>

    <SavedTaskStepsEditor
      :open="stepsOpen"
      :task="task"
      @close="stepsOpen = false"
      @updated="emit('updated', $event)"
    />
    <ShareDialog
      :is-open="iamShareOpen"
      kind="saved_task"
      :resource-id="String(task.id)"
      :resource-name="task.name"
      @close="iamShareOpen = false"
    />
  </section>
</template>
