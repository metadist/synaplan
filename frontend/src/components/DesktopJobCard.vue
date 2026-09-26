<template>
  <div
    class="flex items-start gap-3 px-3 py-2.5 surface-chip rounded-lg"
    :class="{ 'border-l-2 border-red-500': isFailed }"
    data-testid="comp-desktop-job-card"
  >
    <ArrowPathIcon v-if="isActive" class="w-4 h-4 shrink-0 mt-0.5 txt-secondary animate-spin" />
    <CheckCircleIcon v-else-if="isSucceeded" class="w-4 h-4 shrink-0 mt-0.5 text-green-500" />
    <ExclamationTriangleIcon v-else class="w-4 h-4 shrink-0 mt-0.5 text-red-500" />

    <div class="flex-1 min-w-0">
      <p class="text-sm txt-primary break-words">{{ statusLine }}</p>
      <p v-if="metaLine" class="text-xs txt-secondary break-words">{{ metaLine }}</p>
      <p v-if="detailLine" class="text-xs txt-secondary break-words">{{ detailLine }}</p>
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
import { jobCardView, type DesktopJobStatus } from '@/utils/desktopJobCard'

const props = defineProps<{
  jobId: number
  deviceName: string
}>()

const emit = defineEmits<{ dismiss: [] }>()

const { t, locale } = useI18n()

const POLL_MS = 4000
// After this long a still-open job explains itself, but polling continues so a
// late lease or result replaces that explanation. Stopping here used to freeze
// the card on "did not answer" while the computer was still working.
const WAIT_HINT_MS = 5 * 60 * 1000

const status = ref<DesktopJobStatus>('queued')
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
const isFailed = computed(() => view.value.phase === 'failed' || view.value.phase === 'cancelled')

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

const poll = async () => {
  try {
    const job = await desktopApi.getJob(props.jobId)
    status.value = job.status
    errorCode.value = job.errorCode ?? null
    result.value = job.result ?? null
    skill.value = job.skill
    created.value = job.created
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
