<script setup lang="ts">
import LiveVoiceOrb from '@/components/chat/LiveVoiceOrb.vue'
import type { LivePhase } from '@/services/liveVoice/machine'

defineProps<{
  phase: LivePhase
  status: string
  statusHint: string
  micLevel: number
  outLevel: number
  muted: boolean
  captionText: string
  showCaption: boolean
}>()
</script>

<template>
  <section
    class="flex shrink-0 flex-col items-center gap-1 bg-chat px-4 pb-3 pt-4 md:pt-6"
    data-testid="section-live-voice-stage"
  >
    <LiveVoiceOrb :phase="phase" :mic-level="micLevel" :out-level="outLevel" :muted="muted" />
    <p
      class="max-w-sm text-center font-medium txt-primary"
      :class="phase === 'connecting' ? 'text-lg font-semibold' : 'text-sm'"
      role="status"
      aria-live="polite"
      data-testid="live-voice-status"
    >
      {{ status }}
    </p>
    <p
      v-if="statusHint"
      class="max-w-sm text-center text-sm txt-secondary"
      data-testid="live-voice-status-hint"
    >
      {{ statusHint }}
    </p>
    <p
      v-if="showCaption && captionText"
      class="max-w-md text-center text-sm txt-secondary line-clamp-2"
      data-testid="live-voice-caption"
    >
      {{ captionText }}
    </p>
  </section>
</template>
