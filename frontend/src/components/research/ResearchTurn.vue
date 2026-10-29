<template>
  <div
    v-if="progress"
    class="flex flex-col gap-2 text-sm"
    data-testid="research-turn"
    :data-status="progress.status"
  >
    <template v-if="running">
      <div
        class="flex items-center gap-3 min-w-0"
        role="status"
        data-testid="research-turn-working"
      >
        <ResearchSpinner class="w-5 h-5" />
        <div class="flex-1 min-w-0 flex items-baseline gap-2">
          <span class="font-medium txt-primary truncate">{{ headline }}</span>
          <span
            v-if="stepOf"
            class="text-xs txt-tertiary flex-shrink-0"
            data-testid="text-research-step-of"
            >{{ stepOf }}</span
          >
          <span
            v-if="elapsed"
            class="ml-auto text-xs tabular-nums txt-tertiary flex-shrink-0"
            data-testid="text-research-elapsed"
            aria-hidden="true"
            >{{ elapsed }}</span
          >
        </div>
      </div>
      <ResearchPhases :progress="progress" />
    </template>

    <template v-else>
      <button
        type="button"
        class="btn-secondary px-2 py-1 rounded-xl text-xs font-medium inline-flex items-center gap-2 self-start max-w-full"
        :aria-expanded="open"
        data-testid="btn-research-steps"
        @click="open = !open"
      >
        <CheckCircleIcon class="w-3.5 h-3.5 txt-brand flex-shrink-0" aria-hidden="true" />
        <span class="truncate" data-testid="text-research-summary">{{ summary }}</span>
        <ChevronDownIcon
          class="w-3 h-3 transition-transform flex-shrink-0"
          :class="{ 'rotate-180': open }"
          aria-hidden="true"
        />
      </button>
      <ResearchPhases v-if="open" :progress="progress" class="processing-enter" />
    </template>

    <div v-if="running" class="flex flex-col gap-2 pt-1">
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="research.stopping || research.answerNowAccepted"
          :aria-busy="research.stopping"
          data-testid="btn-research-answer-now"
          @click="research.answerNow()"
        >
          {{ t('research.answerNow') }}
        </button>
      </div>
      <p class="text-xs txt-tertiary" data-testid="text-research-answer-now-consequence">
        {{ answerNowConsequence }}
      </p>
      <p
        v-if="research.startError === 'stopFailed' || research.startError === 'answerNowFailed'"
        class="text-sm text-red-600 dark:text-red-400"
      >
        {{ t(`research.${research.startError}`) }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCircleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { useChatsStore } from '@/stores/chats'
import { useResearchStore } from '@/stores/research'
import { fetchResearchEvents } from '@/services/api/researchApi'
import {
  applyResearchSse,
  emptyProgress,
  isTerminalStatus,
  sourceCounts,
  type ResearchProgress,
} from '@/services/chat/stream/handlers/research'
import { progressNarrationSwitches } from '@/utils/progressNarrationConfig'
import ResearchPhases from './ResearchPhases.vue'
import ResearchSpinner from './ResearchSpinner.vue'
import { formatElapsed, researchPhases } from './researchPhases'

/**
 * The research inside the reply bubble. While the run works the bubble
 * shows a headline with the phase it is in and how long it has been going,
 * then the phases: the finished ones folded to a line each, the current one
 * open with what it does right now, the coming ones muted. Answer now is
 * under it; Stop is the composer's send button. Once the run is done the
 * report is the bubble's text and one muted line stays below it — open it
 * to see the phases again, each one unfolding on a click.
 *
 * A run the store follows is read live. Any other (a reload, an old chat)
 * is rebuilt once from its recorded events; one still running is then
 * followed again.
 */
const props = defineProps<{
  runId: string
}>()

const TICK_MS = 1000

const { t } = useI18n()
const research = useResearchStore()
const chats = useChatsStore()

const open = ref(false)
const recorded = ref<ResearchProgress | null>(null)
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

const live = computed(() =>
  research.progress && research.progress.id === props.runId ? research.progress : null
)
/** The followed run, with the recorded steps when the store only has its summary (a reload). */
const progress = computed(() => {
  const followed = live.value
  if (followed === null) return recorded.value
  if (followed.steps.length > 0 || recorded.value === null) return followed
  return { ...followed, steps: recorded.value.steps }
})
const running = computed(() => progress.value !== null && !isTerminalStatus(progress.value.status))
const counts = computed(() =>
  progress.value ? sourceCounts(progress.value) : { found: 0, read: 0, blocked: 0 }
)

const headline = computed(() => {
  const current = progress.value
  if (!current) return ''
  if (current.phase === 'queued' && current.queuedSeconds !== null) {
    return t('research.queued', { seconds: current.queuedSeconds })
  }
  return t('research.turn.working')
})

/** "Step 3 of 5": where the run is on its road. */
const stepOf = computed(() => {
  const current = progress.value
  if (!current || !running.value) return ''
  const phases = researchPhases(current)
  const index = phases.findIndex((phase) => phase.state === 'active')
  if (index < 0) return ''
  return t('research.turn.stepOf', { n: index + 1, total: phases.length })
})

/** How long the run has been going; an admin switch hides timings altogether. */
const elapsed = computed(() => {
  const current = progress.value
  if (!current || !running.value || current.startedAt === null) return ''
  if (!progressNarrationSwitches().timings) return ''
  return formatElapsed(now.value - current.startedAt)
})

const summary = computed(() => {
  const current = progress.value
  if (!current) return ''
  const params = { steps: current.steps.length, sources: counts.value.read }
  if (running.value) return t('research.turn.stepsSoFar', params)
  if (current.outcome === 'answered_without_research') return t('research.answeredWithout')
  if (current.status === 'stopped') return t('research.turn.summary.stopped', params)
  if (current.status === 'failed') return t('research.turn.summary.failed')
  return t('research.turn.summary.complete', params)
})

/** What Answer now does right now: the report from what was read, or the plain answer. */
const answerNowConsequence = computed(() => {
  const current = progress.value
  if (!current) return ''
  if (current.status === 'writing' || current.status === 'checking') {
    return t('research.answerNowConsequence.writing')
  }
  if (counts.value.read > 0) return t('research.answerNowConsequence.reading')
  return t('research.answerNowConsequence.early')
})

watch(
  running,
  (isRunning) => {
    stopTicker()
    if (!isRunning) return
    now.value = Date.now()
    ticker = setInterval(() => {
      now.value = Date.now()
    }, TICK_MS)
  },
  { immediate: true }
)

onUnmounted(stopTicker)

function stopTicker(): void {
  if (ticker === null) return
  clearInterval(ticker)
  ticker = null
}

watch(
  () => props.runId,
  (runId) => {
    recorded.value = null
    open.value = false
    const followed = live.value
    const summaryOnly =
      followed !== null && followed.steps.length === 0 && isTerminalStatus(followed.status)
    if (followed === null || summaryOnly) void rebuild(runId)
  },
  { immediate: true }
)

async function rebuild(runId: string): Promise<void> {
  const chatId = chats.activeChatId
  if (chatId === null) return
  try {
    const body = await fetchResearchEvents(runId, 0)
    if (props.runId !== runId) return
    const built = applyResearchSse(emptyProgress(runId, chatId, ''), body)
    recorded.value = built
    if (live.value === null && !isTerminalStatus(built.status)) {
      await research.follow(chatId, runId)
    }
  } catch {
    // The reply's text stands on its own; the steps are simply not shown.
  }
}
</script>
