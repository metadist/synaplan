<script setup lang="ts">
import { computed, toRef } from 'vue'
import { Icon } from '@iconify/vue'
import type { MediaJobInfo } from '@/stores/history'
import type { MediaJobPollResult } from '@/services/api/mediaJobApi'
import {
  useMediaJobPoll,
  formatElapsedDuration,
  MEDIA_JOB_POLL_INTERVAL_MS,
} from '@/composables/useMediaJobPoll'

const props = defineProps<{
  mediaJob: MediaJobInfo
  modelLabel?: string
}>()

const emit = defineEmits<{
  'update:mediaJob': [value: MediaJobInfo]
  completed: [payload: { url: string; type: string }]
}>()

const jobId = computed(() => props.mediaJob.jobId)
const pollEnabled = computed(() => props.mediaJob.state === 'running')

const live = computed({
  get: () => props.mediaJob,
  set: (value: MediaJobInfo) => emit('update:mediaJob', value),
})

function applyPollStatus(status: MediaJobPollResult) {
  live.value = {
    jobId: status.job_id,
    type: status.type,
    state: status.state,
    error: status.error ?? undefined,
    percent: status.percent ?? undefined,
    elapsedSeconds: status.elapsed_seconds,
    maxWaitSeconds: status.max_wait_seconds,
    remainingSeconds: status.remaining_seconds ?? undefined,
    stalled: status.stalled ?? undefined,
    stallReason: status.stall_reason ?? undefined,
  }

  if (status.state === 'done' && status.file?.url) {
    emit('completed', { url: status.file.url, type: status.file.type ?? status.type })
  }
}

function handleJobLost() {
  live.value = {
    ...props.mediaJob,
    state: 'failed',
    error: undefined,
    lost: true,
  }
}

const { secondsSinceCheck, isFetching, fetchError, latest, refreshNow } = useMediaJobPoll(
  toRef(() => jobId.value),
  pollEnabled,
  applyPollStatus,
  handleJobLost
)

const isFailed = computed(
  () =>
    props.mediaJob.state === 'failed' ||
    props.mediaJob.state === 'cancelled' ||
    props.mediaJob.lost === true
)

const iconForType = computed(() => {
  switch (props.mediaJob.type) {
    case 'video':
      return 'mdi:video-outline'
    case 'image':
      return 'mdi:image-outline'
    case 'audio':
      return 'mdi:waveform'
    default:
      return 'mdi:cog-outline'
  }
})

const titleKey = computed(() => {
  if (isFailed.value) return `message.mediaJob.failedTitle.${props.mediaJob.type}`
  return `message.mediaJob.title.${props.mediaJob.type}`
})

const elapsedSeconds = computed(() => {
  if (latest.value?.elapsed_seconds !== undefined) {
    return latest.value.elapsed_seconds
  }
  return props.mediaJob.elapsedSeconds ?? 0
})

const elapsedLabel = computed(() => formatElapsedDuration(elapsedSeconds.value))

const maxWaitMinutes = computed(() => {
  const seconds = props.mediaJob.maxWaitSeconds ?? latest.value?.max_wait_seconds ?? 1200
  return Math.max(1, Math.round(seconds / 60))
})

const isOverdue = computed(() => {
  if (isFailed.value) return false
  const remaining = latest.value?.remaining_seconds ?? props.mediaJob.remainingSeconds
  if (remaining !== undefined && remaining !== null) {
    return remaining <= 0
  }
  const maxWait = props.mediaJob.maxWaitSeconds ?? latest.value?.max_wait_seconds
  if (maxWait && elapsedSeconds.value > maxWait) {
    return true
  }
  return false
})

const progressPercent = computed(() => {
  const raw = latest.value?.percent ?? props.mediaJob.percent
  if (raw === undefined || raw === null) return null
  return Math.max(0, Math.min(100, Math.round(raw)))
})

const pollIntervalSeconds = Math.round(MEDIA_JOB_POLL_INTERVAL_MS / 1000)

const failureMessage = computed(() => {
  if (props.mediaJob.lost) {
    return undefined
  }
  return props.mediaJob.error
})
</script>

