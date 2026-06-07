<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { TaskCard } from '@/stores/history'

const props = defineProps<{ card: TaskCard }>()

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
    default:
      return 'mdi:text-box-outline'
  }
})

// Title prefers a capability-specific label, falling back to the kind.
const title = computed(() => `taskPlan.capability.${props.card.capability}`)

const isMediaKind = computed(() => ['image', 'video', 'audio'].includes(props.card.kind))

const showSkeleton = computed(
  () => isMediaKind.value && !props.card.url && props.card.state !== 'failed'
)
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
        <Icon v-else icon="mdi:clock-outline" class="w-4 h-4 txt-muted" />
        <span class="txt-muted">{{ $t(`taskPlan.state.${card.state}`) }}</span>
      </span>
    </div>

    <!-- Body -->
    <div v-if="card.state === 'failed'" class="text-sm txt-muted">
      {{ $t('taskPlan.failedBody') }}
    </div>

    <template v-else>
      <!-- Streaming / final text -->
      <p v-if="card.text" class="text-sm txt-primary whitespace-pre-wrap break-words">
        {{ card.text }}<span v-if="card.state === 'running'" class="task-card__cursor">▍</span>
      </p>

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
.task-card--skipped {
  opacity: 0.7;
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
