<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'

defineProps<{
  title: string
  body: string
  allowLabel: string
  declineLabel: string
}>()

const emit = defineEmits<{
  allow: []
  decline: []
}>()

const { teleportTarget } = useFullscreenTeleportTarget()

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  event.preventDefault()
  emit('decline')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport :to="teleportTarget">
    <div
      class="modal-overlay fixed inset-0 z-[10000] flex items-center justify-center p-4"
      role="presentation"
    >
      <button
        type="button"
        class="absolute inset-0 bg-black/50 backdrop-blur-sm dark:bg-black/70"
        :aria-label="declineLabel"
        data-testid="btn-live-voice-consent-backdrop"
        @click="emit('decline')"
      />
      <div
        class="modal-panel surface-card relative w-full max-w-md overflow-y-auto rounded-xl p-6 shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="live-voice-consent-title"
        data-testid="dialog-live-voice-consent"
      >
        <div class="flex items-center gap-3">
          <div
            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[var(--brand)]/10 text-[var(--brand)]"
            aria-hidden="true"
          >
            <Icon icon="mdi:waveform" class="h-5 w-5" />
          </div>
          <h2 id="live-voice-consent-title" class="text-lg font-semibold leading-snug txt-primary">
            {{ title }}
          </h2>
        </div>
        <p class="mt-4 text-sm leading-relaxed txt-secondary">{{ body }}</p>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-live-voice-consent-decline"
            @click="emit('decline')"
          >
            {{ declineLabel }}
          </button>
          <button
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-live-voice-consent-allow"
            autofocus
            @click="emit('allow')"
          >
            {{ allowLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
