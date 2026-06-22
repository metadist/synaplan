<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { TaskCard } from '@/stores/history'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { AIModel, Capability } from '@/types/ai-models'
import MessageText from '@/components/MessageText.vue'

const props = defineProps<{ card: TaskCard }>()

const emit = defineEmits<{
  /** Retry a failed media step with another model (new turn via the Again path). */
  retry: [payload: { prompt: string; modelId: number }]
  /** Stop a running media step (per-card Stop button). */
  cancel: [nodeId: string]
}>()

const aiConfigStore = useAiConfigStore()

const iconForKind = computed(() => {
  switch (props.card.kind) {
    case 'image':
      return 'mdi:image-outline'
    case 'video':
      return 'mdi:video-outline'
    case 'audio':
      return 'mdi:waveform'
    case 'document':
      return 'mdi:file-document-outline'
    case 'search':
      return 'mdi:web'
    case 'extract':
      return 'mdi:text-box-search-outline'
    case 'email':
      return 'mdi:email-outline'
    default:
      return 'mdi:text-box-outline'
  }
})

// Title prefers a capability-specific label, falling back to the kind.
const title = computed(() => `taskPlan.capability.${props.card.capability}`)

const isMediaKind = computed(() => ['image', 'video', 'audio'].includes(props.card.kind))

const showSkeleton = computed(
  () =>
    isMediaKind.value &&
    !props.card.url &&
    props.card.state !== 'failed' &&
    props.card.state !== 'cancelled'
)

// Only media steps run long enough to be worth stopping; the button shows while
// such a step is in flight.
const canCancel = computed(() => isMediaKind.value && props.card.state === 'running')

// Live render progress (e.g. Higgsfield video) — a moving bar instead of a
// static spinner. Only meaningful once the backend has reported a percentage.
const showProgress = computed(
  () =>
    isMediaKind.value && props.card.state === 'running' && props.card.progressPercent !== undefined
)

const progressPercent = computed(() =>
  Math.max(0, Math.min(100, Math.round(props.card.progressPercent ?? 0)))
)

const handleCancel = () => {
  emit('cancel', props.card.nodeId)
}

// Render the body as full markdown for text-bearing nodes (chat / summarize /
// translate / rag_query / file_analysis = kind 'text', plus 'extract'). Without
// this the DAG-emitted body shows as raw "**bold**" / "---" / numbered prose
// because the previous template did `{{ card.text }}` inside a plain <p>. The
// other kinds (audio/image/video/document/search) hold short status strings
// where markdown is unnecessary, so we keep the plain text path for them.
const isProseKind = computed(() => ['text', 'extract'].includes(props.card.kind))

// Search cards show a compact summary (query + source count) instead of the
// raw full-text dump produced by BraveSearchService.formatResultsForAI().
// The full results are available via the Sources dropdown on the message body.
const isSearchKind = computed(() => props.card.kind === 'search')

// Model tag matching this card's media kind — the pool the retry button picks from.
const retryTag = computed((): Capability | null => {
  switch (props.card.kind) {
    case 'image':
      return 'TEXT2PIC'
    case 'video':
      return 'TEXT2VID'
    case 'audio':
      return 'TEXT2SOUND'
    default:
      return null
  }
})

// Next model in the BRANKING-sorted list after the user's default for this
// capability (round-robin, mirroring useModelSelection.predictedModel). The
// failed step ran the default model, so "next" is the most useful retry pick.
// A single-model pool offers nothing: "retry" there would re-run the very
// model that just failed, which is misleading on non-transient failures.
const retryModel = computed((): AIModel | null => {
  const tag = retryTag.value
  if (!tag) return null

  const options = [...(aiConfigStore.models[tag] ?? [])].sort((a, b) => b.rating - a.rating)
  if (options.length <= 1) return null

  const defaultId = aiConfigStore.defaults[tag]
  const idx = options.findIndex((m) => m.id === defaultId)
  return options[(idx + 1) % options.length]
})

