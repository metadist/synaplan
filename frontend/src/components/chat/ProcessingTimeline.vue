<template>
  <!--
    Progress timeline of a streaming turn.

    Finished steps stay on screen with their duration, the active step animates
    and shows a live elapsed counter once it takes more than a moment. In
    `collapsed` mode (answer already streaming) the whole thing folds into one
    muted summary line the user can open.
  -->
  <div
    class="processing-timeline"
    role="status"
    :aria-label="t('processing.timeline.ariaLabel')"
    data-testid="processing-timeline"
  >
    <template v-if="collapsed">
      <button
        type="button"
        class="flex items-center gap-2 text-xs txt-tertiary hover:txt-secondary transition-colors"
        :aria-expanded="expanded"
        data-testid="btn-timeline-toggle"
        @click="expanded = !expanded"
      >
        <CheckCircleIcon class="w-3.5 h-3.5 txt-brand flex-shrink-0" aria-hidden="true" />
        <span>{{ summaryLine }}</span>
        <ChevronDownIcon
          class="w-3 h-3 transition-transform"
          :class="{ 'rotate-180': expanded }"
          aria-hidden="true"
        />
      </button>
      <ol v-if="expanded" class="mt-2 space-y-1 processing-enter" data-testid="timeline-done-steps">
        <li
          v-for="row in doneRows"
          :key="row.step.id"
          class="flex items-center gap-2 text-xs txt-tertiary min-w-0"
        >
          <CheckIcon class="w-3.5 h-3.5 txt-brand flex-shrink-0" aria-hidden="true" />
          <span class="truncate">{{ row.copy.title }}</span>
          <span
            v-if="row.copy.chip"
            class="surface-chip rounded px-1.5 py-0.5 text-[11px] truncate"
          >
            {{ row.copy.chip }}
          </span>
          <span v-if="row.copy.detail" class="truncate opacity-70">{{ row.copy.detail }}</span>
          <span v-if="row.duration" class="ml-auto tabular-nums opacity-70 flex-shrink-0">
            {{ row.duration }}
          </span>
        </li>
      </ol>
    </template>

    <template v-else>
      <ol v-if="doneRows.length > 0" class="space-y-1 mb-2" data-testid="timeline-done-steps">
        <li
          v-for="row in doneRows"
          :key="row.step.id"
          class="flex items-center gap-2 text-xs txt-tertiary min-w-0 processing-enter"
        >
          <CheckIcon class="w-3.5 h-3.5 txt-brand flex-shrink-0" aria-hidden="true" />
          <span class="truncate">{{ row.copy.title }}</span>
          <span
            v-if="row.copy.chip"
            class="surface-chip rounded px-1.5 py-0.5 text-[11px] truncate"
          >
            {{ row.copy.chip }}
          </span>
          <span v-if="row.copy.detail" class="truncate opacity-70">{{ row.copy.detail }}</span>
          <span v-if="row.duration" class="ml-auto tabular-nums opacity-70 flex-shrink-0">
            {{ row.duration }}
          </span>
        </li>
      </ol>

      <div v-if="activeRow" class="flex items-start gap-3" data-testid="timeline-active-step">
        <!-- Brain for the memory pass, spinner otherwise -->
        <svg
          v-if="activeRow.step.key === 'memories' || activeRow.step.key === 'memories_after'"
          class="w-5 h-5 txt-brand flex-shrink-0 animate-pulse"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
          />
        </svg>
        <svg
          v-else
          class="w-5 h-5 animate-spin txt-brand flex-shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          />
        </svg>

        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 min-w-0">
            <div class="font-medium animate-pulse truncate">{{ activeRow.copy.title }}</div>
            <span
              v-if="activeRow.copy.chip"
              class="surface-chip rounded px-1.5 py-0.5 text-[11px] txt-secondary truncate"
            >
              {{ activeRow.copy.chip }}
            </span>
            <span
              v-if="activeElapsedLabel"
              class="ml-auto text-xs tabular-nums txt-tertiary flex-shrink-0"
              data-testid="timeline-elapsed"
            >
              {{ activeElapsedLabel }}
            </span>
          </div>
          <div v-if="activeRow.copy.detail" class="text-sm txt-tertiary mt-0.5">
            {{ activeRow.copy.detail }}
          </div>
          <slot name="active-extra" :step="activeRow.step" />
        </div>
      </div>

      <!-- Nothing narrated yet (turn just started): generic waiting row -->
      <div
        v-else-if="doneRows.length === 0"
        class="flex items-center gap-3"
        data-testid="timeline-waiting"
      >
        <svg
          class="w-5 h-5 animate-spin txt-brand flex-shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          />
        </svg>
        <div class="flex-1 min-w-0">
          <div class="font-medium">{{ t('processing.startedTitle') }}</div>
          <div class="text-sm txt-tertiary mt-0.5">{{ t('processing.startedDesc') }}</div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCircleIcon, CheckIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { progressNarrationSwitches } from '@/utils/progressNarrationConfig'
