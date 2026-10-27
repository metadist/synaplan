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

    <div v-if="!terminal">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="research.stopping"
        data-testid="btn-research-stop"
        @click="research.stop()"
      >
        {{ $t('research.stop') }}
      </button>
      <p class="mt-2 text-sm txt-secondary">{{ $t('research.stopConsequence') }}</p>
    </div>
    <p v-else-if="progress.status === 'stopped'" class="text-sm txt-secondary">
      {{ $t('research.stopped') }}
    </p>
    <p v-else-if="progress.status === 'failed'" class="text-sm text-red-600 dark:text-red-400">
      {{ $t('research.failed') }}
    </p>
    <p v-if="research.startError === 'stopFailed'" class="text-sm text-red-600 dark:text-red-400">
      {{ $t('research.stopFailed') }}
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

function pointLabel(status: string): string {
  if (status === 'answered') return t('research.pointDone')
  if (status === 'not_confirmed') return t('research.pointNotConfirmed')
  return t('research.pointOpen')
}
</script>