const canRetry = computed(
  () => props.card.state === 'failed' && !!props.card.prompt && retryModel.value !== null
)

const handleRetry = () => {
  if (!props.card.prompt || !retryModel.value) return
  emit('retry', { prompt: props.card.prompt, modelId: retryModel.value.id })
}
</script>

<template>
  <div
    class="task-card rounded-xl border p-3 transition-colors"
    :class="{
      'task-card--pending': card.state === 'pending',
      'task-card--running': card.state === 'running',
      'task-card--done': card.state === 'done',
      'task-card--failed': card.state === 'failed',
      'task-card--skipped': card.state === 'skipped',
      'task-card--cancelled': card.state === 'cancelled',
    }"
    :data-testid="`task-card-${card.nodeId}`"
    :data-state="card.state"
  >
    <!-- Header: icon + title + state -->
    <div class="flex items-center gap-2 mb-1">
      <Icon :icon="iconForKind" class="w-4 h-4 flex-shrink-0 txt-secondary" />
      <span class="text-sm font-medium txt-primary flex-1 truncate">
        {{ $t(title, $t(`taskPlan.kind.${card.kind}`)) }}
      </span>

      <button
        v-if="canCancel"
        type="button"
        class="pill text-xs whitespace-nowrap"
        data-testid="task-card-stop"
        @click="handleCancel"
      >
        <Icon icon="mdi:stop" class="w-4 h-4" />
        <span class="font-medium">{{ $t('taskPlan.stop') }}</span>
      </button>

      <span class="flex items-center gap-1 text-xs">
        <Icon
          v-if="card.state === 'running'"
          icon="mdi:loading"
          class="w-4 h-4 animate-spin"
          style="color: var(--brand)"
        />
        <Icon
          v-else-if="card.state === 'done'"
          icon="mdi:check-circle"
          class="w-4 h-4"
          style="color: var(--success, #16a34a)"
        />
        <Icon
          v-else-if="card.state === 'failed'"
          icon="mdi:alert-circle-outline"
          class="w-4 h-4"
          style="color: var(--danger, #dc2626)"
        />
        <Icon
          v-else-if="card.state === 'skipped'"
          icon="mdi:minus-circle-outline"
          class="w-4 h-4 txt-muted"
        />
        <Icon
          v-else-if="card.state === 'cancelled'"
          icon="mdi:stop-circle-outline"
          class="w-4 h-4 txt-muted"
        />
        <Icon v-else icon="mdi:clock-outline" class="w-4 h-4 txt-muted" />
        <span class="txt-muted">{{ $t(`taskPlan.state.${card.state}`) }}</span>
      </span>
    </div>

    <!-- Live render progress (video/image) -->
    <div v-if="showProgress" class="task-card__progress mb-2" data-testid="task-card-progress">
      <div class="task-card__progress-track">
        <div class="task-card__progress-fill" :style="{ width: `${progressPercent}%` }" />
      </div>
      <div class="flex items-center justify-between text-xs txt-muted mt-1">
        <span>{{ $t('taskPlan.rendering') }}</span>
        <span>{{ progressPercent }}%</span>
      </div>
    </div>

    <!-- Body -->
    <div v-if="card.state === 'failed'" class="space-y-2">
      <!-- Specific backend error when available, generic copy otherwise -->
      <p class="text-sm txt-muted break-words" data-testid="task-card-error">
        {{ card.error || $t('taskPlan.failedBody') }}
      </p>
      <button
        v-if="canRetry"
        type="button"
        class="pill text-xs whitespace-nowrap"
        data-testid="task-card-retry"
        @click="handleRetry"
      >
        <Icon icon="mdi:refresh" class="w-4 h-4" />
        <span class="font-medium">
          {{ $t('taskPlan.retryWith', { model: retryModel?.name ?? '' }) }}
        </span>
      </button>
    </div>

    <!-- Skipped: show the dependency reason when the backend provided one -->
    <div v-else-if="card.state === 'skipped' && card.error" class="text-sm txt-muted break-words">
      {{ card.error }}
    </div>

    <!-- Cancelled by the user: neutral note, no error styling -->
    <div
      v-else-if="card.state === 'cancelled'"
      class="text-sm txt-muted break-words"
      data-testid="task-card-cancelled"
    >
      {{ $t('taskPlan.cancelledBody') }}
    </div>

    <template v-else>
      <!-- Search card: compact summary (query + source count). The full results
           are shown in the Sources dropdown on the message body, so the card only
           needs a one-liner — QA feedback PR #1076 points 2 & 4. -->
      <div v-if="isSearchKind && card.state === 'done'" class="task-card__body text-sm txt-muted">
        <span v-if="card.query && card.resultsCount">
          {{ $t('taskPlan.searchSummary', { count: card.resultsCount }, card.resultsCount) }}
        </span>
        <span v-else-if="card.query">{{ card.query }}</span>
      </div>

      <!-- Streaming / final text (prose and non-search kinds) -->
      <div
        v-else-if="!isSearchKind && card.text"
        class="task-card__body text-sm txt-primary break-words"
      >
        <MessageText
          v-if="isProseKind"
          :content="card.text"
          :is-streaming="card.state === 'running'"
          readonly
        />
        <p v-else class="whitespace-pre-wrap">{{ card.text }}</p>
        <span v-if="card.state === 'running'" class="task-card__cursor" aria-hidden="true">▍</span>
      </div>

      <!-- Resolved media -->
      <div v-if="card.url" class="mt-2">
        <img
          v-if="card.kind === 'image'"
          :src="card.url"
          :alt="$t('taskPlan.kind.image')"
          class="rounded-lg max-h-72 w-auto"
        />
        <video
          v-else-if="card.kind === 'video'"
          :src="card.url"
          controls
          class="rounded-lg max-h-72 w-auto"
        />
        <audio v-else-if="card.kind === 'audio'" :src="card.url" controls class="w-full" />
        <a
          v-else-if="card.kind === 'document'"
          :href="card.url"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-2 text-sm"
          style="color: var(--brand)"
        >
          <Icon icon="mdi:download" class="w-4 h-4" />
          {{ $t('taskPlan.download') }}
        </a>
      </div>

      <!-- Media skeleton while waiting -->
      <div
        v-else-if="showSkeleton"
        class="task-card__skeleton mt-2 rounded-lg"
        :class="card.kind === 'audio' ? 'h-10' : 'h-40'"
      />
    </template>
  </div>
