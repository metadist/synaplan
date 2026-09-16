<template>
  <div
    class="flex items-center gap-3 px-3 py-2.5 surface-chip rounded-lg"
    :class="{ 'border-l-2 border-red-500': isFailed }"
    data-testid="comp-desktop-job-card"
  >
    <ArrowPathIcon v-if="isWaiting" class="w-4 h-4 shrink-0 txt-secondary animate-spin" />
    <CheckCircleIcon v-else-if="isSucceeded" class="w-4 h-4 shrink-0 text-green-500" />
    <ExclamationTriangleIcon v-else class="w-4 h-4 shrink-0 text-red-500" />

    <div class="flex-1 min-w-0">
      <p class="text-sm txt-primary truncate">{{ statusLine }}</p>
      <p v-if="detailLine" class="text-xs txt-secondary truncate">{{ detailLine }}</p>
    </div>

    <button
      class="icon-ghost p-0 min-w-0 w-auto h-auto shrink-0"
      :aria-label="$t('common.close')"
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
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { desktopApi, type DesktopJob } from '@/services/api/desktopApi'

const props = defineProps<{
  jobId: number
  deviceName: string
}>()

const emit = defineEmits<{ dismiss: [] }>()

const { t } = useI18n()

const POLL_MS = 4000
// Client-side safety net: even if the server-side reaper is not ticking (e.g. a
// dev install with no cron), the card must reach an honest terminal state
// instead of spinning forever (DS16 acceptance).
const CLIENT_TIMEOUT_MS = 5 * 60 * 1000

const status = ref<DesktopJob['status']>('queued')
const errorCode = ref<string | null>(null)
const timedOut = ref(false)

let poller: number | null = null
let deadline = 0

const isWaiting = computed(
  () => !timedOut.value && (status.value === 'queued' || status.value === 'leased')
)
const isSucceeded = computed(() => status.value === 'succeeded')
const isFailed = computed(
  () => timedOut.value || status.value === 'failed' || status.value === 'cancelled'
)

const statusLine = computed(() => {
  if (isSucceeded.value) return t('config.desktop.jobCard.done', { name: props.deviceName })
  if (isFailed.value) return t('config.desktop.jobCard.failed', { name: props.deviceName })
  return t('config.desktop.jobCard.waiting', { name: props.deviceName })
})

// A device that never answered (timeout / expiry) gets the plain-language line
// from §3.1; a device that refused the skill gets the specific reason.
const detailLine = computed(() => {
  if (!isFailed.value) return ''
  if (timedOut.value || errorCode.value === 'timeout') return t('config.desktop.jobCard.noAnswer')
  if (errorCode.value === 'unknown_skill') return t('config.desktop.jobCard.unknownSkill')
  if (errorCode.value === 'skill_disabled') return t('config.desktop.jobCard.skillDisabled')
  if (errorCode.value) return t('config.desktop.jobCard.localError')
  return t('config.desktop.jobCard.noAnswer')
})

const isTerminal = (s: DesktopJob['status']): boolean =>
  s === 'succeeded' || s === 'failed' || s === 'cancelled'

const stopPolling = () => {
  if (poller) {
    clearInterval(poller)
    poller = null
  }
}

const poll = async () => {
  if (Date.now() > deadline) {
    timedOut.value = true
    stopPolling()
    return
  }
  try {
    const job = await desktopApi.getJob(props.jobId)
    status.value = job.status
    errorCode.value = job.errorCode ?? null
    if (isTerminal(job.status)) stopPolling()
  } catch {
    // A transient poll failure is not terminal; the deadline still bounds it.
  }
}

onMounted(() => {
  deadline = Date.now() + CLIENT_TIMEOUT_MS
  poll()
  poller = window.setInterval(poll, POLL_MS)
})

onUnmounted(stopPolling)
</script>