import {
  describeStep,
  modelWithProvider,
  type StepCopy,
  type Translate,
} from '@/utils/processingStepCopy'
import {
  formatDurationSeconds,
  stepDurationMs,
  type TimelineModel,
  type TimelineStep,
} from '@/utils/processingTimeline'

interface Props {
  steps: TimelineStep[]
  model?: TimelineModel
  /** Answer already streaming: fold the finished steps into one line. */
  collapsed?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  model: () => ({}),
  collapsed: false,
})

const { t } = useI18n()
// vue-i18n's `t` is overloaded; the copy helpers take the plain two-argument shape.
const tr: Translate = (key, params) => (params ? t(key, params) : t(key))

// Admin switches (runtime config): name models/providers, show durations.
const narration = progressNarrationSwitches()
const copyOptions = computed(() => ({ showModels: narration.models }))
const showTimings = computed(() => narration.timings)

// A step that took no measurable time ("0.0s") is noise, not information.
const INSTANT_STEP_MS = 100
const doneDuration = (ms: number): string => (ms < INSTANT_STEP_MS ? '' : formatDurationSeconds(ms))

/** Elapsed time on the active step is shown once the wait becomes noticeable. */
const SHOW_ELAPSED_AFTER_MS = 1500
const TICK_MS = 250

const expanded = ref(false)
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null

const activeStepRef = computed(() => {
  const last = props.steps[props.steps.length - 1]
  return last && last.state === 'active' ? last : undefined
})

function stopTicker() {
  if (ticker) {
    clearInterval(ticker)
    ticker = null
  }
}

watch(
  activeStepRef,
  (step) => {
    stopTicker()
    if (step) {
      now.value = Date.now()
      ticker = setInterval(() => {
        now.value = Date.now()
      }, TICK_MS)
    }
  },
  { immediate: true }
)

onUnmounted(stopTicker)

interface Row {
  step: TimelineStep
  copy: StepCopy
  duration: string
}

const doneRows = computed<Row[]>(() =>
  props.steps
    .filter((step) => step.state === 'done')
    .map((step) => ({
      step,
      copy: describeStep(step, props.model, tr, copyOptions.value),
      duration: showTimings.value ? doneDuration(stepDurationMs(step, now.value)) : '',
    }))
)

const activeRow = computed<Row | undefined>(() => {
  const step = activeStepRef.value
  if (!step) return undefined
  return {
    step,
    copy: describeStep(step, props.model, tr, copyOptions.value),
    duration: showTimings.value ? formatDurationSeconds(stepDurationMs(step, now.value)) : '',
  }
})

const activeElapsedLabel = computed(() => {
  const step = activeStepRef.value
  if (!step || !showTimings.value) return ''
  const elapsed = stepDurationMs(step, now.value)
  return elapsed >= SHOW_ELAPSED_AFTER_MS ? formatDurationSeconds(elapsed) : ''
})

const summaryLine = computed(() => {
  const done = props.steps.filter((step) => step.state === 'done')
  if (done.length === 0) return ''
  const first = done[0].startedAt
  const last = done[done.length - 1].endedAt ?? now.value
  const parts = [t('processing.timeline.summary', { count: done.length }, done.length)]
  if (showTimings.value) parts.push(formatDurationSeconds(Math.max(0, last - first)))
  if (copyOptions.value.showModels) {
    const label = modelWithProvider(undefined, props.model, tr)
    if (label) parts.push(label)
  }
  return parts.join(' · ')
})
</script>
