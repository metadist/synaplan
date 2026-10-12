<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { LivePhase } from '@/services/liveVoice/machine'
import type { LiveTerminalAction } from '@/services/liveVoice/terminal'

const props = defineProps<{
  phase: LivePhase
  status: string
  companyLine: string
  touchesLine: string
  ownerLine: string
  modelLabel: string
  budgetLabel: string
  muteLabel: string
  captionsLabel: string
  endLabel: string
  settingsLabel: string
  captionText: string
  showCaption: boolean
  level: number
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
const levelWidth = computed(() => `${Math.round(Math.min(1, Math.max(0, props.level)) * 100)}%`)

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
    class="surface-card rounded-xl border border-light-border/30 p-4 dark:border-dark-border/20"
    role="region"
    tabindex="0"
    data-testid="panel-live-voice"
    @keydown="onKeydown"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm font-medium txt-primary" data-testid="live-voice-status">{{ status }}</p>
        <div
          class="mt-2 h-2 w-28 overflow-hidden rounded-full bg-black/10 dark:bg-white/10"
          aria-hidden="true"
        >
          <div class="h-full bg-[var(--brand)]" :style="{ width: levelWidth }" />
        </div>
      </div>
      <div class="min-w-0 text-right">
        <div class="hidden min-[360px]:block">
          <p class="truncate text-sm txt-primary">{{ modelLabel }}</p>
          <p v-if="budgetLabel" class="text-xs txt-secondary">{{ budgetLabel }}</p>
          <RouterLink
            to="/settings/voice"
            class="text-xs txt-secondary underline"
            data-testid="link-live-voice-settings"
          >
            {{ settingsLabel }}
          </RouterLink>
        </div>
        <details class="min-[360px]:hidden">
          <summary class="cursor-pointer text-sm txt-primary">{{ modelLabel }}</summary>
          <p v-if="budgetLabel" class="text-xs txt-secondary">{{ budgetLabel }}</p>
          <RouterLink to="/settings/voice" class="text-xs txt-secondary underline">
            {{ settingsLabel }}
          </RouterLink>
        </details>
      </div>
    </div>

    <p
      v-if="warnBanner && !ended"
      class="mt-3 text-sm txt-primary"
      data-testid="live-voice-budget-warn"
    >
      {{ warnBanner }}
    </p>

    <p v-if="showCaption && captionText" class="mt-3 text-sm txt-primary">{{ captionText }}</p>

    <div v-if="!ended" class="mt-4 flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
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
      <button
        type="button"
        class="btn-primary ml-auto px-4 py-2.5 text-sm font-medium"
        data-testid="btn-live-voice-end"
        @click="emit('end')"
      >
        {{ endLabel }}
      </button>
    </div>

    <div v-else class="mt-4" data-testid="live-voice-terminal">
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

    <p class="mt-3 text-xs txt-secondary">{{ ownerLine }} {{ companyLine }} {{ touchesLine }}</p>
  </section>
</template>