</template>

<style scoped>
.task-card {
  background: var(--surface-card, rgba(127, 127, 127, 0.04));
  border-color: var(--border-color, rgba(127, 127, 127, 0.2));
}
.task-card--running {
  border-color: var(--brand);
}
.task-card--failed {
  border-color: var(--danger, #dc2626);
}
.task-card--pending,
.task-card--skipped,
.task-card--cancelled {
  opacity: 0.7;
}

.task-card__progress-track {
  width: 100%;
  height: 6px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--surface-card, rgba(127, 127, 127, 0.18));
}
.task-card__progress-fill {
  height: 100%;
  border-radius: 9999px;
  background: var(--brand);
  transition: width 0.4s ease;
}

.task-card__cursor {
  animation: task-blink 1s steps(2, start) infinite;
}
@keyframes task-blink {
  to {
    visibility: hidden;
  }
}

.task-card__skeleton {
  background: linear-gradient(
    90deg,
    rgba(127, 127, 127, 0.08) 25%,
    rgba(127, 127, 127, 0.18) 37%,
    rgba(127, 127, 127, 0.08) 63%
  );
  background-size: 400% 100%;
  animation: task-shimmer 1.4s ease infinite;
}
@keyframes task-shimmer {
  0% {
    background-position: 100% 50%;
  }
  100% {
    background-position: 0 50%;
  }
}
</style>
