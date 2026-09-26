<template>
  <div
    class="flex items-start gap-3 px-3 py-2.5 surface-chip rounded-lg"
    :class="{ 'border-l-2 border-red-500': isFailed }"
    data-testid="comp-desktop-job-card"
  >
    <ArrowPathIcon v-if="isActive" class="w-4 h-4 shrink-0 mt-0.5 txt-secondary animate-spin" />
    <CheckCircleIcon v-else-if="isSucceeded" class="w-4 h-4 shrink-0 mt-0.5 text-green-500" />
    <NoSymbolIcon v-else-if="isCancelled" class="w-4 h-4 shrink-0 mt-0.5 txt-secondary" />
    <ExclamationTriangleIcon v-else class="w-4 h-4 shrink-0 mt-0.5 text-red-500" />

    <div class="flex-1 min-w-0">
      <p class="text-sm txt-primary break-words">{{ statusLine }}</p>
      <p v-if="metaLine" class="text-xs txt-secondary break-words">{{ metaLine }}</p>
      <p v-if="detailLine" class="text-xs txt-secondary break-words">{{ detailLine }}</p>
      <button
        v-if="canCancel"
        type="button"
        class="btn-danger mt-2 px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-cancel-job"
        :disabled="cancelling"
        @click="cancelTask"
      >
        {{ $t('config.desktop.jobCard.cancel') }}
      </button>
    </div>

    <button
      type="button"
      class="icon-ghost p-0 min-w-0 w-auto h-auto shrink-0"
      :aria-label="$t('config.desktop.jobCard.hide')"
      data-testid="btn-dismiss-job"
      @click="emit('dismiss')"
    >
      <XMarkIcon class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  ArrowPathIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  NoSymbolIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { desktopApi, type DesktopJob } from '@/services/api/desktopApi'
import { jobCardView, type DesktopJobStatus } from '@/utils/desktopJobCard'

const props = defineProps<{
  jobId: number
  deviceName: string
}>()

const emit = defineEmits<{ dismiss: [] }>()

const { t, locale } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const POLL_MS = 4000
// After this long a still-open job explains itself, but polling continues so a
// late lease or result replaces that explanation. Stopping here used to freeze
// the card on "did not answer" while the computer was still working.
const WAIT_HINT_MS = 5 * 60 * 1000

const status = ref<DesktopJobStatus>('queued')
const cancelling = ref(false)
const errorCode = ref<string | null>(null)
const result = ref<DesktopJob['result']>(null)
const skill = ref('')
const created = ref<number | null>(null)
const waitedLong = ref(false)

let poller: number | null = null
let deadline = 0

const view = computed(() =>
  jobCardView(status.value, errorCode.value, result.value, waitedLong.value)
)
const isActive = computed(() => view.value.phase === 'waiting' || view.value.phase === 'running')
const isSucceeded = computed(() => view.value.phase === 'succeeded')
const isCancelled = computed(() => view.value.phase === 'cancelled')
const isFailed = computed(() => view.value.phase === 'failed')
const canCancel = computed(() => isActive.value)

const statusLine = computed(() => {
  const name = props.deviceName
  const skillName = skill.value
  if (view.value.phase === 'succeeded') return t('config.desktop.jobCard.done', { name })
  if (view.value.phase === 'failed') return t('config.desktop.jobCard.failed', { name })
  if (view.value.phase === 'cancelled') return t('config.desktop.jobCard.cancelled', { name })
  if (view.value.phase === 'running' && skillName) {
    return t('config.desktop.jobCard.running', { name, skill: skillName })
  }
  if (view.value.phase === 'waiting' && skillName) {
    return t('config.desktop.jobCard.waitingSkill', { name, skill: skillName })
  }
  return t('config.desktop.jobCard.waiting', { name })
})

const metaLine = computed(() => {
  if (!skill.value || created.value == null) return ''
  const time = new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(created.value * 1000))
  return t('config.desktop.jobCard.meta', { skill: skill.value, time })
})

const detailLine = computed(() => {
  const detail = view.value.detail
  if (detail.type === 'device') return detail.text
  if (detail.type === 'key') return t(`config.desktop.jobCard.${detail.key}`)
  return ''
})

const isTerminal = (s: DesktopJobStatus): boolean =>
  s === 'succeeded' || s === 'failed' || s === 'cancelled'

const stopPolling = () => {
  if (poller) {
    clearInterval(poller)
    poller = null
  }
}

const cancelTask = async () => {
  const running = status.value === 'leased'
  const skillName = skill.value || t('config.desktop.jobCard.thisTask')
  const confirmed = await dialog.confirm({
    title: t('config.desktop.jobCard.confirmCancelTitle'),
    message: t(
      running
        ? 'config.desktop.jobCard.confirmCancelRunning'
        : 'config.desktop.jobCard.confirmCancelQueued',
      { name: props.deviceName, skill: skillName }
    ),
    confirmText: t('config.desktop.jobCard.cancel'),
    cancelText: t('config.desktop.jobCard.keepWaiting'),
    danger: true,
  })
  if (!confirmed) return

  cancelling.value = true
  try {
    const outcome = await desktopApi.cancelJob(props.jobId)
    applyJob(outcome.job)
    if (outcome.job.status === 'cancelled') {
      success(t('config.desktop.jobCard.cancelledDone'))
      stopPolling()
    } else if (isTerminal(outcome.job.status)) {
      showError(t('config.desktop.jobCard.alreadyFinished'))
      stopPolling()
    }
  } catch {
    showError(t('config.desktop.jobCard.cancelFailed'))
  } finally {
    cancelling.value = false
  }
}

const applyJob = (job: DesktopJob) => {
  status.value = job.status
  errorCode.value = job.errorCode ?? null
  result.value = job.result ?? null
  skill.value = job.skill
  created.value = job.created
}

const poll = async () => {
  try {
    const job = await desktopApi.getJob(props.jobId)
    applyJob(job)
    if (Date.now() > deadline && (job.status === 'queued' || job.status === 'leased')) {
      waitedLong.value = true
    }
    if (isTerminal(job.status)) stopPolling()
  } catch {
    // A transient poll failure is not terminal; the next poll tries again.
  }
}

onMounted(() => {
  deadline = Date.now() + WAIT_HINT_MS
  poll()
  poller = window.setInterval(poll, POLL_MS)
})

onUnmounted(stopPolling)
</script>
