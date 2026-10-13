<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { LivePhase } from '@/services/liveVoice/machine'
import type { LiveTerminalAction } from '@/services/liveVoice/terminal'

const props = defineProps<{
  phase: LivePhase
  muted: boolean
  companyLine: string
  touchesLine: string
  ownerLine: string
  modelLabel: string
  budgetLabel: string
  muteLabel: string
  captionsLabel: string
  endLabel: string
  settingsLabel: string
  warnBanner: string
  terminalMessage: string
  terminalActionLabel: string
  terminalAction: LiveTerminalAction
}>()

const emit = defineEmits<{
  mute: []
  captions: []
  end: []
  dismiss: []
  action: []
}>()

const ended = computed(() => props.phase === 'ended')

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('end')
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

    <div v-if="!ended" class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :aria-pressed="muted"
        data-testid="btn-live-voice-mute"
        @click="emit('mute')"
      >
        {{ muteLabel }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-live-voice-captions"
        @click="emit('captions')"
      >
        {{ captionsLabel }}
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
      <button
        type="button"
        class="btn-primary mt-3 px-4 py-2.5 text-sm font-medium"
        data-testid="btn-live-voice-terminal-action"
        @click="emit('action')"
      >
        {{ terminalActionLabel }}
      </button>
    </div>

    <p class="mt-3 text-xs txt-secondary">
      {{ ownerLine }} {{ companyLine }} {{ touchesLine }}
      <span v-if="budgetLabel" class="min-[480px]:hidden">{{ budgetLabel }}.</span>
      <RouterLink to="/settings/voice" class="underline" data-testid="link-live-voice-settings">
        {{ settingsLabel }}
      </RouterLink>
    </p>
  </section>
</template>
