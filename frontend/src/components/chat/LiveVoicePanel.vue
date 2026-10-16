<script setup lang="ts">
import { computed } from 'vue'
import type { LivePhase } from '@/services/liveVoice/machine'
import type { LiveTerminalAction } from '@/services/liveVoice/terminal'

const props = defineProps<{
  phase: LivePhase
  status: string
  statusHint: string
  muted: boolean
  modelLabel: string
  budgetLabel: string
  muteLabel: string
  endLabel: string
  warnBanner: string
  terminalMessage: string
  terminalActionLabel: string
  terminalAction: LiveTerminalAction
  closeLabel: string
}>()

const emit = defineEmits<{
  mute: []
  end: []
  dismiss: []
  action: []
}>()

const ended = computed(() => props.phase === 'ended')
const connecting = computed(() => props.phase === 'connecting')

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    if (ended.value) emit('dismiss')
    else emit('end')
    return
  }
  if (event.key === ' ' && event.target === event.currentTarget) {
    event.preventDefault()
    emit('mute')
  }
}
</script>

<template>
  <section
    class="surface-card rounded-xl border border-light-border/30 p-3 md:p-4 dark:border-dark-border/20"
    role="region"
    tabindex="0"
    data-testid="panel-live-voice"
    @keydown="onKeydown"
  >
    <p
      v-if="warnBanner && !ended"
      class="mb-3 text-sm txt-primary"
      data-testid="live-voice-budget-warn"
    >
      {{ warnBanner }}
    </p>

    <div v-if="connecting" class="flex items-center gap-3" data-testid="live-voice-connecting">
      <span class="live-connect-mark" aria-hidden="true" />
      <div class="min-w-0 flex-1">
        <p class="text-base font-semibold txt-primary">{{ status }}</p>
        <p v-if="statusHint" class="text-sm txt-secondary">{{ statusHint }}</p>
      </div>
      <button
        type="button"
        class="btn-primary shrink-0 px-4 py-2.5 text-sm font-medium"
        data-testid="btn-live-voice-end"
        @click="emit('end')"
      >
        {{ endLabel }}
      </button>
    </div>

    <div v-else-if="!ended" class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :aria-pressed="muted"
        data-testid="btn-live-voice-mute"
        @click="emit('mute')"
      >
        {{ muteLabel }}
      </button>
      <div class="ml-auto flex min-w-0 items-center gap-3">
        <div class="hidden min-w-0 text-right min-[480px]:block">
          <p class="truncate text-sm txt-primary">{{ modelLabel }}</p>
          <p v-if="budgetLabel" class="text-xs txt-secondary">{{ budgetLabel }}</p>
        </div>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-live-voice-end"
          @click="emit('end')"
        >
          {{ endLabel }}
        </button>
      </div>
    </div>

    <div v-else data-testid="live-voice-terminal">
      <p class="text-sm txt-primary">{{ terminalMessage }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-if="terminalAction !== 'close'"
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-live-voice-terminal-action"
          @click="emit('action')"
        >
          {{ terminalActionLabel }}
        </button>
        <button
          type="button"
          class="px-4 py-2.5 text-sm font-medium"
          :class="terminalAction === 'close' ? 'btn-primary' : 'btn-secondary'"
          data-testid="btn-live-voice-terminal-close"
          @click="emit('dismiss')"
        >
          {{ closeLabel }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.live-connect-mark {
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
  border-radius: 9999px;
  border: 2px solid color-mix(in srgb, var(--brand) 28%, transparent);
  border-top-color: var(--brand);
  animation: live-connect-spin 0.8s linear infinite;
}

@keyframes live-connect-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .live-connect-mark {
    animation: none;
    border-color: var(--brand);
  }
}
</style>
