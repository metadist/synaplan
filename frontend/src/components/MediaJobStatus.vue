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
  }

  if (status.state === 'done' && status.file?.url) {
    emit('completed', { url: status.file.url, type: status.file.type ?? status.type })
  }
}

const { secondsSinceCheck, isFetching, fetchError, latest } = useMediaJobPoll(
  toRef(() => jobId.value),
  pollEnabled,
  applyPollStatus
)

const isFailed = computed(
  () => props.mediaJob.state === 'failed' || props.mediaJob.state === 'cancelled'
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
  if (isFailed.value) return 'message.mediaJob.failedTitle'
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

const progressPercent = computed(() => {
  const raw = latest.value?.percent ?? props.mediaJob.percent
  if (raw === undefined || raw === null) return null
  return Math.max(0, Math.min(100, Math.round(raw)))
})

const pollIntervalSeconds = Math.round(MEDIA_JOB_POLL_INTERVAL_MS / 1000)
</script>

<template>
  <div
    class="media-job-status rounded-xl border p-4"
    :class="{
      'media-job-status--failed': isFailed,
    }"
    role="status"
    :aria-label="$t(titleKey)"
    data-testid="media-job-status"
  >
    <div class="flex items-start gap-3">
      <Icon
        :icon="isFailed ? 'mdi:alert-circle-outline' : iconForType"
        class="w-6 h-6 flex-shrink-0"
        :style="{ color: isFailed ? 'var(--danger, #dc2626)' : 'var(--brand)' }"
        aria-hidden="true"
      />
      <div class="flex-1 min-w-0 space-y-1.5">
        <div class="flex items-center gap-2 flex-wrap">
          <Icon
            v-if="!isFailed"
            icon="mdi:loading"
            class="w-4 h-4 animate-spin flex-shrink-0"
            style="color: var(--brand)"
            aria-hidden="true"
          />
          <p class="text-sm font-medium txt-primary">{{ $t(titleKey) }}</p>
        </div>

        <p v-if="isFailed && mediaJob.error" class="text-sm txt-primary break-words" data-testid="media-job-error">
          {{ mediaJob.error }}
        </p>
        <p v-else-if="isFailed" class="text-sm txt-muted">
          {{ $t('message.mediaJob.failedBody') }}
        </p>

        <template v-else>
          <p class="text-sm txt-muted leading-relaxed">
            {{ $t('message.mediaJob.backgroundHint') }}
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

          <div v-if="progressPercent !== null" class="media-job-status__progress" data-testid="media-job-progress">
            <div class="media-job-status__progress-track">
              <div
                class="media-job-status__progress-fill"
                :style="{ width: `${progressPercent}%` }"
              />
            </div>
            <p class="text-xs txt-muted mt-1">
              {{ $t('message.mediaJob.progress', { percent: progressPercent }) }}
            </p>
          </div>

          <p v-if="fetchError" class="text-xs" style="color: var(--danger, #dc2626)">
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

<style scoped>
.media-job-status {
  background: var(--surface-card, rgba(127, 127, 127, 0.04));
  border-color: var(--brand);
}
.media-job-status--failed {
  border-color: var(--danger, #dc2626);
}
.media-job-status__progress-track {
  height: 6px;
  border-radius: 999px;
  background: var(--border-color, rgba(127, 127, 127, 0.2));
  overflow: hidden;
}
.media-job-status__progress-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--brand);
  transition: width 0.4s ease;
}
</style>