<template>
  <div
    class="surface-card rounded-xl border p-4"
    :class="isFailed || isOverdue ? 'border-danger' : 'border-brand'"
    role="status"
    :aria-label="$t(titleKey)"
    data-testid="media-job-status"
  >
    <div class="flex items-start gap-3">
      <Icon
        :icon="isFailed ? 'mdi:alert-circle-outline' : iconForType"
        class="w-6 h-6 flex-shrink-0"
        :class="isFailed ? 'text-danger' : 'text-brand'"
        aria-hidden="true"
      />
      <div class="flex-1 min-w-0 space-y-1.5">
        <div class="flex items-center gap-2 flex-wrap">
          <Icon
            v-if="!isFailed"
            icon="mdi:loading"
            class="w-4 h-4 animate-spin flex-shrink-0 text-brand"
            aria-hidden="true"
          />
          <p class="text-sm font-medium txt-primary">{{ $t(titleKey) }}</p>
          <button
            v-if="!isFailed"
            type="button"
            class="ml-auto inline-flex items-center gap-1 rounded-full border border-[var(--border-light)] px-2 py-0.5 text-xs text-brand hover:bg-black/5 dark:hover:bg-white/5 disabled:cursor-wait disabled:opacity-60"
            :disabled="isFetching"
            data-testid="media-job-refresh"
            @click="refreshNow()"
          >
            <Icon icon="mdi:refresh" class="w-3.5 h-3.5" aria-hidden="true" />
            {{ $t('message.mediaJob.refreshStatus') }}
          </button>
        </div>

        <p
          v-if="isFailed && failureMessage"
          class="text-sm txt-primary break-words"
          data-testid="media-job-error"
        >
          {{ failureMessage }}
        </p>
        <p
          v-else-if="isFailed && mediaJob.lost"
          class="text-sm txt-primary"
          data-testid="media-job-lost"
        >
          {{ $t('message.mediaJob.jobNotFound') }}
        </p>
        <p v-else-if="isFailed" class="text-sm txt-muted">
          {{ $t('message.mediaJob.failedBody') }}
        </p>

        <template v-else>
          <p class="text-sm txt-muted leading-relaxed">
            {{ $t('message.mediaJob.backgroundHint') }}
          </p>

          <p v-if="isOverdue" class="text-sm text-danger" data-testid="media-job-overdue">
            {{ $t('message.mediaJob.overdueWarning') }}
          </p>

          <p
            v-else-if="mediaJob.stalled"
            class="text-sm text-warning"
            data-testid="media-job-stalled"
          >
            {{
              $t(
                mediaJob.stallReason === 'queue_worker_down'
                  ? 'message.mediaJob.stalled.workerDown'
                  : 'message.mediaJob.stalled.generic'
              )
            }}
          </p>

          <p class="text-sm txt-primary" data-testid="media-job-elapsed">
            {{ $t('message.mediaJob.stillRunning', { elapsed: elapsedLabel }) }}
          </p>

          <p class="text-xs txt-muted" data-testid="media-job-last-checked">
            <template v-if="isFetching">
              {{ $t('message.mediaJob.checkingNow') }}
            </template>
            <template v-else>
              {{
                $t('message.mediaJob.lastChecked', {
                  seconds: secondsSinceCheck,
                  interval: pollIntervalSeconds,
                })
              }}
            </template>
          </p>

          <p class="text-xs txt-muted">
            {{ $t(`message.mediaJob.maxWait.${mediaJob.type}`, { minutes: maxWaitMinutes }) }}
          </p>

          <div v-if="progressPercent !== null" class="mt-0.5" data-testid="media-job-progress">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--border-light)]">
              <div
                class="h-full rounded-full bg-brand transition-[width] duration-300 ease-out"
                :style="{ width: `${progressPercent}%` }"
              />
            </div>
            <p class="text-xs txt-muted mt-1">
              {{ $t('message.mediaJob.progress', { percent: progressPercent }) }}
            </p>
          </div>

          <p v-if="fetchError" class="text-xs text-danger">
            {{ $t('message.mediaJob.pollError') }}
          </p>
        </template>

        <p v-if="modelLabel && !isFailed" class="text-xs txt-muted">
          {{ $t('message.mediaJob.model', { model: modelLabel }) }}
        </p>
      </div>
    </div>
  </div>
</template>
