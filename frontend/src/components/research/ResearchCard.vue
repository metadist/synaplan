<template>
  <section
    v-if="progress"
    class="my-4 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 flex flex-col gap-3"
    data-testid="card-research"
    :data-status="progress.status"
  >
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 class="text-sm font-medium txt-primary">{{ $t('research.title') }}</h2>
        <p class="text-sm txt-secondary break-words">{{ progress.question }}</p>
      </div>
      <button
        v-if="terminal"
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium shrink-0"
        data-testid="btn-research-dismiss"
        @click="research.dismiss()"
      >
        {{ $t('research.close') }}
      </button>
    </div>

    <p class="text-sm txt-primary" data-testid="text-research-status">{{ statusText }}</p>
    <p v-if="ceilingText" class="text-sm txt-secondary" data-testid="text-research-ceiling">
      {{ ceilingText }}
    </p>

    <ul
      v-if="progress.points.length > 0"
      class="flex flex-col gap-1"
      data-testid="list-research-plan"
    >
      <li
        v-for="point in progress.points"
        :key="point.id"
        class="flex items-start justify-between gap-2 text-sm"
      >
        <span class="txt-primary break-words">{{ point.title }}</span>
        <span class="txt-secondary shrink-0">{{ pointLabel(point.status) }}</span>
      </li>
    </ul>

    <p class="text-sm txt-secondary" data-testid="text-research-sources">
      {{
        $t('research.sourcesCount', {
          found: counts.found,
          read: counts.read,
          blocked: counts.blocked,
        })
      }}
    </p>

    <ul v-if="progress.snippets.length > 0" class="flex flex-col gap-2">
      <li v-for="(snippet, index) in progress.snippets" :key="index" class="text-sm">
        <p class="txt-primary">{{ snippet.title }}</p>
        <p class="txt-secondary">"{{ snippet.quote }}"</p>
      </li>
    </ul>

    <p v-if="progress.check" class="text-sm txt-primary" data-testid="text-research-fact-check">
      {{
        $t('research.factCheck', {
          supported: progress.check.supported,
          total: progress.check.statements,
          removed: progress.check.removed,
        })
      }}
    </p>

    <p
      v-if="progress.status === 'complete'"
      class="text-sm txt-secondary"
      data-testid="text-research-find"
    >
      {{ $t('research.find') }}
    </p>

    <div v-if="!terminal" class="flex flex-col gap-2">
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="research.stopping"
          data-testid="btn-research-answer-now"
          @click="research.answerNow()"
        >
          {{ $t('research.answerNow') }}
        </button>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="research.stopping"
          data-testid="btn-research-stop"
          @click="research.stop()"
        >
          {{ $t('research.stop') }}
        </button>
      </div>
      <p class="text-sm txt-secondary" data-testid="text-research-answer-now-consequence">
        {{ answerNowConsequence }}
      </p>
      <p class="text-sm txt-secondary">{{ $t('research.stopConsequence') }}</p>
    </div>
    <p
      v-else-if="progress.outcome === 'answered_without_research'"
      class="text-sm txt-secondary"
      data-testid="text-research-answered-without"
    >
      {{ $t('research.answeredWithout') }}
    </p>
    <p v-else-if="progress.status === 'stopped'" class="text-sm txt-secondary">
      {{ $t('research.stopped') }}
    </p>
    <p v-else-if="progress.status === 'failed'" class="text-sm text-red-600 dark:text-red-400">
      {{ $t('research.failed') }}
    </p>
    <p
      v-if="research.startError === 'stopFailed' || research.startError === 'answerNowFailed'"
      class="text-sm text-red-600 dark:text-red-400"
    >
      {{ $t(`research.${research.startError}`) }}
    </p>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useResearchStore } from '@/stores/research'
import { isTerminalStatus, sourceCounts } from '@/services/chat/stream/handlers/research'

const research = useResearchStore()
const { progress } = storeToRefs(research)
const { t } = useI18n()

const terminal = computed(() => (progress.value ? isTerminalStatus(progress.value.status) : false))
const counts = computed(() =>
  progress.value ? sourceCounts(progress.value) : { found: 0, read: 0, blocked: 0 }
)

const statusText = computed(() => {
  const current = progress.value
  if (!current) return ''
  if (current.status === 'complete') return t('research.workingDone')
  if (current.status === 'stopped') return t('research.stopped')
  if (current.status === 'failed') return t('research.failed')
  if (current.phase === 'queued' && current.queuedSeconds !== null) {
    return t('research.queued', { seconds: current.queuedSeconds })
  }
  return t('research.working')
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

/** How far an automatic run decided to look, once its plan is known. */
const ceilingText = computed(() => {
  const current = progress.value
  if (!current || current.ceiling === null || current.points.length === 0) return ''
  if (current.ceiling !== 'quick' && current.ceiling !== 'standard') return ''
  return t(`research.ceiling.${current.ceiling}`, { points: current.points.length })
})

function pointLabel(status: string): string {
  if (status === 'answered') return t('research.pointDone')
  if (status === 'not_confirmed') return t('research.pointNotConfirmed')
  return t('research.pointOpen')
}
</script>
