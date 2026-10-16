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
    class="live-voice-stage flex flex-col items-center bg-chat px-4 md:shrink-0 md:gap-1 md:pb-3 md:pt-6 max-md:relative max-md:min-h-0 max-md:flex-1"
    data-testid="section-live-voice-stage"
  >
    <div class="live-voice-stage__orb">
      <LiveVoiceOrb :phase="phase" :mic-level="micLevel" :out-level="outLevel" :muted="muted" />
    </div>
    <div class="live-voice-stage__copy flex flex-col items-center gap-1">
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
      <!-- The spoken line follows "Show captions while you talk". Off hides it. -->
      <Transition name="live-caption">
        <p
          v-if="showCaption && captionText"
          class="max-w-md text-center text-sm txt-secondary line-clamp-2"
          data-testid="live-voice-caption"
        >
          {{ captionText }}
        </p>
      </Transition>
    </div>
  </section>
</template>

<style scoped>
/* On a phone the circle is the middle of the screen, not of the space
   left above the controls. The status and the spoken preview sit under
   it. Wider windows keep the circle at the top, above the conversation. */
@media (max-width: 767px) {
  .live-voice-stage__orb {
    position: fixed;
    left: 50%;
    top: 50%;
    z-index: 20;
    transform: translate(-50%, -50%);
  }

  .live-voice-stage__copy {
    position: fixed;
    left: 50%;
    top: calc(50% + 6.5rem + 0.75rem);
    z-index: 20;
    width: min(100% - 2rem, 28rem);
    transform: translateX(-50%);
  }

  @media (min-width: 400px) {
    .live-voice-stage__copy {
      top: calc(50% + 8.5rem + 0.75rem);
    }
  }
}

.live-caption-enter-active,
.live-caption-leave-active {
  transition: opacity 200ms ease;
}

.live-caption-enter-from,
.live-caption-leave-to {
  opacity: 0;
}
</style>
